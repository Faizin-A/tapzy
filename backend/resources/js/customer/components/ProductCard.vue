<template>
    <article class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
        <div class="flex gap-3">
            <div
                class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-stone-100 text-xl font-semibold text-stone-400"
                :class="{ 'opacity-60': !product.is_available }"
            >
                <img
                    v-if="imageUrl"
                    :src="imageUrl"
                    :alt="product.name"
                    class="h-full w-full object-cover"
                    loading="lazy"
                />
                <span v-else>{{ initial }}</span>
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="min-w-0 text-sm font-semibold leading-5 text-stone-900">
                        {{ product.name }}
                    </h3>
                    <span class="shrink-0 text-sm font-semibold text-emerald-700">
                        {{ formatRupiah(product.price) }}
                    </span>
                </div>

                <p v-if="product.category" class="mt-0.5 text-xs font-medium text-stone-500">
                    {{ product.category }}
                </p>
                <p v-if="product.description" class="mt-1 line-clamp-2 text-xs leading-5 text-stone-600">
                    {{ product.description }}
                </p>

                <div class="mt-3 flex items-center justify-between gap-3">
                    <span v-if="!product.is_available" class="text-xs font-semibold text-stone-400">
                        Tidak tersedia
                    </span>
                    <QuantityStepper
                        v-else-if="quantity > 0"
                        :quantity="quantity"
                        @decrease="$emit('decrease')"
                        @increase="$emit('increase')"
                    />
                    <button
                        v-else
                        type="button"
                        class="inline-flex min-h-9 items-center justify-center rounded-xl bg-emerald-700 px-4 text-xs font-semibold text-white transition hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
                        @click="$emit('add')"
                    >
                        Tambah
                    </button>

                    <span v-if="quantity > 0" class="text-xs font-medium text-stone-500">
                        Di keranjang
                    </span>
                </div>
            </div>
        </div>
    </article>
</template>

<script setup>
import { computed } from 'vue';
import QuantityStepper from './QuantityStepper.vue';
import { formatRupiah, safeHttpUrl } from '../format';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    quantity: {
        type: Number,
        default: 0,
    },
});

defineEmits(['add', 'increase', 'decrease']);

const imageUrl = computed(() => safeHttpUrl(props.product.image_url));
const initial = computed(() => String(props.product.name ?? '?').trim().charAt(0).toUpperCase() || '?');
</script>
