<template>
    <AppShell>
        <div class="flex items-center gap-3">
            <RouterLink
                :to="{ name: 'store', params: { token: storeToken } }"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-stone-200 bg-white text-stone-600 shadow-sm"
                aria-label="Kembali ke halaman toko"
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
                <h1 class="truncate text-lg font-semibold text-stone-900">{{ storeName }}</h1>
                <p class="text-xs text-stone-500">Antrean {{ queueLabel }}</p>
            </div>

            <RouterLink
                :to="{ name: 'cart', params: { token: storeToken } }"
                class="relative inline-flex min-h-10 items-center justify-center rounded-xl border border-stone-200 bg-white px-3 text-xs font-semibold text-stone-700 shadow-sm"
            >
                Keranjang
                <span
                    v-if="itemCount > 0"
                    class="absolute -right-2 -top-2 flex h-6 min-w-6 items-center justify-center rounded-full bg-emerald-700 px-1.5 text-[11px] font-bold text-white"
                >
                    {{ itemCount }}
                </span>
            </RouterLink>
        </div>

        <LoadingState v-if="loading" class="mt-5" />

        <ErrorState
            v-else-if="error"
            class="mt-5"
            :message="error"
            @retry="load"
        />

        <section
            v-else-if="orderingDisabled"
            class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-center"
        >
            <h2 class="text-base font-semibold text-amber-900">Pemesanan menu tidak tersedia</h2>
            <p class="mt-2 text-sm leading-6 text-amber-800">
                Toko ini hanya menggunakan antrean. Silakan kembali ke halaman toko.
            </p>
            <RouterLink
                :to="{ name: 'store', params: { token: storeToken } }"
                class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-amber-800 px-4 text-sm font-semibold text-white"
            >
                Kembali ke toko
            </RouterLink>
        </section>

        <section
            v-else-if="notFound"
            class="mt-5 rounded-2xl border border-stone-200 bg-white p-6 text-center shadow-sm"
        >
            <h2 class="text-base font-semibold text-stone-900">Menu tidak ditemukan</h2>
            <p class="mt-2 text-sm leading-6 text-stone-600">
                Toko ini tidak dapat diakses atau tautan sudah tidak berlaku.
            </p>
        </section>

        <template v-else>
            <div class="mt-5">
                <label class="sr-only" for="menu-search">Cari menu</label>
                <input
                    id="menu-search"
                    v-model="search"
                    type="search"
                    inputmode="search"
                    placeholder="Cari menu..."
                    class="min-h-11 w-full rounded-xl border border-stone-200 bg-white px-4 text-sm text-stone-900 shadow-sm outline-none placeholder:text-stone-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
                />
            </div>

            <div v-if="categories.length" class="mt-3 flex gap-2 overflow-x-auto pb-1">
                <button
                    type="button"
                    class="shrink-0 rounded-full px-3 py-2 text-xs font-semibold transition"
                    :class="selectedCategory === null
                        ? 'bg-emerald-700 text-white'
                        : 'border border-stone-200 bg-white text-stone-600'"
                    @click="selectedCategory = null"
                >
                    Semua
                </button>
                <button
                    v-for="category in categories"
                    :key="category"
                    type="button"
                    class="shrink-0 rounded-full px-3 py-2 text-xs font-semibold transition"
                    :class="selectedCategory === category
                        ? 'bg-emerald-700 text-white'
                        : 'border border-stone-200 bg-white text-stone-600'"
                    @click="selectedCategory = category"
                >
                    {{ category }}
                </button>
            </div>

            <p
                v-if="cartMessage"
                class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800"
                role="status"
            >
                {{ cartMessage }}
            </p>

            <div v-if="filteredProducts.length" class="mt-4 space-y-3">
                <ProductCard
                    v-for="product in filteredProducts"
                    :key="product.id"
                    :product="product"
                    :quantity="quantityFor(product.id)"
                    @add="addItem(product)"
                    @increase="increment(product.id)"
                    @decrease="decrement(product.id)"
                />
            </div>

            <section
                v-else
                class="mt-5 rounded-2xl border border-stone-200 bg-white p-6 text-center shadow-sm"
            >
                <h2 class="text-base font-semibold text-stone-900">Menu tidak ditemukan</h2>
                <p class="mt-2 text-sm leading-6 text-stone-600">
                    Coba kata kunci lain atau pilih kategori yang berbeda.
                </p>
            </section>

            <div
                v-if="itemCount > 0"
                class="sticky bottom-4 mt-5 flex items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-white p-3 shadow-lg"
            >
                <div class="min-w-0">
                    <p class="text-xs font-medium text-stone-500">{{ itemCount }} item dipilih</p>
                    <p class="text-base font-semibold text-stone-900">{{ subtotalLabel }}</p>
                </div>
                <RouterLink
                    :to="{ name: 'cart', params: { token: storeToken } }"
                    class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white transition hover:bg-emerald-800"
                >
                    Lihat Keranjang
                </RouterLink>
            </div>
        </template>
    </AppShell>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import api, { getErrorMessage, isNotFound, isOrderingDisabled } from '../api';
import AppShell from '../components/AppShell.vue';
import ErrorState from '../components/ErrorState.vue';
import LoadingState from '../components/LoadingState.vue';
import ProductCard from '../components/ProductCard.vue';
import { useCart } from '../composables/useCart';
import { getQueueContext } from '../storage';

const route = useRoute();
const storeToken = computed(() => String(route.params.token ?? ''));
const queueContext = getQueueContext();
const accessToken = computed(() => String(queueContext?.access_token ?? ''));

const {
    itemCount,
    subtotalLabel,
    message: cartMessage,
    quantityFor,
    addItem,
    increment,
    decrement,
} = useCart(accessToken);

const storeName = computed(() => queueContext?.store_name ?? 'Toko');
const queueLabel = computed(() => queueContext?.formatted_number ?? '—');

const loading = ref(true);
const notFound = ref(false);
const orderingDisabled = ref(false);
const error = ref(null);
const products = ref([]);
const categories = ref([]);
const search = ref('');
const selectedCategory = ref(null);

const filteredProducts = computed(() => {
    const keyword = search.value.trim().toLowerCase();

    return products.value.filter((product) => {
        if (selectedCategory.value !== null && product.category !== selectedCategory.value) {
            return false;
        }

        if (keyword === '') {
            return true;
        }

        const haystack = [product.name, product.description, product.category]
            .filter((value) => typeof value === 'string')
            .join(' ')
            .toLowerCase();

        return haystack.includes(keyword);
    });
});

async function load() {
    loading.value = true;
    error.value = null;
    notFound.value = false;
    orderingDisabled.value = false;

    try {
        const response = await api.get(
            `/public/stores/${encodeURIComponent(storeToken.value)}/products`,
        );

        products.value = Array.isArray(response.data?.data?.products)
            ? response.data.data.products
            : [];
        categories.value = Array.isArray(response.data?.data?.categories)
            ? response.data.data.categories
            : [];
        document.title = `Menu ${storeName.value} - TAPZY`;
    } catch (requestError) {
        if (isNotFound(requestError)) {
            notFound.value = true;
        } else if (isOrderingDisabled(requestError)) {
            orderingDisabled.value = true;
        } else {
            error.value = getErrorMessage(requestError);
        }
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>
