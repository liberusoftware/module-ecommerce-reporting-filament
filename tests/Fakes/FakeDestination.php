<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Tests\Fakes;

use Liberu\Ecommerce\Reporting\Contracts\ResultDestination;
use Liberu\Ecommerce\Reporting\Data\ResultSet;
use Throwable;

final class FakeDestination implements ResultDestination
{
    /** @var list<ResultSet> */
    public array $sent = [];

    public function __construct(private readonly string $response = 'accepted', private readonly ?Throwable $raises = null) {}

    public function send(string $tenantId, string $destinationRef, ResultSet $results): string
    {
        if ($this->raises !== null) {
            throw $this->raises;
        }

        $this->sent[] = $results;

        return $this->response;
    }
}
