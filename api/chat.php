<?php
require_once "includes/config.php";
require_login();
$user = current_user($conn);
$productId = (int)($_GET["product_id"] ?? 0);
$partnerId = (int)($_GET["partner_id"] ?? 0);
$chatError = "";
$productCheck = $conn->prepare("SELECT p.seller_id, p.name, p.image1, p.price, u.full_name seller_name FROM products p JOIN users u ON u.id = p.seller_id WHERE p.id = ?");
$productCheck->bind_param("i", $productId);
$productCheck->execute();
$chatProduct = $productCheck->get_result()->fetch_assoc();
$sellerId = (int)($chatProduct["seller_id"] ?? 0);
$validConversation = $productId > 0
    && $partnerId > 0
    && $partnerId !== (int)$user["id"]
    && $sellerId > 0
    && ((int)$user["id"] === $sellerId || $partnerId === $sellerId);

if (!$validConversation) {
    $chatError = "This conversation is unavailable. You cannot chat with your own account.";
}

$partnerName = "";
$partnerPhoto = "";
if ($validConversation) {
    $partnerQuery = $conn->prepare("SELECT full_name, profile_image FROM users WHERE id = ?");
    $partnerQuery->bind_param("i", $partnerId);
    $partnerQuery->execute();
    $partner = $partnerQuery->get_result()->fetch_assoc();
    $partnerName = (string)($partner["full_name"] ?? "");
    $partnerPhoto = (string)($partner["profile_image"] ?? "");
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $validConversation) {
    $message = trim($_POST["message"] ?? "");
    if ($message !== "") {
        $stmt = $conn->prepare("INSERT INTO chats (product_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiis", $productId, $user["id"], $partnerId, $message);
        $stmt->execute();
    }
}
$messages = [];
if ($validConversation) {
    $stmt = $conn->prepare("SELECT c.*, s.full_name sender_name FROM chats c JOIN users s ON c.sender_id = s.id WHERE c.product_id = ? AND ((c.sender_id = ? AND c.receiver_id = ?) OR (c.sender_id = ? AND c.receiver_id = ?)) ORDER BY c.created_at ASC");
    $stmt->bind_param("iiiii", $productId, $user["id"], $partnerId, $partnerId, $user["id"]);
    $stmt->execute();
    $messages = $stmt->get_result();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with <?= e($partnerName ?: "seller") ?> | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-slate-50 min-h-screen">
<?php include "includes/navbar.php"; ?>
<main class="chat-workspace mx-auto max-w-7xl p-4">
    <div class="chat-topbar">
        <a href="messages.php" class="chat-back-link"><span aria-hidden="true">←</span> All messages</a>
        <p><span class="chat-live-dot"></span> A campus conversation</p>
    </div>
    <?php if ($chatError): ?>
        <section class="chat-error-panel" role="alert">
            <span aria-hidden="true">✦</span>
            <h1>Conversation unavailable</h1>
            <p><?= e($chatError) ?></p>
            <a href="messages.php">Back to messages</a>
        </section>
    <?php else: ?>
        <section class="chat-layout">
            <div class="chat-panel">
                <header class="chat-header">
                    <a href="messages.php" class="chat-header-back" aria-label="Back to messages">←</a>
                    <div class="chat-partner-avatar" aria-hidden="true">
                        <?php if ($partnerPhoto !== ""): ?><img src="<?= e($partnerPhoto) ?>" alt=""><?php else: ?><?= e(strtoupper(substr($partnerName, 0, 1))) ?><?php endif; ?>
                    </div>
                    <div class="chat-partner-copy">
                        <h1><?= e($partnerName) ?></h1>
                        <p><span class="chat-live-dot"></span> LOOPLY community member</p>
                    </div>
                    <a class="chat-item-chip" href="product_detail.php?id=<?= $productId ?>">About this find <span aria-hidden="true">↗</span></a>
                </header>

                <div class="chat-thread" id="chat-thread" aria-live="polite">
                    <div class="chat-thread-intro">
                        <span class="chat-thread-spark" aria-hidden="true">✦</span>
                        <p>You’re chatting about</p>
                        <a href="product_detail.php?id=<?= $productId ?>"><?= e($chatProduct["name"]) ?></a>
                        <span class="chat-thread-price">Rp <?= number_format((float)$chatProduct["price"], 0, ",", ".") ?></span>
                    </div>
                    <?php if ($messages && $messages->num_rows > 0): ?>
                        <?php while ($m = $messages->fetch_assoc()): ?>
                            <?php $isOwnMessage = (int)$m["sender_id"] === (int)$user["id"]; ?>
                            <article class="chat-message-row <?= $isOwnMessage ? "is-own-message" : "is-other-message" ?>">
                                <?php if (!$isOwnMessage): ?>
                                    <span class="chat-message-avatar" aria-hidden="true">
                                        <?php if ($partnerPhoto !== ""): ?><img src="<?= e($partnerPhoto) ?>" alt=""><?php else: ?><?= e(strtoupper(substr($partnerName, 0, 1))) ?><?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <div class="chat-message-content">
                                    <span class="chat-message-sender"><?= $isOwnMessage ? "You" : e($m["sender_name"]) ?></span>
                                    <p class="chat-message-bubble"><?= nl2br(e($m["message"])) ?></p>
                                    <time><?= e(date("g:i a · M j", strtotime($m["created_at"]))) ?></time>
                                </div>
                            </article>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="chat-first-message">
                            <span aria-hidden="true">✧</span>
                            <p>This is the start of something good.</p>
                            <small>Say hello or ask a question about the item.</small>
                        </div>
                    <?php endif; ?>
                </div>

                <form method="post" class="chat-composer">
                    <label class="sr-only" for="chat-message">Your message</label>
                    <textarea id="chat-message" name="message" required rows="1" maxlength="2000" placeholder="Ask about the item, condition, or meetup..."></textarea>
                    <div class="chat-composer-bottom">
                        <p><span aria-hidden="true">✳</span> Enter to send · Shift+Enter for a new line</p>
                        <button type="submit" aria-label="Send message">Send <span aria-hidden="true">↗</span></button>
                    </div>
                </form>
            </div>

            <aside class="chat-context-panel">
                <p class="messages-overline">THE ITEM IN THIS CHAT</p>
                <a class="chat-context-product" href="product_detail.php?id=<?= $productId ?>">
                    <div class="chat-context-image"><img src="<?= e(asset_url($chatProduct["image1"])) ?>" alt="<?= e($chatProduct["name"]) ?>"></div>
                    <div class="chat-context-copy">
                        <h2><?= e($chatProduct["name"]) ?></h2>
                        <p>Rp <?= number_format((float)$chatProduct["price"], 0, ",", ".") ?></p>
                        <span>View item details <span aria-hidden="true">↗</span></span>
                    </div>
                </a>
                <div class="chat-context-divider"></div>
                <div class="chat-safety-note">
                    <span aria-hidden="true">✳</span>
                    <div>
                        <h3>Meet campus-safe</h3>
                        <p>Arrange to meet in a public place on campus. Confirm the item and payment together.</p>
                    </div>
                </div>
                <div class="chat-context-decoration" aria-hidden="true">Good finds,<br>better connections.</div>
            </aside>
        </section>
        <script>
        const chatThread = document.getElementById("chat-thread");
        chatThread.scrollTop = chatThread.scrollHeight;
        const chatComposer = document.querySelector(".chat-composer textarea");
        chatComposer.addEventListener("input", () => {
            chatComposer.style.height = "auto";
            chatComposer.style.height = `${Math.min(chatComposer.scrollHeight, 140)}px`;
        });
        chatComposer.addEventListener("keydown", (event) => {
            if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                chatComposer.form.requestSubmit();
            }
        });
        </script>
    <?php endif; ?>
</main>
<?php include "includes/footer.php"; ?>
</body>
</html>
