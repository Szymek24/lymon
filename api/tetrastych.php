<?php
header("Content-Type: application/json; charset=utf-8");
$config = require __DIR__ . '/../config.php';
$db = new PDO("sqlite:" . $config['db_file']);

$rows = $db->query("SELECT * FROM tetrastychs ORDER BY published_on DESC")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
