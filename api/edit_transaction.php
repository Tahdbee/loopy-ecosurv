<?php
require_once "includes/config.php";
require_login();
$user = current_user($conn);
if (in_array($user["role"], ["admin", "staff"], true)) {
    redirect_dashboard($user["role"]);
}

$transactionId = filter_var($_GET["id"] ?? $_POST["id"] ?? null, FILTER_VALIDATE_INT);
if ($transactionId === false || $transactionId < 1) {
    http_response_code(404);
    exit("Transaction not found.");
}

// Only the buyer who created the order may edit it (prevents editing other users' orders).
$stmt = $conn->prepare("SELECT t.*, p.name product_name FROM transactions t JOIN products p ON p.id = t.product_id WHERE t.id = ? AND t.buyer_id = ?");
$stmt->bind_param("ii", $transactionId, $user["id"]);
$stmt->execute();
$transaction = $stmt->get_result()->fetch_assoc();
if (!$transaction) {
    http_response_code(404);
    exit("Transaction not found.");
}

// The meet-up can only be changed while the order is still waiting for the seller.
$editable = $transaction["payment_status"] === "Pending" && (int)$transaction["seller_accepted"] === 0;
$error = "";
$selectedLocation = (string)$transaction["meetup_location"];
$selectedTime = $transaction["meetup_time"] ? date("Y-m-d\TH:i", strtotime((string)$transaction["meetup_time"])) : "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $location = trim((string)($_POST["meetup_location"] ?? ""));
    $submittedTime = trim((string)($_POST["meetup_time"] ?? ""));
    $time = "";

    if (!$editable) {
        $error = "This order can no longer be edited because the seller has already responded.";
    } elseif (!in_array($location, campus_locations(), true)) {
        $error = "Choose a valid meet-up location.";
    } elseif ($submittedTime !== "") {
        $meetupTime = DateTime::createFromFormat('Y-m-d\TH:i', $submittedTime);
        $timeErrors = DateTime::getLastErrors();
        if (!$meetupTime || ($timeErrors !== false && ($timeErrors["warning_count"] > 0 || $timeErrors["error_count"] > 0))) {
            $error = "Please choose a valid meet-up time.";
        } elseif ($meetupTime < new DateTime("now")) {
            $error = "The meet-up time must be in the future.";
        } else {
            $time = $meetupTime->format("Y-m-d H:i:s");
        }
    }

    if ($error === "") {
        $update = $conn->prepare("UPDATE transactions SET meetup_location = ?, meetup_time = NULLIF(?, '') WHERE id = ? AND buyer_id = ? AND payment_status = 'Pending' AND seller_accepted = 0");
        $update->bind_param("ssii", $location, $time, $transactionId, $user["id"]);
        $update->execute();

        $notif = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
        $sellerId = (int)$transaction["seller_id"];
        $notifMessage = mb_substr("Order #" . $transactionId . " (" . $transaction["product_name"] . "): the buyer changed the meet-up to " . $location . ($time !== "" ? " at " . substr($time, 0, 16) : "") . ".", 0, 255);
        $notifLink = "dashboard_seller.php?order_id=" . $transactionId . "#transaction-" . $transactionId;
        $notif->bind_param("iss", $sellerId, $notifMessage, $notifLink);
        $notif->execute();

        header("Location: dashboard_buyer.php#buyer-transaction-" . $transactionId);
        exit;
    }

    $selectedLocation = $location;
    $selectedTime = $submittedTime;
}
?>
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Meet-up | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
</head>
<body class="bg-slate-50 min-h-screen">
<?php include "includes/navbar.php"; ?>
<main class="max-w-2xl mx-auto p-4">
    <a href="dashboard_buyer.php" class="text-blue-700 text-sm">&larr; Buyer Dashboard</a>
    <div class="mt-3 bg-white rounded-2xl shadow-sm p-6">
        <h1 class="text-2xl font-bold text-slate-900">Edit Meet-up</h1>
        <p class="mt-1 text-sm text-slate-600">Order #<?= (int)$transaction["id"] ?> &middot; <?= e((string)$transaction["product_name"]) ?> &middot; Rp <?= number_format((float)$transaction["amount"], 0, ",", ".") ?></p>
        <?php if ($error): ?>
            <p role="alert" class="mt-3 text-sm text-red-700"><?= e($error) ?></p>
        <?php endif; ?>
        <?php if (!$editable): ?>
            <p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">This order can only be edited while it is Pending and the seller has not responded yet. Use the chat to agree on changes with the seller.</p>
        <?php else: ?>
            <form method="post" class="space-y-3 mt-4">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$transaction["id"] ?>">
                <div>
                    <label class="text-sm font-medium" for="meetup_location">Meet-up Location</label>
                    <select id="meetup_location" name="meetup_location" class="mt-1 w-full border rounded-xl px-3 py-2">
                        <?php foreach (campus_locations() as $loc): ?>
                            <option value="<?= e($loc) ?>" <?= $selectedLocation === $loc ? "selected" : "" ?>><?= e($loc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium" for="meetup_time">Meet-up Time</label>
                    <input id="meetup_time" type="datetime-local" name="meetup_time" value="<?= e($selectedTime) ?>" class="mt-1 w-full border rounded-xl px-3 py-2">
                </div>
                <button class="w-full bg-orange-500 hover:bg-orange-600 text-white rounded-xl py-2 font-semibold">Save changes</button>
            </form>
        <?php endif; ?>
    </div>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
