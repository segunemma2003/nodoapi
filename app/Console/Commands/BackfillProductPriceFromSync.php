<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\SyncedProduct;
use Illuminate\Console\Command;

class BackfillProductPriceFromSync extends Command
{
    protected $signature = 'products:backfill-price-from-sync {--dry-run}';

    protected $description = 'Fill in the real price (from the invoicing-system catalog) for products stuck at the old price=1000/quantity=0 placeholder, matching by name. Never deletes or touches quantity.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $bad = Product::where('price', 1000)->where('quantity', 0)->get();

        $updated = 0;
        $ambiguous = 0;
        $unmatched = 0;

        foreach ($bad as $product) {
            $matches = SyncedProduct::whereRaw('LOWER(TRIM(product_name)) = ?', [strtolower(trim($product->name))])
                ->where('is_active', true)
                ->where('cost_price', '>', 0)
                ->get();

            if ($matches->count() === 0) {
                $unmatched++;
                continue;
            }

            if ($matches->count() > 1) {
                $ambiguous++;
                continue;
            }

            $price = $matches->first()->cost_price;

            $this->line("#{$product->id} business={$product->business_id} \"{$product->name}\": 1000 -> {$price}");

            if (!$dryRun) {
                $product->update(['price' => $price]);
            }

            $updated++;
        }

        $this->info(($dryRun ? '[dry run] ' : '') . "Backfilled: {$updated}, ambiguous (multiple market prices, skipped): {$ambiguous}, no match in invoicing system (skipped): {$unmatched}");

        return self::SUCCESS;
    }
}
