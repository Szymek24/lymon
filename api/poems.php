<?php
// /api/poems.php (zaktualizowany)
header("Content-Type: application/json; charset=utf-8");
$config = require __DIR__ . '/../config.php';

try {
    $db = new PDO("sqlite:" . $config['db_file']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Opcjonalny filtr po tagu
    $tag = $_GET['tag'] ?? null;
    
    if ($tag) {
        // Filtruj wiersze po tagu
        $query = "
            SELECT DISTINCT
                p.id, p.slug, p.title, p.body, p.created_at,
                COUNT(DISTINCT pv.id) as view_count
            FROM poems p
            INNER JOIN poem_tags pt ON p.id = pt.poem_id
            INNER JOIN tags t ON pt.tag_id = t.id
            LEFT JOIN poem_views pv ON p.id = pv.poem_id
            WHERE t.slug = ?
            GROUP BY p.id
            ORDER BY p.created_at DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([$tag]);
        $poems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Wszystkie wiersze
        $query = "
            SELECT 
                p.id, p.slug, p.title, p.body, p.created_at,
                COUNT(DISTINCT pv.id) as view_count
            FROM poems p
            LEFT JOIN poem_views pv ON p.id = pv.poem_id
            GROUP BY p.id
            ORDER BY p.created_at DESC
        ";
        $poems = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Dodaj tagi do każdego wiersza
    foreach ($poems as &$poem) {
        $stmt = $db->prepare("
            SELECT t.id, t.name, t.slug
            FROM tags t
            INNER JOIN poem_tags pt ON t.id = pt.tag_id
            WHERE pt.poem_id = ?
            ORDER BY t.name
        ");
        $stmt->execute([$poem['id']]);
        $poem['tags'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $poem['view_count'] = (int)$poem['view_count'];
    }
    
    echo json_encode($poems, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}