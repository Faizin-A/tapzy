<?php

namespace App\Models;

use App\Models\Concerns\ScopesToCurrentTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product name and price are immutable transaction snapshots populated by the
 * backend when an order item is created; this model does not calculate prices.
 */
#[Fillable(['quantity', 'notes'])]
class OrderItem extends Model
{
    use ScopesToCurrentTenant;

    protected static function tenantOwnerRelation(): ?string
    {
        return 'order';
    }

    protected function casts(): array
    {
        return [
            'unit_price_snapshot' => 'decimal:2',
            'quantity' => 'integer',
            'subtotal' => 'decimal:2',
        ];
    }

    /**
     * Get the order containing the item.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the referenced product, if it still exists physically.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
