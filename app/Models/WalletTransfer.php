<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['from_wallet_id', 'to_wallet_id', 'amount', 'transferred_on', 'notes'])]
class WalletTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'transferred_on' => 'date:Y-m-d',
        ];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id');
    }
}
