/**
 * Every page checked for accessibility problems with axe-core, before and
 * after signing in as a new guest. A page passes with no problems at all
 */
import AxeBuilder from '@axe-core/playwright';
import {expect, test, type Page} from '@playwright/test';

const PUBLIC_PAGES = ['/login', '/signup/new', '/password/forgot', '/privacy'];
const SIGNED_IN_PAGES = ['/app', '/profile/show', '/about'];

// The chat page fills in over its socket and draws the map; the others are ready on load
const SETTLE_MS: Record<string, number> = {'/app': 4000};

async function problemsOn(page: Page): Promise<string[]> {
    const {violations} = await new AxeBuilder({page}).analyze();
    return violations.map((violation) => {
        const where = violation.nodes.slice(0, 3).map((node) => node.target.join(' ')).join(', ');
        return `${violation.impact}: ${violation.id}: ${violation.help} (${where})`;
    });
}

async function visit(page: Page, path: string): Promise<void> {
    await page.goto(path);
    await page.waitForTimeout(SETTLE_MS[path] ?? 1500);
}

test('pages before signing in have no accessibility problems', async ({page}) => {
    for (const path of PUBLIC_PAGES) {
        await visit(page, path);
        expect(await problemsOn(page), path).toEqual([]);
    }
});

test('pages for a signed-in guest have no accessibility problems', async ({page}) => {
    await page.goto('/login');
    await page.locator('#guest-form button').click();
    await page.waitForURL('**/app');

    for (const path of SIGNED_IN_PAGES) {
        await visit(page, path);
        expect(await problemsOn(page), path).toEqual([]);
    }
});
