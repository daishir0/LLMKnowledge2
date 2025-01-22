"""
グループ機能追加のためのマイグレーションスクリプト

このスクリプトは以下の変更を行います：
1. groupsテーブルの作成
2. record, tasks, knowledgeテーブルへのgroup_id（外部キー）の追加

使用方法：
    python add_groups_table.py
"""

import sqlite3
import sys
from pathlib import Path

def migrate_database():
    """データベースにグループ機能を追加する"""
    
    # データベースに接続
    db_path = "knowledge.db"
    try:
        conn = sqlite3.connect(db_path)
        cursor = conn.cursor()
        
        # トランザクション開始
        cursor.execute("BEGIN TRANSACTION;")

        # groupsテーブルの作成
        cursor.execute("""
        CREATE TABLE groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            detail TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted INTEGER DEFAULT 0
        );
        """)

        # recordテーブルにgroup_idを追加
        cursor.execute("""
        ALTER TABLE record
        ADD COLUMN group_id INTEGER DEFAULT NULL
        REFERENCES groups(id);
        """)

        # tasksテーブルにgroup_idを追加
        cursor.execute("""
        ALTER TABLE tasks
        ADD COLUMN group_id INTEGER DEFAULT NULL
        REFERENCES groups(id);
        """)

        # knowledgeテーブルにgroup_idを追加
        cursor.execute("""
        ALTER TABLE knowledge
        ADD COLUMN group_id INTEGER DEFAULT NULL
        REFERENCES groups(id);
        """)

        # インデックスの作成
        cursor.execute("CREATE INDEX idx_record_group ON record(group_id);")
        cursor.execute("CREATE INDEX idx_tasks_group ON tasks(group_id);")
        cursor.execute("CREATE INDEX idx_knowledge_group ON knowledge(group_id);")
        
        # トランザクションのコミット
        conn.commit()
        print("Database migration completed successfully!")

    except sqlite3.Error as e:
        # エラーが発生した場合はロールバック
        conn.rollback()
        print(f"An error occurred: {e}")
        sys.exit(1)
    finally:
        # 接続を閉じる
        conn.close()

if __name__ == "__main__":
    migrate_database()