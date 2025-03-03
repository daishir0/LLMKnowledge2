<?php defined('APP_ROOT') or die(); ?>

<h1 class="mb-4">
    <?= $action === 'create' ? 'グループ作成' : 'グループ編集／プレーンナレッジ一括追加' ?>
</h1>

<form method="POST" class="needs-validation" novalidate>
    <div class="mb-3">
        <label for="name" class="form-label">グループ名</label>
        <input type="text" class="form-control" id="name" name="name" 
               value="<?= isset($group) ? h($group['name']) : '' ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="detail" class="form-label">説明</label>
        <textarea class="form-control" id="detail" name="detail" rows="5"><?= isset($group) ? h($group['detail']) : '' ?></textarea>
    </div>
    
    <div class="mb-3">
        <label for="prompt_id" class="form-label">プロンプト</label>
        <select class="form-control" id="prompt_id" name="prompt_id">
            <option value="">プロンプトを選択（オプション）</option>
            <?php foreach ($prompts as $prompt): ?>
            <option value="<?= h($prompt['id']) ?>" 
                data-content="<?= h($prompt['content']) ?>"
                <?= (isset($group) && $group['prompt_id'] == $prompt['id']) ? 'selected' : '' ?>>
                <?= h($prompt['id']) ?>: <?= h($prompt['title']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">プロンプト内容プレビュー</label>
        <pre class="border p-3 bg-light" id="prompt_preview"><?= isset($group['prompt_content']) ? h($group['prompt_content']) : '' ?></pre>
    </div>
    
    <button type="submit" class="btn btn-primary">保存</button>
    <a href="groups.php?action=list" class="btn btn-secondary">キャンセル</a>
</form>

<?php if ($action === 'edit'): ?>
    <hr>
    <!-- プレーンナレッジの追加 -->
    <div class="mt-5">
        <h3>プレーンナレッジ一括追加機能</h3>
        <form id="addRecordsForm">
            <div class="form-group">
                <textarea class="form-control" id="newRecords" name="newRecords" rows="10"
                          placeholder="URLや共有フォルダのファイルパスをここに入力し「追加する」ボタンを押下してください。一括登録できます。（1行に1つ）"></textarea>
            </div>
            <button type="button" id="addRecords" class="btn btn-success mt-3">追加する</button>
        </form>
    </div>

    <script>
    $(document).ready(function() {
        // プロンプト選択時のプレビュー更新
        $('#prompt_id').change(function() {
            const selectedOption = $(this).find('option:selected');
            const promptContent = selectedOption.data('content');
            $('#prompt_preview').text(promptContent || '');
        });

        // プレーンナレッジ追加
        $('#addRecords').click(function() {
            const newRecords = $('#newRecords').val().trim();
            if (!newRecords) {
                alert('追加するデータを入力してください。');
                return;
            }

            $.ajax({
                url: 'common/api.php',
                method: 'POST',
                data: {
                    action: 'add_records',
                    group_id: <?= h($_GET['id']) ?>,
                    newdata: newRecords
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('エラーが発生しました: ' + response.message);
                    }
                },
                error: function() {
                    alert('通信エラーが発生しました。');
                }
            });
        });
    });
    </script>
<?php else: ?>
    <script>
    $(document).ready(function() {
        // プロンプト選択時のプレビュー更新（作成画面用）
        $('#prompt_id').change(function() {
            const selectedOption = $(this).find('option:selected');
            const promptContent = selectedOption.data('content');
            $('#prompt_preview').text(promptContent || '');
        });
    });
    </script>
<?php endif; ?>