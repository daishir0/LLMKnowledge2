<?php
require_once 'config.php';
require_once 'functions.php';
session_start();

// Bearer認証のチェック関数を追加
function isValidBearerToken() {
    global $api_config;
    
    $headers = apache_request_headers();
    if (!isset($headers['Authorization'])) {
        return false;
    }
    
    if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
        $token = $matches[1];
        return $token === $api_config['bulk']['api_key'];
    }
    
    return false;
}

// 認証チェック関数
function isAuthenticated() {
    return isset($_SESSION['user']) || isValidBearerToken();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'create_task':
            if (!isAuthenticated()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => '認証が必要です。']);
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
                                :prompt_content,  // 置換後のプロンプト内容を使用
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
                            ':prompt_content' => $promptContent,
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
            if (!isAuthenticated()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => '認証が必要です。']);
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
            if (!isAuthenticated()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => '認証が必要です。']);
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
            if (!isAuthenticated()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => '認証が必要です。']);
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

        case 'bulk_task_register_by_group':
            if (!isAuthenticated()) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'message' => '認証が必要です。'
                ]);
                exit;
            }

            // グループIDの取得
            $group_id = $_POST['group_id'] ?? null;
            if (!$group_id) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'message' => 'グループIDが指定されていません。'
                ]);
                exit;
            }

            try {
                // トランザクション開始
                $pdo->beginTransaction();

                // グループとプロンプト情報の取得
                $stmt = $pdo->prepare("
                    SELECT g.id, g.prompt_id, p.content as prompt_content, g.task_executed_at
                    FROM groups g
                    LEFT JOIN prompts p ON g.prompt_id = p.id
                    WHERE g.id = :group_id AND g.deleted = 0
                ");
                $stmt->execute([':group_id' => $group_id]);
                $group = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$group || !$group['prompt_id']) {
                    throw new Exception('グループまたはプロンプトが見つかりません。');
                }

                // 対象のレコードを取得（更新日時条件付き）
                $records_stmt = $pdo->prepare("
                    SELECT r.id, r.text, r.reference 
                    FROM record r
                    WHERE r.group_id = :group_id 
                    AND r.deleted = 0
                    AND (
                        :task_executed_at IS NULL 
                        OR r.updated_at > :task_executed_at
                    )
                ");
                $records_stmt->execute([
                    ':group_id' => $group_id,
                    ':task_executed_at' => $group['task_executed_at']
                ]);
                $records = $records_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($records)) {
                    throw new Exception('タスク登録対象のレコードがありません。');
                }

                // 既存のナレッジを論理削除（更新日時条件追加）
                $delete_knowledge_stmt = $pdo->prepare("
                    UPDATE knowledge 
                    SET deleted = 1, updated_at = '$timestamp'
                    WHERE group_id = :group_id 
                    AND parent_type = 'record'
                    AND parent_id IN (
                        SELECT id FROM record 
                        WHERE group_id = :group_id 
                        AND deleted = 0
                        AND (
                            :task_executed_at IS NULL 
                            OR updated_at > :task_executed_at
                        )
                    )
                ");
                $delete_knowledge_stmt->execute([
                    ':group_id' => $group_id,
                    ':task_executed_at' => $group['task_executed_at']
                ]);

                // タスクの一括登録
                $tasks_stmt = $pdo->prepare("
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
                        'record',
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

                // グループのプロンプト内容
                $prompt_content = $group['prompt_content'];

                // タスク登録処理
                foreach ($records as $record) {
                    // referenceがある場合、プロンプト内の{{reference}}を置換
                    $current_prompt_content = $prompt_content;
                    if (!empty($record['reference'])) {
                        $current_prompt_content = str_replace('{{reference}}', $record['reference'], $current_prompt_content);
                    }

                    $tasks_stmt->execute([
                        ':source_id' => $record['id'],
                        ':source_text' => $record['text'],
                        ':prompt_content' => $current_prompt_content,
                        ':prompt_id' => $group['prompt_id'],
                        ':group_id' => $group_id,
                        ':created_by' => $_SESSION['user']
                    ]);
                }

                // グループの最終タスク実行日時を更新
                $update_group_stmt = $pdo->prepare("
                    UPDATE groups 
                    SET task_executed_at = '$timestamp'
                    WHERE id = :group_id
                ");
                $update_group_stmt->execute([':group_id' => $group_id]);

                // トランザクションコミット
                $pdo->commit();

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true, 
                    'message' => count($records) . '件のタスクを登録しました。'
                ]);

            } catch (Exception $e) {
                // トランザクションロールバック
                $pdo->rollBack();

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'message' => 'エラーが発生しました: ' . $e->getMessage()
                ]);
            }
            break;

        case 'force_bulk_task_register_by_group':
            if (!isAuthenticated()) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'message' => '認証が必要です。'
                ]);
                exit;
            }

            // グループIDの取得
            $group_id = $_POST['group_id'] ?? null;
            if (!$group_id) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'message' => 'グループIDが指定されていません。'
                ]);
                exit;
            }

            try {
                // トランザクション開始
                $pdo->beginTransaction();

                // グループとプロンプト情報の取得
                $stmt = $pdo->prepare("
                    SELECT g.id, g.prompt_id, p.content as prompt_content
                    FROM groups g
                    LEFT JOIN prompts p ON g.prompt_id = p.id
                    WHERE g.id = :group_id AND g.deleted = 0
                ");
                $stmt->execute([':group_id' => $group_id]);
                $group = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$group || !$group['prompt_id']) {
                    throw new Exception('グループまたはプロンプトが見つかりません。');
                }

                // 対象のレコードを取得（すべてのアクティブなレコード）
                $records_stmt = $pdo->prepare("
                    SELECT r.id, r.text, r.reference 
                    FROM record r
                    WHERE r.group_id = :group_id 
                    AND r.deleted = 0
                ");
                $records_stmt->execute([':group_id' => $group_id]);
                $records = $records_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($records)) {
                    throw new Exception('タスク登録対象のレコードがありません。');
                }

                // 既存のナレッジを論理削除
                $delete_knowledge_stmt = $pdo->prepare("
                    UPDATE knowledge 
                    SET deleted = 1, updated_at = '$timestamp'
                    WHERE group_id = :group_id 
                    AND parent_type = 'record'
                    AND deleted = 0
                ");
                $delete_knowledge_stmt->execute([':group_id' => $group_id]);

                // タスクの一括登録
                $tasks_stmt = $pdo->prepare("
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
                        'record',
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

                // グループのプロンプト内容
                $prompt_content = $group['prompt_content'];

                // タスク登録処理
                foreach ($records as $record) {
                    // referenceがある場合、プロンプト内の{{reference}}を置換
                    $current_prompt_content = $prompt_content;
                    if (!empty($record['reference'])) {
                        $current_prompt_content = str_replace('{{reference}}', $record['reference'], $current_prompt_content);
                    }

                    $tasks_stmt->execute([
                        ':source_id' => $record['id'],
                        ':source_text' => $record['text'],
                        ':prompt_content' => $current_prompt_content,
                        ':prompt_id' => $group['prompt_id'],
                        ':group_id' => $group_id,
                        ':created_by' => $_SESSION['user']
                    ]);
                }

                // グループの最終タスク実行日時を更新
                $update_group_stmt = $pdo->prepare("
                    UPDATE groups 
                    SET task_executed_at = '$timestamp'
                    WHERE id = :group_id
                ");
                $update_group_stmt->execute([':group_id' => $group_id]);

                // トランザクションコミット
                $pdo->commit();

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true, 
                    'message' => count($records) . '件のタスクを登録しました。'
                ]);

            } catch (Exception $e) {
                // トランザクションロールバック
                $pdo->rollBack();

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'message' => 'エラーが発生しました: ' . $e->getMessage()
                ]);
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