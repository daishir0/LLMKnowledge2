<?php
// エラーログの設定、初期設定時に変えること(TODO)
ini_set('log_errors', 0);
ini_set('error_log', dirname(__FILE__) . '/logs.txt');
error_reporting(E_ALL);

// タイムゾーンを日本時間に設定、初期設定時に変えること(TODO)
date_default_timezone_set('Asia/Tokyo');
$timestamp = date('Y-m-d H:i:s'); // JSTで現在時刻を取得

// アプリケーションのベースURLを設定、初期設定時に変えること(TODO)
$root_url = 'https://example.com';  // ルートURL
$base_url = '/LLMKnowledge2';  // Webルートからの相対パス
define('BASE_URL', $base_url);

// システム名を定義、初期設定時に変えること(TODO)
define('SYSTEM_NAME', 'LLMKnowledge2');

// ヘッダーの色を定義（HTMLカラーコード）
define('HEADER_COLOR', '#000000');

// ランダム引用文の表示フラグ（1:表示する、0:表示しない）
define('SHOW_RANDOM_QUOTES', 0);

$db_path = dirname(__DIR__) . '/knowledge.db';
$pdo = new PDO("sqlite:$db_path");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 認証設定、初期設定時に変えること(TODO)
$auth_config = [
    'username' => 'admin',
    'password' => '', // パスワードをsha1でハッシュ化して設定してください
    'session_timeout' => 3600 // セッションタイムアウト（秒）
];

// API設定
$api_config = [
    'markitdown' => [
        'api_key' => '', // MarkItDownServerの APIキーを設定してください see https://github.com/daishir0/MarkItDownServer
        'base_url' => 'https://mark-it-down-server.url',
    ],
    'bulk' => [
        'api_key' => '', // Bulk APIのキーを設定してください
    ],
];

return [
    'auth' => $auth_config,
    'api' => $api_config,
]; 