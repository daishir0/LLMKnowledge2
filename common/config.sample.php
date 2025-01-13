<?php
// エラーログの設定
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/logs.txt');
error_reporting(E_ALL);

// タイムゾーンを日本時間に設定
date_default_timezone_set('Asia/Tokyo');

// アプリケーションのベースURLを設定
$base_url = '/LLMKnowledge2';  // Webルートからの相対パス
define('BASE_URL', $base_url);

$db_path = dirname(__DIR__) . '/knowledge.db';
$pdo = new PDO("sqlite:$db_path");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// タイムゾーンをJSTに設定
$pdo->exec("PRAGMA timezone = '+09:00'");

// 認証設定
$auth_config = [
    'username' => 'admin',
    'password' => '', // パスワードをsha1でハッシュ化して設定してください
    'session_timeout' => 3600 // セッションタイムアウト（秒）
];

// API設定
$api_config = [
    'openai' => [
        'api_key' => '', // OpenAI APIキーを設定してください
        'base_url' => 'https://api.openai.com/v1',
    ],
    'claude' => [
        'api_key' => '', // Anthropic APIキーを設定してください
        'base_url' => 'https://api.anthropic.com/v1',
    ],
    'markitdown' => [
        'api_key' => '', // MarkItDownServerの APIキーを設定してください
        'base_url' => 'https://mark-it-down-server.url',
    ],
];

return [
    'auth' => $auth_config,
    'api' => $api_config,
]; 