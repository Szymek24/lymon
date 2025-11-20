<?php
session_start();
$config = require __DIR__ . '/../config.php';

if (empty($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

try {
    $db = new PDO("sqlite:" . $config['db_file']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Błąd bazy: " . htmlspecialchars($e->getMessage()));
}

// Ogólne statystyki
$total_poems = $db->query("SELECT COUNT(*) FROM poems")->fetchColumn();
$total_views = $db->query("SELECT COUNT(*) FROM poem_views")->fetchColumn();
$total_tags = $db->query("SELECT COUNT(*) FROM tags")->fetchColumn();
$total_slams = $db->query("SELECT COUNT(*) FROM slams")->fetchColumn();

// Top 10 najpopularniejszych
$popular = $db->query("
    SELECT p.id, p.slug, p.title, COUNT(pv.id) as views
    FROM poems p
    LEFT JOIN poem_views pv ON p.id = pv.poem_id
    GROUP BY p.id
    ORDER BY views DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Ostatnie 30 dni
$recent_views = $db->query("
    SELECT 
        DATE(viewed_at) as date,
        COUNT(*) as views
    FROM poem_views
    WHERE viewed_at >= datetime('now', '-30 days')
    GROUP BY DATE(viewed_at)
    ORDER BY date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Popularne tagi
$popular_tags = $db->query("
    SELECT t.name, t.slug, COUNT(pt.poem_id) as count
    FROM tags t
    LEFT JOIN poem_tags pt ON t.id = pt.tag_id
    GROUP BY t.id
    ORDER BY count DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <title>Statystyki - Panel</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .stats-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); gap:16px; margin:20px 0; }
    .stat-card { background:#fff; border:1px solid #ddd; padding:20px; border-radius:8px; text-align:center; }
    .stat-number { font-size:36px; font-weight:bold; color:#0066cc; }
    .stat-label { color:#666; font-size:14px; margin-top:8px; }
    .chart-container { background:#fff; border:1px solid #ddd; padding:20px; margin:20px 0; border-radius:8px; }
    .calendar { display:grid; grid-template-columns:repeat(7, 1fr); gap:4px; margin:20px 0; }
    .calendar-day { aspect-ratio:1; background:#f5f5f5; border:1px solid #ddd; display:flex; align-items:center; justify-content:center; font-size:12px; position:relative; cursor:pointer; }
    .calendar-day.has-poems { background:#d4edda; border-color:#28a745; font-weight:bold; }
    .calendar-day:hover { background:#e3e3e3; }
    .calendar-header { font-weight:bold; padding:8px; text-align:center; background:#f0f0f0; }
    .tag-cloud { display:flex; flex-wrap:wrap; gap:10px; margin:20px 0; }
    .tag-item { background:#e9ecef; padding:8px 16px; border-radius:20px; font-size:14px; }
    .tag-count { color:#666; font-weight:bold; margin-left:6px; }
    table { width:100%; border-collapse:collapse; margin:20px 0; }
    th, td { padding:10px; text-align:left; border-bottom:1px solid #ddd; }
    th { background:#f5f5f5; font-weight:bold; }
    nav a { margin-right:8px; }
    .month-nav { display:flex; gap:10px; align-items:center; margin:10px 0; }
    .month-nav button { padding:6px 12px; }
  </style>
</head>
<body>
<nav>
  <a href="dashboard.php">Wiersze</a> |
  <a href="slams.php">Slamy</a> |
  <a href="tetrastychs.php">Tetrastychy</a> |
  <a href="stats.php"><strong>📊 Statystyki</strong></a> |
  <a href="logout.php">Wyloguj</a>
</nav>

<h1>📊 Statystyki i Kalendarz</h1>

<h2>Podsumowanie</h2>
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-number"><?php echo (int)$total_poems; ?></div>
    <div class="stat-label">Wiersze</div>
  </div>
  <div class="stat-card">
    <div class="stat-number"><?php echo (int)$total_views; ?></div>
    <div class="stat-label">Wyświetlenia</div>
  </div>
  <div class="stat-card">
    <div class="stat-number"><?php echo (int)$total_tags; ?></div>
    <div class="stat-label">Tagi</div>
  </div>
  <div class="stat-card">
    <div class="stat-number"><?php echo (int)$total_slams; ?></div>
    <div class="stat-label">Slamy</div>
  </div>
</div>

<div class="chart-container">
  <h2>📈 Wyświetlenia (ostatnie 30 dni)</h2>
  <canvas id="viewsChart" width="800" height="300"></canvas>
</div>

<div class="chart-container">
  <h2>🏆 Najpopularniejsze wiersze</h2>
  <?php if (empty($popular)): ?>
    <p>Brak danych o wyświetleniach.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Tytuł</th>
          <th>Wyświetlenia</th>
          <th>Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($popular as $i => $poem): ?>
          <tr>
            <td><?php echo $i + 1; ?></td>
            <td><?php echo htmlspecialchars($poem['title'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><strong><?php echo (int)$poem['views']; ?></strong></td>
            <td><a href="dashboard.php#poem-<?php echo (int)$poem['id']; ?>">Edytuj</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="chart-container">
  <h2>🏷️ Popularne tagi</h2>
  <div class="tag-cloud">
    <?php foreach ($popular_tags as $tag): ?>
      <div class="tag-item">
        #<?php echo htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8'); ?>
        <span class="tag-count">(<?php echo (int)$tag['count']; ?>)</span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="chart-container">
  <h2>📅 Kalendarz publikacji</h2>
  <div class="month-nav">
    <button onclick="changeMonth(-1)">← Poprzedni</button>
    <span id="currentMonth" style="font-weight:bold;"></span>
    <button onclick="changeMonth(1)">Następny →</button>
  </div>
  <div id="calendarGrid"></div>
</div>

<script>
// Dane dla wykresów
const recentViews = <?php echo json_encode($recent_views); ?>;

// Prosty wykres ASCII/blokowy
const canvas = document.getElementById('viewsChart');
const ctx = canvas.getContext('2d');

function drawChart() {
  const w = canvas.width;
  const h = canvas.height;
  const padding = 40;
  const barWidth = (w - padding * 2) / recentViews.length;
  
  // Tło
  ctx.fillStyle = '#f9f9f9';
  ctx.fillRect(0, 0, w, h);
  
  if (recentViews.length === 0) {
    ctx.fillStyle = '#666';
    ctx.font = '16px sans-serif';
    ctx.fillText('Brak danych o wyświetleniach', w/2 - 100, h/2);
    return;
  }
  
  const maxViews = Math.max(...recentViews.map(v => v.views), 1);
  
  // Słupki
  recentViews.forEach((item, i) => {
    const barHeight = (item.views / maxViews) * (h - padding * 2);
    const x = padding + i * barWidth;
    const y = h - padding - barHeight;
    
    ctx.fillStyle = '#0066cc';
    ctx.fillRect(x + 2, y, barWidth - 4, barHeight);
    
    // Wartość
    ctx.fillStyle = '#333';
    ctx.font = '11px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(item.views, x + barWidth/2, y - 5);
    
    // Data
    ctx.save();
    ctx.translate(x + barWidth/2, h - padding + 15);
    ctx.rotate(-Math.PI/4);
    ctx.fillStyle = '#666';
    ctx.font = '10px sans-serif';
    ctx.fillText(item.date.substring(5), 0, 0);
    ctx.restore();
  });
}

drawChart();

// Kalendarz
let currentYear = new Date().getFullYear();
let currentMonth = new Date().getMonth(); // 0-11

function changeMonth(delta) {
  currentMonth += delta;
  if (currentMonth < 0) { currentMonth = 11; currentYear--; }
  if (currentMonth > 11) { currentMonth = 0; currentYear++; }
  loadCalendar();
}

async function loadCalendar() {
  const monthNames = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec',
                      'Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
  
  document.getElementById('currentMonth').textContent = 
    monthNames[currentMonth] + ' ' + currentYear;
  
  // Pobierz dane z API
  const response = await fetch(`/api/stats.php?type=calendar&year=${currentYear}&month=${currentMonth + 1}`);
  const data = await response.json();
  
  renderCalendar(data.days);
}

function renderCalendar(days) {
  const grid = document.getElementById('calendarGrid');
  const firstDay = new Date(currentYear, currentMonth, 1);
  const lastDay = new Date(currentYear, currentMonth + 1, 0);
  const startDayOfWeek = (firstDay.getDay() + 6) % 7; // Poniedziałek = 0
  
  const daysInMonth = lastDay.getDate();
  
  // Nagłówki dni tygodnia
  const headers = ['Pn','Wt','Śr','Cz','Pt','So','Nd'];
  let html = '<div class="calendar">';
  headers.forEach(day => {
    html += `<div class="calendar-header">${day}</div>`;
  });
  
  // Puste komórki przed pierwszym dniem
  for (let i = 0; i < startDayOfWeek; i++) {
    html += '<div class="calendar-day"></div>';
  }
  
  // Dni miesiąca
  const daysMap = {};
  days.forEach(d => {
    const dayNum = new Date(d.date).getDate();
    daysMap[dayNum] = d;
  });
  
  for (let day = 1; day <= daysInMonth; day++) {
    const dayData = daysMap[day];
    const hasPoems = dayData && dayData.count > 0;
    const cls = hasPoems ? 'calendar-day has-poems' : 'calendar-day';
    const title = hasPoems ? `${dayData.count} wierszy: ${dayData.titles.join(', ')}` : '';
    html += `<div class="${cls}" title="${title}">${day}`;
    if (hasPoems) html += `<br><small>${dayData.count}</small>`;
    html += '</div>';
  }
  
  html += '</div>';
  grid.innerHTML = html;
}

loadCalendar();
</script>

</body>
</html>