<?php
session_start();
if (empty($_SESSION['user'])) {
    $_SESSION['after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /gradeapp/login.php');
    exit;
}
