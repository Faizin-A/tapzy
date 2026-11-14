<template>
    <section>
        <button class="mb-5 text-sm text-stone-600 underline" @click="router.back()">Kembali ke antrean</button>
        <div v-if="loading" class="rounded-xl bg-white p-6 shadow-sm">Memuat detail antrean...</div>
        <div v-else-if="error" class="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700">{{ error }}</div>
        <div v-else-if="queue" class="space-y-5">
            <div class="rounded-xl bg-white p-6 shadow-sm"><p class="text-sm text-stone-500">Nomor antrean</p><h1 class="text-3xl font-bold">{{ queue.formatted_number }}</h1><p class="mt-2 text-stone-600">{{ queue.customer_name || 'Pelanggan tanpa nama' }} · {{ queue.customer_phone || 'Nomor telepon tidak tersedia' }}</p><p class="mt-1 text-sm text-stone-500">{{ label(queue.status) }} · {{ formatDate(queue.created_at) }}</p></div>
            <div class="rounded-xl bg-white p-6 shadow-sm"><h2 class="font-semibold">Ringkasan order</h2><p v-if="!queue.order" class="mt-3 text-sm text-stone-500">Tidak ada order terkait.</p><div v-else class="mt-3 grid gap-2 text-sm sm:grid-cols-2"><span>Kode: <strong>{{ queue.order.order_code }}</strong></span><span>Status: <strong>{{ queue.order.status }}</strong></span><span>Total item: <strong>{{ queue.order.total_items }}</strong></span><span>Total: <strong>Rp {{ queue.order.total_price }}</strong></span></div></div>
            <div class="flex flex-wrap gap-3"><button v-if="queue.status === 'waiting' || queue.status === 'skipped'" class="rounded-lg border border-stone-300 px-4 py-2" @click="run(() => callQueue(queue.id))">Panggil</button><button v-if="queue.status === 'called'" class="rounded-lg border border-stone-300 px-4 py-2" @click="run(() => startQueue(queue.id))">Mulai layani</button><button v-if="queue.status === 'called'" class="rounded-lg border border-stone-300 px-4 py-2" @click="run(() => skipQueue(queue.id))">Lewati</button><button v-if="['waiting', 'called'].includes(queue.status)" class="rounded-lg border border-red-200 px-4 py-2 text-red-700" @click="cancel">Batalkan</button><button v-if="queue.status === 'in_service'" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white" @click="complete">Selesaikan</button></div>
        </div>
    </section>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { cancelQueue, callQueue, completeQueue, errorMessage, getQueue, skipQueue, startQueue } from '../api';

const route = useRoute();
const router = useRouter();
const queue = ref(null);
const loading = ref(true);
const error = ref('');
let poller;
function label(status) { return ({ waiting: 'Menunggu', called: 'Dipanggil', in_service: 'Sedang dilayani', skipped: 'Dilewati', completed: 'Selesai', cancelled: 'Dibatalkan' })[status] ?? status; }
function formatDate(value) { return value ? new Date(value).toLocaleString('id-ID') : '-'; }
async function load() { try { queue.value = await getQueue(route.params.id); error.value = ''; } catch (exception) { error.value = errorMessage(exception); } finally { loading.value = false; } }
async function run(action) { try { await action(); await load(); } catch (exception) { error.value = errorMessage(exception); } }
function cancel() { if (window.confirm(`Batalkan ${queue.value.formatted_number}?`)) return run(() => cancelQueue(queue.value.id)); }
function complete() { if (window.confirm(`Selesaikan ${queue.value.formatted_number}?`)) return run(() => completeQueue(queue.value.id)); }
onMounted(() => { load(); poller = window.setInterval(load, 5000); });
onUnmounted(() => window.clearInterval(poller));
</script>
