<?php
// fileoo.com/index.php — front controller
// Routes to the dashboard (logged in) or the login page (logged out).
define('FILEOO_APP', true);
require_once __DIR__ . '/config.php';

if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION["id"])) {
    require __DIR__ . '/dashboard.php';
} else {
    require __DIR__ . '/login.php';
}
