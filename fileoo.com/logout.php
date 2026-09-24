<?php
// fileoo.com/logout.php
require_once 'config.php';

$_SESSION = array();
session_destroy();

header("location: index.php"); // Redirect to login page (now index.php at root)
exit;
?>