<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Expense categories the admin manages (Inventory, Rent, …). */
class ExpenseCategoryController extends Controller
{
    /** GET /api/admin/expense-categories — with how many expenses and how much each holds. */
    public function index(): JsonResponse
    {
        $stats = Expense::selectRaw('expense_category_id, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('expense_category_id')->get()->keyBy('expense_category_id');

        $categories = ExpenseCategory::orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (ExpenseCategory $c) => $c->toArray() + [
                'expenses_count' => (int) ($stats[$c->id]->count ?? 0),
                'expenses_total' => (int) ($stats[$c->id]->total ?? 0),
            ]);

        return response()->json(['data' => $categories]);
    }

    /** POST /api/admin/expense-categories */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['sort_order'] = (int) ExpenseCategory::max('sort_order') + 1;

        return response()->json(['data' => ExpenseCategory::create($data)], 201);
    }

    /** PATCH /api/admin/expense-categories/{category} — a rename carries over to its expenses. */
    public function update(Request $request, ExpenseCategory $category): JsonResponse
    {
        DB::transaction(function () use ($request, $category) {
            $category->update($this->validated($request, $category));
            if ($category->wasChanged('name')) {
                Expense::where('expense_category_id', $category->id)->update(['category' => $category->name]);
            }
        });

        return response()->json(['data' => $category->fresh()]);
    }

    /**
     * DELETE /api/admin/expense-categories/{category}?move_to={id}
     * A category with expenses needs somewhere to move them; the last category can't go.
     */
    public function destroy(Request $request, ExpenseCategory $category): JsonResponse
    {
        if (ExpenseCategory::count() <= 1) {
            return response()->json(['message' => 'Keep at least one category.'], 422);
        }

        $inUse = Expense::where('expense_category_id', $category->id)->exists();
        if ($inUse) {
            $request->validate([
                'move_to' => ['required', 'integer', Rule::exists('expense_categories', 'id'), Rule::notIn([$category->id])],
            ], ['move_to.required' => 'Choose a category to move this category\'s expenses to.']);
        }

        DB::transaction(function () use ($request, $category, $inUse) {
            if ($inUse) {
                $target = ExpenseCategory::findOrFail($request->integer('move_to'));
                Expense::where('expense_category_id', $category->id)
                    ->update(['expense_category_id' => $target->id, 'category' => $target->name]);
            }
            $category->delete();
        });

        return response()->json(['message' => 'Category deleted.']);
    }

    private function validated(Request $request, ?ExpenseCategory $category = null): array
    {
        $data = $request->validate([
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:60',
                Rule::unique('expense_categories', 'name')->ignore($category?->id)],
            'requires_reference' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $data;
    }
}
