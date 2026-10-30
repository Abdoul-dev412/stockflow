<?php

namespace App\Support;

use App\Enums\CoverageLevel;
use Carbon\CarbonInterface;

final class Coverage
{
    public function __construct(
        public readonly CoverageLevel $level,
        public readonly int $usableQuantity,
        public readonly int $historyDays,
        public readonly ?float $dailyConsumption = null,
        public readonly ?float $days = null,
        public readonly ?CarbonInterface $stockoutDate = null,
        public readonly ?string $note = null,
    ) {
    }

    public static function undetermined(int $usableQuantity, int $historyDays, string $note): self
    {
        return new self(CoverageLevel::Undetermined, $usableQuantity, $historyDays, note: $note);
    }

    public function isDetermined(): bool
    {
        return $this->level !== CoverageLevel::Undetermined;
    }

    public function daysLabel(): string
    {
        if ($this->days === null) {
            return '—';
        }

        return $this->days < 1 ? '< 1 j' : ((int) floor($this->days)).' j';
    }
}