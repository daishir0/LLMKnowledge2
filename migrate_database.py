"""
データベースマイグレーションスクリプト

このスクリプトは、ナレッジベースアプリケーションのSQLiteデータベースを
新しいスキーマにマイグレーションするために使用されます。

主な機能：
- 既存のテーブル（knowledge, record）のバックアップ作成
- 新しいテーブル構造の作成（prompts, knowledge, record, 履歴テーブルなど）
- バックアップからデータの復元
- 検索用インデックスの作成

使用方法：
    python migrate_database.py

注意：
- 実行前にデータベースのバックアップを作成することを強く推奨します
- デフォルトでは 'knowledge.db' ファイルに対して実行されます
- スクリプトの実行中にエラーが発生した場合、すべての変更が自動的にロールバックされます
"""

import sqlite3
from pathlib import Path
import sys

def execute_migration(db_path):
    """
    SQLiteデータベースのマイグレーションを実行する
    
    Args:
        db_path (str): データベースファイルのパス
    """
    # データベースファイルの存在確認
    if not Path(db_path).exists():
        print(f"Error: Database file {db_path} does not exist.")
        sys.exit(1)

    # データベースに接続
    try:
        conn = sqlite3.connect(db_path)
        cursor = conn.cursor()
        
        # トランザクション開始
        cursor.execute("BEGIN TRANSACTION;")

        # 既存テーブルのバックアップ
        print("Creating backup tables...")
        cursor.execute("CREATE TABLE knowledge_backup AS SELECT * FROM knowledge;")
        cursor.execute("CREATE TABLE record_backup AS SELECT * FROM record;")

        # 既存テーブルの削除
        print("Dropping existing tables...")
        cursor.execute("DROP TABLE knowledge;")
        cursor.execute("DROP TABLE record;")

        # 新しいテーブルの作成
        print("Creating new tables...")

        # プロンプトテーブル
        cursor.execute("""
        CREATE TABLE prompts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            category TEXT NOT NULL,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted INTEGER DEFAULT 0
        );
        """)

        # プロンプト履歴テーブル
        cursor.execute("""
        CREATE TABLE prompt_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            prompt_id INTEGER,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            modified_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (prompt_id) REFERENCES prompts(id)
        );
        """)

        # プレーンナレッジテーブル
        cursor.execute("""
        CREATE TABLE record (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            text TEXT NOT NULL,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted INTEGER DEFAULT 0
        );
        """)

        # プレーンナレッジ履歴テーブル
        cursor.execute("""
        CREATE TABLE record_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            record_id INTEGER,
            title TEXT NOT NULL,
            text TEXT NOT NULL,
            modified_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (record_id) REFERENCES record(id)
        );
        """)

        # ナレッジテーブル
        cursor.execute("""
        CREATE TABLE knowledge (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            reference TEXT,
            parent_id INTEGER,
            parent_type TEXT,
            prompt_id INTEGER,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted INTEGER DEFAULT 0,
            FOREIGN KEY (prompt_id) REFERENCES prompts(id)
        );
        """)

        # ナレッジ履歴テーブル
        cursor.execute("""
        CREATE TABLE knowledge_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            knowledge_id INTEGER,
            title TEXT NOT NULL,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            reference TEXT,
            modified_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (knowledge_id) REFERENCES knowledge(id)
        );
        """)

        # バックアップからデータを復元
        print("Restoring data from backup...")
        cursor.execute("""
        INSERT INTO record (id, title, text, created_at, updated_at)
        SELECT id, title, text, created_at, updated_at FROM record_backup;
        """)
        
        cursor.execute("""
        INSERT INTO knowledge (id, title, question, answer, reference, created_at, updated_at)
        SELECT id, title, question, answer, reference, created_at, updated_at FROM knowledge_backup;
        """)

        # インデックスの作成
        print("Creating indexes...")
        cursor.execute("CREATE INDEX idx_record_search ON record(title, text);")
        cursor.execute("CREATE INDEX idx_knowledge_search ON knowledge(title, question, answer);")
        cursor.execute("CREATE INDEX idx_prompts_search ON prompts(title, content);")

        # バックアップテーブルの削除
        print("Removing backup tables...")
        cursor.execute("DROP TABLE knowledge_backup;")
        cursor.execute("DROP TABLE record_backup;")

        # トランザクションのコミット
        conn.commit()
        print("Migration completed successfully!")

    except sqlite3.Error as e:
        # エラーが発生した場合はロールバック
        conn.rollback()
        print(f"An error occurred: {e}")
        sys.exit(1)
    finally:
        # 接続を閉じる
        conn.close()

if __name__ == "__main__":
    db_path = "knowledge.db"
    execute_migration(db_path)