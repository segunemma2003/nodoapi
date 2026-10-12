<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class DeduplicateProductsByName extends Command
{
    protected $signature = 'products:dedupe-by-name';

    protected $description = 'Merge products that share the same name (per business) into one row, keeping the most informative price';

    public function handle(): int
    {
        $duplicateGroups = Product::selectRaw('business_id, LOWER(TRIM(name)) as normalized_name')
            ->groupBy('business_id', 'normalized_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $merged = 0;

        foreach ($duplicateGroups as $group) {
            $rows = Product::where('business_id', $group->business_id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$group->normalized_name])
                ->orderByDesc('updated_at')
                ->get();

            // Prefer a row that was actually priced (not the price=1000,
            // quantity=0 bug signature) over one that wasn't, before falling
            // back to whichever was updated most recently.
            $keeper = $rows->first(fn ($p) => !($p->price == 1000 && $p->quantity == 0)) ?? $rows->first();

            $rows->reject(fn ($p) => $p->id === $keeper->id)
                ->each(fn ($p) => $p->delete());

            $merged++;
        }

        $this->info("Merged {$merged} duplicate-name product groups.");

        return self::SUCCESS;
    }
}
