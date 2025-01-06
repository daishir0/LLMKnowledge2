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
                $stmt = $pdo->prepare("
                    INSERT INTO prompts (title, content, category, created_by)
                    VALUES (:title, :content, :category, :created_by)
                ");
                $data['created_by'] = $_SESSION['user'];
                $stmt->execute($data);
                $id = $pdo->lastInsertId();
            } else {
                $id = $_GET['id'];
                $stmt = $pdo->prepare("
                    UPDATE prompts 
                    SET title = :title,
                        content = :content,
                        category = :category,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id AND deleted = 0
                ");
                $data['id'] = $id;
                $stmt->execute($data);
                
                // 履歴の記録
                logHistory($pdo, 'prompt', $id, $data);
            }
            
            redirect('prompts.php?action=list');
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
                SET deleted = 1, updated_at = CURRENT_TIMESTAMP 
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
                <td><?= h($record['title']) ?></td>
                <td>
                    <?php if ($record['category'] === 'plain_to_knowledge'): ?>
                        <span class="badge bg-info">プレーン→ナレッジ</span>
                    <?php else: ?>
                        <span class="badge bg-success">ナレッジ→ナレッジ</span>
                    <?php endif; ?>
                </td>
                <td><?= h($record['usage_count']) ?></td>
                <td><?= h($record['created_at']) ?></td>
                <td>
                    <a href="prompts.php?action=view&id=<?= h($record['id']) ?>" 
                       class="btn btn-sm btn-info">詳細</a>
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

    <!-- ページネーション -->
    <?php if ($pagination['total_pages'] > 1): ?>
    <nav>
        <ul class="pagination justify-content-center">
            <?php for ($i = $pagination['start']; $i <= $pagination['end']; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" 
                   href="prompts.php?action=list&page=<?= $i ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?>">
                    <?= $i ?>
                </a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>

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
                <td><?= h($entry['title']) ?></td>
                <td><?= h($entry['modified_by_user']) ?></td>
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
            <textarea class="form-control" id="content" name="content" rows="10" required>
                <?= isset($prompt) ? h($prompt['content']) : '' ?>
            </textarea>
        </div>
        
        <button type="submit" class="btn btn-primary">保存</button>
        <a href="prompts.php?action=list" class="btn btn-secondary">キャンセル</a>
    </form>
<?php endif; ?>

<?php require_once APP_ROOT . '/common/footer.php'; ?>