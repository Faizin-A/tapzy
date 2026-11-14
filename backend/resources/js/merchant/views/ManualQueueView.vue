<template>
    <section class="mx-auto max-w-2xl">
        <div class="mb-6"><RouterLink to="/queues" class="text-sm text-stone-500 underline">← Kembali ke antrean</RouterLink><h1 class="mt-2 text-2xl font-bold">Tambah antrean manual</h1></div>
        <form class="space-y-4 rounded-xl bg-white p-6 shadow-sm" @submit.prevent="submit">
            <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
            <label class="block text-sm font-medium">Nama pelanggan<input v-model="form.customer_name" required maxlength="100" class="mt-1 w-full rounded-lg border border-stone-300 p-2"><FieldError :errors="errors.customer_name" /></label>
            <label class="block text-sm font-medium">WhatsApp (opsional)<input v-model="form.customer_phone" maxlength="30" class="mt-1 w-full rounded-lg border border-stone-300 p-2"><FieldError :errors="errors.customer_phone" /></label>
            <label class="block text-sm font-medium">Catatan antrean<textarea v-model="form.queue_notes" maxlength="1000" rows="3" class="mt-1 w-full rounded-lg border border-stone-300 p-2" /></label>
            <template v-if="orderingEnabled">
                <p class="border-t border-stone-200 pt-4 text-sm font-semibold">Order opsional</p>
                <label class="block text-sm font-medium">Catatan order<textarea v-model="form.order_notes" maxlength="1000" rows="3" class="mt-1 w-full rounded-lg border border-stone-300 p-2" /></label>
                <div v-if="products.length" class="space-y-2"><div v-for="product in products" :key="product.id" class="flex items-center justify-between gap-3"><span class="text-sm">{{ product.name }} — Rp {{ product.price }}</span><input v-model.number="quantities[product.id]" type="number" min="0" max="99" class="w-20 rounded-lg border border-stone-300 p-2"></div></div>
            </template>
            <div class="flex justify-end"><button :disabled="saving" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white disabled:opacity-50">{{ saving ? 'Menyimpan...' : 'Simpan antrean' }}</button></div>
        </form>
    </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { createManualQueue, errorMessage, listProducts, loadProfile } from '../api';
import FieldError from '../components/FieldError.vue';

const router = useRouter();
const form = reactive({ customer_name: '', customer_phone: '', queue_notes: '', order_notes: '' });
const products = ref([]); const quantities = reactive({}); const errors = ref({}); const error = ref(''); const saving = ref(false); const orderingEnabled = ref(false); const submissionKey = ref(uuid());
onMounted(async () => { try { const profile = await loadProfile(); orderingEnabled.value = profile.ordering_mode === 'queue_and_order'; if (orderingEnabled.value) { const response = await listProducts({ per_page: 100 }); products.value = response.data ?? []; } } catch (exception) { error.value = errorMessage(exception); } });
function uuid() { return crypto.randomUUID ? crypto.randomUUID() : ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)); }
async function submit() {
    saving.value = true; errors.value = {}; error.value = ''; const items = Object.entries(quantities).filter(([, quantity]) => Number(quantity) > 0).map(([product_id, quantity]) => ({ product_id: Number(product_id), quantity: Number(quantity) }));
    try { const queue = await createManualQueue({ ...form, items: orderingEnabled.value && items.length ? items : undefined }, submissionKey.value); await router.push(`/queues/${queue.id}`); } catch (exception) { errors.value = exception?.response?.data?.errors ?? {}; error.value = errorMessage(exception); } finally { saving.value = false; }
}
</script>
