<?php
session_start();
require_once 'config.php';

function checkAuth() {
    global $auth_config;
    
    if (!isset($_SESSION['user']) || !isset($_SESSION['last_activity'])) {
        return false;
    }
    
    if (time() - $_SESSION['last_activity'] > $auth_config['session_timeout']) {
        session_destroy();
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

function login($username, $password) {
    global $auth_config;
    
    if ($username === $auth_config['username'] && 
        sha1($password) === $auth_config['password']) {
        $_SESSION['user'] = $username;
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    return false;
}

function logout() {
    session_destroy();
}

// ログインページでない場合は認証チェック
$current_page = basename($_SERVER['PHP_SELF']);
if ($current_page !== 'login.php' && !checkAuth()) {
    header('Location: ' . BASE_URL . '/common/login.php');
    exit;
}