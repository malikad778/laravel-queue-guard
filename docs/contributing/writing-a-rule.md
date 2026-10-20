# Writing a Custom Rule

Queue Guard makes it simple to implement internal rules or custom SDK checks.

## Architecture Overview

Each rule consists of:
1. **RuleId Enum Case** in `src/Core/RuleId.php`
2. **Detector** in `src/Detectors/` implementing `Malikad\QueueGuard\Contracts\Detector`
3. **PHPStan Rule Adapter** in `src/PHPStan/Rules/` extending `AbstractQueueGuardRule`

## 1. Register Rule ID

In `src/Core/RuleId.php`:

```php
enum RuleId: string
{
    // ...
    case QG010 = 'QG010';
}
```

Implement `title()`, `phpstanIdentifier()`, `defaultSeverity()`, and `explanation()`.

## 2. Implement the Detector

```php
namespace Malikad\QueueGuard\Detectors;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class CustomDetector implements Detector
{
    public function ruleId(): RuleId
    {
        return RuleId::QG010;
    }

    public function detect(array $ast, string $filePath, Config $config): array
    {
        $findings = [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($findings, $filePath, $config) extends NodeVisitorAbstract {
            // Traverse AST nodes and push to $findings
        });
        $traverser->traverse($ast);

        return $findings;
    }
}
```

## 3. Register in `extension.neon`

Add the detector service and PHPStan rule definition to `extension.neon`.

## 4. Add Tests

Add test cases in `tests/Detectors/` testing good, bad, and exempted code patterns.
