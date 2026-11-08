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
$isOwnProduct = (int)$user["id"] === (int)$product["seller_user_id"];
$canChatWithSeller = !$isOwnProduct;
$favoriteCheck = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND product_id = ?");
$favoriteCheck->bind_param("ii", $user["id"], $productId);
$favoriteCheck->execute();
$isFavorite = (bool)$favoriteCheck->get_result()->fetch_assoc();
$favoriteMessage = "";

if (isset($_POST["favorite"]) && $isOwnProduct) {
    $favoriteMessage = "You cannot add your own listing to favorites.";
} elseif (isset($_POST["favorite"])) {
    if ($isFavorite) {
        $favoriteUpdate = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND product_id = ?");
        $favoriteUpdate->bind_param("ii", $user["id"], $productId);
        $favoriteUpdate->execute();
        $isFavorite = false;
        $favoriteMessage = "Removed from your favorites.";
    } else {
        $favoriteUpdate = $conn->prepare("INSERT INTO favorites (user_id, product_id) VALUES (?, ?)");
        $favoriteUpdate->bind_param("ii", $user["id"], $productId);
        $favoriteUpdate->execute();
        $isFavorite = true;
        $favoriteMessage = "Added to your favorites.";
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?= e($product["name"]) ?> | LOOPLY</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="assets/css/app.css"></head>
<body class="bg-slate-50 min-h-screen"><?php include "includes/navbar.php"; ?><main class="max-w-6xl mx-auto p-4"><div class="mt-3 bg-white rounded-2xl shadow-sm p-4 md:p-6 grid md:grid-cols-2 gap-6"><div class="space-y-3"><img src="<?= e($product["image1"]) ?>" class="w-full h-72 object-cover rounded-xl" alt="<?= e($product["name"]) ?>"><div class="grid grid-cols-3 gap-2"><img src="<?= e($product["image1"]) ?>" class="h-20 w-full object-cover rounded-lg" alt="<?= e($product["name"]) ?>"><img src="<?= e($product["image2"] ?: $product["image1"]) ?>" class="h-20 w-full object-cover rounded-lg" alt="<?= e($product["name"]) ?>"><img src="<?= e($product["image3"] ?: $product["image1"]) ?>" class="h-20 w-full object-cover rounded-lg" alt="<?= e($product["name"]) ?>"></div></div><div><h1 class="text-2xl font-bold"><?= e($product["name"]) ?></h1><p class="text-blue-700 text-2xl font-bold mt-2">Rp <?= number_format((float)$product["price"], 0, ",", ".") ?></p><p class="text-sm text-slate-500 mt-2">Condition: <span class="font-semibold"><?= e($product["product_condition"]) ?></span></p><p class="text-sm text-slate-600 mt-4"><?= nl2br(e($product["description"])) ?></p><div class="mt-5 p-3 rounded-xl bg-slate-100"><a class="font-semibold hover:text-blue-700" href="seller_profile.php?id=<?= (int)$product["seller_user_id"] ?>"><?= e($product["seller_name"]) ?></a><p class="text-xs text-slate-500"><?= e($product["faculty"]) ?> / <?= e($product["major"]) ?></p><a class="mt-1 inline-block text-xs text-blue-700" href="seller_profile.php?id=<?= (int)$product["seller_user_id"] ?>">View seller profile &amp; reviews &rarr;</a></div><div class="mt-5 flex flex-wrap gap-2"><?php if ($canChatWithSeller): ?><a class="bg-blue-600 text-white px-4 py-2 rounded-xl" href="chat.php?product_id=<?= $productId ?>&partner_id=<?= (int)$product["seller_user_id"] ?>">Chat Seller</a><?php endif; ?><?php if ($isOwnProduct): ?><span class="rounded-xl bg-slate-200 px-4 py-2 text-slate-500">Your listing</span><?php else: ?><a class="bg-emerald-600 text-white px-4 py-2 rounded-xl" href="transaction.php?product_id=<?= $productId ?>">Buy Now</a><form method="post" action="product_detail.php?id=<?= $productId ?>" class="inline"><button type="submit" name="favorite" class="favorite-button <?= $isFavorite ? "favorited" : "" ?>" aria-pressed="<?= $isFavorite ? "true" : "false" ?>" title="<?= $isFavorite ? "Click to remove from favorites" : "Add to favorites" ?>"><?= $isFavorite ? "Favourited" : "Add to Favorites" ?></button></form><?php endif; ?></div><?php if ($favoriteMessage): ?><p role="status" class="mt-3 text-sm text-emerald-700"><?= e($favoriteMessage) ?></p><?php endif; ?></div></div></main><?php include "includes/footer.php"; ?></body></html>
