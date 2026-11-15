<?php
require_once "includes/config.php";
require_login();
$user = current_user($conn);
require_marketplace_access($user);
if ($user["role"] !== "seller") {
    redirect_dashboard("buyer");
}

$id = (int)($_GET["id"] ?? 0);
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ? AND is_blocked = 0 AND is_deleted = 0");
$stmt->bind_param("ii", $id, $user["id"]);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
if (!$product) {
    die("Product not found.");
}

$message = "";
$productImages = get_product_images($conn, $id);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $name = trim($_POST["name"] ?? "");
    $price = (float)($_POST["price"] ?? 0);
    $description = trim($_POST["description"] ?? "");
    $condition = trim($_POST["product_condition"] ?? "");
    $category = trim($_POST["category"] ?? "Other");
    $removeImages = $_POST["remove_images"] ?? [];

    if (!is_array($removeImages)) {
        $message = "The selected images could not be updated.";
    } else {
        try {
            $keptImages = array_values(array_filter(
                $productImages,
                static fn(string $image): bool => !in_array($image, $removeImages, true)
            ));
            $images = array_values(array_unique(array_merge(
                $keptImages,
                upload_product_images($_FILES["images"] ?? [])
            )));
            if (!$images) {
                $message = "Keep at least one product image or upload a replacement.";
            } else {
                $legacyImages = array_pad(array_slice($images, 0, 3), 3, "");
                $conn->begin_transaction();
                $update = $conn->prepare(
                    "UPDATE products
                     SET name = ?, price = ?, description = ?, product_condition = ?, category = ?,
                         image1 = ?, image2 = ?, image3 = ?
                     WHERE id = ? AND seller_id = ?"
                );
                $update->bind_param(
                    "sdssssssii",
                    $name,
                    $price,
                    $description,
                    $condition,
                    $category,
                    $legacyImages[0],
                    $legacyImages[1],
                    $legacyImages[2],
                    $id,
                    $user["id"]
                );

                if ($update->execute()) {
                    save_product_images($conn, $id, $images);
                    $conn->commit();
                    redirect_completion("product_updated", $id);
                }

                $conn->rollback();
                $message = "Update failed.";
            }
        } catch (RuntimeException $exception) {
            if ($conn->errno === 0) {
                $message = $exception->getMessage();
            } else {
                $conn->rollback();
                throw $exception;
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(asset_url("assets/css/app.css")) ?>">
</head>
<body class="bg-slate-50 min-h-screen">
<?php include "includes/navbar.php"; ?>
<main class="mx-auto max-w-3xl p-4">
    <a href="dashboard_seller.php" class="text-blue-700 text-sm">&larr; Seller Dashboard</a>
    <section class="mt-3 rounded-2xl bg-white p-6 shadow-sm">
        <h1 class="text-2xl font-bold text-blue-700">Edit Product</h1>
        <?php if ($message): ?><p role="alert" class="mt-2 text-sm text-red-600"><?= e($message) ?></p><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="mt-4 grid gap-4">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input name="name" value="<?= e($product["name"]) ?>" required placeholder="Product name" class="w-full rounded-xl border px-3 py-2">
            <input name="price" type="number" min="0" step="500" value="<?= e($product["price"]) ?>" required class="w-full rounded-xl border px-3 py-2">
            <input name="category" value="<?= e($product["category"]) ?>" required class="w-full rounded-xl border px-3 py-2">
            <input name="product_condition" value="<?= e($product["product_condition"]) ?>" required class="w-full rounded-xl border px-3 py-2">
            <textarea name="description" rows="5" class="w-full rounded-xl border px-3 py-2"><?= e($product["description"]) ?></textarea>

            <fieldset>
                <legend class="text-sm font-semibold text-slate-800">Product photos</legend>
                <p class="mt-1 text-xs text-slate-500">Select any number of JPG, PNG, GIF, or WEBP images. Maximum 5 MB per image.</p>
                <?php if ($productImages): ?>
                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <?php foreach ($productImages as $image): ?>
                            <label class="relative overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                                <img src="<?= e(asset_url($image)) ?>" alt="<?= e($product["name"]) ?>" class="h-32 w-full object-cover">
                                <span class="flex items-center gap-2 p-2 text-xs text-slate-600">
                                    <input type="checkbox" name="remove_images[]" value="<?= e($image) ?>">
                                    Remove photo
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <label class="mt-4 block text-sm font-medium text-slate-700">
                    Add photos
                    <input name="images[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple class="mt-1 block w-full rounded-xl border px-3 py-2">
                </label>
            </fieldset>

            <button type="submit" class="rounded-xl bg-blue-600 py-2 font-semibold text-white">Save Changes</button>
        </form>
    </section>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
