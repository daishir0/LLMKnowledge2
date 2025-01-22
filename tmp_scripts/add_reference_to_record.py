# 新しいカラムを追加するスクリプト
import sqlite3

# データベースに接続
conn = sqlite3.connect('knowledge.db')
cursor = conn.cursor()

# カラムを追加するSQL文
cursor.execute("ALTER TABLE record ADD COLUMN reference TEXT")

# 変更を保存
conn.commit()

# 接続を閉じる
conn.close()