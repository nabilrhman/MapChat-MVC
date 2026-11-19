-- The file is UTF-8 (names with accents, emoji); make sure MySQL reads it that way
SET NAMES utf8mb4;

--
-- Tables. Written as plain CREATE TABLE statements (no later ALTERs) so the
-- same file works on MySQL and on TiDB.
--

CREATE TABLE users (
  `id`                        INT          NOT NULL AUTO_INCREMENT,
  `name`                      VARCHAR(50)  NOT NULL,
  -- Optional; shown instead of the first name, and what @mentions use
  `nickname`                  VARCHAR(20)  NULL DEFAULT NULL,
  `email`                     VARCHAR(255) NOT NULL,
  `password_hash`             VARCHAR(255) NOT NULL,
  `password_reset_hash`       VARCHAR(64)  NULL DEFAULT NULL,
  `password_reset_expires_at` DATETIME     NULL DEFAULT NULL,
  `activation_hash`           VARCHAR(64)  NULL DEFAULT NULL,
  `is_active`                 BOOLEAN      NOT NULL DEFAULT FALSE,
  `avatar`                    VARCHAR(2083),
  -- Guest accounts are deleted 24 hours after created_at
  `is_guest`                  BOOLEAN      NOT NULL DEFAULT FALSE,
  -- Hash of the token a guest's browser keeps, so they can come back to it
  `guest_token_hash`          VARCHAR(64)  NULL DEFAULT NULL,
  -- "Show me on the map" in the profile
  `share_location`            BOOLEAN      NOT NULL DEFAULT TRUE,
  -- Google's id for the account that signs in with Google; the password is
  -- empty for an account made that way until it sets one
  `google_id`                 VARCHAR(255) NULL DEFAULT NULL,
  -- Removed their photo, so signing in with Google doesn't bring its photo back
  `google_photo_declined`     BOOLEAN      NOT NULL DEFAULT FALSE,
  -- Wrong passwords in a row, from failed_logins_since; too many stop tries for a while
  `failed_logins`             INT          NOT NULL DEFAULT 0,
  `failed_logins_since`       TIMESTAMP    NULL DEFAULT NULL,
  `created_at`                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `nickname` (`nickname`),
  UNIQUE KEY `password_reset_hash` (`password_reset_hash`),
  UNIQUE KEY `activation_hash` (`activation_hash`),
  UNIQUE KEY `guest_token_hash` (`guest_token_hash`),
  UNIQUE KEY `google_id` (`google_id`)
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

-- "Remember me" logins
CREATE TABLE remembered_logins (
  `token_hash` VARCHAR(64) NOT NULL,
  `user_id`    INT         NOT NULL,
  `expires_at` DATETIME    NOT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `user_id` (`user_id`)
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

-- PHP sessions (see Core/DatabaseSessionHandler.php)
CREATE TABLE sessions (
  `id`         VARCHAR(128) NOT NULL,
  `data`       MEDIUMBLOB   NOT NULL,
  `updated_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `updated_at` (`updated_at`)
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

CREATE TABLE chats (
  `id`        INT          NOT NULL AUTO_INCREMENT,
  `email`     VARCHAR(255) NOT NULL,
  -- NULL for the open room, otherwise the one person this private message is for
  `to_email`  VARCHAR(255) NULL DEFAULT NULL,
  `body`      MEDIUMTEXT,
  `timestamp` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- When the recipient saw this private message; NULL while it's unread
  `read_at`   TIMESTAMP    NULL DEFAULT NULL,
  -- The id the sender's page gave the message, so sending it again after a
  -- lost reply finds this row instead of saving a second one
  `client_id` VARCHAR(36)  NULL DEFAULT NULL,
  -- What the sender wrote, when the message box translated it into body
  `original_body` TEXT     NULL DEFAULT NULL,
  -- Posted by MapChat or a demo person answering someone (App/Models/Bot.php),
  -- not from the demo script; kept here as the job row goes with its guest
  `is_live_answer` BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (`id`),
  KEY `fk_m_email` (`email`),
  KEY `fk_m_to_email` (`to_email`),
  UNIQUE KEY `client_id` (`email`, `client_id`),
  -- One private chat's messages, either way, without reading anyone else's
  KEY `pair` (`to_email`, `email`),
  CONSTRAINT `fk_m_email` FOREIGN KEY (`email`) REFERENCES users (`email`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_m_to_email` FOREIGN KEY (`to_email`) REFERENCES users (`email`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  -- On TiDB, keep chat ids strictly increasing (pages and the chat socket
  -- read on from the last id they have). MySQL ignores this comment.
  /*T![auto_id_cache] AUTO_ID_CACHE = 1 */;

-- Where each person is chatting from
CREATE TABLE userinfo (
  `id`        INT           NOT NULL AUTO_INCREMENT,
  `email`     VARCHAR(255)  NOT NULL,
  `ip`        VARCHAR(50)   NOT NULL,
  `lat`       DECIMAL(10, 8),
  `lng`       DECIMAL(11, 8),
  `city`      VARCHAR(255),
  -- Two-letter code from the IP lookup, e.g. "BD"
  `country`   CHAR(2),
  -- From the browser, e.g. "Asia/Dhaka"; shows others your local time
  `timezone`  VARCHAR(64),
  `online_at` TIMESTAMP     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  CONSTRAINT `fk_i_email` FOREIGN KEY (`email`) REFERENCES users (`email`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

-- What the MapChat account is about to say (see App/Models/Bot.php)
CREATE TABLE mapchat_jobs (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  -- greet (a visitor to a quiet room), reply or mention (in the open room), or dm
  `kind`       VARCHAR(10)  NOT NULL,
  -- The person MapChat greets or answers
  `email`      VARCHAR(255) NOT NULL,
  -- Who answers: MapChat, or a demo person someone @mentioned
  `speaker`    VARCHAR(255) NOT NULL DEFAULT 'bot@mapchat.local',
  -- Their message; NULL for a greeting
  `chat_id`    INT          NULL DEFAULT NULL,
  -- One job per message, and one greeting per person a day (see Bot::arrived)
  `dedupe_key` VARCHAR(300) NOT NULL,
  `due_at`     TIMESTAMP    NOT NULL,
  -- Set once it ran, or once it's no longer needed
  `done_at`    TIMESTAMP    NULL DEFAULT NULL,
  -- MapChat's message, if it posted one
  `posted_id`  INT          NULL DEFAULT NULL,
  -- A private reply already written, held until it would have taken to type
  `reply`      TEXT         NULL DEFAULT NULL,
  -- The speaker's own words, when the held reply is their translation
  `reply_original` TEXT     NULL DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dedupe_key` (`dedupe_key`),
  KEY `due` (`done_at`, `due_at`),
  KEY `posted_id` (`posted_id`),
  KEY `fk_j_email` (`email`),
  CONSTRAINT `fk_j_email` FOREIGN KEY (`email`) REFERENCES users (`email`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

-- Browsers that get Web Push notifications, one row each (see App/Notify/PushNotifications.php)
CREATE TABLE push_subscriptions (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `email`           VARCHAR(255) NOT NULL,
  -- The browser's push service address; hashed for the unique key, as it can be long
  `endpoint`        TEXT         NOT NULL,
  `endpoint_hash`   CHAR(64)     NOT NULL,
  `p256dh`          VARCHAR(255) NOT NULL,
  `auth`            VARCHAR(255) NOT NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `endpoint_hash` (`endpoint_hash`),
  KEY `fk_p_email` (`email`),
  CONSTRAINT `fk_p_email` FOREIGN KEY (`email`) REFERENCES users (`email`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

-- Each translation or recording someone asked Groq for, for the limit a
-- minute (see App/Models/GroqUse.php); rows older than a minute are deleted
CREATE TABLE groq_uses (
  `user_id`  INT         NOT NULL,
  -- translate or transcribe
  `kind`     VARCHAR(10) NOT NULL,
  `used_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `recent` (`user_id`, `kind`, `used_at`),
  CONSTRAINT `fk_g_user` FOREIGN KEY (`user_id`) REFERENCES users (`id`)
    ON DELETE CASCADE
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

-- A built-in account that posts the welcome messages and chats back. It has
-- no usable password (the hash below matches nothing) and cannot log in.
INSERT INTO users (name, email, password_hash, is_active, avatar)
VALUES ('MapChat', 'bot@mapchat.local', '!', TRUE, '/images/avatar-mapchat.svg');

-- It "chats from" Null Island, the made-up spot at 0, 0 where maps put things
-- that have no real location. XN is a code ISO leaves free, with our own flag
INSERT INTO userinfo (email, ip, lat, lng, city, country)
VALUES ('bot@mapchat.local', '0.0.0.0', 0, 0, 'Null Island', 'XN');

INSERT INTO chats (id, email, body, timestamp) VALUES (771, 'bot@mapchat.local', 'Welcome to MapChat! This is one open room, shared by everyone who signs in.', NOW() - INTERVAL 180 MINUTE);
INSERT INTO chats (id, email, body, timestamp) VALUES (781, 'bot@mapchat.local', 'Each pin on the map is someone in the chat. Hover over a pin to see their name.', NOW() - INTERVAL 179 MINUTE);
INSERT INTO chats (id, email, body, timestamp) VALUES (791, 'bot@mapchat.local', 'Your pin comes from your IP address, so it only shows roughly which city you are in, never your exact location.', NOW() - INTERVAL 178 MINUTE);

