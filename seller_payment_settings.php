<?php
require_once "config.php";
require_login();
ensure_seller_payment_columns($conn);
$user = current_user($conn);
if ($user["role"] !== "seller") {
    redirect_dashboard("buyer");
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_payment_settings"])) {
    $instructionsInput = $_POST["payment_instructions"] ?? "";
    $paymentInstructions = is_string($instructionsInput) ? trim($instructionsInput) : "";
    if (strlen($paymentInstructions) > 5000) {
        $error = "Payment instructions must be 5,000 characters or fewer.";
    } else {
        try {
            $qrUpload = $_FILES["payment_qr"] ?? [];
            $removeQr = isset($_POST["remove_payment_qr"])
                && ($qrUpload["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
            $qrImage = $removeQr
                ? ""
                : upload_seller_payment_qr($qrUpload, $user["payment_qr_image"] ?? "");
            $save = $conn->prepare("UPDATE users SET payment_instructions = ?, payment_qr_image = ? WHERE id = ? AND role = 'seller'");
            $save->bind_param("ssi", $paymentInstructions, $qrImage, $user["id"]);
            $save->execute();
            redirect_completion("seller_payment_settings_saved");
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$paymentSettings = $conn->prepare("SELECT payment_instructions, payment_qr_image FROM users WHERE id = ? AND role = 'seller'");
$paymentSettings->bind_param("i", $user["id"]);
$paymentSettings->execute();
$settings = $paymentSettings->get_result()->fetch_assoc();
if (!$settings) {
    redirect_dashboard("seller");
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Settings | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-50 min-h-screen">
<?php include "includes/navbar.php"; ?>
<main class="mx-auto max-w-3xl p-4">
    <a href="dashboard_seller.php" class="text-sm text-blue-700">&larr; Seller Dashboard</a>
    <section class="mt-3 rounded-2xl bg-white p-6 shadow-sm md:p-8">
        <p class="text-xs font-bold uppercase tracking-widest text-blue-700">Seller checkout setup</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-900">Payment methods</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">Tell buyers how to pay you. These details appear in the buyer's order dashboard after you accept an order. Add the account name and number for BCA, DANA, or another service. Never add your password, PIN, or one-time code.</p>

        <?php if ($error): ?>
            <p role="alert" class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="mt-6 space-y-5">
            <div>
                <label for="payment_instructions" class="text-sm font-semibold text-slate-800">Payment instructions and account details</label>
                <textarea id="payment_instructions" name="payment_instructions" maxlength="5000" rows="6" class="mt-2 w-full rounded-xl border px-3 py-2" placeholder="Example: BCA · Account name: Your Name · Account number: 1234567890&#10;DANA · Number: 08xxxxxxxxxx&#10;For cash, please pay at the agreed campus meet-up."><?= e($settings["payment_instructions"] ?? "") ?></textarea>
                <p class="mt-1 text-xs text-slate-500">Share only details you intend to give to your buyers.</p>
            </div>

            <div class="rounded-2xl border border-orange-100 bg-orange-50/70 p-4">
                <label for="payment_qr" class="text-sm font-semibold text-slate-800">Your QRIS payment code (optional)</label>
                <p class="mt-1 text-xs leading-5 text-slate-600">Upload your own merchant QR. Buyers can choose QR payment only when your QR is set up. LOOPLY does not process or automatically verify the payment.</p>
                <?php if (!empty($settings["payment_qr_image"])): ?>
                    <img src="<?= e($settings["payment_qr_image"]) ?>" alt="Your current seller payment QR" class="mt-3 max-h-64 rounded-xl border bg-white p-2">
                    <label class="mt-3 flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="remove_payment_qr" value="1" class="h-4 w-4">
                        Remove my QR code
                    </label>
                <?php endif; ?>
                <input id="payment_qr" name="payment_qr" type="file" accept="image/jpeg,image/png,image/webp" class="mt-3 block w-full rounded-xl border bg-white px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-slate-500">JPG, PNG, or WEBP. Maximum 5 MB. Uploading a new image replaces your current QR.</p>
            </div>

            <button type="submit" name="save_payment_settings" value="1" class="w-full rounded-xl bg-blue-600 py-3 font-semibold text-white">Save payment settings</button>
        </form>
    </section>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
