<?php
define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/common/config.php';
require_once APP_ROOT . '/common/functions.php';
require_once APP_ROOT . '/common/auth.php';
require_once APP_ROOT . '/common/header.php';

$action = $_GET['action'] ?? 'list';
$searchTerm = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 10;

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
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#matrixModal">
                マトリックス出力
            </button>
        </div>
    </div>

    <!-- マトリックス出力用モーダル -->
    <div class="modal fade" id="matrixModal" tabindex="-1" aria-labelledby="matrixModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="matrixModalLabel">マトリックス出力</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="matrixForm" onsubmit="return false;">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="groupIds" class="form-label">グループID（カンマ区切り）</label>
                            <input type="text" class="form-control" id="groupIds" 
                                   placeholder="例: 8,10,11"
                                   pattern="^[0-9]+(,[0-9]+)*$"
                                   title="カンマ区切りの数字のみ入力可能です">
                            <div class="form-text">カンマ(,)区切りでナレッジマトリックス出力したいグループIDを記載してください</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary" id="exportMatrix">出力</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 既存のテーブルと他のコンテンツ -->
    <?php require_once 'groups_list_content.php'; ?>

    <script>
    $(document).ready(function() {
        function exportMatrix() {
            // 入力値から空白を除去
            const groupIds = $('#groupIds').val().trim().replace(/\s+/g, '');
            if (!groupIds) {
                alert('グループIDを入力してください。');
                return;
            }
            
            // 入力値の検証
            if (!/^[0-9]+(,[0-9]+)*$/.test(groupIds)) {
                alert('無効な入力形式です。カンマ区切りの数字のみ入力してください。');
                return;
            }

            // フォームを作成してPOSTリクエストを送信
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'common/export_matrix.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'group_ids';
            input.value = groupIds;
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
            
            // モーダルを閉じる
            $('#matrixModal').modal('hide');
        }

        // 出力ボタンのクリックイベント
        $('#exportMatrix').click(exportMatrix);

        // Enterキーのイベント
        $('#groupIds').keypress(function(e) {
            if (e.which === 13) { // Enterキー
                e.preventDefault();
                exportMatrix();
            }
        });
    });
    </script>

<!-- 詳細表示画面 -->
<?php elseif ($action === 'view'): ?>
    <?php require_once 'groups_view_content.php'; ?>

<!-- 作成・編集画面 -->
<?php else: ?>
    <?php require_once 'groups_edit_content.php'; ?>
<?php endif; ?>

<?php require_once APP_ROOT . '/common/footer.php'; ?>