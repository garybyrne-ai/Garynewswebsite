-- ME News Ireland — SQLite schema (idempotent)

CREATE TABLE IF NOT EXISTS users (
  id            TEXT PRIMARY KEY,
  email         TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  display_name  TEXT NOT NULL,
  handle        TEXT UNIQUE,
  role          TEXT NOT NULL DEFAULT 'member',      -- member | contributor | editor | admin
  is_verified   INTEGER NOT NULL DEFAULT 0,
  plan          TEXT NOT NULL DEFAULT 'free',        -- free | ME+
  title         TEXT,                                -- public job title for contributors
  desk          TEXT,                                -- National | Local | Business | Sport | Culture
  home_town     TEXT,
  home_county   TEXT,
  bio           TEXT,
  accent        TEXT,                                -- hue used for avatar/monogram
  reputation    INTEGER NOT NULL DEFAULT 50,
  created_at    TEXT NOT NULL,
  last_seen_at  TEXT
);

CREATE TABLE IF NOT EXISTS sessions (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    TEXT NOT NULL,
  token_hash TEXT UNIQUE NOT NULL,
  created_at TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS stories (
  id                 TEXT PRIMARY KEY,
  slug               TEXT UNIQUE,
  kind               TEXT NOT NULL DEFAULT 'community', -- community | wire
  created_at         TEXT NOT NULL,
  updated_at         TEXT,
  published_at       TEXT,
  author_user_id     TEXT,
  author_name        TEXT,
  title              TEXT NOT NULL,
  summary            TEXT,
  body               TEXT,
  location_name      TEXT,
  county             TEXT,
  province           TEXT,
  local_area         TEXT,
  latitude           REAL,
  longitude          REAL,
  category           TEXT NOT NULL DEFAULT 'Community',
  media_type         TEXT,
  media_original     TEXT,
  media_public       TEXT,
  image_url          TEXT,
  image_credit       TEXT,
  source_name        TEXT,
  source_url         TEXT,
  source_author      TEXT,
  external_id        TEXT UNIQUE,
  title_hash         TEXT,
  transcript         TEXT,
  moderation_json    TEXT,
  trust_score        INTEGER NOT NULL DEFAULT 0,
  safety_score       INTEGER NOT NULL DEFAULT 0,
  status             TEXT NOT NULL DEFAULT 'processing', -- processing | review | hold | rejected | published
  verification_label TEXT NOT NULL DEFAULT 'Community Report',
  editorial_note     TEXT,
  views              INTEGER NOT NULL DEFAULT 0,
  is_featured        INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS confirmations (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  story_id       TEXT NOT NULL,
  user_id        TEXT,
  created_at     TEXT NOT NULL,
  confirmer_name TEXT,
  note           TEXT,
  FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS comments (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  story_id        TEXT NOT NULL,
  user_id         TEXT,
  created_at      TEXT NOT NULL,
  author          TEXT,
  body            TEXT NOT NULL,
  status          TEXT NOT NULL DEFAULT 'published', -- published | review | rejected
  moderation_json TEXT,
  FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS subscriptions (
  id                     INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id                TEXT UNIQUE,
  email                  TEXT UNIQUE,
  plan                   TEXT NOT NULL DEFAULT 'free',
  status                 TEXT NOT NULL DEFAULT 'active',
  stripe_customer_id     TEXT,
  stripe_subscription_id TEXT,
  created_at             TEXT NOT NULL,
  updated_at             TEXT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS follows (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       TEXT NOT NULL,
  location_name TEXT,
  county        TEXT,
  created_at    TEXT NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    TEXT NOT NULL,
  created_at TEXT NOT NULL,
  type       TEXT NOT NULL,
  title      TEXT NOT NULL,
  body       TEXT,
  story_id   TEXT,
  is_read    INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ads (
  id            TEXT PRIMARY KEY,
  user_id       TEXT,
  created_at    TEXT NOT NULL,
  business_name TEXT NOT NULL,
  title         TEXT NOT NULL,
  body          TEXT NOT NULL,
  url           TEXT,
  target_county TEXT,
  target_town   TEXT,
  status        TEXT NOT NULL DEFAULT 'review', -- review | approved | rejected
  impressions   INTEGER NOT NULL DEFAULT 0,
  clicks        INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS audit_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at  TEXT NOT NULL,
  user_id     TEXT,
  action      TEXT NOT NULL,
  entity_type TEXT,
  entity_id   TEXT,
  detail      TEXT
);

CREATE TABLE IF NOT EXISTS stripe_events (
  id         TEXT PRIMARY KEY,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS rate_limits (
  key    TEXT NOT NULL,
  bucket INTEGER NOT NULL,
  hits   INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (key, bucket)
);

CREATE TABLE IF NOT EXISTS wire_runs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  source_key  TEXT NOT NULL,
  started_at  TEXT NOT NULL,
  finished_at TEXT,
  fetched     INTEGER NOT NULL DEFAULT 0,
  inserted    INTEGER NOT NULL DEFAULT 0,
  error       TEXT
);

CREATE TABLE IF NOT EXISTS settings (
  key   TEXT PRIMARY KEY,
  value TEXT
);

CREATE INDEX IF NOT EXISTS idx_stories_status_published ON stories(status, published_at DESC);
CREATE INDEX IF NOT EXISTS idx_stories_category         ON stories(category);
CREATE INDEX IF NOT EXISTS idx_stories_county           ON stories(county);
CREATE INDEX IF NOT EXISTS idx_stories_author           ON stories(author_user_id);
CREATE INDEX IF NOT EXISTS idx_stories_title_hash       ON stories(title_hash);
CREATE INDEX IF NOT EXISTS idx_sessions_expiry          ON sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_notifications_user       ON notifications(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_follows_user             ON follows(user_id);
CREATE INDEX IF NOT EXISTS idx_comments_story_status    ON comments(story_id, status);
CREATE INDEX IF NOT EXISTS idx_confirmations_story_user ON confirmations(story_id, user_id);
CREATE INDEX IF NOT EXISTS idx_wire_runs_started        ON wire_runs(started_at DESC);

-- Advertising (ME Ads): self-serve designed ads with trial + subscription billing
CREATE TABLE IF NOT EXISTS ad_stats (
  ad_id       TEXT NOT NULL,
  day         TEXT NOT NULL,
  impressions INTEGER NOT NULL DEFAULT 0,
  clicks      INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (ad_id, day)
);

CREATE TABLE IF NOT EXISTS ad_payments (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  ad_id        TEXT NOT NULL,
  gateway      TEXT NOT NULL,
  reference    TEXT,
  amount_cents INTEGER NOT NULL DEFAULT 0,
  currency     TEXT NOT NULL DEFAULT 'EUR',
  status       TEXT NOT NULL DEFAULT 'paid',
  created_at   TEXT NOT NULL,
  detail       TEXT
);
CREATE INDEX IF NOT EXISTS idx_ad_payments_ad ON ad_payments(ad_id, created_at DESC);

-- The Signal: reader voting with weighted, decayed, locality-aware ranking
CREATE TABLE IF NOT EXISTS votes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  story_id   TEXT NOT NULL,
  voter_key  TEXT NOT NULL,          -- user id when signed in, otherwise an anonymous voter cookie
  user_id    TEXT,
  signal     TEXT NOT NULL,          -- matters | talking | good | digging
  county     TEXT,                   -- voter's county at vote time (from Near-me cookies)
  weight     REAL NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  updated_at TEXT,
  UNIQUE (story_id, voter_key),
  FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_votes_story   ON votes(story_id);
CREATE INDEX IF NOT EXISTS idx_votes_created ON votes(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_votes_voter   ON votes(voter_key);
