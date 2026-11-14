<?php

namespace App\Services\Merchant;

use App\Models\Order;
use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class MerchantDashboardService
{
    /** @return array<string, mixed> */
    public function summary(Store $store): array
    {
        $queueStatuses = ['waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled'];
        $orderStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
        $session = QueueSession::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        $queueCounts = array_fill_keys($queueStatuses, 0);
        if ($session !== null) {
            $rows = Queue::query()
                ->where('store_id', $store->id)
                ->where('queue_session_id', $session->id)
                ->select('status', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('status')
                ->pluck('aggregate', 'status');
            foreach ($rows as $status => $count) {
                $queueCounts[$status] = (int) $count;
            }
        }

        $orderCounts = array_fill_keys($orderStatuses, 0);
        $rows = Order::query()
            ->where('store_id', $store->id)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        foreach ($rows as $status => $count) {
            $orderCounts[$status] = (int) $count;
        }

        return [
            'store' => $store,
            'queue_session' => $session === null ? null : [
                'session_code' => $session->session_code,
                'session_date' => $session->session_date?->toDateString(),
                'status' => $session->status,
                'last_queue_number' => $session->last_queue_number,
                'opened_at' => $session->opened_at?->toIso8601String(),
            ],
            'queue_counts' => $queueCounts,
            'order_counts' => $orderCounts,
        ];
    }
}
