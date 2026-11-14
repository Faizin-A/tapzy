<template>
    <div class="min-h-screen bg-stone-50">
        <header class="border-b border-stone-200 bg-white"><div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4"><RouterLink to="/dashboard" class="text-xl font-bold text-stone-900">TAPZY Merchant</RouterLink><nav class="flex flex-wrap gap-4 text-sm"><RouterLink to="/dashboard" class="text-stone-600 hover:text-stone-950">Dashboard</RouterLink><RouterLink to="/queues" class="text-stone-600 hover:text-stone-950">Antrean</RouterLink><RouterLink to="/products" class="text-stone-600 hover:text-stone-950">Produk</RouterLink><RouterLink to="/profile" class="text-stone-600 hover:text-stone-950">Profil Toko</RouterLink></nav></div></header>
        <main class="mx-auto max-w-6xl px-4 py-6"><RouterView /></main>
    </div>
</template>

<script setup>
import { RouterLink, RouterView } from 'vue-router';
</script>
