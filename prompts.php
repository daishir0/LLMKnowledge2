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
$perPage = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 10; // Limited to range 10-100

switch ($action) {
    case 'list':
        // Search processing
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
        
        // Get history data
        $stmt = $pdo->prepare("
            SELECT h.*, h.modified_by as modified_by_user
            FROM prompt_history h
            WHERE h.id = :history_id AND h.prompt_id = :prompt_id
        ");
        $stmt->execute([':history_id' => $history_id, ':prompt_id' => $id]);
        $history = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$history) {
            $_SESSION['error_message'] = "The specified history was not found.";
            header("Location: prompts.php?action=view&id=" . $id);
            exit;
        }
        break;
        
    case 'view':
        $id = $_GET['id'] ?? 0;
        // Get prompt details
        $stmt = $pdo->prepare("
            SELECT p.*,
                   COUNT(k.id) as usage_count
            FROM prompts p
            LEFT JOIN knowledge k ON p.id = k.prompt_id AND k.deleted = 0
            WHERE p.id = :id
            GROUP BY p.id
        ");
        $stmt->execute([':id' => $id]);
        $prompt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get list of knowledge using this prompt
        $stmt = $pdo->prepare("
            SELECT id, title, created_at
            FROM knowledge
            WHERE prompt_id = :prompt_id AND deleted = 0
            ORDER BY created_at DESC
        ");
        $stmt->execute([':prompt_id' => $id]);
        $relatedKnowledge = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get history
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
                    
                    // Create prompt
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
                    
                    // Record history
                    $historyData = [
                        'title' => $data['title'],
                        'content' => $data['content']
                    ];
                    logHistory($pdo, 'prompts', $id, $historyData);
                    
                    $pdo->commit();
                    
                    // Redirect to list page
                    header("Location: prompts.php?action=list");
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            } else {
                $id = $_GET['id'];
                // Validate POST data
                if (empty($_POST['title']) || empty($_POST['content']) || empty($_POST['category'])) {
                    throw new Exception("Required fields are missing");
                }

                try {
                    // Update prompt
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

                    // Record history (continue processing even if an error occurs)
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
                    
                    // Redirect to detail page
                    header("Location: prompts.php?action=view&id=" . $id);
                    exit;
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = date('Y-m-d H:i:s') . " Error in prompt update: " . $e->getMessage() . "\n";
                    $error .= date('Y-m-d H:i:s') . " Debug - Stack trace: " . $e->getTraceAsString() . "\n";
                    file_put_contents(dirname(__FILE__) . '/common/logs.txt', $error, FILE_APPEND);
                    
                    // Save error message to session
                    $_SESSION['error_message'] = "Failed to update the prompt.";
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

    case 'duplicate':
        if (isset($_GET['id'])) {
            try {
                $pdo->beginTransaction();
                
                // Get original prompt
                $stmt = $pdo->prepare("SELECT * FROM prompts WHERE id = :id AND deleted = 0");
                $stmt->execute([':id' => $_GET['id']]);
                $original = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($original) {
                    // Create new prompt
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
                    
                    $data = [
                        'title' => $original['title'] . ' (copy)',
                        'content' => $original['content'],
                        'category' => $original['category'],
                        'created_by' => $_SESSION['user']
                    ];
                    
                    $stmt->execute($data);
                    $newId = $pdo->lastInsertId();
                    
                    // Record history
                    $historyData = [
                        'title' => $data['title'],
                        'content' => $data['content']
                    ];
                    logHistory($pdo, 'prompts', $newId, $historyData);
                    
                    $pdo->commit();
                    
                    // Redirect to list page
                    header("Location: prompts.php?action=list");
                    exit;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error in prompt duplication: " . $e->getMessage());
                $_SESSION['error_message'] = "Failed to duplicate the prompt.";
                header("Location: prompts.php?action=list");
                exit;
            }
        }
        redirect('prompts.php?action=list');
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

<!-- List display screen -->
<?php if ($action === 'list'): ?>
    <h1 class="mb-4">Prompt Management</h1>
    
    <div class="row mb-4">
        <div class="col">
            <form class="d-flex" method="GET" action="prompts.php">
                <input type="hidden" name="action" value="list">
                <input type="search" name="search" class="form-control me-2" 
                       value="<?= h($searchTerm) ?>" placeholder="Search...">
                <button class="btn btn-outline-primary" type="submit">Search</button>
            </form>
        </div>
        <div class="col text-end">
            <a href="prompts.php?action=create" class="btn btn-primary">Create New</a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped">
            <colgroup>
                <col style="min-width: 80px; width: 80px;">  <!-- ID column -->
                <col style="min-width: 200px; max-width: 300px;">  <!-- Title column -->
                <col style="min-width: 150px; width: 150px;">  <!-- Category column -->
                <col style="min-width: 100px; width: 100px;">  <!-- Usage count column -->
                <col style="min-width: 120px; width: 120px;">  <!-- Created date column -->
                <col style="min-width: 160px; width: 160px;">  <!-- Actions column -->
            </colgroup>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Category</th>
                    <th class="d-none d-md-table-cell">Usage Count</th>
                    <th class="d-none d-md-table-cell">Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                <tr>
                    <td><?= h($record['id']) ?></td>
                    <td class="text-truncate" style="max-width: 300px;" title="<?= h($record['title']) ?>">
                        <a href="prompts.php?action=view&id=<?= h($record['id']) ?>" class="d-block text-truncate">
                            <?= h($record['title']) ?>
                        </a>
                    </td>
                    <td>
                        <?php if ($record['category'] === 'plain_to_knowledge'): ?>
                            <span class="badge bg-info">Plain→Knowledge</span>
                        <?php else: ?>
                            <span class="badge bg-success">Knowledge→Knowledge</span>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell"><?= h($record['usage_count']) ?></td>
                    <td class="d-none d-md-table-cell"><?= h(date('Y/m/d H:i', strtotime($record['created_at']))) ?></td>
                    <td>
                        <a href="prompts.php?action=edit&id=<?= h($record['id']) ?>"
                           class="btn btn-sm btn-warning me-2">Edit</a>
                        <a href="prompts.php?action=duplicate&id=<?= h($record['id']) ?>"
                           class="btn btn-sm btn-warning me-2"
                           onclick="return confirm('Do you want to duplicate this prompt?')">Duplicate</a>
                        <a href="prompts.php?action=delete&id=<?= h($record['id']) ?>"
                           class="btn btn-sm btn-danger"
                           onclick="return confirm('Are you sure you want to delete?')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <style>
        /* Table cell styles */
        .table td {
            vertical-align: middle;
        }
        
        /* Make truncated link text hoverable */
        .text-truncate a {
            display: block;
            width: 100%;
        }
        
        /* Tooltip styles */
        [title] {
            position: relative;
            cursor: help;
        }

        /* Smartphone button display adjustments */
        @media (max-width: 576px) {
            .btn {
                display: block;
                width: 100%;
                margin-bottom: 0.25rem;
            }
            .me-2 {
                margin-right: 0 !important;
            }
        }
    </style>

    <!-- Items per page selection -->
    <div class="row mb-3">
        <div class="col-auto">
            <form class="d-flex align-items-center" method="GET" action="prompts.php">
                <input type="hidden" name="action" value="list">
                <input type="hidden" name="search" value="<?= h($searchTerm) ?>">
                <label for="perPage" class="me-2">Items per page:</label>
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
                <a class="page-link" href="<?= $pagination['has_previous'] ? 'prompts.php?action=list&page=' . ($page - 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="Previous page" <?= !$pagination['has_previous'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
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
                        <a class="page-link" href="prompts.php?action=list&page=<?= $p ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?>&per_page=<?= $perPage ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>>
                            <?= $p ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- Next button -->
            <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $pagination['has_next'] ? 'prompts.php?action=list&page=' . ($page + 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="Next page" <?= !$pagination['has_next'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                    <span aria-hidden="true">&raquo;</span>
                    <span class="visually-hidden">Next page</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Direct page number input form -->
    <div class="text-center mt-3">
        <form class="d-inline-flex align-items-center" method="GET" action="prompts.php">
            <input type="hidden" name="action" value="list">
            <input type="hidden" name="search" value="<?= h($searchTerm) ?>">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <label for="pageInput" class="me-2">Go to page:</label>
            <input type="number" id="pageInput" name="page" class="form-control form-control-sm me-2" style="width: 80px;" min="1" max="<?= $pagination['total_pages'] ?>" value="<?= $page ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">Go</button>
            <span class="ms-2">/ <?= $pagination['total_pages'] ?> pages</span>
        </form>
    </div>
    <?php endif; ?>

<!-- History detail view screen -->
<?php elseif ($action === 'view_history'): ?>
    <h1 class="mb-4">Prompt History Details</h1>
    
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title"><?= h($history['title']) ?></h5>
            
            <div class="mb-3">
                <h6>Prompt Content</h6>
                <pre class="border p-3 bg-light"><?= h($history['content']) ?></pre>
            </div>
            
            <div class="mb-3">
                <h6>Change Information</h6>
                <p>Change Date: <?= h(date('Y/m/d H:i', strtotime($history['created_at']))) ?></p>
                <p>Modified By: <?= h($history['modified_by_user'] ?? 'Not set') ?></p>
            </div>
        </div>
    </div>
    
    <div class="mb-4">
        <a href="prompts.php?action=view&id=<?= h($id) ?>" class="btn btn-secondary">Back</a>
    </div>

<!-- Detail view screen -->
<?php elseif ($action === 'view'): ?>
    <h1 class="mb-4">Prompt Details</h1>
    
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">
                <?= h($prompt['title']) ?>
                <?php if ($prompt['deleted'] == 1): ?>
                    <span class="text-danger">(Deleted)</span>
                <?php endif; ?>
            </h5>
            
            <div class="mb-3">
                <h6>Category</h6>
                <p>
                    <?php if ($prompt['category'] === 'plain_to_knowledge'): ?>
                        <span class="badge bg-info">Plain→Knowledge</span>
                    <?php else: ?>
                        <span class="badge bg-success">Knowledge→Knowledge</span>
                    <?php endif; ?>
                </p>
            </div>
            
            <div class="mb-3">
                <h6>Prompt Content</h6>
                <pre class="border p-3 bg-light"><?= h($prompt['content']) ?></pre>
            </div>
            
            <?php if ($relatedKnowledge): ?>
            <div class="mb-3">
                <h6>Knowledge Using This Prompt (<?= count($relatedKnowledge) ?> items)</h6>
                <ul>
                    <?php foreach ($relatedKnowledge as $knowledge): ?>
                    <li>
                        <a href="knowledge.php?action=view&id=<?= h($knowledge['id']) ?>">
                            <?= h($knowledge['title']) ?>
                        </a>
                        <small class="text-muted">(<?= h($knowledge['created_at']) ?>)</small>
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
                <th>Modified By</th>
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
        <a href="prompts.php?action=list" class="btn btn-secondary">Back</a>
        <a href="prompts.php?action=edit&id=<?= h($prompt['id']) ?>"
           class="btn btn-warning">Edit</a>
    </div>

<!-- Create/Edit screen -->
<?php else: ?>
    <h1 class="mb-4">
        <?= $action === 'create' ? 'Create Prompt' : 'Edit Prompt' ?>
    </h1>
    
    <form method="POST" class="needs-validation" novalidate>
        <div class="mb-3">
            <label for="title" class="form-label">Title</label>
            <input type="text" class="form-control" id="title" name="title" 
                   value="<?= isset($prompt) ? h($prompt['title']) : '' ?>" required>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Category</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="category" 
                       id="category_p2k" value="plain_to_knowledge" 
                       <?= isset($prompt) && $prompt['category'] === 'plain_to_knowledge' ? 'checked' : '' ?>>
                <label class="form-check-label" for="category_p2k">
                    Plain→Knowledge
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="category"
                       id="category_k2k" value="knowledge_to_knowledge"
                       <?= isset($prompt) && $prompt['category'] === 'knowledge_to_knowledge' ? 'checked' : '' ?>>
                <label class="form-check-label" for="category_k2k">
                    Knowledge→Knowledge
                </label>
            </div>
        </div>
        
        <div class="mb-3">
            <label for="content" class="form-label">Prompt Content</label>
            <textarea class="form-control" id="content" name="content" rows="10" required><?= isset($prompt) ? h($prompt['content']) : '' ?></textarea>
            Note: {{reference}} will be replaced with the value registered in the reference field.
        </div>
        
        <button type="submit" class="btn btn-primary">Save</button>
        <a href="prompts.php?action=list" class="btn btn-secondary">Cancel</a>
    </form>
<?php endif; ?>

<?php require_once APP_ROOT . '/common/footer.php'; ?>