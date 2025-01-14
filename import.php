<?php
// import.php
require_once __DIR__ . '/common/config.php';
require_once __DIR__ . '/common/functions.php';
require_once __DIR__ . '/common/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once __DIR__ . '/common/header.php';
?>

<h1 class="mb-4">データインポート</h1>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">プレーンナレッジのインポート</h5>
        <form method="POST" enctype="multipart/form-data" class="mb-3">
            <input type="hidden" name="type" value="plain">
            <div class="mb-3">
                <label for="plain_file" class="form-label">CSVファイルを選択</label>
                <input type="file" class="form-control" id="plain_file" name="file" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary">インポート</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">ナレッジのインポート</h5>
        <form method="POST" enctype="multipart/form-data" class="mb-3">
            <input type="hidden" name="type" value="knowledge">
            <div class="mb-3">
                <label for="knowledge_file" class="form-label">CSVファイルを選択</label>
                <input type="file" class="form-control" id="knowledge_file" name="file" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary">インポート</button>
        </form>
    </div>
</div>

<?php
    require_once 'footer.php';
    exit;
}

// POSTリクエストの処理
$type = $_POST['type'] ?? '';
$file = $_FILES['file'] ?? null;

if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    die('File upload failed');
}

// CSVファイルの読み込み
$handle = fopen($file['tmp_name'], 'r');
if (!$handle) {
    die('Failed to open file');
}

// BOMをスキップ
fgets($handle, 4);
rewind($handle);
$bom = fread($handle, 3);
if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) {
    rewind($handle);
}

// ヘッダー行を読み込み
$headers = fgetcsv($handle);

try {
    $pdo->beginTransaction();

    switch ($type) {
        case 'plain':
            while (($data = fgetcsv($handle)) !== false) {
                $stmt = $pdo->prepare("
                    INSERT INTO record (title, text, created_by, created_at, updated_at)
                    VALUES (:title, :text, :created_by, '$timestamp', '$timestamp')
                ");
                
                $stmt->execute([
                    ':title' => $data[1], // タイトル
                    ':text' => $data[2],  // テキスト
                    ':created_by' => $_SESSION['user']
                ]);
            }
            break;

        case 'knowledge':
            while (($data = fgetcsv($handle)) !== false) {
                $stmt = $pdo->prepare("
                    INSERT INTO knowledge (
                        title, question, answer, reference,
                        parent_type, parent_id, prompt_id, created_by
                    ) VALUES (
                        :title, :question, :answer, :reference,
                        :parent_type, :parent_id, :prompt_id, :created_by
                    )
                ");
                
                $stmt->execute([
                    ':title' => $data[1],      // タイトル
                    ':question' => $data[2],   // Question
                    ':answer' => $data[3],     // Answer
                    ':reference' => $data[4],  // Reference
                    ':parent_type' => $data[5],// 親タイプ
                    ':parent_id' => $data[6],  // 親ID
                    ':prompt_id' => $data[7],  // プロンプトID
                    ':created_by' => $_SESSION['user']
                ]);
            }
            break;
    }

    $pdo->commit();
    fclose($handle);

    // 成功メッセージをセッションに保存
    $_SESSION['import_message'] = 'インポートが完了しました。';
    header('Location: ' . ($type === 'plain' ? 'record.php?action=list' : 'knowledge.php?action=list'));
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    fclose($handle);
    die('Import failed: ' . $e->getMessage());
}