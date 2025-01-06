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
?>

<div class="row mb-4">
    <div class="col">
        <h1>KnowledgeDB ダッシュボード</h1>
        <p class="text-muted">ナレッジ管理システムへようこそ</p>
    </div>
</div>

<!-- 統計情報 -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h5 class="card-title">プレーンナレッジ</h5>
                <p class="card-text display-4"><?= h($plainCount) ?></p>
                <p class="card-text">登録件数</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h5 class="card-title">ナレッジ</h5>
                <p class="card-text display-4"><?= h($knowledgeCount) ?></p>
                <p class="card-text">登録件数</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h5 class="card-title">プロンプト</h5>
                <p class="card-text display-4"><?= h($promptCount) ?></p>
                <p class="card-text">登録件数</p>
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
                    <div class="mt-2">
                        <a href="export.php?type=plain" class="btn btn-outline-secondary btn-sm me-2">エクスポート</a>
                        <a href="import.php" class="btn btn-outline-secondary btn-sm">インポート</a>
                    </div>
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
                    <div class="mt-2">
                        <a href="export.php?type=knowledge" class="btn btn-outline-secondary btn-sm me-2">エクスポート</a>
                        <a href="import.php" class="btn btn-outline-secondary btn-sm">インポート</a>
                    </div>
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
</div>

<?php require_once __DIR__ . '/common/footer.php'; ?>