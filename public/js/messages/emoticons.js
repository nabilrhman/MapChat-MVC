/* Emoticons: a typed :-) turns into 🙂 once a space or Send follows it */

// What each emoticon becomes. A new one is one more row. None is longer
// as an emoji than as typed, so swapping never passes the box's maxlength
const EMOJI_BY_EMOTICON = new Map([
    [':)', '🙂'], [':-)', '🙂'],
    [':(', '🙁'], [':-(', '🙁'],
    [':D', '😄'], [':-D', '😄'],
    [';)', '😉'], [';-)', '😉'],
    [':P', '😛'], [':-P', '😛'], [':p', '😛'], [':-p', '😛'],
    [':O', '😮'], [':-O', '😮'], [':o', '😮'], [':-o', '😮'],
    [':|', '😐'], [':-|', '😐'],
    [":'(", '😢'],
    ['xD', '😆'], ['XD', '😆'],
    ['<3', '❤️'],
]);

/**
 * Swaps the emoticon that ends at `end` in `input` for its emoji. Only a
 * whole word counts, so a link's :// or a smile like ok:) stays as typed
 */
function replaceEmoticonEndingAt(input, end) {
    const textBefore = input.value.slice(0, end);
    const lastWord = textBefore.split(/\s/).at(-1);
    const emoji = EMOJI_BY_EMOTICON.get(lastWord);
    if (!emoji) return;

    // 'preserve' keeps the cursor where it was, moved for the shorter text
    input.setRangeText(emoji, end - lastWord.length, end, 'preserve');
    // As putTextAtCursor does: the send button, count and mentions redraw
    input.dispatchEvent(new Event('input'));
}

/**
 * Swaps an emoticon typed last with no space after it. Send calls it
 */
function replaceLastEmoticonIn(input) {
    replaceEmoticonEndingAt(input, input.value.trimEnd().length);
}

/**
 * Turns emoticons typed in `input` into emoji as soon as a space or a new
 * line follows them
 */
function setUpEmoticons(input) {
    input.addEventListener('input', (event) => {
        // Typed by hand: voice typing, mentions and the emoji grid put their
        // text in with no inputType. A phone keyboard's word still being
        // composed is left alone, or its text could double
        const isTyped = event.inputType === 'insertText' || event.inputType === 'insertLineBreak';
        if (!isTyped || event.isComposing) return;

        const typedAt = input.selectionStart - 1;
        const isWhitespaceTyped = /\s/.test(input.value[typedAt] ?? '');
        if (!isWhitespaceTyped) return;

        replaceEmoticonEndingAt(input, typedAt);
    });
}
