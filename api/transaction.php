<?php
require_once "includes/config.php";
require_login();
ensure_transaction_proof_columns($conn);
ensure_seller_payment_columns($conn);
$user = current_user($conn);
$productId = (int)($_GET["product_id"] ?? 0);
if (!$productId) {
    die("Invalid product.");
}

$pq = $conn->prepare("SELECT p.*, u.id seller_user_id, u.payment_instructions, u.payment_qr_image FROM products p JOIN users u ON p.seller_id = u.id WHERE p.id = ?");
$pq->bind_param("i", $productId);
$pq->execute();
$product = $pq->get_result()->fetch_assoc();
if (!$product) {
    die("Product not found.");
}
if ((int)$product["seller_user_id"] === (int)$user["id"]) {
    header("Location: product_detail.php?id=" . $productId);
    exit;
}

$message = "";
$createdTransactionId = 0;
$hasSellerQr = !empty($product["payment_qr_image"]);
$hasSellerOnlinePayment = $hasSellerQr || trim($product["payment_instructions"]) !== "";
$selectedPaymentType = $hasSellerOnlinePayment ? "online" : "cash";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $location = trim($_POST["meetup_location"] ?? "");
    $submittedTime = trim($_POST["meetup_time"] ?? "");
    $time = "";
    $paymentType = $_POST["payment_type"] ?? "cash";
    if (!in_array($location, campus_locations(), true)) {
        $location = "Library Lobby";
    }
    if (!in_array($paymentType, ["online", "cash"], true)) {
        $message = "Choose a valid payment method.";
    } elseif ($paymentType === "online" && !$hasSellerOnlinePayment) {
        $message = "This seller has not added digital payment details. Choose cash or contact the seller.";
    }
    if ($submittedTime !== "") {
        $meetupTime = DateTime::createFromFormat("Y-m-d\\TH:i", $submittedTime);
        $timeErrors = DateTime::getLastErrors();
        if (!$meetupTime || ($timeErrors !== false && ($timeErrors["warning_count"] > 0 || $timeErrors["error_count"] > 0))) {
            $message = "Please choose a valid meet-up time.";
        } else {
            $time = $meetupTime->format("Y-m-d H:i:s");
        }
    }
    $selectedPaymentType = $paymentType;
    $paymentMethod = $paymentType === "online"
        ? ($hasSellerQr ? "Seller QRIS" : "Seller bank/e-wallet")
        : "Cash";
    $paymentFlow = $paymentType === "online" ? "gopay_qr" : "cash_receipt";

    if ($message === "" && (int)$product["is_sold"] === 1) {
        $message = "This item has already been sold.";
    } elseif ($message === "" && (int)$product["seller_user_id"] === (int)$user["id"]) {
        $message = "You cannot purchase your own item.";
    } elseif ($message === "") {
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare("UPDATE products SET is_sold = 1 WHERE id = ? AND is_sold = 0 AND seller_id <> ?");
            $lock->bind_param("ii", $productId, $user["id"]);
            $lock->execute();
            if ($lock->affected_rows !== 1) {
                throw new RuntimeException("This item has already been reserved or sold.");
            }
            $stmt = $conn->prepare("INSERT INTO transactions (product_id, buyer_id, seller_id, meetup_location, meetup_time, payment_type, payment_method, payment_status, payment_flow, amount) VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, ?, 'Pending', ?, ?)");
            $stmt->bind_param("iiisssssd", $productId, $user["id"], $product["seller_user_id"], $location, $time, $paymentType, $paymentMethod, $paymentFlow, $product["price"]);
            $stmt->execute();
            $createdTransactionId = $stmt->insert_id;

            $notif = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
            $notifMessage = "New order: " . $product["name"] . " bought by " . $user["full_name"];
            $notifLink = "dashboard_seller.php?order_id=" . $createdTransactionId . "#transaction-" . $createdTransactionId;
            $notif->bind_param("iss", $product["seller_user_id"], $notifMessage, $notifLink);
            $notif->execute();

            $conn->commit();
            redirect_completion("order_created", $createdTransactionId);
        } catch (Throwable $e) {
            $conn->rollback();
            $message = "Could not create the order: " . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-slate-50 min-h-screen">
<?php include "includes/navbar.php"; ?>
<main class="max-w-2xl mx-auto p-4">
    <div class="mt-3 bg-white rounded-2xl shadow-sm p-6">
        <h1 class="text-2xl font-bold text-slate-900">Buy Now</h1>
        <p class="mt-1 text-sm text-slate-600"><?= e($product["name"]) ?> - Rp <?= number_format((float)$product["price"], 0, ",", ".") ?></p>
        <?php if ($message): ?>
            <p role="alert" class="mt-3 text-sm text-red-700"><?= e($message) ?></p>
        <?php endif; ?>
        <form method="post" class="space-y-3 mt-4"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div>
                <label class="text-sm font-medium" for="meetup_location">Meet-up Location</label>
                <select id="meetup_location" name="meetup_location" class="mt-1 w-full border rounded-xl px-3 py-2">
                    <?php foreach (campus_locations() as $loc): ?>
                        <option value="<?= e($loc) ?>"><?= e($loc) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-sm font-medium" for="meetup_time">Meet-up Time</label>
                <input id="meetup_time" type="datetime-local" name="meetup_time" class="mt-1 w-full border rounded-xl px-3 py-2">
            </div>
            <div>
                <label class="text-sm font-medium" for="paymentType">Payment Type</label>
                <select name="payment_type" class="mt-1 w-full border rounded-xl px-3 py-2" id="paymentType" onchange="toggleMethod()">
                    <?php if ($hasSellerOnlinePayment): ?>
                        <option value="online" <?= $selectedPaymentType === "online" ? "selected" : "" ?>>Seller digital payment<?= $hasSellerQr ? " (QRIS / bank / e-wallet)" : " (bank / e-wallet)" ?></option>
                    <?php endif; ?>
                    <option value="cash" <?= $selectedPaymentType === "cash" ? "selected" : "" ?>>Cash on Meet-up</option>
                </select>
            </div>
            <div id="paymentMethodWrap" class="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                After the seller accepts your order, their QRIS code and payment instructions will appear in your buyer dashboard. Pay the exact order total, upload your receipt, and wait for the seller to verify it.
            </div>
            <div id="cashInfo" class="hidden rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                After receiving cash at the meet-up, the seller uploads a receipt to complete the order.
            </div>
            <button class="w-full bg-orange-500 hover:bg-orange-600 text-white rounded-xl py-2 font-semibold">Create Transaction</button>
        </form>
    </div>
</main>
<script>
function toggleMethod() {
    const isOnline = document.getElementById("paymentType").value === "online";
    document.getElementById("paymentMethodWrap").style.display = isOnline ? "block" : "none";
    document.getElementById("cashInfo").style.display = isOnline ? "none" : "block";
}
toggleMethod();
</script>
<?php include "includes/footer.php"; ?>
</body>
</html>
