#!/bin/bash

# スクリプトの絶対パス、必要に応じて変えること
SCRIPT_PATH="/var/www/html/LLMKnowledge2/common/task2knowledge.py"
LOCK_FILE="/tmp/task2knowledge.lock"

# ロックファイルが存在し、対応するプロセスが実行中の場合は終了
if [ -f "$LOCK_FILE" ]; then
    # ロックファイルに記録されたPIDのプロセスが存在するか確認
    LOCK_PID=$(cat "$LOCK_FILE")
    if ps -p "$LOCK_PID" > /dev/null 2>&1; then
        echo "Previous task2knowledge.py is still running. Exiting."
        exit 1
    else
        # 古いロックファイルを削除
        rm "$LOCK_FILE"
    fi
fi

# 現在のプロセスIDをロックファイルに書き込む
echo $$ > "$LOCK_FILE"

# Python スクリプトを実行
python3 "$SCRIPT_PATH"

# スクリプト終了時にロックファイルを削除
rm "$LOCK_FILE"