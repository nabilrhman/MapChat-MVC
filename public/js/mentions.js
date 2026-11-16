// @mentions in the message box, while you're in the open room: names to
// pick after an @, and mentions shown as chips as you type. chat.js starts
// it with setUpMentions

// Most names shown at once
const MENTION_LIMIT = 6;

// An @ at the start or after a space, then what's typed of the name so far
const MENTION_BEING_TYPED = /(?:^|\s)@([^\s@][^@\n]{0,30}|)$/u;

// A name matches when it, or any of its words, starts with what's typed,
// so a surname finds them too
function matchesTyped(name, typed) {
    const lowerName = name.toLowerCase();
    return lowerName.startsWith(typed) || lowerName.split(/\s+/).some((word) => word.startsWith(typed));
}

// The text as nodes, with each @ and one of the handles in a chip. Longer
// handles go first, so "@Guest 2189" isn't taken for a shorter one
function withMentionChips(text, handles) {
    if (handles.length === 0) return [text];

    const escaped = [...handles]
        .sort((a, b) => b.length - a.length)
        .map((handle) => handle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    const mention = new RegExp(`@(?:${escaped.join('|')})(?![\\p{L}\\p{N}])`, 'giu');

    const nodes = [];
    let last = 0;
    for (const match of text.matchAll(mention)) {
        nodes.push(text.slice(last, match.index), createElement('span', 'mention', match[0]));
        last = match.index + match[0].length;
    }
    nodes.push(text.slice(last));
    return nodes;
}

// canMention() says whether names can be suggested now (the open room
// only); getPeople() gives everyone who could be mentioned, in list order.
// Call it before other keydown listeners on the box, so choosing a name
// with Enter doesn't also send the message
function setUpMentions(input, canMention, getPeople) {
    const picker = createElement('ul', 'mention-picker');
    picker.id = 'mention-picker';
    picker.setAttribute('role', 'listbox');
    picker.setAttribute('aria-label', 'People to mention');
    picker.hidden = true;
    input.after(picker);
    input.setAttribute('aria-controls', picker.id);
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');

    // The box's text drawn again behind it, with chips; the box's own text
    // is see-through so this shows, and the caret and selection stay real
    const chips = createElement('div', 'chat-composer__chips');
    chips.setAttribute('aria-hidden', 'true');
    input.before(chips);
    input.classList.add('chat-composer__textarea--over-chips');

    function showChips() {
        const handles = canMention() ? getPeople().map((person) => person.shown_name) : [];
        // A space after a last empty line, so it takes up its line as in the box
        chips.replaceChildren(...withMentionChips(input.value, handles), ' ');
        chips.scrollTop = input.scrollTop;
    }

    let matches = [];
    let activeIndex = 0;
    // Where the @ being typed is in the box
    let atIndex = 0;

    function close() {
        picker.hidden = true;
        matches = [];
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function createOption(person, index) {
        const option = createElement('li', 'mention-picker__option');
        option.id = `mention-option-${index}`;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', String(index === activeIndex));
        option.classList.toggle('is-active', index === activeIndex);

        const photo = createElement('img', 'mention-picker__photo');
        photo.alt = '';
        photo.src = person.avatar_src;
        option.append(photo, createElement('span', 'mention-picker__name', person.shown_name));

        // mousedown, not click, so the box keeps focus
        option.addEventListener('mousedown', (event) => {
            event.preventDefault();
            choose(person);
        });
        return option;
    }

    function showOptions() {
        picker.replaceChildren(...matches.map(createOption));
        picker.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-activedescendant', `mention-option-${activeIndex}`);
    }

    function update() {
        const isCaret = input.selectionStart === input.selectionEnd;
        const typedSoFar = input.value.slice(0, input.selectionStart);
        const found = canMention() && isCaret ? typedSoFar.match(MENTION_BEING_TYPED) : null;
        if (!found) {
            close();
            return;
        }

        const typed = found[1].toLowerCase();
        atIndex = typedSoFar.length - found[1].length - 1;

        // A space after someone's whole handle finishes the mention, spelled their way
        const finished = typed.endsWith(' ')
            && getPeople().find((person) => person.shown_name.toLowerCase() === typed.trimEnd());
        if (finished) {
            putMention(finished);
            close();
            showChips();
            return;
        }

        // Past a space, only a handle with a space in it (a guest's) can go on,
        // so "@Maya thanks" doesn't bring Maya Thompson back up
        const isMatch = typed.includes(' ')
            ? (person) => person.shown_name.toLowerCase().startsWith(typed)
            : (person) => matchesTyped(person.name, typed) || matchesTyped(person.shown_name, typed);
        matches = getPeople().filter(isMatch).slice(0, MENTION_LIMIT);
        activeIndex = 0;

        if (matches.length === 0) {
            close();
        } else {
            showOptions();
        }
    }

    // Swap the @ and what's typed for their handle (nickname or first name) and a space
    function putMention(person) {
        const before = input.value.slice(0, atIndex);
        const after = input.value.slice(input.selectionStart).replace(/^ /, '');
        const mention = `@${person.shown_name} `;

        input.value = before + mention + after;
        input.selectionStart = input.selectionEnd = before.length + mention.length;
    }

    function choose(person) {
        putMention(person);
        close();
        input.dispatchEvent(new Event('input'));
    }

    function moveActive(step) {
        activeIndex = (activeIndex + step + matches.length) % matches.length;
        showOptions();
    }

    input.addEventListener('input', () => {
        update();
        showChips();
    });
    input.addEventListener('click', update);
    input.addEventListener('blur', close);
    input.addEventListener('scroll', () => {
        chips.scrollTop = input.scrollTop;
    });
    // Back from a private chat: chips again
    input.addEventListener('focus', showChips);

    input.addEventListener('keydown', (event) => {
        if (picker.hidden || event.isComposing) return;

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            moveActive(event.key === 'ArrowDown' ? 1 : -1);
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            event.stopImmediatePropagation();
            choose(matches[activeIndex]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close();
        }
    });
}
