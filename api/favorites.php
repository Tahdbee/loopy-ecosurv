<?php
require_once "includes/config.php";
require_login();
$user = current_user($conn);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["remove_favorite"])) {
    $productId = (int)($_POST["product_id"] ?? 0);
    if ($productId > 0) {
        $remove = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND product_id = ?");
        $remove->bind_param("ii", $user["id"], $productId);
        $remove->execute();
    }
    header("Location: favorites.php");
    exit;
}

$stmt = $conn->prepare(
    "SELECT p.id, p.name, p.price, p.image1, p.is_sold, u.id seller_id, u.full_name seller_name
     FROM favorites f
     JOIN products p ON p.id = f.product_id
     JOIN users u ON u.id = p.seller_id
     WHERE f.user_id = ?
     ORDER BY f.created_at DESC"
);
$stmt->bind_param("i", $user["id"]);
$stmt->execute();
$favorites = $stmt->get_result();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Favorites | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
</head>
<body class="bg-slate-50 min-h-screen">
    <?php include "includes/navbar.php"; ?>
    <main class="max-w-7xl mx-auto p-4">
        <div class="mb-6">
            <div>
                <h1 class="text-3xl font-bold">My Favorites</h1>
                <p class="brand-tagline mt-1">Your saved LOOPLY finds, all in one place.</p>
            </div>
        </div>

        <?php if ($favorites->num_rows === 0): ?>
            <section class="bg-white rounded-2xl shadow-sm p-8 text-center">
                <h2 class="text-xl font-semibold">No favorites yet</h2>
                <p class="text-slate-500 mt-2">Save items you like and they will show up here.</p>
                <a href="index.php" class="inline-block mt-5 bg-blue-600 text-white rounded-xl px-4 py-2 font-semibold">Explore Marketplace</a>
            </section>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php while ($item = $favorites->fetch_assoc()): ?>
                    <article class="product-card bg-white rounded-2xl shadow-sm overflow-hidden">
                        <a href="product_detail.php?id=<?= (int)$item["id"] ?>">
                            <img src="<?= e(asset_url($item["image1"])) ?>" alt="<?= e($item["name"]) ?>" class="w-full h-44 object-cover">
                        </a>
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-2">
                                <h2 class="font-bold"><?= e($item["name"]) ?></h2>
                                <?php if ((int)$item["is_sold"] === 1): ?>
                                    <span class="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-500">Sold</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-blue-700 font-semibold mt-1">Rp <?= number_format((float)$item["price"], 0, ",", ".") ?></p>
                            <p class="text-sm text-slate-500 mt-1">Sold by <a href="seller_profile.php?id=<?= (int)$item["seller_id"] ?>" class="text-blue-700 hover:underline"><?= e($item["seller_name"]) ?></a></p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="product_detail.php?id=<?= (int)$item["id"] ?>" class="bg-blue-600 text-white rounded-lg px-3 py-2 text-sm">View Item</a>
                                <form method="post">
                                    <input type="hidden" name="product_id" value="<?= (int)$item["id"] ?>">
                                    <button type="submit" name="remove_favorite" class="favorite-button">Remove</button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
    </main>
    <?php include "includes/footer.php"; ?>
</body>
</html>
