// A calm ping, one marimba note, for a private message or an @mention of
// you in a chat you aren't looking at. The note is worked out in the
// browser, so there's no sound file to load

// A4: warm, yet still clear on a phone's speaker
const PING_HERTZ = 440;
// Long enough for the bar's ring to die away, so it never ends cut off
const PING_SECONDS = 1;
// The last of it eases down to silence
const PING_FADE_OUT_SECONDS = 0.25;
const PING_VOLUME = 0.26;
// A marimba bar's own pitch and its overtones: how far above the pitch
// each is, how loud, and how many seconds it takes to fade. Each fading at
// its own speed is what makes it sound struck rather than made
const MARIMBA_TONES = [
    {ratio: 1, loudness: 1, fadeSeconds: 0.2},
    {ratio: 3.93, loudness: 0.3, fadeSeconds: 0.08},
    {ratio: 9.2, loudness: 0.06, fadeSeconds: 0.02},
];
// The soft mallet's knock at the start: a burst of muffled noise
const MALLET_SECONDS = 0.008;
const MALLET_LOUDNESS = 0.4;
const MALLET_TOP_HERTZ = 1800;
const PING_ECHO_SECONDS = 0.9;
const PING_ECHO_VOLUME = 0.22;
// Pings that come while one plays wait their turn, but only this many, so
// a burst of messages doesn't chime on and on
const MOST_WAITING_PINGS = 3;

let pingAudio = null;
let pingNote = null;
let pingEcho = null;
// When, on the audio's clock, the last ping started or is set to start;
// never yet, so the first plays at once
let lastPingStartsAt = -Infinity;
// The socket and the polling fallback can both bring the same message
const pingedIds = new Set();

// The struck bar, sample by sample, scaled so its loudest is 1
function createMarimbaNote(audio) {
    const sampleRate = audio.sampleRate;
    const samples = new Float32Array(PING_SECONDS * sampleRate);

    for (const {ratio, loudness, fadeSeconds} of MARIMBA_TONES) {
        const step = 2 * Math.PI * PING_HERTZ * ratio / sampleRate;
        for (let i = 0; i < samples.length; i++) {
            samples[i] += loudness * Math.sin(step * i) * Math.exp(-i / (fadeSeconds * sampleRate));
        }
    }

    // Noise smoothed into a low knock, fading over the mallet's moment
    const malletSamples = Math.floor(MALLET_SECONDS * sampleRate);
    const smoothing = Math.exp(-2 * Math.PI * MALLET_TOP_HERTZ / sampleRate);
    let knock = 0;
    for (let i = 0; i < malletSamples; i++) {
        knock = smoothing * knock + (1 - smoothing) * (Math.random() * 2 - 1);
        samples[i] += MALLET_LOUDNESS * knock * (1 - i / malletSamples);
    }

    // Two milliseconds' rise, so it starts with no click, and a fade at the end
    const riseSamples = Math.floor(0.002 * sampleRate);
    for (let i = 0; i < riseSamples; i++) samples[i] *= i / riseSamples;

    const fadeOutSamples = Math.floor(PING_FADE_OUT_SECONDS * sampleRate);
    const fadeOutStart = samples.length - fadeOutSamples;
    for (let i = fadeOutStart; i < samples.length; i++) {
        samples[i] *= (samples.length - i) / fadeOutSamples;
    }

    const loudest = samples.reduce((highest, sample) => Math.max(highest, Math.abs(sample)), 0);
    const note = audio.createBuffer(1, samples.length, sampleRate);
    note.copyToChannel(samples.map((sample) => sample / loudest), 0);
    return note;
}

// A small room's echo: noise that fades away, which a convolver turns into
// the room's sound
function createPingEcho(audio) {
    const sampleCount = PING_ECHO_SECONDS * audio.sampleRate;
    const echoShape = audio.createBuffer(2, sampleCount, audio.sampleRate);
    for (let channel = 0; channel < 2; channel++) {
        const samples = echoShape.getChannelData(channel);
        for (let i = 0; i < sampleCount; i++) {
            const fade = (1 - i / sampleCount) ** 3.5;
            samples[i] = (Math.random() * 2 - 1) * fade;
        }
    }
    const echo = new ConvolverNode(audio, {buffer: echoShape});
    echo.connect(new GainNode(audio, {gain: PING_ECHO_VOLUME})).connect(audio.destination);
    return echo;
}

// Browsers hold sound back till the page is clicked or typed on, so the
// first of those turns it on
function turnOnPingSound() {
    if (!pingAudio) {
        pingAudio = new AudioContext();
        pingNote = createMarimbaNote(pingAudio);
        pingEcho = createPingEcho(pingAudio);
    }
    pingAudio.resume();
}

document.addEventListener('pointerdown', turnOnPingSound, {once: true});
document.addEventListener('keydown', turnOnPingSound, {once: true});

// Right away, or once the pings already playing or waiting have finished
function playPing() {
    if (pingAudio?.state !== 'running') return;

    const now = pingAudio.currentTime;
    const startsAt = Math.max(now, lastPingStartsAt + PING_SECONDS);
    const waitingPings = Math.ceil((startsAt - now) / PING_SECONDS);
    if (waitingPings > MOST_WAITING_PINGS) return;
    lastPingStartsAt = startsAt;

    const note = new AudioBufferSourceNode(pingAudio, {buffer: pingNote});
    const volume = new GainNode(pingAudio, {gain: PING_VOLUME});
    note.connect(volume);
    volume.connect(pingAudio.destination);
    volume.connect(pingEcho);
    note.start(startsAt);
}

// Whether a new message pings: someone else's that's private or @mentions
// you, in a chat you aren't looking at. One you can see needs no sound
function shouldPingFor(chat, isInView) {
    const isMine = Number(chat.dataset.senderId) === myUserId;
    const isMention = 'mentionsMe' in chat.dataset;
    const isPrivate = chat.dataset.conversation !== EVERYONE;
    if (isMine || isInView || pingedIds.has(chat.dataset.id)) return false;

    pingedIds.add(chat.dataset.id);
    return isMention || isPrivate;
}
