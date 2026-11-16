/**
 * What the tour and the screenshots share: where the app is, the commands
 * run in its containers, and headless Chrome drawing on the GPU.
 *
 * MAPCHAT_URL is the app's address (default http://localhost:8080).
 * MAPCHAT_ROOT is the checkout whose docker compose project runs the app
 * (default this one): set it when running from a git worktree.
 * MAPCHAT_GPU=0 draws without the GPU, and MAPCHAT_CHROME_FLAGS adds flags
 */
import {execFile} from 'node:child_process';
import {resolve} from 'node:path';
import {promisify} from 'node:util';
import {type Browser, chromium, type Page} from 'playwright';

export const run = promisify(execFile);

export const REPO = resolve(import.meta.dirname, '../..');
const COMPOSE_ROOT = process.env.MAPCHAT_ROOT ?? REPO;
export const URL = (process.env.MAPCHAT_URL ?? 'http://localhost:8080').replace(/\/+$/, '');
// Chrome draws on the GPU (through ANGLE and EGL), as software drawing
// can't keep up with a Retina-sized tour
export const USES_GPU = process.env.MAPCHAT_GPU !== '0';
const GPU_FLAGS = ['--enable-gpu', '--ignore-gpu-blocklist', '--use-gl=angle', '--use-angle=gl-egl'];
const CHROME_FLAGS = [
    ...(USES_GPU ? GPU_FLAGS : []),
    ...(process.env.MAPCHAT_CHROME_FLAGS ?? '').split(/\s+/).filter(Boolean),
];

/** Runs a command in the app's container, and gives what it printed */
export async function app(...command: string[]): Promise<string> {
    const {stdout} = await run('docker', ['compose', 'exec', '-T', 'app', ...command], {cwd: COMPOSE_ROOT});
    return stdout;
}

/** Runs a statement on the app's database */
export async function sql(statement: string): Promise<string> {
    const {stdout} = await run(
        'docker',
        ['compose', 'exec', '-T', 'db', 'mysql', '--default-character-set=utf8mb4', '-uroot', '-pmapchat', 'mapchat', '-N', '-e', statement],
        {cwd: COMPOSE_ROOT},
    );
    return stdout.trim();
}

/**
 * Starts the installed Chrome, headless. Playwright's own Ctrl+C handling
 * is off when the caller has its own, to tidy up before it exits
 */
export function launchChrome(extraFlags: string[] = [], {handlesSignals = false} = {}): Promise<Browser> {
    return chromium.launch({
        channel: 'chrome',
        headless: true,
        args: [...extraFlags, ...CHROME_FLAGS],
        handleSIGINT: !handlesSignals,
        handleSIGTERM: !handlesSignals,
    });
}

/** Prints what the page draws with, so a run without the GPU shows */
export async function printRenderer(page: Page): Promise<void> {
    const renderer = await page.evaluate(() => {
        const gl = document.createElement('canvas').getContext('webgl');
        if (!gl) {
            return 'no WebGL';
        }
        const rendererInfo = gl.getExtension('WEBGL_debug_renderer_info');
        return rendererInfo ? gl.getParameter(rendererInfo.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER);
    });
    console.log('Drawing with', renderer);
}
