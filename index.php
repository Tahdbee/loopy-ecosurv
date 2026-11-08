<?php
require_once "config.php";
require_login();
$user = current_user($conn);
$categories = ["Books", "Electronics", "Dorm Items", "Fashion", "Study Supplies", "Other"];
$search = trim($_GET["q"] ?? "");
$category = trim($_GET["category"] ?? "");

$sql = "SELECT p.*, u.full_name seller_name, u.faculty, u.major FROM products p JOIN users u ON p.seller_id = u.id WHERE p.is_sold = 0";
$params = [];
$types = "";
if ($search !== "") {
    $sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}
if ($category !== "") {
    $sql .= " AND p.category = ?";
    $params[] = $category;
    $types .= "s";
}
$sql .= " ORDER BY p.created_at DESC LIMIT 30";
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$products = $stmt->get_result();
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Marketplace | LOOPLY</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="assets/css/app.css"></head>
<body class="bg-slate-50 min-h-screen"><?php include "includes/navbar.php"; ?>
<main class="max-w-7xl mx-auto p-4"><section class="py-10 md:py-14"><p class="text-sm font-bold uppercase tracking-[0.2em] text-blue-600">The campus marketplace</p><h1 class="mt-3 text-4xl md:text-6xl font-extrabold tracking-tight">Discover More.<br><span class="text-blue-700">Shop Different.</span></h1><p class="brand-tagline mt-4 text-lg">Find your next favorite thing on LOOPLY.</p></section><div class="bg-white rounded-2xl p-4 shadow-sm"><form class="grid grid-cols-1 md:grid-cols-4 gap-3"><input type="text" name="q" value="<?= e($search) ?>" placeholder="Search products..." class="md:col-span-2 border rounded-xl px-3 py-2"><select name="category" class="border rounded-xl px-3 py-2"><option value="">All categories</option><?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>" <?= $category === $cat ? "selected" : "" ?>><?= e($cat) ?></option><?php endforeach; ?></select><button class="bg-blue-600 text-white rounded-xl px-3 py-2 font-semibold">Filter</button></form></div>
<h2 class="text-xl font-bold mt-6 mb-3">Latest Student Items</h2><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4"><?php while ($row = $products->fetch_assoc()): ?><div class="product-card bg-white rounded-2xl shadow-sm overflow-hidden"><img src="<?= e($row["image1"]) ?>" alt="<?= e($row["name"]) ?>" class="w-full h-40 object-cover"><div class="p-4"><h3 class="font-bold"><?= e($row["name"]) ?></h3><p class="text-blue-700 font-semibold mt-1">Rp <?= number_format((float)$row["price"], 0, ",", ".") ?></p><a href="seller_profile.php?id=<?= (int)$row["seller_id"] ?>" class="text-sm text-slate-500 mt-1 hover:text-blue-700"><?= e($row["seller_name"]) ?></a><p class="text-xs text-slate-400"><?= e($row["faculty"]) ?> / <?= e($row["major"]) ?></p><a href="product_detail.php?id=<?= (int)$row["id"] ?>" class="mt-3 inline-block bg-blue-600 text-white px-3 py-2 rounded-lg text-sm">View Details</a></div></div><?php endwhile; ?></div></main><?php include "includes/footer.php"; ?></body></html>
