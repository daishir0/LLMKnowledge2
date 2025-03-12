<?php defined('APP_ROOT') or die(); ?>

<h1 class="mb-4">
    <?= $action === 'create' ? 'Create Group' : 'Edit Group / Bulk Add Plain Knowledge' ?>
</h1>

<form method="POST" class="needs-validation" novalidate>
    <div class="mb-3">
        <label for="name" class="form-label">Group Name</label>
        <input type="text" class="form-control" id="name" name="name" 
               value="<?= isset($group) ? h($group['name']) : '' ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="detail" class="form-label">Description</label>
        <textarea class="form-control" id="detail" name="detail" rows="5"><?= isset($group) ? h($group['detail']) : '' ?></textarea>
    </div>
    
    <div class="mb-3">
        <label for="prompt_id" class="form-label">Prompt</label>
        <select class="form-control" id="prompt_id" name="prompt_id">
            <option value="">Select Prompt (Optional)</option>
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
        <label class="form-label">Prompt Content Preview</label>
        <pre class="border p-3 bg-light" id="prompt_preview"><?= isset($group['prompt_content']) ? h($group['prompt_content']) : '' ?></pre>
    </div>
    
    <button type="submit" class="btn btn-primary">Save</button>
    <a href="groups.php?action=list" class="btn btn-secondary">Cancel</a>
</form>

<?php if ($action === 'edit'): ?>
    <hr>
    <!-- プレーンナレッジの追加 -->
    <div class="mt-5">
        <h3>Bulk Add Plain Knowledge</h3>
        <form id="addRecordsForm">
            <div class="form-group">
                <textarea class="form-control" id="newRecords" name="newRecords" rows="10"
                          placeholder="Enter URLs or shared folder file paths here and click the 'Add' button. You can register multiple items at once (one per line)."></textarea>
            </div>
            <button type="button" id="addRecords" class="btn btn-success mt-3">Add</button>
        </form>
    </div>

    <script>
    $(document).ready(function() {
        // Update preview when prompt is selected
        $('#prompt_id').change(function() {
            const selectedOption = $(this).find('option:selected');
            const promptContent = selectedOption.data('content');
            $('#prompt_preview').text(promptContent || '');
        });

        // Add plain knowledge
        $('#addRecords').click(function() {
            const newRecords = $('#newRecords').val().trim();
            if (!newRecords) {
                alert('Please enter data to add.');
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
                        alert('An error occurred: ' + response.message);
                    }
                },
                error: function() {
                    alert('A communication error occurred.');
                }
            });
        });
    });
    </script>
<?php else: ?>
    <script>
    $(document).ready(function() {
        // Update preview when prompt is selected (for creation screen)
        $('#prompt_id').change(function() {
            const selectedOption = $(this).find('option:selected');
            const promptContent = selectedOption.data('content');
            $('#prompt_preview').text(promptContent || '');
        });
    });
    </script>
<?php endif; ?>