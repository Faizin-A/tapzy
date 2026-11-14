<?php

namespace App\Services\Customer;

use App\Exceptions\OrderAlreadyExistsException;
use App\Exceptions\OrderingDisabledException;
use App\Exceptions\ProductNotAvailableException;
use App\Exceptions\QueueNotOrderableException;
use App\Exceptions\QueueSessionInactiveException;
use App\Exceptions\StoreNotOperationalException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\Scopes\TenantScope;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerOrderCreationService
{
    private const ORDERABLE_QUEUE_STATUSES = ['waiting', 'called', 'in_service'];

    /**
     * Create a customer order for the queue identified by the plaintext access
     * token. The queue, session, store, and product ownership are resolved on
     * the backend; request data is never trusted for prices or tenant identity.
     *
     * @param  array<string, mixed>  $validatedData
     */
    public function create(string $accessToken, array $validatedData): ?Order
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $accessToken)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($accessToken, $validatedData): ?Order {
                $queue = Queue::withoutGlobalScope(TenantScope::class)
                    ->where('access_token_hash', hash('sha256', $accessToken))
                    ->lockForUpdate()
                    ->first();

                if ($queue === null) {
                    return null;
                }

                // Store uses SoftDeletes: a soft-deleted store must look like a
                // missing resource, while an existing but inactive/expired
                // store is an explicit business conflict.
                $store = Store::query()->whereKey($queue->store_id)->first();

                if ($store === null) {
                    return null;
                }

                if (! $store->isOperational()) {
                    throw new StoreNotOperationalException;
                }

                if ($store->ordering_mode === 'queue_only') {
                    throw new OrderingDisabledException;
                }

                $session = QueueSession::withoutGlobalScope(TenantScope::class)
                    ->where('id', $queue->queue_session_id)
                    ->where('store_id', $queue->store_id)
                    ->lockForUpdate()
                    ->first();

                if ($session === null || $session->status !== 'active') {
                    throw new QueueSessionInactiveException;
                }

                if (! in_array($queue->status, self::ORDERABLE_QUEUE_STATUSES, true)) {
                    throw new QueueNotOrderableException;
                }

                // Include soft-deleted orders because the database unique index
                // on queue_id still protects the one-order-per-queue rule.
                $existingOrder = Order::withoutGlobalScope(TenantScope::class)
                    ->withTrashed()
                    ->where('queue_id', $queue->id)
                    ->first();

                if ($existingOrder !== null) {
                    throw new OrderAlreadyExistsException;
                }

                $items = array_values($validatedData['items']);
                $productIds = array_values(array_unique(array_map(
                    fn (array $item): int => (int) $item['product_id'],
                    $items
                )));

                /** @var Collection<int, Product> $products */
                $products = Product::withoutGlobalScope(TenantScope::class)
                    ->where('store_id', $queue->store_id)
                    ->whereIn('id', $productIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($products->count() !== count($productIds)) {
                    throw new ProductNotAvailableException;
                }

                foreach ($products as $product) {
                    if ($product->trashed() || ! $product->is_active || ! $product->is_available) {
                        throw new ProductNotAvailableException;
                    }
                }

                $order = new Order;
                $order->order_code = 'ORD-'.(string) Str::ulid();
                $order->store_id = $queue->store_id;
                $order->queue_id = $queue->id;
                $order->nfc_card_id = $queue->nfc_card_id;
                $order->customer_name = $queue->customer_name;
                $order->status = 'pending';
                $order->notes = $validatedData['notes'] ?? null;
                $order->total_items = 0;
                $order->subtotal = '0.00';
                $order->tax_amount = '0.00';
                $order->discount_amount = '0.00';
                $order->total_price = '0.00';
                $order->save();

                $totalItems = 0;
                $subtotal = '0.00';

                foreach ($items as $item) {
                    /** @var Product $product */
                    $product = $products->get((int) $item['product_id']);
                    $quantity = (int) $item['quantity'];
                    $unitPrice = (string) $product->price;
                    $lineSubtotal = bcmul($unitPrice, (string) $quantity, 2);

                    $orderItem = new OrderItem;
                    $orderItem->order_id = $order->id;
                    $orderItem->product_id = $product->id;
                    $orderItem->product_name_snapshot = $product->name;
                    $orderItem->unit_price_snapshot = $unitPrice;
                    $orderItem->quantity = $quantity;
                    $orderItem->subtotal = $lineSubtotal;
                    $orderItem->notes = $item['notes'] ?? null;
                    $orderItem->save();

                    $totalItems += $quantity;
                    $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                }

                $order->total_items = $totalItems;
                $order->subtotal = $subtotal;
                $order->total_price = $subtotal;
                $order->save();

                // OrderItem has a tenant-owner scope. Public requests have no
                // merchant tenant context, so load the relation explicitly.
                $order->setRelation(
                    'items',
                    OrderItem::withoutGlobalScope(TenantScope::class)
                        ->where('order_id', $order->id)
                        ->orderBy('id')
                        ->get()
                );

                return $order;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000'
                && str_contains($exception->getMessage(), 'uq_orders_queue_id')) {
                throw new OrderAlreadyExistsException;
            }

            throw $exception;
        }
    }
}
