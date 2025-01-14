<?php
require_once 'config.php';
require_once 'functions.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'create_task':
            if (!isset($_SESSION['user'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'ログインが必要です。']);
                exit;
            }

            $sourceId = $_POST['source_id'] ?? null;
            $promptId = $_POST['prompt_id'] ?? null;
            $sourceType = $_POST['source_type'] ?? null;

            if ($sourceId && $promptId && $sourceType) {
                $stmt = $pdo->prepare("SELECT content FROM prompts WHERE id = :id AND deleted = 0");
                $stmt->execute([':id' => $promptId]);
                $prompt = $stmt->fetch(PDO::FETCH_ASSOC);

                // ソースタイプに応じてソーステキストを取得
                if ($sourceType === 'record') {
                    $stmt = $pdo->prepare("SELECT text FROM record WHERE id = :id AND deleted = 0");
                } elseif ($sourceType === 'knowledge') {
                    $stmt = $pdo->prepare("SELECT answer as text FROM knowledge WHERE id = :id AND deleted = 0");
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => '不正なソースタイプです。']);
                    exit;
                }
                $stmt->execute([':id' => $sourceId]);
                $source = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($prompt && $source) {
                    try {
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("
                            INSERT INTO tasks (
                                source_type,
                                source_id,
                                source_text,
                                prompt_content,
                                created_by,
                                created_at,
                                updated_at,
                                status
                            ) VALUES (
                                :source_type,
                                :source_id,
                                :source_text,
                                :prompt_content,
                                :created_by,
                                '$timestamp',
                                '$timestamp',
                                'pending'
                            )
                        ");
                        
                        $stmt->execute([
                            ':source_type' => $sourceType,
                            ':source_id' => $sourceId,
                            ':source_text' => $source['text'],
                            ':prompt_content' => $prompt['content'],
                            ':created_by' => $_SESSION['user']
                        ]);

                        $pdo->commit();
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => 'タスクが作成されました。']);
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
                    }
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'プロンプトまたはソースが見つかりません。']);
                }
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => '必要なデータが不足しています。']);
            }
            break;

        case 'create_record':
            $title = $_POST['title'] ?? '';
            $text = $_POST['text'] ?? '';

            if ($title && $text) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO record (title, text, created_at, updated_at)
                        VALUES (:title, :text, '$timestamp', '$timestamp')
                    ");
                    
                    $stmt->execute([
                        ':title' => $title,
                        ':text' => $text
                    ]);

                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Record created successfully.']);
                } catch (PDOException $e) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
                }
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'タイトルと内容が必要です。']);
            }
            break;

        default:
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => '不正なアクションです。']);
            break;
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '不正なリクエストです。']);
}
?>