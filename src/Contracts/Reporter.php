<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Contracts;

use Malikad\QueueGuard\Core\Finding;

interface Reporter
{
    /**
     * @param  list<Finding>  $findings
     */
    public function format(array $findings, int $filesCount, float $elapsedSeconds, string $projectRoot = ''): string;
}
