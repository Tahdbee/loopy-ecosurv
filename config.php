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
