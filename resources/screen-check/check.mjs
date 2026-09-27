// Opens the pages of an app at phone, tablet and computer widths and prints
// one JSON line of what it measured: each page's status, which screen it
// showed, how far it scrolls sideways, what sticks out past its edge, the errors its scripts threw, and, at
// phone width, the controls too small to tap. It measures only; the builder
// decides what a measurement means.
//
// Run it from the app's folder once the app is built and migrated:
//
//   node check.mjs '{"pages": ["/", "/dashboard"]}'
//
// Without "pages" it serves the app with `php artisan serve`, visits every
// GET route without parameters (up to "limit"), and signs in as a new user
// made for this check to see the pages behind a login. With "base" it
// measures an app that is already running there.

import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';

// The box image installs the browser here (docker/box/Dockerfile). The box
// runner passes commands only a short list of environment variables, so the
// check says where it is itself, before Playwright looks.
process.env.PLAYWRIGHT_BROWSERS_PATH ??= '/opt/ms-playwright';
const { chromium } = await import('playwright-core');

// WCAG 2.2 AA (2.5.8) asks for 24 by 24 CSS pixels, or enough space around a
// smaller target; a link inside running text is exempt, as in the guideline.
const MIN_TARGET = 24;
const PHONE = 390;
const MAX_LISTED = 5;
const PORT = 8123;

// Routes that are not screens, or that change the app when visited.
const NOT_SCREENS =
    /^(api|sanctum|_|\.well-known|storage|broadcasting|livewire|telescope|horizon|pulse|up$)|log-?out|verif|confirm|two-factor|passkey|export|download/i;

const input = JSON.parse(process.argv[2] ?? '{}');
const widths = input.widths ?? [PHONE, 820, 1280];
const limit = input.limit ?? 15;
const server = input.base ? null : serve();
const base = new URL(input.base ?? `http://127.0.0.1:${PORT}`);

try {
    await reachable(base);
    const paths = input.pages ?? routes();
    const browser = await chromium.launch({
        executablePath: process.env.SCREEN_CHECK_CHROMIUM || undefined,
    });

    try {
        // Signed out first; a page that sends a guest to sign in is measured
        // again signed in.
        const pages = [];

        for (const path of paths) {
            pages.push(await measure(browser, path, []));
        }

        const behindLogin = pages.filter(
            (page) => page.final !== page.path && page.final !== null,
        );
        const cookies =
            behindLogin.length > 0 && !input.pages ? await signIn(browser) : [];

        for (const page of cookies.length > 0 ? behindLogin : []) {
            pages[pages.indexOf(page)] = {
                ...(await measure(browser, page.path, cookies)),
                signed_in: true,
            };
        }

        process.stdout.write(
            JSON.stringify({ pages, signed_in: cookies.length > 0 }) + '\n',
        );
    } finally {
        await browser.close();
    }
} finally {
    server?.kill();
}

// Serve the app from this folder.
function serve() {
    return spawn(
        'php',
        [
            'artisan',
            'serve',
            '--host=127.0.0.1',
            `--port=${PORT}`,
            '--no-reload',
        ],
        {
            stdio: 'ignore',
        },
    );
}

// Wait up to 30 seconds for the app to answer.
async function reachable(url) {
    for (let attempt = 0; attempt < 60; attempt++) {
        try {
            await fetch(url, { redirect: 'manual' });

            return;
        } catch {
            await new Promise((resolve) => setTimeout(resolve, 500));
        }
    }

    throw new Error(`The app did not answer at ${url.href}.`);
}

// The app's GET routes without parameters, home first.
function routes() {
    const listed = spawnSync(
        'php',
        ['artisan', 'route:list', '--json', '--method=GET'],
        { encoding: 'utf8' },
    );
    const uris = JSON.parse(listed.stdout || '[]')
        .map((route) => route.uri)
        .filter((uri) => !uri.includes('{') && !NOT_SCREENS.test(uri));

    return [...new Set(uris)]
        .sort((a, b) => (b === '/') - (a === '/'))
        .slice(0, limit)
        .map((uri) => (uri === '/' ? '/' : `/${uri}`));
}

// Make a user for this check and sign in through the app's own sign-in
// form. Returns the session cookies, or none when the app has no such user
// model or form.
async function signIn(browser) {
    const email = `screen-check-${randomBytes(4).toString('hex')}@example.test`;
    const password = randomBytes(16).toString('hex');
    const made = spawnSync(
        'php',
        [
            'artisan',
            'tinker',
            '--execute',
            [
                'if (class_exists(\\App\\Models\\User::class)) {',
                '    \\App\\Models\\User::forceCreate(["name" => "Screen check", "email" => getenv("SCREEN_EMAIL"), "password" => \\Illuminate\\Support\\Facades\\Hash::make(getenv("SCREEN_PASSWORD")), "email_verified_at" => now()]);',
                '}',
            ].join(' '),
        ],
        {
            encoding: 'utf8',
            env: {
                ...process.env,
                SCREEN_EMAIL: email,
                SCREEN_PASSWORD: password,
            },
        },
    );

    if (made.status !== 0) {
        return [];
    }

    const context = await browser.newContext();

    try {
        const page = await context.newPage();
        await page.goto(new URL(input.login ?? '/login', base).href, {
            waitUntil: 'networkidle',
            timeout: 30000,
        });
        await page
            .locator('input[type=email], input[name=email]')
            .first()
            .fill(email, { timeout: 5000 });
        await page
            .locator('input[type=password]')
            .first()
            .fill(password, { timeout: 5000 });
        await Promise.all([
            page.waitForURL((url) => !url.pathname.startsWith('/login'), {
                timeout: 15000,
            }),
            page.locator('input[type=password]').first().press('Enter'),
        ]);

        return await context.cookies();
    } catch {
        return [];
    } finally {
        await context.close();
    }
}

// Open one page at each width and measure it.
async function measure(browser, path, cookies) {
    const result = {
        path,
        status: null,
        final: null,
        screen: null,
        widths: [],
    };

    for (const width of widths) {
        const context = await browser.newContext({
            viewport: { width, height: 900 },
            isMobile: width <= PHONE,
            hasTouch: width <= PHONE,
        });
        await context.addCookies(cookies);
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) =>
            errors.push(String(error.message).slice(0, 300)),
        );

        try {
            const response = await page.goto(new URL(path, base).href, {
                waitUntil: 'networkidle',
                timeout: 30000,
            });
            result.status ??= response?.status() ?? null;
            result.final ??= new URL(page.url()).pathname;

            const measured = await page.evaluate(
                ({ phone, minTarget, maxListed }) => {
                    const root = document.documentElement;
                    const small = [];
                    const controls =
                        'a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button], [role=link]';

                    // A phone zooms out on a page wider than itself, so the
                    // width asked for decides, not the one reported.
                    if (phone) {
                        const boxes = [];

                        for (const control of document.querySelectorAll(
                            controls,
                        )) {
                            const box = control.getBoundingClientRect();
                            const style = getComputedStyle(control);

                            // Hidden, or only there for screen readers.
                            if (
                                box.width <= 1 ||
                                box.height <= 1 ||
                                style.visibility === 'hidden'
                            ) {
                                continue;
                            }

                            // A link in a line of text is exempt.
                            const inline =
                                style.display === 'inline' &&
                                control.parentElement &&
                                control.parentElement.textContent.trim()
                                    .length > control.textContent.trim().length;

                            boxes.push({
                                control,
                                box,
                                undersized:
                                    !inline &&
                                    (box.width < minTarget ||
                                        box.height < minTarget),
                            });
                        }

                        // WCAG's spacing exception: an undersized target
                        // passes when a 24 px circle on its centre touches no
                        // other target, nor the circle of another undersized one.
                        const radius = minTarget / 2;
                        const centre = ({ box }) => ({
                            x: box.left + box.width / 2,
                            y: box.top + box.height / 2,
                        });
                        const touches = (a, b) => {
                            const c = centre(a);

                            if (b.undersized) {
                                const d = centre(b);

                                return (
                                    Math.hypot(c.x - d.x, c.y - d.y) < minTarget
                                );
                            }

                            const x = Math.max(
                                b.box.left,
                                Math.min(c.x, b.box.right),
                            );
                            const y = Math.max(
                                b.box.top,
                                Math.min(c.y, b.box.bottom),
                            );

                            return Math.hypot(c.x - x, c.y - y) < radius;
                        };

                        for (const target of boxes) {
                            if (
                                target.undersized &&
                                boxes.some(
                                    (other) =>
                                        other !== target &&
                                        !other.control.contains(
                                            target.control,
                                        ) &&
                                        !target.control.contains(
                                            other.control,
                                        ) &&
                                        touches(target, other),
                                )
                            ) {
                                small.push({
                                    text: (
                                        target.control.getAttribute(
                                            'aria-label',
                                        ) ||
                                        target.control.textContent ||
                                        target.control.getAttribute('name') ||
                                        target.control.tagName
                                    )
                                        .trim()
                                        .replace(/\s+/g, ' ')
                                        .slice(0, 60),
                                    width: Math.round(target.box.width),
                                    height: Math.round(target.box.height),
                                });
                            }
                        }
                    }

                    // Parts that stick out past the screen's edge. A layout
                    // that hides sideways overflow does not scroll; it cuts
                    // them off. Parts inside a box that scrolls sideways (a
                    // wide table, say) can still be reached, so they pass.
                    const cut = [];
                    const edge = document.documentElement.clientWidth;
                    const scrolls = (element) =>
                        ['auto', 'scroll'].includes(
                            getComputedStyle(element).overflowX,
                        );
                    const outside = (box) =>
                        box.width > 1 &&
                        box.height > 1 &&
                        (box.right > edge + 1 || box.left < -1);
                    // Decoration may bleed off the edge; words and controls may not.
                    const holdsSomething = (element) =>
                        (element.innerText ?? '').trim() !== '' ||
                        element.matches(controls) ||
                        element.querySelector(controls) !== null;

                    for (const element of document.body.querySelectorAll('*')) {
                        const style = getComputedStyle(element);

                        if (
                            style.visibility === 'hidden' ||
                            style.position === 'fixed' ||
                            !outside(element.getBoundingClientRect()) ||
                            !holdsSomething(element)
                        ) {
                            continue;
                        }

                        let parent = element.parentElement;
                        let reachable = false;

                        while (parent && parent !== document.body) {
                            if (
                                scrolls(parent) ||
                                getComputedStyle(parent).position === 'fixed'
                            ) {
                                reachable = true;
                                break;
                            }

                            parent = parent.parentElement;
                        }

                        // Only the outermost part that sticks out is named.
                        if (
                            !reachable &&
                            !(
                                element.parentElement &&
                                element.parentElement !== document.body &&
                                outside(
                                    element.parentElement.getBoundingClientRect(),
                                )
                            )
                        ) {
                            const box = element.getBoundingClientRect();

                            cut.push({
                                text: (
                                    element.getAttribute('aria-label') ||
                                    element.textContent ||
                                    element.tagName
                                )
                                    .trim()
                                    .replace(/\s+/g, ' ')
                                    .slice(0, 60),
                                width: Math.round(box.width),
                                past: Math.round(
                                    Math.max(box.right - edge, -box.left),
                                ),
                            });
                        }
                    }

                    // Words too faint to read against what is behind them
                    // (WCAG 2.2 AA 1.4.3): 4.5 to 1, or 3 to 1 for large
                    // text. A canvas turns any CSS colour (oklch too) into
                    // sRGB. Text over a picture or gradient is unknown, and
                    // disabled controls are exempt, as in the guideline.
                    const faint = [];

                    if (phone) {
                        const canvas = document
                            .createElement('canvas')
                            .getContext('2d', { willReadFrequently: true });
                        const rgba = (colour) => {
                            canvas.clearRect(0, 0, 1, 1);
                            canvas.fillStyle = '#000';
                            canvas.fillStyle = colour;
                            canvas.fillRect(0, 0, 1, 1);
                            const [r, g, b, a] = canvas.getImageData(
                                0,
                                0,
                                1,
                                1,
                            ).data;

                            return [r, g, b, a / 255];
                        };
                        const over = ([r, g, b, a], [br, bg, bb]) => [
                            r * a + br * (1 - a),
                            g * a + bg * (1 - a),
                            b * a + bb * (1 - a),
                            1,
                        ];
                        const luminance = ([r, g, b]) => {
                            const [lr, lg, lb] = [r, g, b].map((value) => {
                                const c = value / 255;

                                return c <= 0.04045
                                    ? c / 12.92
                                    : ((c + 0.055) / 1.055) ** 2.4;
                            });

                            return 0.2126 * lr + 0.7152 * lg + 0.0722 * lb;
                        };
                        const behind = (element) => {
                            const layers = [];

                            for (
                                let node = element;
                                node;
                                node = node.parentElement
                            ) {
                                const style = getComputedStyle(node);

                                if (style.backgroundImage !== 'none') {
                                    return null;
                                }

                                const colour = rgba(style.backgroundColor);

                                if (colour[3] > 0) {
                                    layers.push(colour);
                                }

                                if (colour[3] === 1) {
                                    break;
                                }
                            }

                            return layers.reduceRight(
                                (below, layer) => over(layer, below),
                                [255, 255, 255, 1],
                            );
                        };

                        for (const element of document.body.querySelectorAll(
                            '*',
                        )) {
                            const words = [...element.childNodes]
                                .filter(
                                    (node) => node.nodeType === Node.TEXT_NODE,
                                )
                                .map((node) => node.textContent)
                                .join('')
                                .trim();
                            const box = element.getBoundingClientRect();
                            const style = getComputedStyle(element);

                            if (
                                words === '' ||
                                box.width <= 1 ||
                                box.height <= 1 ||
                                style.visibility === 'hidden' ||
                                element.closest(
                                    '[aria-hidden=true], :disabled, [aria-disabled=true], script, style, noscript',
                                )
                            ) {
                                continue;
                            }

                            let hidden = false;

                            for (
                                let node = element;
                                node;
                                node = node.parentElement
                            ) {
                                if (
                                    Number(getComputedStyle(node).opacity) < 1
                                ) {
                                    hidden = true;
                                    break;
                                }
                            }

                            const background = hidden ? null : behind(element);

                            if (background === null) {
                                continue;
                            }

                            const text = over(rgba(style.color), background);
                            const [light, dark] = [
                                luminance(text),
                                luminance(background),
                            ].sort((a, b) => b - a);
                            const ratio = (light + 0.05) / (dark + 0.05);
                            const size = parseFloat(style.fontSize);
                            const needed =
                                size >= 24 ||
                                (size >= 18.66 &&
                                    Number(style.fontWeight) >= 700)
                                    ? 3
                                    : 4.5;

                            if (ratio < needed) {
                                faint.push({
                                    text: words
                                        .replace(/\s+/g, ' ')
                                        .slice(0, 60),
                                    ratio: Math.round(ratio * 100) / 100,
                                    needed,
                                });
                            }
                        }
                    }

                    // The Inertia page component, which names the screen's file.
                    let screen = null;
                    const data = document.querySelector(
                        'script[data-page][type="application/json"], [data-page]',
                    );

                    try {
                        screen =
                            JSON.parse(
                                data?.tagName === 'SCRIPT'
                                    ? data.textContent
                                    : (data?.dataset.page ?? 'null'),
                            )?.component ?? null;
                    } catch {
                        screen = null;
                    }

                    return {
                        screen,
                        overflow: Math.max(
                            0,
                            root.scrollWidth - root.clientWidth,
                        ),
                        cut_off: cut.length,
                        cut: cut.slice(0, maxListed),
                        small_targets: small.length,
                        small: small.slice(0, maxListed),
                        low_contrast: faint.length,
                        faint: faint.slice(0, maxListed),
                    };
                },
                {
                    phone: width <= PHONE,
                    minTarget: MIN_TARGET,
                    maxListed: MAX_LISTED,
                },
            );

            result.screen ??= measured.screen;
            delete measured.screen;
            result.widths.push({
                width,
                ...measured,
                errors: errors.slice(0, MAX_LISTED),
            });
        } catch (error) {
            result.widths.push({
                width,
                failed: String(error.message).split('\n')[0].slice(0, 300),
            });
        } finally {
            await context.close();
        }
    }

    return result;
}
