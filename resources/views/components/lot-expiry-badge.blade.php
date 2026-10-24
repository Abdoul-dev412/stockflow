@props(['lot'])

@php
    $days = $lot->daysLeft();
    $classes = match (true) {
        $days === null => 'bg-gray-100 text-gray-700',
        $days < 0 => 'bg-red-100 text-red-800',
        $days <= \App\Models\StockLot::EXPIRY_WARNING_DAYS => 'bg-amber-100 text-amber-800',
        default => 'bg-green-100 text-green-800',
    };
@endphp

<span class="rounded-full px-2 py-1 text-xs {{ $classes }}">{{ $lot->expiryLabel() }}</span>