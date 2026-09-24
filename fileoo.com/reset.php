<?php
// fileoo.com/reset.php — set a new password from an emailed reset link.
require_once 'config.php';

// Housekeeping: drop expired reset tokens.
db_query("DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP()");

$token = trim($_POST['token'] ?? $_GET['token'] ?? '');

// Resolve the token to a user (only the hash is stored server-side).
$valid = false;
$user_id = null;
if ($token !== '') {
    $stmt = db_query(
        "SELECT user_id FROM password_resets WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1",
        [hash('sha256', $token)]
    );
    $row = $stmt->fetch();
    if ($row) {
        $valid = true;
        $user_id = $row['user_id'];
    }
}

$error = "";
$password_err = "";
$success = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST["csrf_token"]) || !verify_csrf_token($_POST["csrf_token"])) {
        $error = "SECURITY_VIOLATION: CSRF token validation failed.";
    } elseif (!$valid) {
        $error = "This reset link is invalid or has expired.";
    } else {
        $pw = $_POST["password"] ?? "";
        $cpw = $_POST["confirm_password"] ?? "";

        if (strlen($pw) < 10) {
            $password_err = "Password must have at least 10 characters.";
        } elseif (!preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
            $password_err = "Password must include at least one uppercase letter, one lowercase letter, and one number.";
        } elseif ($pw !== $cpw) {
            $password_err = "Passwords did not match.";
        } else {
            $new_hash = password_hash($pw, PASSWORD_DEFAULT);
            db_query("UPDATE users SET password_hash = ? WHERE id = ?", [$new_hash, $user_id]);
            db_query("DELETE FROM password_resets WHERE user_id = ?", [$user_id]);
            $success = true;
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
    <title>[ RESET_PASSCODE ]</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo $css_version; ?>">
</head>
<body>
    <div class="auth-wrapper">
        <h2>[ NEW_PASSCODE ]</h2>

        <?php if ($success): ?>
            <div class="registration-feedback success">
                Your password has been reset.<br><a href="index.php">[ LOGIN_HERE ]</a>
            </div>
        <?php elseif (!$valid): ?>
            <div class="registration-feedback error">
                This reset link is invalid or has expired.
            </div>
            <p class="auth-link-container"><a href="forgot.php">[ REQUEST_A_NEW_LINK ]</a></p>
        <?php else: ?>
            <?php if (!empty($error)): ?>
                <p class="login-overall-error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
            <form action="reset.php" method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div class="auth-form-group">
                    <label for="password">New Passcode (min 10 char, complex):</label>
                    <input type="password" name="password" id="password" class="auth-input" required minlength="10">
                    <?php if (!empty($password_err)): ?><span class="form-field-error"><?php echo htmlspecialchars($password_err); ?></span><?php endif; ?>
                </div>
                <div class="auth-form-group">
                    <label for="confirm_password">Confirm Passcode:</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="auth-input" required>
                </div>
                <div class="auth-form-group">
                    <input type="submit" class="auth-submit-button" value="[ SET_PASSCODE ]">
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
