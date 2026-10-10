<?php

namespace App\Console\Commands;

use App\Services\InventorySyncService;
use Illuminate\Console\Command;

class SyncInventoryProducts extends Command
{
    protected $signature = 'inventory:sync-products';

    protected $description = 'Sync the product catalog and cost prices from newinventory';

    public function handle(InventorySyncService $service): int
    {
        if (!$service->isConfigured()) {
            $this->warn('INVENTORY_API_URL / INVENTORY_API_KEY are not set — skipping.');
            return self::SUCCESS;
        }

        $count = $service->syncProducts();
        $this->info("Synced {$count} products from newinventory.");

        return self::SUCCESS;
    }
}
