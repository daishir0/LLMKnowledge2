<?php
require_once 'config.php';

// エラーレポート設定
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/logs.txt');
error_reporting(E_ALL);

// レスポンスヘッダー設定
header('Content-Type: application/json; charset=UTF-8');

// Bearerトークンの取得と検証
function getBearerToken() {
    $headers = apache_request_headers();
    if (!isset($headers['Authorization'])) {
        return null;
    }
    if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
        return $matches[1];
    }
    return null;
}

// APIキーの検証
$token = getBearerToken();
if (!$token || $token !== $api_config['bulk']['api_key']) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

// リクエストメソッドの取得
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'get_records') {
                // group_idの取得
                $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
                if (!$group_id) {
                    throw new Exception('Invalid group_id');
                }

                // レコードの取得
                $stmt = $pdo->prepare('SELECT * FROM record WHERE group_id = ? AND deleted = 0');
                $stmt->execute([$group_id]);
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'records' => $records]);
            }
            break;

        case 'POST':
            if ($action === 'update_record') {
                $data = json_decode(file_get_contents('php://input'), true);
                if (!isset($data['id']) || !isset($data['text'])) {
                    throw new Exception('Invalid request data');
                }

                // レコードの更新
                $stmt = $pdo->prepare('UPDATE record SET text = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([$data['text'], $timestamp, $data['id']]);

                echo json_encode(['success' => true]);
            }
            break;

        default:
            throw new Exception('Invalid request method');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} 