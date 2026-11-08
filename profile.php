<?php
require_once "config.php";
require_login();
$user = current_user($conn);
$message = "";
$error = "";

if (empty($_SESSION["profile_csrf_token"])) {
    $_SESSION["profile_csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["profile_csrf_token"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedToken = $_POST["csrf_token"] ?? "";
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $error = "Your session expired. Refresh the page and try again.";
    } elseif (isset($_POST["update_profile"])) {
        $fullName = trim($_POST["full_name"] ?? "");
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120) {
            $error = "Your name must be between 2 and 120 characters.";
        } else {
            $newPhotoPath = "";
            try {
                $photoPath = $user["profile_image"] ?? "";
                $photoFile = $_FILES["profile_photo"] ?? [];
                if (($photoFile["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newPhotoPath = upload_profile_photo($photoFile);
                    $photoPath = $newPhotoPath;
                }

                $stmt = $conn->prepare("UPDATE users SET full_name = ?, profile_image = ? WHERE id = ?");
                $stmt->bind_param("ssi", $fullName, $photoPath, $user["id"]);
                if ($stmt->execute()) {
                    $oldPhotoPath = (string)($user["profile_image"] ?? "");
                    $user["full_name"] = $fullName;
                    $user["profile_image"] = $photoPath;
                    $message = "Your profile has been updated.";
                    if ($newPhotoPath !== "" && $oldPhotoPath !== "") {
                        try {
                            delete_profile_photo($oldPhotoPath);
                        } catch (RuntimeException $e) {
                            $message .= " The previous photo could not be removed from storage.";
                        }
                    }
                } else {
                    if ($newPhotoPath !== "") {
                        delete_profile_photo($newPhotoPath);
                    }
                    $error = "Could not update your profile. Please try again.";
                }
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
    } elseif (isset($_POST["remove_profile_photo"])) {
        $oldPhotoPath = (string)($user["profile_image"] ?? "");
        $stmt = $conn->prepare("UPDATE users SET profile_image = '' WHERE id = ?");
        $stmt->bind_param("i", $user["id"]);
        if ($stmt->execute()) {
            $user["profile_image"] = "";
            $message = "Your profile photo has been removed.";
            if ($oldPhotoPath !== "") {
                try {
                    delete_profile_photo($oldPhotoPath);
                } catch (RuntimeException $e) {
                    $message .= " The old image could not be removed from storage.";
                }
            }
        } else {
            $error = "Could not remove your profile photo. Please try again.";
        }
    } elseif (isset($_POST["new_role"])) {
        $newRole = $_POST["new_role"];
        if (in_array($newRole, ["buyer", "seller"], true)) {
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->bind_param("si", $newRole, $user["id"]);
            $stmt->execute();
            redirect_completion("profile_updated");
        }
        $error = "Choose a valid account role.";
    }
}

$avg = $conn->prepare("SELECT AVG(rating) avg_rating, COUNT(*) total_reviews FROM reviews WHERE reviewee_id = ?");
$avg->bind_param("i", $user["id"]);
$avg->execute();
$reviewStats = $avg->get_result()->fetch_assoc();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile &amp; Settings | LOOPLY</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-50 min-h-screen">
<?php include "includes/navbar.php"; ?>
<main class="profile-page mx-auto max-w-5xl space-y-5 p-4">
    <header class="profile-page-heading">
        <p class="landing-section-kicker">Your LOOPLY account</p>
        <h1>Profile <span>&amp;</span> settings</h1>
    </header>

    <?php if ($message): ?><p role="status" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700"><?= e($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-700"><?= e($error) ?></p><?php endif; ?>

    <section id="profile" class="profile-settings-card scroll-reveal">
        <div class="profile-settings-card-heading">
            <div class="profile-settings-icon" aria-hidden="true">♙</div>
            <div>
                <p class="messages-overline">YOUR DETAILS</p>
                <h2>Edit profile</h2>
                <p>Update the name shown to other LOOPLY members.</p>
            </div>
        </div>
        <form method="post" enctype="multipart/form-data" class="profile-edit-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <div class="profile-photo-editor">
                <div class="profile-photo-preview" id="profile-photo-preview">
                    <?php if (!empty($user["profile_image"])): ?>
                        <img src="<?= e($user["profile_image"]) ?>" alt="Your profile photo">
                    <?php else: ?>
                        <span><?= e(strtoupper(substr($user["full_name"], 0, 1))) ?></span>
                    <?php endif; ?>
                </div>
                <div class="profile-photo-controls">
                    <label for="profile_photo">Profile photo</label>
                    <p>Choose a clear image so people recognize you.</p>
                    <input id="profile_photo" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp">
                    <small>JPG, PNG, or WEBP · up to 3 MB</small>
                    <?php if (!empty($user["profile_image"])): ?>
                        <button type="submit" name="remove_profile_photo" value="1" formnovalidate class="profile-remove-photo">Remove photo</button>
                    <?php endif; ?>
                </div>
            </div>
            <label for="full_name">Full name</label>
            <input id="full_name" name="full_name" value="<?= e($user["full_name"]) ?>" required minlength="2" maxlength="120" autocomplete="name">
            <div class="profile-readonly-fields">
                <div>
                    <span>Email address</span>
                    <strong><?= e($user["email"]) ?></strong>
                </div>
                <div>
                    <span>Faculty and major</span>
                    <strong><?= e($user["faculty"] ?: "-") ?> / <?= e($user["major"] ?: "-") ?></strong>
                </div>
            </div>
            <p class="profile-verification <?= (int)$user["is_verified"] ? "is-verified" : "" ?>">
                <span aria-hidden="true"><?= (int)$user["is_verified"] ? "✓" : "!" ?></span>
                <?= (int)$user["is_verified"] ? "Verified student account" : "Unverified account" ?>
            </p>
            <button type="submit" name="update_profile" value="1" class="profile-save-button">Save profile <span aria-hidden="true">→</span></button>
        </form>
    </section>

    <section id="settings" class="profile-settings-card scroll-reveal">
        <div class="profile-settings-card-heading">
            <div class="profile-settings-icon settings-icon" aria-hidden="true">⚙</div>
            <div>
                <p class="messages-overline">ACCOUNT PREFERENCES</p>
                <h2>Settings</h2>
                <p>Manage your account role.</p>
            </div>
        </div>
        <div class="profile-account-settings">
            <form method="post" class="profile-role-setting">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <div>
                    <label for="new_role">Account role</label>
                    <p>Choose how you want to use LOOPLY.</p>
                </div>
                <select id="new_role" name="new_role" class="rounded-xl border px-3 py-2">
                    <option value="buyer" <?= $user["role"] === "buyer" ? "selected" : "" ?>>Buyer</option>
                    <option value="seller" <?= $user["role"] === "seller" ? "selected" : "" ?>>Seller</option>
                </select>
                <button type="submit" class="profile-outline-button">Update role</button>
            </form>
        </div>
    </section>

    <section class="profile-settings-card scroll-reveal">
        <div class="profile-settings-card-heading">
            <div class="profile-settings-icon rating-icon" aria-hidden="true">★</div>
            <div>
                <p class="messages-overline">COMMUNITY TRUST</p>
                <h2>Your seller rating</h2>
                <p><?= (int)($reviewStats["total_reviews"] ?? 0) ?> <?= (int)($reviewStats["total_reviews"] ?? 0) === 1 ? "review" : "reviews" ?> from the LOOPLY community</p>
            </div>
            <strong class="profile-rating-value"><?= number_format((float)($reviewStats["avg_rating"] ?? 0), 1) ?><span>/5</span></strong>
        </div>
    </section>
</main>
<script>
const profilePhotoInput = document.getElementById("profile_photo");
profilePhotoInput.addEventListener("change", () => {
    const file = profilePhotoInput.files[0];
    if (!file) return;
    const preview = document.getElementById("profile-photo-preview");
    const image = document.createElement("img");
    image.alt = "Selected profile photo preview";
    image.src = URL.createObjectURL(file);
    image.onload = () => URL.revokeObjectURL(image.src);
    preview.replaceChildren(image);
});
</script>
<?php include "includes/footer.php"; ?>
</body>
</html>
