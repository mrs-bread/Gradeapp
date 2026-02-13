<?php
session_start();
$_SESSION = [];
session_destroy();
header('Location: /gradeapp/login.php');
exit;
