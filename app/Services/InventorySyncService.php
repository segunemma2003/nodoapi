<?php

namespace App\Services;

use App\Models\SyncedProduct;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the product catalog + cost prices from newinventory (the invoicing
 * app) into a local cache (SyncedProduct) so purchase order prices can be
 * validated without calling out to newinventory on every request.
 */
class InventorySyncService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.inventory.url'), '/');
        $this->apiKey = (string) config('services.inventory.key');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /**
     * Fetch every active product from newinventory and upsert it locally.
     * Returns the number of products synced.
     */
    public function syncProducts(): int
    {
        if (!$this->isConfigured()) {
            Log::warning('InventorySyncService: INVENTORY_API_URL/INVENTORY_API_KEY not configured, skipping sync');
            return 0;
        }

        $this->deduplicateByName();

        $synced = 0;
        $skip = 0;
        $limit = 200;

        while (true) {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->get("{$this->baseUrl}/api/v1/products", [
                    'skip' => $skip,
                    'limit' => $limit,
                    'is_active' => true,
                ]);

            if (!$response->successful()) {
                Log::error('InventorySyncService: failed to fetch products', [
                    'status' => $response->status(),
                    'skip' => $skip,
                ]);
                break;
            }

            $body = $response->json();
            $items = is_array($body) && array_is_list($body) ? $body : ($body['items'] ?? []);

            foreach ($items as $item) {
                $this->upsertProduct($item);
                $synced++;
            }

            if (count($items) < $limit) {
                break;
            }
            $skip += $limit;
        }

        return $synced;
    }

    /**
     * Collapse any rows left over from before name-based matching (or any
     * future drift) down to one per product name — keeping whichever was
     * synced most recently. Safe to run every time: existing POs only ever
     * read their own saved item snapshot, never this table, so deleting a
     * duplicate row here can't affect anything already created.
     */
    protected function deduplicateByName(): void
    {
        $duplicateNames = SyncedProduct::select('product_name')
            ->groupBy('product_name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('product_name');

        foreach ($duplicateNames as $name) {
            $rows = SyncedProduct::where('product_name', $name)
                ->orderByDesc('last_synced_at')
                ->orderByDesc('id')
                ->get();

            $rows->skip(1)->each(fn ($row) => $row->delete());
        }
    }

    /**
     * newinventory can list the same product name more than once (e.g. one
     * row per market), each with its own id and price. We only need one
     * local row per name — matching here by name (not inventory_product_id)
     * means a product that reappears under a different id just gets its
     * price refreshed on the same row instead of creating a duplicate.
     * Existing POs are unaffected either way: their items are a saved
     * snapshot, never a live reference back into this table.
     */
    protected function upsertProduct(array $item): void
    {
        $costPriceKobo = $item['current_cost_price'] ?? null;
        $name = trim($item['product_name'] ?? '');

        if ($name === '') {
            return;
        }

        SyncedProduct::updateOrCreate(
            ['product_name' => $name],
            [
                'inventory_product_id' => $item['id'],
                'sku' => $item['sku'] ?? null,
                'category' => $item['category']['name'] ?? null,
                'unit_of_measure' => $item['unit_of_measure'] ?? null,
                'market_id' => $item['market_id'] ?? null,
                'market_name' => $item['market_name'] ?? null,
                'cost_price' => $costPriceKobo !== null ? $costPriceKobo / 100 : 0,
                'cost_price_effective_date' => $item['cost_price_effective_date'] ?? null,
                'is_active' => $item['is_active'] ?? true,
                'last_synced_at' => now(),
            ]
        );
    }
}
