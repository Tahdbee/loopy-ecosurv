<?php
require_once "config.php";
require_login();
ensure_seller_payment_columns($conn);
$user = current_user($conn);
$completion = $_SESSION["completion"] ?? null;
unset($_SESSION["completion"]);

if (!is_array($completion) || !isset($completion["step"])) {
    redirect_dashboard($user["role"]);
}

$step = $completion["step"];
$itemId = (int)($completion["item_id"] ?? 0);
$dashboardUrl = $user["role"] === "seller" ? "dashboard_seller.php" : "dashboard_buyer.php";
$steps = [
    "registered" => [
        "title" => "Welcome to LOOPLY!",
        "description" => "Your account is ready. Start exploring the marketplace or set up your seller space.",
        "next_title" => "Choose what to do next",
        "next_description" => "Browse items, or open your dashboard to manage your account.",
        "next_label" => "Go to my dashboard",
        "next_url" => $dashboardUrl,
        "secondary_label" => "Explore marketplace",
        "secondary_url" => "index.php",
    ],
    "product_uploaded" => [
        "title" => "Your listing is live!",
        "description" => "Your product has been added to the LOOPLY marketplace.",
        "next_title" => "Check your listing",
        "next_description" => "Review how it looks, then keep an eye on your seller dashboard for buyer questions and orders.",
        "next_label" => "View my product",
        "next_url" => $itemId > 0 ? "product_detail.php?id=" . $itemId : "dashboard_seller.php",
        "secondary_label" => "Open seller dashboard",
        "secondary_url" => "dashboard_seller.php",
    ],
    "product_updated" => [
        "title" => "Changes saved",
        "description" => "Your product details have been updated successfully.",
        "next_title" => "Continue managing your listing",
        "next_description" => "Check the updated product page or return to your seller dashboard.",
        "next_label" => "View updated product",
        "next_url" => $itemId > 0 ? "product_detail.php?id=" . $itemId : "dashboard_seller.php",
        "secondary_label" => "Open seller dashboard",
        "secondary_url" => "dashboard_seller.php",
    ],
    "review_submitted" => [
        "title" => "Review submitted",
        "description" => "Thanks for sharing your experience. Your feedback helps the LOOPLY community shop with confidence.",
        "next_title" => "What happens next?",
        "next_description" => "Your review is now visible on the seller profile. You can continue browsing or check your other orders.",
        "next_label" => "View seller profile",
        "next_url" => $itemId > 0 ? "seller_profile.php?id=" . $itemId : "dashboard_buyer.php",
        "secondary_label" => "View my orders",
        "secondary_url" => "dashboard_buyer.php",
    ],
    "profile_updated" => [
        "title" => "Profile updated",
        "description" => "Your account changes have been saved.",
        "next_title" => "Continue to your dashboard",
        "next_description" => "Your dashboard shows the tools and activity available for your current account role.",
        "next_label" => "Open my dashboard",
        "next_url" => $dashboardUrl,
        "secondary_label" => "View my profile",
        "secondary_url" => "profile.php",
    ],
    "seller_payment_settings_saved" => [
        "title" => "Payment details saved",
        "description" => "Your payment instructions and QR code are ready to share with buyers after you accept their orders.",
        "next_title" => "Check your seller orders",
        "next_description" => "When a buyer places an order, accept it from your dashboard. They will then see your payment details and can upload proof for you to verify.",
        "next_label" => "Open seller dashboard",
        "next_url" => "dashboard_seller.php",
        "secondary_label" => "Edit payment details",
        "secondary_url" => "seller_payment_settings.php",
    ],
    "order_created" => [
        "title" => "Order placed",
        "description" => "Your order request has been sent to the seller.",
        "next_title" => "Follow your order",
        "next_description" => "Open your buyer dashboard to see the status and the next payment or meet-up step.",
        "next_label" => "View my order",
        "next_url" => "dashboard_buyer.php",
        "secondary_label" => "Continue browsing",
        "secondary_url" => "index.php",
    ],
    "order_accepted" => [
        "title" => "Order accepted",
        "description" => "You accepted the buyer's order request.",
        "next_title" => "Keep the order moving",
        "next_description" => "For cash, meet the buyer and upload the receipt after payment. For QR, wait for the buyer's payment proof and verify it.",
        "next_label" => "Manage this order",
        "next_url" => "dashboard_seller.php",
        "secondary_label" => "View marketplace",
        "secondary_url" => "index.php",
    ],
    "order_declined" => [
        "title" => "Order declined",
        "description" => "The request was cancelled and the buyer can see the updated status.",
        "next_title" => "Continue selling",
        "next_description" => "Return to your seller dashboard to manage other orders and listings.",
        "next_label" => "Open seller dashboard",
        "next_url" => "dashboard_seller.php",
        "secondary_label" => "View marketplace",
        "secondary_url" => "index.php",
    ],
    "payment_proof_uploaded" => [
        "title" => "Payment proof uploaded",
        "description" => "Your receipt has been attached to the order.",
        "next_title" => "Wait for seller verification",
        "next_description" => "The seller will review your receipt and update the order. You can check its status in your buyer dashboard.",
        "next_label" => "Track my order",
        "next_url" => "dashboard_buyer.php",
        "secondary_label" => "Explore marketplace",
        "secondary_url" => "index.php",
    ],
    "cash_receipt_uploaded" => [
        "title" => "Order completed",
        "description" => "The cash receipt is saved and the product is marked as sold.",
        "next_title" => "Review the completed order",
        "next_description" => "You can confirm the order status in your seller dashboard and continue managing your listings.",
        "next_label" => "Open seller dashboard",
        "next_url" => "dashboard_seller.php",
        "secondary_label" => "View marketplace",
        "secondary_url" => "index.php",
    ],
    "seller_order_completed" => [
        "title" => "Payment verified",
        "description" => "The order is complete and the product has been marked as sold.",
        "next_title" => "Manage your store",
        "next_description" => "Review your completed order or continue managing your other listings.",
        "next_label" => "Open seller dashboard",
        "next_url" => "dashboard_seller.php",
        "secondary_label" => "View marketplace",
        "secondary_url" => "index.php",
    ],
    "order_completed" => [
        "title" => "Order completed",
        "description" => "The transaction is complete and the product has been marked as sold.",
        "next_title" => "Leave feedback or keep browsing",
        "next_description" => "You can review the seller from your buyer dashboard, or explore more listings.",
        "next_label" => "View my orders",
        "next_url" => "dashboard_buyer.php",
        "secondary_label" => "Explore marketplace",
        "secondary_url" => "index.php",
    ],
    "buyer_marked_complete" => [
        "title" => "Confirmation recorded",
        "description" => "Your part of the order is marked complete.",
        "next_title" => "Wait for the seller",
        "next_description" => "The order will finish once the seller confirms their part. You can follow its status from your buyer dashboard.",
        "next_label" => "Track my order",
        "next_url" => "dashboard_buyer.php",
        "secondary_label" => "Explore marketplace",
        "secondary_url" => "index.php",
    ],
    "seller_marked_complete" => [
        "title" => "Update recorded",
        "description" => "Your part of the order has been marked complete.",
        "next_title" => "What happens next?",
        "next_description" => "The buyer may still need to confirm the order. Check your seller dashboard for status updates.",
        "next_label" => "Track the order",
        "next_url" => "dashboard_seller.php",
        "secondary_label" => "View marketplace",
        "secondary_url" => "index.php",
    ],
];

if (!isset($steps[$step])) {
    redirect_dashboard($user["role"]);
}
$content = $steps[$step];
$orderPaymentInfo = null;
if ($step === "order_created" && $itemId > 0) {
    $orderQuery = $conn->prepare(
        "SELECT t.payment_flow, t.payment_type, t.payment_method, t.amount, t.seller_accepted,
                p.name product_name, u.payment_instructions, u.payment_qr_image
         FROM transactions t
         JOIN products p ON p.id = t.product_id
         JOIN users u ON u.id = t.seller_id
         WHERE t.id = ? AND t.buyer_id = ?"
    );
    $orderQuery->bind_param("ii", $itemId, $user["id"]);
    $orderQuery->execute();
    $orderPaymentInfo = $orderQuery->get_result()->fetch_assoc();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($content["title"]) ?> | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-50 min-h-screen">
<?php include "includes/navbar.php"; ?>
<main class="completion-page mx-auto flex min-h-[70vh] max-w-3xl items-center justify-center p-4">
    <section class="completion-card w-full rounded-3xl border border-white/70 bg-white p-7 text-center shadow-xl md:p-10">
        <div class="completion-check" role="img" aria-label="Completed">
            <svg viewBox="0 0 52 52" aria-hidden="true"><path d="M14 27.5 22 35l16-18" /></svg>
        </div>
        <p class="mt-6 text-xs font-bold uppercase tracking-[0.24em] text-blue-700">LOOPLY · All set</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900 md:text-4xl"><?= e($content["title"]) ?></h1>
        <p class="mx-auto mt-3 max-w-xl text-slate-600"><?= e($content["description"]) ?></p>

        <?php if ($orderPaymentInfo): ?>
            <div class="completion-order mt-6 rounded-2xl border border-orange-100 bg-orange-50/80 p-4 text-left">
                <p class="font-semibold text-slate-900">Order #<?= $itemId ?> · <?= e($orderPaymentInfo["product_name"]) ?></p>
                <p class="mt-1 text-sm text-slate-600">Total: Rp <?= number_format((float)$orderPaymentInfo["amount"], 0, ",", ".") ?> · <?= e($orderPaymentInfo["payment_method"]) ?></p>
                <?php if ($orderPaymentInfo["payment_flow"] === "gopay_qr"): ?>
                    <?php if ((int)$orderPaymentInfo["seller_accepted"] !== 1): ?>
                        <p class="mt-3 text-sm text-slate-700">Wait for the seller to accept your order. Their payment instructions and personal QR code will appear in your buyer dashboard afterward.</p>
                    <?php elseif (!empty($orderPaymentInfo["payment_qr_image"])): ?>
                        <img src="<?= e($orderPaymentInfo["payment_qr_image"]) ?>" alt="Seller QRIS payment code" class="mx-auto mt-4 max-h-64 rounded-xl border bg-white p-2">
                    <?php else: ?>
                        <p class="mt-3 text-sm text-slate-700">No seller QR code is available. If bank or e-wallet details are shown below, use those instructions instead.</p>
                    <?php endif; ?>
                    <?php if ((int)$orderPaymentInfo["seller_accepted"] === 1 && !empty($orderPaymentInfo["payment_instructions"])): ?>
                        <div class="mt-3 whitespace-pre-line rounded-xl bg-white p-3 text-sm text-slate-700"><?= e($orderPaymentInfo["payment_instructions"]) ?></div>
                    <?php endif; ?>
                    <p class="mt-3 text-sm text-slate-700">After paying, upload your payment receipt from the buyer dashboard. The seller will confirm payment after reviewing it.</p>
                <?php else: ?>
                    <p class="mt-3 text-sm text-slate-700">Next, wait for the seller to accept your order and confirm the meet-up details.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="completion-next mt-7 rounded-2xl bg-slate-50 p-5 text-left">
            <p class="text-xs font-bold uppercase tracking-widest text-slate-500">Your next step</p>
            <h2 class="mt-2 text-lg font-bold text-slate-900"><?= e($content["next_title"]) ?></h2>
            <p class="mt-1 text-sm leading-6 text-slate-600"><?= e($content["next_description"]) ?></p>
            <div class="mt-5 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="<?= e($content["next_url"]) ?>" class="completion-primary inline-flex items-center justify-center rounded-xl px-5 py-3 font-semibold text-white"><?= e($content["next_label"]) ?><span aria-hidden="true" class="ml-2">&rarr;</span></a>
                <a href="<?= e($content["secondary_url"]) ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 font-semibold text-slate-700 hover:border-violet-300"><?= e($content["secondary_label"]) ?></a>
            </div>
        </div>
    </section>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
