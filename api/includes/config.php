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

function faculty_majors(): array
{
    return [
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
}

function app_page_url(string $page): string
{
    $scriptName = $_SERVER["SCRIPT_NAME"] ?? "";
    $apiPosition = strpos($scriptName, "/api/");
    $basePath = $apiPosition === false ? "" : substr($scriptName, 0, $apiPosition) . "/api";

    return $basePath . "/" . ltrim($page, "/");
}

function asset_url(string $path): string
{
    if ($path === "" || preg_match("#^(?:https?:)?//#i", $path)) {
        return $path;
    }

    $path = ltrim($path, "/");
    if ($path === "assets/css/app.css") {
        $stylesheetPath = PUBLIC_DIRECTORY . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . "app.css";
        if (is_file($stylesheetPath)) {
            $path .= "?v=" . filemtime($stylesheetPath);
        }
    }

    $scriptName = $_SERVER["SCRIPT_NAME"] ?? "";
    $apiPosition = strpos($scriptName, "/api/");
    if ($apiPosition !== false) {
        $basePath = substr($scriptName, 0, $apiPosition);
        if ($basePath !== "") {
            return $basePath . "/public/" . $path;
        }
    }

    return "/" . $path;
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
    return app_page_url("private_media.php") . "?path=" . rawurlencode($path);
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

function upload_product_images(array $files): array
{
    if (!isset($files["name"]) || !is_array($files["name"])) {
        return [];
    }

    $paths = [];
    foreach ($files["name"] as $index => $name) {
        $error = $files["error"][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (!is_string($name) || !isset($files["tmp_name"][$index], $files["size"][$index])) {
            throw new RuntimeException("One of the selected images could not be read.");
        }

        $paths[] = upload_product_image([
            "name" => $name,
            "tmp_name" => $files["tmp_name"][$index],
            "size" => $files["size"][$index],
            "error" => $error,
        ]);
    }

    return $paths;
}

function ensure_product_images_table(mysqli $conn): void
{
    $existingTable = $conn->query("SHOW TABLES LIKE 'product_images'");
    if ($existingTable->num_rows > 0) {
        return;
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS product_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY product_image_path (product_id, image_path(191)),
            KEY product_images_order (product_id, sort_order, id),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    foreach (["image1" => 0, "image2" => 1, "image3" => 2] as $column => $sortOrder) {
        $order = (int)$sortOrder;
        $conn->query(
            "INSERT IGNORE INTO product_images (product_id, image_path, sort_order)
             SELECT id, $column, $order FROM products
             WHERE $column IS NOT NULL AND $column <> ''"
        );
    }
}

function get_product_images(mysqli $conn, int $productId): array
{
    ensure_product_images_table($conn);
    $stmt = $conn->prepare("SELECT image_path FROM product_images WHERE product_id = ? ORDER BY sort_order, id");
    $stmt->bind_param("i", $productId);
    $stmt->execute();

    return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), "image_path");
}

function save_product_images(mysqli $conn, int $productId, array $images): void
{
    $delete = $conn->prepare("DELETE FROM product_images WHERE product_id = ?");
    $delete->bind_param("i", $productId);
    $delete->execute();

    $insert = $conn->prepare("INSERT INTO product_images (product_id, image_path, sort_order) VALUES (?, ?, ?)");
    foreach (array_values(array_unique($images)) as $sortOrder => $imagePath) {
        $insert->bind_param("isi", $productId, $imagePath, $sortOrder);
        $insert->execute();
    }
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

function ensure_admin_role_schema(mysqli $conn): void
{
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    $column = $result->fetch_assoc();
    if (!$column || !str_contains($column["Type"], "'reviewer'")) {
        $conn->query("ALTER TABLE users MODIFY role ENUM('buyer', 'seller', 'admin', 'reviewer') NOT NULL DEFAULT 'buyer'");
    }

    $previousRole = $conn->query("SHOW COLUMNS FROM users LIKE 'reviewer_previous_role'");
    if ($previousRole->num_rows === 0) {
        try {
            $conn->query("ALTER TABLE users ADD COLUMN reviewer_previous_role ENUM('buyer', 'seller') DEFAULT NULL AFTER role");
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() !== 1060) {
                throw $exception;
            }
        }
    }

    foreach ([
        "is_blocked" => "ALTER TABLE users ADD COLUMN is_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_verified",
    ] as $columnName => $alterSql) {
        if ($conn->query("SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($columnName) . "'")->num_rows === 0) {
            try {
                $conn->query($alterSql);
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() !== 1060) {
                    throw $exception;
                }
            }
        }
    }

    foreach ([
        "is_blocked" => "ALTER TABLE products ADD COLUMN is_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_sold",
        "is_deleted" => "ALTER TABLE products ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_blocked",
    ] as $columnName => $alterSql) {
        if ($conn->query("SHOW COLUMNS FROM products LIKE '" . $conn->real_escape_string($columnName) . "'")->num_rows === 0) {
            try {
                $conn->query($alterSql);
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() !== 1060) {
                    throw $exception;
                }
            }
        }
    }
}

function ensure_staff_notifications_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS staff_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            message VARCHAR(255) NOT NULL,
            link VARCHAR(255) NOT NULL DEFAULT '',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY staff_notifications_user_created (user_id, created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );
}

function notify_staff(mysqli $conn, string $message, string $link): void
{
    ensure_staff_notifications_table($conn);
    $notify = $conn->prepare(
        "INSERT INTO staff_notifications (user_id, message, link)
         SELECT id, ?, ? FROM users WHERE role IN ('admin', 'reviewer')"
    );
    $notify->bind_param("ss", $message, $link);
    $notify->execute();
}

function moderate_report(mysqli $conn, int $reportId, string $action): string
{
    $conn->begin_transaction();
    try {
        $reportQuery = $conn->prepare(
            "SELECT product_id, reported_user_id, status FROM reports WHERE id = ? FOR UPDATE"
        );
        $reportQuery->bind_param("i", $reportId);
        $reportQuery->execute();
        $report = $reportQuery->get_result()->fetch_assoc();
        if (!$report) {
            throw new RuntimeException("That report no longer exists.");
        }
        if ($report["status"] === "dismissed") {
            throw new RuntimeException("A dismissed report cannot be actioned.");
        }

        if ($action === "block_reported_product" || $action === "delete_reported_product") {
            if ($report["product_id"] === null) {
                throw new RuntimeException("This report is not about a product.");
            }
            if ($action === "block_reported_product") {
                $update = $conn->prepare("UPDATE products SET is_blocked = 1 WHERE id = ? AND is_deleted = 0");
                $update->bind_param("i", $report["product_id"]);
                $update->execute();
                if ($update->affected_rows !== 1) {
                    throw new RuntimeException("The product is already removed or could not be blocked.");
                }
                $message = "Product #" . (int)$report["product_id"] . " was blocked.";
            } else {
                $update = $conn->prepare("UPDATE products SET is_blocked = 1, is_deleted = 1 WHERE id = ? AND is_deleted = 0");
                $update->bind_param("i", $report["product_id"]);
                $update->execute();
                if ($update->affected_rows !== 1) {
                    throw new RuntimeException("The product is already removed or could not be deleted.");
                }
                $message = "Reported product #" . (int)$report["product_id"] . " was removed.";
            }
        } elseif ($action === "suspend_reported_account") {
            if ($report["reported_user_id"] === null) {
                throw new RuntimeException("This report is not about an account.");
            }
            $update = $conn->prepare("UPDATE users SET is_blocked = 1 WHERE id = ? AND role IN ('buyer', 'seller') AND is_blocked = 0");
            $update->bind_param("i", $report["reported_user_id"]);
            $update->execute();
            if ($update->affected_rows !== 1) {
                throw new RuntimeException("The account is already suspended or cannot be suspended.");
            }
            $message = "Reported account #" . (int)$report["reported_user_id"] . " was suspended.";
        } else {
            throw new RuntimeException("Unknown moderation action.");
        }

        $updateReport = $conn->prepare("UPDATE reports SET status = 'actioned' WHERE id = ?");
        $updateReport->bind_param("i", $reportId);
        $updateReport->execute();
        $conn->commit();
        notify_staff($conn, $message, "admin/admin-dashboard.php#reports");
        return $message;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function ensure_reports_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reporter_id INT NOT NULL,
            product_id INT DEFAULT NULL,
            reported_user_id INT DEFAULT NULL,
            reason ENUM('scam', 'prohibited_item', 'misleading_listing', 'harassment', 'other') NOT NULL,
            details VARCHAR(2000) NOT NULL DEFAULT '',
            status ENUM('pending', 'reviewed', 'actioned', 'dismissed') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_product_report (reporter_id, product_id),
            UNIQUE KEY unique_account_report (reporter_id, reported_user_id),
            KEY reports_status_created (status, created_at),
            FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );
}

function ensure_admin_documents_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS admin_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            original_name VARCHAR(255) NOT NULL,
            storage_path VARCHAR(255) NOT NULL,
            uploaded_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY admin_documents_created (created_at),
            FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
        )"
    );
}

function is_logged_in(): bool
{
    return isset($_SESSION["user_id"]);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header("Location: " . app_page_url("login.php"));
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
        ensure_admin_role_schema($conn);
        $profilePhotoColumnChecked = true;
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION["user_id"]);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc() ?: null;
    if ($user && (int)($user["is_blocked"] ?? 0) === 1) {
        $_SESSION = [];
        session_destroy();
        header("Location: " . app_page_url("login.php") . "?suspended=1");
        exit;
    }
    return $user;
}

function require_marketplace_access(array $user): void
{
    if (!in_array($user["role"] ?? "", ["buyer", "seller"], true)) {
        redirect_dashboard((string)($user["role"] ?? ""));
    }
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
    if ($role === "admin" || $role === "reviewer") {
        header("Location: " . app_page_url("admin/admin-dashboard.php"));
        exit;
    }
    if ($role === "seller") {
        header("Location: " . app_page_url("dashboard_seller.php"));
        exit;
    }
    header("Location: " . app_page_url("dashboard_buyer.php"));
    exit;
}

function redirect_completion(string $step, int $itemId = 0): void
{
    $_SESSION["completion"] = [
        "step" => $step,
        "item_id" => max(0, $itemId),
    ];
    header("Location: " . app_page_url("completion.php"));
    exit;
}
?>
