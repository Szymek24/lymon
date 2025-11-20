-- upgrade.sql - Dodanie tagów i statystyk do istniejącej bazy

-- Tabela tagów
CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    slug TEXT UNIQUE NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Tabela łącząca wiersze z tagami (Many-to-Many)
CREATE TABLE IF NOT EXISTS poem_tags (
    poem_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    PRIMARY KEY (poem_id, tag_id),
    FOREIGN KEY (poem_id) REFERENCES poems(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

-- Tabela statystyk wyświetleń
CREATE TABLE IF NOT EXISTS poem_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poem_id INTEGER NOT NULL,
    viewed_at TEXT DEFAULT CURRENT_TIMESTAMP,
    ip_hash TEXT,
    user_agent TEXT,
    FOREIGN KEY (poem_id) REFERENCES poems(id) ON DELETE CASCADE
);

-- Indeksy dla lepszej wydajności
CREATE INDEX IF NOT EXISTS idx_poem_tags_poem ON poem_tags(poem_id);
CREATE INDEX IF NOT EXISTS idx_poem_tags_tag ON poem_tags(tag_id);
CREATE INDEX IF NOT EXISTS idx_poem_views_poem ON poem_views(poem_id);
CREATE INDEX IF NOT EXISTS idx_poem_views_date ON poem_views(viewed_at);
CREATE INDEX IF NOT EXISTS idx_poems_created ON poems(created_at);
CREATE INDEX IF NOT EXISTS idx_tags_name ON tags(name);

-- Widok pomocniczy - wiersze z liczbą wyświetleń
CREATE VIEW IF NOT EXISTS poems_with_stats AS
SELECT 
    p.*,
    COUNT(DISTINCT pv.id) as view_count,
    GROUP_CONCAT(t.name, ',') as tag_names,
    GROUP_CONCAT(t.id, ',') as tag_ids
FROM poems p
LEFT JOIN poem_views pv ON p.id = pv.poem_id
LEFT JOIN poem_tags pt ON p.id = pt.poem_id
LEFT JOIN tags t ON pt.tag_id = t.id
GROUP BY p.id;