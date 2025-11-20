<?php
// /admin/setup.php
// Jednorazowy setup bazy bez logowania.

ini_set('display_errors', 1);
error_reporting(E_ALL);

$config = require __DIR__ . '/../config.php';

// Upewnij się, że plik bazy istnieje (SQLite utworzy jeśli ma uprawnienia w katalogu)
$dbFile = $config['db_file'];

// Spróbuj otworzyć bazę (utworzy się automatycznie, jeśli PHP może zapisać plik)
try {
    $db = new PDO("sqlite:" . $dbFile);
} catch (Exception $e) {
    http_response_code(500);
    echo "Błąd: nie można otworzyć/utworzyć bazy: " . htmlspecialchars($e->getMessage());
    exit;
}

// Wczytaj i wykonaj setup.sql
$setupPath = realpath(__DIR__ . '/../setup.sql');
if (!$setupPath || !is_readable($setupPath)) {
    http_response_code(500);
    echo "Błąd: nie znaleziono pliku setup.sql w katalogu głównym (../setup.sql).";
    exit;
}

$sql = file_get_contents($setupPath);
try {
    $db->exec($sql);
} catch (Exception $e) {
    http_response_code(500);
    echo "Błąd SQL podczas tworzenia tabel: " . htmlspecialchars($e->getMessage());
    exit;
}

// Krótka diagnoza
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$ok = in_array('poems', $tables, true) && in_array('slams', $tables, true) && in_array('tetrastychs', $tables, true);

?>
<!DOCTYPE html>
<html lang="pl">
<meta charset="utf-8">
<title>Setup bazy</title>
<body style="font-family:system-ui, sans-serif; padding:24px">
<h1>Setup bazy SQLite</h1>
<p>Plik bazy: <code><?=htmlspecialchars($dbFile)?></code></p>
<?php if ($ok): ?>
  <p style="color:green">✅ Tabele utworzone / istnieją: poems, slams, slam_poems, tetrastychs.</p>
  <p>Możesz przejść do panelu: <a href="login.php">/admin/login.php</a></p>
<?php else: ?>
  <p style="color:#b00">⚠️ Wygląda na to, że tabele nie powstały. Sprawdź uprawnienia pliku/katalogu i zawartość <code>setup.sql</code>.</p>
<?php endif; ?>
</body>
</html>
