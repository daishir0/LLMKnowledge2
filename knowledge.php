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
            $records = search($pdo, 'knowledge', $searchTerm, ['title', 'question', 'answer']);
            $total = count($records);
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM knowledge WHERE deleted = 0
            ");
            $stmt->execute();
            $total = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("
                SELECT k.*, 
                       CASE 
                           WHEN k.parent_type = 'record' THEN r.title 
                           WHEN k.parent_type = 'knowledge' THEN pk.title 
                       END as parent_title,
                       p.title as prompt_title
                FROM knowledge k
                LEFT JOIN record r ON k.parent_type = 'record' AND k.parent_id = r.id
                LEFT JOIN knowledge pk ON k.parent_type = 'knowledge' AND k.parent_id = pk.id
                LEFT JOIN prompts p ON k.prompt_id = p.id
                WHERE k.deleted = 0
                ORDER BY k.created_at DESC 
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
        // ナレッジの詳細情報を取得
        $stmt = $pdo->prepare("
            SELECT k.*, 
                   CASE 
                       WHEN k.parent_type = 'record' THEN r.title 
                       WHEN k.parent_type = 'knowledge' THEN pk.title 
                   END as parent_title,
                   p.title as prompt_title
            FROM knowledge k
            LEFT JOIN record r ON k.parent_type = 'record' AND k.parent_id = r.id
            LEFT JOIN knowledge pk ON k.parent_type = 'knowledge' AND k.parent_id = pk.id
            LEFT JOIN prompts p ON k.prompt_id = p.id
            WHERE k.id = :id AND k.deleted = 0
        ");
        $stmt->execute([':id' => $id]);
        $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 子ナレッジの取得
        $stmt = $pdo->prepare("
            SELECT id, title 
            FROM knowledge 
            WHERE parent_type = 'knowledge' 
            AND parent_id = :id 
            AND deleted = 0
        ");
        $stmt->execute([':id' => $id]);
        $childKnowledge = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 履歴の取得
        $history = getHistory($pdo, 'knowledge', $id);
        break;

    case 'create':
    case 'edit':
        // プロンプト一覧の取得
        $stmt = $pdo->query("
            SELECT id, title 
            FROM prompts 
            WHERE deleted = 0 
            ORDER BY title
        ");
        $prompts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'title' => $_POST['title'],
                'question' => $_POST['question'],
                'answer' => $_POST['answer'],
                'reference' => $_POST['reference'],
                'parent_type' => $_POST['parent_type'],
                'parent_id' => $_POST['parent_id'],
                'prompt_id' => $_POST['prompt_id']
            ];
            
            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO knowledge 
                    (title, question, answer, reference, parent_type, parent_id, prompt_id, created_by)
                    VALUES 
                    (:title, :question, :answer, :reference, :parent_type, :parent_id, :prompt_id, :created_by)
                ");
                $data['created_by'] = $_SESSION['user'];
                $stmt->execute($data);
                $id = $pdo->lastInsertId();
            } else {
                $id = $_GET['id'];
                $stmt = $pdo->prepare("
                    UPDATE knowledge 
                    SET title = :title, 
                        question = :question,
                        answer = :answer,
                        reference = :reference,
                        parent_type = :parent_type,
                        parent_id = :parent_id,
                        prompt_id = :prompt_id,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id AND deleted = 0
                ");
                $data['id'] = $id;
                $stmt->execute($data);
                
                // 履歴の記録
                logHistory($pdo, 'knowledge', $id, $data);
            }
            
            redirect('knowledge.php?action=list');
        }
        
        if ($action === 'edit') {
            $stmt = $pdo->prepare("SELECT * FROM knowledge WHERE id = :id AND deleted = 0");
            $stmt->execute([':id' => $_GET['id']]);
            $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        break;

    case 'delete':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("
                UPDATE knowledge 
                SET deleted = 1, updated_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $_GET['id']]);
        }
        redirect('knowledge.php?action=list');
        break;
}
?>

<!-- リスト表示画面 -->
<?php if ($action === 'list'): ?>
    <h1 class="mb-4">ナレッジ管理</h1>
    
    <div class="row mb-4">
        <div class="col">
            <form class="d-flex" method="GET" action="knowledge.php">
                <input type="hidden" name="action" value="list">
                <input type="search" name="search" class="form-control me-2" 
                       value="<?= h($searchTerm) ?>" placeholder="検索...">
                <button class="btn btn-outline-primary" type="submit">検索</button>
            </form>
        </div>
        <div class="col text-end">
            <a href="knowledge.php?action=create" class="btn btn-primary">新規作成</a>
        </div>
    </div>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>タイトル</th>
                <th>親ナレッジ/プレーンナレッジ</th>
                <th>使用プロンプト</th>
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
                    <?php if ($record['parent_type'] === 'record'): ?>
                        <span class="badge bg-info">プレーン</span>
                    <?php else: ?>
                        <span class="badge bg-success">ナレッジ</span>
                    <?php endif; ?>
                    <?= h($record['parent_title']) ?>
                </td>
                <td><?= h($record['prompt_title']) ?></td>
                <td><?= h($record['created_at']) ?></td>
                <td>
                    <a href="knowledge.php?action=view&id=<?= h($record['id']) ?>" 
                       class="btn btn-sm btn-info">詳細</a>
                    <a href="knowledge.php?action=edit&id=<?= h($record['id']) ?>" 
                       class="btn btn-sm btn-warning">編集</a>
                    <a href="knowledge.php?action=delete&id=<?= h($record['id']) ?>" 
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
                <a class="page-link" 
                   href="knowledge.php?action=list&page=<?= $i ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?>">
                    <?= $i ?>
                </a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>

<!-- 詳細表示画面 -->
<?php elseif ($action === 'view'): ?>
    <h1 class="mb-4">ナレッジ詳細</h1>
    
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title"><?= h($knowledge['title']) ?></h5>
            
            <div class="mb-3">
                <h6>親ナレッジ/プレーンナレッジ</h6>
                <p>
                    <?php if ($knowledge['parent_type'] === 'record'): ?>
                        <span class="badge bg-info">プレーン</span>
                    <?php else: ?>
                        <span class="badge bg-success">ナレッジ</span>
                    <?php endif; ?>
                    <?= h($knowledge['parent_title']) ?>
                </p>
            </div>
            
            <div class="mb-3">
                <h6>Question</h6>
                <p><?= nl2br(h($knowledge['question'])) ?></p>
            </div>
            
            <div class="mb-3">
                <h6>Answer</h6>
                <p><?= nl2br(h($knowledge['answer'])) ?></p>
            </div>
            
            <?php if ($knowledge['reference']): ?>
            <div class="mb-3">
                <h6>Reference</h6>
                <p><a href="<?= h($knowledge['reference']) ?>" target="_blank"><?= h($knowledge['reference']) ?></a></p>
            </div>
            <?php endif; ?>
            
            <div class="mb-3">
                <h6>使用プロンプト</h6>
                <p><?= h($knowledge['prompt_title']) ?></p>
            </div>
            
            <?php if ($childKnowledge): ?>
            <div class="mb-3">
                <h6>子ナレッジ</h6>
                <ul>
                    <?php foreach ($childKnowledge as $child): ?>
                    <li>
                        <a href="knowledge.php?action=view&id=<?= h($child['id']) ?>">
                            <?= h($child['title']) ?>
                        </a>
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
        <a href="knowledge.php?action=list" class="btn btn-secondary">戻る</a>
        <a href="knowledge.php?action=edit&id=<?= h($knowledge['id']) ?>" 
           class="btn btn-warning">編集</a>
    </div>

<!-- 作成・編集画面 -->
<?php else: ?>
    <h1 class="mb-4">
        <?= $action === 'create' ? 'ナレッジ作成' : 'ナレッジ編集' ?>
    </h1>
    
    <form method="POST" class="needs-validation" novalidate>
        <div class="mb-3">
            <label for="title" class="form-label">タイトル</label>
            <input type="text" class="form-control" id="title" name="title" 
                   value="<?= isset($knowledge) ? h($knowledge['title']) : '' ?>" required>
        </div>
        
        <div class="mb-3">
            <label class="form-label">親ナレッジタイプ</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="parent_type" 
                       id="parent_type_record" value="record" 
                       <?= isset($knowledge) && $knowledge['parent_type'] === 'record' ? 'checked' : '' ?>>
                <label class="form-check-label" for="parent_type_record">
                    プレーンナレッジ
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="parent_type" 
                       id="parent_type_knowledge" value="knowledge"
                       <?= isset($knowledge) && $knowledge['parent_type'] === 'knowledge' ? 'checked' : '' ?>>
                <label class="form-check-label" for="parent_type_knowledge">
                    ナレッジ
                </label>
            </div>
        </div>
        
        <div class="mb-3">
        <label for="parent_id" class="form-label">親ナレッジ/プレーンナレッジ選択</label>
            <select class="form-control" id="parent_id" name="parent_id" required>
                <option value="">選択してください</option>
                <?php
                // プレーンナレッジ一覧
                $stmt = $pdo->query("SELECT id, title FROM record WHERE deleted = 0 ORDER BY title");
                $plain_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($plain_records): ?>
                    <optgroup label="プレーンナレッジ">
                        <?php foreach ($plain_records as $record): ?>
                            <option value="<?= h($record['id']) ?>" 
                                    data-type="record"
                                    <?= isset($knowledge) && $knowledge['parent_type'] === 'record' && 
                                        $knowledge['parent_id'] === $record['id'] ? 'selected' : '' ?>>
                                <?= h($record['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif;

                // ナレッジ一覧
                $stmt = $pdo->query("SELECT id, title FROM knowledge WHERE deleted = 0 ORDER BY title");
                $knowledge_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($knowledge_records): ?>
                    <optgroup label="ナレッジ">
                        <?php foreach ($knowledge_records as $record): ?>
                            <option value="<?= h($record['id']) ?>" 
                                    data-type="knowledge"
                                    <?= isset($knowledge) && $knowledge['parent_type'] === 'knowledge' && 
                                        $knowledge['parent_id'] === $record['id'] ? 'selected' : '' ?>>
                                <?= h($record['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="prompt_id" class="form-label">使用プロンプト</label>
            <select class="form-control" id="prompt_id" name="prompt_id" required>
                <option value="">選択してください</option>
                <?php foreach ($prompts as $prompt): ?>
                    <option value="<?= h($prompt['id']) ?>"
                            <?= isset($knowledge) && $knowledge['prompt_id'] === $prompt['id'] ? 'selected' : '' ?>>
                        <?= h($prompt['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="mb-3">
            <label for="question" class="form-label">Question</label>
            <textarea class="form-control" id="question" name="question" rows="3" required>
                <?= isset($knowledge) ? h($knowledge['question']) : '' ?>
            </textarea>
        </div>
        
        <div class="mb-3">
            <label for="answer" class="form-label">Answer</label>
            <textarea class="form-control" id="answer" name="answer" rows="10" required>
                <?= isset($knowledge) ? h($knowledge['answer']) : '' ?>
            </textarea>
        </div>
        
        <div class="mb-3">
            <label for="reference" class="form-label">Reference (URL)</label>
            <input type="url" class="form-control" id="reference" name="reference" 
                   value="<?= isset($knowledge) ? h($knowledge['reference']) : '' ?>">
        </div>
        
        <button type="submit" class="btn btn-primary">保存</button>
        <a href="knowledge.php?action=list" class="btn btn-secondary">キャンセル</a>
    </form>

    <script>
        // 親ナレッジタイプの選択に応じてセレクトボックスの選択肢を制御
        document.querySelectorAll('input[name="parent_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const selectedType = this.value;
                const options = document.querySelectorAll('#parent_id option');
                
                options.forEach(option => {
                    if (option.value === '') return; // Skip placeholder option
                    const optionType = option.getAttribute('data-type');
                    option.style.display = optionType === selectedType ? '' : 'none';
                    if (optionType !== selectedType) {
                        option.selected = false;
                    }
                });
            });
        });
    </script>
<?php endif; ?>

<?php require_once APP_ROOT . '/common/footer.php'; ?>
