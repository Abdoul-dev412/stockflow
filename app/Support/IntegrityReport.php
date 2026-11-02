<?php

namespace App\Support;

final class IntegrityReport
{
    /**
     * @param  array<string, int>  $scanned
     * @param  array<int, array{check: string, ref: string, message: string}>  $issues
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $type,
        public readonly array $scanned,
        public readonly array $issues,
        public readonly array $meta = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->issues === [];
    }

    public function count(): int
    {
        return count($this->issues);
    }

    /** @return array<int, string> */
    public function messages(): array
    {
        return array_column($this->issues, 'message');
    }

    /** Identifie un ensemble d'anomalies : deux exécutions identiques donnent la même signature. */
    public function signature(): string
    {
        $keys = array_map(fn (array $issue) => $issue['check'].'|'.$issue['ref'], $this->issues);
        sort($keys);

        return hash('sha256', implode("\n", $keys));
    }
}