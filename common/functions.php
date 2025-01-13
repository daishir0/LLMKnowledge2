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
        // 必要なカラムのみを抽出（categoryは除外）
        $validColumns = ['title', 'content'];
        $filteredData = array_intersect_key($data, array_flip($validColumns));
        
        // カラムとプレースホルダーを構築
        $columnNames = array_keys($filteredData);
        $columns = implode(', ', $columnNames);
        $placeholders = implode(', ', array_map(function($col) {
            return ":param_$col";
        }, $columnNames));
        
        // SQLクエリを構築
        $sql = "INSERT INTO {$table}_history
                ({$table}_id, $columns, modified_by, created_at)
                VALUES (:table_id, $placeholders, :modified_by, datetime('now', 'localtime'))";
        
        // パラメータを設定
        $params = [];
        foreach ($filteredData as $key => $value) {
            $params[":param_$key"] = $value;
        }
        $params[':table_id'] = $id;
        $params[':modified_by'] = getCurrentUser();
        
        // デバッグ用にSQLとパラメータを出力
        $log = date('Y-m-d H:i:s') . " Debug - SQL: " . $sql . "\n";
        $log .= date('Y-m-d H:i:s') . " Debug - Params: " . print_r($params, true) . "\n";
        file_put_contents(dirname(__FILE__) . '/logs.txt', $log, FILE_APPEND);
        
        // クエリを実行
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            $error = date('Y-m-d H:i:s') . " Debug - Prepare Error: " . print_r($pdo->errorInfo(), true) . "\n";
            file_put_contents(dirname(__FILE__) . '/logs.txt', $error, FILE_APPEND);
            return false;
        }
        
        $result = $stmt->execute($params);
        if (!$result) {
            $error = date('Y-m-d H:i:s') . " Debug - Execute Error: " . print_r($stmt->errorInfo(), true) . "\n";
            file_put_contents(dirname(__FILE__) . '/logs.txt', $error, FILE_APPEND);
        }
        return $result;
    } catch (PDOException $e) {
        $error = date('Y-m-d H:i:s') . " Debug - PDO Error: " . $e->getMessage() . "\n";
        $error .= date('Y-m-d H:i:s') . " Debug - Trace: " . $e->getTraceAsString() . "\n";
        file_put_contents(dirname(__FILE__) . '/logs.txt', $error, FILE_APPEND);
        return false;
    }
}