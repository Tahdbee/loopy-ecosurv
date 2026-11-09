<?php
require_once "includes/config.php";
require_login();

$relativePath = $_GET["path"] ?? "";
if (!is_string($relativePath) || !preg_match("#^(private|uploads)/(payment-proofs|seller-payment-qrs)/([A-Za-z0-9_-]+\\.(?:jpg|jpeg|png|webp))$#i", $relativePath, $matches)) {
    http_response_code(404);
    exit;
}

$user = current_user($conn);
$userId = (int)$user["id"];
if ($user["role"] === "admin") {
    $access = $conn->prepare(
        "SELECT 1 FROM users WHERE id = ? AND payment_qr_image = ?
         UNION ALL
         SELECT 1 FROM transactions WHERE buyer_payment_proof = ? OR seller_cash_receipt = ?
         LIMIT 1"
    );
    $access->bind_param("isss", $userId, $relativePath, $relativePath, $relativePath);
} elseif ($user["role"] === "staff") {
    $access = $conn->prepare(
        "SELECT 1 FROM transactions t
         JOIN moderation_reports r ON r.target_type = 'transaction' AND r.target_id = t.id
         WHERE r.status IN ('new', 'in_review') AND (r.assigned_to IS NULL OR r.assigned_to = ?)
           AND (t.buyer_payment_proof = ? OR t.seller_cash_receipt = ?)
         LIMIT 1"
    );
    $access->bind_param("iss", $userId, $relativePath, $relativePath);
} else {
    $access = $conn->prepare(
        "SELECT 1 FROM users WHERE id = ? AND payment_qr_image = ?
         UNION ALL
         SELECT 1 FROM transactions
         WHERE (buyer_id = ? OR seller_id = ?)
           AND (buyer_payment_proof = ? OR seller_cash_receipt = ?)
         LIMIT 1"
    );
    $access->bind_param("isiiss", $userId, $relativePath, $userId, $userId, $relativePath, $relativePath);
}
$access->execute();
if (!$access->get_result()->fetch_assoc()) {
    http_response_code(403);
    exit;
}

$baseDirectory = $matches[1] === "private" ? PRIVATE_UPLOAD_DIRECTORY : PUBLIC_DIRECTORY . DIRECTORY_SEPARATOR . "uploads";
$file = $baseDirectory . DIRECTORY_SEPARATOR . $matches[2] . DIRECTORY_SEPARATOR . $matches[3];
if (!is_file($file)) {
    http_response_code(404);
    exit;
}

$mimeTypes = [
    "jpg" => "image/jpeg",
    "jpeg" => "image/jpeg",
    "png" => "image/png",
    "webp" => "image/webp",
];
header("Content-Type: " . $mimeTypes[strtolower(pathinfo($file, PATHINFO_EXTENSION))]);
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, no-store");
readfile($file);
