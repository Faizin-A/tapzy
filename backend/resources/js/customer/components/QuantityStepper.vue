<template>
    <div class="inline-flex items-center rounded-xl border border-stone-200 bg-white">
        <button
            type="button"
            class="flex h-9 w-9 items-center justify-center text-lg font-semibold text-stone-600 transition hover:bg-stone-50 disabled:cursor-not-allowed disabled:text-stone-300"
            :disabled="disabled || quantity <= min"
            aria-label="Kurangi jumlah"
            @click="$emit('decrease')"
        >
            −
        </button>
        <span class="min-w-8 text-center text-sm font-semibold text-stone-900" aria-live="polite">
            {{ quantity }}
        </span>
        <button
            type="button"
            class="flex h-9 w-9 items-center justify-center text-lg font-semibold text-emerald-700 transition hover:bg-emerald-50 disabled:cursor-not-allowed disabled:text-stone-300"
            :disabled="disabled || quantity >= max"
            aria-label="Tambah jumlah"
            @click="$emit('increase')"
        >
            +
        </button>
    </div>
</template>

<script setup>
defineProps({
    quantity: {
        type: Number,
        required: true,
    },
    min: {
        type: Number,
        default: 1,
    },
    max: {
        type: Number,
        default: 99,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['increase', 'decrease']);
</script>
