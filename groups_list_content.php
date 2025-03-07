<?php defined('APP_ROOT') or die(); ?>

<!-- 検索結果のグループIDを保持するhidden要素（昇順に並べ替え） -->
<?php
$groupIds = array_column($groups, 'id');
sort($groupIds, SORT_NUMERIC); // 数値として昇順に並べ替え
?>
<div id="searchResultGroupIds" data-group-ids="<?= implode(',', $groupIds) ?>" style="display:none;"></div>

<div class="table-responsive">
    <table class="table table-striped">
        <colgroup>
            <col style="min-width: 80px; width: 80px;">  <!-- ID列 -->
            <col style="min-width: 150px; max-width: 200px;">  <!-- グループ名列 -->
            <col style="min-width: 200px; max-width: 300px;">  <!-- 説明列 -->
            <col style="min-width: 150px; max-width: 200px;">  <!-- プロンプト列 -->
            <col style="min-width: 120px; width: 120px;">  <!-- 作成日時列 -->
            <col style="min-width: 120px; width: 120px;">  <!-- 更新日時列 -->
            <col style="min-width: 300px; width: 300px;">  <!-- 操作列 -->
        </colgroup>
        <thead>
            <tr>
                <th>ID</th>
                <th>グループ名</th>
                <th>説明</th>
                <th>プロンプト</th>
                <th class="d-none d-md-table-cell">作成日時</th>
                <th class="d-none d-md-table-cell">更新日時</th>
                <th>操作</th>
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
            <td class="text-truncate" style="max-width: 200px;" title="<?= h($group['prompt_title'] ?? '未設定') ?>">
                <?= h($group['prompt_title'] ?? '未設定') ?>
            </td>
            <td class="d-none d-md-table-cell"><?= h(date('Y/m/d H:i', strtotime($group['created_at']))) ?></td>
            <td class="d-none d-md-table-cell"><?= h(date('Y/m/d H:i', strtotime($group['updated_at']))) ?></td>
            <td>
                <div class="d-flex flex-wrap gap-2">
                    <a href="groups.php?action=edit&id=<?= h($group['id']) ?>"
                       class="btn btn-sm btn-warning">編集</a>
                    <button type="button"
                            class="btn btn-sm btn-warning duplicate-group"
                            data-group-id="<?= h($group['id']) ?>"
                            data-group-name="<?= h($group['name']) ?>">複製</button>
                    <button type="button"
                            class="btn btn-sm btn-warning bulk-task-register"
                            data-group-id="<?= h($group['id']) ?>"
                            data-group-name="<?= h($group['name']) ?>">
                        タスク登録
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-danger force-bulk-task-register"
                            data-group-id="<?= h($group['id']) ?>"
                            data-group-name="<?= h($group['name']) ?>">
                        強制全タスク登録
                    </button>
                    <a href="groups.php?action=delete&id=<?= h($group['id']) ?>"
                       class="btn btn-sm btn-danger"
                       onclick="return confirm('本当に削除しますか？')">削除</a>
                </div>
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

    /* ボタングループのスタイル */
    .gap-2 {
        gap: 0.5rem !important;
    }

    /* スマートフォンでの表示調整 */
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

<!-- 表示件数選択 -->
<div class="row mb-3">
    <div class="col-auto">
        <form class="d-flex align-items-center" method="GET" action="groups.php">
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
            <a class="page-link" href="<?= $pagination['has_previous'] ? 'groups.php?action=list&page=' . ($page - 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="前のページ" <?= !$pagination['has_previous'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
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
                    <a class="page-link" href="groups.php?action=list&page=<?= $p ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?>&per_page=<?= $perPage ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>>
                        <?= $p ?>
                    </a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- 次へボタン -->
        <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $pagination['has_next'] ? 'groups.php?action=list&page=' . ($page + 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="次のページ" <?= !$pagination['has_next'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                <span aria-hidden="true">&raquo;</span>
                <span class="visually-hidden">次のページ</span>
            </a>
        </li>
    </ul>
</nav>

<!-- ページ番号直接入力フォーム -->
<div class="text-center mt-3">
    <form class="d-inline-flex align-items-center" method="GET" action="groups.php">
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

<!-- 既存のJavaScript -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // グループ複製機能
    $('.duplicate-group').click(function() {
        const $button = $(this);
        const groupId = $button.data('group-id');
        const groupName = $button.data('group-name');

        if (confirm(`「${groupName}」グループを複製しますか？\nグループと関連するプレーンナレッジが複製されます。`)) {
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
                        alert('グループを複製しました。');
                        location.reload();
                    } else {
                        let errorMsg = 'エラーが発生しました: ' + response.message;
                        if (response.details) {
                            errorMsg += '\n\n詳細情報:\n';
                            errorMsg += `エラータイプ: ${response.details.error_type}\n`;
                            errorMsg += `エラー発生箇所: ${response.details.error_file}:${response.details.error_line}\n`;
                            if (response.details.group_id) {
                                errorMsg += `対象グループID: ${response.details.group_id}`;
                            }
                        }
                        alert(errorMsg);
                        $button.prop('disabled', false);
                    }
                },
                error: function(xhr) {
                    let errorMessage = '通信エラーが発生しました。';
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

    // タスク登録機能
    $('.bulk-task-register, .force-bulk-task-register').click(function() {
        const $button = $(this);
        const groupId = $button.data('group-id');
        const groupName = $button.data('group-name');
        const isForce = $button.hasClass('force-bulk-task-register');
        
        const confirmMessage = isForce
            ? 'このグループの、すべてのナレッジを削除して、すべてのタスクを登録します。本当に良いですか？'
            : `本当に「${groupName}」グループ内のすべてのプレーンナレッジをタスク登録してよろしいですか？更新対象となるナレッジは一旦削除されます`;

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
                        alert('タスク登録が完了しました。');
                    } else {
                        let errorMsg = 'エラーが発生しました: ' + response.message;
                        if (response.details) {
                            errorMsg += '\n\n詳細情報:\n';
                            errorMsg += `エラータイプ: ${response.details.error_type}\n`;
                            errorMsg += `エラー発生箇所: ${response.details.error_file}:${response.details.error_line}\n`;
                            if (response.details.group_id) {
                                errorMsg += `対象グループID: ${response.details.group_id}`;
                            }
                        }
                        alert(errorMsg);
                        $button.prop('disabled', false);
                    }
                },
                error: function(xhr) {
                    let errorMessage = '通信エラーが発生しました。';
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