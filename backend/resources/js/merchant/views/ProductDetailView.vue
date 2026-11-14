<template>
    <section class="max-w-3xl"><div class="mb-6"><RouterLink to="/products" class="text-sm underline">← Kembali ke produk</RouterLink></div><div v-if="loading" class="rounded-xl bg-white p-6 shadow-sm">Memuat produk...</div><div v-else-if="error" class="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700">{{ error }}</div><div v-else-if="product" class="rounded-xl bg-white p-6 shadow-sm"><div class="flex items-start justify-between gap-4"><div><h1 class="text-2xl font-bold">{{ product.name }}</h1><p class="mt-2 text-stone-600">{{ product.description || 'Tidak ada deskripsi.' }}</p></div><RouterLink :to="`/products/${product.id}/edit`" class="rounded-lg border border-stone-300 px-3 py-2 text-sm">Edit</RouterLink></div><dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2"><div><dt class="text-stone-500">Harga</dt><dd>Rp {{ product.price }}</dd></div><div><dt class="text-stone-500">Kategori</dt><dd>{{ product.category || '-' }}</dd></div><div><dt class="text-stone-500">Status</dt><dd>{{ product.is_active ? 'Aktif' : 'Nonaktif' }}</dd></div><div><dt class="text-stone-500">Ketersediaan</dt><dd>{{ product.is_available ? 'Tersedia' : 'Habis' }}</dd></div></dl><button class="mt-6 rounded-lg border border-stone-300 px-3 py-2 text-sm" @click="toggleAvailability">{{ product.is_available ? 'Tandai habis' : 'Tandai tersedia' }}</button></div></section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { errorMessage, getProduct, updateProductStatus } from '../api';
const route = useRoute(); const product = ref(null); const loading = ref(true); const error = ref('');
async function load() { try { product.value = await getProduct(route.params.id); } catch (exception) { error.value = errorMessage(exception); } finally { loading.value = false; } }
async function toggleAvailability() { try { product.value = await updateProductStatus(product.value.id, { is_available: !product.value.is_available }); } catch (exception) { error.value = errorMessage(exception); } }
onMounted(load);
</script>
