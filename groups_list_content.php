<?php defined('APP_ROOT') or die(); ?>

<!-- Hidden element to store group IDs from search results (sorted in ascending order) -->
<?php
$groupIds = array_column($groups, 'id');
sort($groupIds, SORT_NUMERIC); // 数値として昇順に並べ替え
?>
<div id="searchResultGroupIds" data-group-ids="<?= implode(',', $groupIds) ?>" style="display:none;"></div>

<div class="table-responsive">
    <table class="table table-striped">
        <colgroup>
            <col style="min-width: 80px; width: 80px;">  <!-- ID column -->
            <col style="min-width: 150px; max-width: 200px;">  <!-- Group name column -->
            <col style="min-width: 200px; max-width: 300px;">  <!-- Description column -->
            <col style="min-width: 150px; max-width: 200px;">  <!-- Prompt column -->
            <col style="min-width: 120px; width: 120px;">  <!-- Created date column -->
            <col style="min-width: 120px; width: 120px;">  <!-- Updated date column -->
            <col style="min-width: 300px; width: 300px;">  <!-- Actions column -->
        </colgroup>
        <thead>
            <tr>
                <th>ID</th>
                <th>Group Name</th>
                <th>Description</th>
                <th>Prompt</th>
                <th class="d-none d-md-table-cell">Created</th>
                <th class="d-none d-md-table-cell">Updated</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $group): ?>
        <tr>
            <td><?= h($group['id']) ?></td>
            <td class="text-truncate" style="max-width: 200px;" title="<?= h($group['name']) ?>">
                <a href="groups.php?action=view&id=<?= h($group['id']) ?>" class="d-block text-truncate">
                    <?= h($group['name']) ?>
                </a>
            </td>
            <td style="word-break: break-word;"><?= h($group['detail']) ?></td>
            <td class="text-truncate" style="max-width: 200px;" title="<?= h($group['prompt_title'] ?? 'Not set') ?>">
                <?= h($group['prompt_title'] ?? 'Not set') ?>
            </td>
            <td class="d-none d-md-table-cell"><?= h(date('Y/m/d H:i', strtotime($group['created_at']))) ?></td>
            <td class="d-none d-md-table-cell"><?= h(date('Y/m/d H:i', strtotime($group['updated_at']))) ?></td>
            <td>
                <div class="d-flex flex-wrap gap-2">
                    <a href="groups.php?action=edit&id=<?= h($group['id']) ?>"
                       class="btn btn-sm btn-warning">Edit</a>
                    <button type="button"
                            class="btn btn-sm btn-warning duplicate-group"
                            data-group-id="<?= h($group['id']) ?>"
                            data-group-name="<?= h($group['name']) ?>">Duplicate</button>
                    <button type="button"
                            class="btn btn-sm btn-warning bulk-task-register"
                            data-group-id="<?= h($group['id']) ?>"
                            data-group-name="<?= h($group['name']) ?>">
                        Register Tasks
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-danger force-bulk-task-register"
                            data-group-id="<?= h($group['id']) ?>"
                            data-group-name="<?= h($group['name']) ?>">
                        Force Register All Tasks
                    </button>
                    <a href="groups.php?action=delete&id=<?= h($group['id']) ?>"
                       class="btn btn-sm btn-danger"
                       onclick="return confirm('Are you sure you want to delete?')">Delete</a>
                </div>
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
    
    /* Make link text hoverable even when truncated */
    .text-truncate a {
        display: block;
        width: 100%;
    }
    
    /* Tooltip styles */
    [title] {
        position: relative;
        cursor: help;
    }

    /* Button group styles */
    .gap-2 {
        gap: 0.5rem !important;
    }

    /* Smartphone display adjustments */
    @media (max-width: 576px) {
        .table td {
            white-space: normal;
            word-break: break-word;
        }
        .text-truncate {
            max-width: 150px !important;
        }
        .d-flex.flex-wrap {
            gap: 0.25rem !important;
        }
        .btn {
            width: 100%;
        }
    }
</style>

<!-- Items per page selection -->
<div class="row mb-3">
    <div class="col-auto">
        <form class="d-flex align-items-center" method="GET" action="groups.php">
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
            <a class="page-link" href="<?= $pagination['has_previous'] ? 'groups.php?action=list&page=' . ($page - 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="Previous page" <?= !$pagination['has_previous'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
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
                    <a class="page-link" href="groups.php?action=list&page=<?= $p ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?>&per_page=<?= $perPage ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>>
                        <?= $p ?>
                    </a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- Next button -->
        <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $pagination['has_next'] ? 'groups.php?action=list&page=' . ($page + 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="Next page" <?= !$pagination['has_next'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                <span aria-hidden="true">&raquo;</span>
                <span class="visually-hidden">Next page</span>
            </a>
        </li>
    </ul>
</nav>

<!-- Direct page number input form -->
<div class="text-center mt-3">
    <form class="d-inline-flex align-items-center" method="GET" action="groups.php">
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

<!-- Existing JavaScript -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // Group duplication function
    $('.duplicate-group').click(function() {
        const $button = $(this);
        const groupId = $button.data('group-id');
        const groupName = $button.data('group-name');

        if (confirm(`Do you want to duplicate the "${groupName}" group?\nThe group and related plain knowledge will be duplicated.`)) {
            $button.prop('disabled', true);

            $.ajax({
                url: 'common/api.php',
                method: 'POST',
                data: {
                    action: 'duplicate_group',
                    group_id: groupId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('Group has been duplicated.');
                        location.reload();
                    } else {
                        let errorMsg = 'An error occurred: ' + response.message;
                        if (response.details) {
                            errorMsg += '\n\nDetails:\n';
                            errorMsg += `Error type: ${response.details.error_type}\n`;
                            errorMsg += `Error location: ${response.details.error_file}:${response.details.error_line}\n`;
                            if (response.details.group_id) {
                                errorMsg += `Target group ID: ${response.details.group_id}`;
                            }
                        }
                        alert(errorMsg);
                        $button.prop('disabled', false);
                    }
                },
                error: function(xhr) {
                    let errorMessage = 'A communication error occurred.';
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response && response.message) {
                            errorMessage = response.message;
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e);
                    }
                    alert(errorMessage);
                    $button.prop('disabled', false);
                }
            });
        }
    });

    // Task registration function
    $('.bulk-task-register, .force-bulk-task-register').click(function() {
        const $button = $(this);
        const groupId = $button.data('group-id');
        const groupName = $button.data('group-name');
        const isForce = $button.hasClass('force-bulk-task-register');
        
        const confirmMessage = isForce
            ? 'This will delete all knowledge in this group and register all tasks. Are you sure?'
            : `Are you sure you want to register all plain knowledge in the "${groupName}" group as tasks? Knowledge to be updated will be deleted first.`;

        if (confirm(confirmMessage)) {
            $button.prop('disabled', true);

            $.ajax({
                url: 'common/api.php',
                method: 'POST',
                data: {
                    action: isForce ? 'force_bulk_task_register_by_group' : 'bulk_task_register_by_group',
                    group_id: groupId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('Task registration completed.');
                    } else {
                        let errorMsg = 'An error occurred: ' + response.message;
                        if (response.details) {
                            errorMsg += '\n\nDetails:\n';
                            errorMsg += `Error type: ${response.details.error_type}\n`;
                            errorMsg += `Error location: ${response.details.error_file}:${response.details.error_line}\n`;
                            if (response.details.group_id) {
                                errorMsg += `Target group ID: ${response.details.group_id}`;
                            }
                        }
                        alert(errorMsg);
                        $button.prop('disabled', false);
                    }
                },
                error: function(xhr) {
                    let errorMessage = 'A communication error occurred.';
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response && response.message) {
                            errorMessage = response.message;
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e);
                    }
                    alert(errorMessage);
                    $button.prop('disabled', false);
                }
            });
        }
    });
});
</script>