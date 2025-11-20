<?php
header("Content-Type: application/json; charset=utf-8");
$config = require __DIR__ . '/../config.php';
$db = new PDO("sqlite:" . $config['db_file']);

$slams = $db->query("SELECT * FROM slams ORDER BY happened_on DESC")->fetchAll(PDO::FETCH_ASSOC);

foreach ($slams as &$slam) {
    $stmt = $db->prepare("SELECT p.id, p.slug, p.title, p.body, p.created_at 
                          FROM slam_poems sp 
                          JOIN poems p ON p.id = sp.poem_id 
                          WHERE sp.slam_id = ? ORDER BY sp.position ASC");
    $stmt->execute([$slam['id']]);
    $slam['poems'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode($slams, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
