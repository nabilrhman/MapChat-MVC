-- The file is UTF-8 (names with accents, emoji); make sure MySQL reads it that way
SET NAMES utf8mb4;

--
-- Table structure for table users
--

CREATE TABLE users (
  `id`            int(11)      NOT NULL,
  `name`          varchar(50)  NOT NULL,
  `email`         varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

--
-- Indexes for table users
--
ALTER TABLE users
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for table users
--
ALTER TABLE users
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Table structure for table `remembered_logins`
--

CREATE TABLE remembered_logins (
  `token_hash` varchar(64) NOT NULL,
  `user_id`    int(11)     NOT NULL,
  `expires_at` datetime    NOT NULL
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

--
-- Indexes for table `remembered_logins`
--
ALTER TABLE `remembered_logins`
  ADD PRIMARY KEY (`token_hash`),
  ADD KEY `user_id` (`user_id`);

--
-- Additional columns for password reset
--

ALTER TABLE `users`
  ADD `password_reset_hash` VARCHAR(64) NULL DEFAULT NULL
  AFTER `password_hash`,
  ADD `password_reset_expires_at` DATETIME NULL DEFAULT NULL
  AFTER `password_reset_hash`,
  ADD UNIQUE (`password_reset_hash`);

--
-- Additional columns for account activation
--

ALTER TABLE `users`
  ADD `activation_hash` VARCHAR(64) NULL DEFAULT NULL
  AFTER `password_reset_expires_at`,
  ADD `is_active` BOOLEAN NOT NULL DEFAULT FALSE
  AFTER `activation_hash`,
  ADD UNIQUE (`activation_hash`);


ALTER TABLE users
  ADD avatar VARCHAR(2083);

--
-- PHP sessions (see Core/DatabaseSessionHandler.php)
--
CREATE TABLE sessions (
  `id`         VARCHAR(128) NOT NULL,
  `data`       MEDIUMBLOB   NOT NULL,
  `updated_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `updated_at` (`updated_at`)
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

--
-- Guest accounts and the "show me on the map" setting
--
ALTER TABLE users
  ADD `is_guest`       BOOLEAN NOT NULL DEFAULT FALSE,
  ADD `share_location` BOOLEAN NOT NULL DEFAULT TRUE,
  ADD `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

CREATE TABLE chats (
  id        INT          NOT NULL AUTO_INCREMENT,
  email     vARCHAR(255) NOT NULL,
  body      MEDIUMTEXT,
  timestamp TIMESTAMP    NOT NULL default CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_m_email (email),
  CONSTRAINT fk_m_email FOREIGN KEY (email) REFERENCES users (email)
    ON DELETE CASCADE
    ON UPDATE CASCADE
)
  ENGINE = InnoDB
  AUTO_INCREMENT = 1
  DEFAULT CHARSET = utf8mb4
  -- On TiDB, keep chat ids strictly increasing (the chat polls for id > last
  -- seen id). MySQL ignores this comment.
  /*T![auto_id_cache] AUTO_ID_CACHE = 1 */;

CREATE TABLE `userinfo` (
  `id`        INT          NOT NULL AUTO_INCREMENT,
  `email`     VARCHAR(255) NOT NULL UNIQUE,
  `ip`        VARCHAR(50)  NOT NULL,
  `lat`       DECIMAL(10, 8),
  `lng`       DECIMAL(11, 8),
  `city`      varchar(255),
  `online_at` TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_i_email (email),
  CONSTRAINT fk_i_email FOREIGN KEY (email) REFERENCES users (email)
    ON DELETE CASCADE
    ON UPDATE CASCADE
)
  ENGINE = InnoDB
  AUTO_INCREMENT = 1
  DEFAULT CHARSET = utf8mb4;

-- A built-in account that posts the welcome messages. It has no usable
-- password (the hash below matches nothing) and cannot log in.
INSERT INTO users (name, email, password_hash, is_active, avatar)
VALUES ('MapChat', 'bot@mapchat.local', '!', TRUE, '/res/avatar-mapchat.svg');

INSERT INTO chats (id, email, body, timestamp) VALUES (771, 'bot@mapchat.local', 'Welcome to MapChat! This is one open room, shared by everyone who signs in.', NOW() - INTERVAL 180 MINUTE);
INSERT INTO chats (id, email, body, timestamp) VALUES (781, 'bot@mapchat.local', 'Each pin on the map is someone in the chat. Hover over a pin to see their name.', NOW() - INTERVAL 179 MINUTE);
INSERT INTO chats (id, email, body, timestamp) VALUES (791, 'bot@mapchat.local', 'Your pin comes from your IP address, so it only shows roughly which city you are in, never your exact location.', NOW() - INTERVAL 178 MINUTE);

