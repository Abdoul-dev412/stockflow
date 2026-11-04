<div class="flex flex-wrap gap-2 text-sm">
    <a href="{{ route('analysis.valuation') }}"
        class="rounded-full px-3 py-1 {{ request()->routeIs('analysis.valuation') ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
        Valorisation et pertes
    </a>
    <a href="{{ route('analysis.abc') }}"
        class="rounded-full px-3 py-1 {{ request()->routeIs('analysis.abc') ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
        ABC et produits dormants
    </a>
</div>