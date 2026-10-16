<?php

namespace App\Http\Controllers;

use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const PERIODS = [7, 30, 90];

    public function __invoke(Request $request): View
    {
        $days = in_array((int) $request->query('days'), self::PERIODS, true)
            ? (int) $request->query('days')
            : 30;

        $counts = Product::stockCounts();

        $perType = StockMovement::query()
            ->where('status', MovementStatus::Applied->value)
            ->where('occurred_at', '>=', now()->subDays($days)->startOfDay())
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->toBase()
            ->pluck('total', 'type');

        $byType = collect(MovementType::cases())
            ->mapWithKeys(fn (MovementType $type) => [$type->value => (int) ($perType[$type->value] ?? 0)])
            ->all();

        return view('dashboard', [
            'days' => $days,
            'types' => MovementType::cases(),
            'byType' => $byType,
            'stats' => [
                'products' => $counts['active'],
                'low' => $counts['low'],
                'out' => $counts['out'],
                'categories' => Category::where('is_active', true)->count(),
                'suppliers' => Supplier::where('is_active', true)->count(),
                'movements' => array_sum($byType),
            ],
            'restock' => Product::query()
                ->active()
                ->needsRestock()
                ->mostUrgentFirst()
                ->with('category:id,name')
                ->limit(5)
                ->get(),
            'recentMovements' => StockMovement::query()
                ->with(['product', 'user:id,name'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(8)
                ->get(),
        ]);
    }
}