<template>
    <section>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div><p class="text-sm text-stone-500">Operasional toko</p><h1 class="text-2xl font-bold">Antrean</h1></div>
            <div class="flex gap-2"><RouterLink to="/queues/manual" class="rounded-lg border border-stone-300 px-4 py-2 text-sm font-semibold">Tambah antrean</RouterLink><button :disabled="actionLoading" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="callNext">{{ actionLoading ? 'Memproses...' : 'Panggil antrean berikutnya' }}</button></div>
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            <button v-for="tab in tabs" :key="tab.value" class="rounded-lg px-3 py-2 text-sm" :class="activeTab === tab.value ? 'bg-stone-900 text-white' : 'bg-white text-stone-700 ring-1 ring-stone-200'" @click="selectTab(tab.value)">{{ tab.label }}</button>
        </div>

        <div v-if="loading" class="rounded-xl bg-white p-6 shadow-sm">Memuat antrean...</div>
        <div v-else-if="error" class="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700">{{ error }} <button class="ml-2 underline" @click="load">Coba lagi</button></div>
        <div v-else-if="!queues.length" class="rounded-xl bg-white p-6 text-stone-500 shadow-sm">Belum ada antrean pada tab ini.</div>
        <div v-else class="overflow-hidden rounded-xl bg-white shadow-sm">
            <div v-for="queue in queues" :key="queue.id" class="border-b border-stone-100 p-4 last:border-0">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <RouterLink :to="`/queues/${queue.id}`" class="text-lg font-semibold underline">{{ queue.formatted_number }}</RouterLink>
                        <p class="text-sm text-stone-600">{{ queue.customer_name || 'Pelanggan tanpa nama' }}</p>
                        <p class="mt-1 text-xs text-stone-500">{{ formatDate(queue.created_at) }} · {{ label(queue.status) }}<span v-if="queue.has_order"> · memiliki order</span></p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-sm">
                        <button v-if="queue.status === 'waiting' || queue.status === 'skipped'" :disabled="actionLoading" class="rounded-lg border border-stone-300 px-3 py-2 disabled:opacity-50" @click="call(queue)">{{ queue.status === 'skipped' ? 'Panggil kembali' : 'Panggil' }}</button>
                        <button v-if="queue.status === 'called'" :disabled="actionLoading" class="rounded-lg border border-stone-300 px-3 py-2 disabled:opacity-50" @click="start(queue)">Mulai layani</button>
                        <button v-if="queue.status === 'called'" :disabled="actionLoading" class="rounded-lg border border-stone-300 px-3 py-2 disabled:opacity-50" @click="skip(queue)">Lewati</button>
                        <button v-if="['waiting', 'called'].includes(queue.status)" :disabled="actionLoading" class="rounded-lg border border-red-200 px-3 py-2 text-red-700 disabled:opacity-50" @click="cancel(queue)">Batalkan</button>
                        <button v-if="queue.status === 'in_service'" :disabled="actionLoading" class="rounded-lg bg-emerald-700 px-3 py-2 font-semibold text-white disabled:opacity-50" @click="complete(queue)">Selesaikan</button>
                    </div>
                </div>
            </div>
        </div>
        <p v-if="meta?.total" class="mt-4 text-sm text-stone-500">{{ meta.total }} antrean</p>
    </section>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { cancelQueue, callNextQueue, callQueue, completeQueue, errorMessage, listQueues, skipQueue, startQueue } from '../api';

const tabs = [
    { value: 'waiting', label: 'Menunggu' },
    { value: 'called', label: 'Dipanggil' },
    { value: 'in_service', label: 'Sedang dilayani' },
    { value: 'skipped', label: 'Dilewati' },
    { value: 'completed', label: 'Selesai' },
    { value: 'cancelled', label: 'Dibatalkan' },
];
const activeTab = ref('waiting');
const queues = ref([]);
const meta = ref(null);
const loading = ref(true);
const actionLoading = ref(false);
const error = ref('');
let poller;

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const response = await listQueues({ status: activeTab.value, per_page: 50 });
        queues.value = response.data;
        meta.value = response.meta;
    } catch (exception) {
        error.value = errorMessage(exception);
    } finally {
        loading.value = false;
    }
}

function selectTab(tab) { activeTab.value = tab; load(); }
function label(status) { return ({ waiting: 'Menunggu', called: 'Dipanggil', in_service: 'Sedang dilayani', skipped: 'Dilewati', completed: 'Selesai', cancelled: 'Dibatalkan' })[status] ?? status; }
function formatDate(value) { return value ? new Date(value).toLocaleString('id-ID') : '-'; }

async function run(action) {
    actionLoading.value = true;
    error.value = '';
    try { await action(); await load(); } catch (exception) { error.value = errorMessage(exception); } finally { actionLoading.value = false; }
}
function callNext() { return run(callNextQueue); }
function call(queue) { return run(() => callQueue(queue.id)); }
function start(queue) { return run(() => startQueue(queue.id)); }
function skip(queue) { return run(() => skipQueue(queue.id)); }
function cancel(queue) { if (window.confirm(`Batalkan ${queue.formatted_number}?`)) return run(() => cancelQueue(queue.id)); }
function complete(queue) { if (window.confirm(`Selesaikan ${queue.formatted_number}?`)) return run(() => completeQueue(queue.id)); }

onMounted(() => { load(); poller = window.setInterval(load, 5000); });
onUnmounted(() => window.clearInterval(poller));
</script>
