"""
Knowledge Database Manager (ナレッジデータベース管理ツール)

使用方法:
1. データの表示のみ:
   python add_knowledge.py <source_db>

2. データの表示とコピー:
   python add_knowledge.py <source_db> <target_db> -f

引数:
    source_db: コピー元のSQLiteデータベースファイルパス
    target_db: コピー先のSQLiteデータベースファイルパス（オプション）
    -f, --force: データのコピーを実行するフラグ

例:
    # データの表示のみ
    python add_knowledge.py data/source.db

    # データの表示とコピー
    python add_knowledge.py data/source.db data/target.db -f

注意:
    - コピー先のデータベースが存在しない場合は新規作成されます
    - -fオプションを使用する場合は、必ずtarget_dbを指定する必要があります
    - コピー時にはソースDBのデータがターゲットDBに追加されます
"""

import sqlite3
import sys
import argparse

def display_and_copy_knowledge(source_db_path, target_db_path=None, force_insert=False):
    source_conn = None
    target_conn = None
    
    try:
        # ソースDBに接続
        source_conn = sqlite3.connect(source_db_path)
        source_cursor = source_conn.cursor()

        # ソースDBから全レコードを取得
        source_cursor.execute("SELECT * FROM knowledge")
        records = source_cursor.fetchall()
        column_names = [description[0] for description in source_cursor.description]

        # レコードを表示
        print("\n元のデータベースの内容:")
        print(" | ".join(column_names))
        print("-" * 100)
        for record in records:
            print(" | ".join(str(value) for value in record))

        # -fオプションが指定されている場合のみinsert処理を実行
        if force_insert and target_db_path:
            # ターゲットDBに接続
            target_conn = sqlite3.connect(target_db_path)
            target_cursor = target_conn.cursor()

            # ターゲットDBにテーブルを作成
            target_cursor.execute("""
                CREATE TABLE IF NOT EXISTS knowledge (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    title TEXT NOT NULL,
                    question TEXT NOT NULL,
                    answer TEXT NOT NULL,
                    reference TEXT,
                    record_id INTEGER,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            """)

            # ターゲットDBにレコードを挿入
            insert_columns = column_names[1:]  # idを除外
            placeholders = ",".join(["?" for _ in insert_columns])
            insert_sql = f"INSERT INTO knowledge ({','.join(insert_columns)}) VALUES ({placeholders})"

            for record in records:
                target_cursor.execute(insert_sql, record[1:])

            target_conn.commit()
            print(f"\n{len(records)}件のレコードを{target_db_path}にコピーしました。")

    except sqlite3.Error as e:
        print(f"データベースエラーが発生しました: {e}")
    except Exception as e:
        print(f"エラーが発生しました: {e}")
    finally:
        if source_conn:
            source_conn.close()
        if target_conn:
            target_conn.close()

def main():
    parser = argparse.ArgumentParser(description='knowledgeテーブルのデータを表示・コピーします')
    parser.add_argument('source_db', help='コピー元のデータベースファイル')
    parser.add_argument('target_db', nargs='?', help='コピー先のデータベースファイル')
    parser.add_argument('-f', '--force', action='store_true', help='コピー先データベースへのinsertを実行')

    args = parser.parse_args()

    if args.force and not args.target_db:
        parser.error("-fオプションを使用する場合は、コピー先データベースを指定する必要があります")

    display_and_copy_knowledge(args.source_db, args.target_db, args.force)

if __name__ == "__main__":
    main()