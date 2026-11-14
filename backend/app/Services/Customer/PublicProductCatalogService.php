<?php

namespace App\Services\Customer;

use App\Models\Product;
use App\Models\Scopes\TenantScope;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Fetches the public product catalog for a resolved store.
 * Only products that are active, available, and not soft-deleted are returned.
 * Filtering and searching are applied server-side, strictly scoped to the
 * resolved store to prevent any cross-tenant data leakage.
 */
class PublicProductCatalogService
{
    /**
     * Retrieve the filtered catalog for the given store.
     *
     * Category and search filters are optional. When provided they narrow the
     * product list but never expand the scope beyond the resolved store.
     *
     * @return array{
     *   categories: list<string>,
     *   products: Collection<int, Product>,
     * }
     */
    public function getCatalog(Store $store, ?string $category = null, ?string $search = null): array
    {
        // Fetch ALL active+available products for the store first so we can
        // derive the full category list before applying optional filters.
        $allVisible = Product::withoutGlobalScope(TenantScope::class)
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->where('is_available', true)
            ->whereNull('deleted_at')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        // Unique, alphabetically sorted, non-null categories from full visible set.
        $categories = $allVisible
            ->pluck('category')
            ->filter(fn ($c) => $c !== null && $c !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        // Apply optional filters to produce the displayed product list.
        $products = $allVisible->when(
            $category !== null && $category !== '',
            fn (Collection $c) => $c->where('category', $category)->values()
        )->when(
            $search !== null && $search !== '',
            fn (Collection $c) => $c->filter(
                fn (Product $p) => str_contains(mb_strtolower($p->name), mb_strtolower($search))
            )->values()
        );

        return [
            'categories' => $categories,
            'products' => $products,
        ];
    }
}
