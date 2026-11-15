<?php
require_once "includes/config.php";
require_login();
$user = current_user($conn);
require_marketplace_access($user);
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
        $images = array_values(array_unique(upload_product_images($_FILES["images"] ?? [])));
        if (!$images) {
            $message = "Choose at least one product photo.";
        } else {
            $legacyImages = array_pad(array_slice($images, 0, 3), 3, "");
            ensure_product_images_table($conn);
            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT INTO products (seller_id, name, category, description, price, product_condition, image1, image2, image3) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssdssss", $user["id"], $name, $category, $description, $price, $condition, $legacyImages[0], $legacyImages[1], $legacyImages[2]);
            if ($stmt->execute()) {
                $productId = (int)$stmt->insert_id;
                save_product_images($conn, $productId, $images);
                $conn->commit();
                redirect_completion("product_uploaded", $productId);
            }
            $conn->rollback();
            $message = "Failed to upload product.";
        }
    } catch (RuntimeException $e) {
        if ($conn->errno === 0) {
            $message = $e->getMessage();
        } else {
            $conn->rollback();
            throw $e;
        }
    }
}
?>
<!doctype html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Upload Product | LOOPLY</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="<?= e(asset_url("assets/css/app.css")) ?>"></head>
<body class="bg-slate-50 min-h-screen"><?php include "includes/navbar.php"; ?><main class="max-w-3xl mx-auto p-4"><a href="dashboard_seller.php" class="text-blue-700 text-sm">&larr; Seller Dashboard</a><div class="bg-white rounded-2xl shadow-sm p-6 mt-3"><h1 class="text-2xl font-bold text-blue-700">Upload Product</h1><?php if ($message): ?><p role="alert" class="mt-2 text-sm text-red-600"><?= e($message) ?></p><?php endif; ?><form method="post" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-3 mt-4"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input name="name" required placeholder="Product name" class="md:col-span-2 border rounded-xl px-3 py-2"><select name="category" class="border rounded-xl px-3 py-2"><option>Books</option><option>Electronics</option><option>Dorm Items</option><option>Fashion</option><option>Study Supplies</option><option>Other</option></select><input name="price" type="number" min="0" step="500" required placeholder="Price" class="border rounded-xl px-3 py-2"><input name="product_condition" required placeholder="Condition" class="md:col-span-2 border rounded-xl px-3 py-2"><textarea name="description" placeholder="Description" class="md:col-span-2 border rounded-xl px-3 py-2"></textarea><label class="md:col-span-2 text-sm font-medium">Product photos<input name="images[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple required class="block w-full border rounded-xl px-3 py-2 mt-1"><span class="mt-1 block text-xs text-slate-500">Select one or more JPG, PNG, GIF, or WEBP images. Maximum 5 MB per image.</span></label><button class="md:col-span-2 bg-blue-600 text-white rounded-xl py-2 font-semibold">Upload</button></form></div></main><?php include "includes/footer.php"; ?></body></html>
