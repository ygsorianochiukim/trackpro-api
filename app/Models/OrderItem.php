<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'product_id',
    'product_name', 'product_model',
    'unit_price', 'unit_subscription',
    'qty', 'line_total',
])]
class OrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'unit_subscription' => 'integer',
            'qty' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
