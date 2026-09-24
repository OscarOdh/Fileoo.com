<?php
// fileoo.com/login.php — login handling + view. Loaded through index.php.
if (!defined('FILEOO_APP')) {
    header("Location: index.php");
    exit;
}

$login_identifier = "";
$password_input = "";
$login_identifier_err = "";
$password_err = "";
$login_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST["csrf_token"]) || !verify_csrf_token($_POST["csrf_token"])) {
        $login_err = "SECURITY_VIOLATION: CSRF token validation failed.";
    } else {
        if (empty(trim($_POST["login_identifier"]))) {
            $login_identifier_err = "Please enter your ID (Username/Email).";
        } else {
            $login_identifier = trim($_POST["login_identifier"]);
        }

        if (empty(trim($_POST["password"]))) {
            $password_err = "Please enter your Passcode.";
        } else {
            $password_input = trim($_POST["password"]);
        }

        if (empty($login_identifier_err) && empty($password_err)) {
            $ip_address = client_ip();

            db_query("DELETE FROM login_attempts WHERE attempt_time < (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)");

            $stmt_attempts = db_query(
                "SELECT COUNT(*) AS failed_count FROM login_attempts WHERE ip_address = ? AND attempt_time > (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)",
                [$ip_address]
            );
            $attempts_result = $stmt_attempts->fetch();

            if ($attempts_result && $attempts_result['failed_count'] >= 5) {
                $login_err = "ACCESS_DENIED: Too many failed login attempts. Locked out for 15 minutes.";
            } else {
                $sql = "SELECT id, username, email, password_hash FROM users WHERE username = ? OR email = ? LIMIT 1";
                $stmt = db_query($sql, [$login_identifier, $login_identifier]);
                $db_user = $stmt->fetch();

                if ($db_user && password_verify($password_input, $db_user["password_hash"])) {
                    db_query("DELETE FROM login_attempts WHERE ip_address = ?", [$ip_address]);

                    $_SESSION["loggedin"] = true;
                    $_SESSION["id"] = $db_user["id"];
                    $_SESSION["username"] = $db_user["username"];
                    $_SESSION["email"] = $db_user["email"];

                    session_regenerate_id(true);
                    header("location: index.php");
                    exit;
                } else {
                    db_query("INSERT INTO login_attempts (ip_address) VALUES (?)", [$ip_address]);
                    $login_err = "ACCESS_DENIED: Invalid ID or Passcode.";
                }
            }
        }
    }
}

$css_version = file_exists(__DIR__ . "/css/style.css") ? filemtime(__DIR__ . "/css/style.css") : "1.0";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>[ LOGIN ]</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo $css_version; ?>">
</head>
<body>
    <div class="auth-wrapper">
        <h2>[ LOGIN ]</h2>
        <?php if(!empty($login_err)): ?>
            <p class="login-overall-error"><?php echo htmlspecialchars($login_err); ?></p>
        <?php endif; ?>

        <form action="index.php" method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="auth-form-group">
                <label for="login_identifier_input">ID:</label>
                <input type="text" name="login_identifier" id="login_identifier_input" class="auth-input" value="<?php echo htmlspecialchars($login_identifier); ?>" required>
                <?php if(!empty($login_identifier_err)): ?><span class="form-field-error"><?php echo htmlspecialchars($login_identifier_err); ?></span><?php endif; ?>
            </div>
            <div class="auth-form-group">
                <label for="password_input_form">PASSCODE:</label>
                <input type="password" name="password" id="password_input_form" class="auth-input" required>
                <?php if(!empty($password_err)): ?><span class="form-field-error"><?php echo htmlspecialchars($password_err); ?></span><?php endif; ?>
            </div>
            <div class="auth-form-group">
                <input type="submit" name="login_submit" class="auth-submit-button" value="[ AUTHENTICATE ]">
            </div>
            <p class="auth-link-container"><a href="register.php">[ REQUEST_CREDS ]</a> &nbsp; <a href="forgot.php">[ FORGOT ]</a></p>
        </form>
    </div>
</body>
</html>
