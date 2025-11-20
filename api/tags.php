<?php
// /api/tags.php
header("Content-Type: application/json; charset=utf-8");
$config = require __DIR__ . '/../config.php';

try {
    $db = new PDO("sqlite:" . $config['db_file']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Pobierz wszystkie tagi z liczbą wierszy
    $query = "
        SELECT 
            t.id,
            t.name,
            t.slug,
            COUNT(pt.poem_id) as poem_count
        FROM tags t
        LEFT JOIN poem_tags pt ON t.id = pt.tag_id
        GROUP BY t.id
        ORDER BY t.name ASC
    ";
    
    $tags = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}