<?php
require_once "config.php";
require_login();
$user = current_user($conn);

$stmt = $conn->prepare(
    "SELECT c.id, c.product_id, c.sender_id, c.receiver_id, c.message, c.created_at,
            p.name product_name,
            CASE WHEN c.sender_id = ? THEN receiver.full_name ELSE sender.full_name END partner_name,
            CASE WHEN c.sender_id = ? THEN c.receiver_id ELSE c.sender_id END partner_id
     FROM chats c
     JOIN products p ON p.id = c.product_id
     JOIN users sender ON sender.id = c.sender_id
     JOIN users receiver ON receiver.id = c.receiver_id
     WHERE (c.sender_id = ? OR c.receiver_id = ?)
       AND c.id = (
           SELECT latest.id
           FROM chats latest
           WHERE latest.product_id = c.product_id
             AND (
                 (latest.sender_id = c.sender_id AND latest.receiver_id = c.receiver_id)
                 OR
                 (latest.sender_id = c.receiver_id AND latest.receiver_id = c.sender_id)
             )
           ORDER BY latest.created_at DESC, latest.id DESC
           LIMIT 1
       )
     ORDER BY c.created_at DESC, c.id DESC"
);
$stmt->bind_param("iiii", $user["id"], $user["id"], $user["id"], $user["id"]);
$stmt->execute();
$conversations = $stmt->get_result();
?>
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Messages - Looply</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-50 min-h-screen">
<main class="max-w-3xl mx-auto p-4">
    <a href="index.php" class="text-blue-700 text-sm">&larr; Home</a>
    <div class="mt-3 bg-white rounded-2xl shadow-sm p-4">
        <h1 class="text-xl font-bold text-blue-700">Messages</h1>
        <div class="mt-4 divide-y">
            <?php if ($conversations->num_rows === 0): ?>
                <p class="py-4 text-sm text-slate-500">No chats yet.</p>
            <?php else: ?>
                <?php while ($conversation = $conversations->fetch_assoc()): ?>
                    <a href="chat.php?product_id=<?= (int)$conversation["product_id"] ?>&partner_id=<?= (int)$conversation["partner_id"] ?>" class="block py-4 hover:bg-slate-50">
                        <div class="flex justify-between gap-3">
                            <div>
                                <p class="font-semibold"><?= e($conversation["partner_name"]) ?></p>
                                <p class="text-sm text-blue-700"><?= e($conversation["product_name"]) ?></p>
                            </div>
                            <p class="text-xs text-slate-500"><?= e($conversation["created_at"]) ?></p>
                        </div>
                        <p class="mt-1 text-sm text-slate-600 truncate"><?= e($conversation["message"]) ?></p>
                    </a>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
