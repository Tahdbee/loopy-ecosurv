<?php
require_once "includes/config.php";
require_login();
$user = current_user($conn);
require_marketplace_access($user);
ensure_reports_table($conn);

$reportTypes = [
    "product" => "product listing",
    "account" => "seller account",
];
$type = $_GET["type"] ?? "";
$targetId = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
if (!is_string($type) || !isset($reportTypes[$type]) || $targetId === false || $targetId === null || $targetId < 1) {
    http_response_code(400);
    exit("Invalid report target.");
}

$target = null;
if ($type === "product") {
    $targetQuery = $conn->prepare(
        "SELECT p.id, p.name, p.seller_id, u.full_name seller_name
         FROM products p JOIN users u ON u.id = p.seller_id WHERE p.id = ? AND p.is_blocked = 0 AND p.is_deleted = 0 AND u.is_blocked = 0"
    );
    $targetQuery->bind_param("i", $targetId);
} else {
    $targetQuery = $conn->prepare(
        "SELECT id, full_name FROM users WHERE id = ? AND role = 'seller' AND is_blocked = 0"
    );
    $targetQuery->bind_param("i", $targetId);
}
$targetQuery->execute();
$target = $targetQuery->get_result()->fetch_assoc();
if (!$target) {
    http_response_code(404);
    exit(ucfirst($reportTypes[$type]) . " not found.");
}

$reportedUserId = $type === "product" ? (int)$target["seller_id"] : $targetId;
if ((int)$user["id"] === $reportedUserId) {
    http_response_code(403);
    exit("You cannot report your own " . $reportTypes[$type] . ".");
}

$reasons = [
    "scam" => "Scam or fraud",
    "prohibited_item" => "Prohibited item",
    "misleading_listing" => "Misleading information",
    "harassment" => "Harassment or abusive behavior",
    "other" => "Other",
];
$error = "";
$success = !empty($_SESSION["report_submitted"]);
unset($_SESSION["report_submitted"]);
$details = "";
$selectedReason = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $selectedReason = $_POST["reason"] ?? "";
    $detailsInput = $_POST["details"] ?? "";
    $details = is_string($detailsInput) ? trim($detailsInput) : "";

    if (!is_string($selectedReason) || !isset($reasons[$selectedReason])) {
        $error = "Choose a reason for your report.";
    } elseif (strlen($details) > 2000) {
        $error = "Additional details must be 2,000 characters or fewer.";
    } else {
        $productId = $type === "product" ? $targetId : null;
        $accountId = $type === "account" ? $targetId : null;
        $insert = $conn->prepare(
            "INSERT INTO reports (reporter_id, product_id, reported_user_id, reason, details)
             VALUES (?, ?, ?, ?, ?)"
        );
        $insert->bind_param("iiiss", $user["id"], $productId, $accountId, $selectedReason, $details);
        try {
            $insert->execute();
            notify_staff(
                $conn,
                "New " . $reportTypes[$type] . " report #" . (int)$insert->insert_id . " submitted.",
                "admin/admin-dashboard.php#reports"
            );
            $_SESSION["report_submitted"] = true;
            header("Location: " . app_page_url("report.php") . "?type=" . rawurlencode($type) . "&id=" . $targetId);
            exit;
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1062) {
                $error = "You have already reported this " . $reportTypes[$type] . ".";
            } else {
                throw $exception;
            }
        }
    }
}

$targetName = $type === "product"
    ? $target["name"] . " by " . $target["seller_name"]
    : $target["full_name"];
$returnUrl = $type === "product"
    ? "product_detail.php?id=" . $targetId
    : "seller_profile.php?id=" . $targetId;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report <?= e($reportTypes[$type]) ?> | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(asset_url("assets/css/app.css")) ?>">
</head>
<body class="bg-slate-50 min-h-screen">
<?php include "includes/navbar.php"; ?>
<main class="mx-auto max-w-2xl p-4 py-10">
    <section class="rounded-2xl bg-white p-6 shadow-sm md:p-8">
        <p class="text-sm font-bold uppercase tracking-widest text-blue-600">Help keep LOOPLY safe</p>
        <h1 class="mt-2 text-3xl font-bold text-slate-900">Report <?= e($reportTypes[$type]) ?></h1>
        <p class="mt-2 text-slate-600">You’re reporting <strong><?= e($targetName) ?></strong>. Reports are sent to the admin team for review.</p>

        <?php if ($success): ?>
            <p role="status" class="mt-5 rounded-xl bg-emerald-50 p-4 text-emerald-800">Thank you. Your report has been submitted for review.</p>
        <?php endif; ?>
        <?php if ($error): ?>
            <p role="alert" class="mt-5 rounded-xl bg-red-50 p-4 text-red-700"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" class="mt-6 space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div>
                <label for="reason" class="mb-2 block text-sm font-semibold text-slate-700">Why are you reporting this?</label>
                <select id="reason" name="reason" required class="w-full rounded-xl border border-slate-300 px-4 py-3">
                    <option value="">Select a reason</option>
                    <?php foreach ($reasons as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $selectedReason === $value ? "selected" : "" ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="details" class="mb-2 block text-sm font-semibold text-slate-700">Additional details <span class="font-normal text-slate-500">(optional)</span></label>
                <textarea id="details" name="details" rows="5" maxlength="2000" class="w-full rounded-xl border border-slate-300 px-4 py-3" placeholder="Share any details that may help the admin team review this report."><?= e($details) ?></textarea>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <?php if (!$success): ?>
                    <button type="submit" class="rounded-xl bg-blue-700 px-5 py-3 font-semibold text-white hover:bg-blue-800">Submit report</button>
                <?php endif; ?>
                <a href="<?= e($returnUrl) ?>" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 hover:bg-slate-50">Back</a>
            </div>
        </form>
    </section>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
