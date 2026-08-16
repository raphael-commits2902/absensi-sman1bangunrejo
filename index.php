<?php
session_start();
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/index.php");
    } elseif ($_SESSION['role'] === 'guru') {
        header("Location: guru/index.php");
    } else {
        header("Location: student/index.php");
    }
    exit;
}
header("Location: login.php");
exit;
?>