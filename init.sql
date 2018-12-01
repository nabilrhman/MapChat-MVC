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
  DEFAULT CHARSET = utf8;

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
  DEFAULT CHARSET = utf8;

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
  DEFAULT CHARSET = utf8;

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
  DEFAULT CHARSET = utf8;

INSERT INTO chats (id, email, body, timestamp) VALUES (771, 'nabilr@outlook.com', 'Hi there, welcome to MapChat!', '2018-10-28 21:40:21');
INSERT INTO chats (id, email, body, timestamp) VALUES (781, 'nabilr@outlook.com', 'Here you can chat with random people from all around the world and see which cities they are from.', '2018-10-28 21:40:38');
INSERT INTO chats (id, email, body, timestamp) VALUES (791, 'nabilr@outlook.com', 'FUN FACT: Press CTRL+Y to generate random dialogues from your favorite show, the Game of Thrones. The dialogues are here for educational purpose only.', '2018-10-28 21:42:21');


ALTER TABLE users
ADD avatar VARCHAR(2083);