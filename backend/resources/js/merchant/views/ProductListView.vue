<template>
    <section>
        <div class="mb-6 flex items-end justify-between gap-4"><div><p class="text-sm text-stone-500">Katalog merchant</p><h1 class="text-2xl font-bold">Produk</h1></div><RouterLink to="/products/new" class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white">Tambah produk</RouterLink></div>
        <div class="mb-4 flex gap-2"><input v-model="search" @keyup.enter="load" placeholder="Cari nama produk" class="w-full rounded-lg border border-stone-300 p-2"><button class="rounded-lg border border-stone-300 px-4" @click="load">Cari</button></div>
        <div v-if="loading" class="rounded-xl bg-white p-6 shadow-sm">Memuat produk...</div><div v-else-if="error" class="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700">{{ error }}</div><div v-else-if="!products.length" class="rounded-xl bg-white p-6 text-stone-500 shadow-sm">Belum ada produk.</div>
        <div v-else class="overflow-hidden rounded-xl bg-white shadow-sm"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="border-b border-stone-200 text-stone-500"><tr><th class="p-4">Nama</th><th class="p-4">Harga</th><th class="p-4">Status</th><th class="p-4">Aksi</th></tr></thead><tbody><tr v-for="product in products" :key="product.id" class="border-b border-stone-100"><td class="p-4 font-medium">{{ product.name }}</td><td class="p-4">Rp {{ product.price }}</td><td class="p-4">{{ product.is_active ? 'Aktif' : 'Nonaktif' }} · {{ product.is_available ? 'Tersedia' : 'Habis' }}</td><td class="flex gap-3 p-4"><RouterLink :to="`/products/${product.id}`" class="underline">Detail</RouterLink><RouterLink :to="`/products/${product.id}/edit`" class="underline">Edit</RouterLink><button class="text-red-700 underline" @click="remove(product)">Hapus</button></td></tr></tbody></table></div></div>
        <div v-if="meta?.last_page > 1" class="mt-4 flex items-center justify-between text-sm"><button :disabled="!links?.prev" class="underline disabled:opacity-40" @click="page--; load()">Sebelumnya</button><span>Halaman {{ meta.current_page }} / {{ meta.last_page }}</span><button :disabled="!links?.next" class="underline disabled:opacity-40" @click="page++; load()">Berikutnya</button></div>
    </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { deleteProduct, errorMessage, listProducts } from '../api';
const products = ref([]); const meta = ref(null); const links = ref(null); const search = ref(''); const page = ref(1); const loading = ref(true); const error = ref('');
async function load() { loading.value = true; error.value = ''; try { const response = await listProducts({ search: search.value || undefined, page: page.value }); products.value = response.data; meta.value = response.meta; links.value = response.links; } catch (exception) { error.value = errorMessage(exception); } finally { loading.value = false; } }
async function remove(product) { if (!window.confirm(`Hapus produk ${product.name}?`)) return; try { await deleteProduct(product.id); await load(); } catch (exception) { error.value = errorMessage(exception); } }
onMounted(load);
</script>
