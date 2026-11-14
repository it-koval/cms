<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Health\HealthResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The status «off»: a module that is not connected is not a problem. */
final class HealthResultTest extends TestCase
{
    #[Test]
    public function offHasItsOwnStatusAndKeepsTheMessage(): void
    {
        $r = HealthResult::off('Не подключён');

        self::assertSame(HealthResult::OFF, $r->status);
        self::assertSame('off', $r->status);
        self::assertSame('Не подключён', $r->message);
    }

    #[Test]
    public function offRanksLikeOkAndNeverMasksAProblem(): void
    {
        self::assertSame(HealthResult::OK, HealthResult::worst(HealthResult::OK, HealthResult::OFF), 'a tie keeps the first');
        self::assertSame(HealthResult::OFF, HealthResult::worst(HealthResult::OFF, HealthResult::OK));
        self::assertSame(HealthResult::WARN, HealthResult::worst(HealthResult::OFF, HealthResult::WARN));
        self::assertSame(HealthResult::FAIL, HealthResult::worst(HealthResult::FAIL, HealthResult::OFF));
        self::assertSame(HealthResult::OK, HealthResult::worst(HealthResult::OK, 'something-new'), 'an unknown status ranks as ok');
    }
}
