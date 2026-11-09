<?php
require_once "includes/config.php";
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
    verify_csrf_token();
    $name = trim($_POST["name"] ?? "");
    $price = (float)($_POST["price"] ?? 0);
    $description = trim($_POST["description"] ?? "");
    $condition = trim($_POST["product_condition"] ?? "");
    $category = trim($_POST["category"] ?? "Other");
    try {
        $image1 = upload_product_image($_FILES["image1"] ?? [], $product["image1"]);
        $image2 = upload_product_image($_FILES["image2"] ?? [], $product["image2"]);
        $image3 = upload_product_image($_FILES["image3"] ?? [], $product["image3"]);
        $u = $conn->prepare("UPDATE products SET name=?, price=?, description=?, product_condition=?, category=?, image1=?, image2=?, image3=? WHERE id=? AND seller_id=?");
        $u->bind_param("sdssssssii", $name, $price, $description, $condition, $category, $image1, $image2, $image3, $id, $user["id"]);
        if ($u->execute()) {
            redirect_completion("product_updated", $id);
        }
        $message = "Update failed.";
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }
}
?>
<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Edit Product | LOOPLY</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="/assets/css/app.css"></head><body class="bg-slate-50 min-h-screen"><?php include "includes/navbar.php"; ?><main class="max-w-3xl mx-auto p-4"><a href="dashboard_seller.php" class="text-blue-700 text-sm">&larr; Seller Dashboard</a><div class="bg-white rounded-2xl shadow-sm p-6 mt-3"><h1 class="text-2xl font-bold text-blue-700">Edit Product</h1><?php if ($message): ?><p class="text-red-600 text-sm mt-2"><?= e($message) ?></p><?php endif; ?><form method="post" enctype="multipart/form-data" class="grid gap-3 mt-4"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input name="name" value="<?= e($product["name"]) ?>" class="border rounded-xl px-3 py-2"><input name="price" type="number" min="0" step="500" value="<?= e($product["price"]) ?>" class="border rounded-xl px-3 py-2"><input name="category" value="<?= e($product["category"]) ?>" class="border rounded-xl px-3 py-2"><input name="product_condition" value="<?= e($product["product_condition"]) ?>" class="border rounded-xl px-3 py-2"><label class="text-sm">Replace main image (optional)<input name="image1" type="file" accept="image/jpeg,image/png,image/gif,image/webp" class="block w-full border rounded-xl px-3 py-2 mt-1"></label><label class="text-sm">Replace image 2 (optional)<input name="image2" type="file" accept="image/jpeg,image/png,image/gif,image/webp" class="block w-full border rounded-xl px-3 py-2 mt-1"></label><label class="text-sm">Replace image 3 (optional)<input name="image3" type="file" accept="image/jpeg,image/png,image/gif,image/webp" class="block w-full border rounded-xl px-3 py-2 mt-1"></label><textarea name="description" class="border rounded-xl px-3 py-2"><?= e($product["description"]) ?></textarea><p class="text-xs text-slate-500">JPG, PNG, GIF, or WEBP. Maximum 5 MB per image.</p><button class="bg-blue-600 text-white rounded-xl py-2">Save Changes</button></form></div></main><?php include "includes/footer.php"; ?></body></html>
