---
name: readme-media
description: Retake the README's screenshots and tour video after a change people see, and swap in the video the README plays. Use when the screenshots or tour are out of date, or the user asks to update them.
---

# README screenshots and tour

The README plays a tour video, then shows a main picture of the room, then
the profile and the two emails. The tour and the main picture are recorded
with Playwright against the app on :8080 (`docs/video/`; each script's
header says what it needs). The app has to be up with the demo people
online. The profile and email pictures stay as they are: the user wants only
the main picture retaken.

## 1. The main picture

Run `npm run screenshots`. It writes `docs/images/mapchat-app.png`: the
room, two private chats open as tabs, a message being written in the box,
and the label of the pin nearest the middle of the map open.

Done when you've looked at it and it shows the current app with all of
that: the pin's label open near the middle, the draft in the box, nothing
half-loaded.

## 2. Tour

Run `npm run tour` in the background: it signs in its own guest, pauses the
chat worker while it records real Groq replies, and takes a few minutes.

Done when frames pulled at each step (for example
`ffmpeg -ss <second> -i docs/images/mapchat-tour.mp4 -frames:v 1 …`,
stacked into a grid) show every step doing what it's there to show: the
hovered person's pin pointed out, the clicked city's pin flown to the middle
of the map, Lukas's answer arriving, the private chats' replies, the tab
moved and closed, the profile. A frame that only looks current isn't
enough: a city click once landed on MapChat's Null Island, which has no
pin, so the map never moved.

## 3. The video the README plays

The README embeds a GitHub `user-attachments` upload, not the repo's file,
so the new tour needs uploading. That takes the user, as a local file can't
reach GitHub from here: the browser pane has no way to attach one, GitHub's
page security blocks it fetching from this machine, and the pane opens
popups in the same tab. (Claude in Chrome's `file_upload` can, when it's
connected, up to 10 MB.)

1. In the browser pane, open the comment box of a PR, signed in to GitHub
   (the user signs in there if it isn't).
2. Ask the user to drag in `docs/images/mapchat-tour.mp4`, the full-size
   file, and leave the comment unposted.
3. Read the link from `textarea[name="comment[body]"]` with the javascript
   tool, then empty the box so nothing posts.
4. Put the link in the README's Overview, in place of the old one.

Done when the README links the new upload and the comment box is empty.

## 4. The words around them

Check the README's Features and the About page against what the app does
now, and update them with it; after changing the About page, run
`npm run a11y`.

Then ship it all with the `ship` skill.
