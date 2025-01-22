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
            <div class="alert alert-warning">
                <strong>CSVファイル作成時の注意:</strong>
                <ul>
                    <li>このインポートは新規データの追加のみを行います。既存データの編集はできません。</li>
                    <li>文字コードはUTF-8で保存してください。</li>
                    <li>CSVファイルの1行目からデータ行として読み込みます。ヘッダー行は不要です。</li>
                    <li>CSVファイルの列は以下の順番で入力してください：</li>
                </ul>
                <ol>
                    <li>タイトル</li>
                    <li>テキスト</li>
                    <li>参照 (オプション)</li>
                    <li>グループID (オプション)</li>
                </ol>
                <p>例: <code>"タイトル例","本文テキスト","参照URL",1</code></p>
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
            <div class="alert alert-warning">
                <strong>CSVファイル作成時の注意:</strong>
                <ul>
                    <li>このインポートは新規データの追加のみを行います。既存データの編集はできません。</li>
                    <li>文字コードはUTF-8で保存してください。</li>
                    <li>CSVファイルの1行目からデータ行として読み込みます。ヘッダー行は不要です。</li>
                    <li>CSVファイルの列は以下の順番で入力してください：</li>
                </ul>
                <ol>
                    <li>タイトル</li>
                    <li>質問</li>
                    <li>回答</li>
                    <li>参照 (オプション)</li>
                    <li>親ID (オプション)</li>
                    <li>親タイプ (オプション)</li>
                    <li>プロンプトID (オプション)</li>
                    <li>グループID (オプション)</li>
                </ol>
                <p>例: <code>"ナレッジタイトル","質問内容","回答内容","参照URL",1,"group",2,1</code></p>
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

// ヘッダー行の読み込みを削除（すべての行をデータ行として扱う）

try {
    $pdo->beginTransaction();

    switch ($type) {
        case 'plain':
            while (($data = fgetcsv($handle)) !== false) {
                $stmt = $pdo->prepare("
                    INSERT INTO record (title, text, reference, group_id, created_by, created_at, updated_at, deleted)
                    VALUES (:title, :text, :reference, :group_id, :created_by, '$timestamp', '$timestamp', 0)
                ");
                
                $stmt->execute([
                    ':title' => $data[0],      // タイトル
                    ':text' => $data[1],       // テキスト
                    ':reference' => $data[2] ?? null,  // 参照
                    ':group_id' => $data[3] ?? null,   // グループID
                    ':created_by' => $_SESSION['user']
                ]);
            }
            break;

        case 'knowledge':
            while (($data = fgetcsv($handle)) !== false) {
                $stmt = $pdo->prepare("
                    INSERT INTO knowledge (
                        title, question, answer, reference,
                        parent_id, parent_type, prompt_id, group_id, created_by, created_at, updated_at, deleted
                    ) VALUES (
                        :title, :question, :answer, :reference,
                        :parent_id, :parent_type, :prompt_id, :group_id, :created_by, '$timestamp', '$timestamp', 0
                    )
                ");
                
                $stmt->execute([
                    ':title' => $data[0],          // タイトル
                    ':question' => $data[1],       // 質問
                    ':answer' => $data[2],         // 回答
                    ':reference' => $data[3] ?? null,  // 参照
                    ':parent_id' => $data[4] ?? null,  // 親ID
                    ':parent_type' => $data[5] ?? null,// 親タイプ
                    ':prompt_id' => $data[6] ?? null,  // プロンプトID
                    ':group_id' => $data[7] ?? null,   // グループID
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