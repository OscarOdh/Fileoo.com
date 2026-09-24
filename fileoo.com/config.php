<?php
// fileoo.com/config.php

// 1. Load environment variables from ../.env (outside webroot) or local .env
$env_paths = [__DIR__ . '/../.env', __DIR__ . '/.env'];
foreach ($env_paths as $env_path) {
    if (file_exists($env_path)) {
        $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if (preg_match('/^"(.*)"$/', $value, $matches) || preg_match("/^'(.*)'$/", $value, $matches)) {
                    $value = $matches[1];
                }
                putenv("$name=$value");
                $_ENV[$name] = $value;
            }
        }
        break;
    }
}

// 2. Required configuration — fail loudly rather than fall back to secrets baked into source
$required_env = ['DB_SERVER', 'DB_USERNAME', 'DB_PASSWORD', 'DB_NAME', 'TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET_KEY'];
foreach ($required_env as $required_key) {
    if (getenv($required_key) === false || getenv($required_key) === '') {
        error_log("Configuration error: missing required environment variable '" . $required_key . "'.");
        http_response_code(500);
        die("Server configuration error. Please contact your system administrator.");
    }
}

define('DB_SERVER', getenv('DB_SERVER'));
define('DB_USERNAME', getenv('DB_USERNAME'));
define('DB_PASSWORD', getenv('DB_PASSWORD'));
define('DB_NAME', getenv('DB_NAME'));
define('TURNSTILE_SITE_KEY', getenv('TURNSTILE_SITE_KEY'));
define('TURNSTILE_SECRET_KEY', getenv('TURNSTILE_SECRET_KEY'));
define('APP_URL', getenv('APP_URL') ?: '');

// Keep in sync with upload_max_filesize / post_max_size in .user.ini (150M)
define('MAX_FILE_SIZE', 150 * 1024 * 1024);

// Default per-user storage quota in MB. The effective quota lives per user in
// users.quota_mb (so individual accounts can be sold more space later); this
// value is only the fallback when that column is missing or NULL.
define('DEFAULT_STORAGE_QUOTA_MB', 1000);

// Resolve secure UPLOAD_DIR (outside the public web root)
$configured_upload_dir = getenv('UPLOAD_DIR') ?: '../fileoo_uploads';
// Resolve it relative to this config file's directory if it is relative
if (substr($configured_upload_dir, 0, 1) !== '/' && substr($configured_upload_dir, 1, 2) !== ':\\') {
    define('UPLOAD_DIR', realpath(__DIR__) . '/' . rtrim($configured_upload_dir, '/') . '/');
} else {
    define('UPLOAD_DIR', rtrim($configured_upload_dir, '/') . '/');
}

// Ensure the upload directory exists and is protected
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
// Add .htaccess inside uploads directory to double-protect it
$htaccess_path = UPLOAD_DIR . '.htaccess';
if (!file_exists($htaccess_path)) {
    file_put_contents($htaccess_path, "Require all denied\n");
}

// Thumbnail cache lives under uploads (so the same .htaccess denies direct
// access; thumbs are served only through download.php after a permission check).
define('THUMB_DIR', UPLOAD_DIR . 'thumbs/');
if (!file_exists(THUMB_DIR)) {
    @mkdir(THUMB_DIR, 0755, true);
}

// 3. Connect to Database using PDO
try {
    $conn = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please contact your system administrator.");
}

// 4. Auto-create login_attempts table if not exists (see SQL/login_attempts.sql)
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
      `ip_address` varchar(45) NOT NULL,
      `attempt_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY `idx_ip_time` (`ip_address`, `attempt_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (PDOException $e) {
    error_log("Failed to auto-create login_attempts table: " . $e->getMessage());
}

// 5. Set character set and default timezone to UTC
$conn->exec("SET time_zone = '+00:00'");
date_default_timezone_set('UTC');

// 6. Subdomain and CORS configuration for upload.fileoo.com
if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === 'upload.fileoo.com') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (preg_match('/^https?:\/\/(www\.)?fileoo\.com$/i', $origin)) {
        header("Access-Control-Allow-Origin: " . $origin);
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Authorization, Cache-Control, Pragma");
    }
    
    // Respond to preflight OPTIONS request immediately
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Define the upload host URL
if (isset($_SERVER['HTTP_HOST']) && preg_match('/fileoo\.com$/i', $_SERVER['HTTP_HOST'])) {
    define('UPLOAD_BASE_URL', 'https://upload.fileoo.com');
} else {
    // Local/development fallback
    define('UPLOAD_BASE_URL', '');
}

// 7. Session management with hardened cookie settings
// (session.cookie_httponly / use_strict_mode are also set in .user.ini; the
//  runtime settings below win and make 'secure' dynamic so local HTTP works.)
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    
    // Set a unified session save path to ensure session sharing between subdomains
    $session_save_dir = dirname(__DIR__) . '/php_sessions';
    if (!file_exists($session_save_dir)) {
        @mkdir($session_save_dir, 0700, true);
    }
    if (is_writable($session_save_dir)) {
        session_save_path($session_save_dir);
    }
    
    // Determine session cookie domain to share between fileoo.com and upload.fileoo.com
    $cookie_domain = '';
    if (isset($_SERVER['HTTP_HOST'])) {
        $host = explode(':', $_SERVER['HTTP_HOST'])[0];
        if (strpos($host, '.') !== false && !filter_var($host, FILTER_VALIDATE_IP)) {
            if (preg_match('/fileoo\.com$/i', $host)) {
                $cookie_domain = '.fileoo.com';
            } else {
                $parts = explode('.', $host);
                if (count($parts) >= 2) {
                    $cookie_domain = '.' . implode('.', array_slice($parts, -2));
                }
            }
        }
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => $cookie_domain,
        'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}


// 8. CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- GLOBAL UTILITY FUNCTIONS ---

/**
 * Execute a PDO query with parameters.
 *
 * By default a query failure logs the details server-side and stops with a
 * generic message. Pass $throw = true inside transactions so the caller's
 * catch block can roll back before anything is shown to the user.
 */
function db_query($sql, $params = [], $throw = false) {
    global $conn;
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database Query Exception: " . $e->getMessage() . " | Query: " . $sql . " | Params: " . json_encode($params));
        if ($throw) {
            throw $e;
        }
        // Suppress detailed schema disclosures
        die("An unexpected database system error occurred. Details have been logged server-side.");
    }
}

/**
 * Verify a CSRF token securely using hash_equals
 */
function verify_csrf_token($token) {
    return !empty($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate a cryptographically secure random hexadecimal string
 */
function generate_random_string($length = 16) {
    return bin2hex(random_bytes(intdiv($length, 2)));
}

/**
 * Format bytes into human readable format
 */
function format_size($size) {
    $mod = 1024;
    $units = explode(' ', 'B KB MB GB TB PB');
    for ($i = 0; $size > $mod && $i < count($units) - 1; $i++) {
        $size /= $mod;
    }
    return round($size, 1) . ' ' . $units[$i];
}

/**
 * Clean output buffer and send JSON response
 */
function send_json_response($data, $statusCode = 200) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Stash flash messages in the session and redirect (PRG pattern).
 * Messages are plain text; the view escapes them at output time.
 */
function redirect_with_messages(array $success, array $errors, $location = 'index.php') {
    $_SESSION['action_success_messages'] = $success;
    $_SESSION['action_error_messages'] = $errors;
    header("Location: " . $location);
    exit;
}

/**
 * Best-effort client IP.
 *
 * Behind Cloudflare, REMOTE_ADDR is a shared Cloudflare edge IP, so rate
 * limiting on it would lock out unrelated visitors. Set
 * TRUST_CF_CONNECTING_IP=1 in .env ONLY when the origin is reachable
 * exclusively through Cloudflare (otherwise the header can be spoofed).
 */
function client_ip() {
    if (getenv('TRUST_CF_CONNECTING_IP') === '1' && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Canonical map of safe raster-image extensions to their MIME type. Kept in one
 * place so inline viewing (download.php), thumbnailing and the dashboard agree
 * on exactly which uploads are treated as previewable images. SVG is absent by
 * design — it can carry script and must never render inline.
 */
function image_mime_map() {
    return [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'bmp'  => 'image/bmp',
        'webp' => 'image/webp',
    ];
}

/**
 * Best-effort thumbnail generator. Downscales a raster image so its longest
 * side is at most $maxDim and writes a PNG to $destPath. Returns true on
 * success; returns false (without throwing) if GD or the source format is
 * unavailable, so a failed thumbnail never blocks an upload.
 */
function create_thumbnail($srcPath, $destPath, $ext, $maxDim = 400) {
    if (!function_exists('imagecreatetruecolor') || !function_exists('getimagesize')) {
        return false;
    }

    if (!is_file($srcPath) || !is_readable($srcPath)) {
        return false;
    }

    // Pre-check dimensions using getimagesize() to prevent GD allocation DoS / decompression bombs (> 50 MP).
    $imgSize = @getimagesize($srcPath);
    if (!$imgSize || empty($imgSize[0]) || empty($imgSize[1])) {
        return false;
    }
    if ($imgSize[0] * $imgSize[1] > 50000000) {
        return false;
    }

    $ext = strtolower($ext);
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $src = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($srcPath) : false;
            break;
        case 'png':
            $src = function_exists('imagecreatefrompng') ? @imagecreatefrompng($srcPath) : false;
            break;
        case 'gif':
            $src = function_exists('imagecreatefromgif') ? @imagecreatefromgif($srcPath) : false;
            break;
        case 'webp':
            $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false;
            break;
        case 'bmp':
            $src = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($srcPath) : false;
            break;
        default:
            return false;
    }
    if (!$src) {
        return false;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return false;
    }

    $scale = min(1, $maxDim / max($w, $h));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));

    $dst = imagecreatetruecolor($tw, $th);
    // Preserve transparency (PNG/GIF/WEBP) when downscaling.
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $tw, $th, $transparent);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

    $ok = @imagepng($dst, $destPath, 6);
    imagedestroy($src);
    imagedestroy($dst);
    return (bool) $ok;
}

/**
 * Absolute base URL for the current request (scheme + host + app path, no
 * trailing slash). Used to build links that must work from an email or an
 * anonymous browser tab.
 *
 * If APP_URL is defined in .env (e.g. https://fileoo.com), it is used as the
 * authoritative canonical base to prevent Host Header Injection attacks.
 */
function app_base_url() {
    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = isset($_SERVER['PHP_SELF']) ? str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])) : '';
    if ($base === '/' || $base === '.') {
        $base = '';
    }
    return $scheme . $host . rtrim($base, '/');
}

/**
 * Send an email via SMTP client using sockets.
 *
 * Credentials come from the environment (.env), never from source:
 *   SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM_EMAIL
 * If the username/password are not configured, mail is skipped (returns false)
 * so a missing mail setup degrades gracefully instead of leaking secrets.
 */
function send_mail_smtp($to, $subject, $body, $from_name = 'FILEOO') {
    $host = getenv('SMTP_HOST') ?: 'mail.fileoo.com';
    $port = (int) (getenv('SMTP_PORT') ?: 465);
    $username = getenv('SMTP_USERNAME') ?: '';
    $password = getenv('SMTP_PASSWORD') ?: '';
    $from_email = getenv('SMTP_FROM_EMAIL') ?: ($username ?: 'help@fileoo.com');

    if ($username === '' || $password === '') {
        error_log('[fileoo] SMTP credentials not configured (set SMTP_USERNAME / SMTP_PASSWORD in .env).');
        return false;
    }

    // We try a sequence of connection targets:
    // 1. Resolved IPv4 of $host.
    // 2. The $host string directly.
    // 3. 'localhost' (as cPanel servers host SMTP locally and mail.domain.com is often proxied by Cloudflare).
    // 4. '127.0.0.1'.
    $resolved_ip = gethostbyname($host);
    $targets = [];
    if ($resolved_ip !== $host) {
        $targets[] = $resolved_ip;
    }
    $targets[] = $host;
    $targets[] = 'localhost';
    $targets[] = '127.0.0.1';

    // Filter duplicates
    $targets = array_unique($targets);

    $socket = null;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]
    ]);

    foreach ($targets as $target) {
        $socket_url = "ssl://$target:$port";
        // Connect with a short timeout to fail fast and move to local fallbacks
        $socket = @stream_socket_client($socket_url, $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $context);
        if ($socket) {
            break;
        }
    }

    if (!$socket) {
        error_log("[fileoo] All SMTP connection attempts failed. Note: If using Cloudflare, make sure the DNS record for mail.fileoo.com is set to 'DNS only' (grey cloud) in the Cloudflare panel. Otherwise, check if your hosting firewall blocks outgoing port 465.");
        return false;
    }

    $expect = function($code) use ($socket) {
        $response = '';
        while (($line = fgets($socket, 512)) !== false) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        $resp_code = substr($response, 0, 3);
        if ($resp_code !== (string)$code) {
            error_log("SMTP Error: expected $code, got response: " . trim($response));
            return false;
        }
        return true;
    };

    // 1. Read greeting (220)
    if (!$expect(220)) { fclose($socket); return false; }

    // 2. EHLO
    fwrite($socket, "EHLO fileoo.com\r\n");
    if (!$expect(250)) { fclose($socket); return false; }

    // 3. AUTH LOGIN
    fwrite($socket, "AUTH LOGIN\r\n");
    if (!$expect(334)) { fclose($socket); return false; }

    // 4. Send Username
    fwrite($socket, base64_encode($username) . "\r\n");
    if (!$expect(334)) { fclose($socket); return false; }

    // 5. Send Password
    fwrite($socket, base64_encode($password) . "\r\n");
    if (!$expect(235)) { fclose($socket); return false; }

    // 6. MAIL FROM
    fwrite($socket, "MAIL FROM:<$from_email>\r\n");
    if (!$expect(250)) { fclose($socket); return false; }

    // 7. RCPT TO
    fwrite($socket, "RCPT TO:<$to>\r\n");
    if (!$expect(250)) { fclose($socket); return false; }

    // 8. DATA
    fwrite($socket, "DATA\r\n");
    if (!$expect(354)) { fclose($socket); return false; }

    // 9. Send headers and body
    // Ensure CRLF line endings
    $body = str_replace("\r", "", $body);
    $body = str_replace("\n", "\r\n", $body);

    $headers = [
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
        "Date: " . date('r'),
        "Message-ID: <" . uniqid('', true) . "@fileoo.com>",
        "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <$from_email>",
        "To: <$to>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?="
    ];

    $email_data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
    fwrite($socket, $email_data);
    if (!$expect(250)) { fclose($socket); return false; }

    // 10. QUIT
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return true;
}
