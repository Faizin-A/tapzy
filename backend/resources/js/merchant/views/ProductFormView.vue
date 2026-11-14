<template>
    <section class="max-w-3xl"><div class="mb-6"><RouterLink to="/products" class="text-sm underline">← Kembali ke produk</RouterLink><h1 class="mt-2 text-2xl font-bold">{{ editing ? 'Edit produk' : 'Tambah produk' }}</h1></div>
        <div v-if="loading" class="rounded-xl bg-white p-6 shadow-sm">Memuat...</div><form v-else class="space-y-5 rounded-xl bg-white p-6 shadow-sm" @submit.prevent="save"><div v-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</div>
            <label class="block text-sm font-medium">Nama<input v-model="form.name" required maxlength="150" class="mt-1 w-full rounded-lg border border-stone-300 p-2"><FieldError :errors="errors.name" /></label><label class="block text-sm font-medium">Deskripsi<textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border border-stone-300 p-2" /><FieldError :errors="errors.description" /></label>
            <div class="grid gap-4 sm:grid-cols-2"><label class="block text-sm font-medium">Harga<input v-model="form.price" required min="0" step="0.01" type="number" class="mt-1 w-full rounded-lg border border-stone-300 p-2"><FieldError :errors="errors.price" /></label><label class="block text-sm font-medium">Kategori<input v-model="form.category" maxlength="100" class="mt-1 w-full rounded-lg border border-stone-300 p-2"><FieldError :errors="errors.category" /></label></div>
            <label class="block text-sm font-medium">URL gambar<input v-model="form.image_url" type="url" class="mt-1 w-full rounded-lg border border-stone-300 p-2"><FieldError :errors="errors.image_url" /></label><label class="flex items-center gap-2 text-sm"><input v-model="form.is_available" type="checkbox"> Tersedia</label><label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox"> Aktif</label>
            <button :disabled="saving" class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
        </form>
    </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { createProduct, errorMessage, getProduct, updateProduct } from '../api';
import FieldError from '../components/FieldError.vue';
const route = useRoute(); const router = useRouter(); const editing = Boolean(route.params.id); const loading = ref(editing); const saving = ref(false); const error = ref(''); const errors = ref({});
const form = reactive({ name: '', description: '', price: '0.00', category: '', image_url: '', is_available: true, is_active: true });
async function load() { if (!editing) return; try { Object.assign(form, await getProduct(route.params.id)); } catch (exception) { error.value = errorMessage(exception); } finally { loading.value = false; } }
async function save() { saving.value = true; error.value = ''; errors.value = {}; try { const product = editing ? await updateProduct(route.params.id, { ...form }) : await createProduct({ ...form }); await router.push(`/products/${product.id}`); } catch (exception) { errors.value = exception?.response?.data?.errors ?? {}; error.value = errorMessage(exception); } finally { saving.value = false; } }
onMounted(load);
</script>
