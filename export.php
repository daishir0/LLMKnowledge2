<?php
// export.php
require_once 'common/config.php';
require_once 'common/functions.php';
require_once 'common/auth.php';

$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'csv';

// ファイル名の設定
$timestamp = date('Ymd_His');
$filename = "{$type}_export_{$timestamp}.{$format}";

// ヘッダーの設定
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// BOMを出力
echo chr(0xEF) . chr(0xBB) . chr(0xBF);

$output = fopen('php://output', 'w');

switch ($type) {
    case 'plain':
        // プレーンナレッジのエクスポート
        fputcsv($output, [
            'ID',
            'タイトル',
            'テキスト',
            '作成者',
            '作成日時',
            '更新日時'
        ]);

        $stmt = $pdo->query("
            SELECT r.*, r.created_by as created_by_name
            FROM record r
            WHERE r.deleted = 0
            ORDER BY r.id
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['title'],
                $row['text'],
                $row['created_by_name'],
                $row['created_at'],
                $row['updated_at']
            ]);
        }
        break;

    case 'knowledge':
        // ナレッジのエクスポート
        fputcsv($output, [
            'ID',
            'タイトル',
            'Question',
            'Answer',
            'Reference',
            '親タイプ',
            '親ID',
            'プロンプトID',
            '作成者',
            '作成日時',
            '更新日時'
        ]);

        $stmt = $pdo->query("
            SELECT k.*, k.created_by as created_by_name
            FROM knowledge k
            WHERE k.deleted = 0
            ORDER BY k.id
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['title'],
                $row['question'],
                $row['answer'],
                $row['reference'],
                $row['parent_type'],
                $row['parent_id'],
                $row['prompt_id'],
                $row['created_by_name'],
                $row['created_at'],
                $row['updated_at']
            ]);
        }
        break;

    case 'history':
        // 履歴のエクスポート
        $target = $_GET['target'] ?? '';
        if (!in_array($target, ['record', 'knowledge', 'prompt'])) {
            die('Invalid target specified');
        }

        fputcsv($output, [
            '対象ID',
            'タイトル',
            '変更内容',
            '変更者',
            '変更日時'
        ]);

        $stmt = $pdo->prepare("
            SELECT h.*, h.modified_by as modified_by_name
            FROM {$target}_history h
            ORDER BY h.{$target}_id, h.created_at
        ");
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row["{$target}_id"],
                $row['title'],
                $row['content'] ?? '',
                $row['modified_by_name'],
                $row['created_at']
            ]);
        }
        break;
}

fclose($output);