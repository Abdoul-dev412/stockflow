@props(['column', 'label', 'sort', 'direction'])

<th class="px-4 py-3 text-left font-medium text-gray-600">
    <a href="{{ request()->fullUrlWithQuery([
            'sort' => $column,
            'direction' => $sort === $column && $direction === 'asc' ? 'desc' : 'asc',
            'page' => null,
        ]) }}" class="hover:underline">
        {{ $label }}
        @if ($sort === $column)
            {{ $direction === 'asc' ? '↑' : '↓' }}
        @endif
    </a>
</th>