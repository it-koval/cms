<?php

declare(strict_types=1);

namespace Itk\Cms\Security\TwoFactor;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps the TOTP secrets of people encrypted in the database (libsodium secretbox, XSalsa20-Poly1305). The key is derived from APP_SECRET with a context of its
 * own, so the same APP_SECRET does not give the same key to anything else. Changing APP_SECRET makes stored secrets unreadable: people enrol again.
 * It also signs the recovery codes (they are stored as keyed hashes, never as text).
 */
final class SecretBox
{
    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    public function seal(#[\SensitiveParameter] string $plain): string
    {
        $nonce = \random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1.' . \base64_encode($nonce . \sodium_crypto_secretbox($plain, $nonce, $this->key('secret')));
    }

    /** @return string|null null when the value is damaged or APP_SECRET changed */
    public function open(#[\SensitiveParameter] string $sealed): ?string
    {
        if (!\str_starts_with($sealed, 'v1.')) {
            return null;
        }
        $raw = \base64_decode(\substr($sealed, 3), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = \sodium_crypto_secretbox_open(\substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), \substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key('secret'));

        return false === $plain ? null : $plain;
    }

    /** A keyed hash of a recovery code. */
    public function hash(#[\SensitiveParameter] string $value): string
    {
        return \hash_hmac('sha256', $value, $this->key('recovery'));
    }

    private function key(string $purpose): string
    {
        if ('' === $this->secret) {
            throw new \LogicException('APP_SECRET is empty: set a unique value in .env.local of this environment.');
        }

        return \hash_hmac('sha256', 'itk-cms:2fa:' . $purpose, $this->secret, true); // 32 bytes = the key size of secretbox
    }
}
