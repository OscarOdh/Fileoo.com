<?php
// fileoo.com/download.php
// Serves files from the protected upload directory (outside the webroot).
// Three modes:
//   ?t=<token>            public, anonymous download via a share link (capped)
//   ?file_id / ?file      authenticated download/inline view (owner or shared)
//   ...&thumb=1           authenticated inline thumbnail (cached PNG)
require_once __DIR__ . '/config.php';

/**
 * Stream a file to the client and exit. $inline controls the disposition;
 * $forceMime, when given, overrides the stored MIME (used to pin images to a
 * trusted type). nosniff prevents the browser re-interpreting the bytes.
 */
function serve_file($path, $download_name, $mime, $size, $inline) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Description: File Transfer');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($download_name) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . $size);
    readfile($path);
    exit;
}

/**
 * Render the passcode gate for a protected public link and exit. Self-contained
 * HTML (no external assets) so it works on the upload subdomain under the strict
 * CSP; posts the passcode back to this same URL as field "p".
 */
function render_passcode_prompt($token, $filename, $error = '') {
    if (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($error !== '' ? 401 : 200);
    header('Content-Type: text/html; charset=UTF-8');
    $t  = htmlspecialchars($token, ENT_QUOTES);
    $fn = htmlspecialchars($filename, ENT_QUOTES);
    $err = $error !== '' ? '<p class="err">' . htmlspecialchars($error, ENT_QUOTES) . '</p>' : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>[ PROTECTED_FILE ]</title>'
       . '<style>'
       . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'background:#0a0a0f;color:#e6f5f2;font-family:Consolas,"Courier New",monospace;padding:20px;}'
       . '.box{width:100%;max-width:380px;background:#12121a;border:1px solid #00e0c0;border-radius:10px;'
       . 'padding:30px 26px;box-shadow:0 0 40px rgba(0,224,192,.18);text-align:center;}'
       . 'h1{margin:0 0 6px;font-size:1.4em;color:#fff;letter-spacing:1px;}'
       . '.sub{margin:0 0 20px;font-size:.9em;color:#8fb3ad;word-break:break-word;}'
       . '.err{margin:0 0 14px;color:#ff6b8a;font-size:.85em;}'
       . 'input{width:100%;padding:12px 14px;margin-bottom:14px;background:#08080c;color:#00e0c0;'
       . 'border:1px solid #1f6f63;border-radius:6px;font-size:1em;box-sizing:border-box;font-family:inherit;}'
       . 'input:focus{outline:none;border-color:#00e0c0;box-shadow:0 0 8px rgba(0,224,192,.4);}'
       . 'button{width:100%;padding:12px;background:linear-gradient(45deg,#00e0c0,#00b0a0);color:#04110f;'
       . 'border:none;border-radius:6px;font-weight:bold;font-size:1em;text-transform:uppercase;letter-spacing:1px;cursor:pointer;}'
       . 'button:hover{filter:brightness(1.1);}'
       . '</style></head><body><div class="box">'
       . '<h1>&#128274; Protected File</h1>'
       . '<p class="sub">&ldquo;' . $fn . '&rdquo; is passcode-protected.</p>'
       . $err
       . '<form method="post" action="download.php?t=' . $t . '">'
       . '<input type="password" name="p" placeholder="Enter passcode" autofocus autocomplete="off">'
       . '<button type="submit">Unlock &amp; Download</button>'
       . '</form></div></body></html>';
    exit;
}

// --- MODE 1: Public share-link download (no login required) ---------------
if (isset($_GET['t']) && $_GET['t'] !== '') {
    $stmt = db_query(
        "SELECT sl.share_link_id, sl.download_count, sl.max_downloads, sl.expires_at, sl.passcode_hash,
                uf.stored_filename, uf.original_filename, uf.filetype, uf.filesize
         FROM share_links sl
         JOIN user_files uf ON uf.file_id = sl.file_id
         WHERE sl.token = ? LIMIT 1",
        [$_GET['t']]
    );
    $link = $stmt->fetch();

    if (!$link) {
        http_response_code(404);
        die("This link is invalid.");
    }
    // expires_at / max_downloads are nullable: NULL means "never" / "unlimited".
    if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) {
        http_response_code(410);
        die("This link has expired.");
    }
    if ($link['max_downloads'] !== null && (int) $link['download_count'] >= (int) $link['max_downloads']) {
        http_response_code(410);
        die("This link has reached its download limit.");
    }

    $filepath = UPLOAD_DIR . $link['stored_filename'];
    if (!is_file($filepath) || !is_readable($filepath)) {
        http_response_code(404);
        die("File not found on the server.");
    }

    // Passcode gate — show the prompt on GET (or on a wrong passcode) and only
    // count/serve once the correct passcode arrives via POST. This also keeps
    // link-preview bots from consuming a protected link's download budget.
    if (!empty($link['passcode_hash'])) {
        $provided = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (string) ($_POST['p'] ?? '') : null;
        if ($provided === null || $provided === '') {
            render_passcode_prompt($_GET['t'], $link['original_filename']);
        }
        if (!password_verify($provided, $link['passcode_hash'])) {
            render_passcode_prompt($_GET['t'], $link['original_filename'], 'Incorrect passcode. Try again.');
        }
    }

    // Count the download first, then stream as a forced attachment.
    db_query("UPDATE share_links SET download_count = download_count + 1 WHERE share_link_id = ?", [$link['share_link_id']]);
    serve_file($filepath, $link['original_filename'], $link['filetype'] ?: 'application/octet-stream', (int) $link['filesize'], false);
}

// --- MODE 4: Bulk Download as ZIP (2+ selected files) ---------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_download') {
    $current_user_id = (int) ($_SESSION['id'] ?? 0);
    $current_user_email = $_SESSION['email'] ?? '';
    if (!$current_user_id) {
        http_response_code(403);
        die("Authentication required.");
    }
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        die("Invalid CSRF token.");
    }

    $file_ids = $_POST['file_ids'] ?? [];
    if (!is_array($file_ids) || count($file_ids) < 2) {
        http_response_code(400);
        die("Please select at least 2 files to download as a ZIP archive.");
    }
    if (count($file_ids) > 50) {
        http_response_code(400);
        die("Maximum 50 files can be downloaded at once.");
    }

    $clean_ids = array_values(array_unique(array_filter(array_map('intval', $file_ids))));
    if (count($clean_ids) < 2) {
        http_response_code(400);
        die("Please select at least 2 valid files.");
    }

    $in_placeholders = implode(',', array_fill(0, count($clean_ids), '?'));
    $sql = "
        SELECT uf.file_id, uf.stored_filename, uf.original_filename, uf.filesize
        FROM user_files uf
        LEFT JOIN file_shares fs_user ON fs_user.file_id = uf.file_id AND fs_user.share_type = 'user' AND fs_user.shared_with_user_id = ?
        LEFT JOIN file_shares fs_email ON fs_email.file_id = uf.file_id AND fs_email.share_type = 'email' AND fs_email.shared_with_email = ?
        WHERE uf.file_id IN ($in_placeholders)
        AND (uf.user_id = ? OR fs_user.share_id IS NOT NULL OR fs_email.share_id IS NOT NULL)
    ";
    $params = array_merge([$current_user_id, $current_user_email], $clean_ids, [$current_user_id]);
    $stmt = db_query($sql, $params);
    $files = $stmt->fetchAll();

    if (empty($files) || count($files) < 1) {
        http_response_code(404);
        die("No accessible files found to download.");
    }

    // Enforce 200 MB maximum safety limit
    $total_size = 0;
    foreach ($files as $f) {
        $total_size += (int) $f['filesize'];
    }
    if ($total_size > (200 * 1024 * 1024)) {
        http_response_code(400);
        die("Selected files exceed the 200 MB limit (" . round($total_size / (1024 * 1024), 1) . " MB). Please select fewer files.");
    }

    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        die("ZipArchive extension is not available on the server.");
    }

    $temp_zip = tempnam(sys_get_temp_dir(), 'foozip_');
    register_shutdown_function(function() use ($temp_zip) {
        if (is_file($temp_zip)) {
            @unlink($temp_zip);
        }
    });

    $zip = new ZipArchive();
    if ($zip->open($temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        die("Failed to create ZIP archive.");
    }

    // Collision handling: deduplicate same-named files inside the archive
    $used_names = [];
    foreach ($files as $f) {
        $real_path = UPLOAD_DIR . $f['stored_filename'];
        if (!is_file($real_path) || !is_readable($real_path)) {
            continue;
        }
        $orig_name = $f['original_filename'] ?: ('file_' . $f['file_id']);
        $filename = $orig_name;
        $name_part = pathinfo($orig_name, PATHINFO_FILENAME);
        $ext_part = pathinfo($orig_name, PATHINFO_EXTENSION);
        $ext_suffix = ($ext_part !== '') ? ('.' . $ext_part) : '';

        $c = 1;
        while (in_array(strtolower($filename), $used_names, true)) {
            $filename = $name_part . ' (' . $c . ')' . $ext_suffix;
            $c++;
        }
        $used_names[] = strtolower($filename);
        $zip->addFile($real_path, $filename);
    }

    $zip->close();

    if (!is_file($temp_zip) || filesize($temp_zip) === 0) {
        http_response_code(500);
        die("Failed to compile ZIP archive.");
    }

    $zip_filename = 'fileoo_files_' . date('Y-m-d-hA-i') . '.zip';
    $zip_size = filesize($temp_zip);

    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Description: File Transfer');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . $zip_size);

    $fp = fopen($temp_zip, 'rb');
    if ($fp) {
        while (!feof($fp)) {
            echo fread($fp, 65536);
            flush();
        }
        fclose($fp);
    }
    exit;
}

// --- MODE 2/3: Authenticated access (owner or shared-with) ----------------
$current_user_id = $_SESSION['id'] ?? 0;
$current_user_email = $_SESSION['email'] ?? '';

$permission_sql_base = "
    SELECT uf.file_id, uf.stored_filename, uf.original_filename, uf.filetype, uf.filesize, uf.thumb_filename
    FROM user_files uf
    LEFT JOIN file_shares fs_user ON fs_user.file_id = uf.file_id AND fs_user.share_type = 'user' AND fs_user.shared_with_user_id = ?
    LEFT JOIN file_shares fs_email ON fs_email.file_id = uf.file_id AND fs_email.share_type = 'email' AND fs_email.shared_with_email = ?
    WHERE %s
    AND (uf.user_id = ? OR fs_user.share_id IS NOT NULL OR fs_email.share_id IS NOT NULL)
    LIMIT 1";

if (isset($_GET['file'])) {
    $sql = sprintf($permission_sql_base, "uf.stored_filename = ?");
    $stmt = db_query($sql, [$current_user_id, $current_user_email, $_GET['file'], $current_user_id]);
    $file_record = $stmt->fetch();
} elseif (isset($_GET['file_id'])) {
    $sql = sprintf($permission_sql_base, "uf.file_id = ?");
    $stmt = db_query($sql, [$current_user_id, $current_user_email, intval($_GET['file_id']), $current_user_id]);
    $file_record = $stmt->fetch();
} else {
    http_response_code(400);
    die("Bad Request: Missing parameters.");
}

if ($file_record) {
    // MODE 3: thumbnail — serve the cached PNG inline (never the original bytes).
    if (isset($_GET['thumb'])) {
        $thumb_filename = $file_record['thumb_filename'];
        if (empty($thumb_filename)) {
            $stored_ext = strtolower(pathinfo($file_record['stored_filename'], PATHINFO_EXTENSION));
            if (array_key_exists($stored_ext, image_mime_map())) {
                $thumb_name = $file_record['stored_filename'] . '.png';
                $thumbpath = THUMB_DIR . $thumb_name;
                $filepath = UPLOAD_DIR . $file_record['stored_filename'];
                if (is_file($filepath) && is_readable($filepath)) {
                    if (create_thumbnail($filepath, $thumbpath, $stored_ext, 400)) {
                        $thumb_filename = 'thumbs/' . $thumb_name;
                        db_query("UPDATE user_files SET thumb_filename = ? WHERE file_id = ?", [$thumb_filename, $file_record['file_id']]);
                    }
                }
            }
        }

        if (!empty($thumb_filename)) {
            $thumbpath = UPLOAD_DIR . $thumb_filename;
            if (is_file($thumbpath) && is_readable($thumbpath)) {
                serve_file($thumbpath, 'thumb.png', 'image/png', filesize($thumbpath), true);
            }
        }
        http_response_code(404);
        die("Thumbnail not found.");
    }

    $filepath = UPLOAD_DIR . $file_record['stored_filename'];
    if (file_exists($filepath) && is_readable($filepath)) {
        // Inline "view" is allowed ONLY for a strict allowlist of raster image
        // formats (browsers never execute script from these). Everything else
        // stays a forced download. The Content-Type for inline images comes from
        // the trusted stored extension, not the client-supplied filetype.
        $inline_image_types = image_mime_map();
        $stored_ext = strtolower(pathinfo($file_record['stored_filename'], PATHINFO_EXTENSION));
        $view_inline = isset($_GET['view']) && isset($inline_image_types[$stored_ext]);

        if ($view_inline) {
            serve_file($filepath, $file_record['original_filename'], $inline_image_types[$stored_ext], (int) $file_record['filesize'], true);
        } else {
            serve_file($filepath, $file_record['original_filename'], $file_record['filetype'] ?: 'application/octet-stream', (int) $file_record['filesize'], false);
        }
    }
    http_response_code(404);
    die("File not found on the server.");
}

// No matching record the requester may access: prompt login or deny.
if (!$current_user_id) {
    header("Location: index.php");
    exit;
}
http_response_code(403);
die("Access Denied: You do not have permission to access this file.");
