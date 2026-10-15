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

    /** @var array<string,int>|null */
    protected ?array $marketIdsByName = null;

    /** @var array<string,int>|null */
    protected ?array $categoryIdsByName = null;

    /**
     * newinventory's product search only takes market_id/category_id, not
     * names -- resolve the name to an id so filtering happens server-side
     * (before the result limit is applied) instead of discarding most of a
     * market's catalog by filtering a small globally-limited page in PHP.
     */
    protected function resolveMarketId(?string $marketName): ?int
    {
        if ($marketName === null) {
            return null;
        }

        if ($this->marketIdsByName === null) {
            $this->marketIdsByName = $this->fetchNameToIdMap('/api/v1/products/markets');
        }

        return $this->marketIdsByName[$marketName] ?? null;
    }

    protected function resolveCategoryId(?string $categoryName): ?int
    {
        if ($categoryName === null) {
            return null;
        }

        if ($this->categoryIdsByName === null) {
            $this->categoryIdsByName = $this->fetchNameToIdMap('/api/v1/products/categories');
        }

        return $this->categoryIdsByName[$categoryName] ?? null;
    }

    /**
     * @return array<string,int>
     */
    protected function fetchNameToIdMap(string $path): array
    {
        try {
            $response = Http::withToken($this->apiKey)->timeout(8)->get("{$this->baseUrl}{$path}");
        } catch (\Throwable $e) {
            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        $map = [];
        foreach ($response->json() ?? [] as $entry) {
            if (!empty($entry['name']) && isset($entry['id'])) {
                $map[$entry['name']] = $entry['id'];
            }
        }

        return $map;
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
     * Fetch specific products by id, live, keyed by inventory_product_id.
     * Used to validate/price a PO's items against newinventory directly --
     * the picker now shows live search results, so validating against the
     * (slower-to-update) synced_products cache alone could reject an item
     * the business just picked from a correctly-loaded dropdown. Returns
     * null (never throws) if newinventory can't be reached, so the caller
     * can fall back to the cache.
     *
     * @param array<int, int> $inventoryProductIds
     * @return array<int, array{inventory_product_id:int,product_name:string,sku:?string,unit_of_measure:?string,market_name:?string,category:?string,cost_price:float}>|null
     */
    public function getByIdsLive(array $inventoryProductIds): ?array
    {
        $ids = array_values(array_unique(array_filter($inventoryProductIds)));

        if (empty($ids)) {
            return [];
        }

        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $responses = Http::pool(fn ($pool) => array_map(
                fn ($id) => $pool->as((string) $id)
                    ->withToken($this->apiKey)
                    ->timeout(8)
                    ->get("{$this->baseUrl}/api/v1/products/{$id}"),
                $ids
            ));
        } catch (\Throwable $e) {
            Log::warning('InventorySyncService: live by-id lookup failed, caller should fall back to cache', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $results = [];
        foreach ($ids as $id) {
            $response = $responses[(string) $id] ?? null;
            if (!$response || $response instanceof \Throwable || !$response->successful()) {
                continue;
            }

            $item = $response->json();
            $name = trim($item['product_name'] ?? '');
            if ($name === '') {
                continue;
            }

            $costPriceKobo = $item['current_cost_price'] ?? null;
            $results[$id] = [
                'inventory_product_id' => $item['id'],
                'product_name' => $name,
                'sku' => $item['sku'] ?? null,
                'unit_of_measure' => $item['unit_of_measure'] ?? null,
                'market_name' => $item['market_name'] ?? null,
                'category' => $item['category']['name'] ?? null,
                'cost_price' => $costPriceKobo !== null ? $costPriceKobo / 100 : 0,
            ];
        }

        return $results;
    }

    /**
     * Search newinventory live, bypassing the cache entirely, so a price,
     * unit of measure, or market change there shows up on FS Pay the moment
     * someone searches for the product -- not whenever the next background
     * sync happens to run. Returns null (never throws) if newinventory can't
     * be reached right now, so callers can fall back to the cached table.
     *
     * @return array<int, array{inventory_product_id:int,product_name:string,sku:?string,unit_of_measure:?string,market_name:?string,category:?string,cost_price:float}>|null
     */
    public function searchLive(?string $search, ?string $marketName, ?string $categoryName = null): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $marketId = $this->resolveMarketId($marketName);
        $categoryId = $this->resolveCategoryId($categoryName);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(8)
                ->get("{$this->baseUrl}/api/v1/products", array_filter([
                    'search' => $search,
                    'market_id' => $marketId,
                    'category_id' => $categoryId,
                    'is_active' => true,
                    'limit' => 100,
                ], fn ($v) => $v !== null && $v !== ''));
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
            // newinventory already filtered by market_id/category_id above --
            // this is just a safety net in case a name couldn't be resolved
            // to an id (e.g. the vendor's market doesn't exactly match any
            // newinventory market name).
            if ($marketId === null && $marketName !== null && ($item['market_name'] ?? null) !== $marketName) {
                continue;
            }
            $category = $item['category']['name'] ?? null;
            if ($categoryId === null && $categoryName !== null && $category !== $categoryName) {
                continue;
            }
            $costPriceKobo = $item['current_cost_price'] ?? null;
            $results[] = [
                'inventory_product_id' => $item['id'],
                'product_name' => $name,
                'sku' => $item['sku'] ?? null,
                'unit_of_measure' => $item['unit_of_measure'] ?? null,
                'market_name' => $item['market_name'] ?? null,
                'category' => $category,
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
     * The active category names newinventory currently has, live. Returns
     * null (never throws) if newinventory can't be reached.
     *
     * @return array<int, string>|null
     */
    public function listCategoriesLive(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(8)
                ->get("{$this->baseUrl}/api/v1/products/categories");
        } catch (\Throwable $e) {
            Log::warning('InventorySyncService: live category list failed, caller should fall back to cache', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        return collect($response->json())
            ->filter(fn ($c) => ($c['is_active'] ?? true) && !empty($c['name']))
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->all();
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
