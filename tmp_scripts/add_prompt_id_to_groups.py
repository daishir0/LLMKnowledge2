#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import sqlite3
import os

def add_prompt_id_to_groups():
    # データベースファイルのパスを設定
    db_path = os.path.join(os.path.dirname(__file__), 'knowledge.db')
    
    try:
        # データベースに接続
        conn = sqlite3.connect(db_path)
        cursor = conn.cursor()
        
        # prompt_idカラムを追加するALTER TABLE文
        alter_table_query = '''
        ALTER TABLE groups 
        ADD COLUMN prompt_id INTEGER DEFAULT NULL REFERENCES prompts(id)
        '''
        
        # クエリを実行
        cursor.execute(alter_table_query)
        
        # 変更をコミット
        conn.commit()
        
        print("カラム 'prompt_id' が正常に追加されました。")
    
    except sqlite3.Error as e:
        print(f"エラーが発生しました: {e}")
    
    finally:
        # 接続を閉じる
        if conn:
            conn.close()

if __name__ == '__main__':
    add_prompt_id_to_groups()