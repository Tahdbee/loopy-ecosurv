<?php
require_once dirname(__DIR__) . "/includes/config.php";

if (!is_logged_in()) {
    header("Location: " . app_page_url("login.php"));
    exit;
}

ensure_admin_role_schema($conn);
$user = current_user($conn);
if (!$user || $user["role"] !== "admin") {
    http_response_code(403);
    exit("Admin access required.");
}

$counts = [];
foreach ([
    "Accounts" => "SELECT COUNT(*) total FROM users",
    "Product listings" => "SELECT COUNT(*) total FROM products",
    "Transactions" => "SELECT COUNT(*) total FROM transactions",
    "Reviews" => "SELECT COUNT(*) total FROM reviews",
    "Open reports" => "SELECT COUNT(*) total FROM reports WHERE status = 'pending'",
] as $label => $sql) {
    $counts[$label] = (int)$conn->query($sql)->fetch_assoc()["total"];
}

$monthlyTransactions = $conn->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') transaction_month,
            COUNT(*) transaction_count,
            SUM(amount) total_amount,
            SUM(payment_status = 'Completed') completed_count,
            SUM(CASE WHEN payment_status = 'Completed' THEN amount ELSE 0 END) completed_amount,
            SUM(payment_status = 'Pending') pending_count,
            SUM(payment_status = 'Paid') paid_count,
            SUM(payment_status = 'Cancelled') cancelled_count
     FROM transactions
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY transaction_month DESC"
)->fetch_all(MYSQLI_ASSOC);

$transactions = $conn->query(
    "SELECT t.id, p.name product_name, b.full_name buyer_name, s.full_name seller_name,
            t.amount, t.payment_status, t.payment_type, t.created_at
     FROM transactions t
     JOIN products p ON p.id = t.product_id
     JOIN users b ON b.id = t.buyer_id
     JOIN users s ON s.id = t.seller_id
     ORDER BY t.created_at DESC, t.id DESC"
)->fetch_all(MYSQLI_ASSOC);

$generatedAt = date("Y-m-d H:i:s") . " (Asia/Jakarta)";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printable website report | LOOPLY</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #172033; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #edf1f7; }
        .toolbar { position: sticky; top: 0; z-index: 1; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 14px max(20px, calc((100% - 1120px) / 2)); background: #172554; color: white; box-shadow: 0 2px 8px #0f172a30; }
        .toolbar p { margin: 0; font-size: 14px; line-height: 1.5; }
        .toolbar button { border: 0; border-radius: 9px; padding: 11px 18px; background: #fff; color: #172554; font-size: 14px; font-weight: 700; cursor: pointer; }
        .page { width: min(1120px, calc(100% - 32px)); margin: 28px auto; padding: 44px; background: #fff; box-shadow: 0 8px 30px #0f172a18; }
        header { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; padding-bottom: 24px; border-bottom: 2px solid #e2e8f0; }
        h1 { margin: 0; font-size: 30px; letter-spacing: -.03em; }
        h2 { margin: 32px 0 12px; font-size: 19px; }
        .subtitle, .muted { color: #64748b; font-size: 13px; }
        .subtitle { margin: 8px 0 0; }
        .generated { text-align: right; white-space: nowrap; }
        .summary { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-top: 24px; }
        .metric { padding: 14px; border: 1px solid #e2e8f0; border-radius: 10px; }
        .metric-label { margin: 0; color: #64748b; font-size: 12px; }
        .metric-value { margin: 7px 0 0; font-size: 23px; font-weight: 700; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 9px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; color: #334155; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        td.numeric { text-align: right; white-space: nowrap; }
        .empty { padding: 16px; border-radius: 8px; background: #f8fafc; color: #64748b; }
        footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 11px; }
        @media (max-width: 700px) {
            .page { padding: 24px 18px; }
            header { flex-direction: column; }
            .generated { text-align: left; white-space: normal; }
            .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @page { size: A4 landscape; margin: 12mm; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .page { width: auto; margin: 0; padding: 0; box-shadow: none; }
            .summary { grid-template-columns: repeat(5, minmax(0, 1fr)); }
            .table-wrap { overflow: visible; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; }
            h2 { break-after: avoid; }
            th { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<nav class="toolbar" aria-label="Print preview controls">
    <p><strong>Print preview</strong> · Review the report below, then print it or choose “Save as PDF” in the print dialog.</p>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</nav>
<main class="page">
    <header>
        <div>
            <h1>LOOPLY Website Report</h1>
            <p class="subtitle">Marketplace activity summary and transaction detail</p>
        </div>
        <div class="generated">
            <div class="muted">Generated</div>
            <strong><?= e($generatedAt) ?></strong>
        </div>
    </header>

    <section class="summary" aria-label="Website totals">
        <?php foreach ($counts as $label => $count): ?>
            <article class="metric">
                <p class="metric-label"><?= e($label) ?></p>
                <p class="metric-value"><?= number_format($count) ?></p>
            </article>
        <?php endforeach; ?>
    </section>

    <section>
        <h2>Transactions by month</h2>
        <?php if (!$monthlyTransactions): ?>
            <p class="empty">No transaction data is available.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Month</th><th class="numeric">Transactions</th><th class="numeric">Total order amount</th><th class="numeric">Completed</th><th class="numeric">Completed amount</th><th class="numeric">Pending</th><th class="numeric">Paid</th><th class="numeric">Cancelled</th></tr></thead>
                    <tbody>
                    <?php foreach ($monthlyTransactions as $row): ?>
                        <tr>
                            <td><?= e((string)$row["transaction_month"]) ?></td>
                            <td class="numeric"><?= number_format((int)$row["transaction_count"]) ?></td>
                            <td class="numeric">Rp <?= number_format((float)$row["total_amount"], 0, ",", ".") ?></td>
                            <td class="numeric"><?= number_format((int)$row["completed_count"]) ?></td>
                            <td class="numeric">Rp <?= number_format((float)$row["completed_amount"], 0, ",", ".") ?></td>
                            <td class="numeric"><?= number_format((int)$row["pending_count"]) ?></td>
                            <td class="numeric"><?= number_format((int)$row["paid_count"]) ?></td>
                            <td class="numeric"><?= number_format((int)$row["cancelled_count"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section>
        <h2>Transaction details <span class="muted">(<?= number_format(count($transactions)) ?> total)</span></h2>
        <?php if (!$transactions): ?>
            <p class="empty">No transaction data is available.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>Product</th><th>Buyer</th><th>Seller</th><th class="numeric">Amount</th><th>Payment type</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td>#<?= (int)$transaction["id"] ?></td>
                            <td><?= e((string)$transaction["product_name"]) ?></td>
                            <td><?= e((string)$transaction["buyer_name"]) ?></td>
                            <td><?= e((string)$transaction["seller_name"]) ?></td>
                            <td class="numeric">Rp <?= number_format((float)$transaction["amount"], 0, ",", ".") ?></td>
                            <td><?= e((string)$transaction["payment_type"]) ?></td>
                            <td><?= e((string)$transaction["payment_status"]) ?></td>
                            <td><?= e((string)$transaction["created_at"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <footer>Generated by LOOPLY Admin · This report reflects website data at the time it was generated.</footer>
</main>
</body>
</html>
