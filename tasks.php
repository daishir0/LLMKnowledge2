<?php
define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/common/config.php';
require_once APP_ROOT . '/common/functions.php';
require_once APP_ROOT . '/common/auth.php';
require_once APP_ROOT . '/common/header.php';

$action = $_GET['action'] ?? 'list';
$searchTerm = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 10; // Limited to range 10-100

switch ($action) {
    case 'delete_pending':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Set deleted flag to 1 for tasks with 'pending' status
            $stmt = $pdo->prepare("
                UPDATE tasks
                SET deleted = 1,
                    updated_at = '$timestamp'
                WHERE status = 'pending'
                AND deleted = 0
            ");
            $stmt->execute();
            $affected = $stmt->rowcount();
            
            // Return response in JSON format
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => "Deleted {$affected} pending tasks."]);
            exit;
        }
        break;
        
    case 'list':
        // Search processing
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
            // Get total count
            $stmt = $pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted = 0");
            $total = $stmt->fetchColumn();
            
            // Get task list
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
<!-- List display screen -->
<?php if ($action === 'list'): ?>
    <h1 class="mb-4">Task Management</h1>
    
    <div class="row mb-4">
        <div class="col">
            <form class="d-flex" method="GET" action="tasks.php">
                <input type="hidden" name="action" value="list">
                <input type="search" name="search" class="form-control me-2"
                       value="<?= h($searchTerm) ?>" placeholder="Search...">
                <button class="btn btn-outline-primary" type="submit">Search</button>
            </form>
        </div>
        <div class="col text-end">
            <button id="deletePendingTasksBtn" class="btn btn-danger">Delete Pending Tasks</button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped">
            <colgroup>
                <col style="min-width: 80px; width: 80px;">  <!-- ID column -->
                <col style="min-width: 200px; max-width: 300px;">  <!-- Source column -->
                <col style="min-width: 200px; max-width: 300px;">  <!-- Prompt column -->
                <col style="min-width: 120px; width: 120px;">  <!-- Status column -->
                <col style="min-width: 120px; width: 120px;">  <!-- Creator column -->
                <col style="min-width: 120px; width: 120px;">  <!-- Created date column -->
            </colgroup>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Source</th>
                    <th>Prompt</th>
                    <th>Status</th>
                    <th class="d-none d-md-table-cell">Creator</th>
                    <th class="d-none d-md-table-cell">Created</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tasks as $task): ?>
            <tr>
                <td><?= h($task['id']) ?></td>
                <td style="max-width: 300px;">
                    <?php if ($task['source_type'] === 'record'): ?>
                        <span class="badge bg-info me-1">Plain</span>
                    <?php else: ?>
                        <span class="badge bg-success me-1">Knowledge</span>
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
        /* Table cell styles */
        .table td {
            vertical-align: middle;
        }
        
        /* Badge right margin */
        .badge {
            margin-right: 8px;
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

        /* Smartphone display adjustments */
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

    <!-- Items per page selection -->
    <div class="row mb-3">
        <div class="col-auto">
            <form class="d-flex align-items-center" method="GET" action="tasks.php">
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
                <a class="page-link" href="<?= $pagination['has_previous'] ? 'tasks.php?action=list&page=' . ($page - 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="Previous page" <?= !$pagination['has_previous'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
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
                        <a class="page-link" href="tasks.php?action=list&page=<?= $p ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?>&per_page=<?= $perPage ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>>
                            <?= $p ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- Next button -->
            <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $pagination['has_next'] ? 'tasks.php?action=list&page=' . ($page + 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="Next page" <?= !$pagination['has_next'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                    <span aria-hidden="true">&raquo;</span>
                    <span class="visually-hidden">Next page</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Direct page number input form -->
    <div class="text-center mt-3">
        <form class="d-inline-flex align-items-center" method="GET" action="tasks.php">
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

<!-- Detail view screen -->
<?php elseif ($action === 'view'): ?>
    <h1 class="mb-4">Task Details</h1>
    
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">
                <?php if ($task['source_type'] === 'record'): ?>
                    <span class="badge bg-info">Plain</span>
                <?php else: ?>
                    <span class="badge bg-success">Knowledge</span>
                <?php endif; ?>
                <?= h($task['source_title']) ?>
            </h5>
            
            <div class="mb-3">
                <h6>Status</h6>
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
                <h6>Source Text</h6>
                <pre class="border p-3 bg-light"><?= h($task['source_text']) ?></pre>
            </div>
            
            <div class="mb-3">
                <h6>Used Prompt</h6>
                <p><?= h($task['prompt_title']) ?></p>
                <pre class="border p-3 bg-light"><?= h($task['prompt_content']) ?></pre>
            </div>
            
            <?php if ($task['error_message']): ?>
            <div class="mb-3">
                <h6>Error Message</h6>
                <pre class="border p-3 bg-light text-danger"><?= h($task['error_message']) ?></pre>
            </div>
            <?php endif; ?>
            
            <?php if ($task['result_knowledge_id']): ?>
            <div class="mb-3">
                <h6>Generated Knowledge</h6>
                <p>
                    <a href="knowledge.php?action=view&id=<?= h($task['result_knowledge_id']) ?>">
                        <?= h($task['result_title']) ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>
            
            <div class="mb-3">
                <h6>Creation Information</h6>
                <p>
                    Creator: <?= h($task['created_by']) ?><br>
                    Created: <?= h($task['created_at']) ?><br>
                    Updated: <?= h($task['updated_at']) ?>
                </p>
            </div>
        </div>
    </div>

    <div class="mb-4">
        <a href="tasks.php?action=list" class="btn btn-secondary">Back</a>
        <?php if ($task['status'] === 'pending'): ?>
            <form method="POST" action="tasks.php?action=cancel" class="d-inline">
                <input type="hidden" name="id" value="<?= h($task['id']) ?>">
                <button type="submit" class="btn btn-warning"
                        onclick="return confirm('Do you want to cancel this task?')">
                    Cancel
                </button>
            </form>
        <?php endif; ?>
    </div>

<?php endif; ?>

<!-- JavaScript code -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Event listener for the Delete Pending Tasks button
    const deletePendingBtn = document.getElementById('deletePendingTasksBtn');
    if (deletePendingBtn) {
        deletePendingBtn.addEventListener('click', function() {
            // Display confirmation dialog
            if (confirm('Are you sure you want to delete all pending tasks? This action cannot be undone.')) {
                // Send Ajax request
                fetch('tasks.php?action=delete_pending', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        // Reload the page
                        window.location.reload();
                    } else {
                        alert('An error occurred: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred.');
                });
            }
        });
    }
});
</script>

<?php require_once APP_ROOT . '/common/footer.php'; ?>