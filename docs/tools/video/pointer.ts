/**
 * The pointer drawn on the page, as headless Chrome draws none: a rounded
 * arrow, and the desktop's own hands wherever the page asks for them
 */
import {existsSync, readFileSync} from 'node:fs';
import {join} from 'node:path';
import {crc32, deflateSync} from 'node:zlib';

/** One of the desktop's cursors, ready for the page: its sizes are in CSS pixels */
export type ThemedCursor = {url: string, size: [number, number], tip: [number, number]};

// The hands are read from the installed cursor theme (Xcursor files) by the
// CSS cursor they stand for. Yaru's, or Adwaita's without it. Not kept in the repo
const THEME_CURSOR_FILES: Record<string, string> = {pointer: 'hand2', grab: 'grab', grabbing: 'grabbing'};
const THEMES = ['/usr/share/icons/Yaru/cursors', '/usr/share/icons/Adwaita/cursors'];
// The cursors' size on the page, in CSS pixels; the theme's image for
// CURSOR_SIZE × scale is drawn at it, so they stay sharp
const CURSOR_SIZE = 24;
const XCURSOR_IMAGE = 0xfffd0002;

function pngChunk(tag: string, body: Buffer): Buffer {
    const tagAndBody = Buffer.concat([Buffer.from(tag, 'latin1'), body]);
    const length = Buffer.alloc(4);
    length.writeUInt32BE(body.length);
    const checksum = Buffer.alloc(4);
    checksum.writeUInt32BE(crc32(tagAndBody));
    return Buffer.concat([length, tagAndBody, checksum]);
}

/** A PNG of plain RGBA pixels, row after row */
function pngOf(width: number, height: number, rgba: Buffer): Buffer {
    const header = Buffer.alloc(13);
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    // 8 bits a channel, RGBA, no interlace
    header.set([8, 6, 0, 0, 0], 8);
    const rows: Buffer[] = [];
    for (let y = 0; y < height; y++) {
        // Each row starts with its filter: none
        rows.push(Buffer.from([0]), rgba.subarray(y * width * 4, (y + 1) * width * 4));
    }
    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        pngChunk('IHDR', header),
        pngChunk('IDAT', deflateSync(Buffer.concat(rows))),
        pngChunk('IEND', Buffer.alloc(0)),
    ]);
}

/** An Xcursor file's image nearest CURSOR_SIZE × scale, as a PNG data URL with its size and tip */
function cursorFromXcursor(path: string, scale: number): ThemedCursor {
    const file = readFileSync(path);
    const imageCount = file.readUInt32LE(12);
    let nearestPosition = 0;
    let nearestDistance = Infinity;
    for (let i = 0; i < imageCount; i++) {
        const entry = 16 + i * 12;
        const distance = Math.abs(file.readUInt32LE(entry + 4) - CURSOR_SIZE * scale);
        if (file.readUInt32LE(entry) === XCURSOR_IMAGE && distance < nearestDistance) {
            nearestDistance = distance;
            nearestPosition = file.readUInt32LE(entry + 8);
        }
    }
    const width = file.readUInt32LE(nearestPosition + 16);
    const height = file.readUInt32LE(nearestPosition + 20);
    const tipX = file.readUInt32LE(nearestPosition + 24);
    const tipY = file.readUInt32LE(nearestPosition + 28);
    // ARGB with the alpha already applied, to plain RGBA
    const rgba = Buffer.alloc(width * height * 4);
    for (let i = 0; i < width * height; i++) {
        const argb = file.readUInt32LE(nearestPosition + 36 + i * 4);
        const alpha = argb >>> 24;
        const unmix = (channel: number) => (alpha ? Math.min(255, Math.floor(channel * 255 / alpha)) : 0);
        rgba.set([unmix((argb >>> 16) & 255), unmix((argb >>> 8) & 255), unmix(argb & 255), alpha], i * 4);
    }
    return {
        url: `data:image/png;base64,${pngOf(width, height, rgba).toString('base64')}`,
        size: [width / scale, height / scale],
        tip: [tipX / scale, tipY / scale],
    };
}

/** The desktop's own cursors, by CSS cursor */
export function readThemeCursors(scale: number): Record<string, ThemedCursor> {
    for (const theme of THEMES) {
        const files = Object.entries(THEME_CURSOR_FILES).map(([css, name]) => [css, join(theme, name)]);
        if (files.every(([, path]) => existsSync(path))) {
            return Object.fromEntries(files.map(([css, path]) => [css, cursorFromXcursor(path, scale)]));
        }
    }
    throw new Error('No cursor theme found: install the Yaru or Adwaita cursor theme');
}

/**
 * Runs in every page before its own scripts: draws the pointer, pointing
 * over anything clickable, open over the map, closed while dragging. It's put
 * back where the last page left it
 */
export function drawPointer(themed: Record<string, ThemedCursor>): void {
    // In the hands' colours: Yaru's grey gradient and thin white edge
    const ARROW = '<svg width="20" height="23" viewBox="0 0 26 30"><defs><linearGradient id="cursor-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#474747"/><stop offset="1" stop-color="#2d2d2d"/></linearGradient></defs><path transform="rotate(15 5 4)" d="M5.6 4.4c-.9-.4-1.8.5-1.4 1.4l7.4 18.2c.4 1 1.8.9 2.1-.1l2.2-6.7c.1-.4.4-.7.8-.8l6.7-2.2c1-.3 1.1-1.7.1-2.1z" fill="url(#cursor-fill)" stroke="#fff" stroke-width="1.2" stroke-linejoin="round"/></svg>';
    // Where each one's tip is in its box
    const tips: Record<string, [number, number]> = {arrow: [3.85, 3.07]};
    for (const [css, image] of Object.entries(themed)) {
        tips[css] = image.tip;
    }
    const startingPoint = window.name.startsWith('cursor:')
        ? window.name.slice(7).split(',').map(Number)
        : [720, 450];

    const addPointer = () => {
        const pointer = document.createElement('div');
        pointer.style.cssText = 'position:fixed;left:0;top:0;z-index:2147483647;pointer-events:none;will-change:transform';
        const pointerImage = document.createElement('div');
        pointerImage.style.cssText = 'transition:transform .12s ease-out';
        pointer.append(pointerImage);
        let shape = '';
        let at = startingPoint;

        const placePointer = (x: number, y: number) => {
            at = [x, y];
            const [tipX, tipY] = tips[shape];
            pointer.style.transform = `translate(${x - tipX}px, ${y - tipY}px)`;
            // Kept in the window's name, so the next page starts it here
            window.name = `cursor:${x},${y}`;
        };
        const showShape = (name: string) => {
            if (name === shape) {
                return;
            }
            shape = name;
            const image = themed[name];
            pointerImage.innerHTML = image
                ? `<img src="${image.url}" width="${image.size[0]}" height="${image.size[1]}" alt="" style="display:block">`
                : ARROW;
            // The theme's cursors have their own shadow
            pointerImage.style.filter = image ? 'none' : 'drop-shadow(0 1px 1.6px rgba(0,0,0,.45))';
            pointerImage.style.transformOrigin = tips[name].map((n) => `${n}px`).join(' ');
            placePointer(at[0], at[1]);
        };

        showShape('arrow');
        document.documentElement.append(pointer);
        document.addEventListener('mousemove', (event) => {
            const css = getComputedStyle(event.target as Element).cursor;
            showShape(themed[css] ? css : 'arrow');
            placePointer(event.clientX, event.clientY);
        }, true);
        // A click presses the pointer in a little and sends a thin purple
        // ring out from its tip, with a faint white edge so it shows on the map
        document.addEventListener('mousedown', (event) => {
            pointerImage.style.transform = 'scale(.84)';
            setTimeout(() => {
                pointerImage.style.transform = '';
            }, 150);
            const ring = document.createElement('div');
            ring.style.cssText = `position:fixed;left:${event.clientX - 22}px;top:${event.clientY - 22}px;width:44px;height:44px;box-sizing:border-box;border-radius:50%;border:2px solid rgba(103,58,183,.9);box-shadow:0 0 0 1px rgba(255,255,255,.8);z-index:2147483646;pointer-events:none;transition:transform .45s cubic-bezier(.2,.7,.3,1),opacity .45s ease-out;transform:scale(.23)`;
            document.documentElement.append(ring);
            requestAnimationFrame(() => requestAnimationFrame(() => {
                ring.style.transform = 'scale(1)';
                ring.style.opacity = '0';
            }));
            setTimeout(() => ring.remove(), 600);
        }, true);
    };

    if (document.documentElement) {
        addPointer();
    } else {
        document.addEventListener('DOMContentLoaded', addPointer, {once: true});
    }
}
