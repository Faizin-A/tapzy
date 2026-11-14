<template>
    <AppShell>
        <section class="rounded-2xl border border-stone-200 bg-white p-6 text-center shadow-sm">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-stone-100 text-stone-500">
                <svg viewBox="0 0 24 24" fill="none" class="h-7 w-7" aria-hidden="true">
                    <path
                        d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </div>
            <h1 class="mt-4 text-lg font-semibold text-stone-900">Toko tidak ditemukan</h1>
            <p class="mt-2 text-sm leading-6 text-stone-600">
                Tautan NFC atau QR ini tidak valid, kartu tidak aktif, atau toko sedang tidak dapat diakses.
            </p>
        </section>
    </AppShell>
</template>

<script setup>
import AppShell from '../components/AppShell.vue';
</script>
