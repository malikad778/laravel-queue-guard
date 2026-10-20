<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\PHPStan\Rules;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Detectors\NonIdempotentFinancialJobDetector;

final class NonIdempotentFinancialJobRule extends AbstractQueueGuardRule
{
    public function __construct(
        private readonly NonIdempotentFinancialJobDetector $detector = new NonIdempotentFinancialJobDetector,
        ?Config $config = null,
    ) {
        parent::__construct($config);
    }

    protected function detector(): Detector
    {
        return $this->detector;
    }
}
