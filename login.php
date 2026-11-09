<?php
require_once "config.php";
if (is_logged_in()) {
    $user = current_user($conn);
    if ($user) {
        redirect_dashboard($user["role"]);
    }
}

if (empty($_SESSION["auth_csrf_token"])) {
    $_SESSION["auth_csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["auth_csrf_token"];
$error = "";
$notice = isset($_GET["registered"]) ? "Your account has been created. Please sign in." : "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedToken = $_POST["csrf_token"] ?? "";
    $emailInput = $_POST["email"] ?? "";
    $password = $_POST["password"] ?? "";

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $error = "Your session expired. Refresh the page and try again.";
    } elseif (!is_string($emailInput) || !is_string($password)) {
        $error = "Invalid email or password.";
    } else {
        $email = trim($emailInput);
        $stmt = $conn->prepare("SELECT id, role, password_hash FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user["password_hash"])) {
            session_regenerate_id(true);
            $_SESSION["user_id"] = (int)$user["id"];
            redirect_dashboard($user["role"]);
        }

        $error = "Invalid email or password.";
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Login | LOOPLY</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="assets/css/app.css"></head>
<body class="min-h-screen bg-slate-50 flex flex-col items-center justify-center p-4"><div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-6"><h1 class="brand-wordmark text-2xl">looply<span>.</span></h1><p class="brand-tagline text-sm mt-1">Discover More. Shop Different.</p><p class="text-sm text-slate-500 mt-3">Campus marketplace for President University students.</p><?php if ($notice): ?><div role="status" class="mt-4 bg-emerald-50 text-emerald-700 px-3 py-2 rounded-lg text-sm"><?= e($notice) ?></div><?php endif; ?><?php if ($error): ?><div role="alert" class="mt-4 bg-red-50 text-red-700 px-3 py-2 rounded-lg text-sm"><?= e($error) ?></div><?php endif; ?><form method="post" class="mt-5 space-y-4"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><div><label class="text-sm font-medium">University Email</label><input name="email" type="email" required autocomplete="username" class="mt-1 w-full border rounded-xl px-3 py-2" placeholder="you@student.president.ac.id"></div><div><label class="text-sm font-medium">Password</label><input name="password" type="password" required autocomplete="current-password" class="mt-1 w-full border rounded-xl px-3 py-2"></div><button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-2 font-semibold">Login</button></form><p class="text-sm mt-4">No account? <a href="register.php" class="text-blue-600 font-semibold">Register</a></p></div><?php include "includes/footer.php"; ?></body></html>
