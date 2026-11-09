<?php
require_once "includes/config.php";
require_once "includes/transaction_report.php";

require_login();
$user = current_user($conn);
if (!$user || $user["role"] !== "admin") {
    http_response_code(403);
    exit("Only administrators can view the transaction report.");
}

[$status, $search] = report_filters_from_request();
$rows = fetch_report_transactions($conn, $status, $search);
$summary = summarize_report_transactions($rows);
$generatedAt = date("d M Y, H:i");
$filterQuery = http_build_query(array_filter(
    ["transaction_status" => $status, "q" => $search],
    static fn($value) => $value !== ""
));
$exportUrl = "export_transactions.php" . ($filterQuery !== "" ? "?" . $filterQuery : "");
$rupiah = static fn($amount): string => "Rp " . number_format((float)$amount, 0, ",", ".");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Report | LOOPLY</title>
    <style>
        :root { --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --brand: #ea580c; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font-family: "Segoe UI", Arial, sans-serif; font-size: 13px; color: var(--ink); background: #f8fafc; }
        .sheet { max-width: 1100px; margin: 0 auto; padding: 24px; background: #fff; border: 1px solid var(--line); border-radius: 12px; }
        h1 { margin: 0; font-size: 22px; color: var(--brand); }
        .meta { margin: 4px 0 0; color: var(--muted); }
        .toolbar { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0; }
        .btn { display: inline-block; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--ink); background: #fff; color: var(--ink); font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin: 16px 0; }
        .card { padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; }
        .card span { display: block; color: var(--muted); font-size: 12px; }
        .card strong { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        th { background: #fff7ed; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .status { font-weight: 600; }
        .empty { padding: 24px; text-align: center; color: var(--muted); }
        footer { margin-top: 16px; color: var(--muted); font-size: 12px; }
        @page { size: A4 landscape; margin: 12mm; }
        @media print {
            body { padding: 0; background: #fff; }
            .sheet { max-width: none; padding: 0; border: 0; border-radius: 0; }
            .no-print { display: none !important; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <h1>LOOPLY Transaction Report</h1>
    <p class="meta">
        Generated <?= e($generatedAt) ?> by <?= e($user["full_name"]) ?> (<?= e(ucfirst($user["role"])) ?>) &middot;
        Status: <?= e($status !== "" ? $status : "All") ?> &middot;
        Search: <?= e($search !== "" ? $search : "none") ?>
    </p>

    <div class="toolbar no-print">
        <button type="button" class="btn primary" onclick="window.print()">Print / Save as PDF</button>
        <a class="btn" href="<?= e($exportUrl) ?>">Export to Excel</a>
        <a class="btn" href="dashboard_admin.php">Back to dashboard</a>
    </div>

    <div class="cards">
        <div class="card"><span>Total transactions</span><strong><?= (int)$summary["count"] ?></strong></div>
        <div class="card"><span>Total value</span><strong><?= e($rupiah($summary["total"])) ?></strong></div>
        <?php foreach ($summary["by_status"] as $statusName => $stat): ?>
            <div class="card"><span><?= e($statusName) ?></span><strong><?= (int)$stat["count"] ?> &middot; <?= e($rupiah($stat["amount"])) ?></strong></div>
        <?php endforeach; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Date</th>
                <th>Product</th>
                <th>Buyer</th>
                <th>Seller</th>
                <th>Meet-up</th>
                <th>Payment</th>
                <th>Status</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="num"><?= (int)$row["id"] ?></td>
                <td><?= e(substr((string)$row["created_at"], 0, 16)) ?></td>
                <td><?= e((string)$row["product_name"]) ?><br><small><?= e((string)$row["product_category"]) ?></small></td>
                <td><?= e((string)$row["buyer_name"]) ?></td>
                <td><?= e((string)$row["seller_name"]) ?></td>
                <td><?= e((string)$row["meetup_location"]) ?><?= $row["meetup_time"] ? "<br><small>" . e(substr((string)$row["meetup_time"], 0, 16)) . "</small>" : "" ?></td>
                <td><?= e(ucfirst((string)$row["payment_type"])) ?><br><small><?= e((string)$row["payment_method"]) ?></small></td>
                <td class="status"><?= e((string)$row["payment_status"]) ?></td>
                <td class="num"><?= e($rupiah($row["amount"])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="9" class="empty">No transactions match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <footer>Confidential: for internal LOOPLY administration only. <?= (int)$summary["count"] ?> record(s) shown.</footer>
</div>
</body>
</html>
