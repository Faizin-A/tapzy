<?php

namespace App\Services\Merchant;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProductManagementService
{
    private const FIELDS = [
        'name', 'description', 'price', 'category', 'image_url', 'is_available', 'is_active',
    ];

    public function __construct(private readonly AuditLogService $auditLogService) {}

    /** @param array<string, mixed> $data */
    public function create(Store $store, array $data, User $actor, ?string $ip = null, ?string $userAgent = null): Product
    {
        return DB::transaction(function () use ($store, $data, $actor, $ip, $userAgent): Product {
            $product = new Product;
            $product->store_id = $store->id;
            $product->fill(Arr::only($data, self::FIELDS));
            $product->save();
            $this->auditLogService->recordProduct(AuditLogService::PRODUCT_CREATED, $product, $actor, [], $this->auditLogService->productSnapshot($product), $ip, $userAgent);

            return $product->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, array $data, User $actor, ?string $ip = null, ?string $userAgent = null): Product
    {
        return DB::transaction(function () use ($product, $data, $actor, $ip, $userAgent): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $old = $this->auditLogService->productSnapshot($locked);
            $locked->fill(Arr::only($data, self::FIELDS));

            if ($locked->isDirty()) {
                $locked->save();
                $this->auditLogService->recordProduct(AuditLogService::PRODUCT_UPDATED, $locked, $actor, $old, $this->auditLogService->productSnapshot($locked), $ip, $userAgent);
            }

            return $locked->fresh();
        });
    }

    public function delete(Product $product, User $actor, ?string $ip = null, ?string $userAgent = null): void
    {
        DB::transaction(function () use ($product, $actor, $ip, $userAgent): void {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $old = $this->auditLogService->productSnapshot($locked);
            $locked->delete();
            $this->auditLogService->recordProduct(AuditLogService::PRODUCT_DELETED, $locked, $actor, $old, $this->auditLogService->productSnapshot($locked), $ip, $userAgent);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateStatus(Product $product, array $data, User $actor, ?string $ip = null, ?string $userAgent = null): Product
    {
        return DB::transaction(function () use ($product, $data, $actor, $ip, $userAgent): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $old = $this->auditLogService->productSnapshot($locked);
            $locked->fill(Arr::only($data, ['is_active', 'is_available']));

            if ($locked->isDirty()) {
                $locked->save();
                $this->auditLogService->recordProduct(AuditLogService::PRODUCT_STATUS_UPDATED, $locked, $actor, $old, $this->auditLogService->productSnapshot($locked), $ip, $userAgent);
            }

            return $locked->fresh();
        });
    }
}
