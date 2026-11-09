<?php
require_once "includes/config.php";
require_login();
$user = current_user($conn);
$productId = (int)($_GET["product_id"] ?? 0);
$partnerId = (int)($_GET["partner_id"] ?? 0);
$chatError = "";
$productCheck = $conn->prepare("SELECT seller_id FROM products WHERE id = ?");
$productCheck->bind_param("i", $productId);
$productCheck->execute();
$chatProduct = $productCheck->get_result()->fetch_assoc();
$sellerId = (int)($chatProduct["seller_id"] ?? 0);
$validConversation = $productId > 0
    && $partnerId > 0
    && $partnerId !== (int)$user["id"]
    && $sellerId > 0
    && ((int)$user["id"] === $sellerId || $partnerId === $sellerId);

if (!$validConversation) {
    $chatError = "This conversation is unavailable. You cannot chat with your own account.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $validConversation) {
    $message = trim($_POST["message"] ?? "");
    if ($message !== "") {
        $stmt = $conn->prepare("INSERT INTO chats (product_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiis", $productId, $user["id"], $partnerId, $message);
        $stmt->execute();
    }
}
$messages = [];
if ($validConversation) {
    $stmt = $conn->prepare("SELECT c.*, s.full_name sender_name FROM chats c JOIN users s ON c.sender_id = s.id WHERE c.product_id = ? AND ((c.sender_id = ? AND c.receiver_id = ?) OR (c.sender_id = ? AND c.receiver_id = ?)) ORDER BY c.created_at ASC");
    $stmt->bind_param("iiiii", $productId, $user["id"], $partnerId, $partnerId, $user["id"]);
    $stmt->execute();
    $messages = $stmt->get_result();
}
?>
<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Chat | LOOPLY</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>"></head><body class="bg-slate-50 min-h-screen"><?php include "includes/navbar.php"; ?><main class="max-w-3xl mx-auto p-4"><div class="mt-3 bg-white rounded-2xl shadow-sm p-4"><h1 class="text-xl font-bold text-blue-700">Buyer-Seller Chat</h1><?php if ($chatError): ?><p role="alert" class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800"><?= e($chatError) ?></p><?php else: ?><div class="mt-4 border rounded-xl p-3 h-80 overflow-y-auto bg-slate-50"><?php if ($messages): while ($m = $messages->fetch_assoc()): ?><div class="mb-2 <?= (int)$m["sender_id"] === (int)$user["id"] ? "text-right" : "text-left" ?>"><p class="text-xs text-slate-500"><?= e($m["sender_name"]) ?></p><span class="inline-block px-3 py-2 rounded-xl bg-white border"><?= e($m["message"]) ?></span></div><?php endwhile; endif; ?></div><form method="post" class="mt-3 flex gap-2"><input name="message" required placeholder="Ask about condition, price, meeting time..." class="flex-1 border rounded-xl px-3 py-2"><button class="bg-blue-600 text-white rounded-xl px-4">Send</button></form><?php endif; ?></div></main><?php include "includes/footer.php"; ?></body></html>
