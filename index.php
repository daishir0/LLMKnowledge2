<?php
require_once __DIR__ . '/common/config.php';
require_once __DIR__ . '/common/functions.php';
require_once __DIR__ . '/common/auth.php';
require_once __DIR__ . '/common/header.php';

// 各テーブルの総数を取得
$stmt = $pdo->query("SELECT COUNT(*) FROM record WHERE deleted = 0");
$plainCount = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM knowledge WHERE deleted = 0");
$knowledgeCount = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM prompts WHERE deleted = 0");
$promptCount = $stmt->fetchColumn();

// tasksテーブルの存在チェック
$stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tasks'");
$tasksTableExists = $stmt->fetchColumn();

if ($tasksTableExists) {
    // タスクの総数とステータス別の数を取得
    $stmt = $pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted = 0");
    $taskCount = $stmt->fetchColumn();
    
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM tasks 
        WHERE deleted = 0 
        GROUP BY status
    ");
    $taskStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
    $taskCount = 0;
    $taskStats = [];
}
?>

<div class="row mb-4">
    <div class="col">
        <h1><?= SYSTEM_NAME ?> ダッシュボード</h1>
        <p class="text-muted">ナレッジ管理システムへようこそ</p>
    </div>
</div>

<!-- 統計情報 -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h5 class="card-title">プレーンナレッジ</h5>
                <a href="record.php?action=list" class="text-white text-decoration-none">
                    <p class="card-text display-4"><?= h($plainCount) ?></p>
                </a>
                <p class="card-text">登録件数</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h5 class="card-title">ナレッジ</h5>
                <a href="knowledge.php?action=list" class="text-white text-decoration-none">
                    <p class="card-text display-4"><?= h($knowledgeCount) ?></p>
                </a>
                <p class="card-text">登録件数</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h5 class="card-title">プロンプト</h5>
                <a href="prompts.php?action=list" class="text-white text-decoration-none">
                    <p class="card-text display-4"><?= h($promptCount) ?></p>
                </a>
                <p class="card-text">登録件数</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h5 class="card-title">残タスク</h5>
                <a href="tasks.php" class="text-white text-decoration-none">
                    <p class="card-text display-4"><?= h($taskStats['pending'] ?? 0) ?></p>
                </a>
                <p class="card-text">未処理タスク数</p>
            </div>
        </div>
    </div>
</div>


<!-- メインメニュー -->
<div class="row mb-4">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">プレーンナレッジ管理</h5>
                <p class="card-text">元となるナレッジの管理を行います。</p>
                <div>
                    <a href="record.php?action=list" class="btn btn-primary me-2">一覧表示</a>
                    <a href="record.php?action=create" class="btn btn-outline-primary me-2">新規作成</a>
                    <a href="import.php" class="btn btn-outline-secondary me-2">インポート</a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">ナレッジ管理</h5>
                <p class="card-text">生成されたナレッジの管理を行います。</p>
                <div>
                    <a href="knowledge.php?action=list" class="btn btn-success me-2">一覧表示</a>
                    <a href="knowledge.php?action=create" class="btn btn-outline-success me-2">新規作成</a>
                    <a href="import.php" class="btn btn-outline-secondary me-2">インポート</a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">プロンプト管理</h5>
                <p class="card-text">ナレッジ生成用のプロンプトを管理します。</p>
                <div>
                    <a href="prompts.php?action=list" class="btn btn-info me-2">一覧表示</a>
                    <a href="prompts.php?action=create" class="btn btn-outline-info me-2">新規作成</a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">履歴管理</h5>
                <p class="card-text">各種データの変更履歴を確認できます。</p>
                <div>
                    <div class="btn-group">
                        <a href="export.php?type=history&target=record" class="btn btn-outline-secondary">プレーンナレッジ履歴</a>
                        <a href="export.php?type=history&target=knowledge" class="btn btn-outline-secondary">ナレッジ履歴</a>
                        <a href="export.php?type=history&target=prompt" class="btn btn-outline-secondary">プロンプト履歴</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 最近の更新 -->
<div class="row">
    
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">最近更新されたプレーンナレッジ</h5>
                <div class="list-group list-group-flush">
                    <?php
                    $stmt = $pdo->query("
                        SELECT id, title, updated_at 
                        FROM record 
                        WHERE deleted = 0 
                        ORDER BY updated_at DESC 
                        LIMIT 5
                    ");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): 
                    ?>
                        <a href="record.php?action=view&id=<?= h($row['id']) ?>" 
                           class="list-group-item list-group-item-action">
                            <?= h($row['title']) ?>
                            <small class="text-muted float-end">
                                <?= h(date('Y/m/d H:i', strtotime($row['updated_at']))) ?>
                            </small>
                        </a>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">最近更新されたナレッジ</h5>
                <div class="list-group list-group-flush">
                    <?php
                    $stmt = $pdo->query("
                        SELECT id, title, updated_at 
                        FROM knowledge 
                        WHERE deleted = 0 
                        ORDER BY updated_at DESC 
                        LIMIT 5
                    ");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): 
                    ?>
                        <a href="knowledge.php?action=view&id=<?= h($row['id']) ?>" 
                           class="list-group-item list-group-item-action">
                            <?= h($row['title']) ?>
                            <small class="text-muted float-end">
                                <?= h(date('Y/m/d H:i', strtotime($row['updated_at']))) ?>
                            </small>
                        </a>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>

</div>


<!-- タスク統計 -->
<div class="row mb-4">

    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">タスクステータス</h5>
                <div class="row text-center">
                    <div class="col">
                        <div class="badge bg-warning mb-2">Pending</div>
                        <p class="h4"><?= h($taskStats['pending'] ?? 0) ?></p>
                    </div>
                    <div class="col">
                        <div class="badge bg-primary mb-2">Processing</div>
                        <p class="h4"><?= h($taskStats['processing'] ?? 0) ?></p>
                    </div>
                    <div class="col">
                        <div class="badge bg-success mb-2">Completed</div>
                        <p class="h4"><?= h($taskStats['completed'] ?? 0) ?></p>
                    </div>
                    <div class="col">
                        <div class="badge bg-danger mb-2">Failed</div>
                        <p class="h4"><?= h($taskStats['failed'] ?? 0) ?></p>
                    </div>
                    <div class="col">
                        <div class="badge bg-secondary mb-2">Cancelled</div>
                        <p class="h4"><?= h($taskStats['cancelled'] ?? 0) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Knowledge化タスク</h5>
                <p class="card-text display-4"><?= h($taskCount) ?></p>
                <p class="card-text">総タスク数</p>
                <div class="mt-3">
                    <a href="tasks.php" class="btn btn-primary">タスク管理</a>
                </div>
            </div>
        </div>
    </div> -->

</div>

<?php require_once __DIR__ . '/common/footer.php'; ?>

<script>
// 残タスク数を更新する関数
function updatePendingTasksCount() {
    // 現在の残タスク数を取得
    const pendingTasksElement = document.querySelector('.bg-warning .card-text.display-4');
    const currentCount = parseInt(pendingTasksElement.textContent) || 0;
    
    // 残タスク数が0の場合は更新しない
    if (currentCount === 0) {
        return;
    }
    
    // APIを呼び出して最新の残タスク数を取得
    fetch('common/api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_pending_tasks_count',
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // 残タスク数を更新
            pendingTasksElement.textContent = data.count;
            
            // タスク統計の表示も更新
            const taskStatsPendingElement = document.querySelector('.badge.bg-warning.mb-2 + .h4');
            if (taskStatsPendingElement) {
                taskStatsPendingElement.textContent = data.count;
            }
            
            // グローバルメニューの残タスク表示も更新
            const pendingTasksCountElement = document.getElementById('pending-tasks-count');
            if (pendingTasksCountElement) {
                pendingTasksCountElement.textContent = `残タスク：${data.count > 0 ? data.count + '件' : '無し'}`;
            }
            
            // 前の状態が0でなく、新しい状態が0の場合にアラートを表示
            if (currentCount > 0 && data.count === 0) {
                alert('タスクが完了しました');
            }
            
            // 残タスク数が0でない場合は5秒後に再度更新
            if (data.count > 0) {
                setTimeout(updatePendingTasksCount, 5000);
            }
        }
    })
    .catch(error => {
        console.error('残タスク数の取得に失敗しました:', error);
    });
}

// ページ読み込み完了時に初期化
document.addEventListener('DOMContentLoaded', function() {
    // 残タスク数を取得
    const pendingTasksElement = document.querySelector('.bg-warning .card-text.display-4');
    const currentCount = parseInt(pendingTasksElement.textContent) || 0;
    
    // 残タスク数が0でない場合のみ、5秒ごとに更新を開始
    if (currentCount > 0) {
        setTimeout(updatePendingTasksCount, 5000);
    }
});
</script>