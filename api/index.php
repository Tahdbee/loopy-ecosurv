<?php
require_once "includes/config.php";
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketplace | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-slate-50 min-h-screen">
<?php include "includes/navbar.php"; ?>
<main class="landing-page mx-auto max-w-7xl p-4">
    <section class="landing-hero scroll-reveal">
        <div class="landing-hero-glow landing-hero-glow-violet" aria-hidden="true"></div>
        <div class="landing-hero-glow landing-hero-glow-orange" aria-hidden="true"></div>
        <div class="landing-hero-copy">
            <p class="landing-eyebrow"><span class="landing-live-dot"></span>The campus marketplace</p>
            <h1>By Students.<br><span>For Students.</span></h1>
            <p class="landing-subtitle">Good finds, good prices, right around campus.</p>
            <div class="landing-hero-actions">
                <a class="landing-cta" href="#marketplace">Find your next favorite <span aria-hidden="true">↘</span></a>
                <span class="landing-social-proof">Made for your campus community</span>
            </div>
            <div class="landing-category-pills" aria-label="Browse popular categories">
                <?php foreach (array_slice($categories, 0, 4) as $cat): ?>
                    <a href="index.php?category=<?= rawurlencode($cat) ?>#marketplace"><?= e($cat) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="landing-hero-art" aria-hidden="true">
            <div class="landing-orbit landing-orbit-one"></div>
            <div class="landing-orbit landing-orbit-two"></div>
            <div class="landing-spark landing-spark-one">✦</div>
            <div class="landing-spark landing-spark-two">✧</div>
            <div class="landing-floating-card landing-floating-card-top"><span>✦</span><div><strong>Finds worth finding</strong><small>Fresh from your campus</small></div></div>
            <div class="landing-bag"><div class="landing-bag-handle"></div><span>looply.</span><i></i></div>
            <div class="landing-floating-card landing-floating-card-bottom"><span>↗</span><div><strong>Your next favorite</strong><small>Could be just a scroll away</small></div></div>
        </div>
        <div class="landing-hero-grain" aria-hidden="true"></div>
    </section>

    <section id="marketplace" class="landing-marketplace scroll-reveal">
        <div class="landing-section-heading">
            <div>
                <p class="landing-section-kicker">A little bit of everything</p>
                <h2>Find something <span>you love.</span></h2>
            </div>
            <p>Explore student listings and discover something useful, delightful, or both.</p>
        </div>
        <div class="landing-search-panel">
            <form class="landing-search-form">
                <label class="landing-search-input">
                    <span aria-hidden="true">⌕</span>
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search your next great find..." aria-label="Search products">
                </label>
                <select name="category" aria-label="Filter by category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= $category === $cat ? "selected" : "" ?>><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="landing-filter-button">Explore items <span aria-hidden="true">→</span></button>
            </form>
        </div>
        <div class="landing-products-heading">
            <h2><?= $search !== "" || $category !== "" ? "Your LOOPLY finds" : "Latest student finds" ?></h2>
            <span><?= $products->num_rows ?> <?= $products->num_rows === 1 ? "item" : "items" ?></span>
        </div>
        <?php if ($products->num_rows === 0): ?>
            <div class="landing-empty-state">
                <span aria-hidden="true">✦</span>
                <h3>No finds just yet</h3>
                <p>Try a different search or category. Your next favorite is out there.</p>
                <a href="index.php#marketplace">See all items</a>
            </div>
        <?php else: ?>
            <div class="landing-product-grid">
                <?php $productIndex = 0; ?>
                <?php while ($row = $products->fetch_assoc()): ?>
                    <article class="product-card landing-product-card scroll-reveal" style="--reveal-delay: <?= ($productIndex % 4) * 70 ?>ms">
                        <a class="landing-product-image" href="product_detail.php?id=<?= (int)$row["id"] ?>" aria-label="View <?= e($row["name"]) ?>">
                            <img src="<?= e(asset_url($row["image1"])) ?>" alt="<?= e($row["name"]) ?>" loading="lazy">
                            <span class="landing-product-category"><?= e($row["category"]) ?></span>
                            <span class="landing-image-arrow" aria-hidden="true">↗</span>
                        </a>
                        <div class="landing-product-info">
                            <div class="landing-product-title-row">
                                <h3><?= e($row["name"]) ?></h3>
                                <span class="landing-product-spark" aria-hidden="true">✦</span>
                            </div>
                            <p class="landing-product-price">Rp <?= number_format((float)$row["price"], 0, ",", ".") ?></p>
                            <a href="seller_profile.php?id=<?= (int)$row["seller_id"] ?>" class="landing-product-seller"><?= e($row["seller_name"]) ?></a>
                            <p class="landing-product-major"><?= e($row["faculty"]) ?> / <?= e($row["major"]) ?></p>
                            <a href="product_detail.php?id=<?= (int)$row["id"] ?>" class="landing-view-product">Take a closer look <span aria-hidden="true">→</span></a>
                        </div>
                    </article>
                    <?php $productIndex++; ?>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<script>
document.documentElement.classList.add("has-scroll-reveal");

const revealTargets = document.querySelectorAll(".scroll-reveal");
if ("IntersectionObserver" in window) {
    const revealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add("is-visible");
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12, rootMargin: "0px 0px -36px 0px" });
    revealTargets.forEach((target) => revealObserver.observe(target));
} else {
    revealTargets.forEach((target) => target.classList.add("is-visible"));
}

document.querySelectorAll(".landing-view-product").forEach((link) => {
    link.addEventListener("click", (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const card = link.closest(".landing-product-card");
        if (!card || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
        event.preventDefault();
        card.classList.add("is-selected");
        window.setTimeout(() => { window.location.href = link.href; }, 260);
    });
});
</script>
<?php include "includes/footer.php"; ?>
</body>
</html>
