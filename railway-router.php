<?php

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) ?: "/";
$root = __DIR__;

if (preg_match("#^/(assets|uploads)/(.*)$#", $path, $matches)) {
    $file = $root . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . $matches[1] . DIRECTORY_SEPARATOR . $matches[2];
    if (is_file($file)) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeTypes = [
            "css" => "text/css",
            "gif" => "image/gif",
            "ico" => "image/x-icon",
            "jpg" => "image/jpeg",
            "jpeg" => "image/jpeg",
            "js" => "text/javascript",
            "png" => "image/png",
            "svg" => "image/svg+xml",
            "webp" => "image/webp",
        ];
        header("Content-Type: " . ($mimeTypes[$extension] ?? "application/octet-stream"));
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

$page = $path === "/" ? "index.php" : ltrim($path, "/");
if (!preg_match("#^[A-Za-z0-9_-]+\\.php$#", $page)) {
    http_response_code(404);
    exit;
}

$file = $root . DIRECTORY_SEPARATOR . "api" . DIRECTORY_SEPARATOR . $page;
if (!is_file($file)) {
    http_response_code(404);
    exit;
}

require $file;
