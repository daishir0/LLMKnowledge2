#!/usr/bin/env python3
"""
Knowledge Matrix出力プログラム

指定されたグループIDのknowledgeレコードを2次元マトリックス形式でExcelファイルに出力します。
PDFタイトルを縦軸、プロンプトを横軸として、knowledgeの内容をセルに配置します。

使用方法:
    python3 export_knowledge_matrix.py GROUP_ID [GROUP_ID ...]

引数:
    GROUP_ID: 処理対象のグループID（複数指定可）

出力:
    - ファイル名: knowledge_matrix.xlsx
    - フォーマット:
        - 縦軸: PDFタイトル
        - 横軸: プロンプト（prompt_idの順序で並び替え）
        - セル: knowledgeの内容
    - スタイル:
        - タイトル行: 中央揃え
        - データ行: 左上揃え
        - 列幅: 内容に応じて自動調整（最大50文字）

使用例:
    # 単一グループの出力
    python3 export_knowledge_matrix.py 8

    # 複数グループの出力
    python3 export_knowledge_matrix.py 8 10 11

    # 任意の数のグループを指定可能
    python3 export_knowledge_matrix.py 1 2 3 4 5
"""

import sqlite3
import pandas as pd
import sys
import argparse
from pathlib import Path
from openpyxl.styles import Alignment

def get_schema(cursor):
    """データベースのスキーマを取得"""
    cursor.execute("SELECT sql FROM sqlite_master WHERE type='table';")
    schemas = cursor.fetchall()
    print("データベーススキーマ:")
    for schema in schemas:
        print(schema[0])
    print("\n")

def create_knowledge_matrix(db_path, group_ids):
    """指定されたグループIDのknowledgeレコードを2次元マトリックスとして取得"""
    conn = sqlite3.connect(db_path)
    cursor = conn.cursor()
    
    # スキーマの確認
    get_schema(cursor)
    
    # まず、対象となるrecordを取得
    group_ids_str = ','.join(map(str, group_ids))
    record_query = f"""
    SELECT DISTINCT r.id, r.title
    FROM record r
    WHERE r.group_id IN ({group_ids_str})
    AND r.deleted = 0
    ORDER BY r.title;
    """
    
    cursor.execute(record_query)
    records = cursor.fetchall()
    
    if not records:
        print(f"警告: 指定されたグループID {group_ids} のレコードが見つかりませんでした")
        conn.close()
        return
        
    # recordごとのknowledgeを取得
    record_ids = [str(r[0]) for r in records]
    record_ids_str = ','.join(record_ids)
    
    # グループIDの順序でプロンプトを取得
    prompts_query = f"""
    SELECT DISTINCT p.id, p.title
    FROM prompts p
    JOIN knowledge k ON k.prompt_id = p.id
    WHERE k.group_id IN ({group_ids_str})
    AND k.deleted = 0
    ORDER BY p.id;
    """
    
    cursor.execute(prompts_query)
    prompt_order = [row[1] for row in cursor.fetchall()]
    
    query = f"""
    SELECT 
        k.id as knowledge_id,
        k.answer as knowledge,
        k.parent_id as record_id,
        r.title as pdf_title,
        p.title as prompt_title
    FROM knowledge k
    JOIN prompts p ON k.prompt_id = p.id
    JOIN record r ON k.parent_id = r.id
    WHERE k.parent_id IN ({record_ids_str})
    AND k.deleted = 0
    AND k.parent_type = 'record'
    ORDER BY r.title, p.id;
    """
    
    cursor.execute(query)
    knowledge_records = cursor.fetchall()
    
    if not knowledge_records:
        print(f"警告: 指定されたrecordに対するknowledgeが見つかりませんでした")
        conn.close()
        return
    
    # データをDataFrameに変換
    df_records = pd.DataFrame(
        knowledge_records, 
        columns=['knowledge_id', 'knowledge', 'record_id', 'pdf_title', 'prompt_title']
    )
    
    # プロンプトを列、PDF titleを行とするピボットテーブルを作成
    pivot_df = df_records.pivot(
        index='pdf_title',
        columns='prompt_title',
        values='knowledge'
    )
    
    # プロンプトの順序を設定
    pivot_df = pivot_df.reindex(columns=prompt_order)
    
    # Excelファイルに出力
    output_file = 'knowledge_matrix.xlsx'
    
    # ExcelWriterを使用してフォーマットを調整
    with pd.ExcelWriter(output_file, engine='openpyxl') as writer:
        pivot_df.to_excel(writer, sheet_name='Knowledge Matrix')
        
        # フォーマットの調整
        worksheet = writer.sheets['Knowledge Matrix']
        
        # タイトル行を中央揃え
        for cell in worksheet[1]:
            cell.alignment = Alignment(horizontal='center', vertical='center')
        
        # データ行を左上揃え
        for row in worksheet.iter_rows(min_row=2):
            for cell in row:
                cell.alignment = Alignment(horizontal='left', vertical='top')
        
        # 列幅の調整
        for column in worksheet.columns:
            max_length = 0
            column = list(column)
            for cell in column:
                try:
                    if len(str(cell.value)) > max_length:
                        max_length = len(str(cell.value))
                except:
                    pass
            adjusted_width = min(max_length + 2, 50)  # 最大幅を50に制限
            worksheet.column_dimensions[column[0].column_letter].width = adjusted_width
    
    print(f"マトリックスを {output_file} に出力しました")
    
    # データの概要を表示
    unique_pdfs = df_records['pdf_title'].unique()
    print(f"\n処理したPDF数: {len(unique_pdfs)}")
    print("\nプロンプト一覧（prompt_id順）:")
    for prompt in prompt_order:
        print(f"- {prompt}")
    
    conn.close()

def main():
    parser = argparse.ArgumentParser(description='Knowledge Matrixを作成します')
    parser.add_argument('group_ids', type=int, nargs='+', help='処理対象のグループID（複数指定可）')
    args = parser.parse_args()
    
    db_path = "knowledge.db"
    if not Path(db_path).exists():
        print(f"エラー: データベースファイル {db_path} が見つかりません")
        sys.exit(1)
    
    create_knowledge_matrix(db_path, args.group_ids)

if __name__ == "__main__":
    main()