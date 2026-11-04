#!/usr/bin/env node
// ITK CMS critical CSS: the Node side of Itk\Cms\CriticalCss\NodeCssExtractor (that class holds the wire contract, version 1).
// PHP has rendered every page and prepared the source CSS; this script only measures. It never fetches the live site: a server on
// 127.0.0.1 answers each page's HTML at the page's own path and every other path from public/, and crittr measures that.

import { existsSync, readFileSync, statSync } from 'node:fs';
import { createServer } from 'node:http';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const EX_USAGE = 64;
const EX_UNAVAILABLE = 69;
const EX_SOFTWARE = 70;
const EX_DEADLINE = 124;

const CONTENT_TYPES = {
    '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.json': 'application/json',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.gif': 'image/gif',
    '.webp': 'image/webp',
    '.avif': 'image/avif',
    '.ico': 'image/x-icon',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
    '.ttf': 'font/ttf',
};

function fail(code, message) {
    process.stderr.write(`${message}\n`);
    process.exit(code);
}

/** Error | unknown[] | anything => one short line. crittr rejects with an array of errors when every URL failed. */
function describe(error) {
    const one = (e) => (e instanceof Error ? e.message : typeof e === 'string' ? e : JSON.stringify(e));
    const text = Array.isArray(error) ? error.map(one).join('; ') : one(error);

    return String(text ?? 'unknown error').replace(/\s+/g, ' ').trim().slice(0, 300);
}

function readInput() {
    let input;
    try {
        input = JSON.parse(readFileSync(0, 'utf8'));
    } catch (e) {
        fail(EX_USAGE, `bad input: ${describe(e)}`);
    }
    const isList = (v, check) => Array.isArray(v) && v.every(check);
    const ok =
        input &&
        input.v === 1 &&
        typeof input.projectDir === 'string' &&
        typeof input.publicDir === 'string' &&
        (input.chrome === null || typeof input.chrome === 'string') &&
        Number.isInteger(input.deadlineMs) &&
        Number.isInteger(input.timeoutMs) &&
        typeof input.css === 'string' &&
        isList(input.viewports, (v) => v && Number.isInteger(v.width) && Number.isInteger(v.height) && typeof v.mobile === 'boolean') &&
        input.viewports.length > 0 &&
        isList(input.keepSelectors ?? [], (s) => typeof s === 'string') &&
        isList(input.removeSelectors ?? [], (s) => typeof s === 'string') &&
        isList(input.pages, (p) => p && typeof p.id === 'string' && typeof p.path === 'string' && typeof p.html === 'string') &&
        input.pages.length > 0;
    if (!ok) {
        fail(EX_USAGE, 'bad input: not a version 1 job');
    }

    return input;
}

/** The entry file of a package from its package.json: exports['.'].import first (crittr exports only that), then main. */
function entryOf(dir) {
    const pkg = JSON.parse(readFileSync(path.join(dir, 'package.json'), 'utf8'));
    let target = pkg.exports?.['.'] ?? pkg.exports;
    while (target && typeof target === 'object') {
        target = target.import ?? target.default ?? target.node;
    }
    const file = typeof target === 'string' ? target : pkg.main;
    if (typeof file !== 'string') {
        throw new Error(`${pkg.name}: no entry in package.json`);
    }

    return path.join(dir, file);
}

/** <dir>/node_modules/<name>, from `from` upwards to `root`. */
function packageDir(name, from, root) {
    let dir = from;
    for (;;) {
        const candidate = path.join(dir, 'node_modules', name);
        if (existsSync(path.join(candidate, 'package.json'))) {
            return candidate;
        }
        if (dir === root || path.dirname(dir) === dir) {
            return null;
        }
        dir = path.dirname(dir);
    }
}

/**
 * crittr from the SITE's node_modules and puppeteer from next to crittr (the copy crittr uses), never from this file's location:
 * the bundle may be a symlinked path repository. Node < 22 or a missing package => "not installed: …", exit EX_UNAVAILABLE.
 */
async function loadLibraries(projectDir) {
    const major = Number(process.versions.node.split('.')[0]);
    if (major < 22) {
        fail(EX_UNAVAILABLE, `not installed: Node 22 or newer is needed, this is ${process.versions.node}`);
    }
    const crittrDir = packageDir('crittr', projectDir, projectDir);
    if (crittrDir === null) {
        fail(EX_UNAVAILABLE, `not installed: crittr is not in ${path.join(projectDir, 'node_modules')} (npm install crittr)`);
    }
    const puppeteerDir = packageDir('puppeteer', crittrDir, projectDir);
    if (puppeteerDir === null) {
        fail(EX_UNAVAILABLE, 'not installed: puppeteer is not next to crittr (npm install crittr)');
    }
    try {
        const crittr = (await import(pathToFileURL(entryOf(crittrDir)).href)).default;
        const puppeteer = (await import(pathToFileURL(entryOf(puppeteerDir)).href)).default;

        return { crittr, puppeteer };
    } catch (e) {
        fail(EX_UNAVAILABLE, `not installed: ${describe(e)}`);
    }
}

/**
 * 127.0.0.1, ephemeral port, GET and HEAD only: the current page's path answers its HTML, anything else the file under publicDir
 * (resolved and checked to stay inside it; the query is ignored, so ?v= works), else 404. Image variants PHP makes on first request
 * may 404 here: the layout does not wait for them (itk_img prints width and height).
 */
function startServer(publicDir) {
    const root = path.resolve(publicDir);
    let current = null;
    const server = createServer((req, res) => {
        const url = new URL(req.url ?? '/', 'http://127.0.0.1');
        let pathname;
        try {
            pathname = decodeURIComponent(url.pathname);
        } catch {
            pathname = url.pathname;
        }
        if (req.method !== 'GET' && req.method !== 'HEAD') {
            res.writeHead(405).end();
            return;
        }
        if (current !== null && pathname === current.path) {
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            res.end(req.method === 'HEAD' ? undefined : current.html);
            return;
        }
        const file = path.resolve(root, `.${pathname}`);
        if (!file.startsWith(root + path.sep) || !existsSync(file) || !statSync(file).isFile()) {
            res.writeHead(404).end();
            return;
        }
        res.writeHead(200, { 'Content-Type': CONTENT_TYPES[path.extname(file).toLowerCase()] ?? 'application/octet-stream' });
        res.end(req.method === 'HEAD' ? undefined : readFileSync(file));
    });

    return new Promise((resolve, reject) => {
        server.on('error', reject);
        server.listen(0, '127.0.0.1', () => {
            resolve({
                origin: `http://127.0.0.1:${server.address().port}`,
                setPage(page) {
                    current = page;
                },
                close() {
                    server.close();
                },
            });
        });
    });
}

/**
 * One browser for the whole batch (crittr takes it as puppeteer.browser and then does not close it). pipe: true instead of a websocket,
 * so Chrome loses its parent and leaves when this process dies hard; the same sandbox flags crittr uses (Docker, root).
 */
async function launchBrowser(puppeteer, chrome) {
    try {
        return await puppeteer.launch({
            headless: true,
            pipe: true,
            executablePath: chrome ?? undefined,
            acceptInsecureCerts: true,
            args: ['--disable-setuid-sandbox', '--no-sandbox', '--ignore-certificate-errors', '--disable-dev-shm-usage'],
        });
    } catch (e) {
        fail(EX_UNAVAILABLE, `not installed: Chrome did not start: ${describe(e)}`);
    }
}

function report(line) {
    return new Promise((resolve) => process.stdout.write(`${JSON.stringify(line)}\n`, () => resolve()));
}

async function main() {
    const input = readInput();
    const { crittr, puppeteer } = await loadLibraries(input.projectDir);
    const server = await startServer(input.publicDir);
    const browser = await launchBrowser(puppeteer, input.chrome);

    let stopping = false;
    // Own deadline below PHP's: close Chrome first, then leave with EX_DEADLINE (pages without a line become 'timeout' in PHP).
    // SIGTERM from Process::stop() takes the same path. A browser that will not close is killed, so the exit never waits on it.
    const stop = async (code) => {
        if (stopping) {
            return;
        }
        stopping = true;
        const kill = setTimeout(() => browser.process()?.kill('SIGKILL'), 3000);
        await browser.close().catch(() => {});
        clearTimeout(kill);
        server.close();
        process.exit(code);
    };
    setTimeout(() => stop(EX_DEADLINE), input.deadlineMs).unref();
    process.on('SIGTERM', () => stop(EX_DEADLINE));
    process.on('SIGINT', () => stop(EX_DEADLINE));

    // crittr reads a `css` string that ends in ".css" as a file path: a newline keeps the prepared CSS a string
    const css = `${input.css}\n`;
    for (const page of input.pages) {
        if (stopping) {
            break;
        }
        server.setPage(page);
        const url = server.origin + page.path;
        try {
            const perViewport = [];
            for (const viewport of input.viewports) {
                // sequential: one tab at a time keeps the memory of the batch near one page, not ten
                const { critical } = await crittr({
                    urls: [url],
                    css,
                    device: { width: viewport.width, height: viewport.height, isMobile: viewport.mobile, hasTouch: viewport.mobile },
                    puppeteer: { browser },
                    browser: { concurrentTabs: 1 },
                    timeout: input.timeoutMs,
                    outputRemainingCss: false,
                    keepSelectors: input.keepSelectors ?? [],
                    removeSelectors: input.removeSelectors ?? [],
                });
                perViewport.push(typeof critical === 'string' ? critical : '');
            }
            await report({ id: page.id, ok: true, css: perViewport });
        } catch (e) {
            if (stopping) {
                break; // the browser was closed under this page by the deadline: PHP records it as a timeout, not as a page error
            }
            await report({ id: page.id, ok: false, error: describe(e) });
        }
    }
    await stop(0);
}

main().catch((e) => fail(EX_SOFTWARE, describe(e)));
