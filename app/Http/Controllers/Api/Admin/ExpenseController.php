<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Wallet;
use App\Support\SalesLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    /**
     * GET /api/admin/expenses — recent expenses + cashflow (income vs expenses),
     * plus the categories and active wallets the expense form offers.
     */
    public function index(): JsonResponse
    {
        $expenses = Expense::with('wallet:id,name')->orderByDesc('incurred_on')->orderByDesc('id')->paginate(10);
        $default = Wallet::default();

        return response()->json([
            'data' => collect($expenses->items())->map(fn (Expense $e) => $e->toArray() + [
                // Expenses recorded before wallets existed were paid from the default wallet.
                'wallet_name' => $e->wallet?->name ?? $default?->name,
            ]),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'total' => $expenses->total(),
                'has_more' => $expenses->hasMorePages(),
            ],
            'cashflow' => $this->cashflow(),
            'category_total' => (int) Expense::sum('amount'),
            'options' => [
                'categories' => ExpenseCategory::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'requires_reference']),
                'wallets' => Wallet::where('is_active', true)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('name')
                    ->get(['id', 'name', 'type', 'is_default']),
            ],
        ]);
    }

    /** POST /api/admin/expenses */
    public function store(Request $request): JsonResponse
    {
        $expense = Expense::create($this->validated($request));

        return response()->json(['data' => $expense], 201);
    }

    /** PATCH /api/admin/expenses/{id} — correct a recorded expense. */
    public function update(Request $request, Expense $expense): JsonResponse
    {
        $expense->update($this->validated($request, $expense));

        return response()->json(['data' => $expense->fresh()]);
    }

    /** DELETE /api/admin/expenses/{id} */
    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();
        return response()->json(['message' => 'Deleted']);
    }

    private function validated(Request $request, ?Expense $expense = null): array
    {
        $request->validate([
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
        ], ['expense_category_id.required' => 'Choose a category.']);
        $category = ExpenseCategory::findOrFail($request->integer('expense_category_id'));

        // An edited expense may keep the wallet it was paid from even if that wallet was since deactivated.
        $wallet = Rule::exists('wallets', 'id')->where(fn ($q) => $q
            ->where('is_active', true)
            ->when($expense?->wallet_id, fn ($q, $id) => $q->orWhere('id', $id)));

        // Some categories (e.g. "Other") don't explain themselves — require what the
        // expense was and the invoice / OR behind it.
        $strict = $category->requires_reference ? 'required' : 'nullable';
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'wallet_id' => ['nullable', 'integer', $wallet],
            'amount' => ['required', 'integer', 'min:1'],
            'invoice_reference' => [$strict, 'string', 'max:100'],
            'incurred_on' => ['required', 'date'],
            'notes' => [$strict, 'string', 'max:2000'],
        ], [
            'amount.min' => 'Enter an amount greater than zero.',
            'invoice_reference.required' => "Enter the invoice / OR reference for \"{$category->name}\" expenses.",
            'notes.required' => "Describe what this \"{$category->name}\" expense was for.",
        ]);

        return [
            ...$data,
            'expense_category_id' => $category->id,
            'category' => $category->name,
            'wallet_id' => $data['wallet_id'] ?? $expense?->wallet_id ?? Wallet::default()?->id,
        ];
    }

    /** Income (paid sales) vs expenses, for today / this month / this year. */
    private function cashflow(): array
    {
        // Philippine-time periods, like the sales report.
        $now = Carbon::now(SalesLedger::TIMEZONE);
        $periods = [
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
        ];

        $out = [];
        foreach ($periods as $key => [$start, $end]) {
            $income = SalesLedger::total($start, $end);
            $expenses = (int) Expense::whereBetween('incurred_on', [$start->toDateString(), $end->toDateString()])
                ->sum('amount');
            $out[$key] = ['income' => $income, 'expenses' => $expenses, 'net' => $income - $expenses];
        }

        return $out;
    }
}
