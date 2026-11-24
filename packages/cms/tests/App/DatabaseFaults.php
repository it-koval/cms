<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App;

use Doctrine\DBAL\Driver\AbstractException;

/** The test and the connection-specific DBAL middleware share one set of faults. */
final class DatabaseFaults
{
    public ?int $refusedPort = null;
    public int $thrown = 0;
    private int $remaining = 0;
    private int $code = 0;
    private ?string $state = null;

    public function failQueries(int $times, int $code, ?string $state): void
    {
        $this->remaining = $times;
        $this->code = $code;
        $this->state = $state;
        $this->thrown = 0;
    }

    public function reset(): void
    {
        $this->refusedPort = null;
        $this->remaining = 0;
    }

    public function query(): void
    {
        if ($this->remaining > 0) {
            --$this->remaining;
            ++$this->thrown;

            throw new class('The database says no', $this->state, $this->code) extends AbstractException {
            };
        }
    }
}
