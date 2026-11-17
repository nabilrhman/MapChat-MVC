/* Voice typing: the mic button beside the message box listens, and what
   was said goes into the box to read over before sending. Groq writes it
   out (App::transcribeAction); when Groq is off or busy, the browser's own
   speech recognition listens instead, where the browser has one */

// Under VoiceClip::MAX_BYTES in any browser's format
const MAX_RECORDING_MS = 60_000;

// The server gives Groq 20 seconds; this leaves room for the upload
const TRANSCRIBE_TIME_LIMIT = 30_000;

// The pitches that tilt the wave's bars against each other, low to high, as
// FFT bins of about 47 Hz: about 100 Hz to 1 kHz, where most of a voice is
const WAVE_BANDS = [[2, 5], [5, 10], [10, 20]];

// The wave's bars, left to right: the low, loud voice in the middle two and
// the tallest there, the outer ones following higher pitches
const WAVE_BARS = [
    {band: 2, size: 0.7},
    {band: 0, size: 1},
    {band: 1, size: 0.9},
    {band: 2, size: 0.65},
];

// Mics differ a lot in how loud they hear the same voice, so the wave scales
// to the loudest of the last few seconds, as voice memo apps do. That peak
// fades by this much each frame (to half in about 2 seconds)...
const WAVE_PEAK_FADE = 0.995;

// ...but never below this loudness (RMS), so silence and room noise stay dots
const WAVE_QUIETEST_PEAK = 0.015;

// How far a bar moves toward the voice's level each frame: up fast, down
// slowly, so it swells with each word instead of flickering
const WAVE_RISE = 0.45;
const WAVE_FALL = 0.12;

// The wave's sizes in CSS px, each drawn as a whole number of the screen's
// own pixels so every gap comes out the same. A bar is 2px, rounded down
// on a scaled screen so it's never heavier; the gap is the widest that
// keeps this much space clear each side of the button
const WAVE_SIDE_PX = 5;
const WAVE_BAR_PX = 2;
const WAVE_GAP_PX = 4;
const WAVE_TALL_PX = 16; // the mic icon's height, so the wave fills the same space

// The mic button's label in each state
const MIC_LABELS = {
    idle: 'Voice typing',
    listening: 'Listening',
    transcribing: 'Typing it out',
};

// Send's label while it's the stop button
const STOP_LABEL = 'Stop';

/**
 * Moves each bar of `wave` with its band of pitches in `microphone`, until
 * the function it returns is called
 */
function moveWaveWith(wave, microphone) {
    const pen = wave.getContext('2d');
    // Each bar's level, from 0 (a round dot) to 1 (the wave's full height)
    const heights = WAVE_BARS.map(() => 0);

    const audio = new AudioContext();
    const analyser = audio.createAnalyser();
    analyser.fftSize = 1024;
    audio.createMediaStreamSource(microphone).connect(analyser);
    const soundWave = new Float32Array(analyser.fftSize);
    const loudnessByPitch = new Uint8Array(analyser.frequencyBinCount);

    // The loudest the voice has been lately, which loudness() measures against
    let recentPeak = WAVE_QUIETEST_PEAK;

    // How loud the voice is now, from 0 to 1 against how loud it's been lately
    function loudness() {
        analyser.getFloatTimeDomainData(soundWave);
        let sumOfSquares = 0;
        for (const sample of soundWave) {
            sumOfSquares += sample * sample;
        }
        const rms = Math.sqrt(sumOfSquares / soundWave.length);

        recentPeak = Math.max(rms, recentPeak * WAVE_PEAK_FADE, WAVE_QUIETEST_PEAK);
        // The square root lifts the quieter parts of a word, as ears hear them
        return Math.sqrt(rms / recentPeak);
    }

    // Each band's share of the voice, from 0 to 1 against the strongest
    function bandShares() {
        analyser.getByteFrequencyData(loudnessByPitch);
        const levels = WAVE_BANDS.map(([from, to]) => {
            let total = 0;
            for (let bin = from; bin < to; bin++) {
                total += loudnessByPitch[bin];
            }
            return total / (to - from);
        });
        const strongest = Math.max(...levels, 1);
        return levels.map((level) => level / strongest);
    }

    // Moves each bar toward the voice: up fast, down slowly
    function followVoice(now) {
        const level = loudness();
        const shares = bandShares();

        WAVE_BARS.forEach(({band, size}, index) => {
            // A slow sway of its own, so the bars never move as one block
            const sway = 0.92 + 0.08 * Math.sin(now / 140 + index * 1.7);
            const tilt = 0.85 + 0.15 * shares[band];
            const target = level * size * tilt * sway;
            const step = target > heights[index] ? WAVE_RISE : WAVE_FALL;
            heights[index] += (target - heights[index]) * step;
        });
    }

    // The button's box in whole screen pixels, as the screen draws its edges
    function buttonPixels(pixelRatio) {
        const box = wave.parentElement.getBoundingClientRect();
        const left = Math.round(box.left * pixelRatio);
        const top = Math.round(box.top * pixelRatio);
        return {
            left,
            top,
            width: Math.round(box.right * pixelRatio) - left,
            height: Math.round(box.bottom * pixelRatio) - top,
        };
    }

    // The whole number nearest `size` that leaves `room` minus it even, so
    // what's left splits equally on both sides
    function nearestThatCentres(size, room) {
        const below = Math.floor(size);
        const fits = (candidate) => (room - candidate) % 2 === 0;
        if (fits(below)) {
            return below;
        }
        // One step either way flips the leftover's oddness; take the closer
        const isUpCloser = below + 1 - size < size - (below - 1) || below - 1 < 1;
        return isUpCloser ? below + 1 : below - 1;
    }

    // The bars and gaps in screen pixels: the widest that keep the side
    // space and leave an even number of pixels over, so the wave centres
    // exactly. Gaps narrow first, then bars
    function barsThatFit(pixelRatio, buttonWidth) {
        const widest = buttonWidth - 2 * Math.round(WAVE_SIDE_PX * pixelRatio);
        const widthOf = (barWidth, gap) => WAVE_BARS.length * barWidth + (WAVE_BARS.length - 1) * gap;
        const fits = (width) => width <= widest && (buttonWidth - width) % 2 === 0;

        for (let barWidth = Math.max(1, Math.floor(WAVE_BAR_PX * pixelRatio)); barWidth >= 1; barWidth--) {
            for (let gap = Math.max(1, Math.round(WAVE_GAP_PX * pixelRatio)); gap >= 1; gap--) {
                if (fits(widthOf(barWidth, gap))) {
                    return {barWidth, gap, width: widthOf(barWidth, gap)};
                }
            }
        }
        // A button too small for that: the thinnest wave, as near the middle as it goes
        return {barWidth: 1, gap: 1, width: widthOf(1, 1)};
    }

    // The bars and gap in screen pixels, and the wave's box inside the
    // button, with the same space left and right and above and below
    function layoutFor(pixelRatio) {
        const button = buttonPixels(pixelRatio);
        const {barWidth, gap, width} = barsThatFit(pixelRatio, button.width);
        const height = nearestThatCentres(WAVE_TALL_PX * pixelRatio, button.height);

        return {
            barWidth,
            gap,
            width,
            height,
            // Floored, for a screen scale where the split can't be exact
            left: button.left + Math.floor((button.width - width) / 2),
            top: button.top + Math.floor((button.height - height) / 2),
        };
    }

    // The canvas over exactly the wave's screen pixels: whole ones, so no
    // bar blurs. Done each frame, so a zoom or another screen is picked up
    function fitToScreenPixels(pixelRatio, layout) {
        wave.style.width = `${layout.width / pixelRatio}px`;
        wave.style.height = `${layout.height / pixelRatio}px`;
        wave.style.translate = '';
        const box = wave.getBoundingClientRect();
        const shiftX = layout.left / pixelRatio - box.left;
        const shiftY = layout.top / pixelRatio - box.top;
        wave.style.translate = `${shiftX}px ${shiftY}px`;

        if (wave.width !== layout.width || wave.height !== layout.height) {
            wave.width = layout.width;
            wave.height = layout.height;
        }
    }

    function drawBars({barWidth, gap}) {
        pen.clearRect(0, 0, wave.width, wave.height);
        pen.fillStyle = getComputedStyle(wave).color;

        heights.forEach((level, index) => {
            // A whole number of pixels, with as many spare above as below,
            // so the bar's top and bottom both land on a pixel's edge
            let barHeight = Math.round(barWidth + level * (wave.height - barWidth));
            if ((wave.height - barHeight) % 2 !== 0) {
                barHeight += 1;
            }
            const x = index * (barWidth + gap);
            const y = (wave.height - barHeight) / 2;

            pen.beginPath();
            pen.roundRect(x, y, barWidth, barHeight, barWidth / 2);
            pen.fill();
        });
    }

    let frame = null;
    function draw(now) {
        followVoice(now);
        const layout = layoutFor(devicePixelRatio);
        fitToScreenPixels(devicePixelRatio, layout);
        drawBars(layout);
        frame = requestAnimationFrame(draw);
    }
    frame = requestAnimationFrame(draw);

    return () => {
        cancelAnimationFrame(frame);
        audio.close();
        pen.clearRect(0, 0, wave.width, wave.height);
        wave.style.removeProperty('translate');
        wave.style.removeProperty('width');
        wave.style.removeProperty('height');
    };
}

/**
 * Lets `micButton` fill `input` by voice at the cursor. While it listens it
 * shows a moving wave, and `sendButton` beside it becomes its stop button
 */
function setUpVoiceTyping(input, micButton, sendButton) {
    const wave = micButton.querySelector('[data-part="voice-wave"]');
    const BrowserSpeech = window.SpeechRecognition ?? window.webkitSpeechRecognition;
    const canRecord = Boolean(navigator.mediaDevices?.getUserMedia) && 'MediaRecorder' in window;
    const canUseGroq = micButton.dataset.canTranscribe === 'true' && canRecord;

    let shouldUseBrowserSpeech = !canUseGroq;
    // No way to listen here: the mic stays hidden
    if (shouldUseBrowserSpeech && !BrowserSpeech) return;

    // 'idle', 'listening' or 'transcribing'
    let state = 'idle';
    // Ends the recording or the browser's listening
    let stopListening = null;

    function showState(newState) {
        state = newState;
        micButton.classList.toggle('is-listening', state === 'listening');
        micButton.classList.toggle('is-transcribing', state === 'transcribing');
        micButton.disabled = state === 'transcribing';
        micButton.setAttribute('aria-pressed', String(state === 'listening'));
        micButton.setAttribute('aria-label', MIC_LABELS[state]);
        micButton.title = MIC_LABELS[state];
        showSendOrStop();
    }

    // While it listens, Send stops it, empty box or not; while the words are
    // written out it waits, and then it sends again
    function showSendOrStop() {
        const sendLabel = state === 'listening' ? STOP_LABEL : 'Send';
        sendButton.setAttribute('aria-label', sendLabel);
        sendButton.title = sendLabel;
        sendButton.disabled = state === 'transcribing'
            || (state === 'idle' && input.value.trim() === '');
    }

    function putSpokenText(text) {
        if (text === '') {
            showToast('Nothing was heard. Try again.', 'warning');
            return;
        }
        // Words said after some typed ones need a space between them
        const before = input.value.slice(0, input.selectionStart);
        const needsSpace = before !== '' && !/\s$/.test(before);
        putTextAtCursor(input, needsSpace ? ` ${text}` : text);
        input.focus();
    }

    async function recordForGroq() {
        let microphone;
        try {
            microphone = await navigator.mediaDevices.getUserMedia({audio: true});
        } catch {
            showToast('Allow the microphone first.', 'warning');
            return;
        }

        const recorder = new MediaRecorder(microphone);
        const pieces = [];
        const limitTimer = setTimeout(() => recorder.stop(), MAX_RECORDING_MS);

        recorder.addEventListener('dataavailable', (event) => pieces.push(event.data));
        recorder.addEventListener('stop', () => {
            clearTimeout(limitTimer);
            stopWave();
            // Ends the browser's "recording" sign too
            microphone.getTracks().forEach((track) => track.stop());
            sendRecording(new Blob(pieces, {type: recorder.mimeType}));
        }, {once: true});

        stopListening = () => recorder.stop();
        recorder.start();
        const stopWave = moveWaveWith(wave, microphone);
        showState('listening');
    }

    async function sendRecording(recording) {
        showState('transcribing');
        const form = new FormData();
        form.append('audio', recording, 'voice');

        try {
            const response = await axios.post('/app/transcribe', form, {timeout: TRANSCRIBE_TIME_LIMIT});
            putSpokenText(response.data.text);
        } catch (error) {
            onGroqFailed(error);
        } finally {
            showState('idle');
        }
    }

    // A recording Groq refused says why; anything else means Groq is off or
    // busy, so the browser listens from now on, where it can
    function onGroqFailed(error) {
        if (error.response?.status === 422) {
            showToast(errorMessageOf(error, 'Your recording could not be sent.'), 'warning');
        } else if (BrowserSpeech) {
            shouldUseBrowserSpeech = true;
            showToast('Voice typing is busy. Press the mic again and your browser will listen.', 'warning');
        } else {
            showToast('Voice typing is busy. Try again in a moment.', 'warning');
        }
    }

    async function listenWithBrowser() {
        const recognition = new BrowserSpeech();
        recognition.lang = navigator.language;
        // The wave's own hold on the mic, and what stops it; none until it's granted
        let microphone = null;
        let stopWave = () => {};

        recognition.addEventListener('result', (event) => {
            putSpokenText(event.results[0][0].transcript.trim());
        });
        recognition.addEventListener('error', (event) => {
            if (event.error === 'not-allowed') {
                showToast('Allow the microphone first.', 'warning');
            } else if (event.error === 'no-speech') {
                showToast('Nothing was heard. Try again.', 'warning');
            } else if (event.error !== 'aborted') {
                showToast('Your browser could not listen. Try again.', 'warning');
            }
        });
        recognition.addEventListener('end', () => {
            stopWave();
            microphone?.getTracks().forEach((track) => track.stop());
            showState('idle');
        });

        // Started on the tap itself, as Safari asks
        stopListening = () => recognition.stop();
        recognition.start();
        showState('listening');

        // The browser listens on its own; a second hold on the mic only draws the wave
        const waveMicrophone = await navigator.mediaDevices?.getUserMedia({audio: true}).catch(() => null);
        if (!waveMicrophone) return;
        if (state !== 'listening') {
            waveMicrophone.getTracks().forEach((track) => track.stop());
            return;
        }
        microphone = waveMicrophone;
        stopWave = moveWaveWith(wave, microphone);
    }

    // Only Send stops it; a second press on the mic while it listens does nothing
    micButton.addEventListener('click', () => {
        if (state !== 'idle') return;
        if (shouldUseBrowserSpeech) {
            listenWithBrowser();
        } else {
            recordForGroq();
        }
    });

    // Send, or Enter, while it listens stops it instead of sending. Caught
    // as pressed, not on submit: an empty box would stop at the browser's
    // own "required" check first, and chat.js's Enter handler sends the box
    function stopInsteadOfSending(event) {
        if (state !== 'listening') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        stopListening();
    }

    sendButton.addEventListener('click', stopInsteadOfSending, {capture: true});
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            stopInsteadOfSending(event);
        }
    }, {capture: true});

    // chat.js's own input handler greys Send out when the box is empty, and
    // it's added after this one, so this waits for it: a stop button stays on
    input.addEventListener('input', () => queueMicrotask(showSendOrStop));

    micButton.hidden = false;
}
