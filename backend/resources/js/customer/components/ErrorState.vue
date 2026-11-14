<template>
    <section class="rounded-2xl border border-rose-200 bg-rose-50 p-5" role="alert">
        <h2 class="text-base font-semibold text-rose-900">Tidak dapat memuat halaman</h2>
        <p class="mt-2 text-sm leading-6 text-rose-800">{{ message }}</p>
        <button
            type="button"
            class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-rose-700 px-4 text-sm font-semibold text-white transition hover:bg-rose-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2"
            @click="$emit('retry')"
        >
            Coba lagi
        </button>
    </section>
</template>

<script setup>
defineProps({
    message: {
        type: String,
        required: true,
    },
});

defineEmits(['retry']);
</script>
