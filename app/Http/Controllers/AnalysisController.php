<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\AbcClassifier;
use App\Services\DormancyCalculator;
use App\Services\StockValuation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnalysisController extends Controller
{
    private const LOSS_PERIODS = [30, 90, 180, 365];

    public function valuation(Request $request, StockValuation $valuation): View
    {
        $filters = $request->validate([
            'days' => ['nullable', Rule::in(self::LOSS_PERIODS)],
        ]);

        $days = (int) ($filters['days'] ?? 90);
        $summary = $valuation->summary();

        return view('analysis.valuation', [
            'summary' => $summary,
            'totalCents' => Money::toCents($summary['value']),
            'categories' => $valuation->byCategory(),
            'losses' => $valuation->lossCost($days),
            'days' => $days,
            'periods' => self::LOSS_PERIODS,
        ]);
    }

    public function abc(AbcClassifier $abc, DormancyCalculator $dormancy): View
    {
        $classified = $abc->classify();
        $dormant = $dormancy->dormantProducts();

        return view('analysis.abc', [
            'classified' => $this->paginate($classified, 25, 'abc_page'),
            'summary' => $abc->summary($classified),
            'unclassified' => Product::query()->active()->count() - $classified->count(),
            'windowDays' => (int) config('stockflow.abc.window_days'),
            'dormant' => $this->paginate($dormant, 25, 'dormant_page'),
            'capital' => Money::fromCents((int) $dormant->sum(fn (array $row) => Money::toCents($row['capital']))),
            'threshold' => $dormancy->thresholdDays(),
        ]);
    }

    private function paginate(Collection $items, int $perPage, string $pageName): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage($pageName);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
        );
    }
}