#!/bin/bash

# **cronでタスク登録をする場合**
# `crontab -e`で以下のようにタスクを登録します。（以下は毎分起動の場合）
# * * * * * /bin/bash /var/www/html/LLMKnowledge2/common/run_task2knowledge.sh

# もしanaconda等でPythonの環境を構築している場合には、以下のように環境を指定すること
# source /home/ec2-user/anaconda3/bin/activate base  # 'base'は使用している環境名

# スクリプトの絶対パスと、ロックファイル。初期設定時に変えること(TODO)
# 同時実行プロセス数の設定（デフォルト値: 3）
MAX_PARALLEL_PROCESSES=3
# プロセス間の待機時間（秒）
WAIT_INTERVAL=3

source /home/ec2-user/anaconda3/bin/activate base

SCRIPT_PATH="/var/www/html/LLMKnowledge2/common/task2knowledge.py"
BASE_LOCK_FILE="/tmp/task2knowledge"

# 現在実行中のプロセス数をカウント
count_running_processes() {
    running_count=0
    for i in $(seq 1 $MAX_PARALLEL_PROCESSES); do
        lock_file="${BASE_LOCK_FILE}_${i}.lock"
        if [ -f "$lock_file" ]; then
            pid=$(cat "$lock_file")
            if ps -p "$pid" > /dev/null 2>&1; then
                running_count=$((running_count + 1))
            else
                rm "$lock_file"
            fi
        fi
    done
    echo $running_count
}

# 利用可能なプロセススロットを探す
find_available_slot() {
    for i in $(seq 1 $MAX_PARALLEL_PROCESSES); do
        lock_file="${BASE_LOCK_FILE}_${i}.lock"
        if [ ! -f "$lock_file" ]; then
            echo $i
            return
        fi
        pid=$(cat "$lock_file")
        if ! ps -p "$pid" > /dev/null 2>&1; then
            rm "$lock_file"
            echo $i
            return
        fi
    done
    echo 0
}

# メイン処理
main() {
    running_processes=$(count_running_processes)
    
    for i in $(seq 1 $((MAX_PARALLEL_PROCESSES - running_processes))); do
        slot=$(find_available_slot)
        if [ "$slot" -eq 0 ]; then
            echo "No available slots"
            exit 1
        fi
        
        lock_file="${BASE_LOCK_FILE}_${slot}.lock"
        echo $$ > "$lock_file"
        
        # バックグラウンドでPythonスクリプトを実行
        {
            python3 "$SCRIPT_PATH"
            rm "$lock_file"
        } &
        
        # 次のプロセス起動までの待機
        sleep $WAIT_INTERVAL
    done
}

main