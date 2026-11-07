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
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Looply Marketplace</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-50 min-h-screen"><nav class="bg-white shadow-sm border-b"><div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between"><a href="index.php" class="text-2xl font-bold text-blue-700">Looply</a><div class="flex gap-2 text-sm"><a href="profile.php" class="px-3 py-2 rounded-lg bg-slate-100">Profile</a><a href="<?= $user["role"] === "seller" ? "dashboard_seller.php" : "dashboard_buyer.php" ?>" class="px-3 py-2 rounded-lg bg-blue-600 text-white">Dashboard</a><a href="logout.php" class="px-3 py-2 rounded-lg bg-slate-200">Logout</a></div></div></nav>
<main class="max-w-7xl mx-auto p-4"><div class="bg-white rounded-2xl p-4 shadow-sm"><form class="grid grid-cols-1 md:grid-cols-4 gap-3"><input type="text" name="q" value="<?= e($search) ?>" placeholder="Search products..." class="md:col-span-2 border rounded-xl px-3 py-2"><select name="category" class="border rounded-xl px-3 py-2"><option value="">All categories</option><?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>" <?= $category === $cat ? "selected" : "" ?>><?= e($cat) ?></option><?php endforeach; ?></select><button class="bg-blue-600 text-white rounded-xl px-3 py-2 font-semibold">Filter</button></form></div>
<h2 class="text-xl font-bold mt-6 mb-3">Latest Student Items</h2><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4"><?php while ($row = $products->fetch_assoc()): ?><div class="bg-white rounded-2xl shadow-sm overflow-hidden"><img src="<?= e($row["image1"]) ?>" alt="<?= e($row["name"]) ?>" class="w-full h-40 object-cover"><div class="p-4"><h3 class="font-bold"><?= e($row["name"]) ?></h3><p class="text-blue-700 font-semibold mt-1">Rp <?= number_format((float)$row["price"], 0, ",", ".") ?></p><p class="text-sm text-slate-500 mt-1"><?= e($row["seller_name"]) ?></p><p class="text-xs text-slate-400"><?= e($row["faculty"]) ?> / <?= e($row["major"]) ?></p><a href="product_detail.php?id=<?= (int)$row["id"] ?>" class="mt-3 inline-block bg-blue-600 text-white px-3 py-2 rounded-lg text-sm">View Details</a></div></div><?php endwhile; ?></div></main></body></html>
