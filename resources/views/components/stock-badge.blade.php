@props(['status'])

<span class="rounded-full px-2 py-1 text-xs {{ $status->badgeClasses() }}">
    {{ $status->label() }}
</span>