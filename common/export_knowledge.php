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
    // ナレッジの取得
    $stmt = $pdo->prepare("
        SELECT title, question, answer, reference 
        FROM knowledge 
        WHERE id = :id AND deleted = 0
    ");
    $stmt->execute([':id' => $id]);
    $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$knowledge) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ナレッジが見つかりません。']);
        exit;
    }

    // ファイル名の安全な生成
    $filename = preg_replace('/[\/\\\?\*:\"\|<>]/', '', $knowledge['title']);
    $filename .= '.txt';

    // エクスポートするテキストの生成
    $exportText = "タイトル: " . $knowledge['title'] . "\n\n";
    $exportText .= "Question:\n" . $knowledge['question'] . "\n\n";
    $exportText .= "Answer:\n" . $knowledge['answer'] . "\n\n";
    
    if (!empty($knowledge['reference'])) {
        $exportText .= "Reference:\n" . $knowledge['reference'] . "\n";
    }

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