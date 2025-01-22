import sqlite3

# データベースに接続
conn = sqlite3.connect('knowledge.db')
cursor = conn.cursor()

# カラムを追加するSQL文
alter_table_query = """
ALTER TABLE tasks
ADD COLUMN prompt_id INTEGER;
"""

# SQL文を実行
cursor.execute(alter_table_query)

# 変更を保存
conn.commit()

# 接続を閉じる
conn.close()