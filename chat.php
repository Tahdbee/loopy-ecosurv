<?php
require_once "config.php";
require_login();
$user = current_user($conn);
$productId = (int)($_GET["product_id"] ?? 0);
$partnerId = (int)($_GET["partner_id"] ?? 0);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $message = trim($_POST["message"] ?? "");
    if ($message !== "" && $productId && $partnerId) {
        $stmt = $conn->prepare("INSERT INTO chats (product_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiis", $productId, $user["id"], $partnerId, $message);
        $stmt->execute();
    }
}
$messages = [];
if ($productId && $partnerId) {
    $stmt = $conn->prepare("SELECT c.*, s.full_name sender_name FROM chats c JOIN users s ON c.sender_id = s.id WHERE c.product_id = ? AND ((c.sender_id = ? AND c.receiver_id = ?) OR (c.sender_id = ? AND c.receiver_id = ?)) ORDER BY c.created_at ASC");
    $stmt->bind_param("iiiii", $productId, $user["id"], $partnerId, $partnerId, $user["id"]);
    $stmt->execute();
    $messages = $stmt->get_result();
}
?>
<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Chat - Looply</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 min-h-screen"><main class="max-w-3xl mx-auto p-4"><div class="flex justify-between"><a href="index.php" class="text-blue-700 text-sm">&larr; Home</a><a href="messages.php" class="text-blue-700 text-sm">Messages</a></div><div class="mt-3 bg-white rounded-2xl shadow-sm p-4"><h1 class="text-xl font-bold text-blue-700">Buyer-Seller Chat</h1><div class="mt-4 border rounded-xl p-3 h-80 overflow-y-auto bg-slate-50"><?php if ($messages): while ($m = $messages->fetch_assoc()): ?><div class="mb-2 <?= (int)$m["sender_id"] === (int)$user["id"] ? "text-right" : "text-left" ?>"><p class="text-xs text-slate-500"><?= e($m["sender_name"]) ?></p><span class="inline-block px-3 py-2 rounded-xl bg-white border"><?= e($m["message"]) ?></span></div><?php endwhile; endif; ?></div><form method="post" class="mt-3 flex gap-2"><input name="message" required placeholder="Ask about condition, price, meeting time..." class="flex-1 border rounded-xl px-3 py-2"><button class="bg-blue-600 text-white rounded-xl px-4">Send</button></form></div></main></body></html>
