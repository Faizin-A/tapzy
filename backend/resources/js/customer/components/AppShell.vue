<template>
    <div class="mx-auto flex min-h-screen w-full max-w-md flex-col px-4 pb-10 pt-5 sm:px-6">
        <header class="mb-5 flex items-center justify-between">
            <span class="text-sm font-semibold tracking-[0.18em] text-emerald-700">TAPZY</span>
            <span class="rounded-full bg-white px-3 py-1 text-xs font-medium text-stone-500 shadow-sm">
                Halaman pelanggan
            </span>
        </header>

        <main class="flex-1">
            <slot />
        </main>
    </div>
</template>
