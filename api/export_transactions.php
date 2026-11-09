<?php
require_once "includes/config.php";
require_once "includes/transaction_report.php";
require_once "includes/xlsx.php";

require_login();
$user = current_user($conn);
if (!$user || $user["role"] !== "admin") {
    http_response_code(403);
    exit("Only administrators can export transaction data.");
}

[$status, $search] = report_filters_from_request();
$rows = fetch_report_transactions($conn, $status, $search);

$headers = [
    "Order ID", "Order Date", "Product", "Category", "Buyer", "Seller", "Meet-up Location",
    "Meet-up Time", "Payment Type", "Payment Method", "Payment Status", "Amount (IDR)",
];

$sheetRows = [];
foreach ($rows as $row) {
    $sheetRows[] = [
        (int)$row["id"],
        $row["created_at"],
        $row["product_name"],
        $row["product_category"],
        $row["buyer_name"],
        $row["seller_name"],
        $row["meetup_location"],
        $row["meetup_time"] ?? "",
        ucfirst((string)$row["payment_type"]),
        $row["payment_method"],
        $row["payment_status"],
        $row["amount"],
    ];
}

$binary = SimpleXlsx::build($headers, $sheetRows, [
    "sheet_name" => "Transactions",
    "widths" => [10, 20, 36, 16, 24, 24, 20, 20, 14, 22, 16, 16],
    "numeric_columns" => [0 => 0, 11 => 2],
]);

record_admin_action(
    $conn,
    (int)$user["id"],
    "transactions_exported",
    "transaction",
    0,
    "Exported " . count($rows) . " transactions to Excel (status: " . ($status !== "" ? $status : "all") . ", search: " . ($search !== "" ? "yes" : "none") . ")."
);

$filename = "looply-transactions-" . date("Ymd-His") . ".xlsx";
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header('Content-Disposition: attachment; filename="' . $filename . '"');
header("Content-Length: " . strlen($binary));
header("Cache-Control: private, no-store");
header("X-Content-Type-Options: nosniff");
echo $binary;
exit;
