<?php
require_once "includes/config.php";
require_login();
ensure_transaction_proof_columns($conn);
$user = current_user($conn);
$conn->query("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, message VARCHAR(255) NOT NULL, link VARCHAR(255) DEFAULT '', is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
if ($user["role"] !== "seller") {
    redirect_dashboard("buyer");
}

$focusedOrderId = (int)($_GET["order_id"] ?? 0);
if ($focusedOrderId > 0) {
    $ownedOrder = $conn->prepare("SELECT id FROM transactions WHERE id = ? AND seller_id = ?");
    $ownedOrder->bind_param("ii", $focusedOrderId, $user["id"]);
    $ownedOrder->execute();
    if ($ownedOrder->get_result()->fetch_assoc()) {
        $notificationLink = "dashboard_seller.php?order_id=" . $focusedOrderId . "#transaction-" . $focusedOrderId;
        $readNotification = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND link = ?");
        $readNotification->bind_param("is", $user["id"], $notificationLink);
        $readNotification->execute();
    } else {
        $focusedOrderId = 0;
    }
}

$message = "";
if (isset($_GET["clear_notifications"])) {
    $clear = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $clear->bind_param("i", $user["id"]);
    $clear->execute();
    header("Location: dashboard_seller.php");
    exit;
}
if (isset($_GET["delete"])) {
    $pid = (int)$_GET["delete"];
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ? AND seller_id = ?");
    $stmt->bind_param("ii", $pid, $user["id"]);
    $stmt->execute();
    header("Location: dashboard_seller.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"], $_POST["tid"])) {
    $tid = (int)$_POST["tid"];
    $completedAction = "";
    if ($_POST["action"] === "accept") {
        $s = $conn->prepare("UPDATE transactions SET seller_accepted = 1, payment_status = IF(payment_flow = 'legacy' AND payment_type = 'cash', 'Paid', payment_status) WHERE id = ? AND seller_id = ? AND payment_status = 'Pending'");
        $s->bind_param("ii", $tid, $user["id"]);
        $s->execute();
        if ($s->affected_rows === 1) {
            $completedAction = "order_accepted";
        }
    } elseif ($_POST["action"] === "reject") {
        $s = $conn->prepare("UPDATE transactions SET payment_status = 'Cancelled' WHERE id = ? AND seller_id = ? AND payment_status NOT IN ('Completed', 'Cancelled') AND (payment_flow = 'legacy' OR (seller_accepted = 0 AND NOT (payment_flow = 'gopay_qr' AND buyer_payment_proof <> '')))");
        $s->bind_param("ii", $tid, $user["id"]);
        $s->execute();
        if ($s->affected_rows === 1) {
            $completedAction = "order_declined";
        }
    } elseif ($_POST["action"] === "verify_qr") {
        $conn->begin_transaction();
        try {
            $s = $conn->prepare("UPDATE transactions SET payment_status = 'Completed', buyer_completed = 1, seller_completed = 1 WHERE id = ? AND seller_id = ? AND payment_flow = 'gopay_qr' AND payment_status NOT IN ('Completed', 'Cancelled') AND seller_accepted = 1 AND buyer_payment_proof <> ''");
            $s->bind_param("ii", $tid, $user["id"]);
            $s->execute();
            if ($s->affected_rows !== 1) {
                throw new RuntimeException("This order needs seller acceptance and a buyer payment receipt before it can be completed.");
            }
            $sold = $conn->prepare("UPDATE products p JOIN transactions t ON t.product_id = p.id SET p.is_sold = 1 WHERE t.id = ? AND t.seller_id = ?");
            $sold->bind_param("ii", $tid, $user["id"]);
            $sold->execute();
            $conn->commit();
            $completedAction = "seller_order_completed";
        } catch (Throwable $e) {
            $conn->rollback();
            $message = $e->getMessage();
        }
    }
    if ($message === "" && $completedAction !== "") {
        redirect_completion($completedAction, $tid);
    }
    if ($message === "") {
        header("Location: dashboard_seller.php");
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["upload_cash_receipt"], $_POST["tid"])) {
    $tid = (int)$_POST["tid"];
    try {
        $check = $conn->prepare("SELECT id FROM transactions WHERE id = ? AND seller_id = ? AND payment_flow = 'cash_receipt' AND payment_type = 'cash' AND seller_accepted = 1 AND payment_status NOT IN ('Completed', 'Cancelled')");
        $check->bind_param("ii", $tid, $user["id"]);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            throw new RuntimeException("This order is not ready for a cash receipt.");
        }
        $receiptPath = upload_transaction_proof($_FILES["cash_receipt"] ?? []);
        $conn->begin_transaction();
        $transactionStarted = true;
        $update = $conn->prepare("UPDATE transactions SET seller_cash_receipt = ?, seller_completed = 1, payment_status = 'Completed' WHERE id = ? AND seller_id = ? AND payment_flow = 'cash_receipt' AND seller_accepted = 1 AND payment_status NOT IN ('Completed', 'Cancelled')");
        $update->bind_param("sii", $receiptPath, $tid, $user["id"]);
        $update->execute();
        if ($update->affected_rows !== 1) {
            throw new RuntimeException("The receipt could not be attached to this order.");
        }
        $sold = $conn->prepare("UPDATE products p JOIN transactions t ON t.product_id = p.id SET p.is_sold = 1 WHERE t.id = ? AND t.seller_id = ?");
        $sold->bind_param("ii", $tid, $user["id"]);
        $sold->execute();
        $conn->commit();
        $transactionStarted = false;
        redirect_completion("cash_receipt_uploaded", $tid);
    } catch (RuntimeException $e) {
        if (!empty($transactionStarted)) {
            $conn->rollback();
        }
        $message = $e->getMessage();
    } catch (Throwable $e) {
        $conn->rollback();
        $message = "Could not complete the order: " . $e->getMessage();
    }
}

if (isset($_GET["complete"])) {
    $tid = (int)$_GET["complete"];
    $stmt = $conn->prepare("UPDATE transactions SET seller_completed = 1 WHERE id = ? AND seller_id = ? AND payment_flow = 'legacy' AND payment_status <> 'Cancelled'");
    $stmt->bind_param("ii", $tid, $user["id"]);
    $stmt->execute();
    if ($stmt->affected_rows === 1) {
        redirect_completion("seller_marked_complete", $tid);
    }
    header("Location: dashboard_seller.php");
    exit;
}

$myProducts = $conn->prepare("SELECT * FROM products WHERE seller_id = ? ORDER BY created_at DESC");
$myProducts->bind_param("i", $user["id"]);
$myProducts->execute();
$products = $myProducts->get_result();
$trx = $conn->prepare("SELECT t.*, p.name product_name, u.full_name buyer_name, r.id review_id, r.rating review_rating, r.comment review_comment FROM transactions t JOIN products p ON t.product_id = p.id JOIN users u ON t.buyer_id = u.id LEFT JOIN reviews r ON r.transaction_id = t.id AND r.reviewer_id = t.buyer_id AND r.reviewee_id = t.seller_id WHERE t.seller_id = ? ORDER BY t.created_at DESC");
$trx->bind_param("i", $user["id"]);
$trx->execute();
$transactions = $trx->get_result();
$notif = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$notif->bind_param("i", $user["id"]);
$notif->execute();
$notifications = $notif->get_result();
$notifCount = $conn->prepare("SELECT COUNT(*) total FROM notifications WHERE user_id = ? AND is_read = 0");
$notifCount->bind_param("i", $user["id"]);
$notifCount->execute();
$unreadNotifications = (int)($notifCount->get_result()->fetch_assoc()["total"] ?? 0);
?>
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Dashboard | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-slate-50">
<?php include "includes/navbar.php"; ?>
<main class="max-w-7xl mx-auto p-4 space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:justify-between sm:items-center">
        <h1 class="text-2xl font-bold text-slate-900">Seller Dashboard</h1>
        <div class="flex flex-wrap gap-2">
            <a href="favorites.php" class="px-3 py-2 rounded-lg bg-slate-200">My Favorites</a>
            <a href="index.php" class="px-3 py-2 rounded-lg bg-slate-200">Home</a>
            <a href="messages.php" class="px-3 py-2 rounded-lg bg-slate-200">Messages</a>
            <a href="seller_payment_settings.php" class="rounded-xl border border-orange-200 bg-orange-50 px-4 py-2 font-semibold text-slate-800">Payment settings</a>
            <a href="upload_product.php" class="rounded-xl bg-blue-600 px-4 py-2 text-white font-semibold shadow-md">+ Upload Product</a>
        </div>
    </div>
    <?php if ($message): ?>
        <p class="rounded-xl bg-orange-50 p-3 text-sm text-slate-700"><?= e($message) ?></p>
    <?php endif; ?>
    <?php if ($focusedOrderId > 0): ?>
        <p class="rounded-xl border border-orange-200 bg-orange-50 p-3 text-sm text-slate-700">
            You opened a buyer order notification. Review the highlighted request below to accept it, follow payment and meet-up progress, and check for the buyer's review after completion.
        </p>
    <?php endif; ?>
    <div class="bg-white rounded-2xl shadow-sm p-4">
        <div class="flex justify-between items-center mb-3">
            <h2 class="font-bold">Notifications <?php if ($unreadNotifications > 0): ?><span class="ml-2 inline-flex items-center justify-center min-w-6 h-6 px-2 rounded-full bg-red-600 text-white text-xs"><?= $unreadNotifications ?></span><?php endif; ?></h2>
            <a href="dashboard_seller.php?clear_notifications=1" class="text-sm text-orange-600">Mark all as read</a>
        </div>
        <div class="space-y-2">
            <?php if ($notifications->num_rows === 0): ?>
                <p class="text-sm text-slate-500">No notifications yet.</p>
            <?php else: ?>
                <?php while ($n = $notifications->fetch_assoc()): ?>
                    <a href="<?= e($n["link"] ?: "dashboard_seller.php") ?>" class="block border rounded-xl p-3 <?= (int)$n["is_read"] ? "bg-white" : "bg-orange-50 border-orange-200" ?>">
                        <p class="text-sm font-medium"><?= e($n["message"]) ?></p>
                        <p class="text-xs text-slate-500 mt-1"><?= e($n["created_at"]) ?></p>
                    </a>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm p-4">
        <h2 class="font-bold mb-3">Your Products</h2>
        <div class="grid md:grid-cols-3 gap-3">
            <?php while ($p = $products->fetch_assoc()): ?>
                <div class="border rounded-xl p-3">
                    <img src="<?= e(asset_url($p["image1"])) ?>" alt="" class="h-32 w-full object-cover rounded-lg">
                    <p class="font-semibold mt-2"><?= e($p["name"]) ?></p>
                    <p class="text-sm text-slate-500">Rp <?= number_format((float)$p["price"], 0, ",", ".") ?></p>
                    <div class="space-x-2">
                        <a href="edit_product.php?id=<?= (int)$p["id"] ?>" class="text-orange-600 text-sm">Edit</a>
                        <a href="dashboard_seller.php?delete=<?= (int)$p["id"] ?>" class="text-red-600 text-sm">Delete</a>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm p-4 overflow-x-auto">
        <h2 class="font-bold mb-3">Transaction Requests</h2>
        <table class="w-full text-sm">
            <thead><tr class="text-left border-b"><th class="py-2">Item</th><th>Buyer</th><th>Meet-up</th><th>Order progress</th><th>Payment / Action</th></tr></thead>
            <tbody>
            <?php while ($t = $transactions->fetch_assoc()): ?>
                <tr id="transaction-<?= (int)$t["id"] ?>" class="border-b align-top">
                    <td class="py-2"><?= e($t["product_name"]) ?></td>
                    <td><?= e($t["buyer_name"]) ?></td>
                    <td><?= e($t["meetup_location"]) ?><br><span class="text-xs text-slate-500"><?= e($t["meetup_time"] ?: "-") ?></span></td>
                    <td class="min-w-40">
                        <p class="font-semibold"><?= e($t["payment_status"]) ?></p>
                        <p class="mt-1 text-xs text-slate-600"><?= (int)$t["seller_accepted"] === 1 ? "Order accepted" : ($t["payment_status"] === "Pending" ? "Awaiting your acceptance" : "Order not active") ?></p>
                        <p class="mt-1 text-xs text-slate-600">
                            <?php if ($t["payment_status"] === "Cancelled"): ?>
                                Order cancelled
                            <?php elseif ($t["payment_flow"] === "gopay_qr"): ?>
                                <?= $t["buyer_payment_proof"] ? "Buyer payment proof uploaded" : ((int)$t["seller_accepted"] === 1 ? "Waiting for payment proof after meet-up" : "Payment proof available after acceptance") ?>
                            <?php elseif ($t["payment_flow"] === "cash_receipt"): ?>
                                <?= $t["seller_cash_receipt"] ? "Meet-up receipt uploaded" : ((int)$t["seller_accepted"] === 1 ? "Meet buyer, then upload receipt" : "Receipt due after accepted meet-up") ?>
                            <?php else: ?>
                                <?= e($t["payment_status"] === "Paid" ? "Payment recorded" : "Legacy payment flow") ?>
                            <?php endif; ?>
                        </p>
                        <?php if ($t["payment_status"] === "Completed"): ?>
                            <p class="mt-1 text-xs font-medium <?= $t["review_id"] ? "text-emerald-700" : "text-orange-700" ?>">
                                <?= $t["review_id"] ? "Buyer reviewed · " . (int)$t["review_rating"] . "/5 stars" : "Waiting for buyer review" ?>
                            </p>
                            <?php if ($t["review_id"] && $t["review_comment"]): ?>
                                <p class="mt-1 text-xs italic text-slate-500">“<?= e($t["review_comment"]) ?>”</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="min-w-56 space-y-2">
                        <?php if ($t["payment_flow"] === "gopay_qr" && $t["buyer_payment_proof"]): ?>
                            <a class="block text-orange-600 underline" href="<?= e(asset_url($t["buyer_payment_proof"])) ?>" target="_blank" rel="noopener">View buyer payment receipt</a>
                        <?php endif; ?>
                        <?php if ($t["payment_status"] === "Pending" && (int)$t["seller_accepted"] === 0): ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="tid" value="<?= (int)$t["id"] ?>">
                                <button name="action" value="accept" class="text-emerald-700">Accept</button>
                            </form>
                            <?php if ($t["payment_flow"] !== "gopay_qr" || !$t["buyer_payment_proof"]): ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="tid" value="<?= (int)$t["id"] ?>">
                                    <button name="action" value="reject" class="text-red-700">Reject</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($t["payment_flow"] === "cash_receipt" && (int)$t["seller_accepted"] === 1 && $t["payment_status"] !== "Completed" && $t["payment_status"] !== "Cancelled"): ?>
                            <form method="post" enctype="multipart/form-data" class="space-y-2">
                                <input type="hidden" name="tid" value="<?= (int)$t["id"] ?>">
                                <label class="block text-xs text-slate-600">Upload cash receipt after receiving payment</label>
                                <input type="file" name="cash_receipt" accept="image/jpeg,image/png,image/webp" required class="block w-full text-xs">
                                <button name="upload_cash_receipt" value="1" class="rounded-lg bg-orange-500 px-3 py-1.5 text-white">Upload receipt & complete</button>
                            </form>
                        <?php elseif ($t["payment_flow"] === "gopay_qr" && (int)$t["seller_accepted"] === 1 && $t["buyer_payment_proof"] && $t["payment_status"] !== "Completed" && $t["payment_status"] !== "Cancelled"): ?>
                            <form method="post">
                                <input type="hidden" name="tid" value="<?= (int)$t["id"] ?>">
                                <button name="action" value="verify_qr" class="rounded-lg bg-orange-500 px-3 py-1.5 text-white">Verify payment & complete</button>
                            </form>
                        <?php elseif ($t["payment_flow"] === "gopay_qr" && !$t["buyer_payment_proof"] && $t["payment_status"] !== "Completed" && $t["payment_status"] !== "Cancelled"): ?>
                            <p class="text-xs text-slate-600">Waiting for the buyer to upload payment proof.</p>
                        <?php elseif ($t["payment_flow"] === "legacy" && $t["payment_status"] !== "Completed" && $t["payment_status"] !== "Cancelled"): ?>
                            <a class="text-blue-700" href="dashboard_seller.php?complete=<?= (int)$t["id"] ?>">Mark Completed</a>
                        <?php endif; ?>
                        <?php if ($t["seller_cash_receipt"]): ?>
                            <a class="block text-orange-600 underline" href="<?= e(asset_url($t["seller_cash_receipt"])) ?>" target="_blank" rel="noopener">View cash receipt</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
