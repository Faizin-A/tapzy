<?php

namespace App\Services\Merchant;

use App\Models\Order;
use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\Store;
use App\Support\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class QueueManagementService
{
    private const ACTIVE_STATUSES = ['waiting', 'called', 'in_service', 'skipped'];

    private const COMPLETED_STATUSES = ['completed', 'cancelled'];

    private const ORDER_COMPLETABLE_STATUSES = ['pending', 'confirmed', 'preparing', 'ready'];

    public function list(Store $store, array $filters): LengthAwarePaginator
    {
        $session = $this->activeSession($store);
        $query = Queue::query()->with('order')->orderBy('queue_number');

        if ($session === null) {
            return $query->whereRaw('1 = 0')->paginate((int) ($filters['per_page'] ?? 15));
        }

        $query->where('store_id', $store->id)->where('queue_session_id', $session->id);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        } elseif (($filters['tab'] ?? 'active') === 'completed') {
            $query->whereIn('status', self::COMPLETED_STATUSES);
        } else {
            $query->whereIn('status', self::ACTIVE_STATUSES);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString();
    }

    public function find(Store $store, Queue $queue): Queue
    {
        $session = $this->activeSession($store);

        if ($session === null || (int) $queue->store_id !== (int) $store->id || (int) $queue->queue_session_id !== (int) $session->id) {
            abort(404);
        }

        return $queue->load('order');
    }

    public function callNext(Store $store): Queue
    {
        return DB::transaction(function () use ($store): Queue {
            $session = $this->lockActiveSession($store);
            $queue = Queue::query()
                ->where('store_id', $store->id)
                ->where('queue_session_id', $session->id)
                ->where('status', 'waiting')
                ->orderBy('queue_number')
                ->lockForUpdate()
                ->first();

            if ($queue === null) {
                throw new ConflictHttpException('Tidak ada antrean yang menunggu.');
            }

            return $this->applyTransition($queue, 'called');
        });
    }

    public function call(Store $store, Queue $queue): Queue
    {
        return $this->transition($store, $queue, ['waiting', 'skipped'], 'called');
    }

    public function start(Store $store, Queue $queue): Queue
    {
        return DB::transaction(function () use ($store, $queue): Queue {
            $session = $this->lockActiveSession($store);
            $lockedQueue = $this->lockQueue($store, $session, $queue);

            if ($lockedQueue->status !== 'called') {
                throw new ConflictHttpException('Hanya antrean yang dipanggil yang dapat mulai dilayani.');
            }

            if (Queue::query()
                ->where('store_id', $store->id)
                ->where('queue_session_id', $session->id)
                ->where('status', 'in_service')
                ->where('id', '!=', $lockedQueue->id)
                ->exists()) {
                throw new ConflictHttpException('Masih ada antrean lain yang sedang dilayani.');
            }

            return $this->applyTransition($lockedQueue, 'in_service');
        });
    }

    public function skip(Store $store, Queue $queue): Queue
    {
        return $this->transition($store, $queue, ['called'], 'skipped');
    }

    public function cancel(Store $store, Queue $queue): Queue
    {
        return $this->transition($store, $queue, ['waiting', 'called'], 'cancelled');
    }

    public function complete(Store $store, Queue $queue): Queue
    {
        return DB::transaction(function () use ($store, $queue): Queue {
            $session = $this->lockActiveSession($store);
            $lockedQueue = $this->lockQueue($store, $session, $queue);

            if ($lockedQueue->status !== 'in_service') {
                throw new ConflictHttpException('Hanya antrean yang sedang dilayani yang dapat diselesaikan.');
            }

            $order = Order::query()
                ->where('store_id', $store->id)
                ->where('queue_id', $lockedQueue->id)
                ->lockForUpdate()
                ->first();

            if ($order?->status === 'cancelled') {
                throw new ConflictHttpException('Order yang dibatalkan tidak dapat diselesaikan.');
            }

            if ($order !== null && in_array($order->status, self::ORDER_COMPLETABLE_STATUSES, true)) {
                $order->status = 'completed';
                $order->completed_at = now();
                $order->save();
            }

            return $this->applyTransition($lockedQueue, 'completed');
        });
    }

    private function transition(Store $store, Queue $queue, array $allowedStatuses, string $newStatus): Queue
    {
        return DB::transaction(function () use ($store, $queue, $allowedStatuses, $newStatus): Queue {
            $session = $this->lockActiveSession($store);
            $lockedQueue = $this->lockQueue($store, $session, $queue);

            if (! in_array($lockedQueue->status, $allowedStatuses, true)) {
                throw new ConflictHttpException('Transisi status antrean tidak diperbolehkan.');
            }

            return $this->applyTransition($lockedQueue, $newStatus);
        });
    }

    private function applyTransition(Queue $queue, string $newStatus): Queue
    {
        $previousStatus = (string) $queue->status;
        $now = now();
        $queue->status = $newStatus;

        match ($newStatus) {
            'called' => $queue->called_at = $now,
            'in_service' => $queue->served_at = $now,
            'completed' => $queue->completed_at = $now,
            'cancelled' => $queue->cancelled_at = $now,
            default => null,
        };

        $queue->save();

        DB::table('queue_status_histories')->insert([
            'queue_id' => $queue->id,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'changed_by_user_id' => app(TenantContext::class)->actorId(),
            'notes' => 'Merchant queue status operation',
            'created_at' => $now,
        ]);

        return $queue->fresh('order');
    }

    private function activeSession(Store $store): ?QueueSession
    {
        return QueueSession::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    private function lockActiveSession(Store $store): QueueSession
    {
        $session = QueueSession::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if ($session === null) {
            throw new ConflictHttpException('Tidak ada session antrean aktif.');
        }

        return $session;
    }

    private function lockQueue(Store $store, QueueSession $session, Queue $queue): Queue
    {
        return Queue::query()
            ->whereKey($queue->id)
            ->where('store_id', $store->id)
            ->where('queue_session_id', $session->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
