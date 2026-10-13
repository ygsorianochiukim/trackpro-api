<?php

namespace App\Models;

use App\Support\SalesLedger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A money account — cash on hand, GCash, a bank account.
 *
 * Balance = opening balance
 *         + sales income (default wallet only — every paid order and renewal lands there)
 *         − expenses paid from it (expenses with no wallet count against the default wallet)
 *         + transfers in − transfers out
 */
#[Fillable(['name', 'type', 'opening_balance', 'is_default', 'is_active', 'sort_order'])]
class Wallet extends Model
{
    public const TYPES = ['cash', 'ewallet', 'bank', 'other'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public static function default(): ?self
    {
        return static::where('is_default', true)->first();
    }

    /** Whether anything points at this wallet (so it can only be deactivated, not deleted). */
    public function inUse(): bool
    {
        return $this->expenses()->exists()
            || WalletTransfer::where('from_wallet_id', $this->id)->orWhere('to_wallet_id', $this->id)->exists();
    }

    /**
     * Every wallet with its balance and this month's money in / out (Philippine time).
     *
     * @return Collection<int, array>
     */
    public static function withBalances(): Collection
    {
        $wallets = static::orderByDesc('is_default')->orderBy('sort_order')->orderBy('name')->get();
        $defaultId = $wallets->firstWhere('is_default', true)?->id;

        $now = Carbon::now(SalesLedger::TIMEZONE);
        [$monthStart, $monthEnd] = [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
        $monthDates = [$monthStart->toDateString(), $monthEnd->toDateString()];

        $sumBy = function (?array $dates, string $column) {
            return WalletTransfer::query()
                ->when($dates, fn ($q) => $q->whereBetween('transferred_on', $dates))
                ->selectRaw("{$column} as wallet, SUM(amount) as total")->groupBy($column)
                ->pluck('total', 'wallet')->map(fn ($t) => (int) $t);
        };

        $spentAll = static::expensesByWallet(null, $defaultId);
        $spentMonth = static::expensesByWallet($monthDates, $defaultId);
        $inAll = $sumBy(null, 'to_wallet_id');
        $outAll = $sumBy(null, 'from_wallet_id');
        $inMonth = $sumBy($monthDates, 'to_wallet_id');
        $outMonth = $sumBy($monthDates, 'from_wallet_id');
        $salesAll = SalesLedger::total();
        $salesMonth = SalesLedger::total($monthStart, $monthEnd);

        return $wallets->map(function (self $w) use ($spentAll, $spentMonth, $inAll, $outAll, $inMonth, $outMonth, $salesAll, $salesMonth) {
            $sales = $w->is_default ? $salesAll : 0;
            $balance = $w->opening_balance + $sales - ($spentAll[$w->id] ?? 0) + ($inAll[$w->id] ?? 0) - ($outAll[$w->id] ?? 0);

            return $w->toArray() + [
                'balance' => $balance,
                'month' => [
                    'in' => ($w->is_default ? $salesMonth : 0) + ($inMonth[$w->id] ?? 0),
                    'out' => ($spentMonth[$w->id] ?? 0) + ($outMonth[$w->id] ?? 0),
                ],
            ];
        })->values();
    }

    /** Expense totals keyed by wallet id, with unassigned expenses added to the default wallet. */
    private static function expensesByWallet(?array $dates, ?int $defaultId): array
    {
        $out = [];
        Expense::query()
            ->when($dates, fn ($q) => $q->whereBetween('incurred_on', $dates))
            ->selectRaw('wallet_id, SUM(amount) as total')->groupBy('wallet_id')
            ->get()
            ->each(function ($row) use (&$out, $defaultId) {
                $id = $row->wallet_id ?? $defaultId;
                if ($id !== null) {
                    $out[$id] = ($out[$id] ?? 0) + (int) $row->total;
                }
            });

        return $out;
    }
}
