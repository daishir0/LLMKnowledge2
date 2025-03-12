<?php
// header.php
require_once __DIR__ . '/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SYSTEM_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="<?= BASE_URL ?>/index.php"><?= SYSTEM_NAME ?></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <!-- <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/record.php?action=list">プレーンナレッジ管理</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/knowledge.php">ナレッジ管理</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/prompts.php">プロンプト管理</a>
                    </li> -->
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/groups.php">Group Management</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/prompts.php">Prompt Management</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/tasks.php">Task Management</a>
                    </li>
                    <li class="nav-item">
                        <?php
                        // tasksテーブルの存在チェック
                        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tasks'");
                        $tasksTableExists = $stmt->fetchColumn();
                        
                        $pendingTasksCount = 0;
                        if ($tasksTableExists) {
                            // 残タスク数（pending状態のタスク数）を取得
                            $stmt = $pdo->query("
                                SELECT COUNT(*)
                                FROM tasks
                                WHERE deleted = 0
                                AND status = 'pending'
                            ");
                            $pendingTasksCount = $stmt->fetchColumn();
                        }
                        ?>
                        <span class="nav-link" id="pending-tasks-count">
                            Pending Tasks: <?= $pendingTasksCount > 0 ? "{$pendingTasksCount} items" : "None" ?>
                        </span>
                    </li>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <span class="nav-link text-light">
                            <i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['user']) ?>
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/common/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container">
