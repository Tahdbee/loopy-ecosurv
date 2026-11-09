<?php
require_once "includes/config.php";
require_login();
$user = current_user($conn);

$stmt = $conn->prepare(
    "SELECT c.id, c.product_id, c.sender_id, c.receiver_id, c.message, c.created_at,
            p.name product_name,
            CASE WHEN c.sender_id = ? THEN receiver.full_name ELSE sender.full_name END partner_name,
            CASE WHEN c.sender_id = ? THEN receiver.profile_image ELSE sender.profile_image END partner_photo,
            CASE WHEN c.sender_id = ? THEN c.receiver_id ELSE c.sender_id END partner_id
     FROM chats c
     JOIN products p ON p.id = c.product_id
     JOIN users sender ON sender.id = c.sender_id
     JOIN users receiver ON receiver.id = c.receiver_id
     WHERE (c.sender_id = ? OR c.receiver_id = ?)
       AND c.id = (
           SELECT latest.id
           FROM chats latest
           WHERE latest.product_id = c.product_id
             AND (
                 (latest.sender_id = c.sender_id AND latest.receiver_id = c.receiver_id)
                 OR
                 (latest.sender_id = c.receiver_id AND latest.receiver_id = c.sender_id)
             )
           ORDER BY latest.created_at DESC, latest.id DESC
           LIMIT 1
       )
     ORDER BY c.created_at DESC, c.id DESC"
);
$stmt->bind_param("iiiii", $user["id"], $user["id"], $user["id"], $user["id"], $user["id"]);
$stmt->execute();
$conversations = $stmt->get_result();
$conversationRows = [];
while ($conversationRow = $conversations->fetch_assoc()) {
    $conversationRows[] = $conversationRow;
}
$latestConversation = $conversationRows[0] ?? null;
?>
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Messages | LOOPLY</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>"></head>
<body class="bg-slate-50 min-h-screen">
<?php include "includes/navbar.php"; ?>
<main class="messages-workspace mx-auto max-w-7xl p-4">
    <header class="messages-page-heading scroll-reveal">
        <div>
            <p class="landing-section-kicker">Your campus, one conversation away</p>
            <h1>Messages<span>.</span></h1>
            <p class="messages-page-subtitle">Make a plan, ask a question, find your next favorite thing.</p>
        </div>
        <a href="index.php#marketplace" class="messages-explore-link">Explore finds <span aria-hidden="true">↗</span></a>
    </header>

    <section class="messages-dashboard scroll-reveal" aria-label="Your message inbox">
        <aside class="messages-sidebar">
            <div class="messages-sidebar-top">
                <div>
                    <p class="messages-overline">LOOPLY INBOX</p>
                    <h2>Your conversations</h2>
                </div>
                <span class="messages-count"><?= $conversations->num_rows ?></span>
            </div>
            <a href="index.php#marketplace" class="messages-new-chat"><span aria-hidden="true">＋</span> Find someone to chat</a>
            <div class="messages-conversation-list">
            <?php if ($conversations->num_rows === 0): ?>
                <div class="messages-list-empty">
                    <span aria-hidden="true">✦</span>
                    <p>No conversations yet.</p>
                    <small>Ask a seller about something you like.</small>
                </div>
            <?php else: ?>
                <?php foreach ($conversationRows as $conversation): ?>
                    <a href="chat.php?product_id=<?= (int)$conversation["product_id"] ?>&partner_id=<?= (int)$conversation["partner_id"] ?>" class="messages-conversation">
                        <span class="messages-avatar" aria-hidden="true">
                            <?php if (!empty($conversation["partner_photo"])): ?><img src="<?= e($conversation["partner_photo"]) ?>" alt=""><?php else: ?><?= e(strtoupper(substr($conversation["partner_name"], 0, 1))) ?><?php endif; ?>
                        </span>
                        <span class="messages-conversation-copy">
                            <span class="messages-conversation-topline">
                                <strong><?= e($conversation["partner_name"]) ?></strong>
                                <time><?= e(date("M j", strtotime($conversation["created_at"]))) ?></time>
                            </span>
                            <span class="messages-product-name"><?= e($conversation["product_name"]) ?></span>
                            <span class="messages-preview"><?= e($conversation["message"]) ?></span>
                        </span>
                        <span class="messages-conversation-arrow" aria-hidden="true">↗</span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
            <div class="messages-sidebar-note">
                <span aria-hidden="true">✳</span>
                <p><strong>Good chats start with good finds.</strong><br>Be kind, meet safely, and enjoy campus shopping.</p>
            </div>
        </aside>

        <div class="messages-welcome">
            <div class="messages-welcome-art" aria-hidden="true">
                <span class="messages-art-orbit messages-art-orbit-one"></span>
                <span class="messages-art-orbit messages-art-orbit-two"></span>
                <span class="messages-art-spark messages-art-spark-one">✦</span>
                <span class="messages-art-spark messages-art-spark-two">✧</span>
                <div class="messages-chat-bubble messages-chat-bubble-left">Is this still available?</div>
                <div class="messages-chat-bubble messages-chat-bubble-right">Yes! Want to meet on campus? <span>✦</span></div>
                <div class="messages-art-center"><span>looply<i>.</i></span></div>
            </div>
            <p class="landing-section-kicker">A little hello goes a long way</p>
            <h2>Find it. Ask about it.<br><span>Make it yours.</span></h2>
            <p class="messages-welcome-copy">Your conversations help campus finds find a new home. Choose a chat to pick up where you left off, or discover something new.</p>
            <?php if ($latestConversation): ?>
                <a class="messages-resume-button" href="chat.php?product_id=<?= (int)$latestConversation["product_id"] ?>&partner_id=<?= (int)$latestConversation["partner_id"] ?>">Resume your latest chat <span aria-hidden="true">→</span></a>
            <?php else: ?>
                <a class="messages-resume-button" href="index.php#marketplace">Browse campus finds <span aria-hidden="true">→</span></a>
            <?php endif; ?>
        </div>

        <aside class="messages-sidecard">
            <div class="messages-sidecard-icon" aria-hidden="true">✳</div>
            <p class="messages-overline">THE LOOPLY WAY</p>
            <h2>Good things happen when we connect.</h2>
            <p>Ask about an item's condition, agree on a public campus meetup, and make your next find a little more personal.</p>
            <a href="index.php#marketplace">See what's around <span aria-hidden="true">↗</span></a>
            <div class="messages-sidecard-decoration" aria-hidden="true">looply.</div>
        </aside>
    </section>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
