<?php
require_once "config.php";
require_login();
$user = current_user($conn);
$conn->query("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, message VARCHAR(255) NOT NULL, link VARCHAR(255) DEFAULT '', is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
if ($user["role"] !== "seller") {
    redirect_dashboard("buyer");
}
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
if (isset($_GET["action"], $_GET["tid"])) {
    $tid = (int)$_GET["tid"];
    if ($_GET["action"] === "accept") {
        $s = $conn->prepare("UPDATE transactions SET seller_accepted = 1, payment_status = IF(payment_type='cash','Paid',payment_status) WHERE id = ? AND seller_id = ?");
        $s->bind_param("ii", $tid, $user["id"]);
        $s->execute();
    }
    if ($_GET["action"] === "reject") {
        $s = $conn->prepare("UPDATE transactions SET payment_status = 'Cancelled' WHERE id = ? AND seller_id = ?");
        $s->bind_param("ii", $tid, $user["id"]);
        $s->execute();
    }
    header("Location: dashboard_seller.php");
    exit;
}
if (isset($_GET["complete"])) {
    $tid = (int)$_GET["complete"];
    $stmt = $conn->prepare("UPDATE transactions SET seller_completed = 1 WHERE id = ? AND seller_id = ? AND payment_status <> 'Cancelled'");
    $stmt->bind_param("ii", $tid, $user["id"]);
    $stmt->execute();
    header("Location: dashboard_seller.php");
    exit;
}
$myProducts = $conn->prepare("SELECT * FROM products WHERE seller_id = ? ORDER BY created_at DESC");
$myProducts->bind_param("i", $user["id"]);
$myProducts->execute();
$products = $myProducts->get_result();
$trx = $conn->prepare("SELECT t.*, p.name product_name, u.full_name buyer_name FROM transactions t JOIN products p ON t.product_id = p.id JOIN users u ON t.buyer_id = u.id WHERE t.seller_id = ? ORDER BY t.created_at DESC");
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
<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Seller Dashboard</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50"><main class="max-w-7xl mx-auto p-4 space-y-5"><div class="flex justify-between items-center"><h1 class="text-2xl font-bold text-blue-700">Seller Dashboard</h1><div class="flex gap-2"><a href="index.php" class="px-3 py-2 rounded-lg bg-slate-200">Home</a><a href="messages.php" class="px-3 py-2 rounded-lg bg-slate-200">Messages</a><a href="upload_product.php" class="px-3 py-2 rounded-lg bg-blue-600 text-white">Upload Product</a></div></div><div class="bg-white rounded-2xl shadow-sm p-4"><div class="flex justify-between items-center mb-3"><h2 class="font-bold">Notifications <?php if ($unreadNotifications > 0): ?><span class="ml-2 inline-flex items-center justify-center min-w-6 h-6 px-2 rounded-full bg-red-600 text-white text-xs"><?= $unreadNotifications ?></span><?php endif; ?></h2><a href="dashboard_seller.php?clear_notifications=1" class="text-sm text-blue-700">Mark all as read</a></div><div class="space-y-2"><?php if ($notifications->num_rows === 0): ?><p class="text-sm text-slate-500">No notifications yet.</p><?php else: ?><?php while ($n = $notifications->fetch_assoc()): ?><a href="<?= e($n["link"] ?: "dashboard_seller.php") ?>" class="block border rounded-xl p-3 <?= (int)$n["is_read"] ? "bg-white" : "bg-blue-50 border-blue-200" ?>"><p class="text-sm font-medium"><?= e($n["message"]) ?></p><p class="text-xs text-slate-500 mt-1"><?= e($n["created_at"]) ?></p></a><?php endwhile; ?><?php endif; ?></div></div><div class="bg-white rounded-2xl shadow-sm p-4"><h2 class="font-bold mb-3">Your Products</h2><div class="grid md:grid-cols-3 gap-3"><?php while ($p = $products->fetch_assoc()): ?><div class="border rounded-xl p-3"><img src="<?= e($p["image1"]) ?>" class="h-32 w-full object-cover rounded-lg"><p class="font-semibold mt-2"><?= e($p["name"]) ?></p><p class="text-sm text-slate-500">Rp <?= number_format((float)$p["price"], 0, ",", ".") ?></p><div class="space-x-2"><a href="edit_product.php?id=<?= (int)$p["id"] ?>" class="text-blue-600 text-sm">Edit</a><a href="dashboard_seller.php?delete=<?= (int)$p["id"] ?>" class="text-red-600 text-sm">Delete</a></div></div><?php endwhile; ?></div></div><div class="bg-white rounded-2xl shadow-sm p-4 overflow-x-auto"><h2 class="font-bold mb-3">Transaction Requests</h2><table class="w-full text-sm"><thead><tr class="text-left border-b"><th class="py-2">Item</th><th>Buyer</th><th>Meet-up</th><th>Status</th><th>Action</th></tr></thead><tbody><?php while ($t = $transactions->fetch_assoc()): ?><tr class="border-b"><td class="py-2"><?= e($t["product_name"]) ?></td><td><?= e($t["buyer_name"]) ?></td><td><?= e($t["meetup_location"]) ?></td><td><?= e($t["payment_status"]) ?></td><td class="space-x-2"><a class="text-emerald-700" href="dashboard_seller.php?action=accept&tid=<?= (int)$t["id"] ?>">Accept</a><a class="text-red-700" href="dashboard_seller.php?action=reject&tid=<?= (int)$t["id"] ?>">Reject</a><a class="text-blue-700" href="dashboard_seller.php?complete=<?= (int)$t["id"] ?>">Completed</a></td></tr><?php endwhile; ?></tbody></table></div></main></body></html>
