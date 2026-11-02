
<?php
session_start();

header("Cache-Control: no-store");

if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
} else {
    header("Location: login.php");
}

exit;
