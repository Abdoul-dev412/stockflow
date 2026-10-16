@props(['label', 'value', 'href' => null, 'tone' => 'gray'])

@php
    $tones = [
        'gray' => 'text-gray-900',
        'amber' => 'text-amber-700',
        'red' => 'text-red-700',
    ];
@endphp

<div class="bg-white shadow-sm sm:rounded-lg p-5">
    <div class="text-sm text-gray-500">
        @if ($href)
            <a href="{{ $href }}" class="hover:underline">{{ $label }}</a>
        @else
            {{ $label }}
        @endif
    </div>
    <div class="mt-1 text-3xl font-semibold {{ $tones[$tone] ?? $tones['gray'] }}">{{ $value }}</div>
</div>