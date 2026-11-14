<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\I18n\I18n;

/** The answer of one health check: ok / warn / fail and a sentence for a non-technical reader. */
final class HealthResult
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    private function __construct(
        public readonly string $status,
        public readonly string $message,
    ) {
    }

    public static function ok(?string $message = null): self
    {
        return new self(self::OK, $message ?? I18n::t('ops.health_all_good'));
    }

    public static function warn(string $message): self
    {
        return new self(self::WARN, $message);
    }

    public static function fail(string $message): self
    {
        return new self(self::FAIL, $message);
    }

    /** A result with a status that is only known at run time ('ok' | 'warn' | 'fail'). */
    public static function of(string $status, string $message): self
    {
        return new self($status, $message);
    }

    /** The worse of two statuses (fail > warn > ok). */
    public static function worst(string $a, string $b): string
    {
        $rank = [self::OK => 0, self::WARN => 1, self::FAIL => 2];

        return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? $a : $b;
    }
}
