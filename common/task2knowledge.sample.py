import sqlite3
import openai
import datetime
import os
import time
import re

# データベースパスを明示的に指定、必要に応じて変えること
DATABASE_PATH = '/var/www/html/LLMKnowledge2/knowledge.db'
API_KEY = os.environ.get('OPENAI_API_KEY', 'your-apikey-here')

# 基本パラメーター
CHUNK_SIZE = 2000        # 1チャンクの最小文字数
MAX_CHUNK_SIZE = 3000   # 1チャンクの最大文字数
MAX_RETRIES = 3         # APIリトライ回数
RETRY_DELAY = 1         # リトライ間隔（秒）
API_RATE_LIMIT = 0.5    # API呼び出し間隔（秒）
DEBUG_MODE = False        # デバッグモードの有効無効

def debug_print(message):
    """デバッグメッセージを出力"""
    if DEBUG_MODE:
        print(f"[DEBUG] {message}")

# 言語依存の設定
SENTENCE_ENDINGS = {
    'ja': ['。', '！', '？'],
    'en': ['.', '!', '?']
}
PARAGRAPH_BREAKS = ['\n\n', '\r\n\r\n']

# 括弧の定義
BRACKETS = {
    'open': ['「', '『', '（', '(', '［', '[', '｛', '{'],
    'close': ['」', '』', '）', ')', '］', ']', '｝', '}']
}

def detect_language(text):
    """テキストの主要言語を検出する"""
    debug_print("Detecting language...")
    if re.search(r'[ぁ-んァ-ン一-龥]', text):
        debug_print("Detected language: Japanese")
        return 'ja'
    debug_print("Detected language: English")
    return 'en'

def is_real_sentence_ending(text, pos, ending):
    """本当の文末かどうかを判断する"""
    if pos + len(ending) >= len(text):
        return True
        
    # 次の文字が開始括弧類でない
    next_char = text[pos + len(ending):pos + len(ending) + 1]
    if next_char in BRACKETS['open']:
        return False
        
    # 省略記号でない
    if ending == '.' and pos + 3 <= len(text):
        if text[pos:pos + 3] == '...':
            return False
            
    # 括弧内の文末でない
    stack = []
    for i in range(pos):
        if text[i] in BRACKETS['open']:
            stack.append(text[i])
        elif text[i] in BRACKETS['close']:
            if stack and BRACKETS['open'].index(stack[-1]) == BRACKETS['close'].index(text[i]):
                stack.pop()
                
    return len(stack) == 0  # 括弧が閉じている場合のみTrue

def find_next_sentence_end(text, start, language, max_end):
    """指定された開始位置から次の文末を探す"""
    endings = SENTENCE_ENDINGS[language]
    
    for i in range(start, min(max_end, len(text))):
        for ending in endings:
            if text[i:i+len(ending)] == ending:
                # 文末の妥当性をチェック
                if is_real_sentence_ending(text, i, ending):
                    return i + len(ending)
    
    return None

def split_text(text, min_chunk_size=CHUNK_SIZE, max_chunk_size=MAX_CHUNK_SIZE):
    """テキストを適切なサイズに分割する改良版"""
    debug_print("Starting advanced text splitting...")
    debug_print(f"Text length: {len(text)}")
    
    if len(text) <= min_chunk_size:
        debug_print("Text is smaller than minimum chunk size, returning as is")
        return [text]

    language = detect_language(text)
    
    chunks = []
    start = 0

    while start < len(text):
        debug_print(f"Processing chunk starting at position {start}")
        
        # 最小チャンクサイズ以降の文末を探す
        chunk_end = find_next_sentence_end(
            text, 
            start + min_chunk_size, 
            language, 
            min(start + max_chunk_size, len(text))
        )
        
        # 文末が見つからない場合は最大チャンクサイズまでで区切る
        if chunk_end is None:
            chunk_end = min(start + max_chunk_size, len(text))
        
        # チャンクを作成
        chunk = text[start:chunk_end]
        chunks.append(chunk)
        
        # デバッグ情報
        debug_print(f"Chunk created: {len(chunk)} characters")
        debug_print(f"Chunk start: {start}, Chunk end: {chunk_end}")
        
        # 次の開始位置を設定
        start = chunk_end
    
    debug_print(f"Split text into {len(chunks)} chunks")
    return chunks

def call_openai_api(client, prompt, text, retries=MAX_RETRIES):
    """OpenAI APIを呼び出す（リトライ機能付き）"""
    debug_print("Preparing API request...")
    request_content = f"{prompt}\n\n{text}"
    print("\n=== API Request ===")
    print(request_content)
    print("==================")

    for attempt in range(retries):
        try:
            debug_print(f"Attempt {attempt + 1} of {retries}")
            completion = client.chat.completions.create(
                model="gpt-4o-mini",
                messages=[
                    {"role": "user", "content": request_content}
                ]
            )
            response = completion.choices[0].message.content
            debug_print("API request successful")
            print("\n=== API Response ===")
            print(response)
            print("===================")
            return response
        except Exception as e:
            debug_print(f"API request failed: {str(e)}")
            if attempt == retries - 1:
                raise e
            time.sleep(RETRY_DELAY * (attempt + 1))
    return None

def update_task_status(cursor, task_id, status, error_message=None, result_knowledge_id=None):
    """タスクのステータスを更新する"""
    debug_print(f"Updating task {task_id} status to {status}")
    if status == 'error' and error_message:
        cursor.execute("""
            UPDATE tasks 
            SET status = ?,
                error_message = ?
            WHERE id = ?
        """, (status, error_message, task_id))
    elif status == 'completed' and result_knowledge_id:
        cursor.execute("""
            UPDATE tasks 
            SET status = ?,
                result_knowledge_id = ?
            WHERE id = ?
        """, (status, result_knowledge_id, task_id))
    else:
        cursor.execute("""
            UPDATE tasks 
            SET status = ?
            WHERE id = ?
        """, (status, task_id))
    debug_print("Task status updated")

def main():
    debug_print("Starting main process")
    debug_print(f"データベースパス: {DATABASE_PATH}")

    debug_print("Initializing OpenAI client")
    # OpenAIクライアントを初期化
    client = openai.OpenAI(api_key=API_KEY)

    debug_print("Connecting to database")
    # SQLiteデータベースに接続
    conn = sqlite3.connect(DATABASE_PATH)
    cursor = conn.cursor()

    try:
        debug_print("Fetching pending/processing task")
        # pendingまたはprocessingのタスクを1件取得（プロンプトIDも含める）
        cursor.execute("""
            SELECT t.id, t.source_type, t.source_id, t.prompt_content, t.source_text, t.prompt_id as prompt_id, t.status, t.group_id
            FROM tasks t
            -- LEFT JOIN prompts p ON p.content = t.prompt_content AND p.deleted = 0
            WHERE t.status IN ('pending', 'processing')
            ORDER BY
                CASE t.status
                    WHEN 'processing' THEN 1
                    WHEN 'pending' THEN 2
                END,
                t.created_at
            LIMIT 1
        """)
        task = cursor.fetchone()

        if task:
            task_id, source_type, source_id, prompt_content, source_text, prompt_id, current_status, group_id = task
            print(f"Processing task ID: {task_id}")
            print(f"Source Type: {source_type}")
            print(f"Current Status: {current_status}")
            print(f"Prompt Content: {prompt_content}")
            print(f"Source Text: {source_text}")
            print(f"Prompt ID: {prompt_id}")

            debug_print("Checking if task needs status update")
            # タスクのステータスを処理中に更新（まだpendingの場合のみ）
            if current_status == 'pending':
                update_task_status(cursor, task_id, 'processing')
                conn.commit()
                debug_print("Task status updated to processing")

            debug_print("Starting text splitting")
            # テキストを分割
            text_chunks = split_text(source_text)
            print(f"\n=== Split into {len(text_chunks)} chunks ===")
            for i, chunk in enumerate(text_chunks, 1):
                print(f"\nChunk {i}/{len(text_chunks)}:")
                print(chunk)
                print("=" * 50)

            debug_print("Processing chunks")
            all_responses = []

            # 各チャンクを処理
            for i, chunk in enumerate(text_chunks):
                debug_print(f"Processing chunk {i+1}/{len(text_chunks)}")
                print(f"\nProcessing chunk {i+1}/{len(text_chunks)}")
                
                try:
                    # APIを呼び出し
                    response = call_openai_api(client, prompt_content, chunk)
                    if response:
                        all_responses.append(response)
                    
                    # レートリミット対策
                    if i < len(text_chunks) - 1:
                        debug_print(f"Waiting {API_RATE_LIMIT} seconds before next chunk")
                        time.sleep(API_RATE_LIMIT)
                except Exception as e:
                    debug_print(f"Error processing chunk {i+1}: {str(e)}")
                    print(f"Error processing chunk {i+1}: {e}")
                    continue

            if not all_responses:
                debug_print("No successful responses from API")
                raise Exception("No successful responses from API")

            debug_print("Combining responses")
            # 全ての応答を結合
            answer = "\n\n".join(all_responses)
            print("\n=== Final Combined Response ===")
            print(answer)
            print("=============================")

            debug_print("Fetching source reference")
            # ソースタイプに応じてreferenceを取得
            if source_type == 'record':
                cursor.execute("SELECT title, reference FROM record WHERE id = ?", (source_id,))
                parent_type = 'record'
                question = "(プレーンナレッジからの作成)"
            elif source_type == 'knowledge':
                cursor.execute("SELECT title, reference FROM knowledge WHERE id = ?", (source_id,))
                parent_type = 'knowledge'
                question = "(ナレッジからの作成)"
            else:
                debug_print(f"Invalid source type: {source_type}")
                raise Exception(f"Invalid source type: {source_type}")

            record = cursor.fetchone()
            if not record:
                debug_print(f"Source not found for ID: {source_id}")
                raise Exception(f"Source not found for ID: {source_id}")

            # (TBD)title, referenceについては、タスク登録した後にrecordを編集した場合は、実行時の想定から変更されてしまうが、軽微と考えてTBD。
            title = record[0]
            reference = record[1] or ""  # referenceがNULLの場合は空文字列を使用
            current_time = datetime.datetime.now()

            debug_print("Inserting knowledge")
            # knowledgeテーブルにデータを挿入（referenceも含める）
            cursor.execute("""
                INSERT INTO knowledge (
                    title,
                    question,
                    answer,
                    reference,
                    parent_id,
                    parent_type,
                    prompt_id,
                    group_id,
                    created_by,
                    created_at,
                    updated_at,
                    deleted
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'admin', ?, ?, 0)
            """, (
                title,                          # タイトル（ソースから）
                question,                       # 質問（ソースタイプに応じて変更）
                answer,                         # 回答（AI応答）
                reference,                      # 参照情報（ソースから）
                source_id,                      # 親ID
                parent_type,                    # 親タイプ（動的）
                prompt_id,                      # プロンプトID
                group_id,                       # グループID（タスクから）
                current_time,                   # 作成日時
                current_time                    # 更新日時
            ))

            debug_print("Updating task status to completed")
            # タスクのステータスを完了に更新
            update_task_status(cursor, task_id, 'completed', result_knowledge_id=cursor.lastrowid)
            conn.commit()
            print(f"Knowledge created successfully with ID: {cursor.lastrowid}")

        else:
            debug_print("No pending or processing tasks found")
            print("No pending or processing tasks found.")

    except Exception as e:
        debug_print(f"Error occurred: {str(e)}")
        # エラーメッセージをtasksテーブルに保存
        if 'task_id' in locals():
            update_task_status(cursor, task_id, 'failed', error_message=str(e))
            conn.commit()
        print(f"Error: {e}")

    finally:
        debug_print("Closing database connection")
        conn.close()

if __name__ == "__main__":
    main()