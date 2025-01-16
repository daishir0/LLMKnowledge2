"""
データベース初期化スクリプト

このスクリプトは、ナレッジベースアプリケーションのSQLiteデータベースを
新規に初期化するために使用されます。

主な機能：
- 新しいテーブル構造の作成（prompts, knowledge, record, tasks, 履歴テーブルなど）
- 検索用インデックスの作成

使用方法：
    python initialize_database.py

注意：
- 既存のknowledge.dbが存在する場合、上書きされます
- データベースを初期化する前に、重要なデータのバックアップを推奨します
"""

import sqlite3
from pathlib import Path
import sys

def initialize_database(db_path):
    """
    SQLiteデータベースを新規に初期化する
    
    Args:
        db_path (str): データベースファイルのパス
    """
    # データベースファイルが存在する場合は削除
    db_file = Path(db_path)
    if db_file.exists():
        db_file.unlink()

    # データベースに接続
    try:
        conn = sqlite3.connect(db_path)
        cursor = conn.cursor()
        
        # トランザクション開始
        cursor.execute("BEGIN TRANSACTION;")

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

        # タスクテーブル
        cursor.execute("""
        CREATE TABLE tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_type TEXT NOT NULL,
            source_id INTEGER NOT NULL,
            source_text TEXT NOT NULL,
            prompt_content TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            priority INTEGER DEFAULT 0,
            scheduled_at DATETIME,
            error_message TEXT,
            result_knowledge_id INTEGER,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted INTEGER DEFAULT 0,
            FOREIGN KEY (result_knowledge_id) REFERENCES knowledge(id)
        );
        """)

        # インデックスの作成
        print("Creating indexes...")
        cursor.execute("CREATE INDEX idx_record_search ON record(title, text);")
        cursor.execute("CREATE INDEX idx_knowledge_search ON knowledge(title, question, answer);")
        cursor.execute("CREATE INDEX idx_prompts_search ON prompts(title, content);")
        
        # タスクテーブル用インデックス
        cursor.execute("CREATE INDEX idx_tasks_status ON tasks(status);")
        cursor.execute("CREATE INDEX idx_tasks_source ON tasks(source_type, source_id);")
        cursor.execute("CREATE INDEX idx_tasks_created ON tasks(created_at, status);")
        cursor.execute("CREATE INDEX idx_tasks_updated ON tasks(updated_at, status);")
        cursor.execute("CREATE INDEX idx_tasks_priority ON tasks(priority, status);")

        # トランザクションのコミット
        conn.commit()
        print("Database initialized successfully!")

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
    initialize_database(db_path)