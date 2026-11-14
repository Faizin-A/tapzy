<template>
    <section><div class="mb-6"><p class="text-sm text-stone-500">Ringkasan operasional</p><h1 class="text-2xl font-bold">Dashboard</h1></div>
        <div v-if="loading" class="rounded-xl bg-white p-6 text-stone-600 shadow-sm">Memuat dashboard...</div>
        <div v-else-if="error" class="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700"><p>{{ error }}</p><button class="mt-3 underline" @click="load">Coba lagi</button></div>
        <template v-else-if="dashboard"><div class="grid gap-4 md:grid-cols-3"><div class="rounded-xl bg-white p-5 shadow-sm md:col-span-2"><p class="text-sm text-stone-500">Toko</p><h2 class="text-xl font-semibold">{{ dashboard.store.name }}</h2><p class="mt-1 text-sm text-stone-600">{{ dashboard.store.address || 'Alamat belum diisi' }}</p></div><div class="rounded-xl bg-white p-5 shadow-sm"><p class="text-sm text-stone-500">Status operasional</p><p class="mt-2 font-semibold" :class="dashboard.store.is_operational ? 'text-emerald-700' : 'text-red-700'">{{ dashboard.store.is_operational ? 'Aktif' : 'Tidak aktif' }}</p></div></div>
            <div class="mt-6 rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">Sesi antrean</h2><p v-if="sessionError" class="mt-2 text-sm text-red-700">{{ sessionError }}</p><p v-if="!dashboard.queue_session" class="mt-2 text-sm text-stone-500">Belum ada sesi aktif.</p><div v-else class="mt-3 grid gap-3 text-sm sm:grid-cols-3"><span>Kode: <strong>{{ dashboard.queue_session.session_code }}</strong></span><span>Nomor terakhir: <strong>{{ dashboard.queue_session.last_queue_number }}</strong></span><span>Status: <strong>{{ dashboard.queue_session.status }}</strong></span></div><div class="mt-4 flex flex-wrap gap-3"><button v-if="!dashboard.queue_session || dashboard.queue_session.status === 'closed'" :disabled="sessionLoading" class="rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="openSession">{{ sessionLoading ? 'Memproses...' : 'Buka sesi' }}</button><template v-else><button :disabled="sessionLoading" class="rounded-lg border border-stone-300 px-3 py-2 text-sm disabled:opacity-50" @click="closeSession">Tutup sesi</button><button :disabled="sessionLoading" class="rounded-lg bg-red-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="resetSession">Reset sesi</button></template></div></div>
            <div class="mt-6 grid gap-6 md:grid-cols-2"><StatusCard title="Antrean aktif" :items="activeQueues" empty="Belum ada antrean aktif." /><StatusCard title="Pesanan" :items="activeOrders" empty="Belum ada pesanan aktif." /></div>
        </template>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { closeQueueSession, errorMessage, loadDashboard, openQueueSession, resetQueueSession } from '../api';
import StatusCard from '../components/StatusCard.vue';
const dashboard = ref(null); const loading = ref(true); const error = ref(''); const sessionLoading = ref(false); const sessionError = ref('');
const activeQueues = computed(() => Object.entries(dashboard.value?.queue_counts ?? {}).filter(([status]) => ['waiting', 'called', 'in_service', 'skipped'].includes(status)));
const activeOrders = computed(() => Object.entries(dashboard.value?.order_counts ?? {}).filter(([status]) => ['pending', 'confirmed', 'preparing', 'ready'].includes(status)));
async function load() { loading.value = true; error.value = ''; try { dashboard.value = await loadDashboard(); } catch (exception) { error.value = errorMessage(exception); } finally { loading.value = false; } }
async function runSessionAction(action) { sessionLoading.value = true; sessionError.value = ''; try { await action(); await load(); } catch (exception) { sessionError.value = errorMessage(exception); } finally { sessionLoading.value = false; } }
function openSession() { return runSessionAction(openQueueSession); }
function closeSession() { return runSessionAction(closeQueueSession); }
function resetSession() { if (!window.confirm('Reset sesi akan membatalkan antrean aktif dan pesanan yang belum selesai. Lanjutkan?')) return; return runSessionAction(resetQueueSession); }
onMounted(load);
</script>
