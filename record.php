<?php
define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/common/config.php';
require_once APP_ROOT . '/common/functions.php';
require_once APP_ROOT . '/common/auth.php';
require_once APP_ROOT . '/common/header.php';

$action = $_GET['action'] ?? 'list';
$searchTerm = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;

switch ($action) {
    case 'list':
        // 検索処理
        if ($searchTerm) {
            $records = search($pdo, 'record', $searchTerm, ['title', 'text']);
            $total = count($records);
        } else {
            // 通常のリスト表示
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM record WHERE deleted = 0
            ");
            $stmt->execute();
            $total = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("
                SELECT * FROM record 
                WHERE deleted = 0
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $pagination = getPagination($total, $perPage, $page);
        break;

    case 'view':
        $id = $_GET['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT r.*, k.id as knowledge_id, k.title as knowledge_title
            FROM record r
            LEFT JOIN knowledge k ON k.parent_id = r.id AND k.parent_type = 'record' AND k.deleted = 0
            WHERE r.id = :id AND r.deleted = 0
        ");
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 履歴の取得
        $history = getHistory($pdo, 'record', $id);
        break;

    case 'create':
    case 'edit':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'title' => $_POST['title'],
                'text' => $_POST['text']
            ];
            
            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO record (title, text, created_by)
                    VALUES (:title, :text, :created_by)
                ");
                $data['created_by'] = $_SESSION['user'];
                $stmt->execute($data);
                $id = $pdo->lastInsertId();
            } else {
                $id = $_GET['id'];
                $stmt = $pdo->prepare("
                    UPDATE record 
                    SET title = :title, text = :text, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id AND deleted = 0
                ");
                $data['id'] = $id;
                $stmt->execute($data);
                
                // 履歴の記録
                logHistory($pdo, 'record', $id, $data);
            }
            
            redirect('record.php?action=list');
        }
        
        if ($action === 'edit') {
            $stmt = $pdo->prepare("SELECT * FROM record WHERE id = :id AND deleted = 0");
            $stmt->execute([':id' => $_GET['id']]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        break;

    case 'delete':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("
                UPDATE record 
                SET deleted = 1, updated_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $_GET['id']]);
        }
        redirect('record.php?action=list');
        break;
}
?>

<!-- リスト表示画面 -->
<?php if ($action === 'list'): ?>
    <h1 class="mb-4">プレーンナレッジ管理</h1>
    
    <div class="row mb-4">
        <div class="col">
            <form class="d-flex" method="GET" action="record.php">
                <input type="hidden" name="action" value="list">
                <input type="search" name="search" class="form-control me-2" 
                       value="<?= h($searchTerm) ?>" placeholder="検索...">
                <button class="btn btn-outline-primary" type="submit">検索</button>
            </form>
        </div>
        <div class="col text-end">
            <a href="record.php?action=create" class="btn btn-primary">新規作成</a>
        </div>
    </div>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>タイトル</th>
                <th>作成日時</th>
                <th>更新日時</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($records as $record): ?>
            <tr>
                <td><?= h($record['id']) ?></td>
                <td><?= h($record['title']) ?></td>
                <td><?= h($record['created_at']) ?></td>
                <td><?= h($record['updated_at']) ?></td>
                <td>
                    <a href="record.php?action=view&id=<?= h($record['id']) ?>" 
                       class="btn btn-sm btn-info">詳細</a>
                    <a href="record.php?action=edit&id=<?= h($record['id']) ?>" 
                       class="btn btn-sm btn-warning">編集</a>
                    <a href="record.php?action=delete&id=<?= h($record['id']) ?>" 
                       class="btn btn-sm btn-danger" 
                       onclick="return confirm('本当に削除しますか？')">削除</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ページネーション -->
    <?php if ($pagination['total_pages'] > 1): ?>
    <nav>
        <ul class="pagination justify-content-center">
            <?php for ($i = $pagination['start']; $i <= $pagination['end']; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="record.php?action=list&page=<?= $i ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?>">
                    <?= $i ?>
                </a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>

<!-- 詳細表示画面 -->
<?php elseif ($action === 'view'): ?>
    <h1 class="mb-4">プレーンナレッジ詳細</h1>
    
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title"><?= h($record['title']) ?></h5>
            <p class="card-text"><?= nl2br(h($record['text'])) ?></p>
            
            <?php if (isset($record['knowledge_id'])): ?>
            <h6 class="mt-4">関連ナレッジ</h6>
            <ul>
                <li><a href="knowledge.php?action=view&id=<?= h($record['knowledge_id']) ?>">
                    <?= h($record['knowledge_title']) ?>
                </a></li>
            </ul>
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
                <td><?= h($entry['title']) ?></td>
                <td><?= h($entry['modified_by_user']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="mb-4">
        <a href="record.php?action=list" class="btn btn-secondary">戻る</a>
        <a href="record.php?action=edit&id=<?= h($record['id']) ?>" 
           class="btn btn-warning">編集</a>
    </div>

<!-- 作成・編集画面 -->
<?php else: ?>
    <h1 class="mb-4">
        <?= $action === 'create' ? 'プレーンナレッジ作成' : 'プレーンナレッジ編集' ?>
    </h1>
    
    <form method="POST" class="needs-validation" novalidate>
        <div class="mb-3">
            <label for="title" class="form-label">タイトル</label>
            <input type="text" class="form-control" id="title" name="title" 
                   value="<?= isset($record) ? h($record['title']) : '' ?>" required>
        </div>
        
        <div class="mb-3">
            <label for="text" class="form-label">内容</label>
            <textarea class="form-control" id="text" name="text" rows="10" required>
                <?= isset($record) ? h($record['text']) : '' ?>
            </textarea>
        </div>
        
        <button type="submit" class="btn btn-primary">保存</button>
        <a href="record.php?action=list" class="btn btn-secondary">キャンセル</a>
    </form>
<?php endif; ?>

<?php require_once APP_ROOT . '/common/footer.php'; ?>