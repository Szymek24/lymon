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

function make_slug($title) {
    $base = @iconv('UTF-8', 'ASCII//TRANSLIT', $title);
    if ($base === false) $base = $title;
    $slug = strtolower(preg_replace('/[^a-z0-9]+/','-', $base));
    $slug = trim($slug, '-');
    if ($slug === '') $slug = 'slam-'.time();
    return $slug;
}

/* Dodaj SLAM */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_slam'])) {
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $date  = isset($_POST['happened_on']) ? trim($_POST['happened_on']) : ''; // YYYY-MM-DD
    if ($title === '' || $date === '') {
        $error = "Tytuł i data są wymagane.";
    } else {
        $slug = make_slug($title . '-' . $date);
        $stmt = $db->prepare("INSERT INTO slams (slug, title, happened_on) VALUES (?, ?, ?)");
        $stmt->execute(array($slug, $title, $date));
        header("Location: slams.php");
        exit;
    }
}

/* Edytuj SLAM (tytuł/data) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_slam'])) {
    $id    = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $date  = isset($_POST['happened_on']) ? trim($_POST['happened_on']) : '';
    if ($id <= 0 || $title === '' || $date === '') {
        $error = "Błędne dane do aktualizacji slamu.";
    } else {
        $stmt = $db->prepare("UPDATE slams SET title=?, happened_on=? WHERE id=?");
        $stmt->execute(array($title, $date, $id));
        header("Location: slams.php#slam-".$id);
        exit;
    }
}

/* Dodaj wiersz do slamu (append) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_poem_to_slam'])) {
    $slam_id = isset($_POST['slam_id']) ? (int)$_POST['slam_id'] : 0;
    $poem_id = isset($_POST['poem_id']) ? (int)$_POST['poem_id'] : 0;
    if ($slam_id > 0 && $poem_id > 0) {
        $stmt = $db->prepare("SELECT COALESCE(MAX(position),0) FROM slam_poems WHERE slam_id=?");
        $stmt->execute(array($slam_id));
        $maxpos = (int)$stmt->fetchColumn();
        $pos = $maxpos + 1;

        // uniknij duplikatu
        $chk = $db->prepare("SELECT 1 FROM slam_poems WHERE slam_id=? AND poem_id=?");
        $chk->execute(array($slam_id, $poem_id));
        if (!$chk->fetchColumn()) {
            $ins = $db->prepare("INSERT INTO slam_poems (slam_id, poem_id, position) VALUES (?, ?, ?)");
            $ins->execute(array($slam_id, $poem_id, $pos));
        }
        header("Location: slams.php#slam-".$slam_id);
        exit;
    } else {
        $error = "Wybierz slam i wiersz.";
    }
}

/* Przesuń wiersz w górę/dół */
function swap_positions($db, $slam_id, $poem_id, $dir) {
    // znajdź obecną pozycję
    $q = $db->prepare("SELECT position FROM slam_poems WHERE slam_id=? AND poem_id=?");
    $q->execute(array($slam_id, $poem_id));
    $pos = (int)$q->fetchColumn();
    if ($pos <= 0) return;

    // sąsiad
    $newPos = $dir === 'up' ? $pos - 1 : $pos + 1;

    // znajdź poem_id sąsiada
    $s = $db->prepare("SELECT poem_id FROM slam_poems WHERE slam_id=? AND position=?");
    $s->execute(array($slam_id, $newPos));
    $neighbor = $s->fetchColumn();

    if ($neighbor) {
        $db->beginTransaction();
        try {
            $a = $db->prepare("UPDATE slam_poems SET position=? WHERE slam_id=? AND poem_id=?");
            $a->execute(array($newPos, $slam_id, $poem_id));
            $b = $db->prepare("UPDATE slam_poems SET position=? WHERE slam_id=? AND poem_id=?");
            $b->execute(array($pos, $slam_id, $neighbor));
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
        }
    }
}

if (isset($_GET['move']) && isset($_GET['slam_id']) && isset($_GET['poem_id'])) {
    $dir = $_GET['move'] === 'up' ? 'up' : 'down';
    $slam_id = (int)$_GET['slam_id'];
    $poem_id = (int)$_GET['poem_id'];
    swap_positions($db, $slam_id, $poem_id, $dir);
    header("Location: slams.php#slam-".$slam_id);
    exit;
}

/* Usuń wiersz ze slamu */
if (isset($_GET['remove']) && isset($_GET['slam_id']) && isset($_GET['poem_id'])) {
    $slam_id = (int)$_GET['slam_id'];
    $poem_id = (int)$_GET['poem_id'];
    $del = $db->prepare("DELETE FROM slam_poems WHERE slam_id=? AND poem_id=?");
    $del->execute(array($slam_id, $poem_id));

    // zbij pozycje „dziury”
    $reorder = $db->prepare("UPDATE slam_poems SET position = position - 1 WHERE slam_id=? AND position > (
        SELECT COALESCE(position,0) FROM slam_poems WHERE slam_id=? AND poem_id=? LIMIT 1
    )");
    // powyższe po DELETE już nie zadziała; więc przeliczymy prościej:
    $rows = $db->prepare("SELECT poem_id FROM slam_poems WHERE slam_id=? ORDER BY position ASC");
    $rows->execute(array($slam_id));
    $list = $rows->fetchAll(PDO::FETCH_COLUMN);
    $i=1;
    foreach ($list as $pid) {
        $upd = $db->prepare("UPDATE slam_poems SET position=? WHERE slam_id=? AND poem_id=?");
        $upd->execute(array($i, $slam_id, $pid));
        $i++;
    }

    header("Location: slams.php#slam-".$slam_id);
    exit;
}

/* Dane do widoków */
$poems = $db->query("SELECT id, title FROM poems ORDER BY title COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
$slams = $db->query("SELECT * FROM slams ORDER BY happened_on DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

function slam_poems($db, $slam_id) {
    $stmt = $db->prepare("SELECT p.id, p.title, sp.position 
                          FROM slam_poems sp 
                          JOIN poems p ON p.id = sp.poem_id
                          WHERE sp.slam_id=?
                          ORDER BY sp.position ASC");
    $stmt->execute(array($slam_id));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <title>Panel — Slamy</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .row { background:#fff; border:1px solid #ddd; padding:10px; margin:10px 0; }
    .muted { color:#666; font-size:12px; }
    .inline { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .inline > * { flex:1 1 220px; }
    .small { max-width:260px; }
    .chips { display:flex; flex-wrap:wrap; gap:6px; }
    .chip  { border:1px solid #ddd; padding:6px 8px; border-radius:6px; background:#fafafa }
    .pos   { font-weight:bold; margin-right:6px; }
    .actions a { margin-right:6px; }
    nav a { margin-right:8px; }
    .anchor { scroll-margin-top: 80px; }
  </style>
</head>
<body>
<nav>
  <a href="dashboard.php">Wiersze</a> |
  <a href="slams.php"><strong>Slamy</strong></a> |
  <a href="tetrastychs.php">Tetrastychy</a> |
  <a href="stats.php">📊 Statystyki</a> |
  <a href="logout.php">Wyloguj</a>
</nav>

<h1>Slamy</h1>

<?php if (!empty($error)): ?>
  <p style="color:#b00"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<h2>Dodaj slam</h2>
<form method="post" class="row">
  <div class="inline">
    <input type="text" name="title" placeholder="Tytuł slamu" required>
    <input class="small" type="date" name="happened_on" placeholder="Data (YYYY-MM-DD)" required>
  </div>
  <button name="add_slam">Dodaj slam</button>
</form>

<h2>Lista slamów</h2>
<?php if (!$slams): ?>
  <p>Brak slamów.</p>
<?php endif; ?>

<?php foreach ($slams as $s): ?>
  <div id="slam-<?php echo (int)$s['id']; ?>" class="row anchor">
    <div class="inline">
      <form method="post" class="inline" style="align-items:flex-end">
        <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
        <div>
          <label>Tytuł</label>
          <input type="text" name="title" value="<?php echo htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div>
          <label>Data (YYYY-MM-DD)</label>
          <input class="small" type="date" name="happened_on" value="<?php echo htmlspecialchars($s['happened_on'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div>
          <button name="update_slam">Zapisz slam</button>
        </div>
      </form>
    </div>

    <h3>Wiersze w slamiе</h3>
    <div class="chips">
      <?php $list = slam_poems($db, (int)$s['id']); ?>
      <?php if (!$list): ?>
        <span class="muted">Brak wierszy w tym slamie.</span>
      <?php else: foreach ($list as $w): ?>
        <div class="chip">
          <span class="pos">#<?php echo (int)$w['position']; ?></span>
          <?php echo htmlspecialchars($w['title'], ENT_QUOTES, 'UTF-8'); ?>
          <span class="actions">
            <a href="slams.php?move=up&amp;slam_id=<?php echo (int)$s['id']; ?>&amp;poem_id=<?php echo (int)$w['id']; ?>">↑</a>
            <a href="slams.php?move=down&amp;slam_id=<?php echo (int)$s['id']; ?>&amp;poem_id=<?php echo (int)$w['id']; ?>">↓</a>
            <a href="slams.php?remove=1&amp;slam_id=<?php echo (int)$s['id']; ?>&amp;poem_id=<?php echo (int)$w['id']; ?>"
               onclick="return confirm('Usunąć wiersz ze slamu?')">Usuń</a>
          </span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <h4>Dodaj istniejący wiersz do slamu</h4>
    <form method="post" class="inline">
      <input type="hidden" name="slam_id" value="<?php echo (int)$s['id']; ?>">
      <select name="poem_id" required>
        <option value="">— wybierz wiersz —</option>
        <?php foreach ($poems as $p): ?>
          <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'); ?></option>
        <?php endforeach; ?>
      </select>
      <button name="add_poem_to_slam">Dodaj do slamu</button>
    </form>
  </div>
<?php endforeach; ?>

</body>
</html>
