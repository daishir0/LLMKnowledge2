<?php
define('APP_ROOT', __DIR__);
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
            $stmt = $pdo->prepare("
                SELECT t.*, 
                       CASE 
                           WHEN t.source_type = 'record' THEN r.title 
                           WHEN t.source_type = 'knowledge' THEN k.title 
                       END as source_title,
                       p.title as prompt_title,
                FROM tasks t
                LEFT JOIN record r ON t.source_type = 'record' AND t.source_id = r.id
                LEFT JOIN knowledge k ON t.source_type = 'knowledge' AND t.source_id = k.id
                LEFT JOIN prompts p ON t.prompt_content = p.content
                WHERE t.deleted = 0 
                AND (
                    r.title LIKE :search 
                    OR k.title LIKE :search 
                    OR t.status LIKE :search
                )
                ORDER BY t.created_at DESC
            ");
            $stmt->execute([':search' => "%$searchTerm%"]);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = count($tasks);
        } else {
            // 総件数の取得
            $stmt = $pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted = 0");
            $total = $stmt->fetchColumn();
            
            // タスク一覧の取得
            $stmt = $pdo->prepare("
                SELECT t.*, 
                       CASE 
                           WHEN t.source_type = 'record' THEN r.title 
                           WHEN t.source_type = 'knowledge' THEN k.title 
                       END as source_title,
                       p.title as prompt_title
                FROM tasks t
                LEFT JOIN record r ON t.source_type = 'record' AND t.source_id = r.id
                LEFT JOIN knowledge k ON t.source_type = 'knowledge' AND t.source_id = k.id
                LEFT JOIN prompts p ON t.prompt_id = p.id
                WHERE t.deleted = 0
                ORDER BY t.created_at DESC 
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
            $stmt->execute();
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $pagination = getPagination($total, $perPage, $page);
        break;

    case 'view':
        $id = $_GET['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   CASE 
                       WHEN t.source_type = 'record' THEN r.title 
                       WHEN t.source_type = 'knowledge' THEN k.title 
                   END as source_title,
                   p.title as prompt_title,
                   rk.title as result_title
            FROM tasks t
            LEFT JOIN record r ON t.source_type = 'record' AND t.source_id = r.id
            LEFT JOIN knowledge k ON t.source_type = 'knowledge' AND t.source_id = k.id
            LEFT JOIN prompts p ON t.prompt_id = p.id
            LEFT JOIN knowledge rk ON t.result_knowledge_id = rk.id
            WHERE t.id = :id AND t.deleted = 0
        ");
        $stmt->execute([':id' => $id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        break;

    case 'cancel':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("
                UPDATE tasks 
                SET status = 'cancelled', 
                    updated_at = '$timestamp'
                WHERE id = :id 
                AND status = 'pending'
                AND deleted = 0
            ");
            $stmt->execute([':id' => $_POST['id']]);
            redirect('tasks.php?action=list');
        }
        break;
}
?>

<!-- リスト表示画面 -->
<?php if ($action === 'list'): ?>
    <h1 class="mb-4">タスク管理</h1>
    
    <div class="row mb-4">
        <div class="col">
            <form class="d-flex" method="GET" action="tasks.php">
                <input type="hidden" name="action" value="list">
                <input type="search" name="search" class="form-control me-2" 
                       value="<?= h($searchTerm) ?>" placeholder="検索...">
                <button class="btn btn-outline-primary" type="submit">検索</button>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped">
            <colgroup>
                <col style="min-width: 80px; width: 80px;">  <!-- ID列 -->
                <col style="min-width: 200px; max-width: 300px;">  <!-- ソース列 -->
                <col style="min-width: 200px; max-width: 300px;">  <!-- プロンプト列 -->
                <col style="min-width: 120px; width: 120px;">  <!-- ステータス列 -->
                <col style="min-width: 120px; width: 120px;">  <!-- 作成者列 -->
                <col style="min-width: 120px; width: 120px;">  <!-- 作成日時列 -->
            </colgroup>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>ソース</th>
                    <th>プロンプト</th>
                    <th>ステータス</th>
                    <th class="d-none d-md-table-cell">作成者</th>
                    <th class="d-none d-md-table-cell">作成日時</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tasks as $task): ?>
            <tr>
                <td><?= h($task['id']) ?></td>
                <td style="max-width: 300px;">
                    <?php if ($task['source_type'] === 'record'): ?>
                        <span class="badge bg-info me-1">プレーン</span>
                    <?php else: ?>
                        <span class="badge bg-success me-1">ナレッジ</span>
                    <?php endif; ?>
                    <span class="text-truncate d-inline-block" style="max-width: calc(100% - 80px); vertical-align: middle;" title="<?= h($task['source_title']) ?>">
                        <a href="tasks.php?action=view&id=<?= h($task['id']) ?>" class="text-truncate d-block">
                            <?= h($task['source_title']) ?>
                        </a>
                    </span>
                </td>
                <td class="text-truncate" style="max-width: 300px;" title="<?= h($task['prompt_title']) ?>">
                    <?= h($task['prompt_title']) ?>
                </td>
                <td>
                    <?php
                    $statusBadgeClass = [
                        'pending' => 'bg-warning',
                        'processing' => 'bg-primary',
                        'completed' => 'bg-success',
                        'failed' => 'bg-danger',
                        'cancelled' => 'bg-secondary'
                    ][$task['status']] ?? 'bg-secondary';
                    ?>
                    <span class="badge <?= $statusBadgeClass ?>">
                        <?= h($task['status']) ?>
                    </span>
                </td>
                <td class="d-none d-md-table-cell"><?= h($task['created_by']) ?></td>
                <td class="d-none d-md-table-cell"><?= h(date('Y/m/d H:i', strtotime($task['created_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        </table>
    </div>

    <style>
        /* テーブルセルのスタイル */
        .table td {
            vertical-align: middle;
        }
        
        /* バッジの右マージン */
        .badge {
            margin-right: 8px;
        }
        
        /* リンクテキストが省略される場合でもホバー可能に */
        .text-truncate a {
            display: block;
            width: 100%;
        }
        
        /* ツールチップのスタイル */
        [title] {
            position: relative;
            cursor: help;
        }

        /* スマートフォンでの表示調整 */
        @media (max-width: 576px) {
            .table td {
                white-space: normal;
                word-break: break-word;
            }
            .text-truncate {
                max-width: 200px !important;
            }
        }
    </style>

    <!-- 表示件数選択 -->
    <div class="row mb-3">
        <div class="col-auto">
            <form class="d-flex align-items-center" method="GET" action="tasks.php">
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
                <a class="page-link" href="<?= $pagination['has_previous'] ? 'tasks.php?action=list&page=' . ($page - 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="前のページ" <?= !$pagination['has_previous'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
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
                        <a class="page-link" href="tasks.php?action=list&page=<?= $p ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?>&per_page=<?= $perPage ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>>
                            <?= $p ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- 次へボタン -->
            <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $pagination['has_next'] ? 'tasks.php?action=list&page=' . ($page + 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="次のページ" <?= !$pagination['has_next'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                    <span aria-hidden="true">&raquo;</span>
                    <span class="visually-hidden">次のページ</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- ページ番号直接入力フォーム -->
    <div class="text-center mt-3">
        <form class="d-inline-flex align-items-center" method="GET" action="tasks.php">
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

<!-- 詳細表示画面 -->
<?php elseif ($action === 'view'): ?>
    <h1 class="mb-4">タスク詳細</h1>
    
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">
                <?php if ($task['source_type'] === 'record'): ?>
                    <span class="badge bg-info">プレーン</span>
                <?php else: ?>
                    <span class="badge bg-success">ナレッジ</span>
                <?php endif; ?>
                <?= h($task['source_title']) ?>
            </h5>
            
            <div class="mb-3">
                <h6>ステータス</h6>
                <?php
                $statusBadgeClass = [
                    'pending' => 'bg-warning',
                    'processing' => 'bg-primary',
                    'completed' => 'bg-success',
                    'failed' => 'bg-danger',
                    'cancelled' => 'bg-secondary'
                ][$task['status']] ?? 'bg-secondary';
                ?>
                <span class="badge <?= $statusBadgeClass ?>">
                    <?= h($task['status']) ?>
                </span>
            </div>
            
            <div class="mb-3">
                <h6>ソーステキスト</h6>
                <pre class="border p-3 bg-light"><?= h($task['source_text']) ?></pre>
            </div>
            
            <div class="mb-3">
                <h6>使用プロンプト</h6>
                <p><?= h($task['prompt_title']) ?></p>
                <pre class="border p-3 bg-light"><?= h($task['prompt_content']) ?></pre>
            </div>
            
            <?php if ($task['error_message']): ?>
            <div class="mb-3">
                <h6>エラーメッセージ</h6>
                <pre class="border p-3 bg-light text-danger"><?= h($task['error_message']) ?></pre>
            </div>
            <?php endif; ?>
            
            <?php if ($task['result_knowledge_id']): ?>
            <div class="mb-3">
                <h6>生成されたナレッジ</h6>
                <p>
                    <a href="knowledge.php?action=view&id=<?= h($task['result_knowledge_id']) ?>">
                        <?= h($task['result_title']) ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>
            
            <div class="mb-3">
                <h6>作成情報</h6>
                <p>
                    作成者: <?= h($task['created_by']) ?><br>
                    作成日時: <?= h($task['created_at']) ?><br>
                    更新日時: <?= h($task['updated_at']) ?>
                </p>
            </div>
        </div>
    </div>

    <div class="mb-4">
        <a href="tasks.php?action=list" class="btn btn-secondary">戻る</a>
        <?php if ($task['status'] === 'pending'): ?>
            <form method="POST" action="tasks.php?action=cancel" class="d-inline">
                <input type="hidden" name="id" value="<?= h($task['id']) ?>">
                <button type="submit" class="btn btn-warning" 
                        onclick="return confirm('このタスクをキャンセルしますか？')">
                    キャンセル
                </button>
            </form>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require_once APP_ROOT . '/common/footer.php'; ?> 