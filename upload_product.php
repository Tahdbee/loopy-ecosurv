<?php
require_once "config.php";
require_login();
$user = current_user($conn);
if ($user["role"] !== "seller") {
    redirect_dashboard("buyer");
}
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $category = trim($_POST["category"] ?? "Other");
    $description = trim($_POST["description"] ?? "");
    $price = (float)($_POST["price"] ?? 0);
    $condition = trim($_POST["product_condition"] ?? "Used - Good");
    $image1 = trim($_POST["image1"] ?? "https://placehold.co/600x400?text=Looply+Item");
    $image2 = trim($_POST["image2"] ?? "");
    $image3 = trim($_POST["image3"] ?? "");
    $stmt = $conn->prepare("INSERT INTO products (seller_id, name, category, description, price, product_condition, image1, image2, image3) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssdssss", $user["id"], $name, $category, $description, $price, $condition, $image1, $image2, $image3);
    $message = $stmt->execute() ? "Product uploaded." : "Failed to upload product.";
}
?>
<!doctype html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Upload Product</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-50 min-h-screen"><main class="max-w-3xl mx-auto p-4"><a href="dashboard_seller.php" class="text-blue-700 text-sm">&larr; Seller Dashboard</a><div class="bg-white rounded-2xl shadow-sm p-6 mt-3"><h1 class="text-2xl font-bold text-blue-700">Upload Product</h1><?php if ($message): ?><p class="mt-2 text-sm text-emerald-700"><?= e($message) ?></p><?php endif; ?><form method="post" class="grid md:grid-cols-2 gap-3 mt-4"><input name="name" required placeholder="Product name" class="md:col-span-2 border rounded-xl px-3 py-2"><select name="category" class="border rounded-xl px-3 py-2"><option>Books</option><option>Electronics</option><option>Dorm Items</option><option>Fashion</option><option>Study Supplies</option><option>Other</option></select><input name="price" type="number" min="0" step="500" required placeholder="Price" class="border rounded-xl px-3 py-2"><input name="product_condition" required placeholder="Condition" class="md:col-span-2 border rounded-xl px-3 py-2"><textarea name="description" placeholder="Description" class="md:col-span-2 border rounded-xl px-3 py-2"></textarea><input name="image1" placeholder="Main image URL" class="md:col-span-2 border rounded-xl px-3 py-2"><input name="image2" placeholder="Image URL 2" class="border rounded-xl px-3 py-2"><input name="image3" placeholder="Image URL 3" class="border rounded-xl px-3 py-2"><button class="md:col-span-2 bg-blue-600 text-white rounded-xl py-2 font-semibold">Upload</button></form></div></main></body></html>
