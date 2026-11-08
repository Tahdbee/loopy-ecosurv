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
        <div class="looply-nav-account">
            <details class="looply-account-menu">
                <summary class="looply-account-pill" aria-label="Account menu for <?= e($user["full_name"] ?? "My account") ?>">
                    <span class="looply-account-avatar" aria-hidden="true">
                        <?php if (!empty($user["profile_image"])): ?>
                            <img src="<?= e($user["profile_image"]) ?>" alt="">
                        <?php else: ?>
                            <?= e(strtoupper(substr($user["full_name"] ?? "M", 0, 1))) ?>
                        <?php endif; ?>
                    </span>
                    <span class="looply-account-name"><?= e($user["full_name"] ?? "My account") ?></span>
                    <span class="looply-account-chevron" aria-hidden="true">⌄</span>
                </summary>
                <div class="looply-account-dropdown">
                    <div class="looply-account-menu-heading">
                        <strong><?= e($user["full_name"] ?? "My account") ?></strong>
                        <span><?= e($user["email"] ?? "") ?></span>
                    </div>
                    <a href="profile.php#profile">
                        <span aria-hidden="true">♙</span>
                        <span>Edit profile</span>
                    </a>
                    <a href="profile.php#settings">
                        <span aria-hidden="true">⚙</span>
                        <span>Settings</span>
                    </a>
                    <div class="looply-account-menu-divider"></div>
                    <a href="logout.php" class="looply-account-logout">
                        <span aria-hidden="true">↪</span>
                        <span>Log out</span>
                    </a>
                </div>
            </details>
        </div>
        <details class="looply-mobile-menu">
            <summary aria-label="Toggle navigation menu"><span></span><span></span><span></span></summary>
            <div class="looply-mobile-menu-panel">
                <?php foreach ($navItems as $item): ?>
                    <a href="<?= e($item["href"]) ?>" class="<?= $item["active"] ? "is-active" : "" ?>"><?= e($item["label"]) ?></a>
                <?php endforeach; ?>
                <a href="profile.php#profile">Edit profile</a>
                <a href="profile.php#settings">Settings</a>
                <a href="logout.php">Log out</a>
            </div>
        </details>
    </nav>
</header>
