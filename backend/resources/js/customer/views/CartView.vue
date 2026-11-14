<template>
    <AppShell>
        <div class="flex items-center gap-3">
            <RouterLink
                :to="{ name: 'menu', params: { token: storeToken } }"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-stone-200 bg-white text-stone-600 shadow-sm"
                aria-label="Kembali ke menu"
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
                <h1 class="text-lg font-semibold text-stone-900">Keranjang</h1>
                <p class="text-xs text-stone-500">{{ storeName }} · Antrean {{ queueLabel }}</p>
            </div>
        </div>

        <section
            v-if="isEmpty"
            class="mt-5 rounded-2xl border border-stone-200 bg-white p-6 text-center shadow-sm"
        >
            <h2 class="text-base font-semibold text-stone-900">Keranjang masih kosong</h2>
            <p class="mt-2 text-sm leading-6 text-stone-600">
                Pilih menu terlebih dahulu sebelum melanjutkan ke konfirmasi.
            </p>
            <RouterLink
                :to="{ name: 'menu', params: { token: storeToken } }"
                class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white"
            >
                Lihat menu
            </RouterLink>
        </section>

        <template v-else>
            <p
                v-if="cartMessage"
                class="mt-4 rounded-xl bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800"
                role="status"
            >
                {{ cartMessage }}
            </p>

            <div class="mt-4 space-y-3">
                <article
                    v-for="item in items"
                    :key="item.product_id"
                    class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm"
                >
                    <div class="flex gap-3">
                        <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-stone-100 text-lg font-semibold text-stone-400">
                            <img
                                v-if="imageUrl(item.image_url)"
                                :src="imageUrl(item.image_url)"
                                :alt="item.name"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            />
                            <span v-else>{{ initial(item.name) }}</span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <h2 class="min-w-0 text-sm font-semibold leading-5 text-stone-900">
                                    {{ item.name }}
                                </h2>
                                <button
                                    type="button"
                                    class="shrink-0 text-xs font-semibold text-rose-700 underline-offset-2 hover:underline"
                                    @click="removeItem(item.product_id)"
                                >
                                    Hapus
                                </button>
                            </div>

                            <p class="mt-1 text-sm font-semibold text-emerald-700">
                                {{ formatRupiah(item.price) }}
                            </p>

                            <div class="mt-3 flex items-center justify-between gap-3">
                                <QuantityStepper
                                    :quantity="item.quantity"
                                    @decrease="decrement(item.product_id)"
                                    @increase="increment(item.product_id)"
                                />
                                <span class="text-sm font-semibold text-stone-900">
                                    {{ formatCents(toCents(item.price) * item.quantity) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <label class="mt-3 block">
                        <span class="text-xs font-medium text-stone-600">Catatan item (opsional)</span>
                        <input
                            :value="item.notes"
                            type="text"
                            maxlength="255"
                            placeholder="Contoh: tanpa gula"
                            class="mt-1 min-h-10 w-full rounded-xl border border-stone-200 bg-stone-50 px-3 text-sm text-stone-900 outline-none placeholder:text-stone-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
                            @input="setItemNotes(item.product_id, $event.target.value)"
                        />
                    </label>
                </article>
            </div>

            <section class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                <label class="block">
                    <span class="text-sm font-semibold text-stone-900">Catatan pesanan</span>
                    <span class="mt-0.5 block text-xs text-stone-500">
                        Opsional, maksimal 1000 karakter.
                    </span>
                    <textarea
                        :value="orderNotes"
                        rows="3"
                        maxlength="1000"
                        placeholder="Contoh: pesanan dibungkus terpisah"
                        class="mt-2 w-full resize-y rounded-xl border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-900 outline-none placeholder:text-stone-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
                        @input="setOrderNotes($event.target.value)"
                    ></textarea>
                </label>
            </section>

            <section class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="text-stone-600">Jumlah item</span>
                    <span class="font-semibold text-stone-900">{{ itemCount }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between gap-3 text-sm">
                    <span class="text-stone-600">Subtotal</span>
                    <span class="font-semibold text-stone-900">{{ subtotalLabel }}</span>
                </div>
                <div class="mt-3 flex items-center justify-between gap-3 border-t border-stone-200 pt-3">
                    <span class="text-sm font-semibold text-stone-900">Total perkiraan</span>
                    <span class="text-lg font-bold text-emerald-700">{{ subtotalLabel }}</span>
                </div>
                <p class="mt-2 text-xs leading-5 text-stone-500">
                    Total akhir dihitung oleh sistem toko saat pesanan dibuat.
                </p>
            </section>

            <RouterLink
                :to="{ name: 'confirm', params: { token: storeToken } }"
                class="mt-4 inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white transition hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
            >
                Lanjut ke Konfirmasi
            </RouterLink>
        </template>
    </AppShell>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import AppShell from '../components/AppShell.vue';
import QuantityStepper from '../components/QuantityStepper.vue';
import { useCart } from '../composables/useCart';
import { formatCents, formatRupiah, safeHttpUrl, toCents } from '../format';
import { getQueueContext } from '../storage';

const route = useRoute();
const storeToken = computed(() => String(route.params.token ?? ''));
const queueContext = getQueueContext();
const accessToken = computed(() => String(queueContext?.access_token ?? ''));

const {
    items,
    orderNotes,
    itemCount,
    subtotalLabel,
    isEmpty,
    message: cartMessage,
    increment,
    decrement,
    removeItem,
    setItemNotes,
    setOrderNotes,
} = useCart(accessToken);

const storeName = computed(() => queueContext?.store_name ?? 'Toko');
const queueLabel = computed(() => queueContext?.formatted_number ?? '—');

function imageUrl(value) {
    return safeHttpUrl(value);
}

function initial(name) {
    return String(name ?? '?').trim().charAt(0).toUpperCase() || '?';
}

onMounted(() => {
    document.title = `Keranjang ${storeName.value} - TAPZY`;
});
</script>
