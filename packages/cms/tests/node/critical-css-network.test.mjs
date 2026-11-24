import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { mkdir, mkdtemp, rm, symlink, writeFile } from 'node:fs/promises';
import { createServer, request } from 'node:http';
import { once } from 'node:events';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { promisify } from 'node:util';
import { launchBrowser, startServer } from '../../node/critical-css.mjs';

function throughProxy(origin, url) {
    return new Promise((resolve, reject) => {
        const req = request(origin, { path: url }, (res) => {
            res.resume();
            res.on('end', () => resolve(res.statusCode));
        });
        req.on('error', reject);
        req.setTimeout(3000, () => req.destroy(new Error('Local render request timed out')));
        req.end();
    });
}

test('the browser requires the sandbox and TLS verification, and uses the restricted proxy', async () => {
    const origin = 'http://127.0.0.1:43210';
    let options;
    await launchBrowser({ launch: async (input) => { options = input; } }, null, origin);

    assert.equal(options.acceptInsecureCerts, false);
    assert.ok(options.args.includes(`--proxy-server=${origin}`));
    assert.ok(options.args.includes('--proxy-bypass-list=<-loopback>'));
    assert.ok(options.args.includes('--force-webrtc-ip-handling-policy=disable_non_proxied_udp'));
    for (const flag of ['--no-sandbox', '--disable-setuid-sandbox', '--ignore-certificate-errors']) {
        assert.ok(!options.args.includes(flag), flag);
    }
});

test('the proxy serves the render origin and refuses other localhost origins and TLS tunnels', async () => {
    let visits = 0;
    const other = createServer((_req, res) => {
        ++visits;
        res.end('local test fixture');
    });
    other.listen(0, '127.0.0.1');
    await once(other, 'listening');
    const otherUrl = `http://127.0.0.1:${other.address().port}/page`;
    const renderServer = await startServer(import.meta.dirname);
    renderServer.setPage({ path: '/page', html: '<h1>Local page</h1>' });

    try {
        assert.equal(await throughProxy(renderServer.origin, `${renderServer.origin}/page`), 200);
        assert.equal(await throughProxy(renderServer.origin, otherUrl), 403);
        assert.equal(await throughProxy(renderServer.origin, `https://127.0.0.1:${other.address().port}/page`), 403);
        const tunnel = request(renderServer.origin, { method: 'CONNECT', path: `127.0.0.1:${other.address().port}` });
        tunnel.end();
        const [response, socket] = await once(tunnel, 'connect');
        socket.destroy();
        assert.equal(response.statusCode, 403);
        assert.equal(visits, 0);
    } finally {
        renderServer.close();
        other.close();
    }
});

test('the render server only serves files whose canonical path is inside public', { timeout: 5000 }, async () => {
    const fixture = await mkdtemp(join(tmpdir(), 'itk-critical-public-'));
    const publicDir = join(fixture, 'public');
    const localCss = join(publicDir, 'site.css');
    const externalCss = join(fixture, 'outside.css');
    let server;
    try {
        await mkdir(publicDir);
        await writeFile(localCss, 'p { color: blue; }');
        await writeFile(externalCss, 'p { color: red; }');
        await symlink(localCss, join(publicDir, 'internal.css'));
        await symlink(externalCss, join(publicDir, 'external.css'));
        await symlink(fixture, join(publicDir, 'directory'));
        server = await startServer(publicDir);

        assert.equal(await throughProxy(server.origin, `${server.origin}/internal.css`), 200);
        assert.equal(await throughProxy(server.origin, `${server.origin}/external.css`), 404);
        assert.equal(await throughProxy(server.origin, `${server.origin}/directory/outside.css`), 404);
    } finally {
        server?.close();
        await rm(fixture, { recursive: true, force: true });
    }
});

const chrome = process.env.ITK_TEST_CHROME;
test('Chrome loads same-origin assets and blocks another localhost origin and a redirect to it', { skip: !chrome }, async (t) => {
    let visits = 0;
    const other = createServer((_req, res) => {
        ++visits;
        res.end('local test fixture');
    });
    other.listen(0, '127.0.0.1');
    await once(other, 'listening');
    const otherUrl = `http://127.0.0.1:${other.address().port}`;
    const renderServer = await startServer(import.meta.dirname);
    const profile = await mkdtemp(join(tmpdir(), 'itk-critical-chrome-'));
    renderServer.setPage({
        path: '/page',
        html: `<!doctype html><body><img src="${otherUrl}/image.png">
            <iframe srcdoc="<meta http-equiv='refresh' content='0;url=${otherUrl}/redirect-target'>"></iframe>
            <script>fetch('/page').then(r => { document.body.dataset.sameOriginStatus = r.status; });</script></body>`,
    });

    try {
        let options;
        await launchBrowser({ launch: async (input) => { options = input; } }, chrome, renderServer.origin);
        const execution = promisify(execFile)(chrome, [
            ...options.args, '--headless', '--dump-dom', '--virtual-time-budget=2000',
            '--disable-background-networking', '--disable-component-update', '--no-first-run',
            '--use-mock-keychain', `--user-data-dir=${profile}`, `${renderServer.origin}/page`,
        ], { timeout: 20000, killSignal: 'SIGKILL' });
        t.after(() => {
            const child = execution.child;
            if (child.pid && child.exitCode === null && child.signalCode === null) {
                child.kill('SIGKILL');
            }
        });
        const { stdout } = await execution;
        assert.match(stdout, /data-same-origin-status="200"/);
        assert.equal(visits, 0);
    } finally {
        renderServer.close();
        other.close();
        await rm(profile, { recursive: true, force: true });
    }
});
