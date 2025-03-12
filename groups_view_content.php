<?php defined('APP_ROOT') or die(); ?>

<h1 class="mb-4">Group Details</h1>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title"><?= h($group['name']) ?></h5>
        <p class="card-text"><?= nl2br(h($group['detail'])) ?></p>
        
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h6 class="card-title">Plain Knowledge Count</h6>
                        <a href="record.php?action=list&search=&group_id=<?= h($group['id']) ?>" class="text-white text-decoration-none">
                            <p class="card-text display-6"><?= h($group['record_count']) ?></p>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h6 class="card-title">Knowledge Count</h6>
                        <a href="knowledge.php?action=list&search=&group_id=<?= h($group['id']) ?>" class="text-white text-decoration-none">
                            <p class="card-text display-6"><?= h($group['knowledge_count']) ?></p>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-4 border-top pt-4">
            <h6>Prompt Information</h6>
            <?php if (!empty($group['prompt_title'])): ?>
                <p class="card-text">
                    <strong>Prompt Name:</strong> <?= h($group['prompt_title']) ?>
                </p>
                <?php if (!empty($group['prompt_content'])): ?>
                <div class="mt-2">
                    <label class="form-label">Prompt Content</label>
                    <pre class="border p-3 bg-light"><?= h($group['prompt_content']) ?></pre>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="card-text text-muted">No prompt registered</p>
            <?php endif; ?>
        </div>

        <div class="mt-4">
            <h6>Creation Information</h6>
            <p>
                Created: <?= h($group['created_at']) ?><br>
                Updated: <?= h($group['updated_at']) ?>
            </p>
        </div>
    </div>
</div>

<!-- List of plain knowledge linked to this group -->
<div class="mt-5">
    <h3>Plain Knowledge Linked to This Group</h3>
    <?php
    // Get total count
    $total_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM record
        WHERE group_id = :group_id
        AND deleted = 0
    ");
    $total_stmt->execute([':group_id' => $_GET['id']]);
    $total_count = $total_stmt->fetchColumn();
    ?>
    <p>Showing the latest 3 out of <?= h($total_count) ?> items. View all items <a href="record.php?action=list&search=&group_id=<?= $_GET['id'] ?>">here</a>.</p>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Reference</th>
                    <th>Updated</th>
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

<!-- List of knowledge linked to this group -->
<div class="mt-5">
    <h3>Knowledge Linked to This Group</h3>
    <?php
    // Get total count
    $total_knowledge_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM knowledge
        WHERE group_id = :group_id
        AND deleted = 0
    ");
    $total_knowledge_stmt->execute([':group_id' => $_GET['id']]);
    $total_knowledge_count = $total_knowledge_stmt->fetchColumn();
    ?>
    <p>Showing the latest 3 out of <?= h($total_knowledge_count) ?> items. View all items <a href="knowledge.php?action=list&search=&group_id=<?= $_GET['id'] ?>">here</a>.</p>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Reference</th>
                    <th>Updated</th>
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

<!-- Data collector section -->
<div class="mt-5 mb-5">
    <h3>Data Collector</h3>
    <div class="card">
        <div class="card-body">
            <p class="card-text">This is the program (Data Collector) and configuration file for collecting plain knowledge for this group. Place them in the same directory and run.</p>

            <a href="client/dist/data_collector.exe"
               class="btn btn-primary"
               download>
                Download Program
            </a>

            <a href="common/export_client_yaml.php?group_id=<?= h($group['id']) ?>"
               class="btn btn-primary">
                Download Configuration File
            </a>
        </div>
    </div>
</div>