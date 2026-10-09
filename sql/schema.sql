-- Flashcards (flashcards.jmpvlab.com) — esquema de base de datos
-- MySQL 5.7+ / MariaDB 10.3+. Todas las fechas se guardan en UTC.
-- Importar una sola vez desde phpMyAdmin (Site Tools > MySQL > phpMyAdmin > Importar).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(254)    NOT NULL,
  name            VARCHAR(100)    NOT NULL DEFAULT '',
  google_sub      VARCHAR(64)     NULL,
  new_per_day     SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  short_session   SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  timezone        VARCHAR(64)     NOT NULL DEFAULT 'America/Lima',
  created_at      DATETIME        NOT NULL,
  last_login_at   DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_google (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_codes (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(254)    NOT NULL,
  code_hash   CHAR(64)        NOT NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at  DATETIME        NOT NULL,
  used_at     DATETIME        NULL,
  created_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_codes_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  selector        CHAR(24)        NOT NULL,
  validator_hash  CHAR(64)        NOT NULL,
  expires_at      DATETIME        NOT NULL,
  created_at      DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_remember_selector (selector),
  KEY ix_remember_user (user_id),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eventos para los límites anti-spam (envío de códigos, intentos, importaciones).
CREATE TABLE IF NOT EXISTS rate_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket      VARCHAR(190)    NOT NULL,
  created_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_rate_bucket (bucket, created_at),
  KEY ix_rate_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS decks (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(120)    NOT NULL,
  created_at  DATETIME        NOT NULL,
  updated_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_decks_user_name (user_id, name),
  CONSTRAINT fk_decks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarjetas. Los campos fsrs_* guardan el estado de ts-fsrs (FSRS-6).
-- state: 0 nueva, 1 aprendiendo, 2 repaso, 3 reaprendiendo.
CREATE TABLE IF NOT EXISTS cards (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  deck_id         BIGINT UNSIGNED NOT NULL,
  week            SMALLINT UNSIGNED NULL,
  front           TEXT            NOT NULL,
  back            TEXT            NOT NULL,
  front_hash      CHAR(64)        NOT NULL,
  position        INT UNSIGNED    NOT NULL DEFAULT 0,
  due             DATETIME        NOT NULL,
  stability       DOUBLE          NOT NULL DEFAULT 0,
  difficulty      DOUBLE          NOT NULL DEFAULT 0,
  elapsed_days    INT UNSIGNED    NOT NULL DEFAULT 0,
  scheduled_days  INT UNSIGNED    NOT NULL DEFAULT 0,
  learning_steps  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  reps            INT UNSIGNED    NOT NULL DEFAULT 0,
  lapses          INT UNSIGNED    NOT NULL DEFAULT 0,
  state           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_review     DATETIME        NULL,
  created_at      DATETIME        NOT NULL,
  updated_at      DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cards_deck_front (deck_id, front_hash),
  KEY ix_cards_user_due (user_id, state, due),
  KEY ix_cards_deck_week (deck_id, week),
  CONSTRAINT fk_cards_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_cards_deck FOREIGN KEY (deck_id) REFERENCES decks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de cada respuesta. Sirve para FSRS, el tope de nuevas y el cuaderno de errores.
-- cram = 1: respuesta en modo examen o errores (no cambia el horario).
CREATE TABLE IF NOT EXISTS review_log (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  card_id         BIGINT UNSIGNED NOT NULL,
  client_uuid     CHAR(36)        NOT NULL,
  rating          TINYINT UNSIGNED NOT NULL,
  state_before    TINYINT UNSIGNED NOT NULL,
  due_before      DATETIME        NOT NULL,
  stability       DOUBLE          NOT NULL DEFAULT 0,
  difficulty      DOUBLE          NOT NULL DEFAULT 0,
  scheduled_days  INT UNSIGNED    NOT NULL DEFAULT 0,
  cram            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms     INT UNSIGNED    NOT NULL DEFAULT 0,
  reviewed_at     DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_review_uuid (client_uuid),
  KEY ix_review_user_time (user_id, reviewed_at),
  KEY ix_review_card (card_id),
  CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_review_card FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
