<?php
// fileoo.com/forgot.php — request a password-reset email.
require_once 'config.php';

if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: index.php");
    exit;
}

$email = "";
$error = "";
$sent = false;

// Housekeeping: drop expired reset tokens.
db_query("DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP()");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST["csrf_token"]) || !verify_csrf_token($_POST["csrf_token"])) {
        $error = "SECURITY_VIOLATION: CSRF token validation failed.";
    } else {
        $email = trim($_POST["email"] ?? "");
        if ($email === "") {
            $error = "Please enter your recovery email.";
        } else {
            // Only act on a real, email-bearing account, but always show the same
            // generic response so this page can't be used to probe for accounts.
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stmt = db_query("SELECT id, username FROM users WHERE email = ? LIMIT 1", [$email]);
                $user = $stmt->fetch();

                if ($user) {
                    $raw_token = bin2hex(random_bytes(32));           // 64 hex chars (the link secret)
                    $token_hash = hash('sha256', $raw_token);          // only the hash is stored

                    db_query("DELETE FROM password_resets WHERE user_id = ?", [$user['id']]);
                    db_query(
                        "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))",
                        [$user['id'], $token_hash]
                    );

                    $reset_link = app_base_url() . '/reset.php?token=' . $raw_token;

                    $body = "A password reset was requested for your FILEOO account (" . $user['username'] . ").\n\n"
                        . "Open this link within 1 hour to choose a new password:\n"
                        . $reset_link . "\n\n"
                        . "If you did not request this, you can safely ignore this email.";

                    $mail_sent = send_mail_smtp($email, 'FILEOO Password Reset', $body);
                    if (!$mail_sent) {
                        error_log('[fileoo] Failed to send password reset email to: ' . $email);
                    }

                    // Dev convenience: with no upload subdomain configured (local),
                    // log the link so it can be tested without a mail server.
                    //if (!UPLOAD_BASE_URL) {
                    //    error_log('[fileoo] Password reset link for ' . $email . ': ' . $reset_link);
                    //}
                }
            }
            $sent = true;
        }
    }
}

$css_version = file_exists("css/style.css") ? filemtime("css/style.css") : "1.0";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>[ FORGOT ]</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo $css_version; ?>">
</head>

<body>
    <div class="auth-wrapper">
        <h2>[ RESET_ACCESS ]</h2>

        <?php if ($sent): ?>
            <div class="registration-feedback success">
                Check your inbox.
            </div>
            <p class="auth-link-container"><a href="index.php">[ BACK_TO_LOGIN ]</a></p>
        <?php else: ?>
            <?php if (!empty($error)): ?>
                <p class="login-overall-error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
            <form action="forgot.php" method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="auth-form-group">
                    <label for="email">Recovery Email:</label>
                    <input type="email" name="email" id="email" class="auth-input"
                        value="<?php echo htmlspecialchars($email); ?>" required>
                </div>
                <div class="auth-form-group">
                    <input type="submit" class="auth-submit-button" value="[ SEND_RESET_LINK ]">
                </div>
            </form>
            <p class="auth-link-container"><a href="index.php">[ LOGIN ]</a> &nbsp; <a href="register.php">[ REQUEST_CREDS
                    ]</a></p>
        <?php endif; ?>
    </div>
</body>

</html>