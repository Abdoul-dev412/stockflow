<?php

namespace App\Support;

final class DailyAction
{
    /** @param array<int, array{label: string, url: ?string}> $items */
    public function __construct(
        public readonly string $key,
        public readonly string $severity,   // critical | warning
        public readonly string $title,
        public readonly int $count,
        public readonly string $detail,
        public readonly string $url,
        public readonly string $cta,
        public readonly array $items = [],
    ) {
    }

    public function rank(): int
    {
        return $this->severity === 'critical' ? 0 : 1;
    }
}