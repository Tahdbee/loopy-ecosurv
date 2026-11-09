<?php
require_once "config.php";
if (is_logged_in()) {
    $user = current_user($conn);
    if ($user) {
        redirect_dashboard($user["role"]);
    }
}

$error = "";
$faculty = "";
$major = "";
$fullName = "";
$email = "";
$role = "buyer";
$csrfToken = $_SESSION["auth_csrf_token"] ??= bin2hex(random_bytes(32));
$facultyMajors = [
    "Faculty of Business" => [
        "Accounting",
        "Business Administration",
        "Management",
        "Actuarial Science",
        "Agribusiness",
    ],
    "Faculty of Artificial Intelligence and Smart Manufacturing" => [
        "Informatics",
        "Information System",
    ],
    "Faculty of Engineering" => [
        "Mechanical Engineering",
        "Electrical Engineering",
        "Industrial Engineering",
        "Environmental Engineering",
        "Civil Engineering",
    ],
    "Faculty of Social Science and Education" => [
        "International Relations",
        "Communication (Communication Science)",
        "Elementary Teacher Education",
    ],
    "Faculty of Art, Design, and Architecture" => [
        "Interior Design",
        "Architecture",
        "Visual Communication Design",
    ],
    "Faculty of Law" => [
        "Law",
    ],
    "Faculty of Medicine" => [
        "Medicine",
    ],
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedToken = $_POST["csrf_token"] ?? "";
    $fullNameInput = $_POST["full_name"] ?? "";
    $emailInput = $_POST["email"] ?? "";
    $password = $_POST["password"] ?? "";
    $facultyInput = $_POST["faculty"] ?? "";
    $majorInput = $_POST["major"] ?? "";
    $faculty = is_string($facultyInput) ? trim($facultyInput) : "";
    $major = is_string($majorInput) ? trim($majorInput) : "";
    $roleInput = $_POST["role"] ?? "buyer";
    $fullName = is_string($fullNameInput) ? trim($fullNameInput) : "";
    $email = is_string($emailInput) ? strtolower(trim($emailInput)) : "";
    $role = is_string($roleInput) && in_array($roleInput, ["buyer", "seller"], true) ? $roleInput : "buyer";

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $error = "Your session expired. Refresh the page and try again.";
    } elseif (strlen($fullName) < 2 || strlen($fullName) > 120 || !is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
        $error = "Enter a name between 2 and 120 characters and a password between 8 and 72 characters.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Enter a valid university email address.";
    } elseif (!isset($facultyMajors[$faculty]) || !in_array($major, $facultyMajors[$faculty], true)) {
        $error = "Select a valid faculty and major.";
    } elseif (!is_verified_student_email($email)) {
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
            try {
                if ($stmt->execute()) {
                    header("Location: login.php?registered=1");
                    exit;
                }
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() !== 1062) {
                    throw $exception;
                }
                $error = "Email already registered.";
            }
            if ($error === "") {
                $error = "Failed to register.";
            }
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Register | LOOPLY</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="assets/css/app.css"></head>
<body class="min-h-screen bg-slate-50 flex flex-col items-center justify-center p-4"><div class="w-full max-w-lg bg-white rounded-2xl shadow-lg p-6"><h1 class="brand-wordmark text-2xl">Create your looply<span>.</span> account</h1><p class="brand-tagline text-sm mt-1">Discover More. Shop Different.</p><?php if ($error): ?><div class="mt-4 bg-red-50 text-red-700 px-3 py-2 rounded-lg text-sm"><?= e($error) ?></div><?php endif; ?><form method="post" class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><div class="md:col-span-2"><label class="text-sm font-medium">Full Name</label><input name="full_name" value="<?= e($fullName) ?>" required minlength="2" maxlength="120" class="mt-1 w-full border rounded-xl px-3 py-2"></div><div class="md:col-span-2"><label class="text-sm font-medium">University Email</label><input type="email" name="email" value="<?= e($email) ?>" required class="mt-1 w-full border rounded-xl px-3 py-2" placeholder="you@student.president.ac.id"></div><div><label for="faculty" class="text-sm font-medium">Faculty</label><select id="faculty" name="faculty" required class="mt-1 w-full border rounded-xl px-3 py-2"><option value="">Select a faculty</option><?php foreach ($facultyMajors as $facultyOption => $majorOptions): ?><option value="<?= e($facultyOption) ?>" <?= $faculty === $facultyOption ? "selected" : "" ?>><?= e($facultyOption) ?></option><?php endforeach; ?></select></div><div><label for="major" class="text-sm font-medium">Major</label><select id="major" name="major" required class="mt-1 w-full border rounded-xl px-3 py-2"><option value="">Select a major</option><?php foreach ($facultyMajors[$faculty] ?? [] as $majorOption): ?><option value="<?= e($majorOption) ?>" <?= $major === $majorOption ? "selected" : "" ?>><?= e($majorOption) ?></option><?php endforeach; ?></select></div><div><label class="text-sm font-medium">Password</label><input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" class="mt-1 w-full border rounded-xl px-3 py-2"></div><div><label class="text-sm font-medium">Role</label><select name="role" required class="mt-1 w-full border rounded-xl px-3 py-2"><option value="buyer" <?= $role === "buyer" ? "selected" : "" ?>>Buyer</option><option value="seller" <?= $role === "seller" ? "selected" : "" ?>>Seller</option></select></div><div class="md:col-span-2"><button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-2 font-semibold">Register</button></div></form><p class="text-sm mt-4">Already registered? <a href="login.php" class="text-blue-600 font-semibold">Login</a></p></div><script>
const facultyMajors = <?= json_encode($facultyMajors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const facultySelect = document.getElementById("faculty");
const majorSelect = document.getElementById("major");
facultySelect.addEventListener("change", () => {
    majorSelect.replaceChildren(new Option("Select a major", ""));
    for (const major of facultyMajors[facultySelect.value] || []) {
        majorSelect.add(new Option(major, major));
    }
});
</script><?php include "includes/footer.php"; ?></body></html>
