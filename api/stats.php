<?php
// /api/stats.php
header("Content-Type: application/json; charset=utf-8");
$config = require __DIR__ . '/../config.php';

try {
    $db = new PDO("sqlite:" . $config['db_file']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $type = $_GET['type'] ?? 'overview';
    
    switch($type) {
        case 'overview':
            // Ogólne statystyki
            $total_poems = $db->query("SELECT COUNT(*) FROM poems")->fetchColumn();
            $total_views = $db->query("SELECT COUNT(*) FROM poem_views")->fetchColumn();
            $total_tags = $db->query("SELECT COUNT(*) FROM tags")->fetchColumn();
            
            // Najpopularniejsze wiersze (top 10)
            $popular = $db->query("
                SELECT p.id, p.slug, p.title, COUNT(pv.id) as views
                FROM poems p
                LEFT JOIN poem_views pv ON p.id = pv.poem_id
                GROUP BY p.id
                ORDER BY views DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            // Ostatnie 7 dni - wyświetlenia per dzień
            $weekly = $db->query("
                SELECT 
                    DATE(viewed_at) as date,
                    COUNT(*) as views
                FROM poem_views
                WHERE viewed_at >= datetime('now', '-7 days')
                GROUP BY DATE(viewed_at)
                ORDER BY date ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'total_poems' => (int)$total_poems,
                'total_views' => (int)$total_views,
                'total_tags' => (int)$total_tags,
                'popular_poems' => $popular,
                'weekly_views' => $weekly
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;
            
        case 'calendar':
            // Kalendarz publikacji - wiersze per dzień
            $year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
            $month = isset($_GET['month']) ? (int)$_GET['month'] : null;
            
            $query = "
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as count,
                    GROUP_CONCAT(title, '|||') as titles,
                    GROUP_CONCAT(slug, '|||') as slugs
                FROM poems
                WHERE strftime('%Y', created_at) = ?
            ";
            
            $params = [sprintf('%04d', $year)];
            
            if ($month !== null) {
                $query .= " AND strftime('%m', created_at) = ?";
                $params[] = sprintf('%02d', $month);
            }
            
            $query .= " GROUP BY DATE(created_at) ORDER BY date ASC";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $days = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Przetwórz wyniki
            $calendar = [];
            foreach ($days as $day) {
                $calendar[] = [
                    'date' => $day['date'],
                    'count' => (int)$day['count'],
                    'titles' => $day['titles'] ? explode('|||', $day['titles']) : [],
                    'slugs' => $day['slugs'] ? explode('|||', $day['slugs']) : []
                ];
            }
            
            echo json_encode([
                'year' => $year,
                'month' => $month,
                'days' => $calendar
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;
            
        case 'poem':
            // Statystyki pojedynczego wiersza
            $poem_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if ($poem_id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid poem ID']);
                exit;
            }
            
            $views = $db->prepare("SELECT COUNT(*) FROM poem_views WHERE poem_id = ?");
            $views->execute([$poem_id]);
            $view_count = $views->fetchColumn();
            
            // Wyświetlenia w ostatnich 30 dniach
            $recent = $db->prepare("
                SELECT 
                    DATE(viewed_at) as date,
                    COUNT(*) as views
                FROM poem_views
                WHERE poem_id = ? AND viewed_at >= datetime('now', '-30 days')
                GROUP BY DATE(viewed_at)
                ORDER BY date ASC
            ");
            $recent->execute([$poem_id]);
            $recent_views = $recent->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'poem_id' => $poem_id,
                'total_views' => (int)$view_count,
                'recent_views' => $recent_views
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid type parameter']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}