<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Wallets (cash, GCash, bank…) with live balances, and transfers between them. */
class WalletController extends Controller
{
    /** GET /api/admin/wallets — balances, the combined total and recent transfers. */
    public function index(): JsonResponse
    {
        $wallets = Wallet::withBalances();
        $transfers = WalletTransfer::with(['from:id,name', 'to:id,name'])
            ->orderByDesc('transferred_on')->orderByDesc('id')->limit(30)->get();

        return response()->json([
            'data' => $wallets,
            // Deactivating a wallet doesn't make its money disappear, so inactive ones still count.
            'total' => (int) $wallets->sum('balance'),
            'transfers' => $transfers,
        ]);
    }

    /** POST /api/admin/wallets */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['sort_order'] = (int) Wallet::max('sort_order') + 1;

        $wallet = DB::transaction(function () use ($data) {
            $wallet = Wallet::create($data + ['is_default' => false]);
            if (($data['is_default'] ?? false) || !Wallet::where('is_default', true)->exists()) {
                $this->makeDefault($wallet);
            }

            return $wallet;
        });

        return response()->json(['data' => $wallet->fresh()], 201);
    }

    /** PATCH /api/admin/wallets/{wallet} */
    public function update(Request $request, Wallet $wallet): JsonResponse
    {
        $data = $this->validated($request, $wallet);

        if ($wallet->is_default && (($data['is_default'] ?? true) === false || ($data['is_active'] ?? true) === false)) {
            return response()->json(['message' => 'Make another wallet the default first.'], 422);
        }

        DB::transaction(function () use ($wallet, $data) {
            $wallet->update(collect($data)->except('is_default')->all());
            if ($data['is_default'] ?? false) {
                $this->makeDefault($wallet);
            }
        });

        return response()->json(['data' => $wallet->fresh()]);
    }

    /** DELETE /api/admin/wallets/{wallet} — a wallet with history is deactivated instead. */
    public function destroy(Wallet $wallet): JsonResponse
    {
        if ($wallet->is_default) {
            return response()->json(['message' => 'Make another wallet the default before removing this one.'], 422);
        }
        if ($wallet->inUse()) {
            $wallet->update(['is_active' => false]);

            return response()->json(['message' => 'This wallet has expenses or transfers, so it was deactivated instead of deleted.']);
        }
        $wallet->delete();

        return response()->json(['message' => 'Wallet deleted.']);
    }

    /** POST /api/admin/wallet-transfers — move money between two active wallets. */
    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_wallet_id' => ['required', 'integer', Rule::exists('wallets', 'id')->where('is_active', true)],
            'to_wallet_id' => ['required', 'integer', 'different:from_wallet_id', Rule::exists('wallets', 'id')->where('is_active', true)],
            'amount' => ['required', 'integer', 'min:1'],
            'transferred_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['to_wallet_id.different' => 'Pick two different wallets.']);

        $transfer = WalletTransfer::create($data);

        return response()->json(['data' => $transfer->load(['from:id,name', 'to:id,name'])], 201);
    }

    /** DELETE /api/admin/wallet-transfers/{transfer} — undo a transfer entered by mistake. */
    public function destroyTransfer(WalletTransfer $transfer): JsonResponse
    {
        $transfer->delete();

        return response()->json(['message' => 'Transfer removed.']);
    }

    private function makeDefault(Wallet $wallet): void
    {
        Wallet::whereKeyNot($wallet->id)->update(['is_default' => false]);
        $wallet->update(['is_default' => true, 'is_active' => true]);
    }

    private function validated(Request $request, ?Wallet $wallet = null): array
    {
        $data = $request->validate([
            'name' => [$wallet ? 'sometimes' : 'required', 'string', 'max:60', Rule::unique('wallets', 'name')->ignore($wallet?->id)],
            'type' => [$wallet ? 'sometimes' : 'required', Rule::in(Wallet::TYPES)],
            'opening_balance' => ['sometimes', 'integer', 'between:-1000000000,1000000000'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $data;
    }
}
