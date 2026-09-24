<?php
// fileoo.com/dashboard.php — dashboard controller. Loaded through index.php
// only when the user is authenticated.
if (!defined('FILEOO_APP')) {
    header("Location: index.php");
    exit;
}

$current_user_id = $_SESSION["id"];
$current_user_username = $_SESSION['username'] ?? '';
$current_user_email = $_SESSION['email'] ?? '';

// Determine current CSS file + storage quota in one lookup. The quota is
// stored per user in MB (users.quota_mb) so accounts can be upgraded
// individually; the column may not exist yet, so fall back gracefully.
$current_css_file_from_db = 'css/style_Matrix.css';
$storage_quota = DEFAULT_STORAGE_QUOTA_MB * 1024 * 1024;

try {
    $stmt_user = $conn->prepare("SELECT selected_css, quota_mb FROM users WHERE id = ? LIMIT 1");
    $stmt_user->execute([$current_user_id]);
    $user_row = $stmt_user->fetch();
} catch (PDOException $e) {
    // quota_mb column absent — read just the CSS preference.
    $stmt_user = db_query("SELECT selected_css FROM users WHERE id = ? LIMIT 1", [$current_user_id]);
    $user_row = $stmt_user->fetch();
}
if ($user_row && !empty($user_row['selected_css'])) {
    $current_css_file_from_db = $user_row['selected_css'];
}
if ($user_row && isset($user_row['quota_mb']) && $user_row['quota_mb'] !== null) {
    $storage_quota = ((int) $user_row['quota_mb']) * 1024 * 1024;
}

/**
 * Total bytes the current user is storing (their own files only — files shared
 * with them count against the owner's quota, not theirs).
 */
function fileoo_storage_used($user_id) {
    $stmt = db_query("SELECT COALESCE(SUM(filesize), 0) AS used FROM user_files WHERE user_id = ?", [$user_id]);
    $row = $stmt->fetch();
    return (int) ($row['used'] ?? 0);
}

if (!isset($_SESSION['current_css_file']) || $_SESSION['current_css_file'] !== $current_css_file_from_db) {
    $_SESSION['current_css_file'] = $current_css_file_from_db;
}

// Sanitize path for loading CSS from css/ folder
$current_css_file = $_SESSION['current_css_file'];
if (strpos($current_css_file, 'css/') !== 0) {
    $current_css_file = 'css/' . basename($current_css_file);
}
$current_css_file = htmlspecialchars($current_css_file);

// Cache-busting CSS version based on file modification time
$css_version = file_exists($current_css_file) ? filemtime($current_css_file) : '1.0';

// Retrieve flash messages that will be displayed on page load
$display_success_messages = $_SESSION['action_success_messages'] ?? [];
$display_error_messages = $_SESSION['action_error_messages'] ?? [];
unset($_SESSION['action_success_messages'], $_SESSION['action_error_messages']);

// --- ACTION HANDLING ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    // Validate CSRF token for all state-changing POST requests
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        if ($is_ajax) {
            send_json_response(['success' => false, 'message' => 'SECURITY_VIOLATION: CSRF token verification failed.'], 403);
        }
        redirect_with_messages([], ['SECURITY_VIOLATION: CSRF token verification failed.']);
    }

    // --- UPLOAD FILE LOGIC ---
    if (isset($_FILES["fileToUpload"]) && isset($_POST['upload_submitted'])) {
        $temp_success_messages = [];
        $temp_error_messages = [];
        $file_data = $_FILES["fileToUpload"];

        if ($file_data["error"] !== UPLOAD_ERR_OK) {
            switch ($file_data["error"]) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $temp_error_messages[] = "UPLOAD_REJECTED: File exceeds size limits. Limit: " . ini_get('upload_max_filesize');
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $temp_error_messages[] = "UPLOAD_ERROR: File transmission interrupted.";
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $temp_error_messages[] = "UPLOAD_ERROR: No file datastream detected.";
                    break;
                default:
                    $temp_error_messages[] = "SYSTEM_ERROR: Unknown upload anomaly (Code: " . $file_data["error"] . ")";
                    break;
            }
        } else {
            $originalClientFileName = basename($file_data["name"]);
            $fileTmpPath = $file_data["tmp_name"];
            $fileSize = $file_data["size"];
            $fileMimeType = mime_content_type($fileTmpPath);
            $pathInfo = pathinfo($originalClientFileName);
            $clientFileNameBase = $pathInfo['filename'];
            $clientFileExtension = isset($pathInfo['extension']) ? strtolower($pathInfo['extension']) : '';

            // Strict file extension allowlist.
            // NOTE: 'svg' is deliberately excluded — SVG files can embed script
            // and must never be added back unless downloads stay attachment-only.
            $allowed_extensions = [
                'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp',
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
                'txt', 'rtf', 'csv', 'json', 'xml', 'md',
                'zip', 'rar', 'tar', 'gz', '7z',
                'mp3', 'wav', 'ogg', 'm4a',
                'mp4', 'avi', 'mov', 'mkv', 'webm'
            ];

            if ($fileSize > MAX_FILE_SIZE) {
                $temp_error_messages[] = "UPLOAD_REJECTED: File (" . format_size($fileSize) . ") exceeds limit of " . format_size(MAX_FILE_SIZE) . ".";
            }
            if (!in_array($clientFileExtension, $allowed_extensions)) {
                $temp_error_messages[] = "UPLOAD_REJECTED: File extension (." . $clientFileExtension . ") is not allowed.";
            }
            if (!is_writable(UPLOAD_DIR)) {
                $temp_error_messages[] = "SYSTEM_FAULT: Upload directory is write-protected.";
            }
            // Enforce the per-user storage quota. Usage is re-read per request so
            // it stays correct across a sequential multi-file batch upload.
            if (empty($temp_error_messages)) {
                $used_bytes = fileoo_storage_used($current_user_id);
                if ($used_bytes + $fileSize > $storage_quota) {
                    $remaining = max(0, $storage_quota - $used_bytes);
                    $temp_error_messages[] = "UPLOAD_REJECTED: Not enough storage. This file is " . format_size($fileSize)
                        . " but only " . format_size($remaining) . " of your " . format_size($storage_quota) . " quota remains.";
                }
            }

            if (empty($temp_error_messages)) {
                $safeBaseName = preg_replace('/[^a-zA-Z0-9_.\-\s]/u', '_', $clientFileNameBase);
                $safeBaseName = preg_replace('/[\s_]+/', '_', $safeBaseName);
                $safeBaseName = trim($safeBaseName, '_-');
                if (empty($safeBaseName)) {
                    $safeBaseName = 'file';
                }

                $randomSuffix = generate_random_string(16);
                $stored_filename_on_server = $safeBaseName . "_" . $randomSuffix . (!empty($clientFileExtension) ? '.' . $clientFileExtension : '');
                $targetPath = UPLOAD_DIR . $stored_filename_on_server;

                $counter = 1;
                while (file_exists($targetPath)) {
                    $stored_filename_on_server = $safeBaseName . "_" . $randomSuffix . "_" . $counter . (!empty($clientFileExtension) ? '.' . $clientFileExtension : '');
                    $targetPath = UPLOAD_DIR . $stored_filename_on_server;
                    $counter++;
                    if ($counter > 20) {
                        $temp_error_messages[] = "Error generating unique filename.";
                        break;
                    }
                }

                if (empty($temp_error_messages)) {
                    if (move_uploaded_file($fileTmpPath, $targetPath)) {
                        // Best-effort thumbnail for raster images (see create_thumbnail()).
                        // A failure here just means no preview icon for this file.
                        $thumb_relpath = null;
                        if (array_key_exists($clientFileExtension, image_mime_map())) {
                            $thumb_name = $stored_filename_on_server . '.png';
                            if (create_thumbnail($targetPath, THUMB_DIR . $thumb_name, $clientFileExtension, 400)) {
                                $thumb_relpath = 'thumbs/' . $thumb_name;
                            }
                        }
                        $sql_insert = "INSERT INTO user_files (user_id, original_filename, stored_filename, filesize, filetype, thumb_filename) VALUES (?, ?, ?, ?, ?, ?)";
                        db_query($sql_insert, [$current_user_id, $originalClientFileName, $stored_filename_on_server, $fileSize, $fileMimeType, $thumb_relpath]);
                        $temp_success_messages[] = "\"" . $originalClientFileName . "\" uploaded successfully.";
                    } else {
                        $temp_error_messages[] = "SYSTEM_FAULT: Failed to move uploaded file.";
                    }
                }
            }
        }
        if ($is_ajax) {
            // Progress-bar (XHR) upload: return THIS file's result to the client,
            // which uploads files one request at a time, accumulates a summary,
            // and reloads once at the end. We deliberately do NOT stash flash
            // messages here — sequential requests would overwrite each other, and
            // it would double up with the client-rendered batch summary.
            $combined_message = trim(implode("\n", array_merge($temp_success_messages, $temp_error_messages)));
            send_json_response(['success' => empty($temp_error_messages), 'message' => $combined_message]);
        }
        redirect_with_messages($temp_success_messages, $temp_error_messages);
    }

    // --- SHARE FILE LOGIC ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'share_file_modal' && isset($_POST['modal_file_id_to_share']) && isset($_POST['modal_share_submit'])) {
        $file_id_to_share = intval($_POST['modal_file_id_to_share']);
        $share_option = $_POST['modal_share_type'] ?? 'none';
        $share_identifier = trim($_POST['modal_share_identifier'] ?? '');
        $temp_success_messages = [];
        $temp_error_messages = [];

        $stmt_owner = db_query("SELECT original_filename FROM user_files WHERE file_id = ? AND user_id = ? LIMIT 1", [$file_id_to_share, $current_user_id]);
        $owner_file = $stmt_owner->fetch();

        if ($owner_file) {
            $original_filename_for_share = $owner_file['original_filename'];

            if ($share_option === 'specific_user_email') {
                if (empty($share_identifier)) {
                    $temp_error_messages[] = "Please enter a username or email to share with.";
                } else {
                    $shared_with_user_id_db = null;
                    $shared_with_email_db = null;
                    $actual_share_type_db = 'user';

                    $stmt_find = db_query("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1", [$share_identifier, $share_identifier]);
                    $found_user = $stmt_find->fetch();

                    if ($found_user) {
                        if ($found_user['id'] == $current_user_id) {
                            $temp_error_messages[] = "You cannot share a file with yourself.";
                        } else {
                            $shared_with_user_id_db = $found_user['id'];
                        }
                    } else {
                        if (filter_var($share_identifier, FILTER_VALIDATE_EMAIL)) {
                            $shared_with_email_db = $share_identifier;
                            $actual_share_type_db = 'email';
                        } else {
                            $temp_error_messages[] = "User \"" . $share_identifier . "\" not found.";
                        }
                    }

                    if (empty($temp_error_messages) && ($shared_with_user_id_db || $shared_with_email_db)) {
                        if ($shared_with_user_id_db) {
                            $stmt_dup = db_query("SELECT share_id FROM file_shares WHERE file_id = ? AND shared_by_user_id = ? AND shared_with_user_id = ? LIMIT 1", [$file_id_to_share, $current_user_id, $shared_with_user_id_db]);
                        } else {
                            $stmt_dup = db_query("SELECT share_id FROM file_shares WHERE file_id = ? AND shared_by_user_id = ? AND shared_with_email = ? LIMIT 1", [$file_id_to_share, $current_user_id, $shared_with_email_db]);
                        }

                        if ($stmt_dup->fetch()) {
                            $temp_error_messages[] = "\"" . $original_filename_for_share . "\" is already shared with \"" . $share_identifier . "\".";
                        } else {
                            db_query(
                                "INSERT INTO file_shares (file_id, shared_by_user_id, shared_with_user_id, shared_with_email, share_type) VALUES (?, ?, ?, ?, ?)",
                                [$file_id_to_share, $current_user_id, $shared_with_user_id_db, $shared_with_email_db, $actual_share_type_db]
                            );
                            $temp_success_messages[] = "\"" . $original_filename_for_share . "\" shared with \"" . $share_identifier . "\".";
                        }
                    }
                }
            } else {
                $temp_error_messages[] = "Invalid share option provided.";
            }
        } else {
            $temp_error_messages[] = "You are not the owner of this file or the file does not exist.";
        }

        redirect_with_messages($temp_success_messages, $temp_error_messages, "index.php#file-row-" . $file_id_to_share);
    }

    // --- CHANGE USERNAME LOGIC ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'change_username' && isset($_POST['new_username'])) {
        $new_username = trim($_POST['new_username']);
        $new_email = trim($_POST['new_email'] ?? '');
        $temp_success_messages = [];
        $temp_error_messages = [];

        if (empty($new_username)) {
            $temp_error_messages[] = "Username cannot be empty.";
        } elseif (strlen($new_username) > 50) {
            $temp_error_messages[] = "Username must be 50 characters or less.";
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new_username)) {
            $temp_error_messages[] = "Username can only contain letters, numbers, and underscores.";
        } else {
            $stmt_check = db_query("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1", [$new_username, $current_user_id]);
            if ($stmt_check->fetch()) {
                $temp_error_messages[] = "Username \"" . $new_username . "\" is already taken.";
            }
        }

        // Validate email if provided
        if (empty($temp_error_messages) && $new_email !== '') {
            if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $temp_error_messages[] = "Please enter a valid email address.";
            } elseif (strlen($new_email) > 100) {
                $temp_error_messages[] = "Email must be 100 characters or less.";
            } else {
                $stmt_check_email = db_query("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1", [$new_email, $current_user_id]);
                if ($stmt_check_email->fetch()) {
                    $temp_error_messages[] = "Email \"" . $new_email . "\" is already registered by another account.";
                }
            }
        }

        if (empty($temp_error_messages)) {
            $db_email = ($new_email !== '') ? $new_email : null;
            db_query("UPDATE users SET username = ?, email = ? WHERE id = ?", [$new_username, $db_email, $current_user_id]);
            $_SESSION['username'] = $new_username;
            $_SESSION['email'] = $db_email;
            $temp_success_messages[] = "Account information successfully updated.";
        }

        if ($is_ajax) {
            send_json_response(['success' => empty($temp_error_messages), 'message' => implode("\n", array_merge($temp_success_messages, $temp_error_messages))]);
        }
        redirect_with_messages($temp_success_messages, $temp_error_messages);
    }

    // --- CHANGE PASSWORD LOGIC ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'change_password' && isset($_POST['current_password']) && isset($_POST['new_password']) && isset($_POST['confirm_new_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_new_password = $_POST['confirm_new_password'];
        $temp_success_messages = [];
        $temp_error_messages = [];

        if (empty($current_password) || empty($new_password) || empty($confirm_new_password)) {
            $temp_error_messages[] = "All password fields are required.";
        } elseif ($new_password !== $confirm_new_password) {
            $temp_error_messages[] = "New password and confirmation do not match.";
        } elseif (strlen($new_password) < 10) {
            $temp_error_messages[] = "New password must be at least 10 characters long.";
        } elseif (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
            $temp_error_messages[] = "Password must include at least one uppercase letter, one lowercase letter, and one number.";
        } else {
            $stmt_hash = db_query("SELECT password_hash FROM users WHERE id = ? LIMIT 1", [$current_user_id]);
            $user_record = $stmt_hash->fetch();

            if ($user_record && password_verify($current_password, $user_record['password_hash'])) {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                db_query("UPDATE users SET password_hash = ? WHERE id = ?", [$new_password_hash, $current_user_id]);
                $temp_success_messages[] = "Password successfully changed.";
            } else {
                $temp_error_messages[] = "Current password is incorrect.";
            }
        }

        if ($is_ajax) {
            send_json_response(['success' => empty($temp_error_messages), 'message' => implode("\n", array_merge($temp_success_messages, $temp_error_messages))]);
        }
        redirect_with_messages($temp_success_messages, $temp_error_messages);
    }

    // --- DELETE ACCOUNT LOGIC ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_account' && isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'yes') {
        $conn->beginTransaction();

        try {
            // Collect filenames first; physical files are removed only AFTER a
            // successful commit so a DB failure cannot orphan-delete files.
            $files_to_remove = [];
            $stmt_files = db_query("SELECT stored_filename, thumb_filename FROM user_files WHERE user_id = ?", [$current_user_id], true);
            while ($row_file = $stmt_files->fetch()) {
                $files_to_remove[] = $row_file['stored_filename'];
                if (!empty($row_file['thumb_filename'])) {
                    $files_to_remove[] = $row_file['thumb_filename'];
                }
            }

            db_query("DELETE FROM file_shares WHERE shared_with_user_id = ? OR shared_with_email = ?", [$current_user_id, $current_user_email], true);
            db_query("DELETE FROM users WHERE id = ?", [$current_user_id], true);
            $conn->commit();

            foreach ($files_to_remove as $relative_path) {
                $physical_path = UPLOAD_DIR . $relative_path;
                if (file_exists($physical_path) && !unlink($physical_path)) {
                    error_log("Account deletion: failed to remove file " . $physical_path);
                }
            }

            session_destroy();
            if ($is_ajax) {
                send_json_response(['success' => true, 'message' => "Account deleted.", 'redirect' => 'index.php']);
            }
            header("Location: index.php");
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Account deletion failed: " . $e->getMessage());
            if ($is_ajax) {
                send_json_response(['success' => false, 'message' => "Failed to delete account. Details logged."], 500);
            }
            redirect_with_messages([], ["Account deletion failed. Details logged."]);
        }
    }

    // --- Set CSS file action ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'set_css_file' && isset($_POST['css_file'])) {
        $requested_css_file = basename($_POST['css_file']);
        $file_path = __DIR__ . '/css/' . $requested_css_file;
        $temp_success_messages = [];
        $temp_error_messages = [];

        if (pathinfo($requested_css_file, PATHINFO_EXTENSION) !== 'css') {
            $temp_error_messages[] = "Invalid file type. Only .css files are allowed.";
        } elseif (!file_exists($file_path)) {
            $temp_error_messages[] = "CSS theme file not found: " . $requested_css_file;
        } else {
            $_SESSION['current_css_file'] = 'css/' . $requested_css_file;
            db_query("UPDATE users SET selected_css = ? WHERE id = ?", ['css/' . $requested_css_file, $current_user_id]);
            $temp_success_messages[] = "Stylesheet preference saved.";
        }

        send_json_response(['success' => empty($temp_error_messages), 'message' => implode("\n", array_merge($temp_success_messages, $temp_error_messages))]);
    }

    // --- CREATE A CONFIGURABLE PUBLIC SHARE LINK (AJAX) ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'create_share_link' && isset($_POST['file_id'])) {
        $file_id_for_link = intval($_POST['file_id']);

        // The requester must be able to see the file (owner or shared-with).
        $stmt_perm = db_query(
            "SELECT uf.file_id
             FROM user_files uf
             LEFT JOIN file_shares fs_user ON fs_user.file_id = uf.file_id AND fs_user.share_type = 'user' AND fs_user.shared_with_user_id = ?
             LEFT JOIN file_shares fs_email ON fs_email.file_id = uf.file_id AND fs_email.share_type = 'email' AND fs_email.shared_with_email = ?
             WHERE uf.file_id = ? AND (uf.user_id = ? OR fs_user.share_id IS NOT NULL OR fs_email.share_id IS NOT NULL)
             LIMIT 1",
            [$current_user_id, $current_user_email, $file_id_for_link, $current_user_id]
        );
        if (!$stmt_perm->fetch()) {
            send_json_response(['success' => false, 'message' => 'You do not have access to this file.'], 403);
        }

        // Expiry — whitelist of relative windows; 'never' stores NULL.
        $expiry_map = [
            '1h' => '+1 hour', '1d' => '+1 day', '7d' => '+7 days',
            '30d' => '+30 days', '1y' => '+1 year', 'never' => null,
        ];
        $expiry_key = $_POST['expiry'] ?? '7d';
        if (!array_key_exists($expiry_key, $expiry_map)) { $expiry_key = '7d'; }
        $expires_at = $expiry_map[$expiry_key] === null
            ? null
            : gmdate('Y-m-d H:i:s', strtotime($expiry_map[$expiry_key], time()));

        // Download cap — 0 or less means unlimited (NULL).
        $max_int = (int) ($_POST['max_downloads'] ?? 20);
        $max_downloads = ($max_int <= 0) ? null : min($max_int, 100000);

        // Optional passcode (stored only as a hash).
        $passcode = trim((string) ($_POST['passcode'] ?? ''));
        $passcode_hash = null;
        if ($passcode !== '') {
            if (strlen($passcode) < 4) {
                send_json_response(['success' => false, 'message' => 'Passcode must be at least 4 characters.'], 422);
            }
            $passcode_hash = password_hash($passcode, PASSWORD_DEFAULT);
        }

        $token = bin2hex(random_bytes(24)); // 48 hex chars, unguessable
        db_query(
            "INSERT INTO share_links (file_id, created_by_user_id, token, max_downloads, download_count, expires_at, passcode_hash)
             VALUES (?, ?, ?, ?, 0, ?, ?)",
            [$file_id_for_link, $current_user_id, $token, $max_downloads, $expires_at, $passcode_hash]
        );

        $link_base = UPLOAD_BASE_URL ?: app_base_url();
        send_json_response([
            'success' => true,
            'link' => [
                'link_id'        => (int) $conn->lastInsertId(),
                'url'            => $link_base . '/download.php?t=' . $token,
                'max_downloads'  => $max_downloads,
                'download_count' => 0,
                'expires_at_unix'=> $expires_at ? strtotime($expires_at . ' UTC') : null,
                'has_passcode'   => $passcode_hash !== null,
                'status'         => 'active',
            ],
        ]);
    }

    // --- REVOKE A PUBLIC SHARE LINK (AJAX) ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'revoke_share_link' && isset($_POST['link_id'])) {
        $link_id_int = intval($_POST['link_id']);
        $stmt_own = db_query(
            "SELECT share_link_id FROM share_links WHERE share_link_id = ? AND created_by_user_id = ? LIMIT 1",
            [$link_id_int, $current_user_id]
        );
        if ($stmt_own->fetch()) {
            db_query("DELETE FROM share_links WHERE share_link_id = ?", [$link_id_int]);
            send_json_response(['success' => true, 'message' => 'Link revoked.']);
        }
        send_json_response(['success' => false, 'message' => 'Link not found or you did not create it.'], 403);
    }

    // --- DELETE FILE(S) (POST + CSRF) — one or many, from the confirm modal ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_selected' && isset($_POST['file_ids'])) {
        $requested_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $_POST['file_ids'])))));
        $temp_success_messages = [];
        $temp_error_messages = [];

        if (empty($requested_ids)) {
            redirect_with_messages([], ["No files were selected for deletion."]);
        }

        $deleted = 0;
        foreach ($requested_ids as $fid) {
            $stmt_get = db_query("SELECT stored_filename, thumb_filename, original_filename FROM user_files WHERE file_id = ? AND user_id = ? LIMIT 1", [$fid, $current_user_id]);
            $file_rec = $stmt_get->fetch();
            if (!$file_rec) {
                $temp_error_messages[] = "A selected file was not found or is not yours.";
                continue;
            }
            // Remove the physical file + cached thumbnail; file_shares and
            // share_links rows cascade away via their foreign keys.
            $physical_path = UPLOAD_DIR . $file_rec['stored_filename'];
            if (file_exists($physical_path)) { @unlink($physical_path); }
            if (!empty($file_rec['thumb_filename'])) {
                $thumb_path = UPLOAD_DIR . $file_rec['thumb_filename'];
                if (file_exists($thumb_path)) { @unlink($thumb_path); }
            }
            db_query("DELETE FROM user_files WHERE file_id = ? AND user_id = ?", [$fid, $current_user_id]);
            $deleted++;
        }

        if ($deleted > 0) {
            $temp_success_messages[] = $deleted . ($deleted === 1 ? " file deleted." : " files deleted.");
        }
        redirect_with_messages($temp_success_messages, $temp_error_messages);
    }

    // --- UNSHARE SELF (POST + CSRF) ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'unshare_self' && isset($_POST['share_id'])) {
        $share_id_int = intval($_POST['share_id']);
        $temp_success_messages = [];
        $temp_error_messages = [];

        $stmt_check = db_query(
            "SELECT fs.file_id, uf.original_filename
             FROM file_shares fs
             JOIN user_files uf ON fs.file_id = uf.file_id
             WHERE fs.share_id = ? AND ((fs.shared_with_user_id = ? AND share_type = 'user') OR (fs.shared_with_email = ? AND share_type = 'email'))
             LIMIT 1",
            [$share_id_int, $current_user_id, $current_user_email]
        );
        $share_row = $stmt_check->fetch();

        if ($share_row) {
            db_query("DELETE FROM file_shares WHERE share_id = ?", [$share_id_int]);
            $temp_success_messages[] = "You are no longer shared on \"" . $share_row['original_filename'] . "\".";
        } else {
            $temp_error_messages[] = "Share not found or not for you.";
        }

        redirect_with_messages($temp_success_messages, $temp_error_messages);
    }

    // --- REVOKE SHARE (owner, POST + CSRF) ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'revoke_share' && isset($_POST['share_id'])) {
        $share_id_int = intval($_POST['share_id']);
        $file_id_anchor = isset($_POST['file_id']) ? intval($_POST['file_id']) : 0;
        $temp_success_messages = [];
        $temp_error_messages = [];

        $stmt_check = db_query(
            "SELECT fs.file_id, uf.original_filename
             FROM file_shares fs
             JOIN user_files uf ON fs.file_id = uf.file_id
             WHERE fs.share_id = ? AND fs.shared_by_user_id = ?
             LIMIT 1",
            [$share_id_int, $current_user_id]
        );
        $revoke_row = $stmt_check->fetch();

        if ($revoke_row) {
            db_query("DELETE FROM file_shares WHERE share_id = ?", [$share_id_int]);
            if ($is_ajax) {
                send_json_response(['success' => true, 'message' => "Share for \"" . $revoke_row['original_filename'] . "\" revoked."]);
            }
            $temp_success_messages[] = "Share for \"" . $revoke_row['original_filename'] . "\" revoked.";
        } else {
            if ($is_ajax) {
                send_json_response(['success' => false, 'message' => "Share not found or you did not create this share."], 403);
            }
            $temp_error_messages[] = "Share not found or you did not create this share.";
        }

        redirect_with_messages($temp_success_messages, $temp_error_messages, "index.php" . ($file_id_anchor ? "#file-row-" . $file_id_anchor : ""));
    }
}

elseif ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action'])) {
    // --- AJAX: GET CURRENT SHARES (read-only) ---
    if ($_GET['action'] === 'get_shares' && isset($_GET['file_id'])) {
        $file_id_for_shares = intval($_GET['file_id']);
        $shares_data = [];

        $stmt_get_s = db_query(
            "SELECT fs.share_id, fs.share_type, fs.shared_with_email, u.username AS shared_with_username
             FROM file_shares fs
             LEFT JOIN users u ON fs.shared_with_user_id = u.id AND fs.share_type = 'user'
             WHERE fs.file_id = ? AND fs.shared_by_user_id = ?",
            [$file_id_for_shares, $current_user_id]
        );
        while ($share_row = $stmt_get_s->fetch()) {
            $shares_data[] = $share_row;
        }
        send_json_response($shares_data);
    }

    // --- AJAX: LIST THIS USER'S PUBLIC LINKS FOR A FILE (read-only) ---
    elseif ($_GET['action'] === 'list_share_links' && isset($_GET['file_id'])) {
        $file_id_for_links = intval($_GET['file_id']);
        $stmt_links = db_query(
            "SELECT share_link_id, token, max_downloads, download_count, expires_at, passcode_hash
             FROM share_links
             WHERE file_id = ? AND created_by_user_id = ?
             ORDER BY created_at DESC",
            [$file_id_for_links, $current_user_id]
        );
        $link_base = UPLOAD_BASE_URL ?: app_base_url();
        $now = time();
        $links_out = [];
        while ($lr = $stmt_links->fetch()) {
            $exp_unix = !empty($lr['expires_at']) ? strtotime($lr['expires_at'] . ' UTC') : null;
            $expired = $exp_unix !== null && $exp_unix < $now;
            $exhausted = $lr['max_downloads'] !== null && (int) $lr['download_count'] >= (int) $lr['max_downloads'];
            $links_out[] = [
                'link_id'         => (int) $lr['share_link_id'],
                'url'             => $link_base . '/download.php?t=' . $lr['token'],
                'max_downloads'   => $lr['max_downloads'] === null ? null : (int) $lr['max_downloads'],
                'download_count'  => (int) $lr['download_count'],
                'expires_at_unix' => $exp_unix,
                'has_passcode'    => !empty($lr['passcode_hash']),
                'status'          => $expired ? 'expired' : ($exhausted ? 'exhausted' : 'active'),
            ];
        }
        send_json_response(['success' => true, 'links' => $links_out]);
    }

    // --- AJAX: Get list of CSS files (read-only) ---
    elseif ($_GET['action'] === 'get_css_files') {
        $css_files = [];
        $scan_dir = __DIR__ . '/css/';
        $files = scandir($scan_dir);

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'css' && !in_array($file, ['style.css', 'dashboard-ui.css'])) {
                $css_files[] = $file;
            }
        }
        sort($css_files);
        send_json_response(['success' => true, 'css_files' => $css_files, 'current_css' => basename($_SESSION['current_css_file'])]);
    }
}

// --- DATA FETCH FOR TEMPLATE DISPLAY ---
// my_share_id is resolved inline (correlated subquery) so the view does not
// need a per-row lookup query.
$sql_list_files = "
    SELECT DISTINCT
        uf.file_id, uf.user_id AS owner_user_id, uf.original_filename,
        uf.stored_filename, uf.filesize, uf.upload_timestamp,
        u_owner.username AS owner_username,
        (SELECT fs_mine.share_id FROM file_shares fs_mine
          WHERE fs_mine.file_id = uf.file_id
            AND ((fs_mine.shared_with_user_id = ? AND fs_mine.share_type = 'user')
              OR (fs_mine.shared_with_email = ? AND fs_mine.share_type = 'email'))
          LIMIT 1) AS my_share_id
    FROM user_files uf
    JOIN users u_owner ON u_owner.id = uf.user_id
    LEFT JOIN file_shares fs_user_check ON fs_user_check.file_id = uf.file_id AND fs_user_check.share_type = 'user' AND fs_user_check.shared_with_user_id = ?
    LEFT JOIN file_shares fs_email_check ON fs_email_check.file_id = uf.file_id AND fs_email_check.share_type = 'email' AND fs_email_check.shared_with_email = ?
    WHERE uf.user_id = ? OR fs_user_check.share_id IS NOT NULL OR fs_email_check.share_id IS NOT NULL
    ORDER BY uf.upload_timestamp DESC";

$stmt_list = db_query($sql_list_files, [$current_user_id, $current_user_email, $current_user_id, $current_user_email, $current_user_id]);
$files_list = $stmt_list->fetchAll();

// Storage usage for the dashboard meter (owned files only).
$storage_used = fileoo_storage_used($current_user_id);

require __DIR__ . '/dashboard_view.php';
