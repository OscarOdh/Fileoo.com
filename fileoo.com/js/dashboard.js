// fileoo.com/js/dashboard.js
// All dashboard behavior. No inline scripts/handlers remain in the markup,
// which allows a strict Content-Security-Policy (script-src 'self').
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // --- Generic helper: POST an action as a real form submit (PRG flow) ---
    function postAction(fields) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php';
        form.style.display = 'none';
        fields.csrf_token = CSRF;
        Object.entries(fields).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    }

    // --- Share modal elements ---
    const shareModalOverlay = document.getElementById('shareFileModalOverlay');
    const shareModalForm = document.getElementById('modalShareForm');
    const modalFileIdInput = document.getElementById('modal_file_id_to_share');
    const modalShareIdentifierInput = document.getElementById('modal_share_identifier');
    const shareModalTitleElement = document.getElementById('shareModalTitle');
    const modalCurrentSharesTableBody = document.getElementById('modalCurrentSharesTableBody');
    const currentSharesContainerForModal = document.getElementById('currentSharesForModal');
    const modalShareDivider = document.getElementById('modalShareDivider');
    const noSharesMessageElement = document.getElementById('noSharesMessage');

    async function fetchCurrentShares(fileId) {
        try {
            const response = await fetch(`index.php?action=get_shares&file_id=${encodeURIComponent(fileId)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) { throw new Error(`HTTP error! status: ${response.status}`); }
            return await response.json();
        } catch (error) {
            console.error('Could not fetch current shares:', error);
            return [];
        }
    }

    // Build a share row using textContent (no HTML injection possible)
    function buildShareRow(share, fileId, originalFilename) {
        const row = modalCurrentSharesTableBody.insertRow();
        const shareTextCell = row.insertCell();
        const typeCell = row.insertCell();
        const actionCell = row.insertCell();

        const infoSpan = document.createElement('span');
        infoSpan.className = 'share-info';
        const typeSpan = document.createElement('span');
        typeSpan.className = 'share-info';

        if (share.share_type === 'user' && share.shared_with_username) {
            infoSpan.textContent = 'User: ' + share.shared_with_username;
            typeSpan.textContent = 'User';
        } else if (share.share_type === 'email' && share.shared_with_email) {
            infoSpan.textContent = 'Email: ' + share.shared_with_email;
            typeSpan.textContent = 'Email';
        } else {
            infoSpan.textContent = 'Unknown Share';
            shareTextCell.appendChild(infoSpan);
            typeCell.appendChild(typeSpan);
            return;
        }

        const revokeBtn = document.createElement('button');
        revokeBtn.type = 'button';
        revokeBtn.className = 'revoke-share-btn-modal';
        revokeBtn.textContent = 'Revoke';
        revokeBtn.addEventListener('click', () => revokeShare(share.share_id, fileId, originalFilename));

        shareTextCell.appendChild(infoSpan);
        typeCell.appendChild(typeSpan);
        actionCell.appendChild(revokeBtn);
    }

    async function openShareModal(fileId, originalFilename) {
        if (!shareModalOverlay || !modalFileIdInput || !shareModalTitleElement || !modalShareIdentifierInput || !shareModalForm || !modalCurrentSharesTableBody || !currentSharesContainerForModal || !modalShareDivider || !noSharesMessageElement) {
            console.error('Modal elements missing!');
            alert('Error: Share dialog components missing.');
            return;
        }
        modalFileIdInput.value = fileId;
        shareModalTitleElement.textContent = 'Manage Share: ' + originalFilename;
        modalShareIdentifierInput.value = '';

        const currentShares = await fetchCurrentShares(fileId);
        modalCurrentSharesTableBody.innerHTML = '';
        currentShares.forEach(share => buildShareRow(share, fileId, originalFilename));

        const hasShares = currentShares.length > 0;
        currentSharesContainerForModal.style.display = 'block';
        modalShareDivider.style.display = hasShares ? 'block' : 'none';
        noSharesMessageElement.style.display = hasShares ? 'none' : 'block';

        shareModalOverlay.classList.add('active');
        shareModalForm.action = 'index.php#file-row-' + fileId;
    }

    function closeShareModal() {
        if (shareModalOverlay) { shareModalOverlay.classList.remove('active'); }
    }

    async function revokeShare(shareId, fileId, originalFilename) {
        if (!confirm('Are you sure you want to revoke this share?')) { return; }

        try {
            const formData = new FormData();
            formData.append('action', 'revoke_share');
            formData.append('share_id', shareId);
            formData.append('file_id', fileId);
            formData.append('csrf_token', CSRF);

            const response = await fetch('index.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const result = await response.json();
            if (result && result.success) {
                openShareModal(fileId, originalFilename);
            } else {
                alert('Error revoking share: ' + ((result && result.message) || 'Unknown error from server.'));
            }
        } catch (error) {
            console.error('Failed to revoke share via AJAX:', error);
            alert('An error occurred while trying to revoke the share. ' + error.message);
        }
    }

    if (shareModalOverlay) {
        shareModalOverlay.addEventListener('click', (event) => {
            if (event.target === shareModalOverlay) { closeShareModal(); }
        });
    }

    // --- Account modals ---
    const accountModals = {
        'change-name-modal': document.getElementById('change-name-modal'),
        'change-password-modal': document.getElementById('change-password-modal'),
        'delete-account-modal': document.getElementById('delete-account-modal')
    };

    function openAccountModal(modalId) {
        Object.values(accountModals).forEach(modal => {
            if (modal) { modal.classList.remove('active'); }
        });
        closeShareModal();

        const modal = accountModals[modalId];
        if (modal) {
            modal.classList.add('active');
            const firstInput = modal.querySelector('input[type="text"], input[type="password"]');
            if (firstInput) { firstInput.focus(); }
        }
        // Close sidebar when a modal is opened
        const sidebar = document.getElementById('sidebarMenu');
        const hamburger = document.getElementById('hamburgerMenu');
        if (sidebar && sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
            hamburger.classList.remove('active');
        }
    }

    function closeAccountModal(modalId) {
        const modal = accountModals[modalId];
        if (modal) { modal.classList.remove('active'); }
    }

    Object.values(accountModals).forEach(modal => {
        if (modal) {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) { closeAccountModal(modal.id); }
            });
        }
    });

    // --- Delegated click handling for all [data-action] elements ---
    document.addEventListener('click', (event) => {
        const el = event.target.closest('[data-action]');
        if (!el) { return; }
        event.preventDefault();

        switch (el.dataset.action) {
            case 'copy':
                openLinkModal(el.dataset.fileId, el.dataset.filename);
                break;
            case 'share':
                openShareModal(el.dataset.fileId, el.dataset.filename);
                break;
            case 'close-link-modal':
                closeLinkModal();
                break;
            case 'delete':
                openDeleteConfirm([{ id: el.dataset.fileId, name: el.dataset.filename }]);
                break;
            case 'unshare':
                openUnshareConfirm(el.dataset.shareId, el.dataset.fileId, el.dataset.filename);
                break;
            case 'open-modal':
                openAccountModal(el.dataset.modal);
                break;
            case 'close-modal':
                closeAccountModal(el.dataset.modal);
                break;
            case 'close-share-modal':
                closeShareModal();
                break;
            case 'close-delete-confirm':
                closeDeleteConfirm();
                break;
            case 'close-unshare-confirm':
                closeUnshareConfirm();
                break;
        }
    });

    // --- AJAX submits for account management forms ---
    function ajaxSubmit(formId, onSuccess) {
        const form = document.getElementById(formId);
        if (!form) { return; }
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            if (formId === 'deleteAccountForm' &&
                !confirm('This action is irreversible and will delete all your files and account data. Are you absolutely certain?')) {
                return;
            }
            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    onSuccess(form, data);
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    }

    ajaxSubmit('changeUsernameForm', (form) => {
        closeAccountModal('change-name-modal');
        const sidebarHeaderUsername = document.getElementById('sidebarUsername');
        if (sidebarHeaderUsername) {
            sidebarHeaderUsername.textContent = document.getElementById('new_username').value;
        }
        const sidebarHeaderEmail = document.getElementById('sidebarEmail');
        if (sidebarHeaderEmail) {
            sidebarHeaderEmail.textContent = document.getElementById('new_email').value;
        }
    });

    ajaxSubmit('changePasswordForm', (form) => {
        closeAccountModal('change-password-modal');
        form.reset();
    });

    ajaxSubmit('deleteAccountForm', (form, data) => {
        if (data.redirect) { window.location.href = data.redirect; }
    });

    // --- Theme selector ---
    async function loadThemesIntoDropdown() {
        const themeSelector = document.getElementById('themeSelector');
        if (!themeSelector) { return; }

        try {
            const response = await fetch('index.php?action=get_css_files', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) { throw new Error('Failed to fetch CSS files.'); }
            const data = await response.json();

            if (data.success && data.css_files) {
                themeSelector.innerHTML = '';
                data.css_files.forEach(file => {
                    const option = document.createElement('option');
                    option.value = file;
                    option.textContent = file.replace('style_', '').replace('.css', '').replace(/([A-Z])/g, ' $1').trim();
                    if (file === data.current_css) { option.selected = true; }
                    themeSelector.appendChild(option);
                });

                themeSelector.addEventListener('change', async (event) => {
                    const selectedFile = event.target.value;
                    const formData = new FormData();
                    formData.append('action', 'set_css_file');
                    formData.append('css_file', selectedFile);
                    formData.append('csrf_token', CSRF);

                    try {
                        const setResponse = await fetch('index.php', {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        const setData = await setResponse.json();
                        if (setData.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + setData.message);
                            themeSelector.value = data.current_css;
                        }
                    } catch (error) {
                        console.error('Error setting CSS file:', error);
                        alert('An error occurred while changing the theme.');
                        themeSelector.value = data.current_css;
                    }
                });
            } else {
                themeSelector.innerHTML = '<option value="">Error loading themes</option>';
            }
        } catch (error) {
            console.error('Error fetching CSS files:', error);
            themeSelector.innerHTML = '<option value="">Could not load themes</option>';
        }
    }
    loadThemesIntoDropdown();

    // --- Hamburger menu ---
    const hamburgerMenu = document.getElementById('hamburgerMenu');
    const sidebarMenu = document.getElementById('sidebarMenu');

    if (hamburgerMenu && sidebarMenu) {
        hamburgerMenu.addEventListener('click', () => {
            sidebarMenu.classList.toggle('active');
            hamburgerMenu.classList.toggle('active');
        });

        document.addEventListener('click', (event) => {
            if (!sidebarMenu.contains(event.target) && !hamburgerMenu.contains(event.target)) {
                if (sidebarMenu.classList.contains('active')) {
                    sidebarMenu.classList.remove('active');
                    hamburgerMenu.classList.remove('active');
                }
            }
        });
    }

    // --- Clipboard helpers (shared by the public-links modal) ---
    function writeClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).catch(() => fallbackCopyText(text));
        }
        fallbackCopyText(text);
        return Promise.resolve();
    }

    function fallbackCopyText(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        Object.assign(textArea.style, { top: '0', left: '0', position: 'fixed', opacity: '0' });
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try { document.execCommand('copy'); } catch (err) { console.error('Copy fallback failed', err); }
        document.body.removeChild(textArea);
    }

    // =====================================================================
    // Public links modal: create configurable links, list + copy + revoke
    // =====================================================================
    const linkModalOverlay = document.getElementById('linkModalOverlay');
    const linkModalTitle = document.getElementById('linkModalTitle');
    const linkListContainer = document.getElementById('linkListContainer');
    const noLinksMessage = document.getElementById('noLinksMessage');
    const linkExpiry = document.getElementById('linkExpiry');
    const linkMaxDownloads = document.getElementById('linkMaxDownloads');
    const linkPasscode = document.getElementById('linkPasscode');
    const createLinkBtn = document.getElementById('createLinkBtn');
    let currentLinkFileId = null;

    // Compact human-friendly countdown ("in 6d", "Expired 2h ago", "Never").
    function relTime(unix) {
        if (!unix) { return 'Never expires'; }
        const now = Date.now() / 1000;
        let diff = unix - now;
        const past = diff < 0;
        diff = Math.abs(diff);
        const units = [['y', 31536000], ['mo', 2592000], ['d', 86400], ['h', 3600], ['m', 60]];
        let label = 'moments';
        for (let i = 0; i < units.length; i++) {
            if (diff >= units[i][1]) { label = Math.floor(diff / units[i][1]) + units[i][0]; break; }
        }
        return past ? ('Expired ' + label + ' ago') : ('Expires in ' + label);
    }

    function makeBadge(text, extraClass) {
        const b = document.createElement('span');
        b.className = 'link-badge' + (extraClass ? ' ' + extraClass : '');
        b.textContent = text;
        return b;
    }

    function buildLinkItem(link) {
        const item = document.createElement('div');
        item.className = 'link-item' + (link.status !== 'active' ? ' inactive' : '');
        item.dataset.linkId = link.link_id;

        const urlRow = document.createElement('div');
        urlRow.className = 'link-url-row';
        const urlField = document.createElement('input');
        urlField.type = 'text';
        urlField.readOnly = true;
        urlField.className = 'link-url-field';
        urlField.value = link.url;
        urlField.addEventListener('focus', () => urlField.select());
        const copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'link-copy-btn';
        copyBtn.textContent = 'Copy';
        copyBtn.addEventListener('click', () => {
            writeClipboard(link.url);
            copyBtn.textContent = 'Copied!';
            setTimeout(() => { copyBtn.textContent = 'Copy'; }, 1500);
        });
        urlRow.appendChild(urlField);
        urlRow.appendChild(copyBtn);

        const meta = document.createElement('div');
        meta.className = 'link-meta';
        if (link.status === 'expired') {
            meta.appendChild(makeBadge('Expired', 'badge-off'));
        } else if (link.status === 'exhausted') {
            meta.appendChild(makeBadge('Limit reached', 'badge-off'));
        } else {
            meta.appendChild(makeBadge(relTime(link.expires_at_unix)));
        }
        const dl = (link.max_downloads === null)
            ? (link.download_count + ' / ∞')
            : (link.download_count + ' / ' + link.max_downloads);
        meta.appendChild(makeBadge('↓ ' + dl + ' downloads'));
        if (link.has_passcode) { meta.appendChild(makeBadge('🔒 Passcode', 'badge-lock')); }

        const revokeBtn = document.createElement('button');
        revokeBtn.type = 'button';
        revokeBtn.className = 'link-revoke-btn';
        revokeBtn.textContent = 'Revoke';
        revokeBtn.addEventListener('click', () => revokeLink(link.link_id));
        meta.appendChild(revokeBtn);

        item.appendChild(urlRow);
        item.appendChild(meta);
        return item;
    }

    function renderLinks(links) {
        if (!linkListContainer) { return; }
        linkListContainer.innerHTML = '';
        if (!links || !links.length) {
            if (noLinksMessage) { noLinksMessage.style.display = 'block'; }
            return;
        }
        if (noLinksMessage) { noLinksMessage.style.display = 'none'; }
        links.forEach((link) => linkListContainer.appendChild(buildLinkItem(link)));
    }

    async function fetchLinks(fileId) {
        try {
            const res = await fetch('index.php?action=list_share_links&file_id=' + encodeURIComponent(fileId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            const data = await res.json();
            return (data && data.success && data.links) ? data.links : [];
        } catch (e) {
            console.error('Could not fetch links:', e);
            return [];
        }
    }

    async function openLinkModal(fileId, filename) {
        if (!linkModalOverlay || !fileId) { return; }
        currentLinkFileId = fileId;
        if (linkModalTitle) { linkModalTitle.textContent = 'Public Links: ' + (filename || ('File #' + fileId)); }
        if (linkPasscode) { linkPasscode.value = ''; }
        renderLinks([]);
        if (noLinksMessage) { noLinksMessage.style.display = 'block'; noLinksMessage.textContent = 'Loading…'; }
        linkModalOverlay.classList.add('active');
        const links = await fetchLinks(fileId);
        if (currentLinkFileId !== fileId) { return; } // modal changed while loading
        if (noLinksMessage) { noLinksMessage.textContent = 'No links yet.'; }
        renderLinks(links);
    }

    function closeLinkModal() {
        if (linkModalOverlay) { linkModalOverlay.classList.remove('active'); }
        currentLinkFileId = null;
    }

    async function createLink() {
        if (!currentLinkFileId || !createLinkBtn) { return; }
        const original = createLinkBtn.textContent;
        createLinkBtn.disabled = true;
        createLinkBtn.textContent = 'Creating…';
        try {
            const fd = new FormData();
            fd.append('action', 'create_share_link');
            fd.append('file_id', currentLinkFileId);
            fd.append('expiry', linkExpiry ? linkExpiry.value : '7d');
            fd.append('max_downloads', linkMaxDownloads ? linkMaxDownloads.value : '20');
            fd.append('passcode', linkPasscode ? linkPasscode.value : '');
            fd.append('csrf_token', CSRF);

            const res = await fetch('index.php', {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();

            if (data && data.success && data.link) {
                await writeClipboard(data.link.url);
                if (linkPasscode) { linkPasscode.value = ''; }
                createLinkBtn.textContent = 'Link copied to clipboard!';
                const links = await fetchLinks(currentLinkFileId);
                renderLinks(links);
                setTimeout(() => { createLinkBtn.textContent = original; }, 1800);
            } else {
                alert((data && data.message) || 'Could not create link.');
                createLinkBtn.textContent = original;
            }
        } catch (e) {
            console.error('createLink failed', e);
            alert('Could not create link.');
            createLinkBtn.textContent = original;
        } finally {
            createLinkBtn.disabled = false;
        }
    }

    async function revokeLink(linkId) {
        try {
            const fd = new FormData();
            fd.append('action', 'revoke_share_link');
            fd.append('link_id', linkId);
            fd.append('csrf_token', CSRF);
            const res = await fetch('index.php', {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (data && data.success) {
                const links = await fetchLinks(currentLinkFileId);
                renderLinks(links);
            } else {
                alert((data && data.message) || 'Could not revoke link.');
            }
        } catch (e) {
            console.error('revokeLink failed', e);
            alert('Could not revoke link.');
        }
    }

    if (createLinkBtn) { createLinkBtn.addEventListener('click', createLink); }
    if (linkModalOverlay) {
        linkModalOverlay.addEventListener('click', (e) => {
            if (e.target === linkModalOverlay) { closeLinkModal(); }
        });
    }

    // --- Show the summary of the previous upload batch ---
    // Multi-file uploads stash a result summary in sessionStorage, then reload
    // to refresh the list; render that summary here and clear it.
    (function renderUploadSummary() {
        let raw = null;
        try { raw = sessionStorage.getItem('fileoo_upload_summary'); } catch (e) { return; }
        if (!raw) { return; }
        try { sessionStorage.removeItem('fileoo_upload_summary'); } catch (e) { /* ignore */ }

        let results;
        try { results = JSON.parse(raw); } catch (e) { return; }
        if (!Array.isArray(results) || !results.length) { return; }

        const main = document.querySelector('.main-content-area');
        if (!main) { return; }

        const okCount = results.filter((r) => r && r.success).length;
        const frag = document.createDocumentFragment();

        if (okCount > 0) {
            const div = document.createElement('div');
            div.className = 'message success';
            div.textContent = okCount + (okCount === 1 ? ' file uploaded successfully.' : ' files uploaded successfully.');
            frag.appendChild(div);
        }
        results.filter((r) => r && !r.success).forEach((r) => {
            const div = document.createElement('div');
            div.className = 'message error';
            div.textContent = (r && r.message) || ('Failed to upload "' + ((r && r.filename) || 'file') + '".');
            frag.appendChild(div);
        });
        main.insertBefore(frag, main.firstChild);
    })();

    // --- Multi-file upload (file picker + drag & drop) ---
    // The server handles one file per request, so files are uploaded
    // sequentially with per-file progress; when the batch finishes we stash a
    // summary and reload once to refresh the file list.
    const uploadForm = document.getElementById('uploadForm');
    const uploadSubmitBtn = document.getElementById('uploadSubmitBtn');
    const uploadProgress = document.getElementById('uploadProgress');
    const uploadProgressFill = document.getElementById('uploadProgressFill');
    const uploadProgressText = document.getElementById('uploadProgressText');
    const uploadSection = document.getElementById('upload-section');

    if (uploadForm && uploadSubmitBtn && uploadProgress && uploadProgressFill && uploadProgressText) {
        const fileInput = document.getElementById('fileToUpload');
        const maxSizeField = uploadForm.querySelector('input[name="MAX_FILE_SIZE"]');
        const maxSize = maxSizeField ? parseInt(maxSizeField.value, 10) : 0;
        const maxSizeMB = maxSize ? Math.round(maxSize / (1024 * 1024)) : 0;
        const uploadAction = uploadForm.getAttribute('action') || 'index.php';
        const fileSelectName = document.getElementById('fileSelectName');
        let uploading = false;

        // Mirror the native "N files chosen" feedback next to the custom button.
        function updateFileSelectName() {
            if (!fileSelectName) { return; }
            const count = fileInput && fileInput.files ? fileInput.files.length : 0;
            if (count === 0) { fileSelectName.textContent = 'No files chosen'; }
            else if (count === 1) { fileSelectName.textContent = fileInput.files[0].name; }
            else { fileSelectName.textContent = count + ' files selected'; }
        }
        if (fileInput) { fileInput.addEventListener('change', updateFileSelectName); }

        function setProgress(percent, label) {
            const clamped = Math.max(0, Math.min(100, percent));
            uploadProgressFill.style.width = clamped + '%';
            uploadProgressText.textContent = label || (Math.floor(clamped) + '%');
        }

        function showProgressBar() {
            // The themes set display:block on the submit button, which would
            // override the `hidden` attribute — toggle display explicitly.
            uploadSubmitBtn.style.display = 'none';
            uploadProgress.style.display = 'block';
            uploadProgress.classList.remove('processing');
            if (fileInput) { fileInput.disabled = true; }
            setProgress(0);
        }

        // Upload one file; always resolves with { filename, success, message }.
        function uploadOne(file, labelPrefix) {
            return new Promise((resolve) => {
                if (maxSize && file.size > maxSize) {
                    resolve({ filename: file.name, success: false,
                        message: 'UPLOAD_REJECTED: "' + file.name + '" is larger than the ' + maxSizeMB + ' MB limit.' });
                    return;
                }

                const formData = new FormData();
                formData.append('csrf_token', CSRF);
                formData.append('MAX_FILE_SIZE', String(maxSize));
                formData.append('upload_submitted', '1');
                formData.append('fileToUpload', file);

                const xhr = new XMLHttpRequest();
                xhr.open('POST', uploadAction);
                xhr.withCredentials = true;
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.upload.addEventListener('progress', (event) => {
                    if (!event.lengthComputable) { return; }
                    if (event.loaded >= event.total) {
                        // Bytes all sent; server is validating/moving the file.
                        uploadProgress.classList.add('processing');
                        setProgress(100, labelPrefix + 'PROCESSING...');
                    } else {
                        const pct = (event.loaded / event.total) * 100;
                        uploadProgress.classList.remove('processing');
                        setProgress(pct, labelPrefix + Math.floor(pct) + '%');
                    }
                });

                xhr.addEventListener('load', () => {
                    uploadProgress.classList.remove('processing');
                    let data = {};
                    try { data = JSON.parse(xhr.responseText) || {}; } catch (e) { data = {}; }
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve({ filename: file.name, success: !!data.success,
                            message: data.message || (data.success ? '"' + file.name + '" uploaded.' : 'Upload failed for "' + file.name + '".') });
                    } else if (xhr.status === 413) {
                        resolve({ filename: file.name, success: false,
                            message: 'UPLOAD_REJECTED: "' + file.name + '" exceeds the server size limit.' });
                    } else {
                        resolve({ filename: file.name, success: false,
                            message: 'UPLOAD_ERROR: server status ' + xhr.status + ' for "' + file.name + '".' });
                    }
                });

                xhr.addEventListener('error', () => resolve({ filename: file.name, success: false,
                    message: 'UPLOAD_ERROR: network failure for "' + file.name + '".' }));
                xhr.addEventListener('abort', () => resolve({ filename: file.name, success: false,
                    message: 'UPLOAD_ABORTED: "' + file.name + '".' }));

                xhr.send(formData);
            });
        }

        async function uploadFiles(fileList) {
            if (uploading) { return; }
            const files = Array.from(fileList || []);
            if (!files.length) { return; }

            uploading = true;
            showProgressBar();

            const results = [];
            for (let i = 0; i < files.length; i++) {
                const prefix = files.length > 1 ? ('FILE ' + (i + 1) + '/' + files.length + ' · ') : '';
                results.push(await uploadOne(files[i], prefix));
            }

            // Hand the batch result to the reloaded page, which renders it.
            try { sessionStorage.setItem('fileoo_upload_summary', JSON.stringify(results)); } catch (e) { /* ignore */ }
            window.location.href = 'index.php';
        }

        // UPLOAD button: send whatever is currently in the file picker.
        uploadForm.addEventListener('submit', (e) => {
            e.preventDefault();
            if (fileInput && fileInput.files && fileInput.files.length) {
                uploadFiles(fileInput.files);
            }
        });

        // Drag & drop onto the upload card uploads the dropped files immediately.
        if (uploadSection) {
            const setHighlight = (on) => uploadSection.classList.toggle('drag-over', on && !uploading);

            ['dragenter', 'dragover'].forEach((evt) => {
                uploadSection.addEventListener(evt, (e) => {
                    e.preventDefault();
                    setHighlight(true);
                });
            });
            uploadSection.addEventListener('dragleave', (e) => {
                // Ignore the dragleave that fires when moving onto a child node.
                if (!uploadSection.contains(e.relatedTarget)) { setHighlight(false); }
            });
            uploadSection.addEventListener('drop', (e) => {
                e.preventDefault();
                setHighlight(false);
                if (uploading) { return; }
                const dt = e.dataTransfer;
                if (dt && dt.files && dt.files.length) { uploadFiles(dt.files); }
            });
        }
    }

    // Stop the browser from opening a file dropped outside the upload card.
    ['dragover', 'drop'].forEach((evt) => {
        document.addEventListener(evt, (e) => {
            const zone = document.getElementById('upload-section');
            if (!zone || !zone.contains(e.target)) { e.preventDefault(); }
        });
    });

    // =====================================================================
    // File list: sorting, live search, multi-select delete, image preview
    // =====================================================================
    const fileTable = document.getElementById('fileTable');
    const fileTableBody = document.getElementById('fileTableBody');
    const noFilesRow = document.getElementById('noFilesRow');

    // Real file rows only (excludes the "no files / no matches" placeholder).
    function fileRows() {
        return fileTableBody ? Array.from(fileTableBody.querySelectorAll('tr[data-file-id]')) : [];
    }

    function updateLastVisibleRow() {
        const rows = fileRows();
        let lastVisible = null;
        rows.forEach((row) => {
            row.classList.remove('last-visible-row');
            if (row.style.display !== 'none') {
                lastVisible = row;
            }
        });
        if (lastVisible) {
            lastVisible.classList.add('last-visible-row');
        }
    }

    // ---------- Sorting (File / Size / Date; default Date DESC) ----------
    if (fileTable && fileTableBody) {
        let sortKey = 'date';
        let sortDir = 'desc';

        function updateSortArrows() {
            fileTable.querySelectorAll('.sort-btn').forEach((btn) => {
                const arrow = btn.querySelector('.sort-arrow');
                const active = btn.dataset.sort === sortKey;
                btn.classList.toggle('active', active);
                if (arrow) { arrow.textContent = active ? (sortDir === 'asc' ? ' ▲' : ' ▼') : ''; }
            });
        }

        function applySort() {
            const rows = fileRows();
            rows.sort((a, b) => {
                let cmp;
                if (sortKey === 'name') {
                    cmp = (a.dataset.filename || '').localeCompare(b.dataset.filename || '');
                } else if (sortKey === 'size') {
                    cmp = (parseInt(a.dataset.size, 10) || 0) - (parseInt(b.dataset.size, 10) || 0);
                } else {
                    cmp = (parseInt(a.dataset.date, 10) || 0) - (parseInt(b.dataset.date, 10) || 0);
                }
                return sortDir === 'asc' ? cmp : -cmp;
            });
            rows.forEach((r) => fileTableBody.insertBefore(r, noFilesRow));
            updateSortArrows();
            updateLastVisibleRow();
        }

        function onSortClick(btn) {
            const key = btn.dataset.sort;
            if (key === sortKey) {
                sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                sortKey = key;
                sortDir = (key === 'name') ? 'asc' : 'desc'; // names A→Z, size/date large→small
            }
            applySort();
        }

        fileTable.querySelectorAll('.sort-btn').forEach((btn) => {
            btn.addEventListener('click', () => onSortClick(btn));
            btn.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSortClick(btn); }
            });
        });

        applySort(); // establish the default (newest first) + show the arrow
    }

    // ---------- Live search / filter ----------
    const searchToggle = document.getElementById('searchToggle');
    const searchBox = document.getElementById('searchBox');
    const searchInput = document.getElementById('fileSearchInput');
    const searchClear = document.getElementById('searchClear');

    function applyFilter() {
        const q = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
        const rows = fileRows();
        let visible = 0;
        rows.forEach((row) => {
            const match = q === '' || (row.dataset.filename || '').indexOf(q) !== -1;
            row.style.display = match ? '' : 'none';
            if (match) { visible++; }
        });
        if (noFilesRow) {
            if (rows.length === 0) {
                noFilesRow.hidden = false;
                noFilesRow.querySelector('td').textContent = '';
            } else if (visible === 0) {
                noFilesRow.hidden = false;
                noFilesRow.querySelector('td').textContent = '// NO MATCHES //';
            } else {
                noFilesRow.hidden = true;
            }
        }
        if (searchToggle) { searchToggle.classList.toggle('active', q !== ''); }
        updateLastVisibleRow();
    }

    if (searchToggle && searchBox && searchInput) {
        const openSearch = () => { searchBox.hidden = false; searchInput.focus(); searchInput.select(); };
        const closeSearch = () => { searchBox.hidden = true; };

        searchToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            if (searchBox.hidden) { openSearch(); } else { closeSearch(); }
        });
        searchInput.addEventListener('input', applyFilter);
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); closeSearch(); }
            else if (e.key === 'Escape') { closeSearch(); }
        });
        searchBox.addEventListener('click', (e) => e.stopPropagation());
        if (searchClear) {
            searchClear.addEventListener('click', () => { searchInput.value = ''; applyFilter(); searchInput.focus(); });
        }
        // Click outside closes the box; the current filter stays applied.
        document.addEventListener('click', (e) => {
            if (searchBox.hidden) { return; }
            if (!searchBox.contains(e.target) && !searchToggle.contains(e.target)) { closeSearch(); }
        });
    }

    // ---------- Multi-select + bulk actions (download zip & delete) ----------
    const selectAll = document.getElementById('selectAll');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const bulkCount = document.getElementById('bulkCount');
    const bulkDownloadBtn = document.getElementById('bulkDownloadBtn');
    const bulkDlCount = document.getElementById('bulkDlCount');
    const bulkDownloadForm = document.getElementById('bulkDownloadForm');
    const bulkDownloadInputs = document.getElementById('bulkDownloadInputs');

    function rowCheckboxes() { return Array.from(document.querySelectorAll('.row-select')); }
    function selectedCheckboxes() { return rowCheckboxes().filter((cb) => cb.checked); }

    function refreshBulkUI() {
        const all = rowCheckboxes();
        const sel = selectedCheckboxes();
        if (bulkCount) { bulkCount.textContent = sel.length; }
        if (bulkDlCount) { bulkDlCount.textContent = sel.length; }
        if (bulkDeleteBtn) { bulkDeleteBtn.hidden = sel.length === 0; }
        if (bulkDownloadBtn) { bulkDownloadBtn.hidden = sel.length < 2; }
        if (selectAll) {
            selectAll.checked = all.length > 0 && sel.length === all.length;
            selectAll.indeterminate = sel.length > 0 && sel.length < all.length;
        }
        all.forEach((cb) => {
            const tr = cb.closest('tr');
            if (tr) { tr.classList.toggle('row-selected', cb.checked); }
        });
    }

    rowCheckboxes().forEach((cb) => cb.addEventListener('change', refreshBulkUI));

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            rowCheckboxes().forEach((cb) => {
                const tr = cb.closest('tr');
                if (tr && tr.style.display !== 'none') { cb.checked = selectAll.checked; }
            });
            refreshBulkUI();
        });
    }

    if (bulkDownloadBtn && bulkDownloadForm && bulkDownloadInputs) {
        bulkDownloadBtn.addEventListener('click', () => {
            const sel = selectedCheckboxes();
            if (sel.length < 2) { return; }
            if (sel.length > 50) {
                alert('Maximum 50 files can be downloaded at once. Please select 50 or fewer files.');
                return;
            }

            // Calculate total size across selected files
            let totalBytes = 0;
            sel.forEach((cb) => {
                const tr = cb.closest('tr');
                if (tr && tr.dataset.size) {
                    totalBytes += parseInt(tr.dataset.size, 10) || 0;
                }
            });

            const maxBytes = 200 * 1024 * 1024;
            if (totalBytes > maxBytes) {
                const mb = (totalBytes / (1024 * 1024)).toFixed(1);
                alert('Selected files exceed the 200 MB limit (' + mb + ' MB). Please select fewer files.');
                return;
            }

            // Populate form inputs
            bulkDownloadInputs.innerHTML = '';
            sel.forEach((cb) => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'file_ids[]';
                inp.value = cb.dataset.fileId;
                bulkDownloadInputs.appendChild(inp);
            });

            // Brief UI feedback then submit download
            const originalText = bulkDownloadBtn.innerHTML;
            bulkDownloadBtn.textContent = 'ZIPPING...';
            bulkDownloadBtn.disabled = true;
            setTimeout(() => {
                bulkDownloadBtn.innerHTML = originalText;
                bulkDownloadBtn.disabled = false;
            }, 2500);

            bulkDownloadForm.submit();
        });
    }

    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', () => {
            const sel = selectedCheckboxes();
            if (!sel.length) { return; }
            openDeleteConfirm(sel.map((cb) => ({ id: cb.dataset.fileId, name: cb.dataset.filename })));
        });
    }

    // ---------- Delete confirmation modal (replaces window.confirm) ----------
    const deleteConfirmModal = document.getElementById('delete-confirm-modal');
    const deleteConfirmList = document.getElementById('deleteConfirmList');
    const deleteConfirmBtn = document.getElementById('deleteConfirmBtn');
    const deleteConfirmTitle = document.getElementById('deleteConfirmTitle');
    let pendingDeleteIds = [];

    function openDeleteConfirm(items) {
        pendingDeleteIds = items.map((it) => it.id).filter(Boolean);
        if (!pendingDeleteIds.length) { return; }
        if (deleteConfirmTitle) {
            deleteConfirmTitle.textContent = pendingDeleteIds.length === 1
                ? 'Delete File' : ('Delete ' + pendingDeleteIds.length + ' Files');
        }
        if (deleteConfirmList) {
            deleteConfirmList.innerHTML = '';
            items.forEach((it) => {
                const li = document.createElement('li');
                li.textContent = it.name || ('File #' + it.id);
                deleteConfirmList.appendChild(li);
            });
        }
        if (deleteConfirmModal) { deleteConfirmModal.classList.add('active'); }
    }

    function closeDeleteConfirm() {
        if (deleteConfirmModal) { deleteConfirmModal.classList.remove('active'); }
        pendingDeleteIds = [];
    }

    if (deleteConfirmBtn) {
        deleteConfirmBtn.addEventListener('click', () => {
            if (!pendingDeleteIds.length) { return; }
            postAction({ action: 'delete_selected', file_ids: pendingDeleteIds.join(',') });
        });
    }
    if (deleteConfirmModal) {
        deleteConfirmModal.addEventListener('click', (e) => {
            if (e.target === deleteConfirmModal) { closeDeleteConfirm(); }
        });
    }

    // ---------- Unshare confirmation modal (replaces window.confirm) ----------
    const unshareConfirmModal = document.getElementById('unshare-confirm-modal');
    const unshareConfirmList = document.getElementById('unshareConfirmList');
    const unshareConfirmBtn = document.getElementById('unshareConfirmBtn');
    let pendingUnshareData = null;

    function openUnshareConfirm(shareId, fileId, filename) {
        pendingUnshareData = { shareId, fileId };
        if (unshareConfirmList) {
            unshareConfirmList.innerHTML = '';
            const li = document.createElement('li');
            li.textContent = filename || ('File #' + fileId);
            unshareConfirmList.appendChild(li);
        }
        if (unshareConfirmModal) { unshareConfirmModal.classList.add('active'); }
    }

    function closeUnshareConfirm() {
        if (unshareConfirmModal) { unshareConfirmModal.classList.remove('active'); }
        pendingUnshareData = null;
    }

    if (unshareConfirmBtn) {
        unshareConfirmBtn.addEventListener('click', () => {
            if (!pendingUnshareData) { return; }
            postAction({
                action: 'unshare_self',
                share_id: pendingUnshareData.shareId,
                file_id: pendingUnshareData.fileId
            });
        });
    }
    if (unshareConfirmModal) {
        unshareConfirmModal.addEventListener('click', (e) => {
            if (e.target === unshareConfirmModal) { closeUnshareConfirm(); }
        });
    }

    // ---------- Image preview (Desktop hover tooltip + Lightbox modal on tap/click) ----------
    const previewPopup = document.getElementById('previewPopup');
    const previewPopupImg = document.getElementById('previewPopupImg');
    const lightboxModal = document.getElementById('imageLightboxModal');
    const lightboxImg = document.getElementById('lightboxImg');
    const lightboxFilename = document.getElementById('lightboxFilename');
    const lightboxDownloadBtn = document.getElementById('lightboxDownloadBtn');

    // Lightbox functions (Mobile tap / Desktop click)
    function openLightbox(icon) {
        if (!lightboxModal || !icon) return;
        const thumbUrl = icon.dataset.thumbUrl || '';
        const fullUrl = icon.dataset.fullUrl || thumbUrl;
        const dlUrl = icon.dataset.downloadUrl || fullUrl;
        const filename = icon.dataset.filename || 'Image Preview';

        if (lightboxFilename) lightboxFilename.textContent = filename;
        if (lightboxDownloadBtn) {
            lightboxDownloadBtn.href = dlUrl;
            lightboxDownloadBtn.setAttribute('download', filename);
        }
        if (lightboxImg) {
            lightboxImg.onerror = function() {
                if (thumbUrl && this.src !== thumbUrl && !this.src.endsWith(thumbUrl)) {
                    this.src = thumbUrl;
                }
            };
            lightboxImg.src = fullUrl || thumbUrl;
        }
        lightboxModal.hidden = false;
        document.body.style.overflow = 'hidden';
        if (previewPopup) { previewPopup.hidden = true; }
    }

    function closeLightbox() {
        if (!lightboxModal || lightboxModal.hidden) return;
        lightboxModal.hidden = true;
        if (lightboxImg) lightboxImg.removeAttribute('src');
        document.body.style.overflow = '';
    }

    if (lightboxModal) {
        lightboxModal.addEventListener('click', (e) => {
            if (e.target.dataset.action === 'close-lightbox' || e.target.classList.contains('lightbox-backdrop') || e.target === lightboxModal) {
                closeLightbox();
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !lightboxModal.hidden) {
                closeLightbox();
            }
        });
    }

    if (previewPopup && previewPopupImg) {
        let hideTimer = null;
        let currentIcon = null;

        function positionPreview(icon) {
            const rect = icon.getBoundingClientRect();
            const margin = 10;
            const pw = previewPopup.offsetWidth || 240;
            const ph = previewPopup.offsetHeight || 180;
            let left = rect.right + margin;
            if (left + pw > window.innerWidth - margin) { left = rect.left - pw - margin; }
            if (left < margin) { left = margin; }
            let top = rect.top;
            if (top + ph > window.innerHeight - margin) { top = window.innerHeight - ph - margin; }
            if (top < margin) { top = margin; }
            previewPopup.style.left = left + 'px';
            previewPopup.style.top = top + 'px';
        }

        function showPreview(icon) {
            if (!icon.dataset.thumbUrl) { return; }
            if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
            currentIcon = icon;
            previewPopupImg.src = icon.dataset.thumbUrl;
            previewPopup.hidden = false;
            positionPreview(icon);
        }
        function hidePreview() {
            previewPopup.hidden = true;
            previewPopupImg.removeAttribute('src');
            currentIcon = null;
        }
        function scheduleHide() { hideTimer = setTimeout(hidePreview, 80); }

        previewPopupImg.addEventListener('load', () => { if (currentIcon && !previewPopup.hidden) { positionPreview(currentIcon); } });

        // Desktop mouse hover tooltip
        document.addEventListener('mouseover', (e) => {
            const icon = e.target.closest('.preview-icon');
            if (icon && (!lightboxModal || lightboxModal.hidden)) { showPreview(icon); }
        });
        document.addEventListener('mouseout', (e) => {
            if (e.target.closest('.preview-icon')) { scheduleHide(); }
        });
        previewPopup.addEventListener('mouseover', () => { if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; } });
        previewPopup.addEventListener('mouseout', scheduleHide);

        // Click / Tap on .preview-icon opens Lightbox Modal (for both mobile touch and desktop click)
        document.addEventListener('click', (e) => {
            const icon = e.target.closest('.preview-icon');
            if (icon) {
                e.preventDefault();
                e.stopPropagation();
                hidePreview();
                openLightbox(icon);
            } else if (!previewPopup.contains(e.target)) {
                hidePreview();
            }
        });
        window.addEventListener('scroll', hidePreview, true);
    }
});
