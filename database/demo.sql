-- The file is UTF-8 (names with accents, emoji); make sure MySQL reads it that way
SET NAMES utf8mb4;

--
-- Sample people and a conversation, so the room doesn't look empty on a
-- fresh install. Run it after init.sql. Leave it out for an empty room.
--
-- The people are made up. Their email addresses end in @demo.mapchat.local,
-- which can't receive mail, and their password hash matches no password, so
-- nobody can log in as them.
--

INSERT INTO users (name, email, password_hash, is_active) VALUES
  ('Aiko Tanaka',   'aiko@demo.mapchat.local',    '!', TRUE),
  ('Rafael Costa',  'rafael@demo.mapchat.local',  '!', TRUE),
  ('Maya Thompson', 'maya@demo.mapchat.local',    '!', TRUE),
  ('Tanvir Hasan',  'tanvir@demo.mapchat.local',  '!', TRUE),
  ('Wanjiru Kamau', 'wanjiru@demo.mapchat.local', '!', TRUE),
  ('Lukas Becker',  'lukas@demo.mapchat.local',   '!', TRUE),
  ('Sofia Ramírez', 'sofia@demo.mapchat.local',   '!', TRUE),
  ('Jae-won Park',  'jaewon@demo.mapchat.local',  '!', TRUE),
  ('Emily Carter',  'emily@demo.mapchat.local',   '!', TRUE);

-- Where each of them is chatting from (city centres, not real addresses)
INSERT INTO userinfo (email, ip, lat, lng, city, country, timezone, online_at) VALUES
  ('aiko@demo.mapchat.local',    '0.0.0.0', 35.6762000,  139.6503000, 'Tokyo',       'JP', 'Asia/Tokyo', NOW()),
  ('rafael@demo.mapchat.local',  '0.0.0.0', 38.7223000,  -9.1393000,  'Lisbon',      'PT', 'Europe/Lisbon', NOW()),
  ('maya@demo.mapchat.local',    '0.0.0.0', 43.6532000,  -79.3832000, 'Toronto',     'CA', 'America/Toronto', NOW()),
  ('tanvir@demo.mapchat.local',  '0.0.0.0', 23.8103000,  90.4125000,  'Dhaka',       'BD', 'Asia/Dhaka', NOW()),
  ('wanjiru@demo.mapchat.local', '0.0.0.0', -1.2921000,  36.8219000,  'Nairobi',     'KE', 'Africa/Nairobi', NOW()),
  ('lukas@demo.mapchat.local',   '0.0.0.0', 52.5200000,  13.4050000,  'Berlin',      'DE', 'Europe/Berlin', NOW()),
  ('sofia@demo.mapchat.local',   '0.0.0.0', 19.4326000,  -99.1332000, 'Mexico City', 'MX', 'America/Mexico_City', NOW()),
  ('jaewon@demo.mapchat.local',  '0.0.0.0', 37.5665000,  126.9780000, 'Seoul',       'KR', 'Asia/Seoul', NOW()),
  ('emily@demo.mapchat.local',   '0.0.0.0', 43.6150000,  -116.2023000,'Boise',       'US', 'America/Boise', NOW());

-- What they say in the room comes from database/demo-chat.php, a few
-- conversations a day (see App/Models/DemoChat.php)
