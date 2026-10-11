<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\Statements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialStatementController extends Controller
{
    public function __construct(private readonly Statements $statements) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date'], 'branch_id' => ['nullable', 'integer']]);

        return response()->json($this->statements->trialBalance($data['as_of'] ?? now()->toDateString(), $data['branch_id'] ?? null));
    }

    public function income(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->statements->incomeStatement(
            $data['from'] ?? now()->startOfMonth()->toDateString(),
            $data['to'] ?? now()->toDateString(),
            $data['branch_id'] ?? null,
        ));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date']]);

        return response()->json($this->statements->balanceSheet($data['as_of'] ?? now()->toDateString()));
    }
}
