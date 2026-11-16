# MapChat

A PHP 8.4 MVC site (`Core/` is the framework, `App/` the app, Twig views)
with plain-script JavaScript and CSS in `public/`, a live chat socket and a
worker in `bin/`, and MySQL. README.md: Run it locally and Project layout say
where everything lives; docs/live-chat.md how the live chat works.

## Rules

### Write plain code

Write the version a newcomer reads in one pass: named steps over dense
expressions, the codebase's existing pattern over a new one. Copy the
neighbouring file's shape before writing (a controller like
`App/Controllers/App.php`, a model like `App/Models/Chat.php`). Something
clever earns its place only when the plain version is wrong or too slow, and
its comment says which. A nested ternary is out: a third case is an `if` or a
lookup.

A block written out for three or more fields that differ only in data becomes
a table the code loops over, so a new field is one row. Two cases stay
written out.

### Names

Every name says what it is in the reader's words, however long that makes
it: `cityLine`, not `line`; `onlinePeople`, not `list`. A short name suits
only a scope seen at a glance, like `i` in a loop.

A function is named for its effect:

- one that acts is a verb phrase: `showChatHeader`, `subscribeToPush`;
- one that only computes a value is named for the value: `payloadOf`,
  `recipientsOf`, `placeName`; `get` reads outside state, `getNewestId`;
- one that answers yes or no reads as a question: `isPushEndpoint`,
  `areNotificationsOn`, `hasX`, `canX`, `shouldX`;
- an event handler is `onX`.

A boolean, in PHP and JS alike, reads as a question too: `isPrivate`,
`shouldSnap`.

### Comments

A comment says why: the reason or trap the code can't show, in one short
line where it can. Each file, class and section opens with a line on what it
is for, and each PHP method has a docblock with `@param` and `@return`, as
the code around it does. Comments end without a full stop, like the rest.

### CSS

BEM: a block is a component (`.person`), an element `__` (`.person__name`), a
modifier `--` (`.chat-header__daylight--day`), a state JS toggles `is-*`.
Selectors use classes; IDs are for JS. Motion uses `animations.css`'s easing
and durations (`--ease-out`, `--dur-fast`) and animates only opacity and
transform. Sizes follow the type scale in use: 13px names and tabs, 11px
times, places and tags.

### Copy

Short sentences in plain words, punctuated with full stops, commas and
colons: where an em dash would go, write two sentences. A hint under a field
is one line.

### Commits

Commits are authored as the repo's owner, with no AI attribution line in the
message, PR or code.

## Check your work

| Changed | Do |
| --- | --- |
| Anything | `docker compose run --rm --build test`: every PHPUnit test |
| `App/ChatSocket/`, `bin/`, or a model they use | `docker compose restart app`: the socket and worker keep the code they started with |
| `composer.json`, the `Dockerfile` | `docker compose up --build` |
| Anything a person sees | Open http://localhost:8080 and look, in the chat and on a phone width: a cramped gap or a wrong size shows only there |
| `public/sw.js` | Reload a MapChat tab: the new version takes over at once |

A change is done when the tests pass, a change a person sees has been looked
at in the browser, and the README and About page still match what the app
does.
