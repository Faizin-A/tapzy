<template><span v-if="errors?.length" class="block text-xs text-red-600">{{ errors[0] }}</span></template>
<script setup>defineProps({ errors: Array });</script>
