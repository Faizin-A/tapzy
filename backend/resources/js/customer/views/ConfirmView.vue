<template>
    <AppShell>
        <div class="flex items-center gap-3">
            <RouterLink
                :to="{ name: 'cart', params: { token: storeToken } }"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-stone-200 bg-white text-stone-600 shadow-sm"
                aria-label="Kembali ke keranjang"
            >
                <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                    <path
                        d="m15 18-6-6 6-6"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </RouterLink>

            <div class="min-w-0 flex-1">
                <h1 class="text-lg font-semibold text-stone-900">Konfirmasi Pesanan</h1>
                <p class="text-xs text-stone-500">{{ storeName }} · Antrean {{ queueLabel }}</p>
            </div>
        </div>

        <section
            v-if="isEmpty"
            class="mt-5 rounded-2xl border border-stone-200 bg-white p-6 text-center shadow-sm"
        >
            <h2 class="text-base font-semibold text-stone-900">Keranjang masih kosong</h2>
            <p class="mt-2 text-sm leading-6 text-stone-600">
                Pilih menu terlebih dahulu sebelum membuat pesanan.
            </p>
            <RouterLink
                :to="{ name: 'menu', params: { token: storeToken } }"
                class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white"
            >
                Lihat menu
            </RouterLink>
        </section>

        <template v-else>
            <section class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold text-stone-900">Toko dan antrean</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-stone-500">Toko</dt>
                        <dd class="text-right font-medium text-stone-900">{{ storeName }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-stone-500">Nomor antrean</dt>
                        <dd class="font-semibold text-emerald-700">{{ queueLabel }}</dd>
                    </div>
                    <div v-if="customerName" class="flex justify-between gap-3">
                        <dt class="text-stone-500">Nama</dt>
                        <dd class="text-right font-medium text-stone-900">{{ customerName }}</dd>
                    </div>
                    <div v-if="customerPhone" class="flex justify-between gap-3">
                        <dt class="text-stone-500">Telepon</dt>
                        <dd class="text-right font-medium text-stone-900">{{ customerPhone }}</dd>
                    </div>
                </dl>
            </section>

            <section class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold text-stone-900">Rincian pesanan</h2>
                <ul class="mt-3 divide-y divide-stone-100">
                    <li v-for="item in items" :key="item.product_id" class="py-3 first:pt-0 last:pb-0">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-stone-900">
                                    {{ item.name }}
                                </p>
                                <p class="mt-0.5 text-xs text-stone-500">
                                    {{ item.quantity }} × {{ formatRupiah(item.price) }}
                                </p>
                                <p v-if="item.notes" class="mt-1 text-xs leading-5 text-amber-700">
                                    Catatan: {{ item.notes }}
                                </p>
                            </div>
                            <span class="shrink-0 text-sm font-semibold text-stone-900">
                                {{ formatCents(toCents(item.price) * item.quantity) }}
                            </span>
                        </div>
                    </li>
                </ul>

                <div v-if="orderNotes" class="mt-3 rounded-xl bg-stone-50 px-3 py-2">
                    <p class="text-xs font-medium text-stone-500">Catatan pesanan</p>
                    <p class="mt-1 whitespace-pre-line text-sm leading-6 text-stone-800">
                        {{ orderNotes }}
                    </p>
                </div>
            </section>

            <section class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="text-stone-600">Jumlah item</span>
                    <span class="font-semibold text-stone-900">{{ itemCount }}</span>
                </div>
                <div class="mt-3 flex items-center justify-between gap-3 border-t border-stone-200 pt-3">
                    <span class="text-sm font-semibold text-stone-900">Total perkiraan</span>
                    <span class="text-lg font-bold text-emerald-700">{{ subtotalLabel }}</span>
                </div>
                <p class="mt-2 text-xs leading-5 text-stone-500">
                    Total akhir dihitung oleh sistem toko. Pembayaran dilakukan langsung di toko.
                </p>
            </section>

            <p
                v-if="submitError"
                class="mt-4 rounded-xl bg-rose-50 px-3 py-2 text-sm leading-6 text-rose-800"
                role="alert"
            >
                {{ submitError }}
                <RouterLink
                    v-if="errorCode === 'ORDER_ALREADY_EXISTS'"
                    :to="{ name: 'order-success', params: { accessToken } }"
                    class="font-semibold underline underline-offset-2"
                >
                    Lihat status
                </RouterLink>
                <RouterLink
                    v-else-if="errorCode === 'PRODUCT_NOT_AVAILABLE'"
                    :to="{ name: 'menu', params: { token: storeToken } }"
                    class="font-semibold underline underline-offset-2"
                >
                    Perbarui menu
                </RouterLink>
                <RouterLink
                    v-else
                    :to="{ name: 'store', params: { token: storeToken } }"
                    class="font-semibold underline underline-offset-2"
                >
                    Kembali ke toko
                </RouterLink>
            </p>

            <button
                type="button"
                class="mt-4 inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white transition hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-stone-300 disabled:text-stone-600"
                :disabled="isSubmitting || isEmpty"
                @click="submitOrder"
            >
                {{ isSubmitting ? 'Mengirim pesanan...' : 'PESAN & ANTRI' }}
            </button>
        </template>
    </AppShell>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import api, { getOrderErrorMessage } from '../api';
import AppShell from '../components/AppShell.vue';
import { useCart } from '../composables/useCart';
import { formatCents, formatRupiah, toCents } from '../format';
import { getQueueContext, saveOrder } from '../storage';

const route = useRoute();
const router = useRouter();
const storeToken = computed(() => String(route.params.token ?? ''));
const queueContext = getQueueContext();
const accessToken = computed(() => String(queueContext?.access_token ?? ''));

const {
    items,
    orderNotes,
    itemCount,
    subtotalLabel,
    isEmpty,
    toPayload,
    clear,
} = useCart(accessToken);

const storeName = computed(() => queueContext?.store_name ?? 'Toko');
const queueLabel = computed(() => queueContext?.formatted_number ?? '—');
const customerName = computed(() => queueContext?.customer_name ?? null);
const customerPhone = computed(() => queueContext?.customer_phone ?? null);

const isSubmitting = ref(false);
const submitError = ref(null);
const errorCode = ref(null);

async function submitOrder() {
    if (isSubmitting.value || isEmpty.value || !accessToken.value) {
        return;
    }

    isSubmitting.value = true;
    submitError.value = null;
    errorCode.value = null;

    try {
        const response = await api.post(
            `/public/queues/${encodeURIComponent(accessToken.value)}/orders`,
            toPayload(),
        );

        const order = response.data?.data;

        if (!order) {
            submitError.value = 'Pesanan berhasil dikirim, tetapi ringkasan pesanan tidak tersedia. Silakan hubungi petugas.';

            return;
        }

        saveOrder(accessToken.value, {
            order,
            store_token: storeToken.value,
            store_name: storeName.value,
            queue_number: queueLabel.value,
            saved_at: new Date().toISOString(),
        });
        clear();
        await router.replace({
            name: 'order-success',
            params: { accessToken: accessToken.value },
        });
    } catch (requestError) {
        errorCode.value = requestError?.response?.data?.error_code ?? null;
        submitError.value = getOrderErrorMessage(requestError);
    } finally {
        isSubmitting.value = false;
    }
}

onMounted(() => {
    document.title = `Konfirmasi Pesanan ${storeName.value} - TAPZY`;
});
</script>
