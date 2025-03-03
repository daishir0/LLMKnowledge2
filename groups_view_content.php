<?php defined('APP_ROOT') or die(); ?>

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
                        <a href="record.php?action=list&search=&group_id=<?= h($group['id']) ?>" class="text-white text-decoration-none">
                            <p class="card-text display-6"><?= h($group['record_count']) ?></p>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h6 class="card-title">ナレッジ数</h6>
                        <a href="knowledge.php?action=list&search=&group_id=<?= h($group['id']) ?>" class="text-white text-decoration-none">
                            <p class="card-text display-6"><?= h($group['knowledge_count']) ?></p>
                        </a>
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

            <a href="common/export_client_yaml.php?group_id=<?= h($group['id']) ?>" 
               class="btn btn-primary">
                設定ファイルをダウンロード
            </a>
        </div>
    </div>
</div>