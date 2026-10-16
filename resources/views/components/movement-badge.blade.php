@props(['type'])

<span class="rounded-full px-2 py-1 text-xs {{ $type->badgeClasses() }}">
    {{ $type->label() }}
</span>