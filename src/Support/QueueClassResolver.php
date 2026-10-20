<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Support;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\TraitUse;

final class QueueClassResolver
{
    private const QUEUED_INTERFACES = [
        'ShouldQueue',
        'ShouldQueueAfterCommit',
        'ShouldBroadcast',
        'ShouldBroadcastNow',
        'Illuminate\\Contracts\\Queue\\ShouldQueue',
        'Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit',
        'Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast',
        'Illuminate\\Contracts\\Broadcasting\\ShouldBroadcastNow',
    ];

    /**
     * Determines if a class is an asynchronous queued class.
     * Only classes that implement ShouldQueue or ShouldBroadcast (or extend a class implementing them)
     * are queued. Plain Mailables, Notifications, and Events (even with Dispatchable) are NOT queued.
     */
    public function isQueuedClass(ClassLike $class): bool
    {
        if ($class instanceof Class_) {
            foreach ($class->implements as $interface) {
                $name = $this->normalizeName($interface);
                if ($this->matchesAny($name, self::QUEUED_INTERFACES)) {
                    return true;
                }
            }

            // Check if extending a queued parent class explicitly
            if ($class->extends !== null) {
                $parentName = $this->normalizeName($class->extends);
                if (
                    $this->matchesAny($parentName, self::QUEUED_INTERFACES)
                    || $parentName === 'AppQueuedJob'
                    || str_ends_with($parentName, '\\AppQueuedJob')
                    || str_ends_with($parentName, 'QueuedJob')
                    || str_ends_with($parentName, 'QueueableJob')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    public function usesSerializesModels(ClassLike $class): bool
    {
        foreach ($this->getUsedTraitNames($class) as $traitName) {
            if (
                $traitName === 'SerializesModels'
                || str_ends_with($traitName, '\\SerializesModels')
                || $traitName === 'SerializesAndRestoresModelIdentifiers'
                || str_ends_with($traitName, '\\SerializesAndRestoresModelIdentifiers')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function getUsedTraitNames(ClassLike $class): array
    {
        $traits = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traits[] = $this->normalizeName($trait);
                }
            }
        }

        return $traits;
    }

    private function normalizeName(Name $name): string
    {
        return ltrim($name->toString(), '\\');
    }

    /**
     * @param  list<string>  $candidates
     */
    private function matchesAny(string $name, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($name === $candidate || str_ends_with($name, '\\'.$candidate)) {
                return true;
            }
        }

        return false;
    }
}
