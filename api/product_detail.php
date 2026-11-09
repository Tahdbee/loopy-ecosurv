<?php
require_once "includes/config.php";
require_login();
$user = current_user($conn);
$productId = (int)($_GET["id"] ?? 0);
$stmt = $conn->prepare("SELECT p.*, u.full_name seller_name, u.faculty, u.major, u.id seller_user_id FROM products p JOIN users u ON p.seller_id = u.id WHERE p.id = ? AND (p.is_hidden = 0 OR p.seller_id = ? OR ? = 'admin' OR ? = 'staff') AND (u.is_active = 1 OR p.seller_id = ? OR ? = 'admin' OR ? = 'staff')");
$stmt->bind_param("iississ", $productId, $user["id"], $user["role"], $user["role"], $user["id"], $user["role"], $user["role"]);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
if (!$product) {
    die("Product not found.");
}
$recommendationsQuery = $conn->prepare(
    "SELECT p.id, p.name, p.category, p.price, p.image1, u.full_name seller_name
     FROM products p
     JOIN users u ON u.id = p.seller_id
     WHERE p.category = ? AND p.id <> ? AND p.is_sold = 0 AND p.is_hidden = 0 AND u.is_active = 1 AND p.seller_id <> ?
     ORDER BY p.created_at DESC
     LIMIT 4"
);
$recommendationsQuery->bind_param("sii", $product["category"], $productId, $user["id"]);
$recommendationsQuery->execute();
$recommendations = $recommendationsQuery->get_result();
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($product["name"]) ?> | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
</head>
<body class="bg-slate-50 min-h-screen">
<?php include "includes/navbar.php"; ?>
<main class="product-detail-page mx-auto max-w-7xl space-y-8 p-4">
    <section class="product-detail-shell grid gap-7 rounded-3xl border border-white/80 bg-white p-4 shadow-xl md:grid-cols-2 md:p-7">
        <div class="product-detail-gallery">
            <div class="product-detail-main-image">
                <img src="<?= e(asset_url($product["image1"])) ?>" alt="<?= e($product["name"]) ?>">
                <span class="landing-product-category"><?= e($product["category"]) ?></span>
            </div>
            <div class="product-detail-thumbnails">
                <img src="<?= e(asset_url($product["image1"])) ?>" alt="<?= e($product["name"]) ?>">
                <img src="<?= e($product["image2"] ?: $product["image1"]) ?>" alt="<?= e($product["name"]) ?>">
                <img src="<?= e($product["image3"] ?: $product["image1"]) ?>" alt="<?= e($product["name"]) ?>">
            </div>
        </div>
        <div class="product-detail-copy">
            <p class="landing-section-kicker">A LOOPLY find for you</p>
            <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 md:text-4xl"><?= e($product["name"]) ?></h1>
            <p class="product-detail-price">Rp <?= number_format((float)$product["price"], 0, ",", ".") ?></p>
            <div class="product-detail-condition"><span>Condition</span><strong><?= e($product["product_condition"]) ?></strong></div>
            <p class="mt-5 whitespace-pre-line leading-7 text-slate-600"><?= e($product["description"]) ?></p>

            <div class="product-detail-seller">
                <div class="product-detail-seller-avatar" aria-hidden="true"><?= e(strtoupper(substr($product["seller_name"], 0, 1))) ?></div>
                <div class="min-w-0 flex-1">
                    <a class="font-bold text-slate-900 hover:text-blue-700" href="seller_profile.php?id=<?= (int)$product["seller_user_id"] ?>"><?= e($product["seller_name"]) ?></a>
                    <p class="mt-1 truncate text-xs text-slate-500"><?= e($product["faculty"]) ?> / <?= e($product["major"]) ?></p>
                    <a class="mt-1 inline-block text-xs font-semibold text-blue-700" href="seller_profile.php?id=<?= (int)$product["seller_user_id"] ?>">Seller profile &amp; reviews <span aria-hidden="true">→</span></a>
                </div>
            </div>

            <div class="product-detail-actions">
                <?php if ($canChatWithSeller): ?>
                    <a class="product-detail-secondary" href="chat.php?product_id=<?= $productId ?>&partner_id=<?= (int)$product["seller_user_id"] ?>">Chat seller</a>
                <?php endif; ?>
                <?php if ($isOwnProduct): ?>
                    <span class="product-detail-own-item">Your listing</span>
                <?php else: ?>
                    <a class="product-detail-buy" href="transaction.php?product_id=<?= $productId ?>">Buy this find <span aria-hidden="true">→</span></a>
                    <form method="post" action="product_detail.php?id=<?= $productId ?>" class="inline">
                        <button type="submit" name="favorite" class="favorite-button <?= $isFavorite ? "favorited" : "" ?>" aria-pressed="<?= $isFavorite ? "true" : "false" ?>" title="<?= $isFavorite ? "Click to remove from favorites" : "Add to favorites" ?>"><?= $isFavorite ? "Favourited" : "Add to favorites" ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if (!$isOwnProduct && !in_array($user["role"], ["admin", "staff"], true)): ?>
                <div class="mt-4 flex flex-wrap gap-3 text-sm">
                    <a class="font-semibold text-red-700 hover:underline" href="report.php?type=product&amp;id=<?= $productId ?>">Report listing</a>
                    <a class="font-semibold text-red-700 hover:underline" href="report.php?type=user&amp;id=<?= (int)$product["seller_user_id"] ?>">Report seller</a>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET["reported"])): ?><p role="status" class="mt-3 text-sm text-emerald-700">Thanks. Your report was submitted for review.</p><?php endif; ?>
            <?php if ($favoriteMessage): ?><p role="status" class="mt-3 text-sm text-emerald-700"><?= e($favoriteMessage) ?></p><?php endif; ?>
        </div>
    </section>

    <?php if ($recommendations->num_rows > 0): ?>
        <section class="category-recommendations">
            <div class="category-recommendations-heading">
                <div>
                    <p class="landing-section-kicker">Picked from the same shelf</p>
                    <h2>More to love in <span><?= e($product["category"]) ?></span></h2>
                </div>
                <a href="index.php?category=<?= rawurlencode($product["category"]) ?>#marketplace">Explore category <span aria-hidden="true">→</span></a>
            </div>
            <div class="category-recommendations-grid">
                <?php while ($recommended = $recommendations->fetch_assoc()): ?>
                    <article class="product-card category-recommendation-card">
                        <a class="category-recommendation-image" href="product_detail.php?id=<?= (int)$recommended["id"] ?>">
                            <img src="<?= e(asset_url($recommended["image1"])) ?>" alt="<?= e($recommended["name"]) ?>" loading="lazy">
                            <span aria-hidden="true">↗</span>
                        </a>
                        <div class="p-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-blue-700"><?= e($recommended["category"]) ?></p>
                            <h3 class="mt-1 truncate font-bold text-slate-900"><?= e($recommended["name"]) ?></h3>
                            <p class="mt-2 font-extrabold text-slate-900">Rp <?= number_format((float)$recommended["price"], 0, ",", ".") ?></p>
                            <p class="mt-1 text-xs text-slate-500">From <?= e($recommended["seller_name"]) ?></p>
                        </div>
                    </article>
                <?php endwhile; ?>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
