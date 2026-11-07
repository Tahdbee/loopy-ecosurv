<?php
require_once "config.php";
require_login();
$user = current_user($conn);
$productId = (int)($_GET["product_id"] ?? 0);
if (!$productId) { die("Invalid product."); }
$pq = $conn->prepare("SELECT p.*, u.id seller_user_id FROM products p JOIN users u ON p.seller_id=u.id WHERE p.id = ?");
$pq->bind_param("i", $productId);
$pq->execute();
$product = $pq->get_result()->fetch_assoc();
if (!$product) { die("Product not found."); }
$conn->query("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, message VARCHAR(255) NOT NULL, link VARCHAR(255) DEFAULT '', is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $location = trim($_POST["meetup_location"] ?? "");
    $time = trim($_POST["meetup_time"] ?? "");
    $paymentType = $_POST["payment_type"] ?? "cash";
    $paymentMethod = trim($_POST["payment_method"] ?? "");
    if (!in_array($location, campus_locations(), true)) { $location = "Library Lobby"; }
    if (!in_array($paymentType, ["online", "cash"], true)) { $paymentType = "cash"; }
    if ($paymentType === "online" && $paymentMethod === "") { $paymentMethod = "QRIS"; }
    $status = $paymentType === "online" ? "Paid" : "Pending";
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO transactions (product_id, buyer_id, seller_id, meetup_location, meetup_time, payment_type, payment_method, payment_status, amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisssssd", $productId, $user["id"], $product["seller_user_id"], $location, $time, $paymentType, $paymentMethod, $status, $product["price"]);
        $stmt->execute();
        $txId = $stmt->insert_id;
        $notif = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
        $notifMessage = "New order: " . $product["name"] . " bought by " . $user["full_name"];
        $notifLink = "dashboard_seller.php";
        $notif->bind_param("iss", $product["seller_user_id"], $notifMessage, $notifLink);
        $notif->execute();
        if ($paymentType === "online") {
            if ((float)$user["wallet_balance"] < (float)$product["price"]) {
                throw new Exception("Insufficient wallet balance. Please top up in profile first.");
            }
            $ded = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
            $ded->bind_param("di", $product["price"], $user["id"]);
            $ded->execute();
            $log = $conn->prepare("INSERT INTO wallet_transactions (user_id, transaction_id, type, amount, note) VALUES (?, ?, 'topup_hold', ?, ?)");
            $note = "Escrow hold for purchase";
            $log->bind_param("iids", $user["id"], $txId, $product["price"], $note);
            $log->execute();
        }
        $conn->commit();
        $message = "Transaction request created successfully.";
    } catch (Throwable $e) {
        $conn->rollback();
        $message = $e->getMessage();
    }
}
?>
<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Transaction - Looply</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 min-h-screen"><main class="max-w-2xl mx-auto p-4"><a href="product_detail.php?id=<?= (int)$product["id"] ?>" class="text-blue-700 text-sm">&larr; Back to Product</a><div class="mt-3 bg-white rounded-2xl shadow-sm p-6"><h1 class="text-2xl font-bold text-blue-700">Buy Now</h1><p class="mt-1 text-sm text-slate-600"><?= e($product["name"]) ?> - Rp <?= number_format((float)$product["price"], 0, ",", ".") ?></p><?php if ($message): ?><p class="mt-3 text-sm text-emerald-700"><?= e($message) ?></p><?php endif; ?><form method="post" class="space-y-3 mt-4"><div><label class="text-sm font-medium">Meet-up Location</label><select name="meetup_location" class="mt-1 w-full border rounded-xl px-3 py-2"><?php foreach (campus_locations() as $loc): ?><option value="<?= e($loc) ?>"><?= e($loc) ?></option><?php endforeach; ?></select></div><div><label class="text-sm font-medium">Meet-up Time</label><input type="datetime-local" name="meetup_time" class="mt-1 w-full border rounded-xl px-3 py-2"></div><div><label class="text-sm font-medium">Payment Type</label><select name="payment_type" class="mt-1 w-full border rounded-xl px-3 py-2" id="paymentType" onchange="toggleMethod()"><option value="online">Online Payment</option><option value="cash">Cash on Meet-up</option></select></div><div id="paymentMethodWrap"><label class="text-sm font-medium">Payment Method</label><select name="payment_method" class="mt-1 w-full border rounded-xl px-3 py-2"><?php foreach (payment_methods() as $method): ?><option value="<?= e($method) ?>"><?= e($method) ?></option><?php endforeach; ?></select><p class="text-xs text-slate-500 mt-1">Simulated payment gateway (QRIS, transfer, e-wallet).</p></div><button class="w-full bg-blue-600 text-white rounded-xl py-2 font-semibold">Create Transaction</button></form></div></main><script>function toggleMethod(){const val=document.getElementById('paymentType').value;document.getElementById('paymentMethodWrap').style.display=val==='online'?'block':'none';}toggleMethod();</script></body></html>
