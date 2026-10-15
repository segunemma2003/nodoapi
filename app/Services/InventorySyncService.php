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

        $this->deduplicateByNameAndMarket();

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
                try {
                    $this->upsertProduct($item);
                    $synced++;
                } catch (\Throwable $e) {
                    // One malformed/conflicting record (e.g. bad data in
                    // newinventory, or an id reused under a different name)
                    // must not abort the sync for every other product.
                    Log::error('InventorySyncService: skipped a product that failed to sync', [
                        'item_id' => $item['id'] ?? null,
                        'product_name' => $item['product_name'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (count($items) < $limit) {
                break;
            }
            $skip += $limit;
        }

        return $synced;
    }

    /**
     * Search newinventory live, bypassing the cache entirely, so a price,
     * unit of measure, or market change there shows up on FS Pay the moment
     * someone searches for the product -- not whenever the next background
     * sync happens to run. Returns null (never throws) if newinventory can't
     * be reached right now, so callers can fall back to the cached table.
     *
     * @return array<int, array{inventory_product_id:int,product_name:string,sku:?string,unit_of_measure:?string,market_name:?string,cost_price:float}>|null
     */
    public function searchLive(?string $search, ?string $marketName): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(8)
                ->get("{$this->baseUrl}/api/v1/products", array_filter([
                    'search' => $search,
                    'is_active' => true,
                    'limit' => 50,
                ]));
        } catch (\Throwable $e) {
            Log::warning('InventorySyncService: live search failed, caller should fall back to cache', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $body = $response->json();
        $items = is_array($body) && array_is_list($body) ? $body : ($body['items'] ?? []);

        $results = [];
        foreach ($items as $item) {
            $name = trim($item['product_name'] ?? '');
            if ($name === '' || ctype_digit($name)) {
                continue;
            }
            if ($marketName !== null && ($item['market_name'] ?? null) !== $marketName) {
                continue;
            }
            $costPriceKobo = $item['current_cost_price'] ?? null;
            $results[] = [
                'inventory_product_id' => $item['id'],
                'product_name' => $name,
                'sku' => $item['sku'] ?? null,
                'unit_of_measure' => $item['unit_of_measure'] ?? null,
                'market_name' => $item['market_name'] ?? null,
                'cost_price' => $costPriceKobo !== null ? $costPriceKobo / 100 : 0,
            ];
        }

        return $results;
    }

    /**
     * The active market names newinventory currently has, live -- not
     * whatever happened to exist in the cache as of the last sync. Returns
     * null (never throws) if newinventory can't be reached.
     *
     * @return array<int, string>|null
     */
    public function listMarketsLive(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(8)
                ->get("{$this->baseUrl}/api/v1/products/markets");
        } catch (\Throwable $e) {
            Log::warning('InventorySyncService: live market list failed, caller should fall back to cache', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $markets = collect($response->json())
            ->filter(fn ($m) => ($m['is_active'] ?? true) && !empty($m['name']))
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $markets;
    }

    /**
     * Collapse any rows left over from before this matching scheme (or any
     * future drift) down to one per (product_name, market_id) pair —
     * keeping whichever was synced most recently. A product genuinely sold
     * in several markets keeps one row per market, since the price (and
     * what a vendor in that market should be charging) differs by market.
     * Safe to run every time: existing POs only ever read their own saved
     * item snapshot, never this table, so deleting a duplicate row here
     * can't affect anything already created.
     */
    protected function deduplicateByNameAndMarket(): void
    {
        $duplicateGroups = SyncedProduct::select('product_name', 'market_id')
            ->groupBy('product_name', 'market_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $rows = SyncedProduct::where('product_name', $group->product_name)
                ->where(function ($q) use ($group) {
                    $group->market_id === null
                        ? $q->whereNull('market_id')
                        : $q->where('market_id', $group->market_id);
                })
                ->orderByDesc('last_synced_at')
                ->orderByDesc('id')
                ->get();

            $rows->skip(1)->each(fn ($row) => $row->delete());
        }
    }

    /**
     * newinventory can reassign a product's id over time (e.g. re-listing
     * it) while its name and market stay the same. Matching on
     * (product_name, market_id) instead of inventory_product_id means that
     * just refreshes the existing row's price instead of creating a
     * duplicate — while still keeping genuinely different markets as
     * separate rows, since the same product name can have a different real
     * price in each market. Existing POs are unaffected either way: their
     * items are a saved snapshot, never a live reference back into this
     * table.
     */
    protected function upsertProduct(array $item): void
    {
        $costPriceKobo = $item['current_cost_price'] ?? null;
        $name = trim($item['product_name'] ?? '');
        $marketId = $item['market_id'] ?? null;

        if ($name === '' || ctype_digit($name)) {
            Log::warning('InventorySyncService: skipped a product with a missing/numeric-only name', [
                'item_id' => $item['id'] ?? null,
                'product_name' => $item['product_name'] ?? null,
            ]);
            return;
        }

        SyncedProduct::updateOrCreate(
            ['product_name' => $name, 'market_id' => $marketId],
            [
                'inventory_product_id' => $item['id'],
                'sku' => $item['sku'] ?? null,
                'category' => $item['category']['name'] ?? null,
                'unit_of_measure' => $item['unit_of_measure'] ?? null,
                'market_name' => $item['market_name'] ?? null,
                'cost_price' => $costPriceKobo !== null ? $costPriceKobo / 100 : 0,
                'cost_price_effective_date' => $item['cost_price_effective_date'] ?? null,
                'is_active' => $item['is_active'] ?? true,
                'last_synced_at' => now(),
            ]
        );
    }
}
