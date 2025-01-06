<?php
// logout.php
session_start();
session_destroy();
require_once 'config.php';

header('Location: ' . BASE_URL . '/common/login.php');
exit;