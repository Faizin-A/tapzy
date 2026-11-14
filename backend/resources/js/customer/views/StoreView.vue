<template>
    <AppShell>
        <LoadingState v-if="loading" />

        <ErrorState
            v-else-if="error"
            :message="error"
            @retry="load"
        />

        <section
            v-else-if="notFound"
            class="rounded-2xl border border-stone-200 bg-white p-6 text-center shadow-sm"
        >
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-stone-100 text-stone-500">
                <svg viewBox="0 0 24 24" fill="none" class="h-7 w-7" aria-hidden="true">
                    <path
                        d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </div>
            <h1 class="mt-4 text-lg font-semibold text-stone-900">Toko tidak ditemukan</h1>
            <p class="mt-2 text-sm leading-6 text-stone-600">
                Tautan NFC atau QR ini tidak valid, kartu tidak aktif, atau toko sedang tidak dapat diakses.
            </p>
        </section>

        <template v-else>
            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold leading-tight text-stone-900">
                            {{ store.name }}
                        </h1>
                        <p class="mt-1 text-sm text-stone-500">
                            Nomor antrean diawali {{ store.queue_prefix }}
                        </p>
                    </div>
                    <span
                        class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold"
                        :class="store.ordering_mode === 'queue_and_order'
                            ? 'bg-emerald-100 text-emerald-800'
                            : 'bg-amber-100 text-amber-800'"
                    >
                        {{ store.ordering_mode === 'queue_and_order' ? 'Antrean + pesanan' : 'Antrean saja' }}
                    </span>
                </div>

                <p v-if="store.description" class="mt-4 text-sm leading-6 text-stone-600">
                    {{ store.description }}
                </p>

                <dl class="mt-4 space-y-2 text-sm">
                    <div v-if="store.address" class="flex gap-2">
                        <dt class="w-20 shrink-0 text-stone-500">Alamat</dt>
                        <dd class="text-stone-800">{{ store.address }}</dd>
                    </div>
                    <div v-if="store.phone" class="flex gap-2">
                        <dt class="w-20 shrink-0 text-stone-500">Telepon</dt>
                        <dd>
                            <a
                                :href="`tel:${store.phone}`"
                                class="font-medium text-emerald-700 underline-offset-2 hover:underline"
                            >
                                {{ store.phone }}
                            </a>
                        </dd>
                    </div>
                    <div v-if="safeMapsUrl" class="flex gap-2">
                        <dt class="w-20 shrink-0 text-stone-500">Lokasi</dt>
                        <dd>
                            <a
                                :href="safeMapsUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-medium text-emerald-700 underline-offset-2 hover:underline"
                            >
                                Buka peta
                            </a>
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="mt-4 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm" aria-live="polite">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-stone-900">Status antrean</h2>
                    <span
                        class="rounded-full px-3 py-1 text-xs font-semibold"
                        :class="queueStatus?.is_session_active
                            ? 'bg-emerald-100 text-emerald-800'
                            : 'bg-stone-100 text-stone-600'"
                    >
                        {{ queueStatus?.is_session_active ? 'Antrean dibuka' : 'Antrean belum dibuka' }}
                    </span>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-stone-50 p-3">
                        <p class="text-xs font-medium text-stone-500">Sedang dilayani</p>
                        <p class="mt-1 text-2xl font-semibold text-stone-900">
                            {{ queueStatus?.currently_serving ?? '—' }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-stone-50 p-3">
                        <p class="text-xs font-medium text-stone-500">Masih menunggu</p>
                        <p class="mt-1 text-2xl font-semibold text-stone-900">
                            {{ queueStatus?.waiting_count ?? 0 }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-stone-50 p-3">
                        <p class="text-xs font-medium text-stone-500">Estimasi tunggu</p>
                        <p class="mt-1 text-lg font-semibold text-stone-900">
                            {{ estimatedWaitLabel }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-stone-50 p-3">
                        <p class="text-xs font-medium text-stone-500">Rata-rata layanan</p>
                        <p class="mt-1 text-lg font-semibold text-stone-900">
                            {{ queueStatus?.avg_service_time_minutes ?? store.avg_service_time }} menit
                        </p>
                    </div>
                </div>

                <p v-if="queueError" class="mt-4 rounded-xl bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800">
                    {{ queueError }}
                    <button
                        type="button"
                        class="ml-1 font-semibold underline underline-offset-2"
                        @click="refreshQueueStatus"
                    >
                        Muat ulang
                    </button>
                </p>
            </section>

            <section
                v-if="existingQueue"
                class="mt-4 rounded-2xl border border-emerald-200 bg-white p-5 shadow-sm"
            >
                <h2 class="text-base font-semibold text-stone-900">Antrean Anda</h2>
                <div class="mt-3 rounded-2xl bg-emerald-50 px-4 py-5 text-center">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">
                        Nomor antrean
                    </p>
                    <p class="mt-1 text-3xl font-bold tracking-tight text-emerald-800">
                        {{ existingQueue.formatted_number }}
                    </p>
                    <p class="mt-1 text-xs text-emerald-700">
                        {{ existingQueue.customer_name ? `Atas nama ${existingQueue.customer_name}` : 'Simpan halaman ini' }}
                    </p>
                </div>

                <p class="mt-3 text-sm leading-6 text-stone-600">
                    {{ existingOrder
                        ? 'Pesanan untuk antrean ini sudah dibuat.'
                        : 'Gunakan tombol di bawah untuk melanjutkan.' }}
                </p>

                <div class="mt-4 space-y-2">
                    <RouterLink
                        v-if="existingOrder"
                        :to="{ name: 'order-success', params: { accessToken: existingQueue.access_token } }"
                        class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white transition hover:bg-emerald-800"
                    >
                        Lihat Pesanan
                    </RouterLink>
                    <RouterLink
                        v-else-if="existingQueue.ordering_mode === 'queue_and_order' && queueStatus?.is_session_active"
                        :to="{ name: 'menu', params: { token } }"
                        class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white transition hover:bg-emerald-800"
                    >
                        Pesan Menu
                    </RouterLink>
                    <RouterLink
                        v-if="!existingOrder"
                        :to="{ name: 'order-success', params: { accessToken: existingQueue.access_token } }"
                        class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-stone-200 bg-white px-4 text-sm font-semibold text-stone-700"
                    >
                        Lihat Status Antrean
                    </RouterLink>
                </div>
            </section>

            <section v-else class="mt-4 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
                <template v-if="store.ordering_mode === 'queue_and_order'">
                    <h2 class="text-base font-semibold text-stone-900">Antrean dan pesanan</h2>
                    <p class="mt-2 text-sm leading-6 text-stone-600">
                        Ambil nomor antrean, lalu pilih menu jika ingin memesan. Pembayaran dilakukan langsung di toko.
                    </p>
                </template>
                <template v-else>
                    <h2 class="text-base font-semibold text-stone-900">Antrean saja</h2>
                    <p class="mt-2 text-sm leading-6 text-stone-600">
                        Toko ini hanya menggunakan antrean. Pemesanan menu tidak tersedia.
                    </p>
                </template>

                <label v-if="queueStatus?.is_session_active" class="mt-4 block">
                    <span class="text-xs font-medium text-stone-600">Nama (opsional)</span>
                    <input
                        v-model="customerName"
                        type="text"
                        maxlength="100"
                        placeholder="Nama untuk dipanggil"
                        class="mt-1 min-h-11 w-full rounded-xl border border-stone-200 bg-stone-50 px-3 text-sm text-stone-900 outline-none placeholder:text-stone-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
                    />
                </label>

                <label v-if="queueStatus?.is_session_active" class="mt-3 block">
                    <span class="text-xs font-medium text-stone-600">Nomor WhatsApp (opsional)</span>
                    <input
                        v-model="customerPhone"
                        type="tel"
                        inputmode="tel"
                        maxlength="30"
                        placeholder="08123456789"
                        class="mt-1 min-h-11 w-full rounded-xl border border-stone-200 bg-stone-50 px-3 text-sm text-stone-900 outline-none placeholder:text-stone-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
                    />
                </label>

                <button
                    type="button"
                    class="mt-3 inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white transition hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-stone-300 disabled:text-stone-600"
                    :disabled="!queueStatus?.is_session_active || queueSubmitting"
                    @click="createQueue"
                >
                    {{ queueSubmitting
                        ? 'Mengambil antrean...'
                        : (queueStatus?.is_session_active ? 'Ambil Antrean' : 'Antrean belum dibuka') }}
                </button>

                <p
                    v-if="queueSubmitError"
                    class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-xs leading-5 text-rose-800"
                    role="alert"
                >
                    {{ queueSubmitError }}
                </p>

                <p class="mt-2 text-center text-xs leading-5 text-stone-500">
                    {{ store.ordering_mode === 'queue_and_order'
                        ? 'Pembayaran dilakukan langsung di toko.'
                        : 'Simpan nomor antrean Anda.' }}
                </p>
            </section>
        </template>
    </AppShell>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import api, { getErrorMessage, getQueueErrorMessage, isNotFound, isRequestCanceled } from '../api';
import AppShell from '../components/AppShell.vue';
import ErrorState from '../components/ErrorState.vue';
import LoadingState from '../components/LoadingState.vue';
import { getOrder, getQueueContext, saveQueueContext } from '../storage';

const route = useRoute();
const router = useRouter();
const token = computed(() => String(route.params.token ?? ''));

const loading = ref(true);
const notFound = ref(false);
const error = ref(null);
const queueError = ref(null);
const store = ref(null);
const queueStatus = ref(null);
const queueContext = ref(getQueueContext());
const customerName = ref('');
const customerPhone = ref('');
const queueSubmitting = ref(false);
const queueSubmitError = ref(null);
const queueKey = ref(null);

const existingQueue = computed(() => {
    const context = queueContext.value;

    return context && context.store_token === token.value && context.access_token
        ? context
        : null;
});

const existingOrder = computed(() => (
    existingQueue.value ? getOrder(existingQueue.value.access_token) : null
));

let pollTimer = null;
let storeRequestController = null;
let queueRequestController = null;
let requestInFlight = false;
let componentMounted = false;
let generationId = 0;

const safeMapsUrl = computed(() => {
    const url = store.value?.google_maps_url;

    if (typeof url !== 'string') {
        return null;
    }

    return /^https?:\/\//i.test(url) ? url : null;
});

const estimatedWaitLabel = computed(() => {
    if (!queueStatus.value?.is_session_active) {
        return '—';
    }

    return `${queueStatus.value.estimated_wait_minutes ?? 0} menit`;
});

function isCurrentRequest(storeToken, requestGeneration) {
    return componentMounted
        && requestGeneration === generationId
        && storeToken === token.value;
}

function abortStoreRequest() {
    storeRequestController?.abort();
    storeRequestController = null;
    requestInFlight = false;
}

function abortQueueRequest() {
    queueRequestController?.abort();
    queueRequestController = null;
    queueSubmitting.value = false;
}

function resetForToken(storeToken) {
    generationId += 1;
    abortStoreRequest();
    abortQueueRequest();
    stopPolling();

    store.value = null;
    queueStatus.value = null;
    loading.value = Boolean(storeToken);
    notFound.value = false;
    error.value = null;
    queueError.value = null;
    customerName.value = '';
    customerPhone.value = '';
    queueSubmitError.value = null;
    queueKey.value = null;
    queueContext.value = getQueueContext();

    if (storeToken) {
        load(storeToken, generationId);
    } else {
        loading.value = false;
    }
}

async function load(storeToken = token.value, requestGeneration = generationId) {
    if (!isCurrentRequest(storeToken, requestGeneration) || requestInFlight) {
        return;
    }

    loading.value = true;
    error.value = null;
    notFound.value = false;
    queueError.value = null;
    requestInFlight = true;
    const controller = new AbortController();
    storeRequestController = controller;

    try {
        const response = await api.get(
            `/public/stores/${encodeURIComponent(storeToken)}`,
            { signal: controller.signal },
        );

        if (!isCurrentRequest(storeToken, requestGeneration)) {
            return;
        }

        store.value = response.data.data;
        document.title = `${store.value.name} - TAPZY`;

        await fetchQueueStatus(storeToken, requestGeneration, controller);

        if (isCurrentRequest(storeToken, requestGeneration)) {
            loading.value = false;
            startPolling();
        }
    } catch (requestError) {
        if (!isCurrentRequest(storeToken, requestGeneration) || isRequestCanceled(requestError)) {
            return;
        }

        if (isNotFound(requestError)) {
            notFound.value = true;
        } else {
            error.value = getErrorMessage(requestError);
        }

        loading.value = false;
    } finally {
        if (storeRequestController === controller) {
            storeRequestController = null;
            requestInFlight = false;
        }
    }
}

async function fetchQueueStatus(storeToken, requestGeneration, controller) {
    queueError.value = null;

    try {
        const response = await api.get(
            `/public/stores/${encodeURIComponent(storeToken)}/queue-status`,
            { signal: controller.signal },
        );
        if (isCurrentRequest(storeToken, requestGeneration)) {
            queueStatus.value = response.data.data;
        }
    } catch (requestError) {
        if (isCurrentRequest(storeToken, requestGeneration) && !isRequestCanceled(requestError)) {
            queueError.value = getErrorMessage(requestError);
        }
    }
}

async function refreshQueueStatus() {
    if (!store.value || requestInFlight || !componentMounted) {
        return;
    }

    const storeToken = token.value;
    const requestGeneration = generationId;
    const controller = new AbortController();
    requestInFlight = true;
    storeRequestController = controller;

    try {
        await fetchQueueStatus(storeToken, requestGeneration, controller);
    } finally {
        if (storeRequestController === controller) {
            storeRequestController = null;
            requestInFlight = false;
        }
    }
}

async function createQueue() {
    if (queueSubmitting.value || !queueStatus.value?.is_session_active) {
        return;
    }

    queueSubmitting.value = true;
    queueSubmitError.value = null;
    const storeToken = token.value;
    const requestGeneration = generationId;
    const controller = new AbortController();
    queueRequestController = controller;

    try {
        if (!queueKey.value) {
            queueKey.value = generateIdempotencyKey();
        }

        if (!queueKey.value) {
            queueSubmitError.value = 'Perangkat tidak dapat membuat kunci permintaan yang aman. Silakan gunakan peramban terbaru.';
            return;
        }

        const payload = {
            source: 'nfc',
        };
        const name = customerName.value.trim();

        if (name !== '') {
            payload.customer_name = name;
        }
        const phone = customerPhone.value.trim();

        if (phone !== '') {
            payload.customer_phone = phone;
        }

        const response = await api.post(
            `/public/stores/${encodeURIComponent(storeToken)}/queues`,
            payload,
            {
                headers: {
                    'Idempotency-Key': queueKey.value,
                },
                signal: controller.signal,
            },
        );

        if (!isCurrentRequest(storeToken, requestGeneration)) {
            return;
        }

        const data = response.data?.data;

        if (!data?.access_token) {
            queueSubmitError.value = 'Antrean sudah diproses, tetapi tautan tidak dapat dipulihkan. Silakan hubungi petugas.';

            return;
        }

        const context = {
            access_token: data.access_token,
            store_token: storeToken,
            store_name: store.value?.name ?? 'Toko',
            ordering_mode: store.value?.ordering_mode ?? 'queue_and_order',
            queue_number: data.queue_number,
            formatted_number: data.formatted_number,
            customer_name: data.customer_name ?? null,
            customer_phone: data.customer_phone ?? null,
            created_at: data.created_at ?? new Date().toISOString(),
        };

        saveQueueContext(context);
        queueContext.value = context;
        queueKey.value = null;

        if (context.ordering_mode === 'queue_and_order') {
            await router.push({
                name: 'menu',
                params: { token: storeToken },
            });

            return;
        }

        await router.push({
            name: 'order-success',
            params: { accessToken: context.access_token },
        });
    } catch (requestError) {
        if (isCurrentRequest(storeToken, requestGeneration) && !isRequestCanceled(requestError)) {
            queueSubmitError.value = getQueueErrorMessage(requestError);
        }
    } finally {
        if (queueRequestController === controller) {
            queueRequestController = null;
            queueSubmitting.value = false;
        }
    }
}

function generateIdempotencyKey() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;

        return [...bytes].map((byte, index) => {
            const hex = byte.toString(16).padStart(2, '0');

            return [4, 6, 8, 10].includes(index) ? `-${hex}` : hex;
        }).join('');
    }

    return null;
}

watch([customerName, customerPhone], () => {
    queueKey.value = null;
});

function startPolling() {
    if (pollTimer !== null || !componentMounted) {
        return;
    }

    pollTimer = window.setInterval(() => {
        if (document.visibilityState === 'visible') {
            refreshQueueStatus();
        }
    }, 10000);
}

function stopPolling() {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

function handleVisibilityChange() {
    if (document.visibilityState === 'visible' && store.value && !notFound.value) {
        refreshQueueStatus();
    }
}

onMounted(() => {
    componentMounted = true;
    document.addEventListener('visibilitychange', handleVisibilityChange);
    resetForToken(token.value);
});

watch(
    () => String(route.params.token ?? ''),
    (storeToken, previousToken) => {
        if (!componentMounted || storeToken === previousToken) {
            return;
        }

        resetForToken(storeToken);
    },
);

onBeforeUnmount(() => {
    componentMounted = false;
    generationId += 1;
    stopPolling();
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    abortStoreRequest();
    abortQueueRequest();
});
</script>
