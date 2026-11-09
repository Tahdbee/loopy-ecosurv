<?php
require_once "includes/config.php";
require_login();
$user = current_user($conn);
if (in_array($user["role"], ["admin", "staff"], true)) {
    redirect_dashboard($user["role"]);
}

$targetType = $_GET["type"] ?? $_POST["target_type"] ?? "";
$targetId = filter_var($_GET["id"] ?? $_POST["target_id"] ?? null, FILTER_VALIDATE_INT);
$returnUrl = "index.php";
$targetLabel = "";
$targetValid = false;

if (in_array($targetType, ["user", "product", "transaction"], true) && $targetId !== false && $targetId > 0) {
    if ($targetType === "user") {
        $check = $conn->prepare("SELECT full_name FROM users WHERE id = ? AND role IN ('buyer', 'seller') AND id <> ? AND is_active = 1");
        $check->bind_param("ii", $targetId, $user["id"]);
        $check->execute();
        $target = $check->get_result()->fetch_assoc();
        if ($target) {
            $targetLabel = "Seller: " . $target["full_name"];
            $returnUrl = "seller_profile.php?id=" . $targetId;
            $targetValid = true;
        }
    } elseif ($targetType === "product") {
        $check = $conn->prepare("SELECT name, seller_id FROM products WHERE id = ? AND seller_id <> ?");
        $check->bind_param("ii", $targetId, $user["id"]);
        $check->execute();
        $target = $check->get_result()->fetch_assoc();
        if ($target) {
            $targetLabel = "Product: " . $target["name"];
            $returnUrl = "product_detail.php?id=" . $targetId;
            $targetValid = true;
        }
    } else {
        $check = $conn->prepare("SELECT id FROM transactions WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
        $check->bind_param("iii", $targetId, $user["id"], $user["id"]);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            $targetLabel = "Transaction #" . $targetId;
            $returnUrl = $user["role"] === "seller" ? "dashboard_seller.php" : "dashboard_buyer.php";
            $targetValid = true;
        }
    }
}

if (!$targetValid) {
    http_response_code(404);
    exit("Report target not found or unavailable.");
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $reason = $_POST["reason"] ?? "";
    $description = trim($_POST["description"] ?? "");
    $allowedReasons = ["fraud", "harassment", "misleading", "prohibited_item", "payment_issue", "other"];
    if (!in_array($reason, $allowedReasons, true)) {
        $error = "Choose a valid report reason.";
    } elseif (mb_strlen($description) < 10 || mb_strlen($description) > 2000) {
        $error = "Please provide 10 to 2,000 characters of detail.";
    } else {
        $stmt = $conn->prepare("INSERT INTO moderation_reports (reporter_id, target_type, target_id, reason, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isiss", $user["id"], $targetType, $targetId, $reason, $description);
        $stmt->execute();
        $reportId = $stmt->insert_id;
        record_admin_action($conn, $user["id"], "report_submitted", $targetType, (int)$targetId, "Report #$reportId submitted: $reason");
        $separator = str_contains($returnUrl, "?") ? "&" : "?";
        header("Location: $returnUrl" . $separator . "reported=1");
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-50">
<?php include "includes/navbar.php"; ?>
<main class="mx-auto max-w-2xl p-4">
    <section class="mt-5 rounded-2xl bg-white p-6 shadow-sm">
        <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">Community safety</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-900">Report this <?= e($targetType) ?></h1>
        <p class="mt-2 text-sm text-slate-600"><?= e($targetLabel) ?></p>
        <?php if ($error !== ""): ?><p role="alert" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700"><?= e($error) ?></p><?php endif; ?>
        <form method="post" class="mt-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="target_type" value="<?= e($targetType) ?>">
            <input type="hidden" name="target_id" value="<?= (int)$targetId ?>">
            <div>
                <label for="reason" class="text-sm font-medium">Reason</label>
                <select id="reason" name="reason" required class="mt-1 w-full rounded-xl border px-3 py-2">
                    <option value="">Select a reason</option>
                    <option value="fraud">Suspected fraud or scam</option>
                    <option value="harassment">Harassment or unsafe behavior</option>
                    <option value="misleading">Misleading information</option>
                    <option value="prohibited_item">Prohibited or inappropriate item</option>
                    <option value="payment_issue">Payment or transaction issue</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label for="description" class="text-sm font-medium">What happened?</label>
                <textarea id="description" name="description" required minlength="10" maxlength="2000" rows="5" class="mt-1 w-full rounded-xl border px-3 py-2"></textarea>
            </div>
            <div class="flex gap-3">
                <button class="rounded-xl bg-orange-600 px-4 py-2 font-semibold text-white">Submit report</button>
                <a href="<?= e($returnUrl) ?>" class="rounded-xl border px-4 py-2 font-semibold text-slate-700">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
