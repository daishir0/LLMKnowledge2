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
$group_id = $_GET['group_id'] ?? 0;
if (!$group_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '無効なグループIDです。']);
    exit;
}

try {
    // グループ名の取得
    $stmt = $pdo->prepare("
        SELECT name
        FROM groups
        WHERE id = :group_id AND deleted = 0
    ");
    $stmt->execute([':group_id' => $group_id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'グループが見つかりません。']);
        exit;
    }

    // グループ内のプレーンナレッジを取得
    $stmt = $pdo->prepare("
        SELECT title, text
        FROM record
        WHERE group_id = :group_id AND deleted = 0
        ORDER BY created_at
    ");
    $stmt->execute([':group_id' => $group_id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($records)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'このグループにはプレーンナレッジが存在しません。']);
        exit;
    }

    // エクスポートするテキストの生成
    $exportText = "";
    foreach ($records as $record) {
        $exportText .= $record['text'] . "\n\n";
    }

    // ファイル名の生成
    $filename = 'plain_group_' . $group_id . '_' . preg_replace('/[\/\\\?\*:\"\|<>]/', '', $group['name']) . '.txt';

    // ヘッダーの設定
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // テキストの出力
    echo $exportText;
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
    exit;
}