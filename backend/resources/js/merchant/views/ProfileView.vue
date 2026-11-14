<template>
    <section class="max-w-3xl"><div class="mb-6"><p class="text-sm text-stone-500">Pengaturan toko</p><h1 class="text-2xl font-bold">Profil Toko</h1></div>
        <div v-if="loading" class="rounded-xl bg-white p-6 shadow-sm">Memuat profil...</div><div v-else-if="error && !profile" class="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700">{{ error }}</div>
        <form v-else class="space-y-5 rounded-xl bg-white p-6 shadow-sm" @submit.prevent="save"><div v-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</div><div v-if="saved" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">Profil berhasil disimpan.</div>
            <label class="block text-sm font-medium">Nama toko<input v-model="form.name" class="mt-1 w-full rounded-lg border border-stone-300 p-2" required maxlength="150"><span v-if="fieldErrors.name" class="text-xs text-red-600">{{ fieldErrors.name[0] }}</span></label>
            <label class="block text-sm font-medium">Deskripsi<textarea v-model="form.description" class="mt-1 w-full rounded-lg border border-stone-300 p-2" rows="3" maxlength="5000" /></label>
            <div class="grid gap-4 sm:grid-cols-2"><label class="block text-sm font-medium">Telepon<input v-model="form.phone" class="mt-1 w-full rounded-lg border border-stone-300 p-2" maxlength="30"></label><label class="block text-sm font-medium">Waktu layanan (menit)<input v-model.number="form.avg_service_time" type="number" min="1" max="240" class="mt-1 w-full rounded-lg border border-stone-300 p-2"></label></div>
            <label class="block text-sm font-medium">Alamat<textarea v-model="form.address" class="mt-1 w-full rounded-lg border border-stone-300 p-2" rows="2" /></label><label class="block text-sm font-medium">Google Maps URL<input v-model="form.google_maps_url" type="url" class="mt-1 w-full rounded-lg border border-stone-300 p-2"></label>
            <div class="grid gap-4 sm:grid-cols-2"><label class="block text-sm font-medium">Prefix antrean<input v-model="form.queue_prefix" class="mt-1 w-full rounded-lg border border-stone-300 p-2" maxlength="5"></label><label class="block text-sm font-medium">Mode pemesanan<select v-model="form.ordering_mode" class="mt-1 w-full rounded-lg border border-stone-300 p-2"><option value="queue_only">Antrean saja</option><option value="queue_and_order">Antrean dan pesanan</option></select></label></div>
            <button :disabled="saving" class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ saving ? 'Menyimpan...' : 'Simpan perubahan' }}</button>
        </form>
    </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { errorMessage, loadProfile, updateProfile } from '../api';
const profile = ref(null); const loading = ref(true); const saving = ref(false); const error = ref(''); const saved = ref(false); const fieldErrors = ref({});
const form = reactive({ name: '', description: '', phone: '', address: '', google_maps_url: '', avg_service_time: 10, queue_prefix: '', ordering_mode: 'queue_and_order' });
function setForm(value) { profile.value = value; Object.assign(form, value); }
async function load() { try { setForm(await loadProfile()); } catch (exception) { error.value = errorMessage(exception); } finally { loading.value = false; } }
async function save() { saving.value = true; error.value = ''; saved.value = false; fieldErrors.value = {}; try { setForm(await updateProfile({ ...form })); saved.value = true; } catch (exception) { fieldErrors.value = exception?.response?.data?.errors ?? {}; error.value = errorMessage(exception); } finally { saving.value = false; } }
onMounted(load);
</script>
