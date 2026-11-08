<?php
$currentPage = basename($_SERVER["PHP_SELF"]);
$dashboardPage = $user["role"] === "seller" ? "dashboard_seller.php" : "dashboard_buyer.php";
$navItems = [
    ["label" => "Explore", "href" => "index.php", "active" => in_array($currentPage, ["index.php", "product_detail.php", "seller_profile.php"], true)],
    ["label" => "Favorites", "href" => "favorites.php", "active" => $currentPage === "favorites.php"],
    ["label" => "Messages", "href" => "messages.php", "active" => in_array($currentPage, ["messages.php", "chat.php"], true)],
    ["label" => "Dashboard", "href" => $dashboardPage, "active" => in_array($currentPage, ["dashboard_buyer.php", "dashboard_seller.php"], true)],
];
if ($user["role"] === "seller") {
    $navItems[] = ["label" => "Upload product", "href" => "upload_product.php", "active" => $currentPage === "upload_product.php"];
    $navItems[] = ["label" => "Payment settings", "href" => "seller_payment_settings.php", "active" => $currentPage === "seller_payment_settings.php"];
}
?>
<header class="looply-header">
    <nav class="looply-nav-shell" aria-label="Main navigation">
        <a href="index.php" class="looply-nav-logo" aria-label="LOOPLY home">
            <img src="assets/img/looplylogo.png" alt="">
        </a>
        <div class="looply-nav-links">
            <?php foreach ($navItems as $item): ?>
                <a href="<?= e($item["href"]) ?>" class="looply-nav-link <?= $item["active"] ? "is-active" : "" ?>" <?= $item["active"] ? 'aria-current="page"' : "" ?>><?= e($item["label"]) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="looply-nav-account" style="display:flex!important">
            <a href="profile.php" class="looply-account-pill" style="max-width:min(38vw,15rem)" title="View your account"><?= e($user["full_name"] ?? "My account") ?></a>
            <a href="logout.php" class="looply-logout-link">Log out</a>
        </div>
        <details class="looply-mobile-menu">
            <summary aria-label="Toggle navigation menu"><span></span><span></span><span></span></summary>
            <div class="looply-mobile-menu-panel">
                <?php foreach ($navItems as $item): ?>
                    <a href="<?= e($item["href"]) ?>" class="<?= $item["active"] ? "is-active" : "" ?>"><?= e($item["label"]) ?></a>
                <?php endforeach; ?>
                <a href="profile.php">My profile · <?= e($user["full_name"] ?? "My account") ?></a>
                <a href="logout.php">Log out</a>
            </div>
        </details>
    </nav>
</header>
