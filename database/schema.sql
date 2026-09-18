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

-- ---------------------------------------------------------------------------
-- The local layer: notices, alerts, closures, polls, corrections, takedowns
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notices (
  id               TEXT PRIMARY KEY,
  slug             TEXT UNIQUE,
  kind             TEXT NOT NULL,                   -- death | memoriam | event | job | planning | pet | result
  status           TEXT NOT NULL DEFAULT 'review',  -- review | published | rejected | expired
  created_at       TEXT NOT NULL,
  updated_at       TEXT,
  published_at     TEXT,
  expires_at       TEXT,
  title            TEXT NOT NULL,                   -- deceased's name, event title, job title, pet name…
  body             TEXT,
  county           TEXT,
  town             TEXT,
  address          TEXT,
  date_of_death    TEXT,
  reposing         TEXT,
  funeral_at       TEXT,
  funeral_venue    TEXT,
  burial           TEXT,
  family_message   TEXT,
  event_at         TEXT,
  event_end        TEXT,
  venue            TEXT,
  price            TEXT,
  url              TEXT,
  contact_name     TEXT,
  contact_org      TEXT,                            -- funeral director, promoter, employer, planning agent
  contact_email    TEXT,
  contact_phone    TEXT,
  submitted_by     TEXT,                            -- user id when signed in
  verify_token     TEXT,
  verified_at      TEXT,
  image_path       TEXT,
  plan             TEXT NOT NULL DEFAULT 'free',    -- free | promoted
  promoted_until   TEXT,
  views            INTEGER NOT NULL DEFAULT 0,
  extra_json       TEXT,
  editorial_note   TEXT
);
CREATE INDEX IF NOT EXISTS idx_notices_kind_status ON notices(kind, status, published_at DESC);
CREATE INDEX IF NOT EXISTS idx_notices_county      ON notices(county, kind);

CREATE TABLE IF NOT EXISTS alert_subscriptions (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  email           TEXT NOT NULL,
  county          TEXT NOT NULL,
  town            TEXT,
  kinds           TEXT NOT NULL DEFAULT 'daily',     -- comma list: daily,deaths,warnings,closures,planning
  token           TEXT UNIQUE NOT NULL,
  user_id         TEXT,
  created_at      TEXT NOT NULL,
  confirmed_at    TEXT,
  unsubscribed_at TEXT,
  last_daily_at   TEXT,
  UNIQUE (email, county)
);

CREATE TABLE IF NOT EXISTS closures (
  id            TEXT PRIMARY KEY,
  created_at    TEXT NOT NULL,
  status        TEXT NOT NULL DEFAULT 'review',      -- review | published | rejected
  school        TEXT NOT NULL,
  county        TEXT NOT NULL,
  town          TEXT,
  closed_on     TEXT NOT NULL,                       -- YYYY-MM-DD
  reopens_on    TEXT,
  reason        TEXT,
  contact_name  TEXT,
  contact_role  TEXT,
  contact_email TEXT,
  verify_token  TEXT,
  verified_at   TEXT,
  published_at  TEXT
);
CREATE INDEX IF NOT EXISTS idx_closures_day ON closures(closed_on, status);

CREATE TABLE IF NOT EXISTS polls (
  id           TEXT PRIMARY KEY,
  week         TEXT NOT NULL,                        -- ISO week, e.g. 2026-W37
  question     TEXT NOT NULL,
  options_json TEXT NOT NULL,
  created_at   TEXT NOT NULL,
  closes_at    TEXT NOT NULL,
  status       TEXT NOT NULL DEFAULT 'open',
  UNIQUE (week)
);
CREATE TABLE IF NOT EXISTS poll_votes (
  poll_id    TEXT NOT NULL,
  voter_key  TEXT NOT NULL,
  option_idx INTEGER NOT NULL,
  county     TEXT,
  created_at TEXT NOT NULL,
  PRIMARY KEY (poll_id, voter_key)
);

CREATE TABLE IF NOT EXISTS corrections (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at TEXT NOT NULL,
  story_id   TEXT,
  title      TEXT NOT NULL,
  summary    TEXT NOT NULL,
  detail     TEXT,
  editor_id  TEXT
);

CREATE TABLE IF NOT EXISTS takedowns (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at TEXT NOT NULL,
  url        TEXT NOT NULL,
  reason     TEXT NOT NULL,
  detail     TEXT,
  contact    TEXT NOT NULL,
  status     TEXT NOT NULL DEFAULT 'open',           -- open | actioned | declined
  note       TEXT,
  handled_at TEXT
);

CREATE TABLE IF NOT EXISTS story_clusters (
  id           TEXT PRIMARY KEY,
  created_at   TEXT NOT NULL,
  updated_at   TEXT,
  lead_id      TEXT,
  count        INTEGER NOT NULL DEFAULT 1,
  outlets_json TEXT,
  framing      TEXT
);

-- ---------------------------------------------------------------------------
-- ME Ads packages: impression bundles bought up front, served until exhausted
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ad_packages (
  id           TEXT PRIMARY KEY,
  slug         TEXT UNIQUE NOT NULL,
  name         TEXT NOT NULL,
  tagline      TEXT,
  price_cents  INTEGER NOT NULL,
  impressions  INTEGER NOT NULL,
  tier         TEXT NOT NULL DEFAULT 'sidebar',   -- sidebar | site | premium
  features_json TEXT,
  badge        TEXT,
  sort         INTEGER NOT NULL DEFAULT 0,
  active       INTEGER NOT NULL DEFAULT 1,
  created_at   TEXT NOT NULL,
  updated_at   TEXT
);

CREATE TABLE IF NOT EXISTS ad_orders (
  id               TEXT PRIMARY KEY,
  user_id          TEXT NOT NULL,
  package_id       TEXT,
  package_name     TEXT NOT NULL,
  tier             TEXT NOT NULL,
  ad_id            TEXT,
  impressions      INTEGER NOT NULL,
  impressions_used INTEGER NOT NULL DEFAULT 0,
  price_cents      INTEGER NOT NULL,
  currency         TEXT NOT NULL DEFAULT 'EUR',
  gateway          TEXT,                            -- stripe | paypal | manual
  gateway_ref      TEXT,                            -- checkout session / PayPal order id
  payment_ref      TEXT,                            -- payment intent / capture id
  status           TEXT NOT NULL DEFAULT 'pending', -- pending | paid | running | completed | refunded | cancelled
  created_at       TEXT NOT NULL,
  paid_at          TEXT,
  started_at       TEXT,
  completed_at     TEXT,
  note             TEXT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_ad_orders_user   ON ad_orders(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ad_orders_ad     ON ad_orders(ad_id, status);
CREATE INDEX IF NOT EXISTS idx_ad_orders_ref    ON ad_orders(gateway_ref);
