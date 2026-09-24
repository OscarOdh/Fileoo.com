<?php
// fileoo.com/register.php
require_once 'config.php'; // Includes session_start() and PDO $conn

// If user is already logged in, redirect them to the file manager
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: index.php");
    exit;
}

// Initialize variables
$username = $email = $password = $confirm_password = "";
$username_err = $email_err = $password_err = $confirm_password_err = "";
$registration_success = ""; // Can be success message or error message

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. Verify CSRF Token
    if (!isset($_POST["csrf_token"]) || !verify_csrf_token($_POST["csrf_token"])) {
        $registration_success = "SECURITY_VIOLATION: CSRF token validation failed.";
    } else {
        // 2. Validate Cloudflare Turnstile CAPTCHA Server-Side
        $turnstile_response = $_POST['cf-turnstile-response'] ?? '';
        $secret_key = TURNSTILE_SECRET_KEY; // defined in config.php

        $post_data = http_build_query([
            'secret' => $secret_key,
            'response' => $turnstile_response,
            'remoteip' => client_ip()
        ]);

        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-type: application/x-www-form-urlencoded',
                'content' => $post_data,
                'timeout' => 5
            ]
        ];

        $context = stream_context_create($opts);
        $response = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        $response_data = json_decode($response, true);

        if (empty($response_data['success'])) {
            $registration_success = "CAPTCHA verification failed. Please try again.";
        }

        // 3. Validate username
        if (empty(trim($_POST["username"]))) {
            $username_err = "Please enter a username.";
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', trim($_POST["username"]))) {
            $username_err = "Username can only contain letters, numbers, and underscores.";
        } else {
            $sql = "SELECT id FROM users WHERE username = ? LIMIT 1";
            $stmt = db_query($sql, [trim($_POST["username"])]);
            if ($stmt->fetch()) {
                $username_err = "This username is already taken.";
            } else {
                $username = trim($_POST["username"]);
            }
        }

        // 4. Validate email — optional, for account recovery only. A blank
        //    field is accepted and stored as NULL (see the INSERT below).
        $email = trim($_POST["email"] ?? "");
        if ($email !== "") {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email_err = "Please enter a valid email address.";
            } else {
                $sql = "SELECT id FROM users WHERE email = ? LIMIT 1";
                $stmt = db_query($sql, [$email]);
                if ($stmt->fetch()) {
                    $email_err = "This email is already registered.";
                }
            }
        }

        // 5. Validate password with strong complexity (Minimum 10 chars, upper/lower/numbers)
        if (empty(trim($_POST["password"]))) {
            $password_err = "Please enter a password.";
        } elseif (strlen(trim($_POST["password"])) < 10) {
            $password_err = "Password must have at least 10 characters.";
        } elseif (!preg_match('/[A-Z]/', $_POST["password"]) || !preg_match('/[a-z]/', $_POST["password"]) || !preg_match('/[0-9]/', $_POST["password"])) {
            $password_err = "Password must include at least one uppercase letter, one lowercase letter, and one number.";
        } else {
            $password = trim($_POST["password"]);
        }

        // 6. Validate confirm password
        if (empty(trim($_POST["confirm_password"]))) {
            $confirm_password_err = "Please confirm password.";
        } else {
            $confirm_password = trim($_POST["confirm_password"]);
            if (empty($password_err) && ($password != $confirm_password)) {
                $confirm_password_err = "Passwords did not match.";
            }
        }

        // 7. Complete registration
        if (empty($username_err) && empty($email_err) && empty($password_err) && empty($confirm_password_err) && empty($registration_success)) {
            $sql = "INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)";
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            // Store NULL (not "") when no email was given, so the UNIQUE index
            // on email allows any number of accounts without a recovery email.
            db_query($sql, [$username, ($email !== "" ? $email : null), $password_hash]);
            
            $registration_success = "Registration successful! You can now<br><a href='index.php'>[ LOGIN_HERE ]</a>";
            $_POST = array(); 
            $username = $email = $password = $confirm_password = ""; 
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
    <title>[ REQUEST_CREDS ]</title>
    <!-- Warm the connection to Cloudflare Turnstile so its script/iframe load without a cold DNS+TLS handshake -->
    <link rel="preconnect" href="https://challenges.cloudflare.com">
    <link rel="dns-prefetch" href="https://challenges.cloudflare.com">
    <link rel="stylesheet" href="css/style.css?v=<?php echo $css_version; ?>">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>
<body>
    <div class="auth-wrapper">
        <h2>[ REQUEST_CREDS ]</h2>

        <?php if (!empty($registration_success)): ?>
            <?php
            $is_success_message = (strpos(strtolower($registration_success), 'successful') !== false || strpos($registration_success, 'LOGIN_HERE') !== false);
            $feedback_class = $is_success_message ? 'success' : 'error';
            ?>
            <div class="registration-feedback <?php echo $feedback_class; ?>">
                <?php echo $registration_success; ?>
            </div>
        <?php endif; ?>

        <?php
        $show_form = true;
        if (!empty($registration_success) && (strpos(strtolower($registration_success), 'successful') !== false || strpos($registration_success, 'LOGIN_HERE') !== false) ) {
            $show_form = false;
        }
        ?>

        <?php if ($show_form): ?>
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="auth-form-group">
                <label for="username">Desired ID (Username):</label>
                <input type="text" name="username" id="username" class="auth-input" value="<?php echo htmlspecialchars($username); ?>" required>
                <?php if(!empty($username_err)): ?><span class="form-field-error"><?php echo htmlspecialchars($username_err); ?></span><?php endif; ?>
            </div>
            <div class="auth-form-group">
                <label for="email">Recovery Email (Optional):</label>
                <input type="email" name="email" id="email" class="auth-input" value="<?php echo htmlspecialchars($email); ?>">
                <?php if(!empty($email_err)): ?><span class="form-field-error"><?php echo htmlspecialchars($email_err); ?></span><?php endif; ?>
            </div>
            <div class="auth-form-group">
                <label for="password">Access Passcode (min 10 char, complex):</label>
                <input type="password" name="password" id="password" class="auth-input" required>
                <?php if(!empty($password_err)): ?><span class="form-field-error"><?php echo htmlspecialchars($password_err); ?></span><?php endif; ?>
            </div>
            <div class="auth-form-group">
                <label for="confirm_password">Confirm Passcode:</label>
                <input type="password" name="confirm_password" id="confirm_password" class="auth-input" required>
                <?php if(!empty($confirm_password_err)): ?><span class="form-field-error"><?php echo htmlspecialchars($confirm_password_err); ?></span><?php endif; ?>
            </div>
            
            <div class="captcha-container" style="display: flex; justify-content: center; margin-top: 15px; margin-bottom: 15px;">
                <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars(TURNSTILE_SITE_KEY); ?>"></div>
            </div>
            
            <div class="auth-form-group">
                <input type="submit" class="auth-submit-button" value="[ SUBMIT ]">
            </div>
        </form>
        <?php endif; ?>

        <p class="auth-link-container">
            <?php if ($show_form): ?>
               Already registered? <a href="index.php">[ LOGIN ]</a>
            <?php elseif (strpos($registration_success, 'LOGIN_HERE') === false): ?>
                Try to <a href="index.php">[ LOGIN ]</a> or attempt registration again.
            <?php endif; ?>
        </p>
    </div>
</body>
</html>