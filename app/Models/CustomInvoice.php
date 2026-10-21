<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['number', 'date', 'customer_name', 'total', 'invoice'])]
class CustomInvoice extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total' => 'integer',
            'invoice' => 'array',
        ];
    }

    /** Suggested number for the next invoice, e.g. CI-000012 (skips any already taken). */
    public static function nextNumber(): string
    {
        $n = (int) (static::max('id') ?? 0) + 1;
        do {
            $number = 'CI-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            $n++;
        } while (static::where('number', $number)->exists());

        return $number;
    }

    /** Store a validated invoice, keeping the listing columns in step with it. */
    public function fillFromInvoice(array $invoice): static
    {
        $subtotal = collect($invoice['items'])->sum(fn ($i) => $i['qty'] * $i['unit_price']);

        return $this->fill([
            'number' => $invoice['number'],
            'date' => $invoice['date'],
            'customer_name' => $invoice['bill_to']['name'],
            'total' => max(0, $subtotal - ($invoice['discount'] ?? 0)),
            'invoice' => $invoice,
        ]);
    }
}
