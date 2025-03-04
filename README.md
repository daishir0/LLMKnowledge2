## Overview
LLMKnowledge2 is a knowledge management system that allows you to store, organize, and generate knowledge entries using Large Language Models (LLMs). It features integration with OpenAI and Anthropic's Claude APIs to help generate and enhance knowledge entries.

Key features:
- Plain knowledge management
- AI-assisted knowledge generation
- Prompt template management
- Version history tracking
- Import/Export functionality
- User authentication
- Responsive web interface
- Group-based knowledge organization
- Knowledge matrix export (Excel format)

## Installation
1. Clone the repository:
```bash
git clone https://github.com/daishir0/LLMKnowledge2
```

2. Navigate to the project directory:
```bash
cd LLMKnowledge2
```

3. Copy the configuration file:
```bash
cp common/config.sample.php common/config.php
```

4. Install PHP dependencies:
```bash
composer install
```

5. Edit the configuration file (`common/config.php`):
- Set your OpenAI API key
- Set your Anthropic Claude API key
- Configure the admin username and password
- Adjust the base URL if needed

6. Set up the SQLite database:
```bash
touch knowledge.db
chmod 666 knowledge.db
```

7. Initialize the database:
```bash
python initialize_database.py
chmod 666 knowledge.db
```

8. Configure your web server:
- Point the web server to the project directory
- Add security rules to prevent direct access to knowledge.db
  ```apache
  # For Apache (httpd.conf)
  <FilesMatch "\.db$">
    Require all denied
  </FilesMatch>
  ```

## Usage
1. Access the application through your web browser
2. Log in using the configured admin credentials
3. Use the dashboard to:
   - Manage plain knowledge entries
   - Generate AI-enhanced knowledge using prompts
   - Create and manage prompt templates
   - Import/export data
   - Track version history
   - Organize knowledge entries into groups
   - Export knowledge matrix in Excel format:
     * Combine knowledge from multiple groups
     * View knowledge in a 2D matrix (PDF titles × prompts)
     * Easy export with comma-separated group IDs

## Requirements
- PHP 7.4 or later
- Composer
- PHP extensions:
  * SQLite support
  * zip (for Excel export)
  * gd (for image processing)

## Notes
- **IMPORTANT**: Protect knowledge.db from external access
  - Place it outside the web root if possible
  - Configure web server rules to deny direct access
  - Regularly check server logs for unauthorized access attempts
- Ensure proper file permissions for the SQLite database file
- Keep your API keys secure
- Regularly backup your database

## License
This project is licensed under the MIT License - see the LICENSE file for details.

---

# LLMKnowledge2
## 概要
LLMKnowledge2は、大規模言語モデル（LLM）を活用してナレッジを保存、整理、生成できる知識管理システムです。OpenAIおよびAnthropic ClaudeのAPIと統合されており、ナレッジエントリの生成と強化を支援します。

主な機能：
- プレーンナレッジ管理
- AI支援によるナレッジ生成
- プロンプトテンプレート管理
- バージョン履歴の追跡
- インポート/エクスポート機能
- ユーザー認証
- レスポンシブWebインターフェース
- グループベースのナレッジ管理
- ナレッジマトリックス出力（Excel形式）

## インストール方法
1. レポジトリをクローンします：
```bash
git clone https://github.com/daishir0/LLMKnowledge2
```

2. プロジェクトディレクトリに移動：
```bash
cd LLMKnowledge2
```

3. 設定ファイルをコピー：
```bash
cp common/config.sample.php common/config.php
```

4. PHP依存パッケージのインストール：
```bash
composer install
```

5. 設定ファイル（`common/config.php`）を編集：
- OpenAI APIキーを設定
- Anthropic Claude APIキーを設定
- 管理者ユーザー名とパスワードを設定
- 必要に応じてベースURLを調整

6. SQLiteデータベースのセットアップ：
```bash
touch knowledge.db
chmod 666 knowledge.db
```

7. データベースの初期化：
```bash
python initialize_database.py
chmod 666 knowledge.db
```

8. Webサーバーの設定：
- プロジェクトディレクトリにWebサーバーを向ける
- knowledge.dbへの直接アクセスを防ぐためのセキュリティルールを追加
  ```apache
  # For Apache (httpd.conf)
  <FilesMatch "\.db$">
    Require all denied
  </FilesMatch>
  ```

## 使い方
1. WebブラウザからアプリケーションにアクセスAI支援によるナレッジ生成
2. 設定した管理者認証情報でログイン
3. ダッシュボードから以下の操作が可能：
   - プレーンナレッジの管理
   - プロンプトを使用したAI支援ナレッジの生成
   - プロンプトテンプレートの作成と管理
   - データのインポート/エクスポート
   - バージョン履歴の確認
   - グループによるナレッジの整理
   - ナレッジマトリックスのExcel出力：
     * 複数グループのナレッジを1つのファイルに統合
     * ID×プロンプトの2次元マトリックス形式
     * カンマ区切りでグループIDを指定して簡単出力

## システム要件
- PHP 7.4以降
- Composer
- PHP拡張機能：
  * SQLiteサポート
  * zip（Excel出力用）
  * gd（画像処理用）

## 注意点
- **重要**: knowledge.dbへの外部アクセスからの保護
  - 可能であればWebルート外に配置
  - 直接アクセスを拒否するWebサーバーのルールを設定
  - 不正アクセスの試みについて定期的にサーバーログを確認
- SQLiteデータベースファイルの適切なファイル権限を確保すること
- APIキーは安全に管理すること
- 定期的にデータベースをバックアップすること

## ライセンス
このプロジェクトはMITライセンスの下でライセンスされています。詳細はLICENSEファイルを参照してください。
