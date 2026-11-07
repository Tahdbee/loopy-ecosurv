<?php
require_once "config.php";
if (is_logged_in()) {
    $user = current_user($conn);
    if ($user) {
        redirect_dashboard($user["role"]);
    }
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();

    if ($user && password_verify($password, $user["password_hash"])) {
        $_SESSION["user_id"] = $user["id"];
        redirect_dashboard($user["role"]);
    } else {
        $error = "Invalid email or password.";
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Login - Looply</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-4"><div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-6"><h1 class="text-2xl font-bold text-blue-700">Looply Login</h1><p class="text-sm text-slate-500 mt-1">Campus marketplace for President University students.</p><?php if ($error): ?><div class="mt-4 bg-red-50 text-red-700 px-3 py-2 rounded-lg text-sm"><?= e($error) ?></div><?php endif; ?><form method="post" class="mt-5 space-y-4"><div><label class="text-sm font-medium">University Email</label><input name="email" type="email" required class="mt-1 w-full border rounded-xl px-3 py-2" placeholder="you@student.president.ac.id"></div><div><label class="text-sm font-medium">Password</label><input name="password" type="password" required class="mt-1 w-full border rounded-xl px-3 py-2"></div><button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-2 font-semibold">Login</button></form><p class="text-sm mt-4">No account? <a href="register.php" class="text-blue-600 font-semibold">Register</a></p></div></body></html>
