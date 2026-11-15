<?php
require_once dirname(__DIR__) . "/includes/config.php";
if (!is_logged_in()) {
    header("Location: " . app_page_url("login.php"));
    exit;
}
ensure_admin_role_schema($conn);
$user = current_user($conn);
if (!$user || !in_array($user["role"], ["admin", "reviewer"], true)) {
    http_response_code(403);
    exit("Admin access required.");
}

ensure_reports_table($conn);
ensure_staff_notifications_table($conn);
$reasonLabels = [
    "scam" => "Scam or fraud",
    "prohibited_item" => "Prohibited item",
    "misleading_listing" => "Misleading information",
    "harassment" => "Harassment or abusive behavior",
    "other" => "Other",
];
$reportStatuses = ["pending", "reviewed", "actioned", "dismissed"];

if ($user["role"] === "reviewer") {
    if (isset($_GET["export"]) || isset($_GET["download_pdf"])) {
        http_response_code(403);
        exit("Report reviewers cannot access admin exports or documents.");
    }

    $error = "";
    $notice = "";
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        verify_csrf_token();
        $action = $_POST["action"] ?? "";
        $reportId = filter_var($_POST["report_id"] ?? null, FILTER_VALIDATE_INT);
        $status = $_POST["status"] ?? "";
        if ($action === "mark_staff_notifications_read") {
            $read = $conn->prepare("UPDATE staff_notifications SET is_read = 1 WHERE user_id = ?");
            $read->bind_param("i", $user["id"]);
            $read->execute();
            $notice = "Staff notifications marked as read.";
        } elseif (in_array($action, ["block_reported_product", "delete_reported_product", "suspend_reported_account"], true)) {
            if ($reportId === false || $reportId < 1) {
                $error = "Choose a valid report.";
            } else {
                try {
                    $notice = moderate_report($conn, $reportId, $action);
                } catch (RuntimeException $exception) {
                    $error = $exception->getMessage();
                }
            }
        } elseif ($action !== "update_report" || $reportId === false || $reportId < 1 || !is_string($status) || !in_array($status, $reportStatuses, true) || $status === "actioned") {
            http_response_code(400);
            $error = "Choose a valid report and status. Use a moderation action to mark a report actioned.";
        } else {
            $update = $conn->prepare("UPDATE reports SET status = ? WHERE id = ?");
            $update->bind_param("si", $status, $reportId);
            $update->execute();
            $notice = $update->affected_rows === 1 ? "Report status updated." : "Report status is unchanged or the report no longer exists.";
            if ($update->affected_rows === 1) {
                notify_staff($conn, "Report #" . $reportId . " status changed to " . $status . ".", "admin/admin-dashboard.php#reports");
            }
        }
    }

    $reports = admin_query_rows(
        $conn,
        "SELECT r.id, r.product_id, r.reported_user_id, r.reason, r.details, r.status, r.created_at,
                reporter.full_name reporter_name, p.name product_name, p.is_blocked product_blocked, p.is_deleted product_deleted,
                reported.full_name reported_name, reported.is_blocked account_blocked, reported.role reported_role
         FROM reports r
         JOIN users reporter ON reporter.id = r.reporter_id
         LEFT JOIN products p ON p.id = r.product_id
         LEFT JOIN users reported ON reported.id = r.reported_user_id
         ORDER BY FIELD(r.status, 'pending', 'reviewed', 'actioned', 'dismissed'), r.created_at DESC
         LIMIT 50"
    );
    $pendingReports = (int)$conn->query("SELECT COUNT(*) total FROM reports WHERE status = 'pending'")->fetch_assoc()["total"];
    $staffNotifications = admin_query_rows($conn, "SELECT id, message, link, is_read, created_at FROM staff_notifications WHERE user_id = " . (int)$user["id"] . " ORDER BY created_at DESC, id DESC LIMIT 20");
    $unreadStaffNotifications = (int)$conn->query("SELECT COUNT(*) total FROM staff_notifications WHERE user_id = " . (int)$user["id"] . " AND is_read = 0")->fetch_assoc()["total"];
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Report review | LOOPLY Staff</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="<?= e(asset_url("assets/css/app.css")) ?>">
    </head>
    <body class="min-h-screen bg-slate-50">
    <?php include dirname(__DIR__) . "/includes/navbar.php"; ?>
    <main class="mx-auto max-w-7xl space-y-7 p-4 py-8">
        <section class="overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-700 via-blue-700 to-cyan-600 p-6 text-white shadow-xl md:p-9">
            <p class="text-sm font-bold uppercase tracking-[.18em] text-blue-100">LOOPLY · Trust &amp; Safety</p>
            <div class="mt-4 flex flex-wrap items-end justify-between gap-5">
                <div><h1 class="text-3xl font-extrabold md:text-4xl">Report review</h1><p class="mt-2 max-w-xl text-blue-100">Review reports, receive staff notifications, and take moderation actions. Your staff access is limited to this page.</p></div>
                <div class="rounded-2xl border border-white/20 bg-white/10 px-6 py-4 backdrop-blur"><p class="text-sm text-blue-100">Awaiting review</p><p class="mt-1 text-3xl font-extrabold"><?= number_format($pendingReports) ?></p></div>
            </div>
        </section>
        <?php if ($error): ?><p role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800"><?= e($error) ?></p><?php endif; ?>
        <?php if ($notice): ?><p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800"><?= e($notice) ?></p><?php endif; ?>
        <section id="notifications" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-7">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-xl font-bold text-slate-900">Staff notifications</h2><p class="mt-1 text-sm text-slate-500"><?= number_format($unreadStaffNotifications) ?> unread</p></div>
                <?php if ($unreadStaffNotifications > 0): ?>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="mark_staff_notifications_read"><button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Mark all as read</button></form>
                <?php endif; ?>
            </div>
            <?php if (!$staffNotifications): ?>
                <p class="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">No staff notifications yet.</p>
            <?php else: ?>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($staffNotifications as $notification): ?>
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 <?= (int)$notification["is_read"] ? "text-slate-500" : "font-semibold text-slate-900" ?>">
                            <a href="<?= e(app_page_url($notification["link"])) ?>" class="min-w-0 flex-1"><?= e($notification["message"]) ?><span class="ml-2 block text-xs font-normal text-slate-500"><?= e($notification["created_at"]) ?></span></a>
                            <?php if (!(int)$notification["is_read"]): ?><span class="rounded-full bg-blue-100 px-2 py-1 text-xs text-blue-800">New</span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-7">
            <div class="mb-5"><h2 class="text-xl font-bold text-slate-900">Community reports</h2><p class="mt-1 text-sm text-slate-500">The latest 50 reports, with pending reports shown first.</p></div>
            <?php if (!$reports): ?>
                <div class="rounded-xl bg-slate-50 p-8 text-center"><p class="font-semibold text-slate-800">You’re all caught up</p><p class="mt-1 text-sm text-slate-500">There are no reports to review yet.</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1150px] text-left text-sm">
                        <thead class="border-b text-xs uppercase text-slate-500"><tr><th class="p-3">Reported target</th><th class="p-3">Reason and details</th><th class="p-3">Reported by</th><th class="p-3">Date</th><th class="p-3">Review status</th><th class="p-3">Moderation</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php foreach ($reports as $report): ?>
                            <?php $accountReport = $report["product_id"] === null; ?>
                            <tr class="align-top">
                                <td class="p-3"><strong><?= $accountReport ? "Account" : "Listing" ?>:</strong> <?= e($accountReport ? (string)$report["reported_name"] : (string)$report["product_name"]) ?><div class="mt-1 text-xs text-slate-500"><?= $accountReport ? "User #" . (int)$report["reported_user_id"] : "Product #" . (int)$report["product_id"] ?></div></td>
                                <td class="max-w-sm p-3"><strong><?= e($reasonLabels[$report["reason"]] ?? $report["reason"]) ?></strong><p class="mt-1 whitespace-pre-line text-slate-600"><?= e($report["details"] ?: "No additional details.") ?></p></td>
                                <td class="p-3"><?= e($report["reporter_name"]) ?></td>
                                <td class="whitespace-nowrap p-3 text-slate-500"><?= e($report["created_at"]) ?></td>
                                <td class="p-3">
                                    <?php if ($report["status"] === "actioned"): ?>
                                        <span class="font-semibold text-emerald-700">Actioned</span>
                                    <?php else: ?>
                                        <form method="post" class="flex items-center gap-2"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_report"><input type="hidden" name="report_id" value="<?= (int)$report["id"] ?>"><select name="status" aria-label="Report status" class="rounded-lg border border-slate-300 px-2 py-2"><?php foreach (["pending", "reviewed", "dismissed"] as $status): ?><option value="<?= e($status) ?>" <?= $report["status"] === $status ? "selected" : "" ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select><button class="rounded-lg bg-slate-900 px-3 py-2 font-semibold text-white hover:bg-slate-700">Save</button></form>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3">
                                    <div class="flex flex-wrap gap-2">
                                        <?php if (!$accountReport): ?>
                                            <?php if ((int)$report["product_deleted"]): ?>
                                                <span class="text-xs font-semibold text-red-700">Post removed</span>
                                            <?php else: ?>
                                                <?php if ((int)$report["product_blocked"]): ?>
                                                    <span class="self-center text-xs font-semibold text-amber-700">Post blocked</span>
                                                <?php else: ?>
                                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="block_reported_product"><input type="hidden" name="report_id" value="<?= (int)$report["id"] ?>"><button class="rounded-lg border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-50">Block post</button></form>
                                                <?php endif; ?>
                                                <form method="post" onsubmit="return confirm(<?= e(json_encode('Remove reported post “' . (string)$report["product_name"] . '”? Transaction and report history will be kept.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_reported_product"><input type="hidden" name="report_id" value="<?= (int)$report["id"] ?>"><button class="rounded-lg border border-red-300 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50">Remove post</button></form>
                                            <?php endif; ?>
                                        <?php elseif ($report["reported_user_id"] !== null && $report["reported_role"] !== null): ?>
                                            <?php if ((int)$report["account_blocked"]): ?>
                                                <span class="text-xs font-semibold text-red-700">Account suspended</span>
                                            <?php elseif (in_array($report["reported_role"], ["buyer", "seller"], true)): ?>
                                                <form method="post" onsubmit="return confirm(<?= e(json_encode('Suspend reported account ' . (string)$report["reported_name"] . '? They will be signed out and unable to log in.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="suspend_reported_account"><input type="hidden" name="report_id" value="<?= (int)$report["id"] ?>"><button class="rounded-lg border border-red-300 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50">Suspend account</button></form>
                                            <?php else: ?>
                                                <span class="text-xs text-slate-500">Staff account protected</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php include dirname(__DIR__) . "/includes/footer.php"; ?>
    </body>
    </html>
    <?php
    exit;
}

if ($user["role"] !== "admin") {
    http_response_code(403);
    exit("Admin access required.");
}

$facultyMajors = faculty_majors();
ensure_admin_documents_table($conn);
require_once dirname(__DIR__, 2) . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Smalot\PdfParser\Parser;

function admin_export_sheet(Spreadsheet $book, string $title, array $headers, array $rows): void
{
    $sheet = $book->getActiveSheet();
    $sheet->setTitle(substr($title, 0, 31));
    foreach ($headers as $column => $heading) {
        $sheet->setCellValueExplicit([$column + 1, 1], $heading, DataType::TYPE_STRING);
    }
    foreach ($rows as $rowIndex => $row) {
        foreach (array_values($row) as $column => $value) {
            $sheet->setCellValueExplicit(
                [$column + 1, $rowIndex + 2],
                $value === null ? "" : (string)$value,
                DataType::TYPE_STRING
            );
        }
    }
    if (!$rows) {
        $sheet->setCellValueExplicit("A2", "No records available.", DataType::TYPE_STRING);
    }
    $sheet->freezePane("A2");
    $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
    foreach (range(1, count($headers)) as $column) {
        $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
    }
}

function admin_query_rows(mysqli $conn, string $sql): array
{
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function admin_user_input(string $key): string
{
    $value = $_POST[$key] ?? "";
    return is_string($value) ? trim($value) : "";
}

function admin_valid_faculty_major(mysqli $conn, array $facultyMajors, string $faculty, string $major, int $userId = 0): bool
{
    if ($faculty === "" && $major === "") {
        return true;
    }
    if (isset($facultyMajors[$faculty]) && in_array($major, $facultyMajors[$faculty], true)) {
        return true;
    }
    if ($userId < 1) {
        return false;
    }

    $current = $conn->prepare("SELECT faculty, major FROM users WHERE id = ?");
    $current->bind_param("i", $userId);
    $current->execute();
    $currentRow = $current->get_result()->fetch_assoc();
    return $currentRow
        && (string)($currentRow["faculty"] ?? "") === $faculty
        && (string)($currentRow["major"] ?? "") === $major;
}

function admin_send_workbook(Spreadsheet $book, string $filename): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header("Cache-Control: no-store, private");
    (new Xlsx($book))->save("php://output");
    exit;
}

$error = "";
$notice = "";

if (isset($_GET["export"]) && $_GET["export"] === "website") {
    $workbook = new Spreadsheet();
    $exportCounts = [];
    foreach ([
        "Users" => "users",
        "Products" => "products",
        "Transactions" => "transactions",
        "Reviews" => "reviews",
        "Reports" => "reports",
        "Favorites" => "favorites",
    ] as $sheetTitle => $tableName) {
        $exportCounts[] = [
            "Sheet" => $sheetTitle,
            "Records" => (int)$conn->query("SELECT COUNT(*) total FROM `$tableName`")->fetch_assoc()["total"],
        ];
    }
    $monthlyTransactionRows = admin_query_rows(
        $conn,
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
    );
    $exportCounts[] = ["Sheet" => "Monthly Transactions", "Records" => count($monthlyTransactionRows)];
    admin_export_sheet($workbook, "Summary", ["Data sheet", "Records"], $exportCounts);
    $workbook->createSheet();
    $workbook->setActiveSheetIndex(1);
    admin_export_sheet($workbook, "Users", [
        "User ID", "Name", "Email", "Role", "Faculty", "Major", "Verified", "Joined",
    ], admin_query_rows($conn, "SELECT id, full_name, email, role, faculty, major, is_verified, created_at FROM users ORDER BY id DESC"));
    $workbook->createSheet();
    $workbook->setActiveSheetIndex(2);
    admin_export_sheet($workbook, "Products", [
        "Product ID", "Seller ID", "Seller", "Name", "Category", "Description", "Price", "Condition", "Sold", "Blocked", "Removed", "Created",
    ], admin_query_rows($conn, "SELECT p.id, p.seller_id, u.full_name seller_name, p.name, p.category, p.description, p.price, p.product_condition, p.is_sold, p.is_blocked, p.is_deleted, p.created_at FROM products p JOIN users u ON u.id = p.seller_id ORDER BY p.id DESC"));
    $workbook->createSheet();
    $workbook->setActiveSheetIndex(3);
    admin_export_sheet($workbook, "Transactions", [
        "Transaction ID", "Product ID", "Product", "Buyer ID", "Buyer", "Seller ID", "Seller", "Amount", "Status", "Created Month",
        "Payment Type", "Payment Method", "Meetup Location", "Meetup Time", "Created",
    ], admin_query_rows($conn, "SELECT t.id, t.product_id, p.name product_name, t.buyer_id, b.full_name buyer_name, t.seller_id, s.full_name seller_name, t.amount, t.payment_status, DATE_FORMAT(t.created_at, '%Y-%m') transaction_month, t.payment_type, t.payment_method, t.meetup_location, t.meetup_time, t.created_at FROM transactions t JOIN products p ON p.id = t.product_id JOIN users b ON b.id = t.buyer_id JOIN users s ON s.id = t.seller_id ORDER BY t.id DESC"));
    $workbook->createSheet();
    $workbook->setActiveSheetIndex(4);
    admin_export_sheet($workbook, "Monthly Transactions", [
        "Month", "Transaction Count", "Total Order Amount", "Completed Count", "Completed Amount", "Pending Count", "Paid Count", "Cancelled Count",
    ], $monthlyTransactionRows);
    $workbook->createSheet();
    $workbook->setActiveSheetIndex(5);
    admin_export_sheet($workbook, "Reviews", [
        "Review ID", "Transaction ID", "Reviewer ID", "Reviewer", "Reviewee ID", "Reviewee", "Rating", "Comment", "Created",
    ], admin_query_rows($conn, "SELECT r.id, r.transaction_id, r.reviewer_id, reviewer.full_name reviewer_name, r.reviewee_id, reviewee.full_name reviewee_name, r.rating, r.comment, r.created_at FROM reviews r JOIN users reviewer ON reviewer.id = r.reviewer_id JOIN users reviewee ON reviewee.id = r.reviewee_id ORDER BY r.id DESC"));
    $workbook->createSheet();
    $workbook->setActiveSheetIndex(6);
    admin_export_sheet($workbook, "Reports", [
        "Report ID", "Type", "Reporter ID", "Reporter", "Product ID", "Product", "Reported Account ID", "Reported Account",
        "Reason", "Details", "Status", "Created",
    ], admin_query_rows($conn, "SELECT r.id, IF(r.product_id IS NULL, 'account', 'product') report_type, r.reporter_id, reporter.full_name reporter_name, r.product_id, p.name product_name, r.reported_user_id, reported.full_name reported_name, r.reason, r.details, r.status, r.created_at FROM reports r JOIN users reporter ON reporter.id = r.reporter_id LEFT JOIN products p ON p.id = r.product_id LEFT JOIN users reported ON reported.id = r.reported_user_id ORDER BY r.id DESC"));
    $workbook->createSheet();
    $workbook->setActiveSheetIndex(7);
    admin_export_sheet($workbook, "Favorites", [
        "Favorite ID", "User ID", "User", "Product ID", "Product", "Created",
    ], admin_query_rows($conn, "SELECT f.id, f.user_id, u.full_name user_name, f.product_id, p.name product_name, f.created_at FROM favorites f JOIN users u ON u.id = f.user_id JOIN products p ON p.id = f.product_id ORDER BY f.id DESC"));
    admin_send_workbook($workbook, "looply-website-data.xlsx");
}

if (isset($_GET["download_pdf"])) {
    $documentId = filter_var($_GET["download_pdf"], FILTER_VALIDATE_INT);
    if ($documentId === false || $documentId < 1) {
        http_response_code(400);
        exit("Invalid document.");
    }
    $documentQuery = $conn->prepare("SELECT original_name, storage_path FROM admin_documents WHERE id = ?");
    $documentQuery->bind_param("i", $documentId);
    $documentQuery->execute();
    $document = $documentQuery->get_result()->fetch_assoc();
    if (!$document || !preg_match("#^private/admin-documents/[a-f0-9]{32}\\.pdf$#", $document["storage_path"])) {
        http_response_code(404);
        exit("Document not found.");
    }
    $filePath = PRIVATE_UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . "admin-documents" . DIRECTORY_SEPARATOR . basename($document["storage_path"]);
    if (!is_file($filePath)) {
        http_response_code(404);
        exit("Document file not found.");
    }
    header("Content-Type: application/pdf");
    header('Content-Disposition: attachment; filename="' . rawurlencode(basename($document["original_name"])) . '"');
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: no-store, private");
    readfile($filePath);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $action = $_POST["action"] ?? "";
    if ($action === "add_reviewer") {
        $emailInput = $_POST["reviewer_email"] ?? "";
        $reviewerEmail = is_string($emailInput) ? strtolower(trim($emailInput)) : "";
        if (!filter_var($reviewerEmail, FILTER_VALIDATE_EMAIL)) {
            $error = "Enter a valid email address for the staff account.";
        } else {
            $reviewerQuery = $conn->prepare("SELECT id, role FROM users WHERE email = ?");
            $reviewerQuery->bind_param("s", $reviewerEmail);
            $reviewerQuery->execute();
            $reviewer = $reviewerQuery->get_result()->fetch_assoc();
            if (!$reviewer) {
                $error = "No account uses that email. Ask the staff member to register or use an existing account.";
            } elseif (in_array($reviewer["role"], ["admin", "reviewer"], true)) {
                $error = $reviewer["role"] === "admin"
                    ? "This account already has full admin access."
                    : "This account is already a report reviewer.";
            } else {
                $promote = $conn->prepare("UPDATE users SET reviewer_previous_role = role, role = 'reviewer' WHERE id = ?");
                $promote->bind_param("i", $reviewer["id"]);
                $promote->execute();
                $notice = "Report reviewer access granted to " . $reviewerEmail . ".";
            }
        }
    } elseif ($action === "create_user") {
        $fullName = admin_user_input("full_name");
        $email = strtolower(admin_user_input("email"));
        $password = $_POST["password"] ?? "";
        $role = admin_user_input("role");
        $faculty = admin_user_input("faculty");
        $major = admin_user_input("major");
        $verified = isset($_POST["is_verified"]) && $_POST["is_verified"] === "1" ? 1 : 0;

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            $error = "Enter a name between 2 and 120 characters and a valid email address up to 120 characters.";
        } elseif (!is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
            $error = "Set a password between 8 and 72 characters.";
        } elseif (
            !in_array($role, ["buyer", "seller", "reviewer"], true)
            || mb_strlen($faculty) > 120 || mb_strlen($major) > 120
            || !admin_valid_faculty_major($conn, $facultyMajors, $faculty, $major)
        ) {
            $error = "Choose a valid role and matching faculty and major.";
        } else {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $insert = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role, faculty, major, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insert->bind_param("ssssssi", $fullName, $email, $passwordHash, $role, $faculty, $major, $verified);
            try {
                $insert->execute();
                $notice = "Account created for " . $email . ".";
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() !== 1062) {
                    throw $exception;
                }
                $error = "An account already uses that email address.";
            }
        }
    } elseif ($action === "update_user") {
        $userId = filter_var($_POST["user_id"] ?? null, FILTER_VALIDATE_INT);
        $fullName = admin_user_input("full_name");
        $email = strtolower(admin_user_input("email"));
        $role = admin_user_input("role");
        $faculty = admin_user_input("faculty");
        $major = admin_user_input("major");
        $verified = isset($_POST["is_verified"]) && $_POST["is_verified"] === "1" ? 1 : 0;
        $blocked = isset($_POST["is_blocked"]) && $_POST["is_blocked"] === "1" ? 1 : 0;

        if ($userId === false || $userId < 1) {
            $error = "Choose a valid account.";
        } elseif ($userId === (int)$user["id"]) {
            $error = "Edit your own administrator account from your profile instead.";
        } elseif (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            $error = "Enter a name between 2 and 120 characters and a valid email address up to 120 characters.";
        } elseif (
            !in_array($role, ["buyer", "seller", "reviewer"], true)
            || mb_strlen($faculty) > 120 || mb_strlen($major) > 120
            || !admin_valid_faculty_major($conn, $facultyMajors, $faculty, $major, $userId === false ? 0 : $userId)
        ) {
            $error = "Choose a valid role and matching faculty and major.";
        } else {
            $update = $conn->prepare("UPDATE users SET reviewer_previous_role = CASE WHEN ? = 'reviewer' AND role IN ('buyer', 'seller') THEN role WHEN ? <> 'reviewer' THEN NULL ELSE reviewer_previous_role END, full_name = ?, email = ?, role = ?, faculty = ?, major = ?, is_verified = ?, is_blocked = ? WHERE id = ? AND role <> 'admin'");
            $update->bind_param("sssssssiii", $role, $role, $fullName, $email, $role, $faculty, $major, $verified, $blocked, $userId);
            try {
                $update->execute();
                $notice = $update->affected_rows === 1 ? "Account updated." : "No changes were made, or the account is protected.";
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() !== 1062) {
                    throw $exception;
                }
                $error = "An account already uses that email address.";
            }
        }
    } elseif ($action === "delete_user") {
        $userId = filter_var($_POST["user_id"] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            $error = "Choose a valid account.";
        } elseif ($userId === (int)$user["id"]) {
            $error = "You cannot delete your own administrator account.";
        } else {
            $conn->begin_transaction();
            $targetQuery = $conn->prepare("SELECT role FROM users WHERE id = ? FOR UPDATE");
            $targetQuery->bind_param("i", $userId);
            $targetQuery->execute();
            $target = $targetQuery->get_result()->fetch_assoc();

            if (!$target) {
                $conn->rollback();
                $error = "That account no longer exists.";
            } elseif ($target["role"] === "admin") {
                $conn->rollback();
                $error = "Administrator accounts cannot be deleted here.";
            } else {
                $relatedRecords = [
                    ["products", "seller_id"],
                    ["transactions", "buyer_id"],
                    ["transactions", "seller_id"],
                    ["chats", "sender_id"],
                    ["chats", "receiver_id"],
                    ["reviews", "reviewer_id"],
                    ["reviews", "reviewee_id"],
                    ["favorites", "user_id"],
                    ["notifications", "user_id"],
                    ["reports", "reporter_id"],
                    ["reports", "reported_user_id"],
                    ["admin_documents", "uploaded_by"],
                ];
                $recordCount = 0;
                foreach ($relatedRecords as [$table, $column]) {
                    $countQuery = $conn->prepare("SELECT COUNT(*) total FROM `$table` WHERE `$column` = ?");
                    $countQuery->bind_param("i", $userId);
                    $countQuery->execute();
                    $recordCount += (int)$countQuery->get_result()->fetch_assoc()["total"];
                }

                if ($recordCount > 0) {
                    $conn->rollback();
                    $error = "This account has related marketplace or staff records and cannot be deleted. Change its role to revoke access instead.";
                } else {
                    $delete = $conn->prepare("DELETE FROM users WHERE id = ? AND role <> 'admin'");
                    $delete->bind_param("i", $userId);
                    $delete->execute();
                    $conn->commit();
                    $notice = $delete->affected_rows === 1 ? "Account deleted." : "The account was not deleted.";
                }
            }
        }
    } elseif ($action === "revoke_reviewer") {
        $userId = filter_var($_POST["user_id"] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1 || $userId === (int)$user["id"]) {
            $error = "Choose a valid reviewer account other than your own.";
        } else {
            $revoke = $conn->prepare("UPDATE users SET role = COALESCE(reviewer_previous_role, 'buyer'), reviewer_previous_role = NULL WHERE id = ? AND role = 'reviewer'");
            $revoke->bind_param("i", $userId);
            $revoke->execute();
            $notice = $revoke->affected_rows === 1 ? "Reviewer access revoked." : "That account is no longer a reviewer.";
        }
    } elseif ($action === "mark_staff_notifications_read") {
        $read = $conn->prepare("UPDATE staff_notifications SET is_read = 1 WHERE user_id = ?");
        $read->bind_param("i", $user["id"]);
        $read->execute();
        $notice = "Staff notifications marked as read.";
    } elseif (in_array($action, ["block_reported_product", "delete_reported_product", "suspend_reported_account"], true)) {
        $reportId = filter_var($_POST["report_id"] ?? null, FILTER_VALIDATE_INT);
        if ($reportId === false || $reportId < 1) {
            $error = "Choose a valid report.";
        } else {
            try {
                $notice = moderate_report($conn, $reportId, $action);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === "update_report") {
        $reportId = filter_var($_POST["report_id"] ?? null, FILTER_VALIDATE_INT);
        $status = $_POST["status"] ?? "";
        if ($reportId === false || $reportId < 1 || !is_string($status) || !in_array($status, $reportStatuses, true) || $status === "actioned") {
            $error = "Choose a valid report and status. Use a moderation action to mark a report actioned.";
        } else {
            $update = $conn->prepare("UPDATE reports SET status = ? WHERE id = ?");
            $update->bind_param("si", $status, $reportId);
            $update->execute();
            $notice = $update->affected_rows === 1 ? "Report status updated." : "Report status is unchanged or the report no longer exists.";
            if ($update->affected_rows === 1) {
                notify_staff($conn, "Report #" . $reportId . " status changed to " . $status . ".", "admin/admin-dashboard.php#reports");
            }
        }
    } elseif ($action === "upload_pdf") {
        $file = $_FILES["pdf_file"] ?? [];
        if (($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_FORM_SIZE) {
            $error = "PDF files must be 4 MB or smaller.";
        } elseif (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = "Choose a PDF file to upload.";
        } elseif (($file["size"] ?? 0) > 4 * 1024 * 1024) {
            $error = "PDF files must be 4 MB or smaller.";
        } elseif (!is_uploaded_file($file["tmp_name"] ?? "")) {
            $error = "The selected upload is invalid.";
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
            $signature = file_get_contents($file["tmp_name"], false, null, 0, 5);
            if ($mime !== "application/pdf" || $signature !== "%PDF-") {
                $error = "Upload a valid PDF document.";
            } else {
                $directory = PRIVATE_UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . "admin-documents";
                if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
                    throw new RuntimeException("Could not create the private document directory.");
                }
                $filename = bin2hex(random_bytes(16)) . ".pdf";
                $destination = $directory . DIRECTORY_SEPARATOR . $filename;
                if (!move_uploaded_file($file["tmp_name"], $destination)) {
                    throw new RuntimeException("Could not store the uploaded PDF.");
                }
                $originalName = preg_replace('/[\x00-\x1F\x7F]/', '', basename((string)($file["name"] ?? "document.pdf")));
                $originalName = mb_substr($originalName ?: "document.pdf", 0, 255);
                $storagePath = "private/admin-documents/" . $filename;
                $insert = $conn->prepare("INSERT INTO admin_documents (original_name, storage_path, uploaded_by) VALUES (?, ?, ?)");
                $insert->bind_param("ssi", $originalName, $storagePath, $user["id"]);
                try {
                    $insert->execute();
                } catch (Throwable $exception) {
                    if (is_file($destination) && !unlink($destination)) {
                        error_log("Could not remove an unregistered admin PDF upload.");
                    }
                    throw $exception;
                }

                try {
                    $parsedPdf = (new Parser())->parseFile($destination);
                    $lines = preg_split('/\R/u', $parsedPdf->getText()) ?: [];
                    $workbook = new Spreadsheet();
                    $sheet = $workbook->getActiveSheet();
                    $sheet->setTitle("Extracted text");
                    $sheet->setCellValueExplicit("A1", "Extracted PDF text", DataType::TYPE_STRING);
                    $rowNumber = 2;
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === "") {
                            continue;
                        }
                        $columns = preg_split('/\t+| {2,}/u', $line) ?: [$line];
                        foreach ($columns as $column => $value) {
                            $sheet->setCellValueExplicit([$column + 1, $rowNumber], trim($value), DataType::TYPE_STRING);
                        }
                        $rowNumber++;
                    }
                    if ($rowNumber === 2) {
                        throw new RuntimeException("No extractable text was found. Scanned PDFs need OCR before conversion.");
                    }
                    $sheet->freezePane("A2");
                    $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
                    foreach (range(1, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn())) as $column) {
                        $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
                    }
                    $downloadName = preg_replace('/[^A-Za-z0-9._-]/', '-', pathinfo($originalName, PATHINFO_FILENAME));
                    admin_send_workbook($workbook, "extracted-" . trim($downloadName, ".-") . ".xlsx");
                } catch (RuntimeException $exception) {
                    $error = $exception->getMessage();
                } catch (Throwable $exception) {
                    error_log("Admin PDF extraction failed: " . $exception->getMessage());
                    $error = "The PDF was saved, but its text could not be extracted. Scanned PDFs may require OCR.";
                }
            }
        }
    } else {
        $error = "Unknown admin action.";
    }
}

$counts = [];
foreach ([
    "users" => "SELECT COUNT(*) total FROM users",
    "products" => "SELECT COUNT(*) total FROM products",
    "transactions" => "SELECT COUNT(*) total FROM transactions",
    "reports" => "SELECT COUNT(*) total FROM reports WHERE status = 'pending'",
    "reviews" => "SELECT COUNT(*) total FROM reviews",
    "messages" => "SELECT COUNT(*) total FROM chats",
] as $key => $query) {
    $counts[$key] = (int)$conn->query($query)->fetch_assoc()["total"];
}
$reports = admin_query_rows(
    $conn,
    "SELECT r.id, r.product_id, r.reported_user_id, r.reason, r.details, r.status, r.created_at,
            reporter.full_name reporter_name, p.name product_name, p.is_blocked product_blocked, p.is_deleted product_deleted,
            reported.full_name reported_name, reported.is_blocked account_blocked, reported.role reported_role
     FROM reports r
     JOIN users reporter ON reporter.id = r.reporter_id
     LEFT JOIN products p ON p.id = r.product_id
     LEFT JOIN users reported ON reported.id = r.reported_user_id
     ORDER BY FIELD(r.status, 'pending', 'reviewed', 'actioned', 'dismissed'), r.created_at DESC
     LIMIT 50"
);
$staffNotifications = admin_query_rows($conn, "SELECT id, message, link, is_read, created_at FROM staff_notifications WHERE user_id = " . (int)$user["id"] . " ORDER BY created_at DESC, id DESC LIMIT 20");
$unreadStaffNotifications = (int)$conn->query("SELECT COUNT(*) total FROM staff_notifications WHERE user_id = " . (int)$user["id"] . " AND is_read = 0")->fetch_assoc()["total"];
$users = admin_query_rows($conn, "SELECT id, full_name, email, role, faculty, major, is_verified, is_blocked, created_at FROM users ORDER BY created_at DESC, id DESC");
$reviewers = admin_query_rows($conn, "SELECT id, full_name, email FROM users WHERE role = 'reviewer' ORDER BY full_name, id");
$products = admin_query_rows($conn, "SELECT p.id, p.name, p.category, p.price, p.is_sold, p.is_blocked, p.is_deleted, p.created_at, u.full_name seller_name FROM products p JOIN users u ON u.id = p.seller_id ORDER BY p.created_at DESC LIMIT 50");
$transactions = admin_query_rows($conn, "SELECT t.id, p.name product_name, b.full_name buyer_name, s.full_name seller_name, t.amount, t.payment_status, t.payment_type, t.created_at FROM transactions t JOIN products p ON p.id = t.product_id JOIN users b ON b.id = t.buyer_id JOIN users s ON s.id = t.seller_id ORDER BY t.created_at DESC LIMIT 50");
$monthlyTransactions = admin_query_rows(
    $conn,
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
);
$reviews = admin_query_rows($conn, "SELECT r.id, reviewer.full_name reviewer_name, reviewee.full_name reviewee_name, r.rating, r.comment, r.created_at FROM reviews r JOIN users reviewer ON reviewer.id = r.reviewer_id JOIN users reviewee ON reviewee.id = r.reviewee_id ORDER BY r.created_at DESC LIMIT 50");
$documents = admin_query_rows($conn, "SELECT d.id, d.original_name, d.created_at, u.full_name uploader_name FROM admin_documents d JOIN users u ON u.id = d.uploaded_by ORDER BY d.created_at DESC LIMIT 30");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin dashboard | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(asset_url("assets/css/app.css")) ?>">
</head>
<body class="min-h-screen bg-slate-50">
<?php include dirname(__DIR__) . "/includes/navbar.php"; ?>
<main class="mx-auto max-w-7xl space-y-8 p-4 py-8">
    <section class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-bold uppercase tracking-widest text-blue-700">Staff workspace</p>
            <h1 class="mt-2 text-3xl font-extrabold text-slate-900 md:text-4xl">Admin dashboard</h1>
            <p class="mt-2 text-slate-600">Review marketplace activity, reports, and uploaded documents.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="print-report.php" target="_blank" rel="noopener" class="rounded-xl border border-slate-300 bg-white px-5 py-3 font-semibold text-slate-800 hover:bg-slate-50">Preview printable report</a>
            <a href="?export=website" class="rounded-xl bg-blue-700 px-5 py-3 font-semibold text-white hover:bg-blue-800">Export website data (.xlsx)</a>
        </div>
    </section>

    <?php if ($error): ?><p role="alert" class="rounded-xl bg-red-50 p-4 text-red-800"><?= e($error) ?></p><?php endif; ?>
    <?php if ($notice): ?><p role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-800"><?= e($notice) ?></p><?php endif; ?>

    <section id="notifications" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="text-xl font-bold text-slate-900">Staff notifications</h2><p class="mt-1 text-sm text-slate-500"><?= number_format($unreadStaffNotifications) ?> unread</p></div>
            <?php if ($unreadStaffNotifications > 0): ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="mark_staff_notifications_read"><button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Mark all as read</button></form>
            <?php endif; ?>
        </div>
        <?php if (!$staffNotifications): ?>
            <p class="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">No staff notifications yet.</p>
        <?php else: ?>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($staffNotifications as $notification): ?>
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3 <?= (int)$notification["is_read"] ? "text-slate-500" : "font-semibold text-slate-900" ?>">
                        <a href="<?= e(app_page_url($notification["link"])) ?>" class="min-w-0 flex-1"><?= e($notification["message"]) ?><span class="ml-2 block text-xs font-normal text-slate-500"><?= e($notification["created_at"]) ?></span></a>
                        <?php if (!(int)$notification["is_read"]): ?><span class="rounded-full bg-blue-100 px-2 py-1 text-xs text-blue-800">New</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-label="Website overview">
        <?php foreach ([
            ["Users", $counts["users"], "Registered accounts"],
            ["Listings", $counts["products"], "Products listed"],
            ["Transactions", $counts["transactions"], "Marketplace orders"],
            ["Pending reports", $counts["reports"], "Require staff review"],
            ["Reviews", $counts["reviews"], "Buyer feedback"],
            ["Messages", $counts["messages"], "Message count only; content stays private"],
        ] as [$label, $count, $description]): ?>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-semibold text-slate-500"><?= e($label) ?></p>
                <p class="mt-2 text-3xl font-extrabold text-slate-900"><?= number_format((int)$count) ?></p>
                <p class="mt-1 text-sm text-slate-500"><?= e($description) ?></p>
            </article>
        <?php endforeach; ?>
    </section>

    <section id="staff-access" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
        <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(18rem,.8fr)] md:items-center">
            <div><p class="text-sm font-bold uppercase tracking-wider text-violet-700">Staff access</p><h2 class="mt-1 text-xl font-bold text-slate-900">Manage report reviewers</h2><p class="mt-2 text-sm leading-6 text-slate-500">Reviewers can only see and update the report queue. Grant access to an existing account or revoke it below.</p></div>
            <form method="post" class="flex flex-col gap-3 sm:flex-row">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_reviewer">
                <label for="reviewer_email" class="sr-only">Existing account email</label>
                <input id="reviewer_email" name="reviewer_email" type="email" required maxlength="120" placeholder="staff@university.edu" class="min-w-0 flex-1 rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-violet-500 focus:ring-4 focus:ring-violet-100">
                <button class="rounded-xl bg-violet-700 px-5 py-3 font-semibold text-white transition hover:bg-violet-800">Grant reviewer access</button>
            </form>
        </div>
        <?php if (!$reviewers): ?>
            <p class="mt-5 rounded-xl bg-slate-50 p-4 text-sm text-slate-500">There are no accounts with reviewer access.</p>
        <?php else: ?>
            <div class="mt-5 overflow-x-auto">
                <table class="w-full min-w-[600px] text-left text-sm">
                    <thead class="border-b text-xs uppercase text-slate-500"><tr><th class="p-3">Reviewer</th><th class="p-3">Email</th><th class="p-3">Access</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($reviewers as $reviewer): ?>
                        <tr>
                            <td class="p-3">#<?= (int)$reviewer["id"] ?> · <?= e($reviewer["full_name"]) ?></td>
                            <td class="p-3"><?= e($reviewer["email"]) ?></td>
                            <td class="p-3">
                                <?php if ((int)$reviewer["id"] === (int)$user["id"]): ?>
                                    <span class="text-slate-500">Your account</span>
                                <?php else: ?>
                                    <form method="post" onsubmit="return confirm('Revoke report reviewer access for this account?')">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="revoke_reviewer">
                                        <input type="hidden" name="user_id" value="<?= (int)$reviewer["id"] ?>">
                                        <button class="rounded-lg border border-red-200 px-3 py-2 font-semibold text-red-700 hover:bg-red-50">Revoke access</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section id="reports" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
        <div class="mb-5">
            <h2 class="text-2xl font-bold text-slate-900">Report review queue</h2>
            <p class="mt-1 text-sm text-slate-500">The latest 50 reports, with pending reports shown first.</p>
        </div>
        <?php if (!$reports): ?>
            <p class="rounded-xl bg-slate-50 p-4 text-slate-500">No reports have been submitted.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1150px] text-left text-sm">
                    <thead class="border-b text-xs uppercase text-slate-500"><tr><th class="p-3">Target</th><th class="p-3">Reason / details</th><th class="p-3">Reported by</th><th class="p-3">Submitted</th><th class="p-3">Status</th><th class="p-3">Moderation</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($reports as $report): ?>
                        <?php $accountReport = $report["product_id"] === null; ?>
                        <tr class="align-top">
                            <td class="p-3"><strong><?= $accountReport ? "Account" : "Listing" ?>:</strong> <?= e($accountReport ? (string)$report["reported_name"] : (string)$report["product_name"]) ?><div class="mt-1 text-xs text-slate-500"><?= $accountReport ? "User #" . (int)$report["reported_user_id"] : "Product #" . (int)$report["product_id"] ?></div></td>
                            <td class="max-w-sm p-3"><strong><?= e($reasonLabels[$report["reason"]] ?? $report["reason"]) ?></strong><p class="mt-1 whitespace-pre-line text-slate-600"><?= e($report["details"] ?: "No additional details.") ?></p></td>
                            <td class="p-3"><?= e($report["reporter_name"]) ?></td>
                            <td class="p-3 whitespace-nowrap text-slate-500"><?= e($report["created_at"]) ?></td>
                            <td class="p-3">
                                <?php if ($report["status"] === "actioned"): ?>
                                    <span class="font-semibold text-emerald-700">Actioned</span>
                                <?php else: ?>
                                    <form method="post" class="flex items-center gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="update_report">
                                        <input type="hidden" name="report_id" value="<?= (int)$report["id"] ?>">
                                        <select name="status" aria-label="Report status" class="rounded-lg border border-slate-300 px-2 py-2">
                                            <?php foreach (["pending", "reviewed", "dismissed"] as $status): ?>
                                                <option value="<?= e($status) ?>" <?= $report["status"] === $status ? "selected" : "" ?>><?= e(ucfirst($status)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="rounded-lg bg-slate-900 px-3 py-2 font-semibold text-white">Save</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td class="p-3">
                                <div class="flex flex-wrap gap-2">
                                    <?php if (!$accountReport): ?>
                                        <?php if ((int)$report["product_deleted"]): ?>
                                            <span class="text-xs font-semibold text-red-700">Post removed</span>
                                        <?php else: ?>
                                            <?php if ((int)$report["product_blocked"]): ?>
                                                <span class="self-center text-xs font-semibold text-amber-700">Post blocked</span>
                                            <?php else: ?>
                                                <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="block_reported_product"><input type="hidden" name="report_id" value="<?= (int)$report["id"] ?>"><button class="rounded-lg border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-50">Block post</button></form>
                                            <?php endif; ?>
                                            <form method="post" onsubmit="return confirm(<?= e(json_encode('Remove reported post “' . (string)$report["product_name"] . '”? Transaction and report history will be kept.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_reported_product"><input type="hidden" name="report_id" value="<?= (int)$report["id"] ?>"><button class="rounded-lg border border-red-300 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50">Remove post</button></form>
                                        <?php endif; ?>
                                    <?php elseif ($report["reported_user_id"] !== null && $report["reported_role"] !== null): ?>
                                        <?php if ((int)$report["account_blocked"]): ?>
                                            <span class="text-xs font-semibold text-red-700">Account suspended</span>
                                        <?php elseif (in_array($report["reported_role"], ["buyer", "seller"], true)): ?>
                                            <form method="post" onsubmit="return confirm(<?= e(json_encode('Suspend reported account ' . (string)$report["reported_name"] . '? They will be signed out and unable to log in.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="suspend_reported_account"><input type="hidden" name="report_id" value="<?= (int)$report["id"] ?>"><button class="rounded-lg border border-red-300 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50">Suspend account</button></form>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500">Staff account protected</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section id="documents" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
            <h2 class="text-xl font-bold text-slate-900">Upload and extract a PDF</h2>
            <p class="mt-1 text-sm text-slate-500">PDFs are stored privately for staff. Text-based PDFs can be converted to an Excel workbook; scanned PDFs require OCR.</p>
            <form method="post" enctype="multipart/form-data" class="mt-5 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_pdf">
                <label for="pdf_file" class="block text-sm font-semibold text-slate-700">PDF file (4 MB max)</label>
                <input id="pdf_file" name="pdf_file" type="file" accept="application/pdf,.pdf" required class="block w-full rounded-xl border border-slate-300 p-3 text-sm">
                <button class="rounded-xl bg-slate-900 px-5 py-3 font-semibold text-white hover:bg-slate-700">Upload PDF and download extracted Excel</button>
            </form>
            <p class="mt-4 text-xs text-slate-500">Extraction exports PDF text lines into spreadsheet rows. It does not perform OCR or infer table structure from images.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
            <h2 class="text-xl font-bold text-slate-900">Stored staff documents</h2>
            <?php if (!$documents): ?>
                <p class="mt-3 text-sm text-slate-500">No PDFs have been uploaded.</p>
            <?php else: ?>
                <ul class="mt-3 divide-y divide-slate-100">
                    <?php foreach ($documents as $document): ?>
                        <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                            <div class="min-w-0"><p class="truncate font-semibold text-slate-800"><?= e($document["original_name"]) ?></p><p class="text-xs text-slate-500">Uploaded by <?= e($document["uploader_name"]) ?> · <?= e($document["created_at"]) ?></p></div>
                            <a class="text-sm font-semibold text-blue-700 hover:text-blue-900" href="?download_pdf=<?= (int)$document["id"] ?>">Download PDF</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <section id="users" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
        <div class="mb-5">
            <h2 class="text-2xl font-bold text-slate-900">User account management</h2>
            <p class="mt-1 text-sm text-slate-500">Create accounts, edit account details and access roles, or delete accounts with no related website history. Administrator accounts are protected.</p>
        </div>
        <form method="post" class="mb-8 grid gap-4 rounded-2xl bg-slate-50 p-4 md:grid-cols-2 lg:grid-cols-4">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_user">
            <div><label for="new_user_name" class="mb-1 block text-sm font-semibold text-slate-700">Full name</label><input id="new_user_name" name="full_name" required minlength="2" maxlength="120" class="w-full rounded-xl border border-slate-300 px-3 py-2"></div>
            <div><label for="new_user_email" class="mb-1 block text-sm font-semibold text-slate-700">Email</label><input id="new_user_email" name="email" type="email" required maxlength="120" placeholder="user@gmail.com" class="w-full rounded-xl border border-slate-300 px-3 py-2"></div>
            <div><label for="new_user_password" class="mb-1 block text-sm font-semibold text-slate-700">Initial password</label><input id="new_user_password" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" class="w-full rounded-xl border border-slate-300 px-3 py-2"><p class="mt-1 text-xs text-slate-500">8–72 characters; stored as a password hash.</p></div>
            <div><label for="new_user_role" class="mb-1 block text-sm font-semibold text-slate-700">Role</label><select id="new_user_role" name="role" class="w-full rounded-xl border border-slate-300 px-3 py-2"><option value="buyer">Buyer</option><option value="seller">Seller</option><option value="reviewer">Report reviewer</option></select></div>
            <div><label for="new_user_faculty" class="mb-1 block text-sm font-semibold text-slate-700">Faculty</label><select id="new_user_faculty" name="faculty" class="w-full rounded-xl border border-slate-300 px-3 py-2"><option value="">Select a faculty (optional)</option><?php foreach ($facultyMajors as $facultyOption => $majorOptions): ?><option value="<?= e($facultyOption) ?>"><?= e($facultyOption) ?></option><?php endforeach; ?></select></div>
            <div><label for="new_user_major" class="mb-1 block text-sm font-semibold text-slate-700">Major</label><select id="new_user_major" name="major" class="w-full rounded-xl border border-slate-300 px-3 py-2" disabled><option value="">Select a major (optional)</option></select></div>
            <label class="flex items-center gap-2 self-end rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium"><input type="checkbox" name="is_verified" value="1" class="rounded border-slate-300">Mark as verified</label>
            <div class="self-end"><button class="w-full rounded-xl bg-blue-700 px-4 py-2.5 font-semibold text-white hover:bg-blue-800">Create account</button></div>
        </form>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1350px] text-left text-sm">
                <thead class="border-b text-xs uppercase text-slate-500"><tr><th class="p-3">ID</th><th class="p-3">Name / email</th><th class="p-3">Role</th><th class="p-3">Faculty</th><th class="p-3">Major</th><th class="p-3">Verified</th><th class="p-3">Suspended</th><th class="p-3">Joined / actions</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($users as $row): ?>
                    <?php $protectedAccount = $row["role"] === "admin" || (int)$row["id"] === (int)$user["id"]; ?>
                    <tr class="align-top">
                        <td class="p-3">#<?= (int)$row["id"] ?><?= $protectedAccount ? '<span class="block text-xs text-slate-500">Protected administrator</span>' : "" ?></td>
                        <td class="p-3">
                            <?php if ($protectedAccount): ?>
                                <strong><?= e($row["full_name"]) ?></strong><div class="mt-1 text-xs text-slate-500"><?= e($row["email"]) ?></div>
                            <?php else: ?>
                                <form id="user-edit-<?= (int)$row["id"] ?>" method="post" class="space-y-2">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="user_id" value="<?= (int)$row["id"] ?>">
                                    <input name="full_name" aria-label="Full name" required minlength="2" maxlength="120" value="<?= e($row["full_name"]) ?>" class="w-full rounded-lg border border-slate-300 px-2 py-1.5">
                                    <input name="email" aria-label="Email" type="email" required maxlength="120" value="<?= e($row["email"]) ?>" class="w-full rounded-lg border border-slate-300 px-2 py-1.5">
                                </form>
                            <?php endif; ?>
                        </td>
                        <td class="p-3">
                            <?php if ($protectedAccount): ?>
                                <span class="font-semibold"><?= e($row["role"]) ?></span>
                            <?php else: ?>
                                <select form="user-edit-<?= (int)$row["id"] ?>" name="role" aria-label="Account role" class="rounded-lg border border-slate-300 px-2 py-1.5">
                                    <?php foreach (["buyer" => "Buyer", "seller" => "Seller", "reviewer" => "Reviewer"] as $roleValue => $roleLabel): ?>
                                        <option value="<?= e($roleValue) ?>" <?= $row["role"] === $roleValue ? "selected" : "" ?>><?= e($roleLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        <td class="p-3"><?php if ($protectedAccount): ?><?= e($row["faculty"] ?? "") ?><?php else: ?>
                            <?php $facultySelectId = "user-faculty-" . (int)$row["id"]; $majorSelectId = "user-major-" . (int)$row["id"]; $rowFaculty = (string)($row["faculty"] ?? ""); $rowMajor = (string)($row["major"] ?? ""); ?>
                            <select id="<?= e($facultySelectId) ?>" form="user-edit-<?= (int)$row["id"] ?>" name="faculty" aria-label="Faculty" data-major-select="<?= e($majorSelectId) ?>" data-original-faculty="<?= e($rowFaculty) ?>" class="w-56 rounded-lg border border-slate-300 px-2 py-1.5">
                                <option value="">Select a faculty (optional)</option>
                                <?php foreach ($facultyMajors as $facultyOption => $majorOptions): ?><option value="<?= e($facultyOption) ?>" <?= $rowFaculty === $facultyOption ? "selected" : "" ?>><?= e($facultyOption) ?></option><?php endforeach; ?>
                                <?php if ($rowFaculty !== "" && !isset($facultyMajors[$rowFaculty])): ?><option value="<?= e($rowFaculty) ?>" selected><?= e($rowFaculty) ?> (existing value)</option><?php endif; ?>
                            </select>
                        <?php endif; ?></td>
                        <td class="p-3"><?php if ($protectedAccount): ?><?= e($row["major"] ?? "") ?><?php else: ?>
                            <select id="<?= e($majorSelectId) ?>" form="user-edit-<?= (int)$row["id"] ?>" name="major" aria-label="Major" data-original-major="<?= e($rowMajor) ?>" class="w-48 rounded-lg border border-slate-300 px-2 py-1.5">
                                <option value="">Select a major (optional)</option>
                                <?php foreach ($facultyMajors[$rowFaculty] ?? [] as $majorOption): ?><option value="<?= e($majorOption) ?>" <?= $rowMajor === $majorOption ? "selected" : "" ?>><?= e($majorOption) ?></option><?php endforeach; ?>
                                <?php if ($rowMajor !== "" && !in_array($rowMajor, $facultyMajors[$rowFaculty] ?? [], true)): ?><option value="<?= e($rowMajor) ?>" selected><?= e($rowMajor) ?> (existing value)</option><?php endif; ?>
                            </select>
                        <?php endif; ?></td>
                        <td class="p-3">
                            <?php if ($protectedAccount): ?>
                                <?= (int)$row["is_verified"] ? "Yes" : "No" ?>
                            <?php else: ?>
                                <label class="flex items-center gap-2"><input form="user-edit-<?= (int)$row["id"] ?>" type="checkbox" name="is_verified" value="1" <?= (int)$row["is_verified"] ? "checked" : "" ?> class="rounded border-slate-300"><span class="sr-only">Verified</span><?= (int)$row["is_verified"] ? "Yes" : "No" ?></label>
                            <?php endif; ?>
                        </td>
                        <td class="p-3">
                            <?php if ($protectedAccount): ?>
                                <?= (int)$row["is_blocked"] ? "Yes" : "No" ?>
                            <?php else: ?>
                                <label class="flex items-center gap-2"><input form="user-edit-<?= (int)$row["id"] ?>" type="checkbox" name="is_blocked" value="1" <?= (int)$row["is_blocked"] ? "checked" : "" ?> class="rounded border-slate-300"><span class="sr-only">Suspended</span><?= (int)$row["is_blocked"] ? "Yes" : "No" ?></label>
                            <?php endif; ?>
                        </td>
                        <td class="p-3">
                            <div class="mb-2 whitespace-nowrap text-xs text-slate-500"><?= e($row["created_at"]) ?></div>
                            <?php if (!$protectedAccount): ?>
                                <div class="flex flex-wrap gap-2">
                                    <button form="user-edit-<?= (int)$row["id"] ?>" class="rounded-lg bg-slate-900 px-3 py-2 font-semibold text-white hover:bg-slate-700">Save</button>
                                    <form method="post" onsubmit="return confirm(<?= e(json_encode('Delete account for ' . $row["full_name"] . '? This cannot be undone.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= (int)$row["id"] ?>">
                                        <button class="rounded-lg border border-red-200 px-3 py-2 font-semibold text-red-700 hover:bg-red-50">Delete</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section id="products" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
        <h2 class="mb-4 text-2xl font-bold text-slate-900">Product listings <span class="text-sm font-normal text-slate-500">(latest 50)</span></h2>
        <div class="overflow-x-auto"><table class="w-full min-w-[850px] text-left text-sm">
            <thead class="border-b text-xs uppercase text-slate-500"><tr><th class="p-3">ID / product</th><th class="p-3">Seller</th><th class="p-3">Category</th><th class="p-3">Price</th><th class="p-3">Status</th><th class="p-3">Moderation</th><th class="p-3">Created</th></tr></thead>
            <tbody class="divide-y divide-slate-100"><?php foreach ($products as $row): ?><tr><td class="p-3">#<?= (int)$row["id"] ?> · <?= e($row["name"]) ?></td><td class="p-3"><?= e($row["seller_name"]) ?></td><td class="p-3"><?= e($row["category"]) ?></td><td class="p-3">Rp <?= number_format((float)$row["price"], 0, ",", ".") ?></td><td class="p-3"><?= (int)$row["is_sold"] ? "Sold" : "Available" ?></td><td class="p-3"><?= (int)$row["is_deleted"] ? "Removed" : ((int)$row["is_blocked"] ? "Blocked" : "—") ?></td><td class="p-3 whitespace-nowrap"><?= e($row["created_at"]) ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
    </section>

    <section id="transactions" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
        <div class="mb-5">
            <h2 class="text-2xl font-bold text-slate-900">Transactions by month</h2>
            <p class="mt-1 text-sm text-slate-500">Monthly totals use the transaction creation date. Completed amounts are shown separately from total order amounts.</p>
        </div>
        <?php if (!$monthlyTransactions): ?>
            <p class="rounded-xl bg-slate-50 p-4 text-slate-500">No transaction data is available.</p>
        <?php else: ?>
            <div class="mb-8 overflow-x-auto">
                <table class="w-full min-w-[850px] text-left text-sm">
                    <thead class="border-b text-xs uppercase text-slate-500"><tr><th class="p-3">Month</th><th class="p-3">Transactions</th><th class="p-3">Total order amount</th><th class="p-3">Completed</th><th class="p-3">Completed amount</th><th class="p-3">Pending</th><th class="p-3">Paid</th><th class="p-3">Cancelled</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($monthlyTransactions as $row): ?>
                        <tr>
                            <td class="p-3 font-semibold"><?= e($row["transaction_month"]) ?></td>
                            <td class="p-3"><?= number_format((int)$row["transaction_count"]) ?></td>
                            <td class="p-3">Rp <?= number_format((float)$row["total_amount"], 0, ",", ".") ?></td>
                            <td class="p-3"><?= number_format((int)$row["completed_count"]) ?></td>
                            <td class="p-3">Rp <?= number_format((float)$row["completed_amount"], 0, ",", ".") ?></td>
                            <td class="p-3"><?= number_format((int)$row["pending_count"]) ?></td>
                            <td class="p-3"><?= number_format((int)$row["paid_count"]) ?></td>
                            <td class="p-3"><?= number_format((int)$row["cancelled_count"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <h3 class="mb-4 text-xl font-bold text-slate-900">Recent transactions <span class="text-sm font-normal text-slate-500">(latest 50)</span></h3>
        <div class="overflow-x-auto"><table class="w-full min-w-[800px] text-left text-sm">
            <thead class="border-b text-xs uppercase text-slate-500"><tr><th class="p-3">ID / product</th><th class="p-3">Buyer</th><th class="p-3">Seller</th><th class="p-3">Amount</th><th class="p-3">Payment</th><th class="p-3">Status</th><th class="p-3">Created</th></tr></thead>
            <tbody class="divide-y divide-slate-100"><?php foreach ($transactions as $row): ?><tr><td class="p-3">#<?= (int)$row["id"] ?> · <?= e($row["product_name"]) ?></td><td class="p-3"><?= e($row["buyer_name"]) ?></td><td class="p-3"><?= e($row["seller_name"]) ?></td><td class="p-3">Rp <?= number_format((float)$row["amount"], 0, ",", ".") ?></td><td class="p-3"><?= e($row["payment_type"]) ?></td><td class="p-3"><?= e($row["payment_status"]) ?></td><td class="p-3 whitespace-nowrap"><?= e($row["created_at"]) ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
    </section>

    <section id="reviews" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
        <h2 class="mb-4 text-2xl font-bold text-slate-900">Reviews <span class="text-sm font-normal text-slate-500">(latest 50)</span></h2>
        <div class="overflow-x-auto"><table class="w-full min-w-[700px] text-left text-sm">
            <thead class="border-b text-xs uppercase text-slate-500"><tr><th class="p-3">ID</th><th class="p-3">Reviewer</th><th class="p-3">Seller</th><th class="p-3">Rating</th><th class="p-3">Comment</th><th class="p-3">Created</th></tr></thead>
            <tbody class="divide-y divide-slate-100"><?php foreach ($reviews as $row): ?><tr><td class="p-3">#<?= (int)$row["id"] ?></td><td class="p-3"><?= e($row["reviewer_name"]) ?></td><td class="p-3"><?= e($row["reviewee_name"]) ?></td><td class="p-3"><?= (int)$row["rating"] ?>/5</td><td class="max-w-md p-3"><?= e($row["comment"] ?: "—") ?></td><td class="p-3 whitespace-nowrap"><?= e($row["created_at"]) ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
    </section>
</main>
<script>
const facultyMajors = <?= json_encode($facultyMajors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function connectFacultyMajor(facultySelect, majorSelect) {
    facultySelect.addEventListener("change", () => {
        majorSelect.replaceChildren(new Option("Select a major", ""));
        const majors = facultyMajors[facultySelect.value] || [];
        for (const major of majors) {
            majorSelect.add(new Option(major, major));
        }
        const originalMajor = majorSelect.dataset.originalMajor || "";
        if (
            facultySelect.value === facultySelect.dataset.originalFaculty
            && originalMajor !== ""
            && !majors.includes(originalMajor)
        ) {
            majorSelect.add(new Option(originalMajor + " (existing value)", originalMajor, true, true));
        }
        majorSelect.disabled = facultySelect.value === "";
        majorSelect.required = facultySelect.value !== "";
    });

    majorSelect.disabled = facultySelect.value === "" && majorSelect.value === "";
    majorSelect.required = facultySelect.value !== "";
}

connectFacultyMajor(
    document.getElementById("new_user_faculty"),
    document.getElementById("new_user_major")
);

for (const facultySelect of document.querySelectorAll("[data-major-select]")) {
    connectFacultyMajor(
        facultySelect,
        document.getElementById(facultySelect.dataset.majorSelect)
    );
}
</script>
<?php include dirname(__DIR__) . "/includes/footer.php"; ?>
</body>
</html>