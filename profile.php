<?php
require_once "config.php";
require_login();
$user = current_user($conn);
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["new_role"])) {
        $newRole = $_POST["new_role"];
        if (in_array($newRole, ["buyer", "seller"], true)) {
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->bind_param("si", $newRole, $user["id"]);
            $stmt->execute();
            redirect_completion("profile_updated");
        }
    }

    if (isset($_POST["topup_amount"])) {
        $amount = (float)$_POST["topup_amount"];
        if ($amount > 0) {
            $up = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $up->bind_param("di", $amount, $user["id"]);
            $up->execute();
            redirect_completion("wallet_topped_up");
        }
    }
}
$wallet = $conn->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$wallet->bind_param("i", $user["id"]);
$wallet->execute();
$walletHistory = $wallet->get_result();
$avg = $conn->prepare("SELECT AVG(rating) avg_rating, COUNT(*) total_reviews FROM reviews WHERE reviewee_id = ?");
$avg->bind_param("i", $user["id"]);
$avg->execute();
$reviewStats = $avg->get_result()->fetch_assoc();
?>
<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Profile | LOOPLY</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="assets/css/app.css"></head><body class="bg-slate-50 min-h-screen"><?php include "includes/navbar.php"; ?><main class="max-w-5xl mx-auto p-4 space-y-4"><div class="bg-white rounded-2xl shadow-sm p-5"><h1 class="text-2xl font-bold"><?= e($user["full_name"]) ?></h1><p class="text-sm text-slate-500"><?= e($user["email"]) ?></p><p class="mt-2 inline-flex px-2 py-1 rounded-lg text-xs <?= (int)$user["is_verified"] ? "bg-emerald-100 text-emerald-700" : "bg-amber-100 text-amber-700" ?>"><?= (int)$user["is_verified"] ? "Verified Student" : "Unverified" ?></p><p class="text-sm mt-2">Role: <span class="font-semibold uppercase"><?= e($user["role"]) ?></span></p><p class="text-sm">Faculty / Major: <?= e($user["faculty"] ?: "-") ?> / <?= e($user["major"] ?: "-") ?></p><p class="text-sm">Average Rating: <span class="font-semibold"><?= number_format((float)($reviewStats["avg_rating"] ?? 0), 1) ?></span> (<?= (int)($reviewStats["total_reviews"] ?? 0) ?> reviews)</p><?php if ($message): ?><p class="mt-2 text-sm text-emerald-700"><?= e($message) ?></p><?php endif; ?><form method="post" class="mt-4 flex gap-2 items-end"><div><label class="text-sm">Change role</label><select name="new_role" class="mt-1 border rounded-xl px-3 py-2"><option value="buyer">Buyer</option><option value="seller">Seller</option></select></div><button class="bg-blue-600 text-white rounded-xl px-4 py-2">Update Role</button></form></div><div class="bg-white rounded-2xl shadow-sm p-5"><h2 class="text-xl font-bold text-blue-700">Wallet</h2><p class="mt-1">Current Balance: <span class="font-bold">Rp <?= number_format((float)$user["wallet_balance"], 0, ",", ".") ?></span></p><div class="mt-3 flex flex-wrap gap-2"><form method="post" class="flex gap-2"><input type="number" step="1000" min="1000" name="topup_amount" placeholder="Top up amount" class="border rounded-xl px-3 py-2"><button class="bg-emerald-600 text-white rounded-xl px-4">Top Up</button></form></div><h3 class="font-semibold mt-5">Transaction History</h3><div class="mt-2 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2">Type</th><th>Amount</th><th>Note</th><th>Date</th></tr></thead><tbody><?php while ($w = $walletHistory->fetch_assoc()): ?><tr class="border-b"><td class="py-2"><?= e($w["type"]) ?></td><td>Rp <?= number_format((float)$w["amount"], 0, ",", ".") ?></td><td><?= e($w["note"]) ?></td><td><?= e($w["created_at"]) ?></td></tr><?php endwhile; ?></tbody></table></div></div></main><?php include "includes/footer.php"; ?></body></html>
