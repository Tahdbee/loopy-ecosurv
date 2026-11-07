<?php
session_start();

$dbHost = "127.0.0.1";
$dbUser = "root";
$dbPass = "";
$dbName = "looply";

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

date_default_timezone_set("Asia/Jakarta");

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

function upload_product_image(array $file, string $existing = ""): string
{
    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }
    if (($file["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Image upload failed.");
    }
    if (($file["size"] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException("Each image must be 5 MB or smaller.");
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
    $extensions = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/gif" => "gif",
        "image/webp" => "webp",
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException("Images must be JPG, PNG, GIF, or WEBP files.");
    }

    $directory = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "products";
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException("Could not create the image upload directory.");
    }

    $filename = bin2hex(random_bytes(16)) . "." . $extensions[$mime];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        throw new RuntimeException("Could not save the uploaded image.");
    }

    return "uploads/products/" . $filename;
}

function is_logged_in(): bool
{
    return isset($_SESSION["user_id"]);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}

function current_user(mysqli $conn): ?array
{
    if (!is_logged_in()) {
        return null;
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION["user_id"]);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc() ?: null;
}

function campus_locations(): array
{
    return [
        "Library Lobby",
        "Student Center",
        "Dormitory Lobby",
        "Cafeteria Area"
    ];
}

function payment_methods(): array
{
    return ["QRIS", "Bank Transfer", "Dana", "OVO", "GoPay"];
}

function is_verified_student_email(string $email): bool
{
    return str_ends_with(strtolower($email), "@student.president.ac.id") ||
        str_ends_with(strtolower($email), "@president.ac.id");
}

function redirect_dashboard(string $role): void
{
    if ($role === "seller") {
        header("Location: dashboard_seller.php");
        exit;
    }
    header("Location: dashboard_buyer.php");
    exit;
}
?>
