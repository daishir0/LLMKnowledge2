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

// MarkItDownClientの読み込み
require_once APP_ROOT . '/common/MarkItDownClient.php';

switch ($action) {
    case 'upload':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
            try {
                // デバッグログの追加
                error_log('Upload started. File info: ' . print_r($_FILES['file'], true), 3, './common/logs.txt');

                // ファイルの一時保存
                $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename($_FILES['file']['name']);
                error_log('Attempting to move file to: ' . $tmpPath, 3, './common/logs.txt');

                if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpPath)) {
                    error_log('Failed to move uploaded file. Upload error code: ' . $_FILES['file']['error'], 3, './common/logs.txt');
                    throw new Exception('Failed to upload file.');
                }

                error_log('File moved successfully. Initializing MarkItDown client...', 3, './common/logs.txt');

                // MarkItDownClientの初期化
                $client = new MarkItDownClient(
                    $api_config['markitdown']['base_url'],
                    $api_config['markitdown']['api_key']
                );

                error_log('Converting file to Markdown...', 3, './common/logs.txt');

                // ファイルをMarkdownに変換
                $result = $client->convertToMarkdown($tmpPath);
                
                error_log('Conversion successful. Result: ' . print_r($result, true), 3, './common/logs.txt');

                // 一時ファイルの削除
                unlink($tmpPath);

                // recordテーブルに保存
                $stmt = $pdo->prepare("
                    INSERT INTO record (
                        title,
                        text,
                        reference,
                        group_id,
                        created_by,
                        created_at,
                        updated_at
                    ) VALUES (
                        :title,
                        :text,
                        :reference,
                        :group_id,
                        :created_by,
                        '$timestamp',
                        '$timestamp'
                    )
                ");
                
                $data = [
                    'title' => $_FILES['file']['name'],
                    'text' => $result['markdown'],
                    'reference' => $_POST['reference'] ?? '',
                    'group_id' => !empty($_POST['group_id']) ? $_POST['group_id'] : null,
                    'created_by' => $_SESSION['user']
                ];
                
                try {
                    $pdo->beginTransaction();
                    
                    $stmt->execute($data);
                    $id = $pdo->lastInsertId();

                    // 履歴の記録
                    $historyData = [
                        'title' => $data['title'],
                        'text' => $data['text'],
                        'reference' => $data['reference']
                    ];
                    logHistory($pdo, 'record', $id, $historyData);

                    $pdo->commit();

                    ob_clean(); // 出力バッファをクリア
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => true,
                        'message' => 'File was successfully uploaded and converted.',
                        'id' => $id
                    ]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }

            } catch (Exception $e) {
                error_log('Error in file upload: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 3, './common/logs.txt');
                ob_clean(); // 出力バッファをクリア
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'An error occurred: ' . $e->getMessage()
                ]);
            }
        } else {
            ob_clean(); // 出力バッファをクリア
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid request.'
            ]);
        }
        exit;

    case 'list':
        $groupId = $_GET['group_id'] ?? '';
        $params = [];
        $whereConditions = ['deleted = 0'];
        
        // グループ条件の追加
        if ($groupId === '') {
            $whereConditions[] = 'group_id IS NULL';
        } elseif ($groupId !== '') {
            $whereConditions[] = 'group_id = :group_id';
            $params[':group_id'] = $groupId;
        }

        // 検索条件の追加
        if ($searchTerm) {
            $whereConditions[] = '(title LIKE :search_title OR text LIKE :search_text)';
            $params[':search_title'] = "%$searchTerm%";
            $params[':search_text'] = "%$searchTerm%";
        }

        // WHERE句の構築
        $whereClause = implode(' AND ', $whereConditions);

        // 総件数の取得
        $countSql = "SELECT COUNT(*) FROM record WHERE $whereClause";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // レコードの取得
        $sql = "SELECT * FROM record
                WHERE $whereClause
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset";
        
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
        $stmt = $pdo->prepare("
            SELECT r.*, k.id as knowledge_id, k.title as knowledge_title, r.group_id,
                   g.id as group_id, g.name as group_name
            FROM record r
            LEFT JOIN knowledge k ON k.parent_id = r.id AND k.parent_type = 'record' AND k.deleted = 0
            LEFT JOIN groups g ON r.group_id = g.id AND g.deleted = 0
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
            
            try {
                $pdo->beginTransaction();

                if ($action === 'create') {
                    $stmt = $pdo->prepare("
                        INSERT INTO record (title, text, reference, group_id, created_by, created_at, updated_at)
                        VALUES (:title, :text, :reference, :group_id, :created_by, '$timestamp', '$timestamp')
                    ");
                    $data = [
                        'title' => $_POST['title'],
                        'text' => $_POST['text'],
                        'reference' => $_POST['reference'],
                        'group_id' => !empty($_POST['group_id']) ? $_POST['group_id'] : null,
                        'created_by' => $_SESSION['user']
                    ];
                    
                    $stmt->execute($data);
                    $id = $pdo->lastInsertId();
                } else {
                    $id = $_GET['id'];
                    $stmt = $pdo->prepare("
                        UPDATE record
                        SET title = :title, text = :text, reference = :reference, group_id = :group_id, updated_at = '$timestamp'
                        WHERE id = :id AND deleted = 0
                    ");
                    $data = [
                        'title' => $_POST['title'],
                        'text' => $_POST['text'],
                        'reference' => $_POST['reference'],
                        'group_id' => !empty($_POST['group_id']) ? $_POST['group_id'] : null,
                        'id' => $id
                    ];
                    $stmt->execute($data);
                }
                
                // 履歴の記録
                $historyData = [
                    'title' => $data['title'],
                    'text' => $data['text'],
                    'reference' => $data['reference']
                ];
                logHistory($pdo, 'record', $id, $historyData);

                $pdo->commit();
                
                // 元のフィルター条件を維持したリダイレクト
                $redirectParams = [];
                if (!empty($_POST['original_search'])) {
                    $redirectParams[] = 'search=' . urlencode($_POST['original_search']);
                }
                if (!empty($_POST['original_group_id'])) {
                    $redirectParams[] = 'group_id=' . urlencode($_POST['original_group_id']);
                }
                
                $redirectUrl = 'record.php?action=list';
                if (!empty($redirectParams)) {
                    $redirectUrl .= '&' . implode('&', $redirectParams);
                }
                
                redirect($redirectUrl);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error in record create/edit: " . $e->getMessage());
                $_SESSION['error_message'] = "エラーが発生しました。";
                
                // エラー時も元のフィルター条件を維持
                $redirectParams = [];
                if (!empty($_POST['original_search'])) {
                    $redirectParams[] = 'search=' . urlencode($_POST['original_search']);
                }
                if (!empty($_POST['original_group_id'])) {
                    $redirectParams[] = 'group_id=' . urlencode($_POST['original_group_id']);
                }
                
                $redirectUrl = 'record.php?action=list';
                if (!empty($redirectParams)) {
                    $redirectUrl .= '&' . implode('&', $redirectParams);
                }
                
                redirect($redirectUrl);
            }
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
                SET deleted = 1, updated_at = '$timestamp'
                WHERE id = :id
            ");
            $stmt->execute([':id' => $_GET['id']]);
        }
        redirect('record.php?action=list');
        break;
}
?>

<!-- List display screen -->
<?php if ($action === 'list'): ?>
    <h1 class="mb-4">Plain Knowledge Management</h1>
    
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
            <form class="d-flex" method="GET" action="record.php">
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
        <div class="col text-end">
            <a href="record.php?action=create" class="btn btn-primary me-2">Create New</a>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadModal">
                Upload
            </button>
        </div>

        <!-- アップロードモーダル -->
        <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="uploadModalLabel">File Upload</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="upload_group" class="form-label">Target Group</label>
                            <select class="form-select" id="upload_group">
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
                                    <option value="<?= h($group['id']) ?>">
                                        <?= h($group['id']) ?>: <?= h($group['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div id="dropZone" class="border rounded p-4 text-center mb-3" style="min-height: 150px;">
                            <div id="uploadArea">
                                <p class="mb-2">Drop files here to start conversion process</p>
                                <p class="text-muted small">or</p>
                                <input type="file" id="fileInput" multiple class="d-none">
                                <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('fileInput').click()">
                                    Select Files
                                </button>
                            </div>
                            <div id="uploadProgress" class="d-none w-100">
                                <h6 class="mb-3">Upload Status</h6>
                                <div class="progress mb-2">
                                    <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div id="uploadStatus" class="small text-muted"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="upload_reference" class="form-label">Reference</label>
                            <input type="text" class="form-control" id="upload_reference">
                        </div>
                        <div id="uploadProgress" class="d-none">
                            <h6>Upload Status</h6>
                            <div class="progress mb-2">
                                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                            </div>
                            <div id="uploadStatus" class="small text-muted"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-warning" id="taskRegisterBtn" disabled>Register Task</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const dropZone = document.getElementById('dropZone');
            const uploadArea = document.getElementById('uploadArea');
            const fileInput = document.getElementById('fileInput');
            const taskRegisterBtn = document.getElementById('taskRegisterBtn');
            const uploadProgress = document.getElementById('uploadProgress');
            const progressBar = uploadProgress.querySelector('.progress-bar');
            const uploadStatus = document.getElementById('uploadStatus');
            const uploadGroup = document.getElementById('upload_group');
            let isUploading = false;
            let hasSuccessfulUpload = false;

            // グループ選択の監視
            uploadGroup.addEventListener('change', updateTaskRegisterButton);

            function updateTaskRegisterButton() {
                const groupSelected = uploadGroup.value !== '';
                taskRegisterBtn.disabled = !hasSuccessfulUpload || !groupSelected;
            }

            // ドラッグ&ドロップイベントの設定
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('border-primary');
            });

            dropZone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                dropZone.classList.remove('border-primary');
            });

            dropZone.addEventListener('drop', async (e) => {
                e.preventDefault();
                dropZone.classList.remove('border-primary');
                
                if (isUploading) return;
                
                const files = Array.from(e.dataTransfer.files);
                if (files.length > 0) {
                    await processFiles(files);
                }
            });

            fileInput.addEventListener('change', async (e) => {
                if (isUploading) return;
                
                const files = Array.from(e.target.files);
                if (files.length > 0) {
                    await processFiles(files);
                }
            });

            async function processFiles(files) {
                isUploading = true;
                uploadArea.classList.add('d-none');
                uploadProgress.classList.remove('d-none');
                let successCount = 0;
                let failCount = 0;
                let errors = [];

                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    const formData = new FormData();
                    formData.append('file', file);
                    formData.append('reference', document.getElementById('upload_reference').value);
                    formData.append('group_id', document.getElementById('upload_group').value);

                    progressBar.style.width = `${(i / files.length) * 100}%`;
                    uploadStatus.innerHTML = `
                        <div class="mb-2">Processing: ${file.name} (${i + 1}/${files.length})</div>
                        ${errors.map(err => `<div class="text-danger small">${err}</div>`).join('')}
                    `;

                    try {
                        const response = await fetch('record.php?action=upload', {
                            method: 'POST',
                            body: formData
                        });

                        let result;
                        try {
                            result = await response.json();
                        } catch (e) {
                            throw new Error('Invalid response from server');
                        }

                        if (response.ok && result.success) {
                            successCount++;
                        } else {
                            failCount++;
                            errors.push(`${file.name}: ${result.message || 'An error occurred'}`);
                        }
                    } catch (error) {
                        failCount++;
                        errors.push(`${file.name}: ${error.message || 'A communication error occurred'}`);
                        console.error(`Error uploading ${file.name}:`, error);
                    }
                }

                progressBar.style.width = '100%';
                
                // 結果表示の構築
                let statusHtml = `<div class="mb-2">Completed: Success ${successCount} items, Failed ${failCount} items</div>`;
                if (errors.length > 0) {
                    statusHtml += '<div class="mt-2"><strong>Error Details:</strong></div>';
                    statusHtml += errors.map(err => `<div class="text-danger small">${err}</div>`).join('');
                }
                uploadStatus.innerHTML = statusHtml;
                
                // 成功したアップロードがある場合はフラグを立てる（エラーの有無に関わらず）
                if (successCount > 0) {
                    hasSuccessfulUpload = true;
                    updateTaskRegisterButton();
                }
                
                // 処理完了後の状態設定
                uploadArea.classList.remove('d-none');
                progressBar.style.width = '0%';
                isUploading = false;
                fileInput.value = '';
            }

            taskRegisterBtn.addEventListener('click', async function() {
                const groupId = document.getElementById('upload_group').value;
                if (!groupId) {
                    alert('Group specification is required for task registration.');
                    return;
                }

                taskRegisterBtn.disabled = true;
                
                try {
                    const response = await fetch('common/api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            'action': 'bulk_task_register_by_group',
                            'group_id': groupId
                        })
                    });
                    
                    const result = await response.json();
                    if (result.success) {
                        alert(result.message);
                        location.reload();
                    } else {
                        alert('An error occurred: ' + result.message);
                        taskRegisterBtn.disabled = false;
                    }
                } catch (error) {
                    alert('A communication error occurred.');
                    taskRegisterBtn.disabled = false;
                }
            });
        });
        </script>
    </div>

    <div class="table-responsive">
        <table class="table table-striped">
            <colgroup>
                <col style="min-width: 80px; width: 80px;">  <!-- ID column -->
                <col style="min-width: 200px; max-width: 400px;">  <!-- Title column -->
                <col style="min-width: 120px; width: 120px;">  <!-- Created date column -->
                <col style="min-width: 120px; width: 120px;">  <!-- Updated date column -->
                <col style="min-width: 160px; width: 160px;">  <!-- Actions column -->
            </colgroup>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th class="d-none d-md-table-cell">Created Date</th>
                    <th class="d-none d-md-table-cell">Updated Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                <tr>
                    <td><?= h($record['id']) ?></td>
                    <td class="text-truncate" style="max-width: 400px;" title="<?= h($record['title']) ?>">
                        <a href="record.php?action=view&id=<?= h($record['id']) ?>" class="d-block text-truncate">
                            <?= h($record['title']) ?>
                        </a>
                    </td>
                    <td class="d-none d-md-table-cell"><?= h(date('Y/m/d H:i', strtotime($record['created_at']))) ?></td>
                    <td class="d-none d-md-table-cell"><?= h(date('Y/m/d H:i', strtotime($record['updated_at']))) ?></td>
                    <td>
                        <a href="record.php?action=edit&id=<?= h($record['id']) ?>&search=<?= h($searchTerm) ?>&group_id=<?= h($groupId) ?>"
                           class="btn btn-sm btn-warning me-2">Edit</a>
                        <a href="record.php?action=delete&id=<?= h($record['id']) ?>"
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

        /* Button display adjustment for smartphones */
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

    <?php if (isset($_GET['group_id']) && $_GET['group_id'] !== ''): ?>
    <div class="text-center mb-4">
        <button id="exportGroupButton" class="btn btn-success" data-group-id="<?= h($_GET['group_id']) ?>">
            Export All Plain Knowledge in This Group
        </button>
    </div>
    <?php endif; ?>

    <!-- Display count selection -->
    <div class="row mb-3">
        <div class="col-auto">
            <form class="d-flex align-items-center" method="GET" action="record.php">
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
                <a class="page-link" href="<?= $pagination['has_previous'] ? 'record.php?action=list&page=' . ($page - 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . (isset($_GET['group_id']) && $_GET['group_id'] !== '' ? '&group_id=' . h($_GET['group_id']) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="Previous page" <?= !$pagination['has_previous'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
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
                        <a class="page-link" href="record.php?action=list&page=<?= $p ?><?= $searchTerm ? '&search=' . h($searchTerm) : '' ?><?= isset($_GET['group_id']) && $_GET['group_id'] !== '' ? '&group_id=' . h($_GET['group_id']) : '' ?>&per_page=<?= $perPage ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>>
                            <?= $p ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- Next button -->
            <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $pagination['has_next'] ? 'record.php?action=list&page=' . ($page + 1) . ($searchTerm ? '&search=' . h($searchTerm) : '') . (isset($_GET['group_id']) && $_GET['group_id'] !== '' ? '&group_id=' . h($_GET['group_id']) : '') . '&per_page=' . $perPage : '#' ?>" aria-label="Next page" <?= !$pagination['has_next'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                    <span aria-hidden="true">&raquo;</span>
                    <span class="visually-hidden">Next page</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Direct page number input form -->
    <div class="text-center mt-3">
        <form class="d-inline-flex align-items-center" method="GET" action="record.php">
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        // Group export function
        $('#exportGroupButton').click(function(e) {
            e.preventDefault();
            const groupId = $(this).data('group-id');
            
            fetch(`common/export_group_plain_knowledge.php?group_id=${groupId}`)
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(data => {
                            throw new Error(data.message || 'An unknown error occurred');
                        });
                    }
                    return response.blob();
                })
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = url;
                    a.download = `plain_group_${groupId}.txt`;
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
    <h1 class="mb-4">Plain Knowledge Details</h1>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger">
            <?= h($_SESSION['error_message']) ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
    
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title"><?= h($record['title']) ?></h5>
            <p class="card-text"><?= nl2br(h($record['text'])) ?></p>
            <h6 class="mt-4">Group</h6>
            <p class="card-text">
                <?php if ($record['group_id']): ?>
                    <?= h($record['group_id']) ?>: <?= h($record['group_name']) ?>
                <?php else: ?>
                    (No Group)
                <?php endif; ?>
            </p>
            <h6 class="mt-4">Reference</h6>
            <p class="card-text"><?= !empty($record['reference']) ? nl2br(h($record['reference'])) : '(Not Registered)' ?></p>
            
            <!-- Knowledge化タスク作成フォーム -->
            <div class="mt-4 border-top pt-4">
                <h6>Create Knowledge Task</h6>
                <form id="taskForm" class="mt-3">
                    <input type="hidden" name="action" value="create_task">
                    <input type="hidden" name="source_type" value="record">
                    <input type="hidden" name="source_id" value="<?= h($record['id']) ?>">
                    <input type="hidden" name="group_id" value="<?= h($record['group_id']) ?>">
                    <div class="mb-3">
                        <label for="prompt_id" class="form-label">Used Prompt</label>
                        <select class="form-control" id="prompt_id" name="prompt_id" required>
                            <option value="">Please select</option>
                            <?php
                            $stmt = $pdo->query("
                                SELECT id, title, content
                                FROM prompts
                                WHERE deleted = 0
                                AND category = 'plain_to_knowledge'
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
            
            <?php if (isset($record['knowledge_id'])): ?>
            <h6 class="mt-4">Related Knowledge</h6>
            <ul>
                <li><a href="knowledge.php?action=view&id=<?= h($record['knowledge_id']) ?>">
                    <?= h($record['knowledge_title']) ?>
                </a></li>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- JavaScript for prompt preview -->
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
                    }
                },
                error: function() {
                    alert('A communication error occurred.');
                    $button.prop('disabled', false);
                }
            });
        });

        // エクスポート機能の追加
        $('#exportButton').click(function(e) {
            e.preventDefault();
            const recordId = $(this).data('record-id');
            
            fetch(`common/export_plain_knowledge.php?id=${recordId}`)
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(data => {
                            throw new Error(data.message || 'An unknown error occurred');
                        });
                    }
                    return response.blob();
                })
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = url;
                    a.download = `${recordId}.txt`;
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
                    
                    <!-- History content modal -->
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
                                    <h6>Content</h6>
                                    <pre class="border p-3 bg-light"><?= h($entry['text']) ?></pre>
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
        <a href="record.php?action=list" class="btn btn-secondary">Back</a>
        <a href="record.php?action=edit&id=<?= h($record['id']) ?>"
           class="btn btn-warning">Edit</a>
        <button id="exportButton" class="btn btn-success" data-record-id="<?= h($record['id']) ?>">Export</button>
    </div>

<!-- タスク作成完了画面 -->
<?php if (isset($taskCreated) && $taskCreated): ?>
    <h1 class="mb-4">Task Creation Completed</h1>
    
    <div class="alert alert-success">
        <h4 class="alert-heading">Task was successfully created!</h4>
        <p>Knowledge conversion task was successfully created. You can check the progress from the task list.</p>
    </div>
    
    <div class="mt-4">
        <a href="tasks.php" class="btn btn-primary">Go to Task List</a>
        <a href="record.php?action=view&id=<?= h($sourceId) ?>" class="btn btn-secondary">Back to Original Article</a>
    </div>
<?php endif; ?>

<!-- 作成・編集画面 -->
<?php else: ?>
    <h1 class="mb-4">
        <?= $action === 'create' ? 'Create Plain Knowledge' : 'Edit Plain Knowledge' ?>
    </h1>
    
    <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="original_search" value="<?= h($_GET['search'] ?? '') ?>">
        <input type="hidden" name="original_group_id" value="<?= h($_GET['group_id'] ?? '') ?>">
        <div class="mb-3">
            <label for="title" class="form-label">Title</label>
            <input type="text" class="form-control" id="title" name="title" 
                   value="<?= isset($record) ? h($record['title']) : '' ?>" required>
        </div>
        
        <div class="mb-3">
            <label for="text" class="form-label">Content</label>
            <textarea class="form-control" id="text" name="text" rows="10" required><?= isset($record) ? h($record['text']) : '' ?></textarea>
        </div>
        
        <div class="mb-3">
            <label for="group_id" class="form-label">Group</label>
            <select name="group_id" id="group_id" class="form-select">
                <option value="">Please select</option>
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
                        <?= (isset($record) && $record['group_id'] == $group['id']) ? 'selected' : '' ?>>
                        <?= h($group['id']) ?>: <?= h($group['name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="reference" class="form-label">Reference</label>
            <input type="text" class="form-control" id="reference" name="reference"
                    value="<?= isset($record) ? h($record['reference']) : '' ?>">
        </div>
        
        <button type="submit" class="btn btn-primary">Save</button>
        <a href="record.php?action=list" class="btn btn-secondary">Cancel</a>
    </form>
<?php endif; ?>

<?php require_once APP_ROOT . '/common/footer.php'; ?>
