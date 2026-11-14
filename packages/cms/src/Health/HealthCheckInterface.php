<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A check shown in «Разработчику → Состояние сайта». A class that implements it is picked up by itself (tag `itk.health_check`),
 * or an extension names it in Registry::healthChecks(). It must be quick (it runs each time the screen opens) and must not throw:
 * an exception is shown as a failed check.
 */
#[AutoconfigureTag('itk.health_check')]
interface HealthCheckInterface
{
    /** What the line is called, e.g. «Платёжный шлюз». */
    public function name(): string;

    public function check(): HealthResult;
}
