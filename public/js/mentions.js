// Suggests people's names after an @ in the message box, while you're in
// the open room. chat.js starts it with setUpMentions

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
        matches = getPeople()
            .filter((person) => matchesTyped(person.name, typed) || matchesTyped(person.shown_name, typed))
            .slice(0, MENTION_LIMIT);
        activeIndex = 0;

        if (matches.length === 0) {
            close();
        } else {
            showOptions();
        }
    }

    // Swap the @ and what's typed for their handle (nickname or first name) and a space
    function choose(person) {
        const before = input.value.slice(0, atIndex);
        const after = input.value.slice(input.selectionStart);
        const mention = `@${person.shown_name} `;

        input.value = before + mention + after;
        input.selectionStart = input.selectionEnd = before.length + mention.length;
        close();
        input.dispatchEvent(new Event('input'));
    }

    function moveActive(step) {
        activeIndex = (activeIndex + step + matches.length) % matches.length;
        showOptions();
    }

    input.addEventListener('input', update);
    input.addEventListener('click', update);
    input.addEventListener('blur', close);

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
