<?php
require_once "config.php";
require_login();
$user = current_user($conn);
if ($user["role"] !== "seller") {
    redirect_dashboard("buyer");
}
$id = (int)($_GET["id"] ?? 0);
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
$stmt->bind_param("ii", $id, $user["id"]);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
if (!$product) { die("Product not found."); }
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $price = (float)($_POST["price"] ?? 0);
    $description = trim($_POST["description"] ?? "");
    $condition = trim($_POST["product_condition"] ?? "");
    $category = trim($_POST["category"] ?? "Other");
    $image1 = trim($_POST["image1"] ?? $product["image1"]);
    $u = $conn->prepare("UPDATE products SET name=?, price=?, description=?, product_condition=?, category=?, image1=? WHERE id=? AND seller_id=?");
    $u->bind_param("sdssssii", $name, $price, $description, $condition, $category, $image1, $id, $user["id"]);
    if ($u->execute()) {
        header("Location: dashboard_seller.php");
        exit;
    }
    $message = "Update failed.";
}
?>
<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Edit Product</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 min-h-screen"><main class="max-w-3xl mx-auto p-4"><a href="dashboard_seller.php" class="text-blue-700 text-sm">&larr; Seller Dashboard</a><div class="bg-white rounded-2xl shadow-sm p-6 mt-3"><h1 class="text-2xl font-bold text-blue-700">Edit Product</h1><?php if ($message): ?><p class="text-red-600 text-sm mt-2"><?= e($message) ?></p><?php endif; ?><form method="post" class="grid gap-3 mt-4"><input name="name" value="<?= e($product["name"]) ?>" class="border rounded-xl px-3 py-2"><input name="price" type="number" min="0" step="500" value="<?= e($product["price"]) ?>" class="border rounded-xl px-3 py-2"><input name="category" value="<?= e($product["category"]) ?>" class="border rounded-xl px-3 py-2"><input name="product_condition" value="<?= e($product["product_condition"]) ?>" class="border rounded-xl px-3 py-2"><input name="image1" value="<?= e($product["image1"]) ?>" class="border rounded-xl px-3 py-2"><textarea name="description" class="border rounded-xl px-3 py-2"><?= e($product["description"]) ?></textarea><button class="bg-blue-600 text-white rounded-xl py-2">Save Changes</button></form></div></main></body></html>
