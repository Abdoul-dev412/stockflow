@props(['coverage'])

<span class="rounded-full px-2 py-1 text-xs {{ $coverage->level->badgeClasses() }}">
    {{ $coverage->level->label() }}@if ($coverage->isDetermined()) · {{ $coverage->daysLabel() }}@endif
</span>