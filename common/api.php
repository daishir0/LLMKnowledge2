<?php
require_once 'config.php';
require_once 'functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("
        INSERT INTO record (title, text)
        VALUES (:title, :text)
    ");
    
    $stmt->execute([
        ':title' => $_POST['title'],
        ':text' => $_POST['text']
    ]);
    
    echo json_encode(['status' => 'success', 'message' => 'Record created successfully.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>