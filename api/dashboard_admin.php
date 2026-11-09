<?php
require_once "includes/config.php";
require_login();
$user = current_user($conn);
if (!in_array($user["role"], ["admin", "staff"], true)) {
    redirect_dashboard($user["role"]);
}
$isAdmin = $user["role"] === "admin";
$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $action = $_POST["action"] ?? "";

    if ($action === "set_role" && $isAdmin) {
        $targetId = filter_var($_POST["user_id"] ?? null, FILTER_VALIDATE_INT);
        $newRole = $_POST["role"] ?? "";
        if ($targetId === false || $targetId < 1 || $targetId === (int)$user["id"] || !in_array($newRole, ["buyer", "seller", "staff"], true)) {
            $error = "Choose a valid account and role. Admin accounts cannot be changed here.";
        } else {
            $targetQuery = $conn->prepare("SELECT role, full_name FROM users WHERE id = ?");
            $targetQuery->bind_param("i", $targetId);
            $targetQuery->execute();
            $target = $targetQuery->get_result()->fetch_assoc();
            if (!$target || $target["role"] === "admin") {
                $error = "That account cannot be changed.";
            } else {
                $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->bind_param("si", $newRole, $targetId);
                $stmt->execute();
                record_admin_action($conn, (int)$user["id"], "role_changed", "user", (int)$targetId, "Changed {$target['full_name']} from {$target['role']} to $newRole");
                $message = "Account role updated.";
            }
        }
    } elseif ($action === "transaction_status" && $isAdmin) {
        $transactionId = filter_var($_POST["transaction_id"] ?? null, FILTER_VALIDATE_INT);
        $status = $_POST["status"] ?? "";
        $reason = trim($_POST["reason"] ?? "");
        if ($transactionId === false || $transactionId < 1 || !in_array($status, ["Pending", "Paid", "Completed", "Cancelled"], true) || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $error = "Choose a valid status and provide a reason (10 to 500 characters).";
        } else {
            $check = $conn->prepare("SELECT product_id, buyer_id, seller_id, payment_status FROM transactions WHERE id = ?");
            $check->bind_param("i", $transactionId);
            $check->execute();
            $transaction = $check->get_result()->fetch_assoc();
            if (!$transaction) {
                $error = "Transaction not found.";
            } else {
                $conn->begin_transaction();
                try {
                    $update = $conn->prepare("UPDATE transactions SET payment_status = ?, buyer_completed = IF(? = 'Completed', 1, 0), seller_completed = IF(? = 'Completed', 1, 0) WHERE id = ?");
                    $update->bind_param("sssi", $status, $status, $status, $transactionId);
                    $update->execute();
                    if ($status === "Cancelled") {
                        $release = $conn->prepare("UPDATE products p SET p.is_sold = 0 WHERE p.id = ? AND NOT EXISTS (SELECT 1 FROM transactions active WHERE active.product_id = p.id AND active.payment_status <> 'Cancelled')");
                        $release->bind_param("i", $transaction["product_id"]);
                        $release->execute();
                    } else {
                        $reserve = $conn->prepare("UPDATE products SET is_sold = 1 WHERE id = ?");
                        $reserve->bind_param("i", $transaction["product_id"]);
                        $reserve->execute();
                    }
                    record_admin_action($conn, (int)$user["id"], "transaction_status_changed", "transaction", (int)$transactionId, "Changed {$transaction['payment_status']} to $status. Reason: $reason");
                    $notification = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                    $notificationMessage = mb_substr("An administrator updated transaction #$transactionId to $status. Reason: $reason", 0, 255);
                    $recipientId = (int)$transaction["buyer_id"];
                    $notificationLink = "dashboard_buyer.php";
                    $notification->bind_param("iss", $recipientId, $notificationMessage, $notificationLink);
                    $notification->execute();
                    $notification->close();
                    $notification = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                    $recipientId = (int)$transaction["seller_id"];
                    $notificationLink = "dashboard_seller.php";
                    $notification->bind_param("iss", $recipientId, $notificationMessage, $notificationLink);
                    $notification->execute();
                    $conn->commit();
                    $message = "Transaction status updated and both parties notified.";
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
            }
        }
    } elseif ($action === "transaction_delete" && $isAdmin) {
        $transactionId = filter_var($_POST["transaction_id"] ?? null, FILTER_VALIDATE_INT);
        $reason = trim($_POST["reason"] ?? "");
        if ($transactionId === false || $transactionId < 1 || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $error = "Provide a valid transaction and a reason (10 to 500 characters) to delete it.";
        } else {
            $check = $conn->prepare("SELECT t.product_id, t.buyer_id, t.seller_id, t.payment_status, t.amount, p.name product_name FROM transactions t JOIN products p ON p.id = t.product_id WHERE t.id = ?");
            $check->bind_param("i", $transactionId);
            $check->execute();
            $transaction = $check->get_result()->fetch_assoc();
            if (!$transaction) {
                $error = "Transaction not found.";
            } else {
                $conn->begin_transaction();
                try {
                    // Notify both parties and write the audit entry first; the transaction row itself is removed last.
                    $notificationMessage = mb_substr("Order #$transactionId (" . $transaction["product_name"] . ") was removed by an administrator. Reason: $reason", 0, 255);
                    $notification = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                    foreach ([[(int)$transaction["buyer_id"], "dashboard_buyer.php"], [(int)$transaction["seller_id"], "dashboard_seller.php"]] as [$recipientId, $notificationLink]) {
                        $notification->bind_param("iss", $recipientId, $notificationMessage, $notificationLink);
                        $notification->execute();
                    }
                    record_admin_action($conn, (int)$user["id"], "transaction_deleted", "transaction", (int)$transactionId, "Deleted order #$transactionId (status {$transaction['payment_status']}, amount " . number_format((float)$transaction["amount"], 0, ",", ".") . "). Reason: $reason");
                    $delete = $conn->prepare("DELETE FROM transactions WHERE id = ?");
                    $delete->bind_param("i", $transactionId);
                    $delete->execute();
                    // Put the product back on the marketplace when no other live order still reserves it.
                    $release = $conn->prepare("UPDATE products p SET p.is_sold = 0 WHERE p.id = ? AND NOT EXISTS (SELECT 1 FROM transactions active WHERE active.product_id = p.id AND active.payment_status <> 'Cancelled')");
                    $release->bind_param("i", $transaction["product_id"]);
                    $release->execute();
                    $conn->commit();
                    $message = "Transaction #$transactionId deleted. Both parties were notified and the action was logged.";
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
            }
        }
    } elseif ($action === "report_action") {
        $reportId = filter_var($_POST["report_id"] ?? null, FILTER_VALIDATE_INT);
        $status = $_POST["status"] ?? "";
        $resolution = trim($_POST["resolution"] ?? "");
        if ($reportId === false || $reportId < 1 || !in_array($status, ["new", "in_review", "resolved", "rejected"], true) || mb_strlen($resolution) < 5 || mb_strlen($resolution) > 2000) {
            $error = "Choose a valid report status and add a note (5 to 2,000 characters).";
        } else {
            $reportQuery = $conn->prepare("SELECT r.reporter_id, r.target_type, r.target_id, u.role reporter_role FROM moderation_reports r JOIN users u ON u.id = r.reporter_id WHERE r.id = ?");
            $reportQuery->bind_param("i", $reportId);
            $reportQuery->execute();
            $report = $reportQuery->get_result()->fetch_assoc();
            if (!$report) {
                $error = "Report not found.";
            } else {
                $stmt = $conn->prepare("UPDATE moderation_reports SET status = ?, resolution = ?, assigned_to = ? WHERE id = ?");
                $actorId = (int)$user["id"];
                $stmt->bind_param("ssii", $status, $resolution, $actorId, $reportId);
                $stmt->execute();
                record_admin_action($conn, $actorId, "report_reviewed", "report", (int)$reportId, "Status: $status. $resolution");
                $notification = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                $notificationMessage = "Your report #$reportId was updated to $status.";
                $notificationLink = $report["reporter_role"] === "seller" ? "dashboard_seller.php" : "dashboard_buyer.php";
                $notification->bind_param("iss", $report["reporter_id"], $notificationMessage, $notificationLink);
                $notification->execute();
                $message = "Report updated and the reporter notified.";
            }
        }
    } elseif ($action === "moderate_user") {
        $targetId = filter_var($_POST["user_id"] ?? null, FILTER_VALIDATE_INT);
        $active = filter_var($_POST["is_active"] ?? null, FILTER_VALIDATE_INT);
        $reason = trim($_POST["reason"] ?? "");
        if ($targetId === false || $targetId < 1 || !in_array($active, [0, 1], true) || (!$isAdmin && $active !== 0) || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $error = "Invalid account moderation action or reason.";
        } else {
            $targetQuery = $conn->prepare("SELECT role, full_name, is_active FROM users WHERE id = ?");
            $targetQuery->bind_param("i", $targetId);
            $targetQuery->execute();
            $target = $targetQuery->get_result()->fetch_assoc();
            if (!$target || $target["role"] === "admin" || $targetId === (int)$user["id"]) {
                $error = "Admin accounts and your own account cannot be moderated here.";
            } elseif (!$isAdmin) {
                $reportCheck = $conn->prepare("SELECT id FROM moderation_reports WHERE target_type = 'user' AND target_id = ? AND status IN ('new', 'in_review') AND (assigned_to IS NULL OR assigned_to = ?) LIMIT 1");
                $reportCheck->bind_param("ii", $targetId, $user["id"]);
                $reportCheck->execute();
                if (!$reportCheck->get_result()->fetch_assoc()) {
                    $error = "Staff can only suspend an account linked to an open report assigned to them.";
                }
            }
            if ($error === "") {
                if ($target && $target["role"] !== "admin" && $targetId !== (int)$user["id"]) {
                    $stmt = $conn->prepare("UPDATE users SET is_active = ?, suspension_reason = IF(? = 1, '', ?) WHERE id = ?");
                    $stmt->bind_param("iisi", $active, $active, $reason, $targetId);
                    $stmt->execute();
                    record_admin_action($conn, (int)$user["id"], $active ? "account_reactivated" : "account_suspended", "user", (int)$targetId, $reason);
                    if (!$active) {
                        $notification = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, 'profile.php')");
                        $notificationMessage = mb_substr("Your account has been temporarily suspended. Reason: $reason", 0, 255);
                        $notification->bind_param("is", $targetId, $notificationMessage);
                        $notification->execute();
                    }
                    $message = $active ? "Account reactivated." : "Account suspended.";
                }
            }
        }
    } elseif ($action === "moderate_product") {
        $productId = filter_var($_POST["product_id"] ?? null, FILTER_VALIDATE_INT);
        $hidden = filter_var($_POST["is_hidden"] ?? null, FILTER_VALIDATE_INT);
        $reason = trim($_POST["reason"] ?? "");
        if ($productId === false || $productId < 1 || !in_array($hidden, [0, 1], true) || (!$isAdmin && $hidden !== 1) || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $error = "Invalid product moderation action or reason.";
        } else {
            $targetQuery = $conn->prepare("SELECT name, seller_id FROM products WHERE id = ?");
            $targetQuery->bind_param("i", $productId);
            $targetQuery->execute();
            $target = $targetQuery->get_result()->fetch_assoc();
            if (!$target) {
                $error = "Product not found.";
            } elseif (!$isAdmin) {
                $reportCheck = $conn->prepare("SELECT id FROM moderation_reports WHERE target_type = 'product' AND target_id = ? AND status IN ('new', 'in_review') AND (assigned_to IS NULL OR assigned_to = ?) LIMIT 1");
                $reportCheck->bind_param("ii", $productId, $user["id"]);
                $reportCheck->execute();
                if (!$reportCheck->get_result()->fetch_assoc()) {
                    $error = "Staff can only hide a product linked to an open report assigned to them.";
                }
            }
            if ($error === "" && $target) {
                $stmt = $conn->prepare("UPDATE products SET is_hidden = ?, moderation_reason = ? WHERE id = ?");
                $stmt->bind_param("isi", $hidden, $reason, $productId);
                $stmt->execute();
                record_admin_action($conn, (int)$user["id"], $hidden ? "product_hidden" : "product_restored", "product", (int)$productId, $reason);
                if ($hidden) {
                    $notification = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, 'dashboard_seller.php')");
                    $notificationMessage = mb_substr("Your listing \"" . $target["name"] . "\" was hidden during a report review. Reason: $reason", 0, 255);
                    $notification->bind_param("is", $target["seller_id"], $notificationMessage);
                    $notification->execute();
                }
                $message = $hidden ? "Product hidden from the marketplace." : "Product restored to the marketplace.";
            }
        }
    } else {
        http_response_code(403);
        exit("You are not allowed to perform this action.");
    }

    if ($error === "") {
        $_SESSION["admin_flash"] = $message;
        header("Location: dashboard_admin.php");
        exit;
    }
}

$message = $_SESSION["admin_flash"] ?? $message;
unset($_SESSION["admin_flash"]);
$statusFilter = $_GET["status"] ?? "";
$search = trim($_GET["q"] ?? "");
$validStatuses = ["new", "in_review", "resolved", "rejected"];
$reportSql = "SELECT r.*, reporter.full_name reporter_name, assignee.full_name assignee_name,
    transaction_target.buyer_payment_proof, transaction_target.seller_cash_receipt,
    CASE r.target_type
        WHEN 'user' THEN (SELECT full_name FROM users WHERE id = r.target_id)
        WHEN 'product' THEN (SELECT name FROM products WHERE id = r.target_id)
        WHEN 'transaction' THEN CONCAT('Transaction #', r.target_id)
    END target_name
    FROM moderation_reports r
    JOIN users reporter ON reporter.id = r.reporter_id
    LEFT JOIN users assignee ON assignee.id = r.assigned_to
    LEFT JOIN transactions transaction_target ON r.target_type = 'transaction' AND transaction_target.id = r.target_id";
$reportConditions = [];
$reportTypes = "";
$reportParams = [];
if (in_array($statusFilter, $validStatuses, true)) {
    $reportConditions[] = "r.status = ?";
    $reportTypes .= "s";
    $reportParams[] = $statusFilter;
}
if ($search !== "") {
    $reportConditions[] = "(r.description LIKE ? OR r.reason LIKE ? OR reporter.full_name LIKE ? OR CAST(r.target_id AS CHAR) = ?)";
    $like = "%$search%";
    array_push($reportParams, $like, $like, $like, $search);
    $reportTypes .= "ssss";
}
if ($reportConditions) {
    $reportSql .= " WHERE " . implode(" AND ", $reportConditions);
}
$reportCountSql = "SELECT COUNT(*) total FROM moderation_reports r JOIN users reporter ON reporter.id = r.reporter_id";
if ($reportConditions) {
    $reportCountSql .= " WHERE " . implode(" AND ", $reportConditions);
}
$reportCountQuery = $conn->prepare($reportCountSql);
if ($reportTypes !== "") {
    $reportCountQuery->bind_param($reportTypes, ...$reportParams);
}
$reportCountQuery->execute();
$totalFilteredReports = (int)$reportCountQuery->get_result()->fetch_assoc()["total"];
$reportPage = max(1, (int)($_GET["report_page"] ?? 1));
$reportPageCount = max(1, (int)ceil($totalFilteredReports / 50));
$reportPage = min($reportPage, $reportPageCount);
$reportOffset = ($reportPage - 1) * 50;
$reportSql .= " ORDER BY FIELD(r.status, 'new', 'in_review', 'resolved', 'rejected'), r.created_at DESC LIMIT 50 OFFSET ?";
$reportsQuery = $conn->prepare($reportSql);
$reportParams[] = $reportOffset;
$reportTypes .= "i";
if ($reportTypes !== "") {
    $reportsQuery->bind_param($reportTypes, ...$reportParams);
}
$reportsQuery->execute();
$reports = $reportsQuery->get_result()->fetch_all(MYSQLI_ASSOC);

$pendingReports = (int)$conn->query("SELECT COUNT(*) AS total FROM moderation_reports WHERE status IN ('new', 'in_review')")->fetch_assoc()["total"];
$totalTransactions = (int)$conn->query("SELECT COUNT(*) AS total FROM transactions")->fetch_assoc()["total"];
$activeTransactions = (int)$conn->query("SELECT COUNT(*) AS total FROM transactions WHERE payment_status IN ('Pending', 'Paid')")->fetch_assoc()["total"];
$completedTransactions = (int)$conn->query("SELECT COUNT(*) AS total FROM transactions WHERE payment_status = 'Completed'")->fetch_assoc()["total"];

$transactionConditions = [];
$transactionTypes = "";
$transactionParams = [];
$transactionStatusFilter = $_GET["transaction_status"] ?? "";
if (in_array($transactionStatusFilter, ["Pending", "Paid", "Completed", "Cancelled"], true)) {
    $transactionConditions[] = "t.payment_status = ?";
    $transactionTypes .= "s";
    $transactionParams[] = $transactionStatusFilter;
}
if ($search !== "") {
    $transactionConditions[] = "(p.name LIKE ? OR buyer.full_name LIKE ? OR seller.full_name LIKE ? OR CAST(t.id AS CHAR) = ?)";
    $like = "%$search%";
    array_push($transactionParams, $like, $like, $like, $search);
    $transactionTypes .= "ssss";
}
$transactionSql = "SELECT t.id, t.amount, t.payment_status, t.payment_type, t.payment_method, t.buyer_payment_proof, t.seller_cash_receipt, t.created_at, t.meetup_location, p.name product_name, buyer.full_name buyer_name, seller.full_name seller_name
    FROM transactions t JOIN products p ON p.id = t.product_id JOIN users buyer ON buyer.id = t.buyer_id JOIN users seller ON seller.id = t.seller_id";
if ($transactionConditions) {
    $transactionSql .= " WHERE " . implode(" AND ", $transactionConditions);
}
$transactionCountSql = "SELECT COUNT(*) total FROM transactions t JOIN products p ON p.id = t.product_id JOIN users buyer ON buyer.id = t.buyer_id JOIN users seller ON seller.id = t.seller_id";
if ($transactionConditions) {
    $transactionCountSql .= " WHERE " . implode(" AND ", $transactionConditions);
}
$transactionCountQuery = $conn->prepare($transactionCountSql);
if ($transactionTypes !== "") {
    $transactionCountQuery->bind_param($transactionTypes, ...$transactionParams);
}
$transactionCountQuery->execute();
$totalFilteredTransactions = (int)$transactionCountQuery->get_result()->fetch_assoc()["total"];
$transactionPage = max(1, (int)($_GET["transaction_page"] ?? 1));
$transactionPageCount = max(1, (int)ceil($totalFilteredTransactions / 50));
$transactionPage = min($transactionPage, $transactionPageCount);
$transactionOffset = ($transactionPage - 1) * 50;
$transactionSql .= " ORDER BY t.created_at DESC LIMIT 50 OFFSET ?";
$transactions = $conn->prepare($transactionSql);
$transactionParams[] = $transactionOffset;
$transactionTypes .= "i";
if ($transactionTypes !== "") {
    $transactions->bind_param($transactionTypes, ...$transactionParams);
}
$transactions->execute();
$transactionRows = $transactions->get_result()->fetch_all(MYSQLI_ASSOC);

$users = [];
$moderatedProducts = [];
$auditRows = [];
if ($isAdmin) {
    $userSearch = $conn->prepare("SELECT id, full_name, email, role, is_active, created_at FROM users WHERE role <> 'admin' AND (? = '' OR full_name LIKE CONCAT('%', ?, '%') OR email LIKE CONCAT('%', ?, '%')) ORDER BY role, full_name LIMIT 100");
    $userSearch->bind_param("sss", $search, $search, $search);
    $userSearch->execute();
    $users = $userSearch->get_result()->fetch_all(MYSQLI_ASSOC);
    $moderatedProducts = $conn->query("SELECT p.id, p.name, p.is_hidden, p.moderation_reason, u.full_name seller_name FROM products p JOIN users u ON u.id = p.seller_id ORDER BY p.is_hidden DESC, p.created_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
    $auditRows = $conn->query("SELECT l.*, u.full_name actor_name FROM admin_audit_logs l JOIN users u ON u.id = l.actor_id ORDER BY l.created_at DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isAdmin ? "Admin" : "Staff" ?> Dashboard | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-50">
<?php include "includes/navbar.php"; ?>
<main class="mx-auto max-w-7xl space-y-6 p-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div><p class="text-sm font-bold uppercase tracking-widest text-orange-600">LOOPLY operations</p><h1 class="mt-1 text-3xl font-bold"><?= $isAdmin ? "Admin" : "Staff" ?> Dashboard</h1></div>
        <a href="index.php" class="rounded-xl border bg-white px-4 py-2 text-sm font-semibold">Marketplace</a>
    </header>
    <?php if ($message !== ""): ?><p role="status" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800"><?= e($message) ?></p><?php endif; ?>
    <?php if ($error !== ""): ?><p role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800"><?= e($error) ?></p><?php endif; ?>
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <article class="rounded-2xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Open reports</p><p class="mt-1 text-3xl font-bold"><?= $pendingReports ?></p></article>
        <?php if ($isAdmin): ?>
            <article class="rounded-2xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">All transactions</p><p class="mt-1 text-3xl font-bold"><?= $totalTransactions ?></p><a href="#admin-transactions" class="mt-2 inline-block text-sm font-semibold text-orange-700 hover:underline">View transaction list &darr;</a></article>
            <article class="rounded-2xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Pending / paid</p><p class="mt-1 text-3xl font-bold"><?= $activeTransactions ?></p></article>
            <article class="rounded-2xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Completed</p><p class="mt-1 text-3xl font-bold"><?= $completedTransactions ?></p></article>
        <?php endif; ?>
    </section>
    <?php if ($isAdmin): ?>
        <section id="admin-transactions" class="scroll-mt-24 rounded-2xl bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3"><div><h2 class="text-xl font-bold">All transactions</h2><p class="mt-1 text-sm text-slate-500">Status changes notify both parties and are recorded in the audit log.</p></div>
                <form method="get" class="flex flex-wrap gap-2"><input name="q" value="<?= e($search) ?>" placeholder="Search item, buyer, seller, or ID" class="rounded-xl border px-3 py-2 text-sm"><select name="transaction_status" class="rounded-xl border px-3 py-2 text-sm"><option value="">All statuses</option><?php foreach (["Pending", "Paid", "Completed", "Cancelled"] as $status): ?><option value="<?= e($status) ?>" <?= $transactionStatusFilter === $status ? "selected" : "" ?>><?= e($status) ?></option><?php endforeach; ?></select><button class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Filter</button><a href="export_transactions.php?q=<?= rawurlencode($search) ?>&amp;transaction_status=<?= rawurlencode($transactionStatusFilter) ?>" class="rounded-xl border border-emerald-600 px-4 py-2 text-sm font-semibold text-emerald-700">Export Excel</a><a href="report_transactions.php?q=<?= rawurlencode($search) ?>&amp;transaction_status=<?= rawurlencode($transactionStatusFilter) ?>" target="_blank" rel="noopener" class="rounded-xl border border-slate-500 px-4 py-2 text-sm font-semibold text-slate-700">Print report / PDF</a></form>
            </div>
            <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="p-2">Order</th><th class="p-2">Buyer / seller</th><th class="p-2">Meet-up</th><th class="p-2">Status</th><th class="p-2">Admin action</th></tr></thead><tbody>
                <?php foreach ($transactionRows as $transaction): ?>
                    <tr class="border-b align-top"><td class="p-2"><strong>#<?= (int)$transaction["id"] ?> · <?= e($transaction["product_name"]) ?></strong><p class="text-xs text-slate-500">Rp <?= number_format((float)$transaction["amount"], 0, ",", ".") ?> · <?= e($transaction["created_at"]) ?></p></td>
                        <td class="p-2"><?= e($transaction["buyer_name"]) ?><p class="text-xs text-slate-500">Seller: <?= e($transaction["seller_name"]) ?></p></td>
                        <td class="p-2"><?= e($transaction["meetup_location"]) ?><p class="text-xs text-slate-500"><?= e($transaction["payment_method"]) ?></p><?php if ($transaction["buyer_payment_proof"]): ?><a class="mt-1 block text-xs text-blue-700 underline" href="<?= e(protected_media_url($transaction["buyer_payment_proof"])) ?>" target="_blank" rel="noopener">Buyer payment proof</a><?php endif; ?><?php if ($transaction["seller_cash_receipt"]): ?><a class="mt-1 block text-xs text-blue-700 underline" href="<?= e(protected_media_url($transaction["seller_cash_receipt"])) ?>" target="_blank" rel="noopener">Seller cash receipt</a><?php endif; ?></td>
                        <td class="p-2 font-semibold"><?= e($transaction["payment_status"]) ?></td>
                        <td class="min-w-52 p-2"><details><summary class="cursor-pointer font-semibold text-blue-700">Manage transaction</summary><div class="mt-2 space-y-3"><form method="post" class="space-y-2"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="transaction_status"><input type="hidden" name="transaction_id" value="<?= (int)$transaction["id"] ?>"><select name="status" class="w-full rounded-lg border px-2 py-1"><?php foreach (["Pending", "Paid", "Completed", "Cancelled"] as $status): ?><option value="<?= e($status) ?>" <?= $transaction["payment_status"] === $status ? "selected" : "" ?>><?= e($status) ?></option><?php endforeach; ?></select><input name="reason" required minlength="10" maxlength="500" placeholder="Reason to change status" class="w-full rounded-lg border px-2 py-1"><button class="rounded-lg bg-blue-600 px-3 py-1.5 font-semibold text-white">Update status</button></form><form method="post" class="space-y-2 border-t pt-3" onsubmit="return confirm('Delete order #<?= (int)$transaction["id"] ?> permanently? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="transaction_delete"><input type="hidden" name="transaction_id" value="<?= (int)$transaction["id"] ?>"><input name="reason" required minlength="10" maxlength="500" placeholder="Reason to delete (required)" class="w-full rounded-lg border px-2 py-1"><button class="rounded-lg bg-red-600 px-3 py-1.5 font-semibold text-white">Delete transaction</button></form></div></details></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transactionRows): ?><tr><td colspan="5" class="p-4 text-center text-slate-500">No transactions found.</td></tr><?php endif; ?>
            </tbody></table></div>
            <?php if ($transactionPageCount > 1 || $totalFilteredTransactions > 0): ?><nav aria-label="Transaction pages" class="mt-4 flex items-center justify-between border-t pt-4 text-sm">
                <span>Page <?= $transactionPage ?> of <?= $transactionPageCount ?> · <?= $totalFilteredTransactions ?> transactions</span>
                <div class="flex gap-2"><?php if ($transactionPage > 1): ?><a class="rounded-lg border px-3 py-1" href="?q=<?= rawurlencode($search) ?>&amp;transaction_status=<?= rawurlencode($transactionStatusFilter) ?>&amp;transaction_page=<?= $transactionPage - 1 ?>#admin-transactions">Previous transactions</a><?php endif; ?><?php if ($transactionPage < $transactionPageCount): ?><a class="rounded-lg border px-3 py-1" href="?q=<?= rawurlencode($search) ?>&amp;transaction_status=<?= rawurlencode($transactionStatusFilter) ?>&amp;transaction_page=<?= $transactionPage + 1 ?>#admin-transactions">Next transactions</a><?php endif; ?></div>
            </nav><?php endif; ?>
        </section>
    <?php endif; ?>
    <section class="rounded-2xl bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="text-xl font-bold">Community reports</h2><p class="mt-1 text-sm text-slate-500">Review reports and record the reason for every decision.</p></div>
            <form method="get" class="flex flex-wrap gap-2">
                <input name="q" value="<?= e($search) ?>" placeholder="Search reports / accounts / transactions" class="rounded-xl border px-3 py-2 text-sm">
                <select name="status" class="rounded-xl border px-3 py-2 text-sm"><option value="">All report statuses</option><?php foreach ($validStatuses as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? "selected" : "" ?>><?= e(ucwords(str_replace("_", " ", $status))) ?></option><?php endforeach; ?></select>
                <button class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Filter</button>
            </form>
        </div>
        <?php if (!$reports): ?><p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-500">No reports found.</p><?php else: ?>
            <div class="mt-4 space-y-4">
                <?php foreach ($reports as $report): ?>
                    <article class="rounded-xl border p-4">
                        <div class="flex flex-wrap justify-between gap-2">
                            <div><p class="font-semibold">#<?= (int)$report["id"] ?> · <?= e(ucwords($report["target_type"])) ?>: <?= e($report["target_name"] ?: "Deleted target") ?></p>
                                <p class="mt-1 text-xs text-slate-500">Reported by <?= e($report["reporter_name"]) ?> · <?= e($report["created_at"]) ?></p>
                            </div>
                            <span class="h-fit rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800"><?= e(ucwords(str_replace("_", " ", $report["status"]))) ?></span>
                        </div>
                        <p class="mt-3 text-sm"><strong>Reason:</strong> <?= e(ucwords(str_replace("_", " ", $report["reason"]))) ?></p>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700"><?= e($report["description"]) ?></p>
                        <?php if ($report["target_type"] === "transaction"): ?>
                            <div class="mt-2 flex flex-wrap gap-3 text-sm"><?php if ($report["buyer_payment_proof"]): ?><a class="font-semibold text-blue-700 underline" href="<?= e(protected_media_url($report["buyer_payment_proof"])) ?>" target="_blank" rel="noopener">View buyer payment proof</a><?php endif; ?><?php if ($report["seller_cash_receipt"]): ?><a class="font-semibold text-blue-700 underline" href="<?= e(protected_media_url($report["seller_cash_receipt"])) ?>" target="_blank" rel="noopener">View seller cash receipt</a><?php endif; ?></div>
                        <?php endif; ?>
                        <?php if ($report["assignee_name"]): ?><p class="mt-2 text-xs text-slate-500">Assigned to <?= e($report["assignee_name"]) ?></p><?php endif; ?>
                        <?php if ($report["resolution"] !== ""): ?><p class="mt-2 rounded-lg bg-slate-50 p-3 text-sm"><strong>Review notes:</strong> <?= e($report["resolution"]) ?></p><?php endif; ?>
                        <form method="post" class="mt-3 grid gap-2 sm:grid-cols-[12rem_1fr_auto]">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="report_action"><input type="hidden" name="report_id" value="<?= (int)$report["id"] ?>">
                            <select name="status" class="rounded-lg border px-3 py-2 text-sm"><?php foreach ($validStatuses as $status): ?><option value="<?= e($status) ?>" <?= $report["status"] === $status ? "selected" : "" ?>><?= e(ucwords(str_replace("_", " ", $status))) ?></option><?php endforeach; ?></select>
                            <input name="resolution" required minlength="5" maxlength="2000" value="<?= e($report["resolution"]) ?>" placeholder="Review notes and decision rationale" class="rounded-lg border px-3 py-2 text-sm">
                            <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Save review</button>
                        </form>
                        <?php if (in_array($report["target_type"], ["user", "product"], true)): ?>
                            <?php
                            $moderationTargetExists = false;
                            if ($report["target_type"] === "user") {
                                $targetState = $conn->prepare("SELECT role, is_active FROM users WHERE id = ?");
                                $targetState->bind_param("i", $report["target_id"]);
                            } else {
                                $targetState = $conn->prepare("SELECT is_hidden FROM products WHERE id = ?");
                                $targetState->bind_param("i", $report["target_id"]);
                            }
                            $targetState->execute();
                            $moderationState = $targetState->get_result()->fetch_assoc();
                            $moderationTargetExists = (bool)$moderationState;
                            ?>
                            <?php $canStaffModerateReport = $isAdmin || (in_array($report["status"], ["new", "in_review"], true) && (empty($report["assigned_to"]) || (int)$report["assigned_to"] === (int)$user["id"])); ?>
                            <?php if ($canStaffModerateReport && $moderationTargetExists && $report["target_type"] === "user" && $moderationState["role"] !== "admin" && (int)$report["target_id"] !== (int)$user["id"]): ?>
                                <?php if ($isAdmin || (int)$moderationState["is_active"] === 1): ?>
                                    <form method="post" class="mt-3 flex flex-wrap items-center gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="moderate_user"><input type="hidden" name="user_id" value="<?= (int)$report["target_id"] ?>"><input type="hidden" name="is_active" value="<?= (int)$moderationState["is_active"] === 1 ? 0 : 1 ?>">
                                        <input name="reason" required minlength="10" maxlength="500" placeholder="Reason (10-500 characters)" class="min-w-64 flex-1 rounded-lg border px-3 py-2 text-sm">
                                        <button class="rounded-lg <?= (int)$moderationState["is_active"] === 1 ? "bg-red-600" : "bg-emerald-600" ?> px-4 py-2 text-sm font-semibold text-white"><?= (int)$moderationState["is_active"] === 1 ? "Suspend account" : "Reactivate account" ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ($canStaffModerateReport && $moderationTargetExists && $report["target_type"] === "product" && ($isAdmin || (int)$moderationState["is_hidden"] === 0)): ?>
                                <form method="post" class="mt-3 flex flex-wrap items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="moderate_product"><input type="hidden" name="product_id" value="<?= (int)$report["target_id"] ?>"><input type="hidden" name="is_hidden" value="<?= (int)$moderationState["is_hidden"] === 1 ? 0 : 1 ?>">
                                    <input name="reason" required minlength="10" maxlength="500" placeholder="Reason (10-500 characters)" class="min-w-64 flex-1 rounded-lg border px-3 py-2 text-sm">
                                    <button class="rounded-lg <?= (int)$moderationState["is_hidden"] === 1 ? "bg-emerald-600" : "bg-red-600" ?> px-4 py-2 text-sm font-semibold text-white"><?= (int)$moderationState["is_hidden"] === 1 ? "Restore product" : "Hide product" ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($reportPageCount > 1): ?><nav aria-label="Report pages" class="mt-4 flex items-center justify-between border-t pt-4 text-sm">
                <span>Page <?= $reportPage ?> of <?= $reportPageCount ?> · <?= $totalFilteredReports ?> reports</span>
                <div class="flex gap-2"><?php if ($reportPage > 1): ?><a class="rounded-lg border px-3 py-1" href="?q=<?= rawurlencode($search) ?>&amp;status=<?= rawurlencode($statusFilter) ?>&amp;report_page=<?= $reportPage - 1 ?>">Previous reports</a><?php endif; ?><?php if ($reportPage < $reportPageCount): ?><a class="rounded-lg border px-3 py-1" href="?q=<?= rawurlencode($search) ?>&amp;status=<?= rawurlencode($statusFilter) ?>&amp;report_page=<?= $reportPage + 1 ?>">Next reports</a><?php endif; ?></div>
            </nav><?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($isAdmin): ?>
        <section class="rounded-2xl bg-white p-5 shadow-sm">
            <h2 class="text-xl font-bold">Accounts and staff access</h2><p class="mt-1 text-sm text-slate-500">Only admins can assign staff. Admin accounts must be provisioned directly in the database.</p>
            <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="p-2">Account</th><th class="p-2">Role</th><th class="p-2">Status</th><th class="p-2">Role / account action</th></tr></thead><tbody>
                <?php foreach ($users as $account): ?>
                    <tr class="border-b"><td class="p-2"><strong><?= e($account["full_name"]) ?></strong><p class="text-xs text-slate-500"><?= e($account["email"]) ?></p></td><td class="p-2"><?= e(ucfirst($account["role"])) ?></td><td class="p-2"><?= (int)$account["is_active"] === 1 ? "Active" : "Suspended" ?></td>
                        <td class="p-2"><div class="flex flex-wrap gap-2">
                            <form method="post" class="flex gap-2"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="set_role"><input type="hidden" name="user_id" value="<?= (int)$account["id"] ?>"><select name="role" class="rounded-lg border px-2 py-1"><?php foreach (["buyer", "seller", "staff"] as $role): ?><option value="<?= e($role) ?>" <?= $account["role"] === $role ? "selected" : "" ?>><?= e(ucfirst($role)) ?></option><?php endforeach; ?></select><button class="rounded-lg bg-blue-600 px-3 py-1 text-white">Save role</button></form>
                            <form method="post" class="flex gap-2"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="moderate_user"><input type="hidden" name="user_id" value="<?= (int)$account["id"] ?>"><input type="hidden" name="is_active" value="<?= (int)$account["is_active"] === 1 ? 0 : 1 ?>"><input name="reason" required minlength="10" maxlength="500" placeholder="Reason" class="w-40 rounded-lg border px-2 py-1"><button class="rounded-lg <?= (int)$account["is_active"] === 1 ? "bg-red-600" : "bg-emerald-600" ?> px-3 py-1 text-white"><?= (int)$account["is_active"] === 1 ? "Suspend" : "Reactivate" ?></button></form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section class="rounded-2xl bg-white p-5 shadow-sm">
            <h2 class="text-xl font-bold">Product moderation</h2><p class="mt-1 text-sm text-slate-500">Hidden listings are not visible in marketplace search or available for new orders.</p>
            <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="p-2">Product</th><th class="p-2">Seller</th><th class="p-2">Status</th><th class="p-2">Moderate</th></tr></thead><tbody>
                <?php foreach ($moderatedProducts as $product): ?><tr class="border-b"><td class="p-2"><?= e($product["name"]) ?><?php if ($product["moderation_reason"]): ?><p class="text-xs text-slate-500"><?= e($product["moderation_reason"]) ?></p><?php endif; ?></td><td class="p-2"><?= e($product["seller_name"]) ?></td><td class="p-2"><?= (int)$product["is_hidden"] ? "Hidden" : "Visible" ?></td><td class="p-2"><form method="post" class="flex flex-wrap gap-2"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="moderate_product"><input type="hidden" name="product_id" value="<?= (int)$product["id"] ?>"><input type="hidden" name="is_hidden" value="<?= (int)$product["is_hidden"] ? 0 : 1 ?>"><input name="reason" required minlength="10" maxlength="500" placeholder="Reason" class="w-48 rounded-lg border px-2 py-1"><button class="rounded-lg <?= (int)$product["is_hidden"] ? "bg-emerald-600" : "bg-red-600" ?> px-3 py-1 text-white"><?= (int)$product["is_hidden"] ? "Restore" : "Hide" ?></button></form></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section class="rounded-2xl bg-white p-5 shadow-sm">
            <h2 class="text-xl font-bold">Admin and staff audit log</h2><div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="p-2">When</th><th class="p-2">Who</th><th class="p-2">Action</th><th class="p-2">Target</th><th class="p-2">Details</th></tr></thead><tbody>
                <?php foreach ($auditRows as $log): ?><tr class="border-b align-top"><td class="p-2"><?= e($log["created_at"]) ?></td><td class="p-2"><?= e($log["actor_name"]) ?></td><td class="p-2"><?= e($log["action"]) ?></td><td class="p-2"><?= e($log["target_type"]) ?> #<?= (int)$log["target_id"] ?></td><td class="p-2"><?= e($log["details"]) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
