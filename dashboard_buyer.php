<?php
require_once "config.php";
require_login();
$user = current_user($conn);
if ($user["role"] !== "buyer") {
    redirect_dashboard("seller");
}
if (isset($_GET["complete"])) {
    $tid = (int)$_GET["complete"];
    $stmt = $conn->prepare("UPDATE transactions SET buyer_completed = 1 WHERE id = ? AND buyer_id = ? AND payment_status <> 'Cancelled'");
    $stmt->bind_param("ii", $tid, $user["id"]);
    $stmt->execute();
    $check = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND buyer_id = ?");
    $check->bind_param("ii", $tid, $user["id"]);
    $check->execute();
    $tx = $check->get_result()->fetch_assoc();
    if ($tx && (int)$tx["seller_completed"] === 1 && $tx["payment_status"] !== "Completed") {
        if ($tx["payment_type"] === "online") {
            $conn->begin_transaction();
            try {
                $upSeller = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
                $upSeller->bind_param("di", $tx["amount"], $tx["seller_id"]);
                $upSeller->execute();
                $log = $conn->prepare("INSERT INTO wallet_transactions (user_id, transaction_id, type, amount, note) VALUES (?, ?, 'release_to_seller', ?, ?)");
                $note = "Escrow released to seller";
                $log->bind_param("iids", $tx["seller_id"], $tx["id"], $tx["amount"], $note);
                $log->execute();
                $done = $conn->prepare("UPDATE transactions SET payment_status = 'Completed' WHERE id = ?");
                $done->bind_param("i", $tx["id"]);
                $done->execute();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
            }
        } else {
            $done = $conn->prepare("UPDATE transactions SET payment_status = 'Completed' WHERE id = ?");
            $done->bind_param("i", $tx["id"]);
            $done->execute();
        }
        $sold = $conn->prepare("UPDATE products SET is_sold = 1 WHERE id = ?");
        $sold->bind_param("i", $tx["product_id"]);
        $sold->execute();
    }
    header("Location: dashboard_buyer.php");
    exit;
}
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["transaction_id"], $_POST["rating"])) {
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
    }
}
$fav = $conn->prepare("SELECT p.* FROM favorites f JOIN products p ON f.product_id = p.id WHERE f.user_id = ? ORDER BY f.created_at DESC");
$fav->bind_param("i", $user["id"]);
$fav->execute();
$favorites = $fav->get_result();
$trx = $conn->prepare("SELECT t.*, p.name product_name, u.full_name seller_name FROM transactions t JOIN products p ON t.product_id = p.id JOIN users u ON t.seller_id = u.id WHERE t.buyer_id = ? ORDER BY t.created_at DESC");
$trx->bind_param("i", $user["id"]);
$trx->execute();
$transactions = $trx->get_result();
?>
<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Buyer Dashboard</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50"><main class="max-w-7xl mx-auto p-4 space-y-5"><div class="flex justify-between items-center"><h1 class="text-2xl font-bold text-blue-700">Buyer Dashboard</h1><a href="index.php" class="px-3 py-2 rounded-lg bg-blue-600 text-white">Home</a></div><div class="bg-white rounded-2xl shadow-sm p-4"><h2 class="font-bold mb-3">Favorite Items</h2><div class="grid md:grid-cols-4 gap-3"><?php while ($f = $favorites->fetch_assoc()): ?><a href="product_detail.php?id=<?= (int)$f["id"] ?>" class="border rounded-xl p-2 block"><img src="<?= e($f["image1"]) ?>" class="h-24 w-full object-cover rounded-lg"><p class="text-sm mt-1 font-semibold"><?= e($f["name"]) ?></p></a><?php endwhile; ?></div></div><div class="bg-white rounded-2xl shadow-sm p-4 overflow-x-auto"><h2 class="font-bold mb-3">Your Transactions</h2><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2">Item</th><th>Seller</th><th>Meet-up</th><th>Status</th><th>Action</th></tr></thead><tbody><?php while ($t = $transactions->fetch_assoc()): ?><tr class="border-b align-top"><td class="py-2"><?= e($t["product_name"]) ?></td><td><?= e($t["seller_name"]) ?></td><td><?= e($t["meetup_location"]) ?><br><span class="text-xs text-slate-500"><?= e($t["meetup_time"] ?: "-") ?></span></td><td><?= e($t["payment_status"]) ?></td><td><a class="text-emerald-700" href="dashboard_buyer.php?complete=<?= (int)$t["id"] ?>">Mark Completed</a><?php if ($t["payment_status"] === "Completed"): ?><form method="post" class="mt-2 space-y-1"><input type="hidden" name="transaction_id" value="<?= (int)$t["id"] ?>"><input type="number" name="rating" min="1" max="5" required class="border rounded px-2 py-1 w-16" placeholder="5"><input name="comment" class="border rounded px-2 py-1" placeholder="Review"><button class="bg-blue-600 text-white px-2 py-1 rounded">Submit</button></form><?php endif; ?></td></tr><?php endwhile; ?></tbody></table></div></main></body></html>
