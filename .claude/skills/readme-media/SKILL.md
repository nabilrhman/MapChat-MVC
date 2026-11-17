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

Check the README's Features and the About page against what the app does
now, and update them with it; after changing the About page, run
`npm run a11y`.

Then ship it all with the `ship` skill.
