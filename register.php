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
    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $faculty = trim($_POST["faculty"] ?? "");
    $major = trim($_POST["major"] ?? "");
    $role = $_POST["role"] ?? "buyer";

    if (!in_array($role, ["buyer", "seller"], true)) {
        $role = "buyer";
    }

    if (!is_verified_student_email($email)) {
        $error = "Use your President University email for verification.";
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = "Email already registered.";
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $verified = 1;
            $stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role, faculty, major, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssi", $fullName, $email, $hash, $role, $faculty, $major, $verified);
            if ($stmt->execute()) {
                $_SESSION["user_id"] = $stmt->insert_id;
                redirect_dashboard($role);
            }
            $error = "Failed to register.";
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Register - Looply</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-4"><div class="w-full max-w-lg bg-white rounded-2xl shadow-lg p-6"><h1 class="text-2xl font-bold text-blue-700">Create Looply Account</h1><?php if ($error): ?><div class="mt-4 bg-red-50 text-red-700 px-3 py-2 rounded-lg text-sm"><?= e($error) ?></div><?php endif; ?><form method="post" class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4"><div class="md:col-span-2"><label class="text-sm font-medium">Full Name</label><input name="full_name" required class="mt-1 w-full border rounded-xl px-3 py-2"></div><div class="md:col-span-2"><label class="text-sm font-medium">University Email</label><input type="email" name="email" required class="mt-1 w-full border rounded-xl px-3 py-2" placeholder="you@student.president.ac.id"></div><div><label class="text-sm font-medium">Faculty</label><input name="faculty" class="mt-1 w-full border rounded-xl px-3 py-2"></div><div><label class="text-sm font-medium">Major</label><input name="major" class="mt-1 w-full border rounded-xl px-3 py-2"></div><div><label class="text-sm font-medium">Password</label><input type="password" name="password" required class="mt-1 w-full border rounded-xl px-3 py-2"></div><div><label class="text-sm font-medium">Role</label><select name="role" required class="mt-1 w-full border rounded-xl px-3 py-2"><option value="buyer">Buyer</option><option value="seller">Seller</option></select></div><div class="md:col-span-2"><button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-2 font-semibold">Register</button></div></form><p class="text-sm mt-4">Already registered? <a href="login.php" class="text-blue-600 font-semibold">Login</a></p></div></body></html>
