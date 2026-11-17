---
name: readme-media
description: Retake the README's screenshots and tour video after a change people see, and swap in the video the README plays. Use when the screenshots or tour are out of date, or the user asks to update them.
---

# README screenshots and tour

The README shows four screenshots from `docs/images/` and plays a tour
video. Both are recorded with Playwright against the app on :8080
(`docs/video/`; each script's header says what it needs). The app has to be
up with the demo people online.

## 1. Screenshots

Run `npm run screenshots`. It writes `docs/images/mapchat-login.jpg` and
`mapchat-{app,private,profile}.png`.

Done when you've looked at all four and each shows the current app: nothing
half-loaded, no stray focus ring, the chat and map filled in.

## 2. Tour

Run `npm run tour` in the background: it signs in its own guest, pauses the
chat worker while it records real Groq replies, and takes a few minutes.

Done when frames pulled from across the video (for example
`ffmpeg -ss <second> -i docs/images/mapchat-tour.mp4 -frames:v 1 …` at six
points, stacked into one grid) show each step of the tour in the current
design.

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
