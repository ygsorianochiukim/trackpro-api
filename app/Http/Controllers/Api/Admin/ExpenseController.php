<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ExpenseController extends Controller
{
    /** GET /api/admin/expenses — recent expenses + cashflow (income vs expenses). */
    public function index(): JsonResponse
    {
        $expenses = Expense::orderByDesc('incurred_on')->orderByDesc('id')->paginate(10);

        return response()->json([
            'data' => $expenses->items(),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'total' => $expenses->total(),
                'has_more' => $expenses->hasMorePages(),
            ],
            'cashflow' => $this->cashflow(),
            'category_total' => (int) Expense::sum('amount'),
        ]);
    }

    /** POST /api/admin/expenses */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'integer', 'min:0'],
            'incurred_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $expense = Expense::create($data);

        return response()->json(['data' => $expense], 201);
    }

    /** DELETE /api/admin/expenses/{id} */
    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();
        return response()->json(['message' => 'Deleted']);
    }

    /** Income (paid sales) vs expenses, for today / this month / this year. */
    private function cashflow(): array
    {
        $periods = [
            'today' => [Carbon::today(), Carbon::today()->endOfDay()],
            'month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            'year' => [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()],
        ];

        $out = [];
        foreach ($periods as $key => [$start, $end]) {
            $income = (int) Payment::where('status', 'paid')
                ->whereBetween('paid_at', [$start, $end])
                ->sum('amount');
            $expenses = (int) Expense::whereBetween('incurred_on', [$start->toDateString(), $end->toDateString()])
                ->sum('amount');
            $out[$key] = ['income' => $income, 'expenses' => $expenses, 'net' => $income - $expenses];
        }

        return $out;
    }
}
