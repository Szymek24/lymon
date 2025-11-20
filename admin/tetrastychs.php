<?php
session_start();
$config = require __DIR__ . '/../config.php';

date_default_timezone_set('Europe/Warsaw');

try {
    $db = new PDO("sqlite:" . $config['db_file']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo "Błąd bazy: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

if (empty($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

/* Dodaj tetrastych */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tetra'])) {
    $date = isset($_POST['published_on']) ? trim($_POST['published_on']) : ''; // YYYY-MM-DD
    $body = isset($_POST['body']) ? trim($_POST['body']) : '';
    if ($date === '' || $body === '') {
        $error = "Data i treść są wymagane.";
    } else {
        $stmt = $db->prepare("INSERT INTO tetrastychs (published_on, body) VALUES (?, ?)");
        $stmt->execute(array($date, $body));
        header("Location: tetrastychs.php");
        exit;
    }
}

/* Aktualizuj tetrastych */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tetra'])) {
    $id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $date = isset($_POST['published_on']) ? trim($_POST['published_on']) : '';
    $body = isset($_POST['body']) ? trim($_POST['body']) : '';
    if ($id <= 0 || $date === '' || $body === '') {
        $error = "Błędne dane do aktualizacji.";
    } else {
        $stmt = $db->prepare("UPDATE tetrastychs SET published_on=?, body=? WHERE id=?");
        $stmt->execute(array($date, $body, $id));
        header("Location: tetrastychs.php#tetra-".$id);
        exit;
    }
}

/* Usuń tetrastych */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $id = (int)$_GET['delete'];
    $del = $db->prepare("DELETE FROM tetrastychs WHERE id=?");
    $del->execute(array($id));
    header("Location: tetrastychs.php");
    exit;
}

$items = $db->query("SELECT * FROM tetrastychs ORDER BY published_on DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <title>Panel — Tetrastychy</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .row { background:#fff; border:1px solid #ddd; padding:10px; margin:10px 0; }
    .muted { color:#666; font-size:12px; }
    .inline { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .inline > * { flex:1 1 220px; }
    .small { max-width:260px; }
    nav a { margin-right:8px; }
    .anchor { scroll-margin-top: 80px; }
    pre { white-space: pre-wrap; }
  </style>
</head>
<body>
<nav>
  <a href="dashboard.php">Wiersze</a> |
  <a href="slams.php">Slamy</a> |
  <a href="tetrastychs.php"><strong>Tetrastychy</strong></a> |
  <a href="stats.php">📊 Statystyki</a> |
  <a href="logout.php">Wyloguj</a>
</nav>

<h1>Tetrastych dzienny</h1>

<?php if (!empty($error)): ?>
  <p style="color:#b00"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<h2>Dodaj tetrastych</h2>
<form method="post" class="row">
  <div class="inline">
    <input class="small" type="date" name="published_on" placeholder="Data (YYYY-MM-DD)" required>
  </div>
  <textarea name="body" rows="6" placeholder="Treść (krótki wiersz — 4 wersy)" required></textarea>
  <button name="add_tetra">Dodaj</button>
</form>

<h2>Lista</h2>
<?php if (!$items): ?>
  <p>Brak tetrastychów.</p>
<?php endif; ?>

<?php foreach ($items as $t): ?>
  <div id="tetra-<?php echo (int)$t['id']; ?>" class="row anchor">
    <strong><?php echo htmlspecialchars($t['published_on'], ENT_QUOTES, 'UTF-8'); ?></strong>
    <pre><?php echo htmlspecialchars($t['body'], ENT_QUOTES, 'UTF-8'); ?></pre>

    <details>
      <summary>Edytuj</summary>
      <form method="post">
        <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
        <div class="inline">
          <input class="small" type="date" name="published_on" value="<?php echo htmlspecialchars($t['published_on'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <textarea name="body" rows="6" required><?php echo htmlspecialchars($t['body'], ENT_QUOTES, 'UTF-8'); ?></textarea>
        <button name="update_tetra">Zapisz</button>
        <a href="tetrastychs.php?delete=<?php echo (int)$t['id']; ?>" onclick="return confirm('Usunąć wpis?')">Usuń</a>
      </form>
    </details>
  </div>
<?php endforeach; ?>

</body>
</html>
