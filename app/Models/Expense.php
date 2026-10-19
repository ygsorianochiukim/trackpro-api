<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'category', 'expense_category_id', 'wallet_id', 'amount', 'invoice_reference', 'incurred_on', 'notes'])]
class Expense extends Model
{
    /** `category` keeps the category's name (kept in sync on rename) for display. */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /** Null = paid from the default wallet. */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'incurred_on' => 'date:Y-m-d',
        ];
    }
}
