<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BalanceTransaction;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\WalletFunding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    public function __construct(protected PaystackService $paystackService)
    {
    }

    /**
     * Create the business's Paystack customer + dedicated virtual account if
     * it doesn't have one yet. Safe to call on every wallet lookup.
     */
    public function ensureVirtualAccount(Business $business): Business
    {
        if ($business->virtual_account_number) {
            return $business;
        }

        if (!$business->paystack_customer_code) {
            $customerResult = $this->paystackService->createCustomer([
                'email' => $business->email,
                'first_name' => $business->name,
                'phone' => $business->phone,
            ]);

            if (!$customerResult['success']) {
                Log::error('WalletService: failed to create Paystack customer', [
                    'business_id' => $business->id,
                    'error' => $customerResult['message'],
                ]);
                return $business;
            }

            $business->paystack_customer_code = $customerResult['data']['customer_code'];
            $business->save();
        }

        $dvaResult = $this->paystackService->createDedicatedVirtualAccount($business->paystack_customer_code);

        if (!$dvaResult['success']) {
            Log::error('WalletService: failed to create dedicated virtual account', [
                'business_id' => $business->id,
                'error' => $dvaResult['message'],
            ]);
            return $business;
        }

        $account = $dvaResult['data']['account'] ?? $dvaResult['data'];
        $business->virtual_account_number = $account['account_number'] ?? null;
        $business->virtual_account_bank_name = $account['bank']['name'] ?? null;
        $business->virtual_account_name = $account['account_name'] ?? null;
        $business->paystack_dva_id = $dvaResult['data']['id'] ?? null;
        $business->save();

        return $business;
    }

    /**
     * Credit a business's wallet from a Paystack dedicated-account transfer.
     * Idempotent on paystack_reference, so webhook retries are safe.
     */
    public function creditWallet(Business $business, float $amount, string $paystackReference, ?string $channel, array $rawPayload = []): bool
    {
        if (WalletFunding::where('paystack_reference', $paystackReference)->exists()) {
            Log::info('WalletService: ignoring duplicate wallet funding webhook', [
                'business_id' => $business->id,
                'reference' => $paystackReference,
            ]);
            return false;
        }

        DB::transaction(function () use ($business, $amount, $paystackReference, $channel, $rawPayload) {
            WalletFunding::create([
                'business_id' => $business->id,
                'paystack_reference' => $paystackReference,
                'amount' => $amount,
                'channel' => $channel,
                'raw_payload' => $rawPayload,
            ]);

            $before = (float) ($business->wallet_balance ?? 0);
            $business->wallet_balance = $before + $amount;
            $business->save();

            BalanceTransaction::create([
                'business_id' => $business->id,
                'transaction_type' => 'credit',
                'balance_type' => 'wallet',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $business->wallet_balance,
                'reference_type' => 'wallet_funding',
                'description' => "Wallet funded via virtual account transfer (ref: {$paystackReference})",
            ]);
        });

        return true;
    }

    /**
     * Pay down a purchase order's outstanding amount from the business's
     * wallet balance. Pays whatever the wallet can cover (partial allowed).
     *
     * @return array{success: bool, message: string, amount_applied?: float}
     */
    public function settlePurchaseOrderFromWallet(PurchaseOrder $po, ?string $initiatedByNote = null): array
    {
        $business = $po->business;

        if ($po->outstanding_amount <= 0) {
            return ['success' => false, 'message' => 'This purchase order is already fully paid'];
        }

        if ((float) ($business->wallet_balance ?? 0) <= 0) {
            return ['success' => false, 'message' => 'Wallet balance is empty'];
        }

        $amountToApply = min((float) $po->outstanding_amount, (float) $business->wallet_balance);

        DB::transaction(function () use ($business, $po, $amountToApply, $initiatedByNote) {
            $before = (float) ($business->wallet_balance ?? 0);
            $business->wallet_balance = $before - $amountToApply;
            $business->save();

            BalanceTransaction::create([
                'business_id' => $business->id,
                'transaction_type' => 'debit',
                'balance_type' => 'wallet',
                'amount' => $amountToApply,
                'balance_before' => $before,
                'balance_after' => $business->wallet_balance,
                'reference_type' => 'purchase_order',
                'reference_id' => $po->id,
                'description' => "Wallet settlement for PO {$po->po_number}",
            ]);

            Payment::create([
                'payment_reference' => 'WALLET_' . $po->po_number . '_' . now()->timestamp,
                'purchase_order_id' => $po->id,
                'business_id' => $business->id,
                'amount' => $amountToApply,
                'payment_type' => 'wallet_settlement',
                'status' => 'confirmed',
                'payment_date' => now(),
                'confirmed_at' => now(),
                'notes' => $initiatedByNote ?? 'Settled from wallet balance',
            ]);

            $po->updatePaymentAmounts($amountToApply);
        });

        return [
            'success' => true,
            'message' => 'Purchase order settled from wallet balance',
            'amount_applied' => $amountToApply,
        ];
    }
}
