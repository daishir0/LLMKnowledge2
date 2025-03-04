<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// グループIDのバリデーション
if (!isset($_POST['group_ids']) || empty($_POST['group_ids'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'グループIDが指定されていません。']);
    exit;
}

// 入力値から空白を除去
$group_ids_input = trim(preg_replace('/\s+/', '', $_POST['group_ids']));

// グループIDをカンマで分割して配列に
$group_ids = array_map('intval', explode(',', $group_ids_input));

// 不正な値をフィルタリング
$group_ids = array_filter($group_ids, function($id) {
    return $id > 0;
});

if (empty($group_ids)) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => '有効なグループIDが指定されていません。']);
    exit;
}

try {
    // グループIDを文字列に変換
    $group_ids_str = implode(',', $group_ids);

    // グループ情報を取得（ユーザーが指定した順序を保持）
    $groups = [];
    foreach ($group_ids as $group_id) {
        $group_query = "
            SELECT id, name
            FROM groups
            WHERE id = :group_id
            AND deleted = 0
        ";
        $stmt = $pdo->prepare($group_query);
        $stmt->execute([':group_id' => $group_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($group) {
            $groups[] = $group;
        }
    }
    
    // 各グループに関連するプロンプトを取得
    $group_prompts = [];
    foreach ($groups as $group) {
        $prompts_query = "
            SELECT DISTINCT p.id, p.title
            FROM prompts p
            JOIN knowledge k ON k.prompt_id = p.id
            WHERE k.group_id = :group_id
            AND k.deleted = 0
            ORDER BY p.id
        ";
        $stmt = $pdo->prepare($prompts_query);
        $stmt->execute([':group_id' => $group['id']]);
        $prompts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $group_prompts[$group['id']] = [
            'name' => $group['name'],
            'prompts' => $prompts
        ];
    }

    // 次に、ユニークなrecordを取得（タイトルでグループ化）
    $records_query = "
        SELECT DISTINCT r.id, r.title
        FROM record r
        WHERE r.group_id IN ($group_ids_str)
        AND r.deleted = 0
        GROUP BY r.title
        ORDER BY r.title
    ";
    $records = $pdo->query($records_query)->fetchAll(PDO::FETCH_ASSOC);

    // 各recordに対するknowledgeを取得
    $knowledge_data = [];
    foreach ($records as $record) {
        $record_title = $record['title'];
        $knowledge_data[$record_title] = [];

        // このタイトルに一致するすべてのrecordのIDを取得
        $title_records_query = "
            SELECT id 
            FROM record 
            WHERE title = :title 
            AND group_id IN ($group_ids_str)
            AND deleted = 0
        ";
        $stmt = $pdo->prepare($title_records_query);
        $stmt->execute([':title' => $record_title]);
        $record_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $record_ids_str = implode(',', $record_ids);

        // このrecordに関連するすべてのknowledgeを取得
        if (!empty($record_ids)) {
            $knowledge_query = "
                SELECT 
                    k.answer,
                    p.id as prompt_id,
                    p.title as prompt_title
                FROM knowledge k
                JOIN prompts p ON k.prompt_id = p.id
                WHERE k.parent_id IN ($record_ids_str)
                AND k.deleted = 0
                AND k.parent_type = 'record'
                ORDER BY p.id
            ";
            $knowledge_records = $pdo->query($knowledge_query)->fetchAll(PDO::FETCH_ASSOC);

            // プロンプトごとの回答をマッピング
            foreach ($knowledge_records as $kr) {
                $knowledge_data[$record_title][$kr['prompt_title']] = $kr['answer'];
            }
        }
    }

    // Excelファイルの作成
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // ヘッダー行の作成
    $sheet->setCellValue('A1', 'PDFタイトル');
    $col = 'B';
    foreach ($groups as $group) {
        $group_id = $group['id'];
        // このグループに関連するプロンプトのタイトルを取得
        $prompt_titles = array_column($group_prompts[$group_id]['prompts'], 'title');
        // プロンプトタイトルを結合して表示
        $prompt_title = implode(', ', $prompt_titles);
        $sheet->setCellValue($col . '1', $prompt_title);
        $col++;
    }

    // データの配置
    $row = 2;
    foreach ($knowledge_data as $title => $prompt_data) {
        $sheet->setCellValue('A' . $row, $title);
        
        $col = 'B';
        foreach ($groups as $group) {
            $group_id = $group['id'];
            // このグループに関連するプロンプトの回答を結合
            $group_answers = [];
            foreach ($group_prompts[$group_id]['prompts'] as $prompt) {
                $prompt_title = $prompt['title'];
                if (isset($prompt_data[$prompt_title])) {
                    $group_answers[] = $prompt_data[$prompt_title];
                }
            }
            $value = implode("\n\n", $group_answers);
            $sheet->setCellValue($col . $row, $value);
            $col++;
        }
        $row++;
    }

    // スタイルの設定
    $lastCol = chr(ord('A') + count($groups));
    $lastRow = $row - 1;

    // ヘッダー行のスタイル（中央揃え）
    $sheet->getStyle('A1:' . $lastCol . '1')->getAlignment()
          ->setHorizontal(Alignment::HORIZONTAL_CENTER)
          ->setVertical(Alignment::VERTICAL_CENTER);

    // データ行のスタイル（左上揃え）
    $sheet->getStyle('A2:' . $lastCol . $lastRow)->getAlignment()
          ->setHorizontal(Alignment::HORIZONTAL_LEFT)
          ->setVertical(Alignment::VERTICAL_TOP);

    // すべての列幅を200pxに設定（1px ≈ 0.142857）
    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setWidth(28.571); // 200px ÷ 7
    }

    // 改行を有効にする
    $sheet->getStyle('A1:' . $lastCol . $lastRow)
          ->getAlignment()
          ->setWrapText(true);

    // ファイルの出力
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="knowledge_matrix.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

} catch (Exception $e) {
    error_log("Error in export_matrix.php: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}