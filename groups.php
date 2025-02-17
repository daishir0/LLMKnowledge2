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

// プロンプト一覧の取得（plain_to_knowledge限定）
$prompts_stmt = $pdo->query("
    SELECT id, title, content
    FROM prompts
    WHERE deleted = 0
    AND category = 'plain_to_knowledge'
    ORDER BY id ASC
");
$prompts = $prompts_stmt->fetchAll(PDO::FETCH_ASSOC);

switch ($action) {
    case 'list':
        // 検索処理
        if ($searchTerm) {
            $stmt = $pdo->prepare("
                SELECT g.*, p.title as prompt_title FROM groups g
                LEFT JOIN prompts p ON g.prompt_id = p.id
                WHERE g.deleted = 0 
                AND (g.name LIKE :search OR g.detail LIKE :search OR p.title LIKE :search)
                ORDER BY g.created_at DESC
            ");
            $stmt->execute([':search' => "%$searchTerm%"]);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = count($groups);
        } else {
            // 総件数の取得
            $stmt = $pdo->query("SELECT COUNT(*) FROM groups WHERE deleted = 0");
            $total = $stmt->fetchColumn();
            
            // グループ一覧の取得
            $stmt = $pdo->prepare("
                SELECT g.*, p.title as prompt_title FROM groups g
                LEFT JOIN prompts p ON g.prompt_id = p.id
                WHERE g.deleted = 0
                ORDER BY g.created_at DESC 
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
            $stmt->execute();
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $pagination = getPagination($total, $perPage, $page);
        break;

    case 'view':
        $id = $_GET['id'] ?? 0;
        
        // グループ情報の取得
        $stmt = $pdo->prepare("
            SELECT g.*,
                   p.title as prompt_title,
                   p.content as prompt_content,
                   COUNT(DISTINCT r.id) as record_count,
                   COUNT(DISTINCT k.id) as knowledge_count
            FROM groups g
            LEFT JOIN prompts p ON g.prompt_id = p.id
            LEFT JOIN record r ON r.group_id = g.id AND r.deleted = 0
            LEFT JOIN knowledge k ON k.group_id = g.id AND k.deleted = 0
            WHERE g.id = :id AND g.deleted = 0
            GROUP BY g.id
        ");
        $stmt->execute([':id' => $id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        break;

    case 'create':
    case 'edit':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'name' => $_POST['name'],
                'detail' => $_POST['detail'],
                'prompt_id' => $_POST['prompt_id'] ?? null
            ];
            
            try {
                $pdo->beginTransaction();

                if ($action === 'create') {
                    $stmt = $pdo->prepare("
                        INSERT INTO groups (name, detail, prompt_id, created_at, updated_at)
                        VALUES (:name, :detail, :prompt_id, '$timestamp', '$timestamp')
                    ");
                } else {
                    $id = $_GET['id'];
                    $stmt = $pdo->prepare("
                        UPDATE groups 
                        SET name = :name, 
                            detail = :detail, 
                            prompt_id = :prompt_id, 
                            updated_at = '$timestamp'
                        WHERE id = :id AND deleted = 0
                    ");
                    $data['id'] = $id;
                }

                $stmt->execute($data);
                $pdo->commit();
                
                redirect('groups.php?action=list');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error in group create/edit: " . $e->getMessage());
                $_SESSION['error_message'] = "エラーが発生しました。";
                redirect('groups.php?action=list');
            }
        }
        
        if ($action === 'edit') {
            $stmt = $pdo->prepare("
                SELECT g.*, p.content as prompt_content 
                FROM groups g
                LEFT JOIN prompts p ON g.prompt_id = p.id
                WHERE g.id = :id AND g.deleted = 0
            ");
            $stmt->execute([':id' => $_GET['id']]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        break;

    case 'delete':
        if (isset($_GET['id'])) {
            try {
                $pdo->beginTransaction();

                // グループを論理削除
                $stmt = $pdo->prepare("
                    UPDATE groups
                    SET deleted = 1, updated_at = '$timestamp'
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $_GET['id']]);

                // 紐づいているプレーンナレッジを論理削除
                $stmt = $pdo->prepare("
                    UPDATE record
                    SET deleted = 1, updated_at = '$timestamp'
                    WHERE group_id = :group_id
                ");
                $stmt->execute([':group_id' => $_GET['id']]);

                // 紐づいているナレッジを論理削除
                $stmt = $pdo->prepare("
                    UPDATE knowledge
                    SET deleted = 1, updated_at = '$timestamp'
                    WHERE group_id = :group_id
                ");
                $stmt->execute([':group_id' => $_GET['id']]);

                $pdo->commit();
                $_SESSION['success_message'] = 'グループと関連するデータを削除しました。';
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error in group delete: " . $e->getMessage());
                $_SESSION['error_message'] = 'エラーが発生しました。';
            }
        }
        redirect('groups.php?action=list');
        break;
}
?>

<!-- リスト表示画面 -->
<?php if ($action === 'list'): ?>
    <h1 class="mb-4">グループ管理</h1>
    
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= h($_SESSION['success_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= h($_SESSION['error_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
    
    <div class="row mb-4">
        <div class="col">
            <form class="d-flex" method="GET" action="groups.php">
                <input type="hidden" name="action" value="list">
                <input type="search" name="search" class="form-control me-2" 
                       value="<?= h($searchTerm) ?>" placeholder="検索...">
                <button class="btn btn-outline-primary" type="submit">検索</button>
            </form>
        </div>
        <div class="col text-end">
            <a href="groups.php?action=create" class="btn btn-primary">新規作成</a>
        </div>
    </div>

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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        $('.bulk-task-register').click(function() {
            const $button = $(this);
            const groupId = $button.data('group-id');
            const groupName = $button.data('group-name');

            if (confirm(`本当に「${groupName}」グループ内のすべてのプレーンナレッジをタスク登録してよろしいですか？更新対象となるナレッジは一旦削除されます`)) {
                $button.prop('disabled', true);

                $.ajax({
                    url: 'common/api.php',
                    method: 'POST',
                    data: {
                        action: 'bulk_task_register_by_group',
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

        $('.force-bulk-task-register').click(function() {
            const $button = $(this);
            const groupId = $button.data('group-id');
            const groupName = $button.data('group-name');

            if (confirm(`このグループの、すべてのナレッジを削除して、すべてのタスクを登録します。本当に良いですか？`)) {
                $button.prop('disabled', true);

                $.ajax({
                    url: 'common/api.php',
                    method: 'POST',
                    data: {
                        action: 'force_bulk_task_register_by_group',
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

<!-- 詳細表示画面 -->
<?php elseif ($action === 'view'): ?>
    <h1 class="mb-4">グループ詳細</h1>
    
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title"><?= h($group['name']) ?></h5>
            <p class="card-text"><?= nl2br(h($group['detail'])) ?></p>
            
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <h6 class="card-title">プレーンナレッジ数</h6>
                            <p class="card-text display-6"><?= h($group['record_count']) ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <h6 class="card-title">ナレッジ数</h6>
                            <p class="card-text display-6"><?= h($group['knowledge_count']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mt-4 border-top pt-4">
                <h6>プロンプト情報</h6>
                <?php if (!empty($group['prompt_title'])): ?>
                    <p class="card-text">
                        <strong>プロンプト名:</strong> <?= h($group['prompt_title']) ?>
                    </p>
                    <?php if (!empty($group['prompt_content'])): ?>
                    <div class="mt-2">
                        <label class="form-label">プロンプト内容</label>
                        <pre class="border p-3 bg-light"><?= h($group['prompt_content']) ?></pre>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="card-text text-muted">登録されたプロンプトは無し</p>
                <?php endif; ?>
            </div>

            <div class="mt-4">
                <h6>作成情報</h6>
                <p>
                    作成日時: <?= h($group['created_at']) ?><br>
                    更新日時: <?= h($group['updated_at']) ?>
                </p>
            </div>
        </div>
    </div>

    <!-- 紐づいているプレーンナレッジの一覧 -->
    <div class="mt-5">
        <h3>このグループに紐づいているプレーンナレッジ</h3>
        <?php
        // 全体の件数を取得
        $total_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM record
            WHERE group_id = :group_id  
            AND deleted = 0
        ");
        $total_stmt->execute([':group_id' => $_GET['id']]);
        $total_count = $total_stmt->fetchColumn();
        ?>
        <p>全<?= h($total_count) ?>件中、最新3件を表示しています。全件は<a href="record.php?action=list&search=&group_id=<?= $_GET['id'] ?>">こちら</a>。</p>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>タイトル</th>
                        <th>Reference</th>
                        <th>更新日時</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmt = $pdo->prepare("
                        SELECT * FROM record
                        WHERE group_id = :group_id
                        AND deleted = 0
                        ORDER BY updated_at DESC
                        LIMIT 3
                    ");
                    $stmt->execute([':group_id' => $_GET['id']]);
                    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($records as $record):
                    ?>
                    <tr>
                        <td><?= h($record['id']) ?></td>
                        <td><a href="record.php?action=view&id=<?= h($record['id']) ?>"><?= h($record['title']) ?></a></td>
                        <td><?= h($record['reference']) ?></td>
                        <td><?= h(date('Y/m/d H:i', strtotime($record['updated_at']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

       <!-- 紐づいているナレッジの一覧 -->
   <div class="mt-5">
       <h3>このグループに紐づいているナレッジ</h3>
       <?php
       // 全体の件数を取得
       $total_knowledge_stmt = $pdo->prepare("
           SELECT COUNT(*) FROM knowledge
           WHERE group_id = :group_id
           AND deleted = 0
       ");
       $total_knowledge_stmt->execute([':group_id' => $_GET['id']]);
       $total_knowledge_count = $total_knowledge_stmt->fetchColumn();
       ?>
       <p>全<?= h($total_knowledge_count) ?>件中、最新3件を表示しています。全件は<a href="knowledge.php?action=list&search=&group_id=<?= $_GET['id'] ?>">こちら</a>。</p>
       <div class="table-responsive">
           <table class="table">
               <thead>
                   <tr>
                       <th>ID</th>
                       <th>タイトル</th>
                       <th>Reference</th>
                       <th>更新日時</th>
                   </tr>
               </thead>
               <tbody>
                   <?php
                   $knowledge_stmt = $pdo->prepare("
                       SELECT * FROM knowledge
                       WHERE group_id = :group_id
                       AND deleted = 0
                       ORDER BY updated_at DESC
                       LIMIT 3
                   ");
                   $knowledge_stmt->execute([':group_id' => $_GET['id']]);
                   $knowledges = $knowledge_stmt->fetchAll(PDO::FETCH_ASSOC);
                   foreach ($knowledges as $knowledge):
                   ?>
                   <tr>
                       <td><?= h($knowledge['id']) ?></td>
                       <td><a href="knowledge.php?action=view&id=<?= h($knowledge['id']) ?>"><?= h($knowledge['title']) ?></a></td>
                       <td><?= h($knowledge['reference']) ?></td>
                       <td><?= h(date('Y/m/d H:i', strtotime($knowledge['updated_at']))) ?></td>
                   </tr>
                   <?php endforeach; ?>
               </tbody>
           </table>
       </div>
   </div>

   <!-- データコレクター部分 -->
   <div class="mt-5 mb-5">
       <h3>データコレクター</h3>
       <div class="card">
           <div class="card-body">
               <p class="card-text">このグループの、プレーンナレッジを収集するプログラム（データコレクター）と設定ファイルです。同じディレクトリに置いて実行してください。</p>
 
               <a href="client/dist/data_collector.exe" 
                  class="btn btn-primary"
                  download>
                   プログラムをダウンロード
               </a>
 
               <a href="common/export_client_yaml.php?group_id=<?= h($id) ?>" 
                  class="btn btn-primary">
                   設定ファイルをダウンロード
               </a>
 
            </div>
       </div>
    </div>
<!-- 作成・編集画面 -->
<?php else: ?>
    <h1 class="mb-4">
        <?= $action === 'create' ? 'グループ作成' : 'グループ編集／プレーンナレッジ一括追加' ?>
    </h1>
    
    <form method="POST" class="needs-validation" novalidate>
        <div class="mb-3">
            <label for="name" class="form-label">グループ名</label>
            <input type="text" class="form-control" id="name" name="name" 
                   value="<?= isset($group) ? h($group['name']) : '' ?>" required>
        </div>
        
        <div class="mb-3">
            <label for="detail" class="form-label">説明</label>
            <textarea class="form-control" id="detail" name="detail" rows="5"><?= isset($group) ? h($group['detail']) : '' ?></textarea>
        </div>
        
        <div class="mb-3">
            <label for="prompt_id" class="form-label">プロンプト</label>
            <select class="form-control" id="prompt_id" name="prompt_id">
                <option value="">プロンプトを選択（オプション）</option>
                <?php foreach ($prompts as $prompt): ?>
                <option value="<?= h($prompt['id']) ?>" 
                    data-content="<?= h($prompt['content']) ?>"
                    <?= (isset($group) && $group['prompt_id'] == $prompt['id']) ? 'selected' : '' ?>>
                    <?= h($prompt['id']) ?>: <?= h($prompt['title']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">プロンプト内容プレビュー</label>
            <pre class="border p-3 bg-light" id="prompt_preview"><?= isset($group['prompt_content']) ? h($group['prompt_content']) : '' ?></pre>
        </div>
        
        <button type="submit" class="btn btn-primary">保存</button>
        <a href="groups.php?action=list" class="btn btn-secondary">キャンセル</a>
    </form>
    
    <?php if ($action === 'edit'): ?>
        <!-- jQuery読み込み -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <hr>

        <!-- プレーンナレッジの追加 -->
        <div class="mt-5">
            <h3>プレーンナレッジ一括追加機能</h3>
            <form id="addRecordsForm">
                <div class="form-group">
                    <textarea class="form-control" id="newRecords" name="newRecords" rows="10"
                              placeholder="URLや共有フォルダのファイルパスをここに入力し「追加する」ボタンを押下してください。一括登録できます。（1行に1つ）"></textarea>
                </div>
                <button type="button" id="addRecords" class="btn btn-success mt-3">追加する</button>
            </form>
        </div>

        <!-- JavaScript -->
        <script>
        $(document).ready(function() {
            $('#prompt_id').change(function() {
                const selectedOption = $(this).find('option:selected');
                const promptContent = selectedOption.data('content');
                $('#prompt_preview').text(promptContent || '');
            });

            // プレーンナレッジ追加
            $('#addRecords').click(function() {
                const newRecords = $('#newRecords').val().trim();
                if (!newRecords) {
                    alert('追加するデータを入力してください。');
                    return;
                }

                $.ajax({
                    url: 'common/api.php',
                    method: 'POST',
                    data: {
                        action: 'add_records',
                        group_id: <?= h($_GET['id']) ?>,
                        newdata: newRecords
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('エラーが発生しました: ' + response.message);
                        }
                    },
                    error: function() {
                        alert('通信エラーが発生しました。');
                    }
                });
            });
        });
        </script>
    <?php endif; ?>

    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        $('#prompt_id').change(function() {
            const selectedOption = $(this).find('option:selected');
            const promptContent = selectedOption.data('content');
            $('#prompt_preview').text(promptContent || '');
        });
    });
    </script>
<?php endif; ?>

<?php require_once APP_ROOT . '/common/footer.php'; ?>