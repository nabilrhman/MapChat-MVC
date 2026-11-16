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
is for, and each PHP method has a docblock whose first line says what it
does; its `@param` and `@return` add only what the types can't say (an
array's keys, a unit, what null means). Comments end without a full stop,
like the rest.

### Modern PHP

Write PHP 8.4:

- Every parameter, return and property has a type (`?int`, `int|false`,
  `void`, `never`); `mixed` only where a value truly can be anything.
- A value set once is `readonly`; a constructor that only stores its
  arguments promotes them.
- One value checked against several cases is a `match`; a fixed set of
  string values (a job's kind, a conversation) is a backed `enum`.
- `str_contains`, `str_starts_with`, `??`, `?->`, arrow functions for
  one-line closures, first-class callables (`strlen(...)`), and named
  arguments where a bare `true` or a third positional would be a guess.
- Values from the database arrive as strings: cast them where they come in,
  so the code past that point sees `int` and `bool`.

### Modern JavaScript

The scripts run as they are, with no build step, sharing globals in the order
`base_app.html` and `base_chat.html` load them. Write today's JavaScript:

- `const`, or `let` when it changes; arrow functions; template literals;
  destructuring; `?.` and `??`; `for…of`.
- `async`/`await` over `.then` chains; a request that can hang has a
  timeout (`AbortSignal.timeout`, or the same limit on a socket request).
- The DOM's own methods: `append`, `replaceChildren`, `closest`,
  `classList.toggle(name, isOn)`, `addEventListener(…, {once: true})`.
- A helper that exists (`readFlag`, `createPlace`, `fadeInPhotos`) is called,
  not written again.

### Modern CSS

Custom properties for any value used twice; `gap`, `inset`, logical
properties (`margin-inline`, `padding-block`); `:has()` and `color-mix()`
over extra classes and hand-mixed colours. Keyframes are kebab-case like
classes.

### CSS

BEM: a block is a component (`.person`), an element `__` (`.person__name`), a
modifier `--` (`.chat-header__daylight--day`), a state JS toggles `is-*`.
Selectors use classes; IDs are for JS. Motion uses `animations.css`'s easing
and durations (`--ease-out`, `--dur-fast`) and animates opacity and
transform; a band sliding open to a height it can't know animates
`grid-template-rows` from `0fr` to `1fr`, and a colour may fade on hover.
Sizes follow the type scale in use: 13px names and tabs, 11px times, places
and tags.

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
| Anything | `docker compose run --rm --build test`: every PHPUnit test. In a worktree, add `-p <worktree name>` so its database is its own |
| `App/ChatSocket/`, `bin/`, or a model they use | `docker compose restart app`: the socket and worker keep the code they started with |
| `composer.json`, the `Dockerfile` | `docker compose up --build` |
| Anything a person sees | Open http://localhost:8080 and look, in the chat and on a phone width: a cramped gap or a wrong size shows only there |
| `public/sw.js` | Reload a MapChat tab: the new version takes over at once |

A change is done when the tests pass, a change a person sees has been looked
at in the browser, and the README and About page still match what the app
does.
