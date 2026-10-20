<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Core;

final class Finding
{
    public function __construct(
        public readonly RuleId $ruleId,
        public readonly Severity $severity,
        public readonly string $file,
        public readonly int $line,
        public readonly string $message,
        public readonly string $tip,
        public readonly ?string $docsUrl = null,
    ) {}

    public function getDocsUrl(): string
    {
        return $this->docsUrl ?? $this->ruleId->docsUrl();
    }

    public function fingerprint(string $projectRoot = '', int $occurrence = 0): string
    {
        $relativePath = $this->relativePath($projectRoot);
        $normalizedMsg = preg_replace('/\s+/', ' ', trim($this->message)) ?? $this->message;

        return sha1($this->ruleId->value.'|'.$relativePath.'|'.$normalizedMsg.'|'.$occurrence);
    }

    public function relativePath(string $projectRoot = ''): string
    {
        $normalizedFile = str_replace('\\', '/', $this->file);
        if ($projectRoot !== '') {
            $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/').'/';
            if (str_starts_with($normalizedFile, $normalizedRoot)) {
                return substr($normalizedFile, strlen($normalizedRoot));
            }
        }

        return $normalizedFile;
    }

    public function withSeverity(Severity $severity): self
    {
        return new self(
            $this->ruleId,
            $severity,
            $this->file,
            $this->line,
            $this->message,
            $this->tip,
            $this->docsUrl,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $projectRoot = ''): array
    {
        return [
            'ruleId' => $this->ruleId->value,
            'identifier' => $this->ruleId->phpstanIdentifier(),
            'severity' => $this->severity->value,
            'file' => $this->relativePath($projectRoot),
            'line' => $this->line,
            'message' => $this->message,
            'tip' => $this->tip,
            'docsUrl' => $this->getDocsUrl(),
        ];
    }
}
