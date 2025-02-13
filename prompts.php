<?php
define('APP_ROOT', __DIR__);
error_log("Debug - Loading prompts.php");
require_once APP_ROOT . '/common/config.php';
require_once APP_ROOT . '/common/functions.php';
require_once APP_ROOT . '/common/auth.php';
require_once APP_ROOT . '/common/header.php';

$action = $_GET['action'] ?? 'list';
$searchTerm = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 10; // 10-100の範囲で制限

switch ($action) {
    case 'list':
        // 検索処理
        if ($searchTerm) {
            $records = search($pdo, 'prompts', $searchTerm, ['title', 'content']);
            $total = count($records);
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM prompts WHERE deleted = 0
            ");
            $stmt->execute();
            $total = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("
                SELECT p.*, 
                       COUNT(k.id) as usage_count
                FROM prompts p
                LEFT JOIN knowledge k ON p.id = k.prompt_id AND k.deleted = 0
                WHERE p.deleted = 0
                GROUP BY p.id
                ORDER BY p.created_at DESC 
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $pagination = getPagination($total, $perPage, $page);
        break;

    case 'view_history':
        $id = $_GET['id'] ?? 0;
        $history_id = $_GET['history_id'] ?? 0;
        
        // 履歴データの取得
        $stmt = $pdo->prepare("
            SELECT h.*, h.modified_by as modified_by_user
            FROM prompt_history h
            WHERE h.id = :history_id AND h.prompt_id = :prompt_id
        ");
        $stmt->execute([':history_id' => $history_id, ':prompt_id' => $id]);
        $history = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$history) {
            $_SESSION['error_message'] = "指定された履歴が見つかりません。";
            header("Location: prompts.php?action=view&id=" . $id);
            exit;
        }
        break;
        
    case 'view':
        $id = $_GET['id'] ?? 0;
        // プロンプトの詳細情報を取得
        $stmt = $pdo->prepare("
            SELECT p.*,
                   COUNT(k.id) as usage_count
            FROM prompts p
            LEFT JOIN knowledge k ON p.id = k.prompt_id AND k.deleted = 0
            WHERE p.id = :id AND p.deleted = 0
            GROUP BY p.id
        ");
        $stmt->execute([':id' => $id]);
        $prompt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // このプロンプトを使用しているナレッジの一覧を取得
        $stmt = $pdo->prepare("
            SELECT id, title, created_at
            FROM knowledge
            WHERE prompt_id = :prompt_id AND deleted = 0
            ORDER BY created_at DESC
        ");
        $stmt->execute([':prompt_id' => $id]);
        $relatedKnowledge = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 履歴の取得
        $history = getHistory($pdo, 'prompt', $id);
        break;

    case 'create':
    case 'edit':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'title' => $_POST['title'],
                'content' => $_POST['content'],
                'category' => $_POST['category']
            ];
            
            if ($action === 'create') {
                try {
                    $pdo->beginTransaction();
                    
                    // プロンプトの作成
                    $stmt = $pdo->prepare("
                        INSERT INTO prompts (
                            title,
                            content,
                            category,
                            created_by,
                            created_at,
                            updated_at
                        ) VALUES (
                            :title,
                            :content,
                            :category,
                            :created_by,
                            '$timestamp',
                            '$timestamp'
                        )
                    ");
                    $data['created_by'] = $_SESSION['user'];
                    $stmt->execute($data);
                    $id = $pdo->lastInsertId();
                    
                    // 履歴の記録
                    $historyData = [
                        'title' => $data['title'],
                        'content' => $data['content']
                    ];
                    logHistory($pdo, 'prompts', $id, $historyData);
                    
                    $pdo->commit();
                    
                    // 一覧ページにリダイレクト
                    header("Location: prompts.php?action=list");
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            } else {
                $id = $_GET['id'];
                // POSTデータの検証
                if (empty($_POST['title']) || empty($_POST['content']) || empty($_POST['category'])) {
                    throw new Exception("Required fields are missing");
                }

                try {
                    // プロンプトの更新
                    $updateData = [
                        'title' => $_POST['title'],
                        'content' => $_POST['content'],
                        'category' => $_POST['category'],
                        'id' => $id
                    ];
                    
                    $stmt = $pdo->prepare("
                        UPDATE prompts
                        SET title = :title,
                            content = :content,
                            category = :category,
                            updated_at = '$timestamp'
                        WHERE id = :id AND deleted = 0
                    ");
                    
                    if (!$stmt->execute($updateData)) {
                        throw new Exception("Failed to update prompt: " . print_r($stmt->errorInfo(), true));
                    }

                    // 履歴の記録（エラーが発生しても処理は続行）
                    try {
                        $historyData = [
                            'title' => $_POST['title'],
                            'content' => $_POST['content']
                        ];
                        
                        if (!logHistory($pdo, 'prompts', $id, $historyData)) {
                            error_log("Warning: Failed to log history for prompt ID: " . $id);
                        }
                    } catch (Exception $historyError) {
                        error_log("Warning: History logging failed: " . $historyError->getMessage());
                    }
                    $success = date('Y-m-d H:i:s') . " Debug - Transaction committed successfully\n";
                    file_put_contents(dirname(__FILE__) . '/common/logs.txt', $success, FILE_APPEND);
                    
                    // 詳細ページにリダイレクト
                    header("Location: prompts.php?action=view&id=" . $id);
                    exit;
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = date('Y-m-d H:i:s') . " Error in prompt update: " . $e->getMessage() . "\n";
                    $error .= date('Y-m-d H:i:s') . " Debug - Stack trace: " . $e->getTraceAsString() . "\n";
                    file_put_contents(dirname(__FILE__) . '/common/logs.txt', $error, FILE_APPEND);
                    
                    // エラーメッセージをセッションに保存
                    $_SESSION['error_message'] = "プロンプトの更新に失敗しました。";
                    header("Location: prompts.php?action=edit&id=" . $id);
                    exit;
                }
            }
        }
        
        if ($action === 'edit') {
            $stmt = $pdo->prepare("SELECT * FROM prompts WHERE id = :id AND deleted = 0");
            $stmt->execute([':id' => $_GET['id']]);
            $prompt = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        break;

    case 'delete':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("
                UPDATE prompts 
                SET deleted = 1, updated_at = '$timestamp'
                WHERE id = :id
            ");
            $stmt->execute([':id' => $_GET['id']]);
        }
        redirect('prompts.php?action=list');
        break;
}
?>

<!-- リスト表示画面 -->
<?php if ($action === 'list'): ?>
    <h1 class="mb-4">プロンプト管理</h1>
    
    <div class="row mb-4">
        <div class="col">
            <form class="d-flex" method="GET" action="prompts.php">
                <input type="hidden" name="action" value="list">
                <input type="search" name="search" class="form-control me-2" 
                       value="<?= h($searchTerm) ?>" placeholder="検索...">
                <button class="btn btn-outline-primary" type="submit">検索</button>
            </form>
        </div>
        <div class="col text-end">
            <a href="prompts.php?action=create" class="btn btn-primary">新規作成</a>
        </div>
    </div>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>タイトル</th>
                <th>カテゴリ</th>
                <th>使用回数</th>
                <th>作成日時</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($records as $record): ?>
            <tr>
                <td><?= h($record['id']) ?></td>
                <td>
                    <a href="prompts.php?action=view&id=<?= h($record['id']) ?>">
                        <?= h($record['title']) ?>
                    </a>
                </td>
                <td>
                    <?php if ($record['category'] === 'plain_to_knowledge'): ?>
                        <span class="badge bg-info">プレーン→ナレッジ</span>
                    <?php else: ?>
                        <span class="badge bg-success">ナレッジ→ナレッジ</span>
                    <?php endif; ?>
                </td>
                <td><?= h($record['usage_count']) ?></td>
                <td><?= h(date('Y/m/d H:i', strtotime($record['created_at']))) ?></td>
                <td>
                    <a href="prompts.php?action=edit&id=<?= h($record['id']) ?>"
                       class="btn btn-sm btn-warning">編集</a>
                    <?php if ($record['usage_count'] == 0): ?>
                        <a href="prompts.php?action=delete&id=<?= h($record['id']) ?>"
                           class="btn btn-sm btn-danger"
                           onclick="return confirm('本当に削除しますか？')">削除</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- 表示件数選択 -->
    <div class="row mb-3">
        <div class="col-auto">
            <form class="d-flex align-items-center" method="GET" action="prompts.php">
                <input type="hidden" name="action" value="list">
                <input type="hidden" name="search" value="<?= h($searchTerm) ?>">
                <label for="perPage" class="me-2">表示件数:</label>
                <select id="perPage" name="per_page" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                    <?php foreach ([10, 20, 50, 100] as $value): ?>
                    <option value="<?= $value ?>" <?= $perPage == $value ? 'selected' : '' ?>><?= $value ?>件</option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="col text-end">
            <p class="mb-0"><?= h($pagination['showing']) ?></p>
        </div>
    </div>

    <!-- ページネーション -->
    <?php if ($pagination['total_pages'] > 1): ?>
    <nav aria-label="ページナビゲーション">
        <ul class="pagination justify-content-center">
            <!-- 前へボタン -->
            <li class="page-item <?= !$pagination['has_previous'] ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $pagination['has_previous'] ? 'prompts.php?action=list&page=' . ($page - 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="前のページ" <?= !$pagination['has_previous'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                    <span aria-hidden="true">&laquo;</span>
                    <span class="visually-hidden">前のページ</span>
                </a>
            </li>

            <!-- ページ番号 -->
            <?php foreach ($pagination['pages'] as $p): ?>
                <?php if ($p === '...'): ?>
                    <li class="page-item disabled">
                        <span class="page-link">...</span>
                    </li>
                <?php else: ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="prompts.php?action=list&page=<?= $p ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?>&per_page=<?= $perPage ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>>
                            <?= $p ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- 次へボタン -->
            <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $pagination['has_next'] ? 'prompts.php?action=list&page=' . ($page + 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="次のページ" <?= !$pagination['has_next'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                    <span aria-hidden="true">&raquo;</span>
                    <span class="visually-hidden">次のページ</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- ページ番号直接入力フォーム -->
    <div class="text-center mt-3">
        <form class="d-inline-flex align-items-center" method="GET" action="prompts.php">
            <input type="hidden" name="action" value="list">
            <input type="hidden" name="search" value="<?= h($searchTerm) ?>">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <label for="pageInput" class="me-2">ページ指定:</label>
            <input type="number" id="pageInput" name="page" class="form-control form-control-sm me-2" style="width: 80px;" min="1" max="<?= $pagination['total_pages'] ?>" value="<?= $page ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">移動</button>
            <span class="ms-2">/ <?= $pagination['total_pages'] ?>ページ</span>
        </form>
    </div>
    <?php endif; ?>

<!-- 履歴詳細表示画面 -->
<?php elseif ($action === 'view_history'): ?>
    <h1 class="mb-4">プロンプト履歴詳細</h1>
    
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title"><?= h($history['title']) ?></h5>
            
            <div class="mb-3">
                <h6>プロンプト内容</h6>
                <pre class="border p-3 bg-light"><?= h($history['content']) ?></pre>
            </div>
            
            <div class="mb-3">
                <h6>変更情報</h6>
                <p>変更日時：<?= h(date('Y/m/d H:i', strtotime($history['created_at']))) ?></p>
                <p>変更者：<?= h($history['modified_by_user'] ?? '未設定') ?></p>
            </div>
        </div>
    </div>
    
    <div class="mb-4">
        <a href="prompts.php?action=view&id=<?= h($id) ?>" class="btn btn-secondary">戻る</a>
    </div>

<!-- 詳細表示画面 -->
<?php elseif ($action === 'view'): ?>
    <h1 class="mb-4">プロンプト詳細</h1>
    
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title"><?= h($prompt['title']) ?></h5>
            
            <div class="mb-3">
                <h6>カテゴリ</h6>
                <p>
                    <?php if ($prompt['category'] === 'plain_to_knowledge'): ?>
                        <span class="badge bg-info">プレーン→ナレッジ</span>
                    <?php else: ?>
                        <span class="badge bg-success">ナレッジ→ナレッジ</span>
                    <?php endif; ?>
                </p>
            </div>
            
            <div class="mb-3">
                <h6>プロンプト内容</h6>
                <pre class="border p-3 bg-light"><?= h($prompt['content']) ?></pre>
            </div>
            
            <?php if ($relatedKnowledge): ?>
            <div class="mb-3">
                <h6>このプロンプトを使用したナレッジ（<?= count($relatedKnowledge) ?>件）</h6>
                <ul>
                    <?php foreach ($relatedKnowledge as $knowledge): ?>
                    <li>
                        <a href="knowledge.php?action=view&id=<?= h($knowledge['id']) ?>">
                            <?= h($knowledge['title']) ?>
                        </a>
                        <small class="text-muted">（<?= h($knowledge['created_at']) ?>）</small>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 履歴表示 -->
    <h3 class="mb-3">変更履歴</h3>
    <table class="table">
        <thead>
            <tr>
                <th>変更日時</th>
                <th>タイトル</th>
                <th>変更者</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $entry): ?>
            <tr>
                <td><?= h($entry['created_at']) ?></td>
                <td>
                    <a href="prompts.php?action=view_history&id=<?= h($prompt['id']) ?>&history_id=<?= h($entry['id']) ?>">
                        <?= h($entry['title']) ?>
                    </a>
                </td>
                <td><?= h($entry['modified_by']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="mb-4">
        <a href="prompts.php?action=list" class="btn btn-secondary">戻る</a>
        <a href="prompts.php?action=edit&id=<?= h($prompt['id']) ?>" 
           class="btn btn-warning">編集</a>
    </div>

<!-- 作成・編集画面 -->
<?php else: ?>
    <h1 class="mb-4">
        <?= $action === 'create' ? 'プロンプト作成' : 'プロンプト編集' ?>
    </h1>
    
    <form method="POST" class="needs-validation" novalidate>
        <div class="mb-3">
            <label for="title" class="form-label">タイトル</label>
            <input type="text" class="form-control" id="title" name="title" 
                   value="<?= isset($prompt) ? h($prompt['title']) : '' ?>" required>
        </div>
        
        <div class="mb-3">
            <label class="form-label">カテゴリ</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="category" 
                       id="category_p2k" value="plain_to_knowledge" 
                       <?= isset($prompt) && $prompt['category'] === 'plain_to_knowledge' ? 'checked' : '' ?>>
                <label class="form-check-label" for="category_p2k">
                    プレーン→ナレッジ
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="category" 
                       id="category_k2k" value="knowledge_to_knowledge"
                       <?= isset($prompt) && $prompt['category'] === 'knowledge_to_knowledge' ? 'checked' : '' ?>>
                <label class="form-check-label" for="category_k2k">
                    ナレッジ→ナレッジ
                </label>
            </div>
        </div>
        
        <div class="mb-3">
            <label for="content" class="form-label">プロンプト内容</label>
            <textarea class="form-control" id="content" name="content" rows="10" required><?= isset($prompt) ? h($prompt['content']) : '' ?></textarea>
            ※{{reference}}は、referenceに登録された値にリプレースされます。
        </div>
        
        <button type="submit" class="btn btn-primary">保存</button>
        <a href="prompts.php?action=list" class="btn btn-secondary">キャンセル</a>
    </form>
<?php endif; ?>

<?php require_once APP_ROOT . '/common/footer.php'; ?>