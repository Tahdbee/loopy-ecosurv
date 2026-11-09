<?php
require_once "includes/config.php";
require_login();

$relativePath = $_GET["path"] ?? "";
if (!is_string($relativePath) || !preg_match("#^(private|uploads)/(payment-proofs|seller-payment-qrs)/([A-Za-z0-9_-]+\\.(?:jpg|jpeg|png|webp))$#i", $relativePath, $matches)) {
    http_response_code(404);
    exit;
}

$user = current_user($conn);
$path = $conn->real_escape_string($relativePath);
$userId = (int)$user["id"];
$access = $conn->query(
    "SELECT 1 FROM users WHERE id = $userId AND payment_qr_image = '$path'
     UNION ALL
     SELECT 1 FROM transactions
     WHERE (buyer_id = $userId OR seller_id = $userId)
       AND (buyer_payment_proof = '$path' OR seller_cash_receipt = '$path')
     LIMIT 1"
);
if (!$access || !$access->fetch_assoc()) {
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
