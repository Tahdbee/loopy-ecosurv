<?php
require_once "config.php";
require_login();
$user = current_user($conn);
$sellerId = (int)($_GET["id"] ?? 0);

$sellerQuery = $conn->prepare("SELECT id, full_name, faculty, major, created_at FROM users WHERE id = ? AND role = 'seller'");
$sellerQuery->bind_param("i", $sellerId);
$sellerQuery->execute();
$seller = $sellerQuery->get_result()->fetch_assoc();
if (!$seller) {
    http_response_code(404);
    die("Seller not found.");
}

$reviewMessage = "";
$reviewError = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["submit_review"])) {
    $transactionId = filter_var($_POST["transaction_id"] ?? null, FILTER_VALIDATE_INT);
    $rating = filter_var($_POST["rating"] ?? null, FILTER_VALIDATE_INT);
    $commentInput = $_POST["comment"] ?? "";
    $comment = is_string($commentInput) ? trim($commentInput) : "";

    if ($transactionId === false || $transactionId < 1) {
        $reviewError = "Choose a valid purchase to review.";
    } elseif ($rating === false || $rating < 1 || $rating > 5) {
        $reviewError = "Choose a star rating from 1 to 5.";
    } elseif (strlen($comment) > 4000) {
        $reviewError = "Your comment must be 1,000 characters or fewer.";
    } else {
        $eligible = $conn->prepare(
            "SELECT id FROM transactions
             WHERE id = ? AND buyer_id = ? AND seller_id = ? AND payment_status = 'Completed'"
        );
        $eligible->bind_param("iii", $transactionId, $user["id"], $sellerId);
        $eligible->execute();

        if (!$eligible->get_result()->fetch_assoc()) {
            $reviewError = "You can review this seller after a completed purchase.";
        } else {
            $insert = $conn->prepare(
                "INSERT IGNORE INTO reviews (transaction_id, reviewer_id, reviewee_id, rating, comment)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $insert->bind_param("iiiis", $transactionId, $user["id"], $sellerId, $rating, $comment);
            $insert->execute();
            if ($insert->affected_rows === 1) {
                redirect_completion("review_submitted", $sellerId);
            }
            $reviewMessage = "You have already reviewed this purchase.";
        }
    }
}

$statsQuery = $conn->prepare(
    "SELECT COUNT(*) review_count, COALESCE(AVG(rating), 0) average_rating
     FROM reviews WHERE reviewee_id = ?"
);
$statsQuery->bind_param("i", $sellerId);
$statsQuery->execute();
$reviewStats = $statsQuery->get_result()->fetch_assoc();

$productsQuery = $conn->prepare(
    "SELECT p.id, p.name, p.category, p.price, p.product_condition, p.image1, p.is_sold,
            COALESCE(AVG(r.rating), 0) average_rating, COUNT(r.id) review_count
     FROM products p
     LEFT JOIN transactions t
       ON t.product_id = p.id AND t.seller_id = p.seller_id AND t.payment_status = 'Completed'
     LEFT JOIN reviews r ON r.transaction_id = t.id AND r.reviewee_id = p.seller_id
     WHERE p.seller_id = ?
     GROUP BY p.id
     ORDER BY p.created_at DESC"
);
$productsQuery->bind_param("i", $sellerId);
$productsQuery->execute();
$products = $productsQuery->get_result();

$reviewsQuery = $conn->prepare(
    "SELECT r.rating, r.comment, r.created_at, reviewer.full_name reviewer_name,
            p.id product_id, p.name product_name
     FROM reviews r
     JOIN transactions t ON t.id = r.transaction_id
     JOIN products p ON p.id = t.product_id
     JOIN users reviewer ON reviewer.id = r.reviewer_id
     WHERE r.reviewee_id = ? AND t.seller_id = ?
     ORDER BY r.created_at DESC, r.id DESC"
);
$reviewsQuery->bind_param("ii", $sellerId, $sellerId);
$reviewsQuery->execute();
$reviews = $reviewsQuery->get_result();

$reviewableQuery = $conn->prepare(
    "SELECT t.id transaction_id, p.name product_name
     FROM transactions t
     JOIN products p ON p.id = t.product_id
     WHERE t.buyer_id = ? AND t.seller_id = ? AND t.payment_status = 'Completed'
       AND NOT EXISTS (
           SELECT 1 FROM reviews r
           WHERE r.transaction_id = t.id AND r.reviewer_id = ? AND r.reviewee_id = ?
       )
     ORDER BY t.created_at DESC"
);
$reviewableQuery->bind_param("iiii", $user["id"], $sellerId, $user["id"], $sellerId);
$reviewableQuery->execute();
$reviewablePurchases = $reviewableQuery->get_result();

$roundedAverage = (int)round((float)$reviewStats["average_rating"]);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($seller["full_name"]) ?> | LOOPLY Seller</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-50 min-h-screen">
    <?php include "includes/navbar.php"; ?>
    <main class="max-w-7xl mx-auto p-4 space-y-8">
        <section class="seller-hero bg-white rounded-2xl shadow-sm p-6 md:p-8">
            <p class="text-sm font-bold uppercase tracking-widest text-blue-600">LOOPLY Seller</p>
            <h1 class="mt-2 text-3xl md:text-4xl font-bold"><?= e($seller["full_name"]) ?></h1>
            <p class="mt-2 text-sm text-slate-500"><?= e($seller["faculty"] ?: "President University") ?><?= $seller["major"] ? " / " . e($seller["major"]) : "" ?></p>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <span class="seller-stars" aria-label="<?= number_format((float)$reviewStats["average_rating"], 1) ?> out of 5 stars"><?= str_repeat("★", $roundedAverage) ?><span><?= str_repeat("★", 5 - $roundedAverage) ?></span></span>
                <span class="font-semibold"><?= number_format((float)$reviewStats["average_rating"], 1) ?> / 5</span>
                <span class="text-sm text-slate-500"><?= (int)$reviewStats["review_count"] ?> <?= (int)$reviewStats["review_count"] === 1 ? "review" : "reviews" ?></span>
            </div>
        </section>

        <section>
            <div class="mb-4 flex items-end justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-bold">Seller's listings</h2>
                    <p class="mt-1 text-sm text-slate-500">Browse everything listed by <?= e($seller["full_name"]) ?>.</p>
                </div>
                <span class="text-sm text-slate-500"><?= $products->num_rows ?> items</span>
            </div>
            <?php if ($products->num_rows === 0): ?>
                <div class="bg-white rounded-2xl p-6 text-slate-500">This seller has no listings yet.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    <?php while ($product = $products->fetch_assoc()): ?>
                        <?php $productStars = (int)round((float)$product["average_rating"]); ?>
                        <article class="product-card bg-white rounded-2xl shadow-sm overflow-hidden">
                            <a href="product_detail.php?id=<?= (int)$product["id"] ?>">
                                <img src="<?= e($product["image1"]) ?>" alt="<?= e($product["name"]) ?>" class="w-full h-52 object-cover">
                            </a>
                            <div class="p-5">
                                <div class="flex items-start justify-between gap-2">
                                    <h3 class="font-bold text-lg"><?= e($product["name"]) ?></h3>
                                    <?php if ((int)$product["is_sold"] === 1): ?><span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-500">Sold</span><?php endif; ?>
                                </div>
                                <p class="text-sm text-slate-500 mt-1"><?= e($product["category"]) ?> · <?= e($product["product_condition"]) ?></p>
                                <p class="text-blue-700 font-semibold mt-3">Rp <?= number_format((float)$product["price"], 0, ",", ".") ?></p>
                                <p class="mt-2 text-sm"><span class="seller-stars" aria-label="<?= number_format((float)$product["average_rating"], 1) ?> out of 5 stars"><?= str_repeat("★", $productStars) ?><span><?= str_repeat("★", 5 - $productStars) ?></span></span> <span class="text-slate-500">(<?= (int)$product["review_count"] ?>)</span></p>
                                <a href="product_detail.php?id=<?= (int)$product["id"] ?>" class="mt-4 inline-block bg-blue-600 text-white rounded-lg px-4 py-2 text-sm">View item</a>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="grid lg:grid-cols-[minmax(0,1fr)_20rem] gap-6 items-start">
            <div>
                <h2 class="text-2xl font-bold mb-4">Buyer reviews</h2>
                <?php if ($reviews->num_rows === 0): ?>
                    <div class="bg-white rounded-2xl p-6 text-slate-500">No reviews yet. Completed buyers can leave the first review.</div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php while ($review = $reviews->fetch_assoc()): ?>
                            <article class="bg-white rounded-2xl shadow-sm p-5">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h3 class="font-semibold"><?= e($review["reviewer_name"]) ?></h3>
                                        <a href="product_detail.php?id=<?= (int)$review["product_id"] ?>" class="text-sm text-blue-700"><?= e($review["product_name"]) ?></a>
                                    </div>
                                    <time class="text-xs text-slate-500"><?= e($review["created_at"]) ?></time>
                                </div>
                                <p class="seller-stars mt-3" aria-label="<?= (int)$review["rating"] ?> out of 5 stars"><?= str_repeat("★", (int)$review["rating"]) ?><span><?= str_repeat("★", 5 - (int)$review["rating"]) ?></span></p>
                                <?php if (trim((string)$review["comment"]) !== ""): ?>
                                    <p class="mt-2 whitespace-pre-line text-slate-600"><?= e($review["comment"]) ?></p>
                                <?php else: ?>
                                    <p class="mt-2 text-sm italic text-slate-400">No written comment.</p>
                                <?php endif; ?>
                            </article>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="bg-white rounded-2xl shadow-sm p-5">
                <h2 class="text-xl font-bold">Write a review</h2>
                <p class="mt-1 text-sm text-slate-500">Reviews are available after a completed purchase.</p>
                <?php if ($reviewMessage): ?><p role="status" class="mt-3 text-sm text-emerald-700"><?= e($reviewMessage) ?></p><?php endif; ?>
                <?php if ($reviewError): ?><p role="alert" class="mt-3 text-sm text-red-700"><?= e($reviewError) ?></p><?php endif; ?>
                <?php if ($reviewablePurchases->num_rows === 0): ?>
                    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-500">No completed purchases waiting for a review.</p>
                <?php else: ?>
                    <form method="post" class="mt-4 space-y-3">
                        <label class="block text-sm font-medium" for="transaction_id">Purchase</label>
                        <select id="transaction_id" name="transaction_id" required class="w-full border rounded-xl px-3 py-2">
                            <?php while ($purchase = $reviewablePurchases->fetch_assoc()): ?>
                                <option value="<?= (int)$purchase["transaction_id"] ?>"><?= e($purchase["product_name"]) ?></option>
                            <?php endwhile; ?>
                        </select>
                        <fieldset>
                            <legend class="block text-sm font-medium mb-1">Your rating</legend>
                            <div class="seller-rating-control" role="radiogroup" aria-label="Seller rating">
                                <?php for ($star = 5; $star >= 1; $star--): ?>
                                    <input type="radio" id="rating-<?= $star ?>" name="rating" value="<?= $star ?>" required>
                                    <label for="rating-<?= $star ?>" aria-label="<?= $star ?> stars">★</label>
                                <?php endfor; ?>
                            </div>
                        </fieldset>
                        <label class="block text-sm font-medium" for="comment">Comment <span class="font-normal text-slate-400">(optional)</span></label>
                        <textarea id="comment" name="comment" maxlength="1000" rows="4" class="w-full border rounded-xl px-3 py-2" placeholder="Share your experience with this seller"></textarea>
                        <button type="submit" name="submit_review" class="w-full bg-blue-600 text-white rounded-xl px-4 py-2 font-semibold">Submit review</button>
                    </form>
                <?php endif; ?>
            </aside>
        </section>
    </main>
    <?php include "includes/footer.php"; ?>
</body>
</html>
