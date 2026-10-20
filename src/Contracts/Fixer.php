<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Contracts;

use Malikad\QueueGuard\Core\RuleId;

interface Fixer
{
    public function supports(): RuleId;

    public function fix(string $code): string;
}
