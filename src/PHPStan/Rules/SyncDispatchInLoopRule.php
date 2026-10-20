<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\PHPStan\Rules;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Detectors\SyncDispatchInLoopDetector;

final class SyncDispatchInLoopRule extends AbstractQueueGuardRule
{
    public function __construct(
        private readonly SyncDispatchInLoopDetector $detector = new SyncDispatchInLoopDetector,
        ?Config $config = null,
    ) {
        parent::__construct($config);
    }

    protected function detector(): Detector
    {
        return $this->detector;
    }
}
