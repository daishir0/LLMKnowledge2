import sqlite3

def migrate_database(old_db_path, new_db_path):
    # 古いデータベースに接続
    old_conn = sqlite3.connect(old_db_path)
    old_cursor = old_conn.cursor()

    # 新しいデータベースに接続
    new_conn = sqlite3.connect(new_db_path)
    new_cursor = new_conn.cursor()

    # 新しいテーブルを作成
    new_cursor.execute("""
    CREATE TABLE prompts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        category TEXT NOT NULL,
        created_by TEXT,  -- 変更: INTEGER から TEXT に
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted INTEGER DEFAULT 0
    );
    """)

    new_cursor.execute("""
    CREATE TABLE prompt_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        prompt_id INTEGER,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        modified_by TEXT,  -- 変更: INTEGER から TEXT に
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (prompt_id) REFERENCES prompts(id)
    );
    """)

    new_cursor.execute("""
    CREATE TABLE record (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        text TEXT NOT NULL,
        created_by TEXT,  -- 変更: INTEGER から TEXT に
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted INTEGER DEFAULT 0
    );
    """)

    new_cursor.execute("""
    CREATE TABLE record_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        record_id INTEGER,
        title TEXT NOT NULL,
        text TEXT NOT NULL,
        modified_by TEXT,  -- 変更: INTEGER から TEXT に
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (record_id) REFERENCES record(id)
    );
    """)

    new_cursor.execute("""
    CREATE TABLE knowledge (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        question TEXT NOT NULL,
        answer TEXT NOT NULL,
        reference TEXT,
        parent_id INTEGER,
        parent_type TEXT,
        prompt_id INTEGER,
        created_by TEXT,  -- 変更: INTEGER から TEXT に
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted INTEGER DEFAULT 0,
        FOREIGN KEY (prompt_id) REFERENCES prompts(id)
    );
    """)

    new_cursor.execute("""
    CREATE TABLE knowledge_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        knowledge_id INTEGER,
        title TEXT NOT NULL,
        question TEXT NOT NULL,
        answer TEXT NOT NULL,
        reference TEXT,
        modified_by TEXT,  -- 変更: INTEGER から TEXT に
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (knowledge_id) REFERENCES knowledge(id)
    );
    """)

    new_cursor.execute("""
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
        created_by TEXT,  -- 変更: INTEGER から TEXT に
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted INTEGER DEFAULT 0,
        FOREIGN KEY (result_knowledge_id) REFERENCES knowledge(id)
    );
    """)

    # データを移行
    tables = ['prompts', 'prompt_history', 'record', 'record_history', 'knowledge', 'knowledge_history', 'tasks']
    for table in tables:
        old_cursor.execute(f"SELECT * FROM {table};")
        rows = old_cursor.fetchall()
        
        for row in rows:
            # 新しいテーブルにデータを挿入
            new_cursor.execute(f"INSERT INTO {table} VALUES ({', '.join(['?' for _ in row])});", row)

    # コミットして接続を閉じる
    new_conn.commit()
    old_conn.close()
    new_conn.close()
    print("Migration completed successfully!")

if __name__ == "__main__":
    migrate_database("knowledge.db", "knowledge2.db")