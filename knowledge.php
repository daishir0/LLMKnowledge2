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
        $groupId = $_GET['group_id'] ?? '';
        $params = [];
        $whereConditions = ['k.deleted = 0'];
        
        // グループ条件の追加
        if ($groupId === '') {
            $whereConditions[] = 'k.group_id IS NULL';
        } elseif ($groupId !== '') {
            $whereConditions[] = 'k.group_id = :group_id';
            $params[':group_id'] = $groupId;
        }

        // 検索条件の追加
        if ($searchTerm) {
            $whereConditions[] = '(k.title LIKE :search_title OR k.question LIKE :search_question OR k.answer LIKE :search_answer)';
            $params[':search_title'] = "%$searchTerm%";
            $params[':search_question'] = "%$searchTerm%";
            $params[':search_answer'] = "%$searchTerm%";
        }

        // WHERE句の構築
        $whereClause = implode(' AND ', $whereConditions);

        // 総件数の取得
        $countSql = "SELECT COUNT(*) FROM knowledge k WHERE $whereClause";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // レコードの取得
        $sql = "
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
            WHERE $whereClause
            ORDER BY k.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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
                   p.title as prompt_title,
                   g.id as group_id, g.name as group_name
            FROM knowledge k
            LEFT JOIN record r ON k.parent_type = 'record' AND k.parent_id = r.id
            LEFT JOIN knowledge pk ON k.parent_type = 'knowledge' AND k.parent_id = pk.id
            LEFT JOIN prompts p ON k.prompt_id = p.id
            LEFT JOIN groups g ON k.group_id = g.id AND g.deleted = 0
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
                'reference' => $_POST['reference']
            ];
            
            try {
                $pdo->beginTransaction();
                
                if ($action === 'create') {
                    $stmt = $pdo->prepare("
                        INSERT INTO knowledge
                        (title, question, answer, reference, parent_type, parent_id, prompt_id, created_by, created_at, updated_at)
                        VALUES
                        (:title, :question, :answer, :reference, :parent_type, :parent_id, :prompt_id, :created_by, '$timestamp', '$timestamp')
                    ");
                    $data['created_by'] = $_SESSION['user'];
                    $data['parent_type'] = $knowledge['parent_type'];
                    $data['parent_id'] = $knowledge['parent_id'];
                    $data['prompt_id'] = $knowledge['prompt_id'];
                    $stmt->execute($data);
                    $id = $pdo->lastInsertId();
                    
                    // 新規作成時も履歴を記録
                    $historyStmt = $pdo->prepare("
                        INSERT INTO knowledge_history
                        (knowledge_id, title, question, answer, reference, modified_by, created_at)
                        VALUES
                        (:knowledge_id, :title, :question, :answer, :reference, :modified_by, '$timestamp')
                    ");
                    $historyData = [
                        'knowledge_id' => $id,
                        'title' => $data['title'],
                        'question' => $data['question'],
                        'answer' => $data['answer'],
                        'reference' => $data['reference'],
                        'modified_by' => $_SESSION['user']
                    ];
                    $historyStmt->execute($historyData);
                } else {
                    $id = $_GET['id'];
                    $stmt = $pdo->prepare("
                        UPDATE knowledge
                        SET title = :title,
                            question = :question,
                            answer = :answer,
                            reference = :reference,
                            updated_at = '$timestamp'
                        WHERE id = :id AND deleted = 0
                    ");
                    $data['id'] = $id;
                    $stmt->execute($data);
                    
                    // 履歴の記録
                    $historyStmt = $pdo->prepare("
                        INSERT INTO knowledge_history
                        (knowledge_id, title, question, answer, reference, modified_by, created_at)
                        VALUES
                        (:knowledge_id, :title, :question, :answer, :reference, :modified_by, '$timestamp')
                    ");
                    $historyData = [
                        'knowledge_id' => $id,
                        'title' => $data['title'],
                        'question' => $data['question'],
                        'answer' => $data['answer'],
                        'reference' => $data['reference'],
                        'modified_by' => $_SESSION['user']
                    ];
                    $historyStmt->execute($historyData);
                }
                
                $pdo->commit();
                redirect('knowledge.php?action=list');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error in knowledge create/edit: " . $e->getMessage());
                $_SESSION['error_message'] = "エラーが発生しました。";
                redirect('knowledge.php?action=list');
            }
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
                SET deleted = 1, updated_at = '$timestamp'
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
                <select name="group_id" class="form-select me-2" style="width: auto;">
                    <option value="">グループ指定なし</option>
                    <?php
                    $groupStmt = $pdo->query("
                        SELECT id, name
                        FROM groups
                        WHERE deleted = 0
                        ORDER BY name
                    ");
                    while ($group = $groupStmt->fetch(PDO::FETCH_ASSOC)):
                    ?>
                        <option value="<?= h($group['id']) ?>"
                                <?= (isset($_GET['group_id']) && $_GET['group_id'] == $group['id']) ? 'selected' : '' ?>>
                            <?= h($group['id']) ?>: <?= h($group['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <button class="btn btn-outline-primary" type="submit">検索</button>
            </form>
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
                <td>
                    <a href="knowledge.php?action=view&id=<?= h($record['id']) ?>">
                        <?= h($record['title']) ?>
                    </a>
                </td>
                <td>
                    <?php if ($record['parent_type'] === 'record'): ?>
                        <span class="badge bg-info">プレーン</span>
                    <?php else: ?>
                        <span class="badge bg-success">ナレッジ</span>
                    <?php endif; ?>
                    <?= h($record['parent_title']) ?>
                </td>
                <td><?= h($record['prompt_title']) ?></td>
                <td><?= h(date('Y/m/d H:i', strtotime($record['created_at']))) ?></td>
                <td>
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

    <?php if (isset($_GET['group_id']) && $_GET['group_id'] !== ''): ?>
    <div class="text-center mb-4">
        <button id="exportGroupButton" class="btn btn-success" data-group-id="<?= h($_GET['group_id']) ?>">
            このグループのナレッジを全てエクスポートする
        </button>
    </div>
    <?php endif; ?>

    <!-- ページネーション -->
    <?php if ($pagination['total_pages'] > 1): ?>
    <nav>
        <ul class="pagination justify-content-center">
            <?php for ($i = $pagination['start']; $i <= $pagination['end']; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" 
                   href="knowledge.php?action=list&page=<?= $i ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?><?= isset($_GET['group_id']) && $_GET['group_id'] !== '' ? '&group_id=' . h($_GET['group_id']) : '' ?>">
                    <?= $i ?>
                </a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>

    <!-- JavaScriptをリスト画面でも読み込む -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        // グループエクスポート機能
        $('#exportGroupButton').click(function(e) {
            e.preventDefault();
            const groupId = $(this).data('group-id');
            
            fetch(`common/export_group_knowledge.php?group_id=${groupId}`)
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(data => {
                            throw new Error(data.message || '不明なエラーが発生しました');
                        });
                    }
                    return response.blob();
                })
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = url;
                    a.download = `group_${groupId}.txt`;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                })
                .catch(error => {
                    alert(error.message);
                });
        });
    });
    </script>

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
                        <a href="record.php?action=view&id=<?= h($knowledge['parent_id']) ?>">
                            <?= h($knowledge['parent_title']) ?>
                        </a>
                    <?php else: ?>
                        <span class="badge bg-success">ナレッジ</span>
                        <a href="knowledge.php?action=view&id=<?= h($knowledge['parent_id']) ?>">
                            <?= h($knowledge['parent_title']) ?>
                        </a>
                    <?php endif; ?>
                </p>
            </div>

            <div class="mb-3">
                <h6>使用プロンプト</h6>
                <p>
                    <?php if ($knowledge['prompt_id']): ?>
                        <a href="prompts.php?action=view&id=<?= h($knowledge['prompt_id']) ?>">
                            <?= h($knowledge['prompt_title']) ?>
                        </a>
                    <?php else: ?>
                        <?= h($knowledge['prompt_title']) ?>
                    <?php endif; ?>
                </p>
            </div>

            <hr>

            <div class="mb-3">
                <h6>Question</h6>
                <p><?= nl2br(h($knowledge['question'])) ?></p>
            </div>
            
            <div class="mb-3">
                <h6>Answer</h6>
                <p><?= nl2br(h($knowledge['answer'])) ?></p>
            </div>

            <div class="mb-3">
                <h6>グループ</h6>
                <p>
                    <?php if ($knowledge['group_id']): ?>
                        <?= h($knowledge['group_id']) ?>: <?= h($knowledge['group_name']) ?>
                    <?php else: ?>
                        （グループ無し）
                    <?php endif; ?>
                </p>
            </div>

            <div class="mb-3">
                <h6>Reference</h6>
                <p><?= !empty($knowledge['reference']) ? nl2br(h($knowledge['reference'])) : '（登録なし）' ?></p>
            </div>
            
            <!-- Knowledge化タスク作成フォーム -->
            <div class="mt-4 border-top pt-4">
                <h6>Knowledge化タスク作成</h6>
                <form id="taskForm" class="mt-3">
                    <input type="hidden" name="action" value="create_task">
                    <input type="hidden" name="source_type" value="knowledge">
                    <input type="hidden" name="source_id" value="<?= h($knowledge['id']) ?>">
                    <input type="hidden" name="source_text" value="<?= h($knowledge['answer']) ?>">
                    
                    <div class="mb-3">
                        <label for="prompt_id" class="form-label">使用プロンプト</label>
                        <select class="form-control" id="prompt_id" name="prompt_id" required>
                            <option value="">選択してください</option>
                            <?php
                            $stmt = $pdo->query("
                                SELECT id, title, content 
                                FROM prompts 
                                WHERE deleted = 0 
                                AND category = 'knowledge_to_knowledge'
                                ORDER BY title
                            ");
                            while ($prompt = $stmt->fetch(PDO::FETCH_ASSOC)):
                            ?>
                                <option value="<?= h($prompt['id']) ?>" 
                                        data-content="<?= h($prompt['content']) ?>">
                                    <?= h($prompt['title']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">プロンプト内容プレビュー</label>
                        <pre class="border p-3 bg-light" id="prompt_preview"></pre>
                    </div>
                    
                    <button type="button" id="createTaskButton" class="btn btn-primary">タスク作成</button>
                </form>
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
                <th>内容</th>
                <th>変更者</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $entry): ?>
            <tr>
                <td><?= h(date('Y/m/d H:i', strtotime($entry['created_at']))) ?></td>
                <td><?= h($entry['title']) ?></td>
                <td>
                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#historyModal<?= h($entry['id']) ?>">
                        内容を表示
                    </button>
                    
                    <!-- 履歴内容モーダル -->
                    <div class="modal fade" id="historyModal<?= h($entry['id']) ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">履歴詳細</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <h6>タイトル</h6>
                                    <p><?= h($entry['title']) ?></p>
                                    <h6>Question</h6>
                                    <p><?= nl2br(h($entry['question'])) ?></p>
                                    <h6>Answer</h6>
                                    <p><?= nl2br(h($entry['answer'])) ?></p>
                                    <?php if ($entry['reference']): ?>
                                    <h6>Reference</h6>
                                    <p><a href="<?= h($entry['reference']) ?>" target="_blank"><?= h($entry['reference']) ?></a></p>
                                    <?php endif; ?>
                                    <p class="text-muted">
                                        変更日時: <?= h(date('Y/m/d H:i', strtotime($entry['created_at']))) ?>
                                    </p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </td>
                <td><?= h($entry['modified_by']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="mb-4">
        <a href="knowledge.php?action=list" class="btn btn-secondary">戻る</a>
        <a href="knowledge.php?action=edit&id=<?= h($knowledge['id']) ?>" 
           class="btn btn-warning">編集</a>
        <button id="exportButton" class="btn btn-success" data-knowledge-id="<?= h($knowledge['id']) ?>">エクスポート</button>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        $('#prompt_id').change(function() {
            const selectedOption = $(this).find('option:selected');
            const promptContent = selectedOption.data('content');
            $('#prompt_preview').text(promptContent || '');
        });

        $('#createTaskButton').click(function() {
            const $button = $(this);
            $button.prop('disabled', true);

            $.ajax({
                url: 'common/api.php',
                method: 'POST',
                data: $('#taskForm').serialize(),
                dataType: 'json',
                success: function(response) {
                    alert(response.message);
                    if (!response.success) {
                        $button.prop('disabled', false);
                    } else {
                        location.reload();
                    }
                },
                error: function() {
                    alert('通信エラーが発生しました。');
                    $button.prop('disabled', false);
                }
            });
        });

        // 個別ナレッジエクスポート機能
        $('#exportButton').click(function(e) {
            e.preventDefault();
            const knowledgeId = $(this).data('knowledge-id');
            
            fetch(`common/export_knowledge.php?id=${knowledgeId}`)
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(data => {
                            throw new Error(data.message || '不明なエラーが発生しました');
                        });
                    }
                    return response.blob();
                })
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = url;
                    a.download = `${knowledgeId}.txt`;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                })
                .catch(error => {
                    alert(error.message);
                });
        });
    });
    </script>

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
            <p>
                <?php if ($knowledge['parent_type'] === 'record'): ?>
                    <span class="badge bg-info">プレーンナレッジ</span>
                <?php else: ?>
                    <span class="badge bg-success">ナレッジ</span>
                <?php endif; ?>
            </p>
        </div>
        
        <div class="mb-3">
            <label class="form-label">親ナレッジ/プレーンナレッジ</label>
            <p>
                <?php if ($knowledge['parent_type'] === 'record'): ?>
                    <a href="record.php?action=view&id=<?= h($knowledge['parent_id']) ?>">
                        <?= h($knowledge['parent_title']) ?>
                    </a>
                <?php else: ?>
                    <a href="knowledge.php?action=view&id=<?= h($knowledge['parent_id']) ?>">
                        <?= h($knowledge['parent_title']) ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>

        <div class="mb-3">
            <label class="form-label">使用プロンプト</label>
            <p>
                <?php if ($knowledge['prompt_id']): ?>
                    <a href="prompts.php?action=view&id=<?= h($knowledge['prompt_id']) ?>">
                        <?= h($knowledge['prompt_title']) ?>
                    </a>
                <?php else: ?>
                    <?= h($knowledge['prompt_title']) ?>
                <?php endif; ?>
            </p>
        </div>
        
        <div class="mb-3">
            <label for="question" class="form-label">Question</label>
            <textarea class="form-control" id="question" name="question" rows="3" required><?= isset($knowledge) ? h($knowledge['question']) : '' ?></textarea>
        </div>
        
        <div class="mb-3">
            <label for="answer" class="form-label">Answer</label>
            <textarea class="form-control" id="answer" name="answer" rows="10" required><?= isset($knowledge) ? h($knowledge['answer']) : '' ?></textarea>
        </div>
        
        <div class="mb-3">
            <label for="reference" class="form-label">Reference</label>
            <input type="url" class="form-control" id="reference" name="reference" 
                   value="<?= isset($knowledge) ? h($knowledge['reference']) : '' ?>">
        </div>
        
        <button type="submit" class="btn btn-primary">保存</button>
        <a href="knowledge.php?action=list" class="btn btn-secondary">キャンセル</a>
    </form>
<?php endif; ?>

<?php require_once APP_ROOT . '/common/footer.php'; ?>