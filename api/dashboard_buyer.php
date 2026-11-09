<?php
require_once "includes/config.php";
require_login();
ensure_transaction_proof_columns($conn);
ensure_seller_payment_columns($conn);
ensure_seller_payment_columns($conn);
$user = current_user($conn);
if ($user["role"] !== "buyer") {
    redirect_dashboard($user["role"]);
}

$message = "";
if (isset($_GET["payment_status_check"])) {
    $statusTransactionId = filter_var($_GET["payment_status_check"], FILTER_VALIDATE_INT);
    header("Content-Type: application/json; charset=utf-8");
    if ($statusTransactionId === false || $statusTransactionId < 1) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid transaction."]);
        exit;
    }
    $statusCheck = $conn->prepare("SELECT payment_status FROM transactions WHERE id = ? AND buyer_id = ? AND payment_flow = 'gopay_qr'");
    $statusCheck->bind_param("ii", $statusTransactionId, $user["id"]);
    $statusCheck->execute();
    $statusRow = $statusCheck->get_result()->fetch_assoc();
    if (!$statusRow) {
        http_response_code(404);
        echo json_encode(["error" => "Transaction not found."]);
        exit;
    }
    echo json_encode(["status" => $statusRow["payment_status"]]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["complete"])) {
    verify_csrf_token();
    $tid = (int)$_POST["complete"];
    $stmt = $conn->prepare("UPDATE transactions SET buyer_completed = 1 WHERE id = ? AND buyer_id = ? AND payment_flow = 'legacy' AND payment_status <> 'Cancelled'");
    $stmt->bind_param("ii", $tid, $user["id"]);
    $stmt->execute();
    $check = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND buyer_id = ? AND payment_flow = 'legacy'");
    $check->bind_param("ii", $tid, $user["id"]);
    $check->execute();
    $tx = $check->get_result()->fetch_assoc();
    if ($tx && (int)$tx["seller_completed"] === 1 && !in_array($tx["payment_status"], ["Completed", "Cancelled"], true)) {
        $done = $conn->prepare("UPDATE transactions SET payment_status = 'Completed' WHERE id = ? AND payment_status <> 'Cancelled'");
        $done->bind_param("i", $tx["id"]);
        $done->execute();
        if ($done->affected_rows === 1) {
            $sold = $conn->prepare("UPDATE products SET is_sold = 1 WHERE id = ?");
            $sold->bind_param("i", $tx["product_id"]);
            $sold->execute();
        }
    }
    if ($message === "" && $tx && $tx["payment_status"] !== "Cancelled") {
        redirect_completion(
            (int)$tx["seller_completed"] === 1 ? "order_completed" : "buyer_marked_complete"
        );
    }
    if ($message === "") {
        header("Location: dashboard_buyer.php");
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["upload_payment_proof"])) {
    verify_csrf_token();
    $tid = (int)($_POST["transaction_id"] ?? 0);
    try {
        $check = $conn->prepare("SELECT id, seller_id FROM transactions WHERE id = ? AND buyer_id = ? AND payment_flow = 'gopay_qr' AND payment_type = 'online' AND seller_accepted = 1 AND payment_status NOT IN ('Completed', 'Cancelled')");
        $check->bind_param("ii", $tid, $user["id"]);
        $check->execute();
        $eligibleTransaction = $check->get_result()->fetch_assoc();
        if (!$eligibleTransaction) {
            throw new RuntimeException("This order cannot accept a payment receipt.");
        }
        $proofPath = upload_transaction_proof($_FILES["payment_proof"] ?? []);
        $update = $conn->prepare("UPDATE transactions SET buyer_payment_proof = ? WHERE id = ? AND buyer_id = ? AND payment_flow = 'gopay_qr' AND seller_accepted = 1 AND payment_status NOT IN ('Completed', 'Cancelled')");
        $update->bind_param("sii", $proofPath, $tid, $user["id"]);
        $update->execute();
        if ($update->affected_rows !== 1) {
            throw new RuntimeException("The receipt could not be attached to this order.");
        }
        $notification = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
        $notificationMessage = "Payment proof uploaded for order #" . $tid . ". Review the receipt and update the order.";
        $notificationLink = "dashboard_seller.php?order_id=" . $tid . "#transaction-" . $tid;
        $notification->bind_param("iss", $eligibleTransaction["seller_id"], $notificationMessage, $notificationLink);
        $notification->execute();
        redirect_completion("payment_proof_uploaded", $tid);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["transaction_id"], $_POST["rating"])) {
    verify_csrf_token();
    $tid = (int)$_POST["transaction_id"];
    $rating = max(1, min(5, (int)$_POST["rating"]));
    $comment = trim($_POST["comment"] ?? "");
    $get = $conn->prepare("SELECT seller_id FROM transactions WHERE id = ? AND buyer_id = ? AND payment_status = 'Completed'");
    $get->bind_param("ii", $tid, $user["id"]);
    $get->execute();
    $tx = $get->get_result()->fetch_assoc();
    if ($tx) {
        $ins = $conn->prepare("INSERT IGNORE INTO reviews (transaction_id, reviewer_id, reviewee_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param("iiiis", $tid, $user["id"], $tx["seller_id"], $rating, $comment);
        $ins->execute();
        if ($ins->affected_rows === 1) {
            redirect_completion("review_submitted", (int)$tx["seller_id"]);
        }
    }
}

$fav = $conn->prepare("SELECT p.* FROM favorites f JOIN products p ON f.product_id = p.id WHERE f.user_id = ? ORDER BY f.created_at DESC");
$fav->bind_param("i", $user["id"]);
$fav->execute();
$favorites = $fav->get_result();
$trx = $conn->prepare("SELECT t.*, p.name product_name, u.full_name seller_name, u.payment_instructions seller_payment_instructions, u.payment_qr_image seller_payment_qr, r.id review_id, r.rating review_rating FROM transactions t JOIN products p ON t.product_id = p.id JOIN users u ON t.seller_id = u.id LEFT JOIN reviews r ON r.transaction_id = t.id AND r.reviewer_id = t.buyer_id AND r.reviewee_id = t.seller_id WHERE t.buyer_id = ? ORDER BY t.created_at DESC");
$trx->bind_param("i", $user["id"]);
$trx->execute();
$transactionRows = $trx->get_result()->fetch_all(MYSQLI_ASSOC);

$paymentSuccessNotice = null;
$seenPaymentSuccess = $_SESSION["seen_payment_success"][$user["id"]] ?? [];
if (!is_array($seenPaymentSuccess)) {
    $seenPaymentSuccess = [];
}
foreach ($transactionRows as $transactionRow) {
    if ($transactionRow["payment_flow"] === "gopay_qr"
        && $transactionRow["payment_status"] === "Completed"
        && !$transactionRow["review_id"]
        && !in_array((int)$transactionRow["id"], $seenPaymentSuccess, true)) {
        $paymentSuccessNotice = $transactionRow;
        $seenPaymentSuccess[] = (int)$transactionRow["id"];
        break;
    }
}
$_SESSION["seen_payment_success"][$user["id"]] = $seenPaymentSuccess;
$paymentTransactionsToWatch = [];
foreach ($transactionRows as $transactionRow) {
    if ($transactionRow["payment_flow"] === "gopay_qr"
        && $transactionRow["seller_accepted"]
        && $transactionRow["buyer_payment_proof"]
        && !in_array($transactionRow["payment_status"], ["Completed", "Cancelled"], true)) {
        $paymentTransactionsToWatch[] = (int)$transactionRow["id"];
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Dashboard | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
</head>
<body class="bg-slate-50">
<?php include "includes/navbar.php"; ?>
<?php if ($paymentSuccessNotice): ?>
    <div id="paymentSuccessModal" class="payment-success-overlay" role="dialog" aria-modal="true" aria-labelledby="paymentSuccessTitle">
        <section class="payment-success-card">
            <button type="button" class="payment-success-close" aria-label="Close payment confirmation" onclick="closePaymentSuccess()">&times;</button>
            <div class="completion-check" role="img" aria-label="Payment confirmed">
                <svg viewBox="0 0 52 52" aria-hidden="true"><path d="M14 27.5 22 35l16-18" /></svg>
            </div>
            <p class="mt-5 text-xs font-bold uppercase tracking-[0.2em] text-emerald-700">Seller verified your payment</p>
            <h1 id="paymentSuccessTitle" class="mt-2 text-2xl font-bold text-slate-900">Payment confirmed!</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">Your QR payment for <strong><?= e($paymentSuccessNotice["product_name"]) ?></strong> has been verified. Your next step is to rate the seller.</p>
            <a href="#buyer-transaction-<?= (int)$paymentSuccessNotice["id"] ?>" onclick="closePaymentSuccess()" class="completion-primary mt-5 inline-flex items-center justify-center rounded-xl px-5 py-3 font-semibold text-white">Continue to review <span aria-hidden="true" class="ml-2">&rarr;</span></a>
        </section>
    </div>
<?php endif; ?>
<main class="max-w-7xl mx-auto p-4 space-y-5">
    <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold text-slate-900">Buyer Dashboard</h1>
        <div class="flex gap-2">
            <a href="favorites.php" class="px-3 py-2 rounded-lg bg-slate-200">My Favorites</a>
            <a href="messages.php" class="px-3 py-2 rounded-lg bg-slate-200">Messages</a>
            <a href="index.php" class="px-3 py-2 rounded-lg bg-orange-500 text-white">Home</a>
        </div>
    </div>
    <?php if ($message): ?>
        <p class="rounded-xl bg-orange-50 p-3 text-sm text-slate-700"><?= e($message) ?></p>
    <?php endif; ?>
    <div class="bg-white rounded-2xl shadow-sm p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-bold">Favorite Items</h2>
            <a href="favorites.php" class="text-sm text-orange-600">View all</a>
        </div>
        <div class="grid md:grid-cols-4 gap-3">
            <?php if ($favorites->num_rows === 0): ?>
                <p class="text-sm text-slate-500">No favorites yet.</p>
            <?php else: ?>
                <?php while ($f = $favorites->fetch_assoc()): ?>
                    <a href="product_detail.php?id=<?= (int)$f["id"] ?>" class="border rounded-xl p-2 block">
                        <img src="<?= e(asset_url($f["image1"])) ?>" alt="" class="h-24 w-full object-cover rounded-lg">
                        <p class="text-sm mt-1 font-semibold"><?= e($f["name"]) ?></p>
                    </a>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm p-4 overflow-x-auto">
        <h2 class="font-bold mb-3">Your Transactions</h2>
        <table class="w-full text-sm">
            <thead><tr class="border-b text-left"><th class="py-2">Item</th><th>Seller</th><th>Meet-up</th><th>Status</th><th>Payment / Action</th></tr></thead>
            <tbody>
            <?php foreach ($transactionRows as $t): ?>
                <tr id="buyer-transaction-<?= (int)$t["id"] ?>" class="border-b align-top">
                    <td class="py-2"><?= e($t["product_name"]) ?></td>
                    <td><a href="seller_profile.php?id=<?= (int)$t["seller_id"] ?>" class="text-orange-600 hover:underline"><?= e($t["seller_name"]) ?></a></td>
                    <td><?= e($t["meetup_location"]) ?><br><span class="text-xs text-slate-500"><?= e($t["meetup_time"] ?: "-") ?></span></td>
                    <td><?= e($t["payment_status"]) ?></td>
                    <td class="min-w-52">
                        <?php if ($t["payment_status"] === "Pending" && (int)$t["seller_accepted"] === 0): ?><a href="edit_transaction.php?id=<?= (int)$t["id"] ?>" class="mb-2 mr-3 inline-block text-xs font-semibold text-blue-700 hover:underline">Edit meet-up</a><?php endif; ?><a href="report.php?type=transaction&amp;id=<?= (int)$t["id"] ?>" class="mb-2 inline-block text-xs font-semibold text-red-700 hover:underline">Report transaction</a>
                        <?php if ($t["payment_flow"] === "gopay_qr" && $t["payment_status"] !== "Completed" && $t["payment_status"] !== "Cancelled"): ?>
                            <?php if ($t["buyer_payment_proof"]): ?>
                                <a class="text-orange-600 underline" href="<?= e(protected_media_url($t["buyer_payment_proof"])) ?>" target="_blank" rel="noopener">View uploaded receipt</a>
                                <p class="text-xs text-slate-500 mt-1">Waiting for seller verification.</p>
                            <?php elseif ((int)$t["seller_accepted"] !== 1): ?>
                                <p class="text-xs text-slate-600">Wait for the seller to accept the order before paying or uploading proof.</p>
                            <?php else: ?>
                                <div class="mb-2 rounded-xl border border-orange-100 bg-orange-50 p-2">
                                    <p class="text-xs font-medium text-slate-700">Pay the seller directly. Exact order total:</p>
                                    <p class="mt-1 text-sm font-bold text-slate-900">Rp <?= number_format((float)$t["amount"], 0, ",", ".") ?></p>
                                    <?php if ($t["seller_payment_qr"]): ?>
                                        <a href="<?= e(protected_media_url($t["seller_payment_qr"])) ?>" target="_blank" rel="noopener">
                                            <img src="<?= e(protected_media_url($t["seller_payment_qr"])) ?>" alt="Seller QRIS payment code" class="mx-auto mt-2 max-h-48 rounded-lg border bg-white p-1">
                                        </a>
                                    <?php elseif (!$t["seller_payment_instructions"]): ?>
                                        <p class="mt-2 text-xs text-amber-800">Seller QR is currently unavailable. Contact the seller before sending payment.</p>
                                    <?php else: ?>
                                        <p class="mt-2 text-xs text-slate-600">No QR code was added. Follow the bank or e-wallet instructions below.</p>
                                    <?php endif; ?>
                                    <p class="mt-1 text-xs text-slate-500">Payment is confirmed after the seller reviews your proof.</p>
                                </div>
                                <?php if ($t["seller_payment_instructions"]): ?>
                                    <div class="mb-2 whitespace-pre-line rounded-xl bg-slate-50 p-3 text-xs text-slate-700"><strong>Seller's payment instructions</strong><?= "\n" . e($t["seller_payment_instructions"]) ?></div>
                                <?php endif; ?>
                                <form method="post" enctype="multipart/form-data" class="mt-2 space-y-2">
                                    <input type="hidden" name="transaction_id" value="<?= (int)$t["id"] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="file" name="payment_proof" accept="image/jpeg,image/png,image/webp" required class="block w-full text-xs">
                                    <button name="upload_payment_proof" value="1" class="rounded-lg bg-orange-500 px-3 py-1.5 text-white">Upload payment receipt</button>
                                </form>
                            <?php endif; ?>
                        <?php elseif ($t["payment_flow"] === "cash_receipt" && $t["payment_status"] !== "Completed" && $t["payment_status"] !== "Cancelled"): ?>
                            <?php if ((int)$t["seller_accepted"] === 1 && $t["seller_payment_instructions"]): ?>
                                <div class="whitespace-pre-line rounded-xl bg-slate-50 p-3 text-xs text-slate-700"><strong>Seller's payment instructions</strong><?= "\n" . e($t["seller_payment_instructions"]) ?></div>
                            <?php endif; ?>
                            <p class="text-xs text-slate-600">Meet the seller at the agreed location. The seller uploads a receipt after receiving payment.</p>
                        <?php elseif ($t["payment_flow"] === "legacy" && $t["payment_status"] !== "Completed" && $t["payment_status"] !== "Cancelled"): ?>
                            <form method="post" class="inline"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="complete" value="<?= (int)$t["id"] ?>"><button class="text-emerald-700">Mark Completed</button></form>
                        <?php endif; ?>
                        <?php if ($t["seller_cash_receipt"]): ?>
                            <a class="block text-orange-600 underline mt-2" href="<?= e(protected_media_url($t["seller_cash_receipt"])) ?>" target="_blank" rel="noopener">View seller cash receipt</a>
                        <?php endif; ?>
                        <?php if ($t["payment_status"] === "Completed" && !$t["review_id"]): ?>
                            <form method="post" class="mt-2 space-y-1">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="transaction_id" value="<?= (int)$t["id"] ?>">
                                <input type="number" name="rating" min="1" max="5" required class="border rounded px-2 py-1 w-16" placeholder="5">
                                <input name="comment" class="border rounded px-2 py-1" placeholder="Review">
                                <button class="bg-orange-500 text-white px-2 py-1 rounded">Submit</button>
                            </form>
                        <?php elseif ($t["review_id"]): ?>
                            <p class="mt-2 text-xs font-medium text-emerald-700">Review submitted<?= $t["review_rating"] ? " · " . (int)$t["review_rating"] . "/5 stars" : "" ?>.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>
<script>
function closePaymentSuccess() {
    const modal = document.getElementById("paymentSuccessModal");
    if (modal) modal.remove();
}

const paymentTransactionsToWatch = <?= json_encode($paymentTransactionsToWatch, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
if (paymentTransactionsToWatch.length > 0) {
    const checkPaymentStatuses = async () => {
        for (const transactionId of paymentTransactionsToWatch) {
            try {
                const response = await fetch(`dashboard_buyer.php?payment_status_check=${transactionId}`, {
                    headers: { "Accept": "application/json" },
                    cache: "no-store"
                });
                if (!response.ok) continue;
                const result = await response.json();
                if (result.status === "Completed") {
                    window.location.reload();
                    return;
                }
            } catch (error) {
                console.error("Could not check payment status.", error);
            }
        }
    };
    window.setInterval(checkPaymentStatuses, 5000);
}
</script>
<?php include "includes/footer.php"; ?>
</body>
</html>
