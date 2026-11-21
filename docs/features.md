# Features

Everything MapChat does, in full. Back to the [README](../README.md).

- One open chat room for everyone signed in, live over a WebSocket.
  Messages are up to 500 characters, at most 30 a minute, or 10 for a guest.
- Private chats in tabs of their own, with unread counts and "Typing...".
  Each opens with a header showing who you're talking to, and their city and
  local time.
- A message box that stays one line, its buttons beside it, until what you
  write wraps; then it grows to five lines (four on a phone) and scrolls.
- @mentions: type @ to pick someone; mentions show as chips.
- Emoji from the smile button in the message box.
- Voice typing: the mic beside an empty box listens, and what you said goes
  into the box to read over before sending. Groq's Whisper writes it out; when
  Groq is off or its free plan is used up for the minute, the browser's own
  speech recognition listens instead (Chrome, Safari and Edge). It listens
  for the language last picked in the translate bar's From, even with the
  bar closed; on Auto it guesses.
- Translation: the translate button beside the mic opens From and To above
  the message box. From starts on Auto and To on English, and the choice is
  remembered. A message goes out in the To language, and readers see
  "Translated · See original", which fades to what was typed and back.
  Groq translates, with Cerebras when Groq is busy, up to 30 messages a
  minute, or 10 for a guest. The emoji grid and the translate bar take turns.
  MapChat and the AI characters answer a translated message in its language,
  and their reply is translated back.
- A calm ping for a private message or an @mention of you in a chat you
  aren't looking at. Your profile turns it off on that browser. The room's
  tab counts only @mentions.
- Notifications for private messages and @mentions, even with MapChat closed
  (Web Push). Turn them on from the chat or your profile. They're per
  browser, and logging out turns them off there.
- A map with a pin for each person online, placed from their IP address and
  rounded to about a kilometre, never from GPS, with their local time and a
  sun or moon.
- Hover someone, or a message's city, to point out their pin; click either
  to fly the map to it. The night side of the world is shaded.
- Hover a message's time to see the sender's own time, or its city to see
  their country. On a phone, tap it.
- Anyone can hide their pin and city from the map.
- MapChat, the room's AI, and AI characters who chat back: see
  [ai-chat.md](ai-chat.md).
- Try it as a guest without signing up. A guest account lasts 24 hours
  unless kept, then it's logged out and deleted.
- Accounts with email activation, password reset, nicknames and avatars.
- Delete your profile from the profile page, after a dialog asks first. Your
  messages, private chats, location and notifications go with it.
- Continue with Google on the login page, which makes the account the first
  time, or links it to the account with the same email. Someone with no photo
  of their own gets their Google photo.
- Works on phones, where the chat is a sheet over the map: drag it to the
  height you like, and tap its handle to switch between all of the chat and
  that height. The chat's header is one line there; tap it for their status
  and place. Enter starts a new line, and Send sends.
