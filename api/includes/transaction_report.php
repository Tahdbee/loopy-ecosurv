<?php

/**
 * Shared data access for the transaction report (print / PDF) and the Excel export,
 * so both always show exactly the same rows for the same filters.
 */

function report_statuses(): array
{
    return ["Pending", "Paid", "Completed", "Cancelled"];
}

/**
 * Reads the report filters from the query string.
 * Returns [status, search]; status is "" (all) or one of report_statuses().
 *
 * @return array{0: string, 1: string}
 */
function report_filters_from_request(): array
{
    $status = isset($_GET["transaction_status"]) && is_string($_GET["transaction_status"]) ? $_GET["transaction_status"] : "";
    if (!in_array($status, report_statuses(), true)) {
        $status = "";
    }
    $search = isset($_GET["q"]) && is_string($_GET["q"]) ? trim($_GET["q"]) : "";
    if (mb_strlen($search) > 100) {
        $search = mb_substr($search, 0, 100);
    }
    return [$status, $search];
}

/**
 * Fetches transactions matching the filters (newest first, no pagination).
 * All user input is bound as parameters.
 */
function fetch_report_transactions(mysqli $conn, string $status, string $search, int $limit = 5000): array
{
    $conditions = [];
    $types = "";
    $params = [];

    if (in_array($status, report_statuses(), true)) {
        $conditions[] = "t.payment_status = ?";
        $types .= "s";
        $params[] = $status;
    }
    if ($search !== "") {
        $conditions[] = "(p.name LIKE ? OR buyer.full_name LIKE ? OR seller.full_name LIKE ? OR CAST(t.id AS CHAR) = ?)";
        $like = "%" . $search . "%";
        array_push($params, $like, $like, $like, $search);
        $types .= "ssss";
    }

    $sql = "SELECT t.id, t.created_at, t.amount, t.payment_status, t.payment_type, t.payment_method, t.meetup_location, t.meetup_time,
            p.name product_name, p.category product_category, buyer.full_name buyer_name, seller.full_name seller_name
        FROM transactions t
        JOIN products p ON p.id = t.product_id
        JOIN users buyer ON buyer.id = t.buyer_id
        JOIN users seller ON seller.id = t.seller_id";
    if ($conditions) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    $sql .= " ORDER BY t.created_at DESC, t.id DESC LIMIT " . max(1, $limit);

    $stmt = $conn->prepare($sql);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Totals for the report header: overall count/value and a breakdown per status.
 */
function summarize_report_transactions(array $rows): array
{
    $summary = ["count" => count($rows), "total" => 0.0, "by_status" => []];
    foreach (report_statuses() as $status) {
        $summary["by_status"][$status] = ["count" => 0, "amount" => 0.0];
    }
    foreach ($rows as $row) {
        $amount = (float)$row["amount"];
        $summary["total"] += $amount;
        if (isset($summary["by_status"][$row["payment_status"]])) {
            $summary["by_status"][$row["payment_status"]]["count"]++;
            $summary["by_status"][$row["payment_status"]]["amount"] += $amount;
        }
    }
    return $summary;
}
