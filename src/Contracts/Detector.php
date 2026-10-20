<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Contracts;

use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use PhpParser\Node;

interface Detector
{
    public function ruleId(): RuleId;

    /**
     * @param  array<Node\Stmt>  $ast
     * @return list<Finding>
     */
    public function detect(array $ast, string $filePath, Config $config): array;
}
