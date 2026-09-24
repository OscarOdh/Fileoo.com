<?php
// fileoo.com/dashboard_view.php
// Presentation layer for the file manager dashboard. Loaded through dashboard.php.
if (!defined('FILEOO_APP')) {
    header("Location: index.php");
    exit;
}
$js_version = file_exists(__DIR__ . '/js/dashboard.js') ? filemtime(__DIR__ . '/js/dashboard.js') : '1.0';
$matrix_js_version = file_exists(__DIR__ . '/js/matrix.js') ? filemtime(__DIR__ . '/js/matrix.js') : '1.0';
$ui_css_version = time();
$css_version = time();

// --- Storage meter geometry (open-bottom 270° ring gauge) ---
$storage_used = $storage_used ?? 0;
$storage_quota = $storage_quota ?? (DEFAULT_STORAGE_QUOTA_MB * 1024 * 1024);
$storage_quota_safe = ($storage_quota > 0) ? $storage_quota : (DEFAULT_STORAGE_QUOTA_MB * 1024 * 1024);
$storage_pct = min(1.0, max(0.0, $storage_used / $storage_quota_safe));
$storage_pct_display = (int) round($storage_pct * 100);
$gauge_circ = 2 * M_PI * 80;              // r = 80
$gauge_track_len = 0.75 * $gauge_circ;     // 270° visible arc
$gauge_value_len = $storage_pct * $gauge_track_len;
$gauge_track_dash = round($gauge_track_len, 2) . ' ' . round($gauge_circ - $gauge_track_len, 2);
$gauge_value_dash = round($gauge_value_len, 2) . ' ' . round($gauge_circ - $gauge_value_len, 2);
// Round caps look best for a partial fill, but at ~0% they render a stray dot.
$gauge_value_cap = ($gauge_value_len > 0.5) ? 'round' : 'butt';
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1.0,user-scalable=yes">
        <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <?php if (UPLOAD_BASE_URL): // Uploads/downloads/copied links live on a separate origin — warm it early. ?>
        <link rel="preconnect" href="<?php echo htmlspecialchars(UPLOAD_BASE_URL); ?>">
        <link rel="dns-prefetch" href="<?php echo htmlspecialchars(UPLOAD_BASE_URL); ?>">
        <?php endif; ?>
        <link rel="stylesheet" href="<?php echo $current_css_file; ?>?v=<?php echo $css_version; ?>">
        <link rel="stylesheet" href="css/dashboard-ui.css?v=<?php echo $ui_css_version; ?>">
        <title>FILEOO - <?php echo htmlspecialchars($current_user_username); ?></title>
        <script src="js/matrix.js?v=<?php echo $matrix_js_version; ?>" defer></script>
        <script src="js/dashboard.js?v=<?php echo $js_version; ?>" defer></script>
    </head>
    <body>
        <canvas id="cyberpunk-bg"></canvas>
        <div class="page-container">
            <header class="site-main-header">
                <button class="hamburger-menu" id="hamburgerMenu">
                    <span class="bar"></span>
                    <span class="bar"></span>
                    <span class="bar"></span>
                </button>
                <h1><a href="index.php">FILEOO.COM</a></h1>
                <div class="logout">
                    <a href="logout.php" title="Logout"> <img src="images/logout.png" alt="logout" width="26" height="26" > </a>
                </div>
            </header>

            <!-- Sidebar Menu -->
            <aside class="sidebar-menu" id="sidebarMenu">
                <div class="sidebar-header">
                    <h2 id="sidebarUsername"><?php echo htmlspecialchars($current_user_username); ?></h2>
                    <p id="sidebarEmail"><?php echo htmlspecialchars($current_user_email); ?></p>
                </div>
                <nav class="sidebar-nav">
                    <ul>
                        <li><a href="#" data-action="open-modal" data-modal="change-name-modal">Change Username</a></li>
                        <li><a href="#" data-action="open-modal" data-modal="change-password-modal">Change Password</a></li>
                        <li><a href="#" data-action="open-modal" data-modal="delete-account-modal">Delete Account</a></li>
                    </ul>
                </nav>
                <div class="theme-selector-container">
                    <label for="themeSelector">Select Theme:</label>
                    <select id="themeSelector">
                        <!-- Options will be populated by JavaScript -->
                    </select>
                </div>
            </aside>

            <main class="main-content-area">
                <?php
                // Display flash messages (plain text, escaped at output)
                foreach ($display_error_messages as $msg) {
                    echo "<div class='message error'>" . htmlspecialchars($msg) . "</div>";
                }
                foreach ($display_success_messages as $msg) {
                    echo "<div class='message success'>" . htmlspecialchars($msg) . "</div>";
                }
                ?>

                <div class="dashboard-top">
                    <section class="form-wrapper upload-card" id="upload-section">
                        <form action="<?php echo UPLOAD_BASE_URL ? UPLOAD_BASE_URL . '/index.php' : 'index.php'; ?>" method="post" enctype="multipart/form-data" id="uploadForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="upload-dropzone">
                                <svg class="upload-cloud" viewBox="0 0 24 24" width="52" height="52" aria-hidden="true">
                                    <path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                          d="M6.5 18.5h11a3.75 3.75 0 0 0 .4-7.48 5.75 5.75 0 0 0-11.16-1.3A4.25 4.25 0 0 0 6.5 18.5Z"/>
                                    <path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                          d="M12 16.5v-7m0 0-2.4 2.4M12 9.5l2.4 2.4"/>
                                </svg>
                                <input type="file" name="fileToUpload" id="fileToUpload" class="file-select-input" multiple required>
                                <label for="fileToUpload" class="file-select-btn upload-choose-label">Choose Files or Drag &amp; Drop</label>
                                <span class="file-select-name" id="fileSelectName">No files chosen</span>
                            </div>
                            <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo MAX_FILE_SIZE; ?>" />
                            <input type="hidden" name="upload_submitted" value="1" />
                            <input type="submit" value="UPLOAD" name="submit_upload_form" id="uploadSubmitBtn">
                            <!-- The UPLOAD button is swapped for this progress bar during upload -->
                            <div class="upload-progress" id="uploadProgress" style="display:none;">
                                <div class="upload-progress-fill" id="uploadProgressFill"></div>
                                <span class="upload-progress-text" id="uploadProgressText">0%</span>
                            </div>
                        </form>
                    </section>

                    <section class="form-wrapper storage-card" id="storage-section">
                        <div class="storage-inner">
                            <div class="storage-gauge" role="img" aria-label="<?php echo $storage_pct_display; ?>% of storage used">
                                <svg class="gauge-svg" viewBox="0 0 200 200" aria-hidden="true">
                                    <circle class="gauge-track" cx="100" cy="100" r="80" fill="none"
                                            stroke-width="18" stroke-linecap="round"
                                            stroke-dasharray="<?php echo $gauge_track_dash; ?>"
                                            transform="rotate(135 100 100)"/>
                                    <circle class="gauge-value" cx="100" cy="100" r="80" fill="none"
                                            stroke-width="18" stroke-linecap="<?php echo $gauge_value_cap; ?>"
                                            stroke-dasharray="<?php echo $gauge_value_dash; ?>"
                                            transform="rotate(135 100 100)"/>
                                </svg>
                                <div class="gauge-label"><?php echo $storage_pct_display; ?>%</div>
                            </div>
                            <div class="storage-info">
                                <div class="storage-title">STORAGE</div>
                                <div class="storage-numbers">
                                    <strong><?php echo htmlspecialchars(format_size($storage_used)); ?></strong>
                                    <span class="storage-quota">/ <?php echo htmlspecialchars(format_size($storage_quota)); ?> USED</span>
                                </div>
                                <!-- Slim usage bar; replaces the ring gauge on mobile -->
                                <div class="storage-line-bar" aria-hidden="true"><div class="storage-line-fill" style="width: <?php echo $storage_pct_display; ?>%;"></div></div>
                            </div>
                        </div>
                    </section>
                </div>

                <section class="table-wrapper" id="archive-section">
                    <?php
                    // Selection checkboxes / bulk delete only appear with more than
                    // one deletable (owned) file.
                    $owned_count = 0;
                    foreach ($files_list as $r) {
                        if ($r['owner_user_id'] == $current_user_id) { $owned_count++; }
                    }
                    $multi_select_enabled = $owned_count > 0;
                    ?>
                    <div class="responsive-table-container">
                        <table class="fl-table" id="fileTable">
                            <colgroup class="table-desktop-cols">
                                <col class="col-file-c">
                                <col class="col-meta-c">
                                <col class="col-actions-c">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="col-file">
                                        <div class="col-file-inner">
                                            <button type="button" class="search-toggle" id="searchToggle" title="Search files" aria-label="Search files">
                                                <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14z"/></svg>
                                            </button>
                                            <span class="sort-btn" data-sort="name" role="button" tabindex="0">File<span class="sort-arrow" aria-hidden="true"></span></span>
                                            <div class="search-box" id="searchBox" hidden>
                                                <input type="text" id="fileSearchInput" placeholder="Filter files&hellip;" autocomplete="off" spellcheck="false">
                                                <button type="button" class="search-clear" id="searchClear" title="Clear search" aria-label="Clear search">
                                                    <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M16.24 3.56l4.95 4.95c.78.78.78 2.05 0 2.83L12 20.53H4v-8.03L16.24 3.56zM6 18.53h3.59l8.06-8.06-3.59-3.59L6 14.94v3.59z"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </th>
                                    <th class="col-meta">
                                        <span class="sort-btn" data-sort="size" role="button" tabindex="0">Size<span class="sort-arrow" aria-hidden="true"></span></span>
                                        <span class="meta-sep">/</span>
                                        <span class="sort-btn" data-sort="date" role="button" tabindex="0">Date<span class="sort-arrow" aria-hidden="true"></span></span>
                                    </th>
                                    <th class="col-actions<?php echo $multi_select_enabled ? '' : ' no-bulk'; ?>">
                                        <?php if ($multi_select_enabled): ?>
                                            <button type="button" class="bulk-download-btn" id="bulkDownloadBtn" hidden>DOWNLOAD (<span id="bulkDlCount">0</span>)</button>
                                            <button type="button" class="bulk-delete-btn" id="bulkDeleteBtn" hidden>DELETE (<span id="bulkCount">0</span>)</button>
                                            <label class="select-all-wrap" title="Select all"><input type="checkbox" id="selectAll"><span class="cbx"></span></label>
                                        <?php endif; ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="fileTableBody">
                            <?php if (count($files_list) > 0): ?>
                                <?php foreach ($files_list as $row):
                                    $file_id = (int)$row['file_id'];
                                    $raw_orig_filename = $row['original_filename'];
                                    $original_filename_display = htmlspecialchars($raw_orig_filename);
                                    $is_owner = ($row['owner_user_id'] == $current_user_id);

                                    $owner_info_display = "";
                                    if (!$is_owner) {
                                        $owner_info_display = " <small style='color:#aaa;'>(" . htmlspecialchars($row['owner_username']) . ")</small>";
                                    }

                                    $upload_unix = 0;
                                    try {
                                        $utc_date = new DateTime($row['upload_timestamp'], new DateTimeZone('UTC'));
                                        $upload_unix = $utc_date->getTimestamp();
                                        $utc_date->setTimezone(new DateTimeZone('America/Chicago'));
                                        $filetime_display = $utc_date->format('Y.m.d h:i A');
                                    } catch (Exception $e) {
                                        $upload_unix = (int) strtotime($row['upload_timestamp']);
                                        $filetime_display = date('Y.m.d h:i A', $upload_unix);
                                    }
                                    $formatted_filesize_display = format_size($row['filesize']);

                                    // Split filename for middle-truncation (keeps the extension / last chars visible)
                                    $orig_ext = pathinfo($raw_orig_filename, PATHINFO_EXTENSION);
                                    if ($orig_ext !== '') {
                                        $filename_ext = '.' . $orig_ext;
                                        $filename_base = mb_substr($raw_orig_filename, 0, -mb_strlen($filename_ext));
                                    } else {
                                        if (mb_strlen($raw_orig_filename) > 6) {
                                            $filename_base = mb_substr($raw_orig_filename, 0, -4);
                                            $filename_ext = mb_substr($raw_orig_filename, -4);
                                        } else {
                                            $filename_base = $raw_orig_filename;
                                            $filename_ext = '';
                                        }
                                    }

                                    // Raster images open inline in a new tab (there is a separate DOWN
                                    // button for downloading). Everything else keeps the download link.
                                    $file_ext = strtolower(pathinfo($row['stored_filename'], PATHINFO_EXTENSION));
                                    $is_viewable_image = in_array($file_ext, ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'], true);
                                    $download_base = UPLOAD_BASE_URL ? UPLOAD_BASE_URL . '/' : '';
                                    $has_thumb = !empty($row['thumb_filename']) || $is_viewable_image;
                                ?>
                                    <tr id="file-row-<?php echo $file_id; ?>" data-file-id="<?php echo $file_id; ?>" data-filename="<?php echo htmlspecialchars(strtolower($row['original_filename'])); ?>" data-size="<?php echo (int) $row['filesize']; ?>" data-date="<?php echo $upload_unix; ?>">
                                        <td data-label="" class="file-cell">
                                            <div class="file-name-wrap">
                                                <?php if ($has_thumb): 
                                                    $preview_thumb_url = 'download.php?file_id=' . $file_id . '&thumb=1';
                                                    $preview_full_url = $is_viewable_image ? ('download.php?file_id=' . $file_id . '&view=1') : $preview_thumb_url;
                                                    $preview_dl_url = ($download_base ?: '') . 'download.php?file_id=' . $file_id;
                                                ?>
                                                    <span class="preview-icon" data-thumb-url="<?php echo htmlspecialchars($preview_thumb_url); ?>" data-full-url="<?php echo htmlspecialchars($preview_full_url); ?>" data-download-url="<?php echo htmlspecialchars($preview_dl_url); ?>" data-filename="<?php echo $original_filename_display; ?>" tabindex="0" title="Preview <?php echo $original_filename_display; ?>" aria-label="Preview <?php echo $original_filename_display; ?>" role="button">
                                                        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M21 19V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($is_viewable_image): ?>
                                                    <a href="<?php echo $download_base; ?>download.php?file_id=<?php echo $file_id; ?>&amp;view=1" target="_blank" rel="noopener noreferrer" class="file-link" title="View: <?php echo $original_filename_display; ?>"><span class="file-name-base"><b><?php echo htmlspecialchars($filename_base); ?></b></span><?php if ($filename_ext !== ''): ?><span class="file-name-ext"><b><?php echo htmlspecialchars($filename_ext); ?></b></span><?php endif; ?></a>
                                                <?php else: ?>
                                                    <a href="<?php echo $download_base; ?>download.php?file_id=<?php echo $file_id; ?>" class="file-link" title="Download: <?php echo $original_filename_display; ?>"><span class="file-name-base"><b><?php echo htmlspecialchars($filename_base); ?></b></span><?php if ($filename_ext !== ''): ?><span class="file-name-ext"><b><?php echo htmlspecialchars($filename_ext); ?></b></span><?php endif; ?></a>
                                                <?php endif; ?>
                                                <?php echo $owner_info_display; ?>
                                            </div>
                                        </td>
                                        <td data-label="">
                                            <i><?php echo htmlspecialchars($formatted_filesize_display) . "  /  " . htmlspecialchars($filetime_display); ?></i>
                                        </td>
                                        <td data-label="" class="actions-cell">
                                            <a href="<?php echo $download_base; ?>download.php?file_id=<?php echo $file_id; ?>" class="action-link download-link" title="Download <?php echo $original_filename_display; ?>">
                                                <img src="images/dl.png" alt="Download Icon" width="16" height="16"> <span>DOWN</span>
                                            </a>
                                            <a href="#" class="action-link copy-link" data-action="copy" data-file-id="<?php echo $file_id; ?>" data-filename="<?php echo $original_filename_display; ?>" title="Manage public links for: <?php echo $original_filename_display; ?>">
                                                <img src="images/copy.png" alt="Link Icon" width="16" height="16"> <span>LINK</span>
                                            </a>
                                            <?php if ($is_owner): ?>
                                                <a href="#" class="action-link share-link" data-action="share" data-file-id="<?php echo $file_id; ?>" data-filename="<?php echo $original_filename_display; ?>" title="Share <?php echo $original_filename_display; ?>">
                                                    <img src="images/share.png" alt="Share Icon" width="16" height="16"> <span>SHARE</span>
                                                </a>
                                                <?php if ($multi_select_enabled): ?>
                                                    <label class="row-select-wrap" title="Select <?php echo $original_filename_display; ?>">
                                                        <input type="checkbox" class="row-select" data-file-id="<?php echo $file_id; ?>" data-filename="<?php echo $original_filename_display; ?>">
                                                        <span class="cbx"></span>
                                                    </label>
                                                <?php endif; ?>
                                            <?php elseif (!empty($row['my_share_id'])): ?>
                                                <a href="#" class="action-link unshare-link" data-action="unshare" data-share-id="<?php echo (int)$row['my_share_id']; ?>" data-file-id="<?php echo $file_id; ?>" data-filename="<?php echo $original_filename_display; ?>" title="Remove my access to <?php echo $original_filename_display; ?>">
                                                    <img src="images/unshare.png" alt="Unshare Icon" width="16" height="16"> <span>UNSHARE</span>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr id="noFilesRow"<?php if (count($files_list) > 0) echo ' hidden'; ?>><td colspan="3" style="text-align:center; padding: 20px;" data-label=""></td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>

        <!-- Share File Modal HTML (Single instance) -->
        <div id="shareFileModalOverlay" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="shareModalTitle">Manage Share</h3>
                    <button type="button" class="modal-close-btn" data-action="close-share-modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="add-share-section">
                        <h4>Add New Share:</h4>
                        <form id="modalShareForm" action="index.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="share_file_modal">
                            <input type="hidden" name="modal_file_id_to_share" id="modal_file_id_to_share" value="">
                            <div class="form-group">
                                <label for="modal_share_type">Share option:</label>
                                <select name="modal_share_type" id="modal_share_type">
                                    <option value="specific_user_email">Specific User (Username or Email)</option>
                                </select>
                            </div>
                            <div class="form-group" id="modal_share_identifier_div">
                                <label for="modal_share_identifier">Username or Email:</label>
                                <input type="text" name="modal_share_identifier" id="modal_share_identifier" placeholder="Enter username or email">
                            </div>
                            <input type="submit" value="SHARE"  name="modal_share_submit">
                        </form>
                    </div>

                    <hr class="content-divider" id="modalShareDivider" style="display:none;">

                    <div id="currentSharesForModal" class="current-shares-section" style="display:none;">
                        <h4>Currently Shared With:</h4>
                        <table id="modalCurrentSharesTable">
                            <thead>
                                <tr>
                                    <th>Shared With</th>
                                    <th>Type</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="modalCurrentSharesTableBody"></tbody>
                        </table>
                        <p id="noSharesMessage" style="display:none;">Not currently shared with any specific users/emails.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Public Links Modal (configurable public download links) -->
        <div id="linkModalOverlay" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="linkModalTitle">Public Links</h3>
                    <button type="button" class="modal-close-btn" data-action="close-link-modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="add-share-section">
                        <h4>Create Link</h4>
                        <div class="link-create-grid">
                            <div class="form-group">
                                <label for="linkExpiry">Expires</label>
                                <select id="linkExpiry">
                                    <option value="1h">1 hour</option>
                                    <option value="1d">1 day</option>
                                    <option value="7d" selected>7 days</option>
                                    <option value="30d">30 days</option>
                                    <option value="1y">1 year</option>
                                    <option value="never">Never</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="linkMaxDownloads">Download limit</label>
                                <select id="linkMaxDownloads">
                                    <option value="1">1 download</option>
                                    <option value="5">5 downloads</option>
                                    <option value="20" selected>20 downloads</option>
                                    <option value="100">100 downloads</option>
                                    <option value="0">Unlimited</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="linkPasscode">Passcode (optional)</label>
                                <input type="text" id="linkPasscode" autocomplete="off" placeholder="Leave blank for none">
                            </div>
                        </div>
                        <button type="button" class="link-create-btn" id="createLinkBtn">Create &amp; Copy Link</button>
                    </div>

                    <hr class="content-divider">

                    <div class="current-shares-section" id="activeLinksSection">
                        <h4>Active Links</h4>
                        <div id="linkListContainer" class="link-list"></div>
                        <p id="noLinksMessage" class="link-empty">No links yet.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Change Username Modal -->
        <div id="change-name-modal" class="modal-overlay">
            <div class="account-modal-content">
                <div class="account-modal-header">
                    <h3>Change Username</h3>
                    <button type="button" class="account-modal-close-btn" data-action="close-modal" data-modal="change-name-modal">&times;</button>
                </div>
                <div class="account-modal-body">
                    <form id="changeUsernameForm" action="index.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="change_username">
                        <div class="form-group">
                            <label for="new_username">NEW USERNAME:</label>
                            <input type="text" id="new_username" name="new_username" required maxlength="50" value="<?php echo htmlspecialchars($current_user_username); ?>">
                        </div>
                        <div class="form-group">
                            <label for="new_email">NEW EMAIL (OPTIONAL):</label>
                            <input type="email" id="new_email" name="new_email" maxlength="100" value="<?php echo htmlspecialchars($current_user_email); ?>">
                        </div>
                        <input type="submit" value="SAVE">
                    </form>
                </div>
            </div>
        </div>

        <!-- Change Password Modal -->
        <div id="change-password-modal" class="modal-overlay">
            <div class="account-modal-content">
                <div class="account-modal-header">
                    <h3>Change Password</h3>
                    <button type="button" class="account-modal-close-btn" data-action="close-modal" data-modal="change-password-modal">&times;</button>
                </div>
                <div class="account-modal-body">
                    <form id="changePasswordForm" action="index.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label for="current_password">Current Password:</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                        <div class="form-group">
                            <label for="new_password">New Password:</label>
                            <input type="password" id="new_password" name="new_password" required minlength="10">
                        </div>
                        <div class="form-group">
                            <label for="confirm_new_password">Confirm New Password:</label>
                            <input type="password" id="confirm_new_password" name="confirm_new_password" required>
                        </div>
                        <input type="submit" value="Change Password">
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Account Modal -->
        <div id="delete-account-modal" class="modal-overlay">
            <div class="account-modal-content">
                <div class="account-modal-header">
                    <h3>Delete Account</h3>
                    <button type="button" class="account-modal-close-btn" data-action="close-modal" data-modal="delete-account-modal">&times;</button>
                </div>
                <div class="account-modal-body">
                    <p class="warning-text">
                        <b>WARNING:</b> Deleting your account is permanent. All your uploaded files and sharing records will be irrevocably removed. This action cannot be undone.
                    </p>
                    <p>Are you absolutely sure you want to delete your account?</p>
                    <form id="deleteAccountForm" action="index.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="delete_account">
                        <input type="hidden" name="confirm_delete" value="yes">
                        <input type="submit" value="Yes, Delete My Account" style="background: linear-gradient(45deg, #ff0000, #aa0000); box-shadow: 0 0 10px rgba(255,0,0,0.4);">
                    </form>
                </div>
            </div>
        </div>

        <!-- Bulk Download ZIP Form -->
        <form id="bulkDownloadForm" method="post" action="download.php" style="display:none;">
            <input type="hidden" name="action" value="bulk_download">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <div id="bulkDownloadInputs"></div>
        </form>

        <!-- Delete Confirmation Modal (replaces the native confirm dialog) -->
        <div id="delete-confirm-modal" class="modal-overlay">
            <div class="account-modal-content">
                <div class="account-modal-header">
                    <h3 id="deleteConfirmTitle">Delete File</h3>
                    <button type="button" class="account-modal-close-btn" data-action="close-delete-confirm">&times;</button>
                </div>
                <div class="account-modal-body">
                    <p class="warning-text">This permanently deletes the following. It cannot be undone.</p>
                    <ul id="deleteConfirmList" class="confirm-file-list"></ul>
                    <div class="confirm-actions">
                        <button type="button" class="btn-cancel" data-action="close-delete-confirm">Cancel</button>
                        <button type="button" class="btn-danger" id="deleteConfirmBtn">Delete</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Unshare Confirmation Modal (replaces the native confirm dialog) -->
        <div id="unshare-confirm-modal" class="modal-overlay">
            <div class="account-modal-content">
                <div class="account-modal-header">
                    <h3>Remove Share Access</h3>
                    <button type="button" class="account-modal-close-btn" data-action="close-unshare-confirm">&times;</button>
                </div>
                <div class="account-modal-body">
                    <p class="warning-text">This will remove your access to the following shared file.</p>
                    <ul id="unshareConfirmList" class="confirm-file-list"></ul>
                    <div class="confirm-actions">
                        <button type="button" class="btn-cancel" data-action="close-unshare-confirm">Cancel</button>
                        <button type="button" class="btn-danger" id="unshareConfirmBtn">Remove Access</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Image Lightbox Modal for mobile / tap preview -->
        <div id="imageLightboxModal" class="lightbox-overlay" hidden>
            <div class="lightbox-backdrop" data-action="close-lightbox"></div>
            <div class="lightbox-content">
                <div class="lightbox-header">
                    <span id="lightboxFilename" class="lightbox-title">Image Preview</span>
                    <button type="button" class="lightbox-close-btn" data-action="close-lightbox" aria-label="Close preview">&times;</button>
                </div>
                <div class="lightbox-body">
                    <img id="lightboxImg" src="" alt="Image Preview">
                </div>
                <div class="lightbox-footer">
                    <a id="lightboxDownloadBtn" href="#" class="lightbox-dl-btn" download>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        <span>Download</span>
                    </a>
                    <button type="button" class="lightbox-dismiss-btn" data-action="close-lightbox">Close</button>
                </div>
            </div>
        </div>

        <!-- Floating image preview (positioned by JS on hover of a preview icon) -->
        <div id="previewPopup" class="preview-popup" hidden><img id="previewPopupImg" src="" alt="Preview"></div>
    </body>
</html>
