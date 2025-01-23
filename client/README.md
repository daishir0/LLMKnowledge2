# データコレクター

このツールは、指定されたソースからコンテンツを収集し、マークダウン形式に変換して保存・更新するPythonアプリケーションです。

## 主な機能

- 複数のファイル形式（HTML, PDF, Word, Excel, PowerPoint）からのコンテンツ抽出
- ローカルファイルとURLからのコンテンツ収集
- コンテンツの自動マークダウン変換
- データベースによる変更管理
- APIを介したデータの同期

## 環境構築

### 前提条件

- Anaconda または Miniconda がインストールされていること
- Python 3.10 以上

### 必要なパッケージ

- pyyaml >= 6.0.1: YAML設定ファイルの読み込み
- requests >= 2.31.0: HTTPリクエスト処理
- markitdown: ドキュメント変換ライブラリ（Microsoft提供）
- beautifulsoup4 >= 4.12.2: HTMLパース
- lxml >= 4.9.3: XMLパース

### セットアップ手順

1. Python環境の作成
```bash
# Python 3.10で新しい環境を作成
conda create -n client python=3.10

# 環境をアクティベート
conda activate client
```

2. 依存パッケージのインストール
```bash
# パッケージのインストール
pip install -r requirements.txt
```

3. 設定ファイルの準備
```bash
# config.sample.yamlをコピーしてconfig.yamlを作成
cp config.sample.yaml config.yaml

# config.yamlを編集して必要な設定を行う
```

## 設定

`config.yaml` に以下の設定が必要です：

```yaml
api:
  bearer_token: "YOUR_API_TOKEN"
  group_id: "YOUR_GROUP_ID"
  bulk_api_url: "API_ENDPOINT_URL"
debug:
  enabled: true  # デバッグログを有効にする場合はtrue
```

## 使用方法

1. 環境のアクティベート
```bash
conda activate client
```

2. スクリプトの実行
```bash
python data_collector.py
```

## ログ

- 実行ログは `log.txt` に出力されます
- デバッグモードが有効な場合、詳細なログが記録されます
- 古いログファイルは自動的にタイムスタンプ付きでバックアップされます

## データベース

- SQLiteデータベース（`data.db`）を使用
- 古いデータベースファイルは自動的にタイムスタンプ付きでバックアップされます

## サポートされているファイル形式

- HTML/XHTML
- PDF
- Microsoft Word (.doc, .docx)
- Microsoft Excel (.xls, .xlsx)
- Microsoft PowerPoint (.ppt, .pptx)

## エラーハンドリング

- 各処理のエラーはログファイルに記録されます
- データベースにもエラー内容が保存されます
- 処理は継続的に実行され、一つのレコードでエラーが発生しても他のレコードの処理は継続されます 