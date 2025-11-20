<?php
// /api/track_view.php - Śledzenie wyświetleń wierszy
header("Content-Type: application/json; charset=utf-8");
$config = require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$poem_id = isset($input['poem_id']) ? (int)$input['poem_id'] : 0;

if ($poem_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid poem ID']);
    exit;
}

try {
    $db = new PDO("sqlite:" . $config['db_file']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Sprawdź czy wiersz istnieje
    $check = $db->prepare("SELECT id FROM poems WHERE id = ?");
    $check->execute([$poem_id]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Poem not found']);
        exit;
    }
    
    // Hash IP (dla prywatności - nie przechowujemy pełnego IP)
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip_hash = hash('sha256', $ip . date('Y-m-d')); // Zmienia się codziennie
    
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    // Zapisz wyświetlenie
    $stmt = $db->prepare("
        INSERT INTO poem_views (poem_id, viewed_at, ip_hash, user_agent)
        VALUES (?, datetime('now'), ?, ?)
    ");
    $stmt->execute([$poem_id, $ip_hash, substr($user_agent, 0, 255)]);
    
    echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}