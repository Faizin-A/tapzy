<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\Product\IndexProductRequest;
use App\Http\Requests\Merchant\Product\StoreProductRequest;
use App\Http\Requests\Merchant\Product\UpdateProductRequest;
use App\Http\Requests\Merchant\Product\UpdateProductStatusRequest;
use App\Http\Resources\MerchantProductResource;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Merchant\ProductManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    public function index(IndexProductRequest $request): AnonymousResourceCollection
    {
        $query = Product::query();
        $validated = $request->validated();

        if (($validated['search'] ?? null) !== null && $validated['search'] !== '') {
            $query->where('name', 'like', '%'.$validated['search'].'%');
        }
        foreach (['is_active', 'is_available'] as $filter) {
            if (array_key_exists($filter, $validated)) $query->where($filter, (bool) $validated[$filter]);
        }

        return MerchantProductResource::collection($query
            ->orderBy($validated['sort'] ?? 'created_at', $validated['direction'] ?? 'desc')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString());
    }

    public function store(StoreProductRequest $request, ProductManagementService $service): \Illuminate\Http\JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('create', Product::class);

        $resource = MerchantProductResource::make($service->create(
            $this->merchantStore($request), $request->validated(), $this->actor($request), $request->ip(), $request->userAgent()
        ));

        return $resource->response()->setStatusCode(201);
    }

    public function show(Request $request, Product $product): MerchantProductResource
    {
        Gate::forUser($this->actor($request))->authorize('view', $product);

        return MerchantProductResource::make($product);
    }

    public function update(UpdateProductRequest $request, Product $product, ProductManagementService $service): MerchantProductResource
    {
        Gate::forUser($this->actor($request))->authorize('update', $product);

        return MerchantProductResource::make($service->update(
            $product, $request->validated(), $this->actor($request), $request->ip(), $request->userAgent()
        ));
    }

    public function destroy(Request $request, Product $product, ProductManagementService $service): \Illuminate\Http\Response
    {
        Gate::forUser($this->actor($request))->authorize('delete', $product);
        $service->delete($product, $this->actor($request), $request->ip(), $request->userAgent());

        return response()->noContent();
    }

    public function status(UpdateProductStatusRequest $request, Product $product, ProductManagementService $service): MerchantProductResource
    {
        Gate::forUser($this->actor($request))->authorize('update', $product);

        return MerchantProductResource::make($service->updateStatus(
            $product, $request->validated(), $this->actor($request), $request->ip(), $request->userAgent()
        ));
    }

    private function merchantStore(Request $request): Store
    {
        $store = $request->attributes->get('active_merchant_store');
        abort_unless($store instanceof Store, 403, 'Akses toko tidak tersedia.');

        return $store;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'Akses tidak tersedia.');

        return $user;
    }
}
