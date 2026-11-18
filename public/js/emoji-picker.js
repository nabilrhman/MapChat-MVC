/* Emoji picker: the smile button beside the message box opens a grid of
   emoji, and a click puts one in at the cursor */

// The groups the grid shows, in order. A new emoji is one more in a row
const EMOJI_GROUPS = [
    {name: 'Faces', emoji: '😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😋 😜 🤪 😎 🤩 🥳 😏 😌 🤔 🤗 🤭 😐 😴 😮 😲 🥺 😢 😭 😤 😡 🤯 😳 🥶 🤒 🤠'},
    {name: 'Hands', emoji: '👍 👎 👌 ✌️ 🤞 🤟 🤙 👋 👏 🙌 🙏 🤝 💪 👀 ✍️ 🫶'},
    {name: 'Hearts', emoji: '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 💔 💕 💖 💯 ✨ 🔥 ⭐ 🎉'},
    {name: 'Places', emoji: '🌍 🌎 🌏 🗺️ 📍 🏠 🏙️ 🏖️ 🏔️ ✈️ 🚗 🚆 ☀️ 🌙 🌧️ ❄️'},
    {name: 'Things', emoji: '☕ 🍕 🍔 🍜 🍰 🍻 🎂 🎁 🎵 📷 💻 📱 ⚽ 🎮 📚 💡'},
];

/**
 * Lets `toggleButton` open `picker`, a grid whose emoji go into `input`
 * at the cursor
 */
function setUpEmojiPicker(input, toggleButton, picker) {
    // Phones: focusing the box after a pick would bring the keyboard up over the grid
    const isTouchScreen = matchMedia('(pointer: coarse)').matches;
    const groups = picker.querySelector('[data-part="emoji-groups"]');
    const closeButton = picker.querySelector('[data-part="emoji-close"]');

    function createGroup({name, emoji}) {
        const group = createElement('div', 'group');
        const heading = createElement('h3', 'heading', name);
        const grid = createElement('div', 'grid');

        for (const character of emoji.split(' ')) {
            const button = createButton('emoji', character);
            button.dataset.part = 'emoji';
            grid.append(button);
        }

        group.append(heading, grid);
        return group;
    }

    function isPickerOpen() {
        return !picker.hidden;
    }

    function openPicker() {
        if (groups.childElementCount === 0) {
            groups.append(...EMOJI_GROUPS.map(createGroup));
        }
        picker.hidden = false;
        toggleButton.setAttribute('aria-expanded', 'true');
        toggleButton.classList.add('is-open');
    }

    function closePicker() {
        picker.hidden = true;
        toggleButton.setAttribute('aria-expanded', 'false');
        toggleButton.classList.remove('is-open');
    }

    function putEmoji(character) {
        putTextAtCursor(input, character);
        if (!isTouchScreen) {
            input.focus();
        }
    }

    toggleButton.addEventListener('click', () => {
        if (isPickerOpen()) {
            closePicker();
        } else {
            openPicker();
        }
    });

    closeButton.addEventListener('click', () => {
        closePicker();
        toggleButton.focus();
    });

    picker.addEventListener('click', (event) => {
        const emojiButton = event.target.closest('[data-part="emoji"]');
        if (emojiButton) {
            putEmoji(emojiButton.textContent);
        }
    });

    // A click anywhere else closes it, as a menu does
    document.addEventListener('click', (event) => {
        if (!isPickerOpen()) return;
        if (picker.contains(event.target) || toggleButton.contains(event.target)) return;
        closePicker();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !isPickerOpen()) return;
        closePicker();
        toggleButton.focus();
    });

    input.form.addEventListener('submit', closePicker);
}
