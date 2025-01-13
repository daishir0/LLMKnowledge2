<?php
require_once '../common/config.php';
require_once '../common/functions.php';
require_once '../common/auth.php';

// セッション認証
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ログインが必要です。']);
    exit;
}

// パラメータの検証
$id = $_GET['id'] ?? 0;
if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '無効なIDです。']);
    exit;
}

try {
    // レコードの取得
    $stmt = $pdo->prepare("SELECT title, text FROM record WHERE id = :id AND deleted = 0");
    $stmt->execute([':id' => $id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'レコードが見つかりません。']);
        exit;
    }

    // ファイル名の安全な生成（マルチバイト文字対応）
    $filename = $record['title'];
    
    // ファイル名から使用できない文字を削除
    $filename = preg_replace('/[\/\\\?\*:\"\|<>]/', '', $filename);
    
    // 拡張子の追加
    $filename .= '.txt';

    // ヘッダーの設定（マルチバイト文字対応）
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // テキストの出力
    echo $record['text'];
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
    exit;
}