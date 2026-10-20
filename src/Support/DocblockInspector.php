<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Support;

use Malikad\QueueGuard\Core\RuleId;
use PhpParser\Node;

final class DocblockInspector
{
    /**
     * Inspects a node and its enclosing ancestors (method, class, statement)
     * to check if it is exempted via @queue-safe.
     *
     * Supports:
     *   - @queue-safe (exempts all rules)
     *   - @queue-safe QG001 (exempts QG001)
     *   - @queue-safe QG001,QG006 (exempts multiple rules)
     *
     * @param  list<Node>  $ancestors
     */
    public function isExempt(Node $node, RuleId $ruleId, array $ancestors = []): bool
    {
        if ($this->nodeHasExemption($node, $ruleId)) {
            return true;
        }

        foreach ($ancestors as $ancestor) {
            if ($this->nodeHasExemption($ancestor, $ruleId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if the given comment text exempts the specified rule.
     */
    public function commentExempts(string $commentText, RuleId $ruleId): bool
    {
        $scopes = $this->parseExemptions($commentText);
        if ($scopes === null || $scopes === []) {
            return false;
        }

        if ($scopes === ['*']) {
            return true;
        }

        return in_array($ruleId->value, $scopes, true)
            || in_array(strtolower($ruleId->value), array_map('strtolower', $scopes), true)
            || in_array($ruleId->phpstanIdentifier(), $scopes, true);
    }

    /**
     * Parses @queue-safe annotations in a comment block.
     *
     * @return list<string>|null null if not present, ['*'] if all rules are exempted, or list of rule IDs
     */
    public function parseExemptions(string $commentText): ?array
    {
        // Must match @queue-safe with word boundary so @queue-safe-ish does not match
        if (! preg_match_all('/@queue-safe\b(?:\s+([A-Za-z0-9_.,\s-]+))?/', $commentText, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $allRules = [];
        $hasWildcard = false;

        foreach ($matches as $match) {
            $rawList = isset($match[1]) ? trim($match[1]) : '';
            // Strip trailing comment closers or docblock asterisks
            $rawList = preg_replace('/\*+\/.*$/', '', $rawList) ?? $rawList;
            $rawList = trim($rawList);

            if ($rawList === '') {
                $hasWildcard = true;

                continue;
            }

            $tokens = preg_split('/[\s,]+/', $rawList) ?: [];
            $foundRuleInThisTag = false;

            foreach ($tokens as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }

                // Match valid RuleId (e.g. QG001..QG999, case-insensitive) or PHPStan identifier
                if (preg_match('/^QG\d{3}$/i', $token)) {
                    $allRules[] = strtoupper($token);
                    $foundRuleInThisTag = true;
                } elseif (preg_match('/^queueGuard\.[A-Za-z0-9]+$/', $token)) {
                    $allRules[] = $token;
                    $foundRuleInThisTag = true;
                }
            }

            // If text followed @queue-safe but NO valid rule IDs were found (e.g. "@queue-safe because ..."),
            // do NOT treat as wildcard! It is an invalid/ignored tag.
        }

        if ($hasWildcard) {
            return ['*'];
        }

        if ($allRules === []) {
            return null;
        }

        return array_values(array_unique($allRules));
    }

    private function nodeHasExemption(Node $node, RuleId $ruleId): bool
    {
        foreach ($node->getComments() as $comment) {
            if ($this->commentExempts($comment->getText(), $ruleId)) {
                return true;
            }
        }

        return false;
    }
}
