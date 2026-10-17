<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ProductFilters
{
    /** @return array{search: string, status: ?string, stock: ?string, category: ?int} */
    public static function fromRequest(Request $request): array
    {
        $search = $request->query('search');

        return [
            'search' => is_string($search) ? trim($search) : '',
            'status' => in_array($request->query('status'), ['active', 'inactive'], true)
                ? $request->query('status') : null,
            'stock' => in_array($request->query('stock'), ['normal', 'low', 'out'], true)
                ? $request->query('stock') : null,
            'category' => $request->integer('category') ?: null,
        ];
    }
}