<?php
require_once "includes/config.php";
require_once "includes/validation.php";
require_login();
$user = current_user($conn);
if ($user["role"] !== "seller") {
    redirect_dashboard("buyer");
}
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $name = trim($_POST["name"] ?? "");
    $category = trim($_POST["category"] ?? "Other");
    $description = trim($_POST["description"] ?? "");
    $price = (float)($_POST["price"] ?? 0);
    $condition = trim($_POST["product_condition"] ?? "Used - Good");
    try {
        $validationError = validate_product_fields($name, $price, $description, $condition, $category);
        if ($validationError !== null) {
            throw new RuntimeException($validationError);
        }
        $image1 = upload_product_image($_FILES["image1"] ?? [], "https://placehold.co/600x400?text=Looply+Item");
        $image2 = upload_product_image($_FILES["image2"] ?? []);
        $image3 = upload_product_image($_FILES["image3"] ?? []);
        $stmt = $conn->prepare("INSERT INTO products (seller_id, name, category, description, price, product_condition, image1, image2, image3) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssdssss", $user["id"], $name, $category, $description, $price, $condition, $image1, $image2, $image3);
        if ($stmt->execute()) {
            redirect_completion("product_uploaded", (int)$stmt->insert_id);
        }
        $message = "Failed to upload product.";
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }
}
?>
<!doctype html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Upload Product | LOOPLY</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>"></head>
<body class="bg-slate-50 min-h-screen"><?php include "includes/navbar.php"; ?><main class="max-w-3xl mx-auto p-4"><a href="dashboard_seller.php" class="text-blue-700 text-sm">&larr; Seller Dashboard</a><div class="bg-white rounded-2xl shadow-sm p-6 mt-3"><h1 class="text-2xl font-bold text-blue-700">Upload Product</h1><?php if ($message): ?><p class="mt-2 text-sm <?= str_starts_with($message, "Product uploaded") ? "text-emerald-700" : "text-red-600" ?>"><?= e($message) ?></p><?php endif; ?><form method="post" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-3 mt-4"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input name="name" required minlength="3" maxlength="150" placeholder="Product name" class="md:col-span-2 border rounded-xl px-3 py-2"><select name="category" class="border rounded-xl px-3 py-2"><option>Books</option><option>Electronics</option><option>Dorm Items</option><option>Fashion</option><option>Study Supplies</option><option>Other</option></select><input name="price" type="number" min="500" max="100000000" step="500" required placeholder="Price" class="border rounded-xl px-3 py-2"><input name="product_condition" required minlength="2" maxlength="60" placeholder="Condition" class="md:col-span-2 border rounded-xl px-3 py-2"><textarea name="description" maxlength="2000" placeholder="Description" class="md:col-span-2 border rounded-xl px-3 py-2"></textarea><label class="md:col-span-2 text-sm">Main image<input name="image1" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required class="block w-full border rounded-xl px-3 py-2 mt-1"></label><label class="text-sm">Image 2 (optional)<input name="image2" type="file" accept="image/jpeg,image/png,image/gif,image/webp" class="block w-full border rounded-xl px-3 py-2 mt-1"></label><label class="text-sm">Image 3 (optional)<input name="image3" type="file" accept="image/jpeg,image/png,image/gif,image/webp" class="block w-full border rounded-xl px-3 py-2 mt-1"></label><p class="md:col-span-2 text-xs text-slate-500">JPG, PNG, GIF, or WEBP. Maximum 5 MB per image.</p><button class="md:col-span-2 bg-blue-600 text-white rounded-xl py-2 font-semibold">Upload</button></form></div></main><?php include "includes/footer.php"; ?></body></html>
