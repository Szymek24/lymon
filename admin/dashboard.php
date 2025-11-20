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

function localDTtoUTCZ($local) {
    if (!$local) return gmdate('Y-m-d\TH:i:s\Z');
    try {
        $dt = new DateTime($local, new DateTimeZone('Europe/Warsaw'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s\Z');
    } catch (Exception $e) {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

function utcZtoLocalInput($utc) {
    if (!$utc) return '';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Warsaw'));
        return $dt->format('Y-m-d\TH:i');
    } catch (Exception $e) {
        return '';
    }
}

function make_slug($title) {
    $base = @iconv('UTF-8', 'ASCII//TRANSLIT', $title);
    if ($base === false) $base = $title;
    $slug = strtolower(preg_replace('/[^a-z0-9]+/','-', $base));
    $slug = trim($slug, '-');
    if ($slug === '') $slug = 'wiersz-'.time();
    return $slug;
}

function process_tags($db, $poem_id, $tags_string) {
    $db->prepare("DELETE FROM poem_tags WHERE poem_id = ?")->execute([$poem_id]);
    
    if (empty(trim($tags_string))) return;
    
    $tag_names = array_map('trim', explode(',', $tags_string));
    $tag_names = array_filter($tag_names);
    
    foreach ($tag_names as $tag_name) {
        $tag_slug = make_slug($tag_name);
        
        $stmt = $db->prepare("SELECT id FROM tags WHERE slug = ?");
        $stmt->execute([$tag_slug]);
        $tag_id = $stmt->fetchColumn();
        
        if (!$tag_id) {
            $ins = $db->prepare("INSERT INTO tags (name, slug) VALUES (?, ?)");
            $ins->execute([$tag_name, $tag_slug]);
            $tag_id = $db->lastInsertId();
        }
        
        $db->prepare("INSERT OR IGNORE INTO poem_tags (poem_id, tag_id) VALUES (?, ?)")
           ->execute([$poem_id, $tag_id]);
    }
}

/* === SETUP === */
if (isset($_GET['setup'])) {
    $setupPath = __DIR__ . '/../setup.sql';
    $upgradePath = __DIR__ . '/../upgrade.sql';
    
    if (is_readable($setupPath)) {
        $sql = file_get_contents($setupPath);
        try {
            $db->exec($sql);
            echo "Tabele podstawowe utworzone!<br>";
        } catch (Exception $e) {
            echo "Błąd SQL (setup): " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
    
    if (is_readable($upgradePath)) {
        $sql = file_get_contents($upgradePath);
        try {
            $db->exec($sql);
            echo "Tabele tagów i statystyk dodane!<br>";
        } catch (Exception $e) {
            echo "Błąd SQL (upgrade): " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
    
    echo "<a href=\"dashboard.php\">Powrót do panelu</a>";
    exit;
}

/* === LOGOWANIE === */
if (empty($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$success = '';
$error = '';

/* === BULK DELETE === */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    $ids = $_POST['poem_ids'] ?? [];
    if (!empty($ids)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $db->prepare("DELETE FROM poems WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = "Usunięto " . count($ids) . " wierszy.";
    }
}

/* === BULK TAG === */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_tag'])) {
    $ids = $_POST['poem_ids'] ?? [];
    $tag_action = $_POST['tag_action'] ?? 'add';
    $tag_name = trim($_POST['bulk_tag_name'] ?? '');
    
    if (!empty($ids) && !empty($tag_name)) {
        $tag_slug = make_slug($tag_name);
        
        // Znajdź lub utwórz tag
        $stmt = $db->prepare("SELECT id FROM tags WHERE slug = ?");
        $stmt->execute([$tag_slug]);
        $tag_id = $stmt->fetchColumn();
        
        if (!$tag_id) {
            $ins = $db->prepare("INSERT INTO tags (name, slug) VALUES (?, ?)");
            $ins->execute([$tag_name, $tag_slug]);
            $tag_id = $db->lastInsertId();
        }
        
        if ($tag_action === 'add') {
            foreach ($ids as $poem_id) {
                $db->prepare("INSERT OR IGNORE INTO poem_tags (poem_id, tag_id) VALUES (?, ?)")
                   ->execute([$poem_id, $tag_id]);
            }
            $success = "Dodano tag '$tag_name' do " . count($ids) . " wierszy.";
        } else {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $db->prepare("DELETE FROM poem_tags WHERE tag_id = ? AND poem_id IN ($placeholders)");
            $stmt->execute(array_merge([$tag_id], $ids));
            $success = "Usunięto tag '$tag_name' z " . count($ids) . " wierszy.";
        }
    }
}

/* === DELETE POEM === */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $id = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM poems WHERE id = ?");
    $stmt->execute([$id]);
    $success = "Wiersz usunięty.";
}

/* === DODAWANIE WIERSZA === */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_poem'])) {
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $body  = isset($_POST['body']) ? trim($_POST['body']) : '';
    $tags  = isset($_POST['tags']) ? trim($_POST['tags']) : '';
    $created_local = isset($_POST['created_at']) ? $_POST['created_at'] : '';
    $created_iso = localDTtoUTCZ($created_local);

    if ($title === '') {
        $error = "Tytuł jest wymagany.";
    } else {
        $slug = make_slug($title);
        $stmt = $db->prepare("INSERT INTO poems (slug, title, body, created_at) VALUES (?, ?, ?, ?)");
        $stmt->execute(array($slug, $title, $body, $created_iso));
        $poem_id = $db->lastInsertId();
        
        process_tags($db, $poem_id, $tags);
        
        $success = "Wiersz dodany!";
        header("Location: dashboard.php?success=" . urlencode($success));
        exit;
    }
}

/* === AKTUALIZACJA WIERSZA (AJAX) === */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    header('Content-Type: application/json');
    $id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $field  = $_POST['field'] ?? '';
    $value  = $_POST['value'] ?? '';

    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
        exit;
    }

    try {
        if ($field === 'title') {
            $stmt = $db->prepare("UPDATE poems SET title=? WHERE id=?");
            $stmt->execute([$value, $id]);
        } elseif ($field === 'body') {
            $stmt = $db->prepare("UPDATE poems SET body=? WHERE id=?");
            $stmt->execute([$value, $id]);
        } elseif ($field === 'created_at') {
            $created_iso = localDTtoUTCZ($value);
            $stmt = $db->prepare("UPDATE poems SET created_at=? WHERE id=?");
            $stmt->execute([$created_iso, $id]);
        } elseif ($field === 'tags') {
            process_tags($db, $id, $value);
        }
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* === EXPORT === */
if (isset($_GET['export'])) {
    $format = $_GET['format'] ?? 'json';
    
    $poems = $db->query("SELECT * FROM poems ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($poems as &$poem) {
        $stmt = $db->prepare("
            SELECT t.name
            FROM tags t
            INNER JOIN poem_tags pt ON t.id = pt.tag_id
            WHERE pt.poem_id = ?
        ");
        $stmt->execute([$poem['id']]);
        $poem['tags'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="wiersze-' . date('Y-m-d') . '.json"');
        echo json_encode($poems, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wiersze-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        fputcsv($output, ['ID', 'Tytuł', 'Treść', 'Data', 'Tagi', 'Slug']);
        
        foreach ($poems as $p) {
            fputcsv($output, [
                $p['id'],
                $p['title'],
                $p['body'],
                $p['created_at'],
                implode(', ', $p['tags']),
                $p['slug']
            ]);
        }
        fclose($output);
    }
    exit;
}

/* === IMPORT === */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($file['tmp_name']);
        $data = json_decode($content, true);
        
        if (is_array($data)) {
            $imported = 0;
            foreach ($data as $poem) {
                if (empty($poem['title'])) continue;
                
                $slug = make_slug($poem['title']);
                $stmt = $db->prepare("INSERT INTO poems (slug, title, body, created_at) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $slug,
                    $poem['title'],
                    $poem['body'] ?? '',
                    $poem['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z')
                ]);
                
                $poem_id = $db->lastInsertId();
                
                if (!empty($poem['tags'])) {
                    $tags = is_array($poem['tags']) ? implode(', ', $poem['tags']) : $poem['tags'];
                    process_tags($db, $poem_id, $tags);
                }
                
                $imported++;
            }
            $success = "Zaimportowano $imported wierszy.";
        } else {
            $error = "Błędny format pliku JSON.";
        }
    }
}

/* === POBIERZ WIERSZE === */
$search = $_GET['search'] ?? '';
$tag_filter = $_GET['tag'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

$query = "SELECT * FROM poems WHERE 1=1";
$params = [];

if ($search !== '') {
    $query .= " AND (title LIKE ? OR body LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($tag_filter !== '') {
    $query .= " AND id IN (
        SELECT pt.poem_id FROM poem_tags pt
        INNER JOIN tags t ON pt.tag_id = t.id
        WHERE t.slug = ?
    )";
    $params[] = $tag_filter;
}

switch ($sort) {
    case 'oldest':
        $query .= " ORDER BY created_at ASC, id ASC";
        break;
    case 'az':
        $query .= " ORDER BY title COLLATE NOCASE ASC";
        break;
    case 'za':
        $query .= " ORDER BY title COLLATE NOCASE DESC";
        break;
    case 'popular':
        $query = "SELECT p.*, COUNT(pv.id) as view_count FROM poems p
                  LEFT JOIN poem_views pv ON p.id = pv.poem_id
                  WHERE 1=1";
        if ($search !== '') {
            $query .= " AND (p.title LIKE ? OR p.body LIKE ?)";
        }
        if ($tag_filter !== '') {
            $query .= " AND p.id IN (
                SELECT pt.poem_id FROM poem_tags pt
                INNER JOIN tags t ON pt.tag_id = t.id
                WHERE t.slug = ?
            )";
        }
        $query .= " GROUP BY p.id ORDER BY view_count DESC";
        break;
    default: // newest
        $query .= " ORDER BY created_at DESC, id DESC";
}

$stmt = $db->prepare($query);
$stmt->execute($params);
$poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Dodaj tagi i statystyki do każdego wiersza
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
    
    if (!isset($poem['view_count'])) {
        $views = $db->prepare("SELECT COUNT(*) FROM poem_views WHERE poem_id = ?");
        $views->execute([$poem['id']]);
        $poem['view_count'] = $views->fetchColumn();
    }
}

// Pobierz wszystkie tagi do filtrowania
$all_tags = $db->query("
    SELECT t.*, COUNT(pt.poem_id) as poem_count
    FROM tags t
    LEFT JOIN poem_tags pt ON t.id = pt.tag_id
    GROUP BY t.id
    ORDER BY t.name
")->fetchAll(PDO::FETCH_ASSOC);

$success_msg = $_GET['success'] ?? $success;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <title>Panel - Wiersze</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    :root {
      --primary: #0066cc;
      --danger: #dc3545;
      --success: #28a745;
      --warning: #ffc107;
      --gray-light: #f8f9fa;
      --gray: #6c757d;
      --border: #dee2e6;
    }
    
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    
    .toast {
      position: fixed;
      top: 20px;
      right: 20px;
      background: white;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 16px 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      z-index: 1000;
      animation: slideIn 0.3s ease;
    }
    
    .toast.success { border-left: 4px solid var(--success); }
    .toast.error { border-left: 4px solid var(--danger); }
    
    @keyframes slideIn {
      from { transform: translateX(400px); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }
    
    .controls-bar {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
      margin: 16px 0;
      padding: 16px;
      background: var(--gray-light);
      border-radius: 6px;
    }
    
    .controls-bar > * { flex: 0 0 auto; }
    .controls-bar input[type="search"] { flex: 1 1 300px; min-width: 200px; }
    
    .bulk-actions {
      display: none;
      position: fixed;
      bottom: 20px;
      left: 50%;
      transform: translateX(-50%);
      background: white;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 16px 24px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.2);
      z-index: 100;
      animation: slideUp 0.3s ease;
    }
    
    .bulk-actions.show { display: flex; gap: 12px; align-items: center; }
    
    @keyframes slideUp {
      from { transform: translateX(-50%) translateY(100px); opacity: 0; }
      to { transform: translateX(-50%) translateY(0); opacity: 1; }
    }
    
    .poem-row {
      background: white;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 16px;
      margin: 12px 0;
      transition: all 0.2s;
    }
    
    .poem-row:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .poem-row.selected { border-color: var(--primary); background: #f0f7ff; }
    
    .poem-header {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      cursor: pointer;
    }
    
    .poem-header input[type="checkbox"] {
      margin-top: 4px;
      width: 18px;
      height: 18px;
      cursor: pointer;
    }
    
    .poem-info { flex: 1; }
    
    .poem-title {
      font-size: 18px;
      font-weight: 600;
      margin: 0 0 6px 0;
      color: #333;
    }
    
    .poem-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      font-size: 13px;
      color: var(--gray);
      margin-bottom: 8px;
    }
    
    .poem-meta > span { display: flex; align-items: center; gap: 4px; }
    
    .poem-actions {
      display: flex;
      gap: 8px;
      margin-top: 8px;
    }
    
    .btn {
      padding: 6px 12px;
      border: 1px solid var(--border);
      background: white;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
      transition: all 0.2s;
    }
    
    .btn:hover { background: var(--gray-light); }
    .btn-primary { background: var(--primary); color: white; border-color: var(--primary); }
    .btn-primary:hover { background: #0052a3; }
    .btn-danger { background: var(--danger); color: white; border-color: var(--danger); }
    .btn-danger:hover { background: #c82333; }
    .btn-sm { padding: 4px 8px; font-size: 12px; }
    
    .tags-inline {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin: 8px 0;
    }
    
    .tag-badge {
      background: #e3f2fd;
      color: #1976d2;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 12px;
      border: 1px solid #90caf9;
    }
    
    .poem-body {
      display: none;
      margin-top: 12px;
      padding-top: 12px;
      border-top: 1px solid var(--border);
    }
    
    .poem-body.show { display: block; }
    
    .poem-body pre {
      white-space: pre-wrap;
      font-family: Georgia, serif;
      font-size: 14px;
      line-height: 1.7;
      color: #333;
      margin: 0;
      max-height: 300px;
      overflow-y: auto;
      padding: 12px;
      background: var(--gray-light);
      border-radius: 4px;
    }
    
    .editable {
      border: 1px dashed transparent;
      padding: 4px 8px;
      border-radius: 4px;
      transition: all 0.2s;
      cursor: text;
    }
    
    .editable:hover { border-color: var(--border); background: var(--gray-light); }
    .editable:focus { border-color: var(--primary); background: white; outline: none; }
    
    textarea.editable {
      width: 100%;
      min-height: 200px;
      font-family: Georgia, serif;
      resize: vertical;
    }
    
    .preview-panel {
      position: fixed;
      right: 0;
      top: 0;
      bottom: 0;
      width: 400px;
      background: white;
      border-left: 1px solid var(--border);
      padding: 20px;
      overflow-y: auto;
      transform: translateX(100%);
      transition: transform 0.3s ease;
      z-index: 50;
      box-shadow: -2px 0 12px rgba(0,0,0,0.1);
    }
    
    .preview-panel.show { transform: translateX(0); }
    
    .preview-close {
      position: absolute;
      top: 10px;
      right: 10px;
      font-size: 24px;
      cursor: pointer;
      color: var(--gray);
    }
    
    .preview-content {
      margin-top: 30px;
      font-family: Georgia, serif;
      white-space: pre-wrap;
      line-height: 1.8;
    }
    
    .modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.5);
      z-index: 200;
      align-items: center;
      justify-content: center;
    }
    
    .modal.show { display: flex; }
    
    .modal-content {
      background: white;
      border-radius: 8px;
      padding: 24px;
      max-width: 500px;
      width: 90%;
      box-shadow: 0 8px 24px rgba(0,0,0,0.3);
    }
    
    .modal h3 { margin-top: 0; }
    
    .form-group {
      margin: 16px 0;
    }
    
    .form-group label {
      display: block;
      margin-bottom: 6px;
      font-weight: 500;
    }
    
    .form-group input,
    .form-group select {
      width: 100%;
      padding: 8px 12px;
      border: 1px solid var(--border);
      border-radius: 4px;
    }
    
    .stat-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 10px;
      background: var(--gray-light);
      border-radius: 12px;
      font-size: 13px;
    }
    
    .expand-icon {
      color: var(--gray);
      font-size: 12px;
      transition: transform 0.2s;
    }
    
    .expand-icon.expanded { transform: rotate(180deg); }
    
    @media (max-width: 768px) {
      .preview-panel { width: 100%; }
      .controls-bar { flex-direction: column; align-items: stretch; }
      .controls-bar > * { width: 100%; }
    }
  </style>
</head>
<body>
  <nav style="border-bottom: 1px solid var(--border); padding: 12px 20px; background: white;">
    <a href="dashboard.php"><strong>Wiersze</strong></a> |
    <a href="slams.php">Slamy</a> |
    <a href="tetrastychs.php">Tetrastychy</a> |
    <a href="stats.php">📊 Statystyki</a> |
    <a href="logout.php">Wyloguj</a>
  </nav>

  <main style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
      <h1 style="margin: 0;">Panel administracyjny</h1>
      <div style="display: flex; gap: 8px;">
        <button class="btn" onclick="document.getElementById('addPoemForm').style.display='block'; document.getElementById('newTitle').focus()">
          ➕ Nowy wiersz
        </button>
        <button class="btn" onclick="document.getElementById('importModal').classList.add('show')">
          📥 Import
        </button>
        <button class="btn" onclick="window.location.href='?export&format=json'">
          📤 Export JSON
        </button>
        <button class="btn" onclick="window.location.href='?export&format=csv'">
          📤 Export CSV
        </button>
      </div>
    </div>

    <!-- Formularz dodawania (początkowo ukryty) -->
    <div id="addPoemForm" style="display: none; background: var(--gray-light); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <h3 style="margin: 0;">Nowy wiersz</h3>
        <button class="btn btn-sm" onclick="document.getElementById('addPoemForm').style.display='none'">✕</button>
      </div>
      <form method="post">
        <div class="form-group">
          <input type="text" id="newTitle" name="title" placeholder="Tytuł" required style="width: 100%; padding: 10px; font-size: 16px;">
        </div>
        <div class="form-group">
          <input type="datetime-local" name="created_at" placeholder="Data publikacji (opcjonalnie)" style="width: 100%; padding: 8px;">
        </div>
        <div class="form-group">
          <textarea name="body" rows="8" placeholder="Treść wiersza..." style="width: 100%; padding: 10px; font-family: Georgia, serif;" id="newBody"></textarea>
        </div>
        <div class="form-group">
          <input type="text" name="tags" placeholder="Tagi (oddzielone przecinkami, np: miłość, natura)" style="width: 100%; padding: 8px;">
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
          <button name="add_poem" class="btn btn-primary">Dodaj wiersz</button>
          <button type="button" class="btn" onclick="showPreview(document.getElementById('newTitle').value, document.getElementById('newBody').value)">
            👁️ Podgląd
          </button>
        </div>
      </form>
    </div>

    <!-- Kontrolki wyszukiwania i filtrowania -->
    <div class="controls-bar">
      <input type="search" id="searchInput" placeholder="🔍 Szukaj w tytule lub treści..." 
             value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
             onkeyup="debounceSearch()">
      
      <select id="tagFilter" onchange="applyFilters()">
        <option value="">Wszystkie tagi</option>
        <?php foreach ($all_tags as $tag): ?>
          <option value="<?php echo htmlspecialchars($tag['slug'], ENT_QUOTES, 'UTF-8'); ?>"
                  <?php echo $tag_filter === $tag['slug'] ? 'selected' : ''; ?>>
            #<?php echo htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo $tag['poem_count']; ?>)
          </option>
        <?php endforeach; ?>
      </select>
      
      <select id="sortSelect" onchange="applyFilters()">
        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Najnowsze</option>
        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Najstarsze</option>
        <option value="az" <?php echo $sort === 'az' ? 'selected' : ''; ?>>A-Z</option>
        <option value="za" <?php echo $sort === 'za' ? 'selected' : ''; ?>>Z-A</option>
        <option value="popular" <?php echo $sort === 'popular' ? 'selected' : ''; ?>>Najpopularniejsze</option>
      </select>
      
      <button class="btn btn-sm" onclick="selectAll()">✓ Zaznacz wszystkie</button>
      <button class="btn btn-sm" onclick="deselectAll()">✗ Odznacz</button>
      
      <span style="color: var(--gray); font-size: 14px;">
        Znaleziono: <strong><?php echo count($poems); ?></strong> wierszy
      </span>
    </div>

    <?php if (!$poems): ?>
      <div style="text-align: center; padding: 60px; color: var(--gray);">
        <p style="font-size: 48px; margin: 0;">📭</p>
        <p>Nie znaleziono wierszy.</p>
      </div>
    <?php else: ?>
      <!-- Lista wierszy -->
      <div id="poemsList">
        <?php foreach($poems as $p): ?>
          <div class="poem-row" data-id="<?php echo (int)$p['id']; ?>">
            <div class="poem-header" onclick="togglePoemBody(<?php echo (int)$p['id']; ?>)">
              <input type="checkbox" class="poem-checkbox" value="<?php echo (int)$p['id']; ?>" 
                     onclick="event.stopPropagation(); updateBulkActions()">
              
              <div class="poem-info">
                <h3 class="poem-title">
                  <?php echo htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'); ?>
                  <span class="expand-icon" id="expand-<?php echo (int)$p['id']; ?>">▼</span>
                </h3>
                
                <div class="poem-meta">
                  <span>📅 <?php echo date('Y-m-d H:i', strtotime(utcZtoLocalInput($p['created_at']))); ?></span>
                  <span>👁️ <?php echo (int)$p['view_count']; ?> wyświetleń</span>
                  <span>🔗 <code><?php echo htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8'); ?></code></span>
                </div>
                
                <?php if (!empty($p['tags'])): ?>
                  <div class="tags-inline">
                    <?php foreach ($p['tags'] as $tag): ?>
                      <span class="tag-badge">#<?php echo htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            
            <div class="poem-body" id="body-<?php echo (int)$p['id']; ?>">
              <div class="poem-actions">
                <button class="btn btn-sm" onclick="editInline(<?php echo (int)$p['id']; ?>); event.stopPropagation()">
                  ✏️ Edytuj
                </button>
                <button class="btn btn-sm" onclick="showPreview('<?php echo htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'); ?>', `<?php echo htmlspecialchars($p['body'], ENT_QUOTES, 'UTF-8'); ?>`); event.stopPropagation()">
                  👁️ Podgląd
                </button>
                <button class="btn btn-sm btn-danger" onclick="deletePoem(<?php echo (int)$p['id']; ?>, '<?php echo htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'); ?>'); event.stopPropagation()">
                  🗑️ Usuń
                </button>
                <a class="btn btn-sm" href="../#/wiersz/<?php echo htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank">
                  🔗 Zobacz na stronie
                </a>
              </div>
              
              <div style="margin-top: 12px;">
                <strong>Tytuł:</strong>
                <div contenteditable="false" class="editable" id="title-<?php echo (int)$p['id']; ?>" 
                     data-id="<?php echo (int)$p['id']; ?>" data-field="title">
                  <?php echo htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
              </div>
              
              <div style="margin-top: 12px;">
                <strong>Data:</strong>
                <input type="datetime-local" class="editable" id="date-<?php echo (int)$p['id']; ?>"
                       data-id="<?php echo (int)$p['id']; ?>" data-field="created_at"
                       value="<?php echo htmlspecialchars(utcZtoLocalInput($p['created_at']), ENT_QUOTES, 'UTF-8'); ?>"
                       style="border: 1px solid var(--border); padding: 6px; border-radius: 4px;"
                       disabled>
              </div>
              
              <div style="margin-top: 12px;">
                <strong>Tagi:</strong>
                <input type="text" class="editable" id="tags-<?php echo (int)$p['id']; ?>"
                       data-id="<?php echo (int)$p['id']; ?>" data-field="tags"
                       value="<?php echo htmlspecialchars(implode(', ', array_column($p['tags'], 'name')), ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="miłość, natura, wieczór"
                       style="width: 100%; border: 1px solid var(--border); padding: 6px; border-radius: 4px;"
                       disabled>
              </div>
              
              <div style="margin-top: 12px;">
                <strong>Treść:</strong>
                <textarea class="editable" id="content-<?php echo (int)$p['id']; ?>"
                          data-id="<?php echo (int)$p['id']; ?>" data-field="body"
                          style="width: 100%; min-height: 200px; border: 1px solid var(--border); padding: 10px; border-radius: 4px; font-family: Georgia, serif;"
                          disabled><?php echo htmlspecialchars($p['body'], ENT_QUOTES, 'UTF-8'); ?></textarea>
              </div>
              
              <div style="margin-top: 12px; display: none;" id="save-<?php echo (int)$p['id']; ?>">
                <button class="btn btn-primary" onclick="saveInline(<?php echo (int)$p['id']; ?>)">💾 Zapisz zmiany</button>
                <button class="btn" onclick="cancelEdit(<?php echo (int)$p['id']; ?>)">❌ Anuluj</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Bulk actions bar -->
    <div class="bulk-actions" id="bulkActions">
      <span id="bulkCount">0 zaznaczonych</span>
      <button class="btn btn-sm" onclick="showBulkTagModal()">🏷️ Dodaj/usuń tag</button>
      <button class="btn btn-sm btn-danger" onclick="bulkDelete()">🗑️ Usuń zaznaczone</button>
    </div>

  </main>

  <!-- Preview Panel -->
  <div class="preview-panel" id="previewPanel">
    <span class="preview-close" onclick="closePreview()">✕</span>
    <h2 id="previewTitle" style="font-family: Georgia, serif;"></h2>
    <div class="preview-content" id="previewContent"></div>
  </div>

  <!-- Bulk Tag Modal -->
  <div class="modal" id="bulkTagModal">
    <div class="modal-content">
      <h3>Masowa edycja tagów</h3>
      <form method="post" id="bulkTagForm">
        <div class="form-group">
          <label>Akcja:</label>
          <select name="tag_action" required>
            <option value="add">Dodaj tag do zaznaczonych</option>
            <option value="remove">Usuń tag z zaznaczonych</option>
          </select>
        </div>
        <div class="form-group">
          <label>Tag:</label>
          <input type="text" name="bulk_tag_name" placeholder="np. miłość" required>
        </div>
        <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px;">
          <button type="button" class="btn" onclick="document.getElementById('bulkTagModal').classList.remove('show')">Anuluj</button>
          <button type="submit" name="bulk_tag" class="btn btn-primary">Wykonaj</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Import Modal -->
  <div class="modal" id="importModal">
    <div class="modal-content">
      <h3>Import wierszy</h3>
      <form method="post" enctype="multipart/form-data">
        <div class="form-group">
          <label>Wybierz plik JSON:</label>
          <input type="file" name="import_file" accept=".json" required>
          <p style="font-size: 12px; color: var(--gray); margin-top: 6px;">
            Format: tablica obiektów z polami: title, body, created_at (opcjonalne), tags (opcjonalne)
          </p>
        </div>
        <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px;">
          <button type="button" class="btn" onclick="document.getElementById('importModal').classList.remove('show')">Anuluj</button>
          <button type="submit" class="btn btn-primary">Importuj</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Toast notifications
    function showToast(message, type = 'success') {
      const toast = document.createElement('div');
      toast.className = `toast ${type}`;
      toast.textContent = message;
      document.body.appendChild(toast);
      
      setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
      }, 3000);
    }

    <?php if ($success_msg): ?>
      showToast(<?php echo json_encode($success_msg); ?>, 'success');
    <?php endif; ?>

    <?php if ($error): ?>
      showToast(<?php echo json_encode($error); ?>, 'error');
    <?php endif; ?>

    // Debounced search
    let searchTimeout;
    function debounceSearch() {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        applyFilters();
      }, 400);
    }

    function applyFilters() {
      const search = document.getElementById('searchInput').value;
      const tag = document.getElementById('tagFilter').value;
      const sort = document.getElementById('sortSelect').value;
      
      let url = 'dashboard.php?';
      if (search) url += 'search=' + encodeURIComponent(search) + '&';
      if (tag) url += 'tag=' + encodeURIComponent(tag) + '&';
      url += 'sort=' + sort;
      
      window.location.href = url;
    }

    // Toggle poem body
    function togglePoemBody(id) {
      const body = document.getElementById('body-' + id);
      const icon = document.getElementById('expand-' + id);
      
      if (body.classList.contains('show')) {
        body.classList.remove('show');
        icon.classList.remove('expanded');
      } else {
        body.classList.add('show');
        icon.classList.add('expanded');
      }
    }

    // Bulk actions
    function updateBulkActions() {
      const checked = document.querySelectorAll('.poem-checkbox:checked');
      const bulkBar = document.getElementById('bulkActions');
      const count = document.getElementById('bulkCount');
      
      if (checked.length > 0) {
        bulkBar.classList.add('show');
        count.textContent = checked.length + ' zaznaczonych';
      } else {
        bulkBar.classList.remove('show');
      }
      
      // Update row selection style
      document.querySelectorAll('.poem-row').forEach(row => {
        const checkbox = row.querySelector('.poem-checkbox');
        if (checkbox && checkbox.checked) {
          row.classList.add('selected');
        } else {
          row.classList.remove('selected');
        }
      });
    }

    function selectAll() {
      document.querySelectorAll('.poem-checkbox').forEach(cb => cb.checked = true);
      updateBulkActions();
    }

    function deselectAll() {
      document.querySelectorAll('.poem-checkbox').forEach(cb => cb.checked = false);
      updateBulkActions();
    }

    function showBulkTagModal() {
      const checked = document.querySelectorAll('.poem-checkbox:checked');
      if (checked.length === 0) {
        alert('Zaznacz przynajmniej jeden wiersz');
        return;
      }
      
      const form = document.getElementById('bulkTagForm');
      // Clear existing hidden inputs
      form.querySelectorAll('input[name="poem_ids[]"]').forEach(el => el.remove());
      
      // Add hidden inputs for selected IDs
      checked.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'poem_ids[]';
        input.value = cb.value;
        form.appendChild(input);
      });
      
      document.getElementById('bulkTagModal').classList.add('show');
    }

    function bulkDelete() {
      const checked = document.querySelectorAll('.poem-checkbox:checked');
      if (checked.length === 0) {
        alert('Zaznacz przynajmniej jeden wiersz');
        return;
      }
      
      if (!confirm(`Czy na pewno chcesz usunąć ${checked.length} zaznaczonych wierszy? Ta operacja jest nieodwracalna.`)) {
        return;
      }
      
      const form = document.createElement('form');
      form.method = 'POST';
      form.innerHTML = '<input type="hidden" name="bulk_delete" value="1">';
      
      checked.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'poem_ids[]';
        input.value = cb.value;
        form.appendChild(input);
      });
      
      document.body.appendChild(form);
      form.submit();
    }

    // Inline editing
    let editingId = null;
    let originalValues = {};

    function editInline(id) {
      if (editingId !== null && editingId !== id) {
        if (!confirm('Masz niezapisane zmiany w innym wierszu. Kontynuować?')) {
          return;
        }
        cancelEdit(editingId);
      }
      
      editingId = id;
      originalValues = {};
      
      // Enable editing
      ['title', 'date', 'tags', 'content'].forEach(field => {
        const el = document.getElementById(field + '-' + id);
        if (el) {
          originalValues[field] = el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' 
            ? el.value 
            : el.textContent;
          
          if (el.hasAttribute('contenteditable')) {
            el.setAttribute('contenteditable', 'true');
          } else {
            el.removeAttribute('disabled');
          }
          
          el.style.borderColor = 'var(--primary)';
          el.style.background = 'white';
        }
      });
      
      document.getElementById('save-' + id).style.display = 'block';
    }

    function cancelEdit(id) {
      // Restore original values
      ['title', 'date', 'tags', 'content'].forEach(field => {
        const el = document.getElementById(field + '-' + id);
        if (el && originalValues[field] !== undefined) {
          if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
            el.value = originalValues[field];
          } else {
            el.textContent = originalValues[field];
          }
          
          if (el.hasAttribute('contenteditable')) {
            el.setAttribute('contenteditable', 'false');
          } else {
            el.setAttribute('disabled', 'disabled');
          }
          
          el.style.borderColor = '';
          el.style.background = '';
        }
      });
      
      document.getElementById('save-' + id).style.display = 'none';
      editingId = null;
      originalValues = {};
    }

    async function saveInline(id) {
      const updates = {};
      
      ['title', 'created_at', 'tags', 'body'].forEach(field => {
        let elementId = field;
        if (field === 'body') elementId = 'content';
        if (field === 'created_at') elementId = 'date';
        
        const el = document.getElementById(elementId + '-' + id);
        if (el) {
          updates[field] = el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' 
            ? el.value 
            : el.textContent.trim();
        }
      });
      
      // Save each field
      for (const [field, value] of Object.entries(updates)) {
        const formData = new FormData();
        formData.append('ajax_update', '1');
        formData.append('id', id);
        formData.append('field', field);
        formData.append('value', value);
        
        const response = await fetch('dashboard.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (!result.success) {
          showToast('Błąd zapisywania pola ' + field + ': ' + result.error, 'error');
          return;
        }
      }
      
      showToast('Wiersz zaktualizowany!', 'success');
      
      // Disable editing
      ['title', 'date', 'tags', 'content'].forEach(field => {
        const el = document.getElementById(field + '-' + id);
        if (el) {
          if (el.hasAttribute('contenteditable')) {
            el.setAttribute('contenteditable', 'false');
          } else {
            el.setAttribute('disabled', 'disabled');
          }
          el.style.borderColor = '';
          el.style.background = '';
        }
      });
      
      // Update title in header
      const headerTitle = document.querySelector(`[data-id="${id}"] .poem-title`);
      if (headerTitle) {
        headerTitle.childNodes[0].textContent = updates.title + ' ';
      }
      
      document.getElementById('save-' + id).style.display = 'none';
      editingId = null;
      
      // Reload after 1 second to show updated tags
      setTimeout(() => location.reload(), 1000);
    }

    // Delete poem
    function deletePoem(id, title) {
      if (!confirm(`Czy na pewno chcesz usunąć wiersz "${title}"? Ta operacja jest nieodwracalna.`)) {
        return;
      }
      
      window.location.href = 'dashboard.php?delete=' + id;
    }

    // Preview
    function showPreview(title, body) {
      document.getElementById('previewTitle').textContent = title;
      document.getElementById('previewContent').textContent = body;
      document.getElementById('previewPanel').classList.add('show');
    }

    function closePreview() {
      document.getElementById('previewPanel').classList.remove('show');
    }

    // Close modals on outside click
    document.querySelectorAll('.modal').forEach(modal => {
      modal.addEventListener('click', (e) => {
        if (e.target === modal) {
          modal.classList.remove('show');
        }
      });
    });

    // Auto-save draft (optional - commented out for now)
    /*
    let autoSaveTimer;
    function startAutoSave() {
      clearTimeout(autoSaveTimer);
      autoSaveTimer = setTimeout(() => {
        if (editingId !== null) {
          // Save to localStorage as draft
          const draft = {
            id: editingId,
            title: document.getElementById('title-' + editingId).textContent,
            body: document.getElementById('content-' + editingId).value,
            tags: document.getElementById('tags-' + editingId).value,
            timestamp: Date.now()
          };
          localStorage.setItem('draft_' + editingId, JSON.stringify(draft));
          showToast('Szkic automatycznie zapisany', 'success');
        }
      }, 30000); // 30 seconds
    }
    */

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
      // Ctrl+S to save (when editing)
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        if (editingId !== null) {
          saveInline(editingId);
        }
      }
      
      // Escape to cancel edit
      if (e.key === 'Escape') {
        if (editingId !== null) {
          cancelEdit(editingId);
        }
        closePreview();
        document.querySelectorAll('.modal').forEach(m => m.classList.remove('show'));
      }
    });

    // Markdown support helper (basic)
    function applyMarkdown(textarea) {
      const text = textarea.value;
      // Simple markdown-like formatting
      const formatted = text
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')  // **bold**
        .replace(/\*(.+?)\*/g, '<em>$1</em>');             // *italic*
      
      // Show preview
      showPreview('Podgląd z formatowaniem', formatted);
    }
  </script>
</body>
</html>
