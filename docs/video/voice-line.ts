/**
 * Makes docs/video/voice-line.wav, the line the tour says into the voice-typing
 * mic, with Groq's text to speech (canopylabs/orpheus-v1-english).
 *
 *   npm run voice-line
 *
 * Once only: the file is kept, so the tour doesn't need Groq for it again. It
 * needs GROQ_API_KEY in .env, and Orpheus's terms accepted for the Groq org
 * (console.groq.com/playground?model=canopylabs%2Forpheus-v1-english). A
 * recording of your own voice, as a WAV of that name, does as well.
 * MAPCHAT_VOICE picks Orpheus's voice.
 */
import {readFileSync, writeFileSync} from 'node:fs';
import {join} from 'node:path';

import {REPO} from './chrome.ts';

export const VOICE_LINE_FILE = join(REPO, 'docs/video/voice-line.wav');
// What the guest says to Rafael, after his tips for Lisbon
const VOICE_LINE = "thanks! when's the best time to catch the sunset there?";
const VOICE = process.env.MAPCHAT_VOICE ?? 'troy';

/** GROQ_API_KEY from .env */
function groqKey(): string {
    const line = readFileSync(join(REPO, '.env'), 'utf8').split('\n').find((setting) => setting.startsWith('GROQ_API_KEY='));
    const key = line?.slice('GROQ_API_KEY='.length).trim();
    if (!key) {
        throw new Error('Set GROQ_API_KEY in .env');
    }
    return key;
}

async function main(): Promise<void> {
    const response = await fetch('https://api.groq.com/openai/v1/audio/speech', {
        method: 'POST',
        headers: {'Authorization': `Bearer ${groqKey()}`, 'Content-Type': 'application/json'},
        body: JSON.stringify({model: 'canopylabs/orpheus-v1-english', voice: VOICE, input: VOICE_LINE, response_format: 'wav'}),
        signal: AbortSignal.timeout(60_000),
    });
    if (!response.ok) {
        throw new Error(`Groq didn't make the line: ${await response.text()}`);
    }
    writeFileSync(VOICE_LINE_FILE, Buffer.from(await response.arrayBuffer()));
    console.log(`${VOICE_LINE_FILE}: "${VOICE_LINE}"`);
}

// Only when run, not when the tour imports VOICE_LINE_FILE
if (process.argv[1]?.endsWith('voice-line.ts')) {
    await main();
}
