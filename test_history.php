<?php
require_once __DIR__ . '/common/config.php';
require_once __DIR__ . '/common/functions.php';

// セッションユーザーを設定（テスト用）
session_start();
$_SESSION['user'] = 'test_user';

function testLogHistory($pdo, $table, $id, $data) {
    echo "Testing $table history logging...\n";
    $result = logHistory($pdo, $table, $id, $data);
    echo $result ? "Success!\n" : "Failed.\n";
    echo "Check logs for details.\n\n";
}

// recordテーブルのテスト
$recordData = [
    'title' => 'Test Record',
    'text' => 'This is a test record content.',
    'reference' => 'Test reference'
];
testLogHistory($pdo, 'record', 1, $recordData);

// knowledgeテーブルのテスト
$knowledgeData = [
    'title' => 'Test Knowledge',
    'question' => 'What is this test for?',
    'answer' => 'This is a test for history logging.',
    'reference' => 'Test knowledge reference'
];
testLogHistory($pdo, 'knowledge', 1, $knowledgeData);

// promptsテーブルのテスト
$promptData = [
    'title' => 'Test Prompt',
    'content' => 'This is a test prompt content.'
];
testLogHistory($pdo, 'prompts', 1, $promptData);

// 無効なテーブル名のテスト
$invalidData = [
    'title' => 'Invalid Test',
    'content' => 'This should fail.'
];
testLogHistory($pdo, 'invalid_table', 1, $invalidData);

// 必須カラム欠落のテスト
$invalidRecordData = [
    'title' => 'Invalid Record'
    // textカラムが欠落
];
testLogHistory($pdo, 'record', 1, $invalidRecordData);

echo "Testing completed. Please check the logs for details.\n"; 