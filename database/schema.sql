CREATE TABLE IF NOT EXISTS lotteries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    numbers_count INTEGER NOT NULL,
    max_number INTEGER NOT NULL,
    stoloto_game TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS draws (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lottery_id INTEGER NOT NULL,
    draw_number INTEGER NOT NULL,
    draw_date TEXT NOT NULL,
    numbers TEXT NOT NULL,
    fetched_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (lottery_id) REFERENCES lotteries(id),
    UNIQUE (lottery_id, draw_number)
);

CREATE INDEX IF NOT EXISTS idx_draws_lottery_date ON draws(lottery_id, draw_date DESC);
CREATE INDEX IF NOT EXISTS idx_draws_lottery_number ON draws(lottery_id, draw_number DESC);
