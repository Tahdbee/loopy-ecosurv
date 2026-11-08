<?php
ini_set("session.cookie_httponly", "1");
ini_set("session.cookie_samesite", "Lax");
ini_set("session.use_strict_mode", "1");
if (
    (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ||
    (int)($_SERVER["SERVER_PORT"] ?? 0) === 443
) {
    ini_set("session.cookie_secure", "1");
}
session_start();

$dbHost = getenv("DB_HOST") ?: (getenv("MYSQLHOST") ?: "127.0.0.1");
$dbUser = getenv("DB_USER") ?: (getenv("MYSQLUSER") ?: "root");
$dbPass = getenv("DB_PASSWORD");
if ($dbPass === false) {
    $dbPass = getenv("MYSQLPASSWORD") ?: "";
}
$dbName = getenv("DB_NAME") ?: (getenv("MYSQLDATABASE") ?: "looply");
$dbPort = (int)(getenv("DB_PORT") ?: (getenv("MYSQLPORT") ?: 3306));

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

date_default_timezone_set("Asia/Jakarta");

define("PUBLIC_DIRECTORY", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "public");
define("PRIVATE_UPLOAD_DIRECTORY", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "private_uploads");

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

function asset_url(string $path): string
{
    if ($path === "" || preg_match("#^(?:https?:)?//#i", $path) || str_starts_with($path, "/")) {
        return $path;
    }

    return "/" . ltrim($path, "/");
}

function csrf_token(): string
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function verify_csrf_token(): void
{
    $submitted = $_POST["csrf_token"] ?? "";
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(400);
        exit("Invalid request token.");
    }
}

function protected_media_url(string $path): string
{
    return "private_media.php?path=" . rawurlencode($path);
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

    $directory = PUBLIC_DIRECTORY . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "products";
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

function upload_profile_photo(array $file): string
{
    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return "";
    }
    if (($file["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Profile photo upload failed.");
    }
    if (($file["size"] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException("Your profile photo must be 3 MB or smaller.");
    }

    $temporaryPath = $file["tmp_name"] ?? "";
    if (!is_uploaded_file($temporaryPath)) {
        throw new RuntimeException("The selected profile photo is invalid.");
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extensions = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException("Profile photos must be JPG, PNG, or WEBP images.");
    }

    $directory = PUBLIC_DIRECTORY . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "profile-photos";
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException("Could not create the profile photo directory.");
    }

    $filename = bin2hex(random_bytes(16)) . "." . $extensions[$mime];
    if (!move_uploaded_file($temporaryPath, $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException("Could not save your profile photo.");
    }

    return "uploads/profile-photos/" . $filename;
}

function delete_profile_photo(string $photoPath): void
{
    $prefix = "uploads/profile-photos/";
    if (!str_starts_with($photoPath, $prefix)) {
        return;
    }

    $filename = substr($photoPath, strlen($prefix));
    if ($filename === "" || basename($filename) !== $filename) {
        return;
    }

    $path = PUBLIC_DIRECTORY . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "profile-photos" . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException("Could not remove the previous profile photo.");
    }
}

function upload_transaction_proof(array $file): string
{
    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException("Choose an image to upload.");
    }
    if (($file["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Image upload failed.");
    }
    if (($file["size"] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException("The proof image must be 5 MB or smaller.");
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
    $extensions = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException("Proof images must be JPG, PNG, or WEBP files.");
    }

    $directory = PRIVATE_UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . "payment-proofs";
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException("Could not create the payment proof directory.");
    }

    $filename = bin2hex(random_bytes(16)) . "." . $extensions[$mime];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        throw new RuntimeException("Could not save the payment proof image.");
    }

    return "private/payment-proofs/" . $filename;
}

function upload_seller_payment_qr(array $file, string $existing = ""): string
{
    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }
    if (($file["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("QR image upload failed.");
    }
    if (($file["size"] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException("The QR image must be 5 MB or smaller.");
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
    $extensions = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException("The QR image must be JPG, PNG, or WEBP.");
    }

    $directory = PRIVATE_UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . "seller-payment-qrs";
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException("Could not create the seller QR upload directory.");
    }

    $filename = bin2hex(random_bytes(16)) . "." . $extensions[$mime];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        throw new RuntimeException("Could not save the seller QR image.");
    }

    return "private/seller-payment-qrs/" . $filename;
}

function ensure_transaction_proof_columns(mysqli $conn): void
{
    $columns = [
        "payment_flow" => "ENUM('legacy', 'cash_receipt', 'gopay_qr') NOT NULL DEFAULT 'legacy'",
        "buyer_payment_proof" => "VARCHAR(255) NOT NULL DEFAULT ''",
        "seller_cash_receipt" => "VARCHAR(255) NOT NULL DEFAULT ''",
    ];

    foreach ($columns as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM transactions LIKE '" . $conn->real_escape_string($column) . "'");
        if ($check->num_rows === 0) {
            try {
                $conn->query("ALTER TABLE transactions ADD COLUMN `$column` $definition");
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() !== 1060) {
                    throw $e;
                }
            }
        }
    }
}

function ensure_seller_payment_columns(mysqli $conn): void
{
    $columns = [
        "payment_instructions" => "VARCHAR(5000) NOT NULL DEFAULT ''",
        "payment_qr_image" => "VARCHAR(255) NOT NULL DEFAULT ''",
    ];

    foreach ($columns as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($column) . "'");
        if ($check->num_rows === 0) {
            try {
                $conn->query("ALTER TABLE users ADD COLUMN `$column` $definition");
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() !== 1060) {
                    throw $e;
                }
            }
        }
    }
}

function ensure_user_profile_photo_column(mysqli $conn): void
{
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
    if ($check->num_rows === 0) {
        try {
            $conn->query("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NOT NULL DEFAULT '' AFTER major");
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() !== 1060) {
                throw $e;
            }
        }
    }
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

    static $profilePhotoColumnChecked = false;
    if (!$profilePhotoColumnChecked) {
        ensure_user_profile_photo_column($conn);
        $profilePhotoColumnChecked = true;
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

function redirect_completion(string $step, int $itemId = 0): void
{
    $_SESSION["completion"] = [
        "step" => $step,
        "item_id" => max(0, $itemId),
    ];
    header("Location: completion.php");
    exit;
}
?>
