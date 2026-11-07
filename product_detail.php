<?php
require_once "config.php";
require_login();
$user = current_user($conn);
$productId = (int)($_GET["id"] ?? 0);
$stmt = $conn->prepare("SELECT p.*, u.full_name seller_name, u.faculty, u.major, u.id seller_user_id FROM products p JOIN users u ON p.seller_id = u.id WHERE p.id = ?");
$stmt->bind_param("i", $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
if (!$product) {
    die("Product not found.");
}
if (isset($_POST["favorite"])) {
    $fav = $conn->prepare("INSERT IGNORE INTO favorites (user_id, product_id) VALUES (?, ?)");
    $fav->bind_param("ii", $user["id"], $productId);
    $fav->execute();
}
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?= e($product["name"]) ?> - Looply</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-50 min-h-screen"><main class="max-w-6xl mx-auto p-4"><a href="index.php" class="text-blue-700 text-sm">&larr; Back to home</a><div class="mt-3 bg-white rounded-2xl shadow-sm p-4 md:p-6 grid md:grid-cols-2 gap-6"><div class="space-y-3"><img src="<?= e($product["image1"]) ?>" class="w-full h-72 object-cover rounded-xl" alt=""><div class="grid grid-cols-3 gap-2"><img src="<?= e($product["image1"]) ?>" class="h-20 object-cover rounded-lg"><img src="<?= e($product["image2"] ?: $product["image1"]) ?>" class="h-20 object-cover rounded-lg"><img src="<?= e($product["image3"] ?: $product["image1"]) ?>" class="h-20 object-cover rounded-lg"></div></div><div><h1 class="text-2xl font-bold"><?= e($product["name"]) ?></h1><p class="text-blue-700 text-2xl font-bold mt-2">Rp <?= number_format((float)$product["price"], 0, ",", ".") ?></p><p class="text-sm text-slate-500 mt-2">Condition: <span class="font-semibold"><?= e($product["product_condition"]) ?></span></p><p class="text-sm text-slate-600 mt-4"><?= nl2br(e($product["description"])) ?></p><div class="mt-5 p-3 rounded-xl bg-slate-100"><p class="font-semibold"><?= e($product["seller_name"]) ?></p><p class="text-xs text-slate-500"><?= e($product["faculty"]) ?> / <?= e($product["major"]) ?></p></div><div class="mt-5 flex flex-wrap gap-2"><a class="bg-blue-600 text-white px-4 py-2 rounded-xl" href="chat.php?product_id=<?= $productId ?>&partner_id=<?= (int)$product["seller_user_id"] ?>">Chat Seller</a><a class="bg-emerald-600 text-white px-4 py-2 rounded-xl" href="transaction.php?product_id=<?= $productId ?>">Buy Now</a><form method="post" class="inline"><button name="favorite" class="bg-slate-200 px-4 py-2 rounded-xl">Favorite</button></form></div></div></div></main></body></html>
