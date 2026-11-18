/**
 * The pointer and keyboard at a person's pace: curved, unevenly paced moves,
 * a little past a far target and back, and typing with small hesitations
 */
import {setTimeout as sleep} from 'node:timers/promises';
import type {Page} from 'playwright';

/** A point on the page, in CSS pixels */
export type Point = [number, number];

const MOVES_PER_SECOND = 60;

/** Waits this many seconds */
export function waitSeconds(seconds: number): Promise<void> {
    return sleep(seconds * 1000);
}

/** Slow, fast, slow from 0 to 1 */
function smootherStep(t: number): number {
    return t * t * t * (10 - 15 * t + 6 * t * t);
}

/** Like smootherStep, a little gentler */
function smoothStep(t: number): number {
    return t * t * (3 - 2 * t);
}

/**
 * Random numbers that come out the same on every run, so a recording
 * repeats; Math.random can't be seeded
 */
export class SeededRandom {
    private state: number;

    constructor(seed: number) {
        this.state = seed >>> 0;
    }

    /** The next number in [0, 1): mulberry32, small and good enough for a pointer */
    next(): number {
        this.state = (this.state + 0x6d2b79f5) >>> 0;
        let mixed = Math.imul(this.state ^ (this.state >>> 15), this.state | 1);
        mixed ^= mixed + Math.imul(mixed ^ (mixed >>> 7), mixed | 61);
        return ((mixed ^ (mixed >>> 14)) >>> 0) / 4294967296;
    }

    between(low: number, high: number): number {
        return low + (high - low) * this.next();
    }

    /** Around the mean, by a bell curve (Box-Muller) */
    nearMean(mean: number, spread: number): number {
        const fromZero = Math.sqrt(-2 * Math.log(1 - this.next())) * Math.cos(2 * Math.PI * this.next());
        return mean + spread * fromZero;
    }

    eitherSign(): number {
        return this.next() < 0.5 ? -1 : 1;
    }
}

/** Someone at the keyboard and mouse */
export class Person {
    readonly page: Page;
    readonly random: SeededRandom;
    at: Point;

    constructor(page: Page, random: SeededRandom, at: Point) {
        this.page = page;
        this.random = random;
        this.at = at;
    }

    /**
     * Moves along a path, given as the point at each moment from 0 to 1.
     * Each move goes out on the clock, its wait less the time the move took to
     * reach Chrome, so the pointer really moves MOVES_PER_SECOND times a second
     * and the recording has a new frame for each
     */
    async glide(pointAt: (t: number) => Point, seconds: number): Promise<void> {
        const steps = Math.max(6, Math.floor(seconds * MOVES_PER_SECOND));
        const startedAt = performance.now();
        for (let step = 1; step <= steps; step++) {
            const [x, y] = pointAt(step / steps);
            await this.page.mouse.move(x, y);
            const dueAt = startedAt + (step / steps) * seconds * 1000;
            await sleep(Math.max(0, dueAt - performance.now()));
        }
        this.at = pointAt(1);
    }

    /**
     * Moves on a gentle curve, slow-fast-slow, longer for longer moves
     * (Fitts), and a little past a far target, then back onto it
     */
    async moveTo([x, y]: Point): Promise<void> {
        const [startX, startY] = this.at;
        const dx = x - startX;
        const dy = y - startY;
        const distance = Math.hypot(dx, dy);
        if (distance < 2) {
            return;
        }
        const seconds = (0.22 + 0.11 * Math.log2(distance / 20 + 1)) * this.random.between(0.9, 1.15);
        const overshoot = distance > 150 ? Math.min(9, distance * 0.035) : 0;
        const unitX = dx / distance;
        const unitY = dy / distance;
        const endX = x + unitX * overshoot + this.random.between(-2, 2);
        const endY = y + unitY * overshoot + this.random.between(-2, 2);
        // The curve bends to one side by a share of the distance
        const bend = distance * this.random.between(0.06, 0.16) * this.random.eitherSign();
        const firstControl: Point = [startX + dx * 0.3 - unitY * bend, startY + dy * 0.3 + unitX * bend];
        const secondControl: Point = [startX + dx * 0.75 - unitY * bend * 0.5, startY + dy * 0.75 + unitX * bend * 0.5];

        const curvePointAt = (t: number): Point => {
            const done = smootherStep(t);
            const left = 1 - done;
            const weights = [left ** 3, 3 * left * left * done, 3 * left * done * done, done ** 3];
            return [
                weights[0] * startX + weights[1] * firstControl[0] + weights[2] * secondControl[0] + weights[3] * endX,
                weights[0] * startY + weights[1] * firstControl[1] + weights[2] * secondControl[1] + weights[3] * endY,
            ];
        };
        await this.glide(curvePointAt, seconds);

        if (overshoot) {
            const [pastX, pastY] = this.at;
            const backPointAt = (t: number): Point => [pastX + (x - pastX) * smoothStep(t), pastY + (y - pastY) * smoothStep(t)];
            await this.glide(backPointAt, this.random.between(0.12, 0.18));
        }
    }

    async click(where: Point): Promise<void> {
        await this.moveTo(where);
        await waitSeconds(this.random.between(0.08, 0.16));
        // Pressed exactly on the target, wherever the move's wobble ended
        await this.page.mouse.move(...where);
        await this.page.mouse.down();
        await waitSeconds(0.05);
        await this.page.mouse.up();
        await waitSeconds(0.05);
    }

    /** Presses, carries it across a little slower than a plain move, lets go */
    async drag(start: Point, end: Point): Promise<void> {
        await this.moveTo(start);
        await waitSeconds(this.random.between(0.15, 0.25));
        await this.page.mouse.move(...start);
        await this.page.mouse.down();
        await waitSeconds(0.12);
        const [startX, startY] = start;
        const [endX, endY] = end;
        // Straight across, with a slight sag in the middle
        const dragPointAt = (t: number): Point => [
            startX + (endX - startX) * smootherStep(t),
            startY + (endY - startY) * smootherStep(t) + Math.sin(Math.PI * t) * 3,
        ];
        await this.glide(dragPointAt, this.random.between(0.7, 0.9));
        await waitSeconds(0.15);
        await this.page.mouse.up();
    }

    async type(text: string): Promise<void> {
        // By code point, so an emoji goes in whole
        for (const character of text) {
            await this.page.keyboard.insertText(character);
            let pause = this.random.nearMean(0.11, 0.035);
            if (' ,?!'.includes(character) && this.random.next() < 0.25) {
                pause += this.random.between(0.15, 0.4);
            }
            await waitSeconds(Math.min(0.5, Math.max(0.04, pause)));
        }
    }

    async pressEnter(): Promise<void> {
        await this.page.keyboard.press('Enter');
    }
}
