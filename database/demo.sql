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

INSERT INTO users (name, email, password_hash, is_active, avatar) VALUES
  ('Aiko Tanaka',   'aiko@demo.mapchat.local',    '!', TRUE, '/res/avatars/aiko.svg'),
  ('Rafael Costa',  'rafael@demo.mapchat.local',  '!', TRUE, '/res/avatars/rafael.svg'),
  ('Maya Thompson', 'maya@demo.mapchat.local',    '!', TRUE, '/res/avatars/maya.svg'),
  ('Tanvir Hasan',  'tanvir@demo.mapchat.local',  '!', TRUE, '/res/avatars/tanvir.svg'),
  ('Wanjiru Kamau', 'wanjiru@demo.mapchat.local', '!', TRUE, '/res/avatars/wanjiru.svg'),
  ('Lukas Becker',  'lukas@demo.mapchat.local',   '!', TRUE, '/res/avatars/lukas.svg'),
  ('Sofia Ramírez', 'sofia@demo.mapchat.local',   '!', TRUE, '/res/avatars/sofia.svg'),
  ('Jae-won Park',  'jaewon@demo.mapchat.local',  '!', TRUE, '/res/avatars/jaewon.svg'),
  ('Emily Carter',  'emily@demo.mapchat.local',   '!', TRUE, '/res/avatars/emily.svg');

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

-- The conversation, a message every few minutes over the last couple of hours
INSERT INTO chats (email, body, timestamp) VALUES
  ('maya@demo.mapchat.local',    'Hi everyone! Anyone here from Lisbon? Flying out in two weeks and I have no plan yet', NOW() - INTERVAL 130 MINUTE),
  ('rafael@demo.mapchat.local',  'Right here 👋 How many days do you have?', NOW() - INTERVAL 127 MINUTE),
  ('maya@demo.mapchat.local',    'Four days. Mostly want good food and some walking', NOW() - INTERVAL 125 MINUTE),
  ('rafael@demo.mapchat.local',  'Then skip the tram 28 queue and just walk Alfama in the morning before the crowds. Lunch at any tasca with a handwritten menu, the less English on it the better', NOW() - INTERVAL 122 MINUTE),
  ('rafael@demo.mapchat.local',  'And take the train to Sintra on a weekday, not the weekend', NOW() - INTERVAL 121 MINUTE),
  ('maya@demo.mapchat.local',    'This is gold, thank you!!', NOW() - INTERVAL 119 MINUTE),
  ('lukas@demo.mapchat.local',   'Seconding the weekday Sintra advice. I went on a Saturday once and spent more time in lines than in palaces', NOW() - INTERVAL 112 MINUTE),
  ('tanvir@demo.mapchat.local',  'Here in Dhaka the rain hasn''t stopped for hours 🌧️', NOW() - INTERVAL 96 MINUTE),
  ('aiko@demo.mapchat.local',    'We had the same in Tokyo yesterday. Today it''s clear and sunny, perfect for a long walk', NOW() - INTERVAL 93 MINUTE),
  ('tanvir@demo.mapchat.local',  'Lucky! Here it''s perfect weather for cha and shingara at the corner stall', NOW() - INTERVAL 90 MINUTE),
  ('wanjiru@demo.mapchat.local', 'Hello from Nairobi! Just found this app. Is it always this international in here?', NOW() - INTERVAL 74 MINUTE),
  ('emily@demo.mapchat.local',   'Pretty much! I''m in Boise, Idaho, which is usually the quietest pin on the map 😄', NOW() - INTERVAL 71 MINUTE),
  ('wanjiru@demo.mapchat.local', 'I had to look up where Idaho is, I''ll be honest', NOW() - INTERVAL 69 MINUTE),
  ('emily@demo.mapchat.local',   'Most Americans do too. Lots of mountains, lots of potatoes', NOW() - INTERVAL 67 MINUTE),
  ('jaewon@demo.mapchat.local',  'Question for anyone who has visited Seoul: what surprised you most? I''m writing a small guide for friends', NOW() - INTERVAL 48 MINUTE),
  ('aiko@demo.mapchat.local',    'How late everything stays open. I had dinner at 2am in Mapo and the place was full', NOW() - INTERVAL 45 MINUTE),
  ('sofia@demo.mapchat.local',   'The subway! Clean, on time, and the transfer music is so cheerful', NOW() - INTERVAL 43 MINUTE),
  ('jaewon@demo.mapchat.local',  'Both going in the guide, thanks 🙏', NOW() - INTERVAL 41 MINUTE),
  ('sofia@demo.mapchat.local',   'And if anyone comes to Mexico City: tacos al pastor from a stand with a long line of locals, always', NOW() - INTERVAL 22 MINUTE),
  ('maya@demo.mapchat.local',    'Adding Mexico City to the list for next year. This room is ruining my savings', NOW() - INTERVAL 19 MINUTE),
  ('lukas@demo.mapchat.local',   'Worth every cent 😄', NOW() - INTERVAL 17 MINUTE),
  ('wanjiru@demo.mapchat.local', 'Okay, I''m staying. Signing off for now, see you all later from Nairobi!', NOW() - INTERVAL 6 MINUTE);
