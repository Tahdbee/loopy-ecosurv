<?php
require_once "includes/config.php";
ensure_admin_role_schema($conn);
if (is_logged_in()) {
    $user = current_user($conn);
    if ($user) {
        redirect_dashboard($user["role"]);
    }
}

$error = "";
$notice = isset($_GET["registered"]) ? "Your account has been created. Please sign in." : "";
if (isset($_GET["suspended"])) {
    $notice = "This account has been suspended. Contact the LOOPLY staff team for help.";
}
$accountType = "member";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $emailInput = $_POST["email"] ?? "";
    $password = $_POST["password"] ?? "";
    $accountTypeInput = $_POST["account_type"] ?? "member";
    $accountType = is_string($accountTypeInput) && in_array($accountTypeInput, ["member", "staff"], true)
        ? $accountTypeInput
        : "member";

    if (!is_string($emailInput) || !is_string($password) || !is_string($accountTypeInput) || !in_array($accountTypeInput, ["member", "staff"], true)) {
        $error = "Invalid email or password.";
    } else {
        $email = trim($emailInput);
        $stmt = $conn->prepare("SELECT id, role, password_hash, is_blocked FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $allowedRoles = $accountType === "staff" ? ["admin", "reviewer"] : ["buyer", "seller"];

        if ($user && !(int)$user["is_blocked"] && in_array($user["role"], $allowedRoles, true) && password_verify($password, $user["password_hash"])) {
            session_regenerate_id(true);
            $_SESSION["user_id"] = (int)$user["id"];
            redirect_dashboard($user["role"]);
        }

        $error = "Invalid email or password.";
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(asset_url("assets/css/app.css")) ?>">
</head>
<body class="auth-page min-h-screen text-slate-900">
<main class="mx-auto grid min-h-screen max-w-7xl items-center gap-8 px-4 py-8 lg:grid-cols-[1.05fr_.95fr] lg:px-8">
    <section class="auth-showcase relative hidden min-h-[680px] overflow-hidden rounded-[2rem] p-12 text-white shadow-2xl lg:flex lg:flex-col lg:justify-between">
        <div class="absolute -right-20 -top-20 h-80 w-80 rounded-full border border-white/15"></div>
        <div class="absolute -right-8 -top-8 h-56 w-56 rounded-full border border-white/20"></div>
        <div class="auth-showcase-glow absolute -bottom-28 -left-20 h-80 w-80 rounded-full blur-2xl"></div>
        <a href="<?= e(app_page_url("index.php")) ?>" class="auth-showcase-brand relative text-3xl font-black tracking-tight">looply<span>.</span></a>
        <div class="relative max-w-lg">
            <p class="auth-showcase-badge mb-5 inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold backdrop-blur"><span class="h-2 w-2 rounded-full"></span> Your campus marketplace</p>
            <h1 class="text-5xl font-extrabold leading-tight xl:text-6xl">Good finds.<br><span class="auth-showcase-highlight">New beginnings.</span></h1>
            <p class="mt-6 max-w-md text-lg leading-8 text-white/75">Discover student finds, give pre-loved things a new home, and make room for what’s next.</p>
            <div class="mt-10 flex items-center gap-4 rounded-2xl border border-white/15 bg-white/10 p-4 backdrop-blur">
                <span class="auth-showcase-icon grid h-12 w-12 place-items-center rounded-xl bg-white text-2xl" aria-hidden="true">↗</span>
                <div><p class="font-bold">Have something to sell?</p><p class="mt-1 text-sm text-white/75">List it for your campus community.</p></div>
            </div>
        </div>
        <p class="relative text-sm text-white/75">A campus marketplace made for President University students.</p>
    </section>

    <section class="auth-card mx-auto w-full max-w-md rounded-[2rem] border p-6 shadow-xl sm:p-9">
        <a href="<?= e(app_page_url("index.php")) ?>" class="auth-brand text-2xl font-black tracking-tight lg:hidden">looply<span>.</span></a>
        <p class="auth-eyebrow mt-3 text-sm font-bold uppercase tracking-[.18em]">Welcome back</p>
        <h2 class="mt-2 text-3xl font-extrabold tracking-tight">Sign in to LOOPLY</h2>
        <p class="mt-2 text-sm leading-6 text-slate-500">Pick up where you left off in your campus community.</p>

        <?php if ($notice): ?><div role="status" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div role="alert" class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= e($error) ?></div><?php endif; ?>

        <form method="post" class="mt-7 space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <fieldset>
                <legend class="mb-3 text-sm font-semibold text-slate-700">Choose your sign-in</legend>
                <div class="grid grid-cols-2 gap-3">
                    <label class="relative cursor-pointer">
                        <input class="peer sr-only" type="radio" name="account_type" value="member" <?= $accountType === "member" ? "checked" : "" ?>>
                        <span class="auth-choice-card flex min-h-24 flex-col justify-center rounded-2xl border-2 px-4 py-3 transition">
                            <span class="text-sm font-bold text-slate-900">Marketplace</span>
                            <span class="mt-1 text-xs leading-5 text-slate-500">Buy, sell &amp; browse</span>
                        </span>
                    </label>
                    <label class="relative cursor-pointer">
                        <input class="peer auth-staff-choice sr-only" type="radio" name="account_type" value="staff" <?= $accountType === "staff" ? "checked" : "" ?>>
                        <span class="auth-choice-card flex min-h-24 flex-col justify-center rounded-2xl border-2 px-4 py-3 transition">
                            <span class="text-sm font-bold text-slate-900">Staff access</span>
                            <span class="mt-1 text-xs leading-5 text-slate-500">Admin &amp; report review</span>
                        </span>
                    </label>
                </div>
            </fieldset>
            <div>
                <label for="email" class="mb-2 block text-sm font-semibold text-slate-700">University email</label>
                <input id="email" name="email" type="email" required autocomplete="username" class="auth-input w-full rounded-xl border px-4 py-3 outline-none transition" placeholder="you@student.president.ac.id">
            </div>
            <div>
                <label for="password" class="mb-2 block text-sm font-semibold text-slate-700">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password" class="auth-input w-full rounded-xl border px-4 py-3 outline-none transition">
            </div>
            <button type="submit" class="auth-primary-button w-full rounded-xl px-5 py-3.5 font-bold text-white transition focus:outline-none">Sign in <span aria-hidden="true">→</span></button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-500">New to LOOPLY? <a href="register.php?role=buyer" class="auth-link font-semibold">Create an account</a></p>
    </section>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
