<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\PHPStan\Rules;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Detectors\DispatchInsideTransactionDetector;

final class DispatchInsideTransactionRule extends AbstractQueueGuardRule
{
    public function __construct(
        private readonly DispatchInsideTransactionDetector $detector = new DispatchInsideTransactionDetector,
        ?Config $config = null,
    ) {
        parent::__construct($config);
    }

    protected function detector(): Detector
    {
        return $this->detector;
    }
}
