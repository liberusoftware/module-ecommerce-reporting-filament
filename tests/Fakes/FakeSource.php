<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Tests\Fakes;

use Liberu\Ecommerce\Reporting\Contracts\MetricSource;
use Liberu\Ecommerce\Reporting\Data\Definition;
use Liberu\Ecommerce\Reporting\Data\Reading;
use Liberu\Ecommerce\Reporting\Data\Window;
use Throwable;

/** A source the host would bind. Nothing in this package implements one. */
final class FakeSource implements MetricSource
{
    /** @param  list<Reading>  $readings */
    public function __construct(private readonly array $readings = [], private readonly ?Throwable $raises = null) {}

    /** @return list<Reading> */
    public function read(string $tenantId, Definition $definition, Window $window): array
    {
        if ($this->raises !== null) {
            throw $this->raises;
        }

        return $this->readings;
    }
}
