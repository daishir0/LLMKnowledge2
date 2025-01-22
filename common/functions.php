<?php
// functions.php に追加

function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

function search($pdo, $table, $searchTerm, $columns) {
    $conditions = [];
    $params = [];
    
    foreach ($columns as $column) {
        $conditions[] = "$column LIKE :search_$column";
        $params[":search_$column"] = "%$searchTerm%";
    }
    
    $sql = "SELECT * FROM $table 
            WHERE deleted = 0 
            AND (" . implode(' OR ', $conditions) . ")
            ORDER BY updated_at DESC";
            
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPagination($total, $perPage, $currentPage) {
    $totalPages = ceil($total / $perPage);
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    return [
        'total' => $total,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'start' => $start,
        'end' => $end
    ];
}

function getHistory($pdo, $table, $id) {
    $stmt = $pdo->prepare("
        SELECT h.*
        FROM {$table}_history h
        WHERE h.{$table}_id = :id
        ORDER BY h.created_at DESC
    ");
    $stmt->execute([':id' => $id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function logHistory($pdo, $table, $id, $data) {
    try {
        // テーブルごとの有効なカラムを定義
        $validColumns = [
            'record' => ['title', 'text', 'reference'],
            'knowledge' => ['title', 'question', 'answer', 'reference'],
            'prompts' => ['title', 'content']
        ];

        // 履歴テーブル名とIDカラム名のマッピング
        $historyMap = [
            'prompts' => ['table' => 'prompt', 'id_column' => 'prompt_id'],
            'record' => ['table' => 'record', 'id_column' => 'record_id'],
            'knowledge' => ['table' => 'knowledge', 'id_column' => 'knowledge_id']
        ];

        // テーブルの存在確認
        if (!isset($validColumns[$table])) {
            throw new Exception("Invalid table name: $table");
        }

        // 必要なカラムのみを抽出
        $tableColumns = $validColumns[$table];
        $filteredData = array_intersect_key($data, array_flip($tableColumns));

        // 必須カラムの存在確認
        foreach ($tableColumns as $column) {
            if (!isset($filteredData[$column]) && !in_array($column, ['reference'])) {
                throw new Exception("Missing required column: $column");
            }
        }

        // カラムとプレースホルダーを構築
        $columnNames = array_keys($filteredData);
        $columns = implode(', ', $columnNames);
        $placeholders = implode(', ', array_map(function($col) {
            return ":param_$col";
        }, $columnNames));

        // 履歴テーブル名とIDカラム名を取得
        $historyTable = $historyMap[$table]['table'] . '_history';
        $idColumn = $historyMap[$table]['id_column'];

        // SQLクエリを構築
        $sql = "INSERT INTO {$historyTable}
                ({$idColumn}, $columns, modified_by, created_at)
                VALUES (:table_id, $placeholders, :modified_by, datetime('now', 'localtime'))";

        // パラメータを設定
        $params = [];
        foreach ($filteredData as $key => $value) {
            $params[":param_$key"] = $value;
        }
        $params[':table_id'] = $id;
        $params[':modified_by'] = getCurrentUser();

        // デバッグログ
        $log = date('Y-m-d H:i:s') . " Debug - Table: $table (History: $historyTable)\n";
        $log .= date('Y-m-d H:i:s') . " Debug - SQL: " . $sql . "\n";
        $log .= date('Y-m-d H:i:s') . " Debug - Params: " . print_r($params, true) . "\n";
        file_put_contents(dirname(__FILE__) . '/logs.txt', $log, FILE_APPEND);

        // クエリを実行
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . print_r($pdo->errorInfo(), true));
        }

        if (!$stmt->execute($params)) {
            throw new Exception("Failed to execute statement: " . print_r($stmt->errorInfo(), true));
        }

        return true;

    } catch (Exception $e) {
        $error = date('Y-m-d H:i:s') . " Error - {$e->getMessage()}\n";
        $error .= date('Y-m-d H:i:s') . " Trace: {$e->getTraceAsString()}\n";
        file_put_contents(dirname(__FILE__) . '/logs.txt', $error, FILE_APPEND);
        return false;
    }
}