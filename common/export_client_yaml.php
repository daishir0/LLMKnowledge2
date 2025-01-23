<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// グループIDの取得と検証
$group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
if ($group_id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid group ID');
}

// 設定を取得
$config = require __DIR__ . '/config.php';

// YAMLフォーマットの文字列を生成
$yaml_content = <<<YAML
api:
  bearer_token: "{$config['api']['bulk']['api_key']}"
  bulk_api_url: "{$root_url}{$base_url}/common/bulk-api.php"
  group_id: {$group_id}
YAML;

// YAMLとしてエクスポート
header('Content-Type: text/yaml');
header('Content-Disposition: attachment; filename="config.yaml"');
echo $yaml_content; 