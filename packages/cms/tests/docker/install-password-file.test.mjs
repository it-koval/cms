import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';

test('the Docker entrypoint passes only the mounted password file path to install', () => {
    const work = mkdtempSync(join(import.meta.dirname, '.install-'));
    const passwordPath = join(work, 'admin-password');
    const password = randomBytes(16).toString('hex');
    const kit = join(import.meta.dirname, '../../docker');
    try {
        writeFileSync(passwordPath, `${password}\n`, { mode: 0o600 });
        writeFileSync(join(work, 'php'), `#!/bin/sh
[ "$2" = "itk:install" ] || exit 0
[ -z "\${ADMIN_PASSWORD+x}\${ITK_ADMIN_PASSWORD+x}" ] || exit 1
printf '%s\n' "$@"
`, { mode: 0o700 });
        writeFileSync(join(work, 'php-fpm'), '#!/bin/sh\nexit 0\n', { mode: 0o700 });

        const result = spawnSync('/bin/sh', [join(kit, 'php/docker-entrypoint.sh'), 'php-fpm'], {
            cwd: work,
            env: { PATH: `${work}:/usr/bin:/bin`, ADMIN_EMAIL: 'docker-test@example.test', ADMIN_PASSWORD_FILE: passwordPath },
            encoding: 'utf8',
            timeout: 10000,
            killSignal: 'SIGKILL',
        });
        assert.equal(result.status, 0, result.stderr);
        assert.ok(result.stdout.split('\n').includes(`--password-file=${passwordPath}`));
        assert.ok(!result.stdout.includes(password));
        assert.ok(!result.stderr.includes(password));
        assert.doesNotMatch(readFileSync(join(kit, 'compose.yaml'), 'utf8'), /^\s*(?:ITK_)?ADMIN_PASSWORD:/m);
    } finally {
        rmSync(work, { recursive: true, force: true });
    }
});
