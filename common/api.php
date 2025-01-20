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
                    $stmt = $pdo->prepare("SELECT text, reference, group_id FROM record WHERE id = :id AND deleted = 0");
                } elseif ($sourceType === 'knowledge') {
                    $stmt = $pdo->prepare("SELECT answer as text, reference FROM knowledge WHERE id = :id AND deleted = 0");
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

                        // プロンプト内容を取得
                        $promptContent = $prompt['content'];
                        
                        // referenceが存在する場合、プロンプト内の{{reference}}を置換
                        if (!empty($source['reference'])) {
                            $promptContent = str_replace('{{reference}}', $source['reference'], $promptContent);
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO tasks (
                                source_type,
                                source_id,
                                source_text,
                                prompt_content,
                                prompt_id,
                                group_id,
                                created_by,
                                created_at,
                                updated_at,
                                status
                            ) VALUES (
                                :source_type,
                                :source_id,
                                :source_text,
                                :prompt_content,
                                :prompt_id,
                                :group_id,
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
                            ':prompt_content' => $promptContent,  // 置換後のプロンプト内容を使用
                            ':prompt_id' => $promptId,
                            ':group_id' => $source['group_id'],
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
            if (!isset($_SESSION['user'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'ログインが必要です。']);
                exit;
            }

            $title = $_POST['title'] ?? '';
            $text = $_POST['text'] ?? '';
            $reference = $_POST['reference'] ?? '';

            if ($title && $text) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO record (title, text, reference, created_at, updated_at)
                        VALUES (:title, :text, :reference, '$timestamp', '$timestamp')
                    ");
                    
                    $stmt->execute([
                        ':title' => $title,
                        ':text' => $text,
                        ':reference' => $reference
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

        case 'delete_records':
            if (!isset($_SESSION['user'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'ログインが必要です。']);
                exit;
            }

            if (!isset($_POST['record_ids']) || !is_array($_POST['record_ids'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => '削除対象が指定されていません。']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    UPDATE record
                    SET deleted = 1,
                        updated_at = '$timestamp'
                    WHERE id = :id
                    AND deleted = 0
                ");

                foreach ($_POST['record_ids'] as $id) {
                    $stmt->execute([':id' => $id]);
                }

                $pdo->commit();
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'プレーンナレッジを削除しました。']);
            } catch (Exception $e) {
                $pdo->rollBack();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
            }
            break;

        case 'add_records':
            if (!isset($_SESSION['user'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'ログインが必要です。']);
                exit;
            }

            if (!isset($_POST['group_id']) || !isset($_POST['newdata'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => '必要なデータが不足しています。']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    INSERT INTO record (
                        title,
                        text,
                        reference,
                        group_id,
                        created_by,
                        created_at,
                        updated_at
                    ) VALUES (
                        :title,
                        :text,
                        :reference,
                        :group_id,
                        :created_by,
                        '$timestamp',
                        '$timestamp'
                    )
                ");

                $newRecords = explode("\n", trim($_POST['newdata']));
                $successCount = 0;

                foreach ($newRecords as $newdata) {
                    $newdata = trim($newdata);
                    if (empty($newdata)) continue;

                    // カンマが含まれる場合は、カンマまでを参照情報とする
                    $commaPos = strpos($newdata, ',');
                    $reference = $commaPos !== false ? substr($newdata, 0, $commaPos) : $newdata;

                    $stmt->execute([
                        ':title' => $newdata,
                        ':text' => '',
                        ':reference' => $reference,
                        ':group_id' => $_POST['group_id'],
                        ':created_by' => $_SESSION['user']
                    ]);
                    $successCount++;
                }

                $pdo->commit();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $successCount . '件のプレーンナレッジを追加しました。'
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
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