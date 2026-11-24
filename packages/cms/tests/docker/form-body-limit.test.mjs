import assert from 'node:assert/strict';
import { request } from 'node:http';
import { test } from 'node:test';

// Set to the loopback address of a disposable site using the kit's nginx and PHP-FPM.
const site = process.env.ITK_TEST_KIT_HTTP;
const boundary = 'itk-kit-form-boundary';
const limit = 256 * 1024;

function body(size, multipart) {
    const prefix = multipart
        ? `--${boundary}\r\nContent-Disposition: form-data; name="itk_hp"\r\n\r\nfixture\r\n--${boundary}\r\nContent-Disposition: form-data; name="sample"\r\n\r\n`
        : 'itk_hp=fixture&sample=';
    const suffix = multipart ? `\r\n--${boundary}--\r\n` : '';
    return prefix + 'x'.repeat(size - prefix.length - suffix.length) + suffix;
}

function post(url, size, multipart, chunked) {
    const headers = {
        'Content-Type': multipart ? `multipart/form-data; boundary=${boundary}` : 'application/x-www-form-urlencoded',
        ...(chunked ? { 'Transfer-Encoding': 'chunked' } : { 'Content-Length': size }),
    };
    return new Promise((resolve, reject) => {
        const req = request(url, { method: 'POST', headers }, (res) => {
            res.resume();
            res.on('end', () => resolve({ status: res.statusCode, type: res.headers['content-type'] ?? '' }));
        });
        req.on('error', reject);
        req.setTimeout(3000, () => req.destroy(new Error('Local kit request timed out')));
        req.end(body(size, multipart));
    });
}

test('the kit accepts the form body limit and refuses larger forms before PHP parses them', { skip: !site, timeout: 12000 }, async () => {
    const url = new URL('/request/itk-kit-form-limit', site);
    assert.equal(url.protocol, 'http:');
    assert.equal(url.hostname, '127.0.0.1');

    // The honeypot exercises the real form route without creating a request.
    for (const multipart of [false, true]) {
        for (const chunked of [false, true]) {
            const atLimit = await post(url, limit, multipart, chunked);
            assert.equal(atLimit.status, 200);
            assert.match(atLimit.type, /^application\/json\b/);
            const overLimit = await post(url, limit + 1, multipart, chunked);
            assert.equal(overLimit.status, 413);
            assert.match(overLimit.type, /^text\/html\b/, 'nginx must refuse the form before the CMS can return its own JSON error');
        }
    }
});
