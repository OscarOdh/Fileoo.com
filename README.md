# FILEOO

**A self-hosted file locker with real sharing controls**

Upload files, keep them behind a login, and hand out links that expire, cap their
own download count, and can require a passcode. Runs on ordinary shared PHP
hosting. No framework, no Composer, no build step.

---

## What problem does this solve?

You want to send someone a file. Email bounces it for being too big. Dropbox
wants both of you to have accounts. WeTransfer gives you a link with no control,
and once it's out, it's out. You can't take it back or see whether anyone used
it.

FILEOO is the small, self-hosted middle ground. You run it on your own hosting,
so the files are on your disk and your terms. You get accounts, per-user storage
quotas, and two genuinely different ways to share:

- **Share with a person**, either another FILEOO user or an email address that
  hasn't signed up yet. When they do sign up with that address, the file is
  already waiting.
- **Share with a link**, for anyone with the URL and no account needed. But
  *you* set how long it lives, how many downloads it allows, and whether it needs
  a passcode. Revoke it whenever you want.

Two design decisions define the whole codebase.

**Uploaded files never live inside the website folder.** They're written to a
directory the web server won't serve, and the only way to get bytes out is
`download.php`, which checks permissions on every single request. There is no URL
that maps directly to a file on disk, so a leaked filename is worth nothing.

**Nothing secret is in the source.** Database credentials, CAPTCHA keys and mail
credentials all come from a `.env` file outside the webroot. If one is missing,
the app refuses to start rather than falling back to something baked into a PHP
file.

---

## Features

**Drag, drop, done.** Drop files anywhere on the upload card, or use the picker.
Multiple files at once, uploaded one after another with a live per-file progress
bar that tells you `FILE 3/7 · 64%`, then flips to `PROCESSING...` while the
server validates and files it away.

**Share links you actually control.** Every public link gets its own settings.
Expire in 1 hour, 1 day, 7 days, 30 days, 1 year, or never. Cap it at N downloads
or leave it unlimited. Add a passcode and the recipient hits a lock screen before
the download starts. The dashboard shows each link's live download count and
whether it's active, expired, or exhausted, and one click revokes it.

**Passcode gates that link previews can't burn.** A protected link only counts a
download after the correct passcode is submitted. Chat apps and crawlers that
fetch your link to build a preview card don't silently eat one of your downloads.

**Share with people who aren't users yet.** Share to an email address and the
grant sits waiting. The moment someone registers with that address, the file
appears in their dashboard. No invitation flow, no pending-state UI.

**Bulk download as a ZIP.** Tick several files and get one archive, with
duplicate names automatically renamed `report.pdf`, `report (1).pdf`. Capped at
50 files and 200 MB per archive.

**Image previews, two ways.** Hover a thumbnail on desktop and a preview floats
next to your cursor. Tap it on mobile and it opens a full lightbox. Thumbnails
are generated on upload, cached as PNGs, and like everything else, served only
through the permission check.

**A storage gauge you can read at a glance.** A 270° ring showing your percentage
used, drawn in pure SVG with no chart library, that collapses to a slim bar on
mobile. Quotas are per-user, so individual accounts can be given more room.

**Sort, search, select.** Click a column to sort by name, size, or date. Hit the
search icon for live filtering as you type. Tick multiple rows for bulk delete or
bulk download. All client-side, all instant.

**Four themes, saved to your account.** Matrix, Dark, Pink, and the base style,
picked from the sidebar, stored against your user, applied on every device you
log in from. The Matrix theme comes with a live falling-characters canvas
background.

**Built with the security boring and done.** CSRF tokens on every state-changing
request. Login rate limiting at 5 failures per IP per 15 minutes. Cloudflare
Turnstile on registration. Passwords hashed with `password_hash()`. Password
reset tokens stored only as SHA-256 hashes with a 1-hour expiry. A Content
Security Policy that forbids inline scripts outright. Prepared statements
everywhere, with emulation off.

**Deploys by copying files.** It's PHP. Upload the folder, create the database
tables, write a `.env`. There is no build, no bundler, no dependency install, no
Node, no Composer.

---

## Requirements

| What | Notes |
|---|---|
| PHP 8.1+ | Built and running against `ea-php81` on cPanel |
| MySQL or MariaDB | Six tables; schema included in `SQL/` |
| Apache with `.htaccess` support | Carries the security headers and CSP |
| PHP `pdo_mysql` | Database access |
| PHP `gd` | Image thumbnails. Optional, since thumbnail generation fails gracefully without it. |
| PHP `zip` | Bulk ZIP downloads. Optional, since the feature reports a clear error without it. |
| A [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/) site key and secret | **Required.** Registration won't work without it, and `config.php` refuses to start. |
| An SMTP account | Optional. Only used for password-reset emails. Without it, reset requests silently no-op and log a warning. |

---

## Deploying it

**Copy the folder structure as-is.** The repo is already laid out the way the
server needs it, and that layout is the security model: only `fileoo.com/` is
web-accessible, and your secrets and uploaded files sit one level above it where
no HTTP request can reach them.

```
your-hosting-account/
├── .env                  ← your settings. NOT web-accessible.
├── fileoo_uploads/       ← uploaded files land here. NOT web-accessible.
└── public_html/          ← everything inside fileoo.com/ goes here
    ├── index.php
    ├── config.php
    ├── .htaccess
    └── ...
```

Your web root might be called `public_html`, `www`, or `htdocs` depending on the
host. That folder gets the **contents of `fileoo.com/`**, not the folder itself.
`.env` and `fileoo_uploads/` go in its parent.

### 1. Upload

Drop the contents of `fileoo.com/` into your web root, then put `.env` and
`fileoo_uploads/` one level above it.

Check that `.htaccess` and `.user.ini` made it across. FTP clients and file
managers hide dot-files by default, and missing them is the single most common
way this deploy goes wrong: you lose the security headers and your upload limit
silently drops to a couple of megabytes.

Then open `php.ini` and point the log at a real writable path:

```ini
error_log = "/path/to/php.error.log"        ← change this
```

On cPanel that's usually `/home/youraccount/logs/php.error.log`. Leave the
placeholder in and PHP errors go nowhere, which makes every problem below much
harder to diagnose.

### 2. Create the database

Make an empty MySQL database, then run the files in `SQL/` in this order, since
they have foreign keys between them:

```
users.sql  →  user_files.sql  →  file_shares.sql  →  share_links.sql  →  password_resets.sql
```

In phpMyAdmin: select your database first, then Import, one file at a time.

Skip `login_attempts.sql`. The app creates that table itself on first load. And
the schema files use unqualified table names, so they build into whichever
database you already have selected.

### 3. Fill in `.env`

Every value ships as `YYYY`. Replace them with your own. The app returns a 500 on
startup if any required one is still blank, so you can't half-configure it by
accident.

```ini
# Required. The app dies with a 500 if any of these are missing.
DB_SERVER=localhost
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
DB_NAME=your_db_name
TURNSTILE_SITE_KEY=0x4AAA...
TURNSTILE_SECRET_KEY=0x4AAA...

# Optional but strongly recommended
APP_URL=https://yourdomain.com        # pins the canonical base URL. Without it,
                                      # links are built from the Host header,
                                      # which an attacker can forge.

# Optional
UPLOAD_DIR=../fileoo_uploads          # default. Must be outside the web root.
TRUST_CF_CONNECTING_IP=1              # ONLY if your origin is reachable *exclusively*
                                      # through Cloudflare. See the warning below.

# Optional. Password reset emails. Omit and resets just don't send.
SMTP_HOST=mail.yourdomain.com
SMTP_PORT=465
SMTP_USERNAME=help@yourdomain.com
SMTP_PASSWORD=...
SMTP_FROM_EMAIL=help@yourdomain.com
```

### 4. Open the site

Register the first account and you're running. There's no admin panel and no
setup wizard, so the first account is just an account. To give someone more
space, edit `users.quota_mb` for their row.

---

### If something's wrong

**Blank page, or "Server configuration error".** A required value in `.env` is
still `YYYY`, or the file isn't where `config.php` can see it. It checks
`../.env` first, then `./.env`.

**Uploads fail above a few MB.** Your host ignored `.user.ini`. Set
`upload_max_filesize` and `post_max_size` to `150M` in the cPanel MultiPHP INI
Editor instead. These must match `MAX_FILE_SIZE` in `config.php`, which is also
150 MB. If PHP's limit is the lower one, uploads die before any of the app's own
code runs.

**Errors vanish instead of logging.** `php.ini` ships with a placeholder
`error_log` path. Point it somewhere writable.

**Registration won't submit.** The Turnstile keys are wrong. Both the site key
and the secret key are required, and they must be from the same Turnstile widget.

**No password reset emails.** The `SMTP_*` values are optional, and resets fail
silently without them. Check your error log for `[fileoo]` lines.

### Optional: a separate upload subdomain

Production runs uploads and downloads through `upload.fileoo.com`, a second
domain pointed at the same files, to keep large uploads off the main hostname.
`config.php` detects that host and emits the CORS headers for it, and the session
cookie domain is set so a login is shared across both.

Skip it and everything runs on one host, which is simpler and works fine.

### A note on the deployed layout

On the production server, `.env`, `.htaccess` and `.user.ini` sit **outside** the
app directory. The copies in this repo are for local development and reference.

---

## How it works, file by file

### Request flow, in one paragraph

Everything enters through `index.php`. It loads `config.php`, which connects to
the database, starts the session, and defines every helper, then checks whether
you're logged in, then `require`s either `dashboard.php` or `login.php`. Those
two files check that a constant called `FILEOO_APP` is defined and redirect if it
isn't, so browsing directly to `dashboard.php` can't bypass the login check.
`dashboard.php` handles any POST action, loads the file list, and hands off to
`dashboard_view.php` for rendering.

### `config.php`, the foundation

Every other file's first line loads this. It does seven things in order.

**Loads the environment.** A small hand-written `.env` parser, no library. It
reads `KEY=value` lines, strips quotes, skips `#` comments, and pushes each into
`putenv()`. It checks `../.env` first, then `./.env`.

**Fails closed on missing config.** Six variables are required. If any is absent,
it logs the specific missing key server-side, returns HTTP 500, and dies with a
generic message. There is deliberately **no fallback to a hardcoded default**,
because that pattern is how credentials end up in git history.

**Resolves and protects the upload directory.** `UPLOAD_DIR` is resolved to an
absolute path, created if missing, and given a `.htaccess` containing
`Require all denied`. That's belt-and-braces: the directory is already outside
the web root, so the `.htaccess` only matters if someone later misconfigures the
server. `THUMB_DIR` lives inside it, so the same denial covers thumbnails.

**Connects with PDO.** Three options are set explicitly. `ERRMODE_EXCEPTION` so
failures throw instead of silently returning `false`. `FETCH_ASSOC` so rows are
plain associative arrays. And the important one, `EMULATE_PREPARES => false`,
which forces *real* server-side prepared statements. With emulation on, PDO
builds the query string in PHP and the "parameterized" query is really string
interpolation with escaping. Turning it off means the values never touch the SQL
text.

**Hardens the session.** `use_strict_mode` on, so the server won't accept a
session ID it didn't issue, which is the defence against session fixation. A
shared save path so both hostnames see the same sessions. `httponly` so
JavaScript can't read the cookie. `SameSite=Lax` against CSRF. And `secure` set
dynamically from whether the request is HTTPS, so local HTTP development still
works without a config change.

**Generates a CSRF token.** One 32-byte random token per session, checked on
every state-changing request with `hash_equals()`, which is constant-time so
response timing can't leak the correct value.

**Defines the shared helpers:**

| Function | What it does |
|---|---|
| `db_query()` | Prepare and execute. Errors are logged with the query and parameters, and the user sees a generic message, so no schema leaks. Pass `$throw = true` inside a transaction so the caller can roll back. |
| `verify_csrf_token()` | Constant-time token comparison. |
| `generate_random_string()` | `random_bytes` to hex. Cryptographically secure, not `rand()`. |
| `format_size()` | Bytes to `1.4 GB`. |
| `send_json_response()` | Clears the output buffer, sets the status and JSON header, exits. The buffer clear matters, because a stray warning printed earlier would otherwise corrupt the JSON. |
| `redirect_with_messages()` | Post/Redirect/Get. Stash flash messages in the session, redirect, so refreshing the page doesn't re-submit the form. |
| `client_ip()` | The real client IP. Behind Cloudflare, `REMOTE_ADDR` is a Cloudflare edge IP shared by thousands of sites, so rate-limiting on it would lock out strangers. Set `TRUST_CF_CONNECTING_IP=1` to use the `CF-Connecting-IP` header instead, but **only** if your origin can't be reached directly, because otherwise anyone can forge that header. |
| `image_mime_map()` | The single canonical list of extensions treated as previewable images. One definition, used by upload, inline view, and thumbnailing, so the three can't drift apart. **SVG is absent on purpose**, because SVG files can contain `<script>` and rendering one inline would be stored XSS. |
| `create_thumbnail()` | Downscales to a 400px PNG. Calls `getimagesize()` first and bails above 50 megapixels, so a "decompression bomb" (a small file that expands to gigabytes in memory) can't exhaust the server. Returns `false` on any failure so a bad thumbnail never blocks an upload. |
| `app_base_url()` | The canonical base URL. Prefers `APP_URL` from `.env`, because the alternative, `$_SERVER['HTTP_HOST']`, is attacker-controlled. A forged Host header would otherwise put an attacker's domain into a password-reset email. |
| `send_mail_smtp()` | A complete SMTP client written directly on sockets. See below. |

**About `send_mail_smtp()`:** it speaks SMTP by hand (`EHLO`, `AUTH LOGIN` with
base64 credentials, `MAIL FROM`, `RCPT TO`, `DATA`) over a TLS socket, reading
and verifying each numeric response code. No PHPMailer, no Composer. It also
tries four connection targets in order: the resolved IPv4 of the mail host, the
hostname itself, `localhost`, then `127.0.0.1`. That sequence exists because on
cPanel the mail server usually runs on the same machine, while
`mail.yourdomain.com` may be proxied by Cloudflare, which doesn't proxy SMTP, so
the public name fails and the local one works.

### `index.php`, the front controller

Eleven lines. Defines `FILEOO_APP`, loads `config.php`, and routes to the
dashboard or the login page. Its only job is to be the single entry point, so
there's exactly one place the auth check happens.

### `login.php`, authentication

Before checking any password, it enforces a rate limit. Delete `login_attempts`
rows older than 15 minutes, count what's left for this IP, and refuse at 5 or
more. Failures insert a row; a success deletes all rows for that IP.

The lookup accepts username *or* email in one query. On success it calls
`session_regenerate_id(true)`, issuing a brand-new session ID at the moment
privileges change, so a session ID an attacker planted before login is worthless
after it.

Note what the error messages *don't* say. A wrong username and a wrong password
both return `ACCESS_DENIED: Invalid ID or Passcode.` Distinguishing them would
turn the login form into a tool for discovering which accounts exist.

### `register.php`, account creation

CSRF check, then server-side Turnstile verification, where the CAPTCHA token is
POSTed to Cloudflare's `siteverify` endpoint with your secret key. Doing this on
the server is the entire point, since a client-side-only CAPTCHA is decoration.

Usernames are restricted to letters, numbers and underscores. Passwords require
10 or more characters with upper, lower, and a digit. Email is **optional**, and
when it's blank it's stored as SQL `NULL`, not `""`. That's deliberate: the
`email` column has a `UNIQUE` index, and MySQL allows unlimited `NULL`s in a
unique index but only one empty string. Storing `""` would mean only one account
could ever exist without an email.

Passwords go through `password_hash()` with `PASSWORD_DEFAULT`, which is bcrypt
today and will follow PHP's default forward automatically.

### `forgot.php` and `reset.php`, password recovery

`forgot.php` generates a 32-byte token, stores **only its SHA-256 hash**, and
emails the raw token in a link. So the reset table is useless to anyone who
steals it, because the hashes can't be turned back into working links. Tokens
expire in 1 hour, expired rows are purged on every page load, and requesting a
new reset deletes any previous one.

The critical detail: **the response is identical whether or not the email
exists.** Always "Check your inbox." An attacker can't use the form to find out
which addresses have accounts.

`reset.php` hashes the token from the URL and looks for a matching unexpired row.
It re-validates password complexity server-side, never trusting that the
browser's `minlength` was honoured, then updates the hash and deletes every reset
token for that user, so the link is single-use.

### `dashboard.php`, the controller

Roughly 675 lines, structured as one big `if/elseif` chain over
`$_POST['action']`. Every branch is behind a single CSRF check at the top, so no
action can skip it.

**Upload.** Checks the PHP error code, then a strict extension allowlist (images,
documents, text, archives, audio, video, and explicitly not SVG), then the size
limit, then the per-user quota. Quota usage is re-read from the database on every
single request, which is what makes a sequential multi-file batch correct. File 4
of 7 is checked against the space remaining *after* files 1 to 3 landed.

The stored filename is *not* the uploaded filename. It's sanitized to
`[a-zA-Z0-9_.-]`, plus a 16-character random suffix, with a collision loop on
top. So two people uploading `resume.pdf` get different files on disk, and a
malicious filename can't escape the directory.

Then a best-effort thumbnail, then the database row. The original filename is
stored separately and only ever used as a download name.

**Share with a user or email.** Looks up the identifier as a username or email
first. If no account matches and it's a valid email address, it records a pending
email share. Blocks sharing with yourself and blocks duplicates.

**Create a public link.** The expiry is chosen from a **hardcoded map** of
allowed values rather than accepting a date from the client, so user input never
reaches the date arithmetic. The download cap is clamped to 100,000. An optional
passcode is hashed with `password_hash()`, never stored raw. The token is 24
random bytes as 48 hex characters, unguessable by construction.

Note the permission rule here. You can create a public link for a file shared
*with* you, not only files you own. Revoking, however, is restricted to links you
created yourself.

**Delete file(s).** Ownership is verified per file. Physical file and cached
thumbnail are unlinked, then the row is deleted. `file_shares` and `share_links`
rows disappear automatically through their `ON DELETE CASCADE` foreign keys.

**Delete account.** The one place a transaction is used. Filenames are collected
*first*, the database work is committed, and **only then** are the physical files
removed. Deleting files before the commit would mean a failed transaction leaves
database rows pointing at files that no longer exist. This is the branch that
passes `$throw = true` to `db_query()`, so a failure reaches the `catch` and
rolls back instead of dying mid-transaction.

**Theme switching.** `basename()` strips any directory component from the
requested filename, the extension is checked to be `.css`, and the file must
exist. Three independent guards against path traversal on what is otherwise a
user-supplied filename.

**GET actions** handle the read-only AJAX: listing a file's shares, listing its
public links (computing `active` / `expired` / `exhausted` server-side), and
enumerating available themes by scanning the `css/` folder.

**Finally**, the file list query. One statement returns owned files and
shared-with-you files together, with the owner's username joined in and the
viewer's own `share_id` resolved by a correlated subquery, so the view can render
an "unshare me" button without running a query per row.

### `dashboard_view.php`, the rendering layer

Pure presentation. Receives `$files_list`, `$storage_used`, `$storage_quota` and
the theme from the controller.

The storage gauge is worth a look. It's an SVG circle with `r=80`, drawn as a
270° arc by setting `stroke-dasharray` to 75% of the circumference and rotating
it 135°. The filled portion is a second circle with a dash length proportional to
usage. All the geometry is computed in PHP before any HTML is written. There's
even a detail for the near-empty case: at under 0.5 units of fill,
`stroke-linecap` switches from `round` to `butt`, because a rounded cap on a
zero-length line renders as a stray dot.

Every inline value passes through `htmlspecialchars()`. CSS and JS are loaded
with `?v=<filemtime>` so a deploy automatically busts browser and CDN caches,
which is what lets `.htaccess` mark those files `immutable` with a one-year TTL.
The CSRF token is published in a `<meta>` tag for JavaScript to read.

### `download.php`, the only way out

Every byte a user ever receives comes through this file. Four modes.

**Public share link (`?t=<token>`).** No login. Looks the token up, checks expiry
and download cap, and if a passcode is set, renders a self-contained lock screen.
That screen has its own inline CSS and no external assets, because the CSP
forbids cross-origin resources and it has to work on the upload subdomain too.
The download count increments only *after* a correct passcode, on a POST.

**Bulk ZIP (POST).** Requires login and a CSRF token. Validates access to every
requested file in one query, enforces 2 to 50 files and a 200 MB total, builds
the archive in a temp file, and registers a shutdown function to delete it, so
the temp file is cleaned up even if the script dies mid-stream.

**Authenticated download.** A single SQL statement does the permission check,
`LEFT JOIN`-ing both share tables and requiring that you're the owner *or* have a
user share *or* have an email share. There's no separate "can this user see
this?" function to forget to call, because the permission is part of the query
that fetches the file.

**Thumbnail (`&thumb=1`).** Serves the cached PNG, generating it on demand if
it's missing. Never serves the original bytes.

Two details that matter.

- **Inline viewing is allowlisted.** `?view` only renders inline for the raster
  formats in `image_mime_map()`. Everything else is a forced attachment
  download. And the `Content-Type` for an inline image comes from the *stored
  file extension*, not the `filetype` column, because that column holds a MIME
  type derived at upload time. Pinning the response to a trusted value stops a
  crafted file being served as something executable.
- **`X-Content-Type-Options: nosniff`** on every response, so a browser can't
  second-guess the declared type and decide a file is HTML.

### `js/dashboard.js`, the front end

About 1,200 lines of plain JavaScript in one IIFE. No jQuery, no framework, no
build step. Highlights:

**Uploads.** The server handles one file per request, so `uploadFiles()` loops
with `await`, uploading sequentially. Each `uploadOne()` returns a promise that
**always resolves** and never rejects, so one failed file can't abort the batch.
Per-file progress comes from `xhr.upload.onprogress`. Once all bytes are sent it
switches to a `PROCESSING...` state, because the server still has to validate and
move the file and the byte counter would otherwise sit at 100% looking frozen.
When the batch finishes, results go into `sessionStorage` and the page reloads
once. The reloaded page reads the summary and renders it. One reload for any
number of files.

**Drag and drop** with a subtlety: `dragleave` fires when the pointer moves onto
a *child* element, which would make the highlight flicker. The handler checks
`uploadSection.contains(e.relatedTarget)` and ignores those.

**`postAction()`** builds and submits a real hidden form for actions that should
follow the Post/Redirect/Get pattern, while genuinely asynchronous things use
`fetch`. Two patterns, used deliberately rather than by accident.

**Delegated events.** One document-level click listener handles every
`[data-action]` element, so rows added or changed later work without rebinding.

**Modals instead of `confirm()`.** Delete and unshare both use custom modals,
which are stylable, themeable, and can show exactly which files are about to go.

**Clipboard** uses the async `navigator.clipboard` API with a
`document.execCommand('copy')` fallback for insecure contexts, since the modern
API requires HTTPS.

**Sorting, filtering and multi-select** are all DOM operations on rows already on
the page, with no round trips.

### `js/matrix.js`, the background

A `<canvas>` of falling katakana. It reads its colors from CSS custom properties
via `getComputedStyle`, so it re-themes itself with the rest of the page instead
of hardcoding green.

### `css/`, the themes

| File | Role |
|---|---|
| `style.css` | Login, register, forgot, reset. The logged-out pages. Not offered as a theme. |
| `dashboard-ui.css` | Structural layout, modals, tables. Loaded alongside whichever theme is active. Also excluded from the theme picker. |
| `style_Matrix.css` | Default. Terminal green, with the falling-character canvas. |
| `style_Dark.css` | Dark neutral. |
| `style_Pink.css` | Pink. |

The theme dropdown is built by scanning the folder and excluding `style.css` and
`dashboard-ui.css`, so dropping a new `style_Whatever.css` into `css/` makes it
appear in the picker with no code change.

### `SQL/`, the schema

| Table | What it holds |
|---|---|
| `users` | Account, `password_hash`, optional unique email, `selected_css` theme preference, `quota_mb`. |
| `user_files` | One row per upload. `original_filename` (what the user sees) is separate from `stored_filename` (what's on disk, unique). Cascades from `users`. |
| `file_shares` | Person-to-person shares. `share_type` is `user` or `email`; the unused column stays `NULL`. Cascades from both `user_files` and `users`. |
| `share_links` | Public links: token, `max_downloads` and `expires_at` (both nullable, meaning unlimited and never), `download_count`, `passcode_hash`. |
| `password_resets` | `token_hash` only, never the raw token, plus expiry. |
| `login_attempts` | IP and timestamp, indexed on both. Auto-created by `config.php`. |

Cascading deletes do real work here. Removing a file removes its shares and its
public links, and removing a user removes everything they own. The application
code never has to remember to clean up.

### `.htaccess`, the server-side security layer

Disables directory listing. Denies HTTP access to `.env`, `.git`, `.htaccess`,
`composer.json` and `package.json`. Sets `X-Frame-Options`,
`X-Content-Type-Options`, `Referrer-Policy`, and a Content Security Policy that
allows scripts only from this origin plus Cloudflare Turnstile, with **no
`unsafe-inline` for scripts**. That's why every piece of JavaScript in the
project lives in a `.js` file rather than in a `<script>` tag.

It also sets one-year `immutable` caching on CSS and JS. That's only safe because
every reference carries a `?v=<filemtime>`, so a changed file gets a new URL and
there's nothing stale to serve.

### Supporting files

| File | What it is |
|---|---|
| `logout.php` | Clears `$_SESSION` and destroys it. |
| `clear_cache.php` | Calls `opcache_reset()`. A deploy convenience, since PHP's bytecode cache can otherwise keep serving the previous version of a file for minutes. |
| `.user.ini` | Per-directory PHP settings: 150 MB uploads, errors logged not displayed, `expose_php` off, session hardening. |
| `php.ini` | cPanel-generated error log path. |
| `help.html` | A full in-app user guide: overview, getting started, uploads and quota, table actions, sharing, FAQs. |
| `images/` | Row action icons. |

---

## Security notes

Things that are handled properly:

- Files stored outside the web root, with every byte gated by a permission check
- Real prepared statements (`EMULATE_PREPARES => false`)
- CSRF tokens on all state-changing requests, compared in constant time
- `password_hash()` and `password_verify()` throughout, including link passcodes
- Reset tokens stored as hashes, 1-hour expiry, single use
- Login rate limiting, with identical errors for bad username and bad password
- Identical responses from the forgot-password form regardless of account existence
- `session_regenerate_id()` on login, and `use_strict_mode` on
- A CSP with no `unsafe-inline` for scripts
- An upload extension allowlist that excludes SVG, and inline rendering limited
  to raster images with a server-chosen MIME type
- Decompression-bomb guard on thumbnail generation

Things to be aware of:

- **`TRUST_CF_CONNECTING_IP` is dangerous if misused.** Only enable it when your
  origin cannot be reached except through Cloudflare. Otherwise anyone can set
  the header and bypass login rate limiting entirely.
- **`send_mail_smtp()` disables TLS certificate verification** (`verify_peer`
  false). That's there for cPanel self-signed certificates on localhost, but it
  means the SMTP connection is encrypted without being authenticated.
- **`clear_cache.php` is publicly reachable.** It only resets OPcache, so the
  impact is a brief performance dip, but there's no reason for it to be
  unauthenticated. Consider protecting it or deleting it after deploys.
- **There is no virus scanning.** The extension allowlist stops a file being
  *executed by the server*, but a `.zip` or `.docx` a user downloads is whatever
  was uploaded.
- **Quotas are the only abuse limit.** There's no upload rate limit and no cap on
  how many share links a user can generate.

---

## Known limits

- **No folders.** One flat list per user. Sorting and search cover a lot, but
  there's no hierarchy.
- **No resumable uploads.** A dropped connection at 140 MB of a 150 MB file means
  starting over.
- **No admin interface.** Quotas are changed with SQL.
- **150 MB per file, 200 MB per ZIP**, both hardcoded, and the 150 must be kept
  in sync between `config.php` and `.user.ini`.
- **Apache-shaped.** The security headers and the CSP live in `.htaccess`. On
  nginx you'd need to port all of it, and losing it silently would remove the
  CSP.
- **Sequential uploads only.** Simple and reliable, but a batch of small files is
  slower than it needs to be.
