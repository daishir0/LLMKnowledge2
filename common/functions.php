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
        SELECT h.*, u.username as modified_by_user
        FROM {$table}_history h
        LEFT JOIN users u ON h.modified_by = u.id
        WHERE h.{$table}_id = :id
        ORDER BY h.created_at DESC
    ");
    $stmt->execute([':id' => $id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function logHistory($pdo, $table, $id, $data) {
    $columns = implode(', ', array_keys($data));
    $values = ':' . implode(', :', array_keys($data));
    
    $sql = "INSERT INTO {$table}_history 
            ({$table}_id, $columns, modified_by) 
            VALUES (:record_id, $values, :modified_by)";
            
    $stmt = $pdo->prepare($sql);
    $params = array_combine(
        array_map(function($key) { return ":$key"; }, array_keys($data)),
        array_values($data)
    );
    $params[':record_id'] = $id;
    $params[':modified_by'] = getCurrentUser();
    
    return $stmt->execute($params);
}