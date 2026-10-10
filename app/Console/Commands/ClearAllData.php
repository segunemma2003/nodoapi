<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Business;
use App\Models\PurchaseOrder;
use App\Models\Payment;
use App\Models\BalanceTransaction;
use App\Models\Vendor;

class ClearAllData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:clear-all-data {--force : Force the operation without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all purchase orders, payments, vendors, and businesses (except specified ones)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
                if (!$this->option('force')) {
            if (!$this->confirm('This will delete ALL purchase orders, payments, vendors, and most businesses. Are you sure?')) {
                $this->info('Operation cancelled.');
                return 0;
            }

            if (!$this->confirm('This will keep only: operations@foodstuff.store, support@foodstuff.store, bomadokubo@foodstuff.store. Continue?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

                $this->info('Starting to clear all data...');

        DB::beginTransaction();
        try {
            // 1. Count records before deletion
            $poCount = PurchaseOrder::count();
            $paymentCount = Payment::count();
            $vendorCount = Vendor::count();
            $businessCount = Business::count();
            $transactionCount = BalanceTransaction::whereIn('reference_type', ['purchase_order', 'payment'])->count();

            $this->info("Found {$poCount} purchase orders, {$paymentCount} payments, {$vendorCount} vendors, {$businessCount} businesses, {$transactionCount} related transactions");

            // 2. Delete payments first (due to foreign key constraints)
            $this->info('Deleting payments...');
            Payment::truncate();

            // 3. Delete purchase orders
            $this->info('Deleting purchase orders...');
            PurchaseOrder::truncate();

            // 4. Delete all vendors
            $this->info('Deleting all vendors...');
            Vendor::truncate();

            // 5. Delete related balance transactions
            $this->info('Deleting related balance transactions...');
            BalanceTransaction::whereIn('reference_type', ['purchase_order', 'payment'])->delete();

            // 6. Delete businesses except specified ones
            $this->info('Deleting businesses except specified ones...');
            $protectedEmails = [
                'operations@foodstuff.store',
                'support@foodstuff.store',
                'bomadokubo@foodstuff.store'
            ];

            $deletedBusinessCount = Business::whereNotIn('email', $protectedEmails)->delete();
            $this->info("Deleted {$deletedBusinessCount} businesses");

            // 7. Reset balances for remaining businesses
            $this->info('Resetting balances for remaining businesses...');
            $remainingBusinesses = Business::all();

            foreach ($remainingBusinesses as $business) {
                // Reset to initial credit assignment
                $business->update([
                    'available_balance' => $business->current_balance,
                    'credit_balance' => 0,
                    'credit_limit' => $business->current_balance,
                ]);
            }

            DB::commit();

            $this->info('✅ Successfully cleared all data!');
            $this->info("Deleted: {$poCount} purchase orders, {$paymentCount} payments, {$vendorCount} vendors, {$deletedBusinessCount} businesses, {$transactionCount} transactions");
            $this->info("Reset balances for {$remainingBusinesses->count()} remaining businesses");
            $this->info("Protected businesses: " . implode(', ', $protectedEmails));

        } catch (\Exception $e) {
            DB::rollback();
            $this->error('❌ Error occurred: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
