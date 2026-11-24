import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

test('the kit ships a default-deny profile that permits bounded Chromium namespace flags', () => {
    const profile = JSON.parse(readFileSync(new URL('../../docker/chrome-seccomp.json', import.meta.url), 'utf8'));
    assert.equal(profile.defaultAction, 'SCMP_ACT_ERRNO');
    const chromeNamespaces = 0x70020000; // user, pid, network, mount
    const otherNamespaces = 0x7e020000 & ~chromeNamespaces;
    const allowed = (name) => profile.syscalls.filter((rule) => rule.action === 'SCMP_ACT_ALLOW' && rule.names.includes(name) && !rule.includes?.caps?.length);

    assert.deepEqual(allowed('unshare').map((rule) => rule.args), [[{
        index: 0, value: (0xffffffff & ~chromeNamespaces) >>> 0, valueTwo: 0, op: 'SCMP_CMP_MASKED_EQ',
    }]]);
    for (const index of [0, 1]) {
        assert.ok(allowed('clone').some((rule) => rule.args?.some((arg) => arg.index === index && arg.value === otherNamespaces && arg.valueTwo === 0 && arg.op === 'SCMP_CMP_MASKED_EQ')));
    }
    assert.equal(allowed('clone3').length, 0);
    assert.equal(allowed('setns').length, 0);
});
