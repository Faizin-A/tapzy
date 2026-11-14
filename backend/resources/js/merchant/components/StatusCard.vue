<template>
    <div class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">{{ title }}</h2><p v-if="!items.length" class="mt-3 text-sm text-stone-500">{{ empty }}</p><div v-else class="mt-3 divide-y divide-stone-100"><div v-for="([status, count]) in items" :key="status" class="flex justify-between py-2 text-sm"><span class="capitalize">{{ status.replaceAll('_', ' ') }}</span><strong>{{ count }}</strong></div></div></div>
</template>

<script setup>
defineProps({ title: String, items: Array, empty: String });
</script>
