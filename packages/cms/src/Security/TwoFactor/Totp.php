<?php

declare(strict_types=1);

namespace Itk\Cms\Security\TwoFactor;

/**
 * One-time codes by RFC 6238 (TOTP) over RFC 4226 (HOTP): HMAC-SHA1, 6 digits, a new code every 30 seconds, the secret in Base32 (what Google Authenticator,
 * 1Password, Authy and others scan). Pure functions: the time is always passed in.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    /** A code is accepted one step before and one step after the current one: a phone clock that is a little off still works. */
    public const WINDOW = 1;
    public const SECRET_BYTES = 20;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        return self::base32Encode(\random_bytes(self::SECRET_BYTES));
    }

    /** The code of the step that contains the moment `$time` (Unix seconds). */
    public static function code(string $secret, int $time, int $digits = self::DIGITS): string
    {
        return self::hotp($secret, \intdiv($time, self::PERIOD), $digits);
    }

    /**
     * The step (a number of 30-second periods since 1970) the code belongs to, or null when it does not fit the window around `$time`.
     * A step that is not above `$after` is refused: a code can be used once.
     */
    public static function verify(string $secret, string $code, int $time, ?int $after = null, int $window = self::WINDOW): ?int
    {
        $code = \preg_replace('/\s+/', '', $code) ?? '';
        if (1 !== \preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $now = \intdiv($time, self::PERIOD);
        $found = null;
        for ($step = $now - $window; $step <= $now + $window; ++$step) {
            // every step is compared, so the time taken does not tell which one matched
            if (\hash_equals(self::hotp($secret, $step), $code) && (null === $after || $step > $after)) {
                $found = $step;
            }
        }

        return $found;
    }

    /** The address the authenticator apps read from the QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $issuer = \str_replace(':', ' ', $issuer);

        return \sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            \rawurlencode($issuer),
            \rawurlencode($account),
            $secret,
            \rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    public static function hotp(string $secret, int $counter, int $digits = self::DIGITS): string
    {
        $key = self::base32Decode($secret);
        $hash = \hash_hmac('sha1', \pack('J', $counter), $key, true);
        $offset = \ord($hash[19]) & 0x0F;
        $value = ((\ord($hash[$offset]) & 0x7F) << 24) | (\ord($hash[$offset + 1]) << 16) | (\ord($hash[$offset + 2]) << 8) | \ord($hash[$offset + 3]);

        return \str_pad((string) ($value % (10 ** $digits)), $digits, '0', \STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (\str_split($bytes) as $c) {
            $bits .= \str_pad(\decbin(\ord($c)), 8, '0', \STR_PAD_LEFT);
        }
        $out = '';
        foreach (\str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) \bindec(\str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = \strtoupper(\preg_replace('/[\s=-]+/', '', $text) ?? '');
        $bits = '';
        foreach (\str_split($text) as $c) {
            $i = \strpos(self::ALPHABET, $c);
            if (false === $i) {
                throw new \InvalidArgumentException('Not a Base32 string');
            }
            $bits .= \str_pad(\decbin($i), 5, '0', \STR_PAD_LEFT);
        }
        $out = '';
        foreach (\str_split($bits, 8) as $byte) {
            if (8 === \strlen($byte)) {
                $out .= \chr((int) \bindec($byte));
            }
        }

        return $out;
    }
}
