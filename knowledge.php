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
                
                // 元のフィルター条件を維持したリダイレクト
                $redirectParams = [];
                if (!empty($_POST['original_search'])) {
                    $redirectParams[] = 'search=' . urlencode($_POST['original_search']);
                }
                if (!empty($_POST['original_group_id'])) {
                    $redirectParams[] = 'group_id=' . urlencode($_POST['original_group_id']);
                }
                
                $redirectUrl = 'knowledge.php?action=list';
                if (!empty($redirectParams)) {
                    $redirectUrl .= '&' . implode('&', $redirectParams);
                }
                
                redirect($redirectUrl);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error in knowledge create/edit: " . $e->getMessage());
                $_SESSION['error_message'] = "エラーが発生しました。";
                
                // エラー時も元のフィルター条件を維持
                $redirectParams = [];
                if (!empty($_POST['original_search'])) {
                    $redirectParams[] = 'search=' . urlencode($_POST['original_search']);
                }
                if (!empty($_POST['original_group_id'])) {
                    $redirectParams[] = 'group_id=' . urlencode($_POST['original_group_id']);
                }
                
                $redirectUrl = 'knowledge.php?action=list';
                if (!empty($redirectParams)) {
                    $redirectUrl .= '&' . implode('&', $redirectParams);
                }
                
                redirect($redirectUrl);
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

<!-- List display screen -->
<?php if ($action === 'list'): ?>
    <h1 class="mb-4">Knowledge Management</h1>
    
    <div class="row mb-4">
        <div class="col">
            <form class="d-flex" method="GET" action="knowledge.php">
                <input type="hidden" name="action" value="list">
                <input type="search" name="search" class="form-control me-2"
                       value="<?= h($searchTerm) ?>" placeholder="Search...">
                <select name="group_id" class="form-select me-2" style="width: auto;">
                    <option value="">No group specified</option>
                    <?php
                    $groupStmt = $pdo->query("
                        SELECT id, name
                        FROM groups
                        WHERE deleted = 0
                        ORDER BY id
                    ");
                    while ($group = $groupStmt->fetch(PDO::FETCH_ASSOC)):
                    ?>
                        <option value="<?= h($group['id']) ?>"
                                <?= (isset($_GET['group_id']) && $_GET['group_id'] == $group['id']) ? 'selected' : '' ?>>
                            <?= h($group['id']) ?>: <?= h($group['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <button class="btn btn-outline-primary" type="submit">Search</button>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped">
            <colgroup>
                <col style="min-width: 80px; width: 80px;">  <!-- ID column -->
                <col style="min-width: 200px; max-width: 300px;">  <!-- Title column -->
                <col style="min-width: 200px; max-width: 300px;">  <!-- Parent knowledge column -->
                <col style="min-width: 150px; max-width: 200px;">  <!-- Prompt column -->
                <col style="min-width: 120px; width: 120px;">  <!-- Created date column -->
                <col style="min-width: 160px; width: 160px;">  <!-- Actions column -->
            </colgroup>
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Parent Knowledge/Plain Knowledge</th>
                <th>Used Prompt</th>
                <th>Created Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($records as $record): ?>
            <tr>
                <td><?= h($record['id']) ?></td>
                <td class="text-truncate" style="max-width: 300px;" title="<?= h($record['title']) ?>">
                    <a href="knowledge.php?action=view&id=<?= h($record['id']) ?>">
                        <?= h($record['title']) ?>
                    </a>
                </td>
                <td style="word-break: break-word;">
                    <?php if ($record['parent_type'] === 'record'): ?>
                        <span class="badge bg-info">Plain</span>
                    <?php else: ?>
                        <span class="badge bg-success">Knowledge</span>
                    <?php endif; ?>
                    <span style="display: inline-block; max-width: calc(100% - 70px); word-break: break-word;">
                        <?= h($record['parent_title']) ?>
                    </span>
                </td>
                <td class="text-truncate" style="max-width: 200px;" title="<?= h($record['prompt_title']) ?>">
                    <?= h($record['prompt_title']) ?>
                </td>
                <td><?= h(date('Y/m/d H:i', strtotime($record['created_at']))) ?></td>
                <td>
                    <a href="knowledge.php?action=edit&id=<?= h($record['id']) ?>&search=<?= h($searchTerm) ?>&group_id=<?= h($groupId) ?>"
                       class="btn btn-sm btn-warning">Edit</a>
                    <a href="knowledge.php?action=delete&id=<?= h($record['id']) ?>"
                       class="btn btn-sm btn-danger"
                       onclick="return confirm('Are you sure you want to delete?')">Delete</a>
                </td>
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
    </style>

    <?php if (isset($_GET['group_id']) && $_GET['group_id'] !== ''): ?>
    <div class="text-center mb-4">
        <button id="exportGroupButton" class="btn btn-success" data-group-id="<?= h($_GET['group_id']) ?>">
            Export All Knowledge in This Group
        </button>
    </div>
    <?php endif; ?>

    <!-- Display count selection -->
    <div class="row mb-3">
        <div class="col-auto">
            <form class="d-flex align-items-center" method="GET" action="knowledge.php">
                <input type="hidden" name="action" value="list">
                <input type="hidden" name="search" value="<?= h($searchTerm) ?>">
                <input type="hidden" name="group_id" value="<?= h($groupId) ?>">
                <label for="perPage" class="me-2">Display count:</label>
                <select id="perPage" name="per_page" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                    <?php foreach ([10, 20, 50, 100] as $value): ?>
                    <option value="<?= $value ?>" <?= $perPage == $value ? 'selected' : '' ?>><?= $value ?> items</option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="col text-end">
            <p class="mb-0"><?= h($pagination['showing']) ?></p>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($pagination['total_pages'] > 1): ?>
    <nav aria-label="Page navigation">
        <ul class="pagination justify-content-center">
            <!-- Previous button -->
            <li class="page-item <?= !$pagination['has_previous'] ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $pagination['has_previous'] ? 'knowledge.php?action=list&page=' . ($page - 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . (isset($_GET['group_id']) && $_GET['group_id'] !== '' ? '&group_id=' . h($_GET['group_id']) : '') : '#' ?>" aria-label="Previous page" <?= !$pagination['has_previous'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                    <span aria-hidden="true">&laquo;</span>
                    <span class="visually-hidden">Previous page</span>
                </a>
            </li>

            <!-- Page numbers -->
            <?php foreach ($pagination['pages'] as $p): ?>
                <?php if ($p === '...'): ?>
                    <li class="page-item disabled">
                        <span class="page-link">...</span>
                    </li>
                <?php else: ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="knowledge.php?action=list&page=<?= $p ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?><?= isset($_GET['group_id']) && $_GET['group_id'] !== '' ? '&group_id=' . h($_GET['group_id']) : '' ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>>
                            <?= $p ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- Next button -->
            <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $pagination['has_next'] ? 'knowledge.php?action=list&page=' . ($page + 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . (isset($_GET['group_id']) && $_GET['group_id'] !== '' ? '&group_id=' . h($_GET['group_id']) : '') : '#' ?>" aria-label="Next page" <?= !$pagination['has_next'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                    <span aria-hidden="true">&raquo;</span>
                    <span class="visually-hidden">Next page</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Direct page number input form -->
    <div class="text-center mt-3">
        <form class="d-inline-flex align-items-center" method="GET" action="knowledge.php">
            <input type="hidden" name="action" value="list">
            <input type="hidden" name="search" value="<?= h($searchTerm) ?>">
            <input type="hidden" name="group_id" value="<?= h($groupId) ?>">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <label for="pageInput" class="me-2">Go to page:</label>
            <input type="number" id="pageInput" name="page" class="form-control form-control-sm me-2" style="width: 80px;" min="1" max="<?= $pagination['total_pages'] ?>" value="<?= $page ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">Go</button>
            <span class="ms-2">/ <?= $pagination['total_pages'] ?> pages</span>
        </form>
    </div>
    <?php endif; ?>

    <!-- JavaScriptをリスト画面でも読み込む -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        // Group export function
        $('#exportGroupButton').click(function(e) {
            e.preventDefault();
            const groupId = $(this).data('group-id');
            
            fetch(`common/export_group_knowledge.php?group_id=${groupId}`)
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(data => {
                            throw new Error(data.message || 'An unknown error has occurred');
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
    <h1 class="mb-4">Knowledge Details</h1>
    
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title"><?= h($knowledge['title']) ?></h5>
            
            <div class="mb-3">
                <h6>Parent Knowledge/Plain Knowledge</h6>
                <p>
                    <?php if ($knowledge['parent_type'] === 'record'): ?>
                        <span class="badge bg-info">Plain</span>
                        <a href="record.php?action=view&id=<?= h($knowledge['parent_id']) ?>">
                            <?= h($knowledge['parent_title']) ?>
                        </a>
                    <?php else: ?>
                        <span class="badge bg-success">Knowledge</span>
                        <a href="knowledge.php?action=view&id=<?= h($knowledge['parent_id']) ?>">
                            <?= h($knowledge['parent_title']) ?>
                        </a>
                    <?php endif; ?>
                </p>
            </div>

            <div class="mb-3">
                <h6>Used Prompt</h6>
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
                <h6>Group</h6>
                <p>
                    <?php if ($knowledge['group_id']): ?>
                        <?= h($knowledge['group_id']) ?>: <?= h($knowledge['group_name']) ?>
                    <?php else: ?>
                        (No Group)
                    <?php endif; ?>
                </p>
            </div>

            <div class="mb-3">
                <h6>Reference</h6>
                <p><?= !empty($knowledge['reference']) ? nl2br(h($knowledge['reference'])) : '(Not Registered)' ?></p>
            </div>
            
            <!-- Knowledge化タスク作成フォーム -->
            <div class="mt-4 border-top pt-4">
                <h6>Create Knowledge Task</h6>
                <form id="taskForm" class="mt-3">
                    <input type="hidden" name="action" value="create_task">
                    <input type="hidden" name="source_type" value="knowledge">
                    <input type="hidden" name="source_id" value="<?= h($knowledge['id']) ?>">
                    <input type="hidden" name="source_text" value="<?= h($knowledge['answer']) ?>">
                    
                    <div class="mb-3">
                        <label for="prompt_id" class="form-label">Used Prompt</label>
                        <select class="form-control" id="prompt_id" name="prompt_id" required>
                            <option value="">Please select</option>
                            <?php
                            $stmt = $pdo->query("
                                SELECT id, title, content
                                FROM prompts
                                WHERE deleted = 0
                                AND category = 'knowledge_to_knowledge'
                                ORDER BY id ASC
                            ");
                            while ($prompt = $stmt->fetch(PDO::FETCH_ASSOC)):
                            ?>
                                <option value="<?= h($prompt['id']) ?>" 
                                        data-content="<?= h($prompt['content']) ?>">
                                    <?= h($prompt['id']) ?>: <?= h($prompt['title']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Prompt Content Preview</label>
                        <pre class="border p-3 bg-light" id="prompt_preview"></pre>
                    </div>
                    
                    <button type="button" id="createTaskButton" class="btn btn-primary">Create Task</button>
                </form>
            </div>
            
            <?php if ($childKnowledge): ?>
            <div class="mb-3">
                <h6>Child Knowledge</h6>
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

    <!-- History display -->
    <h3 class="mb-3">Change History</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Change Date</th>
                <th>Title</th>
                <th>Content</th>
                <th>Modified By</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $entry): ?>
            <tr>
                <td><?= h(date('Y/m/d H:i', strtotime($entry['created_at']))) ?></td>
                <td><?= h($entry['title']) ?></td>
                <td>
                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#historyModal<?= h($entry['id']) ?>">
                        Show Content
                    </button>
                    
                    <!-- 履歴内容モーダル -->
                    <div class="modal fade" id="historyModal<?= h($entry['id']) ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">History Details</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <h6>Title</h6>
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
                                        Change Date: <?= h(date('Y/m/d H:i', strtotime($entry['created_at']))) ?>
                                    </p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
        <a href="knowledge.php?action=list" class="btn btn-secondary">Back</a>
        <a href="knowledge.php?action=edit&id=<?= h($knowledge['id']) ?>"
           class="btn btn-warning">Edit</a>
        <button id="exportButton" class="btn btn-success" data-knowledge-id="<?= h($knowledge['id']) ?>">Export</button>
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
                    alert('A communication error has occurred.');
                    $button.prop('disabled', false);
                }
            });
        });

        // Individual knowledge export function
        $('#exportButton').click(function(e) {
            e.preventDefault();
            const knowledgeId = $(this).data('knowledge-id');
            
            fetch(`common/export_knowledge.php?id=${knowledgeId}`)
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(data => {
                            throw new Error(data.message || 'An unknown error has occurred');
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
        <?= $action === 'create' ? 'Create Knowledge' : 'Edit Knowledge' ?>
    </h1>
    
    <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="original_search" value="<?= h($_GET['search'] ?? '') ?>">
        <input type="hidden" name="original_group_id" value="<?= h($_GET['group_id'] ?? '') ?>">
        <div class="mb-3">
            <label for="title" class="form-label">Title</label>
            <input type="text" class="form-control" id="title" name="title" 
                   value="<?= isset($knowledge) ? h($knowledge['title']) : '' ?>" required>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Parent Knowledge Type</label>
            <p>
                <?php if ($knowledge['parent_type'] === 'record'): ?>
                    <span class="badge bg-info">Plain Knowledge</span>
                <?php else: ?>
                    <span class="badge bg-success">Knowledge</span>
                <?php endif; ?>
            </p>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Parent Knowledge/Plain Knowledge</label>
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
            <label class="form-label">Used Prompt</label>
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
        
        <button type="submit" class="btn btn-primary">Save</button>
        <a href="knowledge.php?action=list" class="btn btn-secondary">Cancel</a>
    </form>
<?php endif; ?>

<?php require_once APP_ROOT . '/common/footer.php'; ?>