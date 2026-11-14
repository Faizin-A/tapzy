<template>
    <AppShell>
        <LoadingState v-if="loading && !tracking" />
        <ErrorState v-else-if="trackingError && !tracking" :message="trackingError" @retry="loadTracking" />
        <section v-else-if="notFound" class="rounded-2xl border border-stone-200 bg-white p-6 text-center shadow-sm">
            <h1 class="text-lg font-semibold text-stone-900">Antrean tidak ditemukan</h1>
            <p class="mt-2 text-sm leading-6 text-stone-600">Tautan antrean tidak valid atau sudah tidak tersedia.</p>
        </section>

        <template v-else>
            <section class="rounded-2xl border border-emerald-200 bg-white p-5 text-center shadow-sm" aria-live="polite">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                    <svg viewBox="0 0 24 24" fill="none" class="h-8 w-8" aria-hidden="true"><path d="m5 13 4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </div>
                <h1 class="mt-4 text-xl font-semibold text-stone-900">{{ order ? 'Pesanan berhasil dibuat' : 'Status antrean Anda' }}</h1>
                <p class="mt-2 text-sm leading-6 text-stone-600">{{ storeName }}</p>
                <div class="mt-4 rounded-2xl bg-emerald-50 px-4 py-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Nomor antrean</p>
                    <p class="mt-1 text-4xl font-bold tracking-tight text-emerald-800">{{ queueLabel }}</p>
                    <p class="mt-2 text-sm font-semibold text-emerald-800">{{ statusLabel }}</p>
                    <p v-if="tracking" class="mt-1 text-xs text-emerald-700">{{ waitingAheadLabel }}<span v-if="estimateLabel"> · {{ estimateLabel }}</span></p>
                    <p v-if="order?.order_code" class="mt-2 text-xs font-medium text-emerald-700">Kode pesanan {{ order.order_code }}</p>
                </div>
            </section>

            <p v-if="trackingError" class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800" role="alert">
                {{ trackingError }} <button type="button" class="ml-1 font-semibold underline underline-offset-2" @click="loadTracking">Coba lagi</button>
            </p>

            <section v-if="tracking" class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold text-stone-900">Detail antrean</h2>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-xl bg-stone-50 p-3"><dt class="text-xs text-stone-500">Menunggu di depan</dt><dd class="mt-1 font-semibold text-stone-900">{{ tracking.waiting_ahead }}</dd></div>
                    <div class="rounded-xl bg-stone-50 p-3"><dt class="text-xs text-stone-500">Sesi antrean</dt><dd class="mt-1 font-semibold text-stone-900">{{ tracking.session?.session_code ?? '—' }}</dd></div>
                    <div class="col-span-2 rounded-xl bg-stone-50 p-3"><dt class="text-xs text-stone-500">Tanggal sesi</dt><dd class="mt-1 font-semibold text-stone-900">{{ tracking.session?.session_date ?? '—' }}</dd></div>
                </dl>
            </section>

            <section v-if="order" class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold text-stone-900">Rincian pesanan</h2>
                <ul class="mt-3 divide-y divide-stone-100">
                    <li v-for="(item, index) in order.items" :key="index" class="py-3 first:pt-0 last:pb-0">
                        <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="text-sm font-semibold text-stone-900">{{ item.product_name }}</p><p class="mt-0.5 text-xs text-stone-500">{{ item.quantity }} × {{ formatRupiah(item.unit_price) }}</p><p v-if="item.notes" class="mt-1 text-xs leading-5 text-amber-700">Catatan: {{ item.notes }}</p></div><span class="shrink-0 text-sm font-semibold text-stone-900">{{ formatRupiah(item.subtotal) }}</span></div>
                    </li>
                </ul>
                <div class="mt-3 flex items-center justify-between gap-3 border-t border-stone-200 pt-3"><span class="text-sm font-semibold text-stone-900">Total</span><span class="text-lg font-bold text-emerald-700">{{ formatRupiah(order.total_price) }}</span></div>
            </section>

            <div class="mt-4 space-y-2">
                <RouterLink v-if="storeToken" :to="{ name: 'store', params: { token: storeToken } }" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-stone-200 bg-white px-4 text-sm font-semibold text-stone-700 shadow-sm">Kembali ke toko</RouterLink>
                <RouterLink v-if="canOrderMore" :to="{ name: 'menu', params: { token: storeToken } }" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white">Pesan menu</RouterLink>
            </div>
        </template>
    </AppShell>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import api, { getErrorMessage, isNotFound, isRequestCanceled } from '../api';
import AppShell from '../components/AppShell.vue';
import ErrorState from '../components/ErrorState.vue';
import LoadingState from '../components/LoadingState.vue';
import { formatRupiah } from '../format';
import { getOrder, getQueueContext } from '../storage';

const route = useRoute();
const accessToken = computed(() => String(route.params.accessToken ?? ''));
const queueContext = ref(null);
const storedOrder = ref(null);
const order = computed(() => storedOrder.value?.order ?? null);
const loading = ref(true);
const tracking = ref(null);
const trackingError = ref(null);
const notFound = ref(false);
const componentMounted = ref(false);
const pollInFlight = ref(false);
const generationId = ref(0);
let pollTimer = null;
let abortController = null;

const hasContext = computed(() => queueContext.value?.access_token === accessToken.value);
const storeToken = computed(() => storedOrder.value?.store_token ?? (hasContext.value ? queueContext.value.store_token : ''));
const storeName = computed(() => tracking.value?.store?.name ?? storedOrder.value?.store_name ?? (hasContext.value ? queueContext.value.store_name : null) ?? 'Toko');
const queueLabel = computed(() => tracking.value?.formatted_number ?? storedOrder.value?.queue_number ?? (hasContext.value ? queueContext.value.formatted_number : null) ?? '—');
const statusLabels = { waiting: 'Menunggu', called: 'Dipanggil', in_service: 'Sedang dilayani', skipped: 'Dilewati sementara', completed: 'Selesai', cancelled: 'Dibatalkan' };
const statusLabel = computed(() => statusLabels[tracking.value?.status] ?? 'Memuat status');
const waitingAheadLabel = computed(() => `${tracking.value?.waiting_ahead ?? 0} antrean di depan`);
const estimateLabel = computed(() => tracking.value?.estimated_wait_minutes != null ? `Estimasi ${tracking.value.estimated_wait_minutes} menit` : '');
const canOrderMore = computed(() => !order.value && hasContext.value && queueContext.value.ordering_mode === 'queue_and_order');

function isTerminal(status) { return status === 'completed' || status === 'cancelled'; }

function isCurrentRequest(token, requestGeneration) {
    return componentMounted.value
        && requestGeneration === generationId.value
        && token === accessToken.value;
}

function abortTrackingRequest() {
    abortController?.abort();
    abortController = null;
    pollInFlight.value = false;
}

function resetForToken(token) {
    generationId.value += 1;
    abortTrackingRequest();
    stopPolling();
    tracking.value = null;
    trackingError.value = null;
    notFound.value = false;
    loading.value = Boolean(token);
    queueContext.value = getQueueContext();
    storedOrder.value = token ? getOrder(token) : null;

    if (!token) {
        loading.value = false;
        return;
    }

    const requestGeneration = generationId.value;
    startPolling();
    loadTracking(token, requestGeneration);
}

async function loadTracking(token = accessToken.value, requestGeneration = generationId.value) {
    if (!isCurrentRequest(token, requestGeneration) || pollInFlight.value) return;
    pollInFlight.value = true;
    trackingError.value = null;
    const controller = new AbortController();
    abortController = controller;

    try {
        const response = await api.get(`/public/queues/${encodeURIComponent(token)}`, { signal: controller.signal });
        if (!isCurrentRequest(token, requestGeneration)) return;
        tracking.value = response.data?.data ?? null;
        notFound.value = false;
        document.title = `${tracking.value?.formatted_number ?? 'Antrean'} - TAPZY`;
        if (isTerminal(tracking.value?.status)) stopPolling();
    } catch (requestError) {
        if (!isCurrentRequest(token, requestGeneration) || isRequestCanceled(requestError)) return;
        if (isNotFound(requestError)) { notFound.value = true; stopPolling(); return; }
        trackingError.value = getErrorMessage(requestError);
    } finally {
        if (abortController === controller) {
            abortController = null;
            pollInFlight.value = false;
        }

        if (requestGeneration === generationId.value) loading.value = false;
    }
}

function startPolling() {
    if (pollTimer !== null || !componentMounted.value) return;
    pollTimer = window.setInterval(() => {
        if (document.visibilityState === 'visible' && !isTerminal(tracking.value?.status)) {
            loadTracking(accessToken.value, generationId.value);
        }
    }, 10000);
}

function stopPolling() {
    if (pollTimer !== null) { window.clearInterval(pollTimer); pollTimer = null; }
}

function handleVisibilityChange() {
    if (document.visibilityState === 'visible' && !isTerminal(tracking.value?.status)) {
        startPolling();
        loadTracking(accessToken.value, generationId.value);
    }
}

watch(
    () => String(route.params.accessToken ?? ''),
    (token, previousToken) => {
        if (!componentMounted.value || token === previousToken) return;
        resetForToken(token);
    },
);

onMounted(() => {
    componentMounted.value = true;
    document.addEventListener('visibilitychange', handleVisibilityChange);
    resetForToken(accessToken.value);
});

onBeforeUnmount(() => {
    componentMounted.value = false;
    generationId.value += 1;
    stopPolling();
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    abortTrackingRequest();
});
</script>
