<?php
// エラーハンドリングの設定
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// 予期せぬエラーのハンドリング
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Fatal Error: ' . $error['message'],
            'details' => [
                'error_type' => 'FatalError',
                'error_file' => basename($error['file']),
                'error_line' => $error['line']
            ]
        ]);
        exit;
    }
});

require_once 'config.php';
require_once 'functions.php';
session_start();

// グローバルなtry-catchでラップ
try {

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
                                :prompt_content,
                                :prompt_id,
                                :group_id,
                                :created_by,
                                '$timestamp',
                                '$timestamp',
                                'pending'
                            )
                        ");
                        
                        $stmt->bindValue(':source_type', $sourceType, PDO::PARAM_STR);
                        $stmt->bindValue(':source_id', $sourceId, PDO::PARAM_INT);
                        $stmt->bindValue(':source_text', $source['text'], PDO::PARAM_STR);
                        $stmt->bindValue(':prompt_content', $promptContent, PDO::PARAM_STR);
                        $stmt->bindValue(':prompt_id', $promptId, PDO::PARAM_INT);
                        $stmt->bindValue(':group_id', $source['group_id'], PDO::PARAM_INT);
                        $stmt->bindValue(':created_by', $_SESSION['user'], PDO::PARAM_STR);
                        $stmt->execute();

                        $pdo->commit();
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => 'タスクが作成されました。']);
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => 'データベースエラー: ' . $e->getMessage(),
                            'details' => [
                                'error_type' => 'PDOException',
                                'error_code' => $e->getCode(),
                                'error_file' => basename($e->getFile()),
                                'error_line' => $e->getLine()
                            ]
                        ]);
                    }
                } else {
                    header('Content-Type: application/json');
                    $errorDetail = '';
                    if (!$prompt) {
                        $errorDetail .= "プロンプトID: {$promptId} が見つかりません。";
                    }
                    if (!$source) {
                        $errorDetail .= $errorDetail ? "\n" : "";
                        $errorDetail .= "ソース {$sourceType}(ID: {$sourceId}) が見つかりません。";
                    }
                    echo json_encode(['success' => false, 'message' => "データが見つかりません。\n{$errorDetail}"]);
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
                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'データベースエラー: ' . $e->getMessage(),
                        'details' => [
                            'error_type' => get_class($e),
                            'error_code' => $e->getCode(),
                            'error_file' => basename($e->getFile()),
                            'error_line' => $e->getLine()
                        ]
                    ]);
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
                    $errorDetail = '';
                    if (!$group) {
                        $errorDetail .= "グループID: {$group_id} が見つかりません。";
                    } elseif (!$group['prompt_id']) {
                        $errorDetail .= "グループID: {$group_id} にプロンプトが設定されていません。";
                    }
                    throw new Exception("データが見つかりません。\n{$errorDetail}");
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
                    throw new Exception("タスク登録対象のレコードがありません。\nグループID: {$group_id}" .
                        ($group['task_executed_at'] ? "\n最終実行日時: {$group['task_executed_at']}" : ""));
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

                    $tasks_stmt->bindValue(':source_id', $record['id'], PDO::PARAM_INT);
                    $tasks_stmt->bindValue(':source_text', $record['text'], PDO::PARAM_STR);
                    $tasks_stmt->bindValue(':prompt_content', $current_prompt_content, PDO::PARAM_STR);
                    $tasks_stmt->bindValue(':prompt_id', $group['prompt_id'], PDO::PARAM_INT);
                    $tasks_stmt->bindValue(':group_id', $group_id, PDO::PARAM_INT);
                    $tasks_stmt->bindValue(':created_by', $_SESSION['user'], PDO::PARAM_STR);
                    $tasks_stmt->execute();
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

                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'エラーが発生しました: ' . $e->getMessage(),
                    'details' => [
                        'error_type' => get_class($e),
                        'error_code' => $e->getCode(),
                        'error_file' => basename($e->getFile()),
                        'error_line' => $e->getLine(),
                        'group_id' => $group_id
                    ]
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
                // グループとプロンプト情報の取得（トランザクション外で実行）
                $stmt = $pdo->prepare("
                    SELECT g.id, g.prompt_id, p.content as prompt_content
                    FROM groups g
                    LEFT JOIN prompts p ON g.prompt_id = p.id
                    WHERE g.id = :group_id AND g.deleted = 0
                ");
                $stmt->execute([':group_id' => $group_id]);
                $group = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$group || !$group['prompt_id']) {
                    $errorDetail = '';
                    if (!$group) {
                        $errorDetail .= "グループID: {$group_id} が見つかりません。";
                    } elseif (!$group['prompt_id']) {
                        $errorDetail .= "グループID: {$group_id} にプロンプトが設定されていません。";
                    }
                    throw new Exception("データが見つかりません。\n{$errorDetail}");
                }

                // レコード数を確認
                $count_stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM record r
                    WHERE r.group_id = :group_id
                    AND r.deleted = 0
                ");
                $count_stmt->execute([':group_id' => $group_id]);
                $total_records = $count_stmt->fetchColumn();

                if ($total_records === 0) {
                    throw new Exception("タスク登録対象のレコードがありません。\nグループID: {$group_id}");
                }

                // プロンプト内容を保持
                $prompt_content = $group['prompt_content'];
                $batch_size = 1000; // 1回あたりの処理件数
                $processed_count = 0;

                // 既存のナレッジを論理削除
                $delete_knowledge_stmt = $pdo->prepare("
                    UPDATE knowledge
                    SET deleted = 1, updated_at = '$timestamp'
                    WHERE group_id = :group_id
                    AND parent_type = 'record'
                    AND deleted = 0
                ");

                // タスク登録用のステートメントを準備
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

                // グループの最終タスク実行日時更新用のステートメントを準備
                $update_group_stmt = $pdo->prepare("
                    UPDATE groups
                    SET task_executed_at = '$timestamp'
                    WHERE id = :group_id
                ");

                // トランザクション開始
                $pdo->beginTransaction();
                try {
                    // 既存のナレッジを論理削除
                    $delete_knowledge_stmt->execute([':group_id' => $group_id]);

                    // レコードをバッチで処理
                    for ($offset = 0; $offset < $total_records; $offset += $batch_size) {
                        $records_stmt = $pdo->prepare("
                            SELECT r.id, r.text, r.reference
                            FROM record r
                            WHERE r.group_id = :group_id
                            AND r.deleted = 0
                            LIMIT :limit OFFSET :offset
                        ");
                        $records_stmt->bindValue(':group_id', $group_id, PDO::PARAM_INT);
                        $records_stmt->bindValue(':limit', $batch_size, PDO::PARAM_INT);
                        $records_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                        $records_stmt->execute();

                        while ($record = $records_stmt->fetch(PDO::FETCH_ASSOC)) {
                            $current_prompt_content = $prompt_content;
                            if (!empty($record['reference'])) {
                                $current_prompt_content = str_replace('{{reference}}', $record['reference'], $current_prompt_content);
                            }

                            $tasks_stmt->bindValue(':source_id', $record['id'], PDO::PARAM_INT);
                            $tasks_stmt->bindValue(':source_text', $record['text'], PDO::PARAM_STR);
                            $tasks_stmt->bindValue(':prompt_content', $current_prompt_content, PDO::PARAM_STR);
                            $tasks_stmt->bindValue(':prompt_id', $group['prompt_id'], PDO::PARAM_INT);
                            $tasks_stmt->bindValue(':group_id', $group_id, PDO::PARAM_INT);
                            $tasks_stmt->bindValue(':created_by', $_SESSION['user'], PDO::PARAM_STR);
                            $tasks_stmt->execute();
                            $processed_count++;
                        }

                        // メモリ解放
                        unset($records_stmt);
                        gc_collect_cycles();
                    }

                    // グループの最終タスク実行日時を更新
                    $update_group_stmt->execute([':group_id' => $group_id]);

                    // すべての処理が成功したらコミット
                    $pdo->commit();

                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => $processed_count . '件のタスクを登録しました。'
                    ]);
                } catch (Exception $e) {
                    // トランザクションロールバック
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'エラーが発生しました: ' . $e->getMessage(),
                        'details' => [
                            'error_type' => get_class($e),
                            'error_code' => $e->getCode(),
                            'error_file' => basename($e->getFile()),
                            'error_line' => $e->getLine(),
                            'group_id' => $group_id
                        ]
                    ]);
                }
            } catch (Exception $e) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'エラーが発生しました: ' . $e->getMessage(),
                    'details' => [
                        'error_type' => get_class($e),
                        'error_code' => $e->getCode(),
                        'error_file' => basename($e->getFile()),
                        'error_line' => $e->getLine(),
                        'group_id' => $group_id
                    ]
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
} catch (Throwable $e) {
    // 予期せぬエラーのハンドリング
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => '予期せぬエラーが発生しました: ' . $e->getMessage(),
        'details' => [
            'error_type' => get_class($e),
            'error_code' => $e->getCode(),
            'error_file' => basename($e->getFile()),
            'error_line' => $e->getLine()
        ]
    ]);
}
?>