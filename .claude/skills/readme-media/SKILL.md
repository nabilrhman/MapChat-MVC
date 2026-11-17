---
name: readme-media
description: Retake the README's screenshots and tour video after a change people see, and swap in the video the README plays. Use when the screenshots or tour are out of date, or the user asks to update them.
---

# README screenshots and tour

The README plays a tour video, then shows a large main picture of the room,
then two phone pictures side by side (shown 300px wide, from the full-size
files), then the login beside the profile, then the two emails. The tour and
the other pictures are recorded with Playwright against the app on :8080
(`docs/video/`; each script's header says what it needs). The app has to be up with the demo people online. The
email pictures stay as they are: the user wants only the room, phone, login
and profile pictures retaken.

## 1. The room, phone, login and profile pictures

Run `npm run screenshots`. It writes five pictures to `docs/images/`, all
at 2x:

- `mapchat-app.png`, 1440×900: the room, two private chats open as tabs, a
  message the guest sent that @mentions a demo person (who answers), a
  message being written in the box that @mentions another, and the pin
  nearest the middle panned to the middle of the map with its label open;
- `mapchat-phone.png`, an iPhone's 393×852: the room with a pin panned to
  the middle of the map and its label open, as a tap opens it;
- `mapchat-phone-online.png`: the same phone with the online list slid open;
- `mapchat-profile.png`, 1440×900: a new guest's profile, one screen of it,
  so it matches the login in size and they share their row evenly;
- `mapchat-login.png`, 1440×900: the login page with Continue with Google.
  Without Google keys, the app is started again with placeholder ones for it,
  then as it was.

Each run signs its guests out when their pictures are done, and at the end
takes what they said, and what was said to them, out of the room, so the
next run's pictures don't show this one's.

Done when you've looked at all five and they show the current app with all
of that: the label's pin in the middle of the open map, the chips in the
messages and the draft, the newest messages, Continue with Google, no row lit
as if hovered, nothing half-loaded.

## 2. Tour

Once, if it's missing: `npx playwright install chromium`. The tour runs in
Playwright's Chromium, as branded Chrome won't load the capture extension.

Run `npm run tour` in the background: it signs in its own guest, pauses the
chat worker while it records real Groq replies, and takes a few minutes.
Without Google keys in `.env`, it starts the app container again with
placeholder ones so the login page shows Continue with Google, as the live
site does, and restarts it as it was afterwards. It records the tab at
2880×1800 through `docs/video/capture-extension/` (Chrome's own tab capture
and real-time VP8), and NVENC makes the 60fps H.264. It ends by printing the
frame rate the recording kept while the page moved.

Done when:

- that printed rate is about 60fps at 2880×1800. The user wants 2× at a
  true 60fps; Chrome's screencast, the old way, managed only about 40 at
  that size;
- frames pulled at each step (for example
  `ffmpeg -ss <second> -i docs/images/mapchat-tour.mp4 -frames:v 1 …`,
  stacked into a grid) show every step doing what it's there to show: the
  login page with Continue with Google, the hovered person's pin pointed
  out, the clicked city's pin flown to the middle of the map, Lukas's answer
  arriving, the private chats' replies, the tab moved and closed, the
  profile. A frame that only looks current isn't enough: a city click once
  landed on MapChat's Null Island, which has no pin, so the map never moved.

## 3. The video the README plays

The README embeds a GitHub `user-attachments` upload, not the repo's file,
so the new tour needs uploading. A page on github.com can't fetch from this
machine (its security blocks it), but it can fetch from GitHub's API, so the
video goes up by way of a pushed branch:

1. Commit the new tour on the branch you'll ship, and push it.
2. In the browser pane, signed in to GitHub (the user signs in there if it
   isn't), open the comment box of any PR.
3. With the javascript tool on that page: fetch
   `https://api.github.com/repos/nabilrhman/MapChat-MVC/contents/docs/images/mapchat-tour.mp4?ref=<branch>`
   with `Accept: application/vnd.github.raw`, make the blob a `File`, set it
   on the comment box's file input (`#fc-new_comment_field`) through a
   `DataTransfer`, and dispatch `change`.
4. Wait for the `user-attachments` link to appear in
   `textarea[name="comment[body]"]`, read it, then empty the box so nothing
   posts.
5. Put the link in the README's Overview, in place of the old one.

Done when the fetched size matches the file's, the README links the new
upload, and the comment box is empty.

## 4. The words around them

Check the README's Features, `docs/features.md` and the About page against
what the app does now, and update them with it; after changing the About
page, run `npm run a11y`.

Then ship it all with the `ship` skill.
