<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'category', 'amount', 'incurred_on', 'notes'])]
class Expense extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'incurred_on' => 'date',
        ];
    }
}
