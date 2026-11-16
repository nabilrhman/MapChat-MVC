/**
 * Runs the browser checks in the Chrome installed here, at a desktop and a
 * phone width, against the app at MAPCHAT_URL (default the local one).
 * MAPCHAT_GPU=0 turns off the GPU flags, for a machine without one
 */
import {defineConfig, devices} from '@playwright/test';

const usesGpu = process.env.MAPCHAT_GPU !== '0';
const gpuFlags = ['--enable-gpu', '--ignore-gpu-blocklist', '--use-gl=angle', '--use-angle=gl-egl'];

export default defineConfig({
    testDir: '.',
    // One guest at a time: they share the app, its demo and its rate limits
    workers: 1,
    timeout: 120_000,
    reporter: 'list',
    use: {
        baseURL: process.env.MAPCHAT_URL ?? 'http://localhost:8080',
        channel: 'chrome',
        launchOptions: {args: usesGpu ? gpuFlags : []},
    },
    projects: [
        {name: 'desktop', use: {viewport: {width: 1280, height: 800}}},
        {name: 'phone', use: {...devices['Pixel 7'], channel: 'chrome'}},
    ],
});
