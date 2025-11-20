CREATE TABLE IF NOT EXISTS poems (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT UNIQUE,
    title TEXT,
    body TEXT,
    created_at TEXT
);

CREATE TABLE IF NOT EXISTS slams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT UNIQUE,
    title TEXT,
    happened_on TEXT
);

CREATE TABLE IF NOT EXISTS slam_poems (
    slam_id INTEGER,
    poem_id INTEGER,
    position INTEGER,
    PRIMARY KEY(slam_id, poem_id)
);

CREATE TABLE IF NOT EXISTS tetrastychs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    published_on TEXT,
    body TEXT
);
