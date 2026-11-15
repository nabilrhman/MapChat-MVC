-- The file is UTF-8 (names with accents, emoji); make sure MySQL reads it that way
SET NAMES utf8mb4;

--
-- Tables. Written as plain CREATE TABLE statements (no later ALTERs) so the
-- same file works on MySQL and on TiDB.
--

CREATE TABLE users (
  `id`                        INT          NOT NULL AUTO_INCREMENT,
  `name`                      VARCHAR(50)  NOT NULL,
  `email`                     VARCHAR(255) NOT NULL,
  `password_hash`             VARCHAR(255) NOT NULL,
  `password_reset_hash`       VARCHAR(64)  NULL DEFAULT NULL,
  `password_reset_expires_at` DATETIME     NULL DEFAULT NULL,
  `activation_hash`           VARCHAR(64)  NULL DEFAULT NULL,
  `is_active`                 BOOLEAN      NOT NULL DEFAULT FALSE,
  `avatar`                    VARCHAR(2083),
  -- Guest accounts are deleted 24 hours after created_at
  `is_guest`                  BOOLEAN      NOT NULL DEFAULT FALSE,
  -- "Show me on the map" in the profile
  `share_location`            BOOLEAN      NOT NULL DEFAULT TRUE,
  `created_at`                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `password_reset_hash` (`password_reset_hash`),
  UNIQUE KEY `activation_hash` (`activation_hash`)
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
  PRIMARY KEY (`id`),
  KEY `fk_m_email` (`email`),
  KEY `fk_m_to_email` (`to_email`),
  CONSTRAINT `fk_m_email` FOREIGN KEY (`email`) REFERENCES users (`email`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_m_to_email` FOREIGN KEY (`to_email`) REFERENCES users (`email`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  -- On TiDB, keep chat ids strictly increasing (the chat polls for id > last
  -- seen id). MySQL ignores this comment.
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

-- A built-in account that posts the welcome messages. It has no usable
-- password (the hash below matches nothing) and cannot log in.
INSERT INTO users (name, email, password_hash, is_active, avatar)
VALUES ('MapChat', 'bot@mapchat.local', '!', TRUE, '/res/avatar-mapchat.svg');

INSERT INTO chats (id, email, body, timestamp) VALUES (771, 'bot@mapchat.local', 'Welcome to MapChat! This is one open room, shared by everyone who signs in.', NOW() - INTERVAL 180 MINUTE);
INSERT INTO chats (id, email, body, timestamp) VALUES (781, 'bot@mapchat.local', 'Each pin on the map is someone in the chat. Hover over a pin to see their name.', NOW() - INTERVAL 179 MINUTE);
INSERT INTO chats (id, email, body, timestamp) VALUES (791, 'bot@mapchat.local', 'Your pin comes from your IP address, so it only shows roughly which city you are in, never your exact location.', NOW() - INTERVAL 178 MINUTE);

