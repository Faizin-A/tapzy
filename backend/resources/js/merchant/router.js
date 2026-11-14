import { createRouter, createWebHistory } from 'vue-router';
import DashboardView from './views/DashboardView.vue';
import ProfileView from './views/ProfileView.vue';
import ProductListView from './views/ProductListView.vue';
import ProductFormView from './views/ProductFormView.vue';
import ProductDetailView from './views/ProductDetailView.vue';
import QueueListView from './views/QueueListView.vue';
import QueueDetailView from './views/QueueDetailView.vue';
import ManualQueueView from './views/ManualQueueView.vue';

export default createRouter({
    history: createWebHistory('/merchant'),
    routes: [
        { path: '/', redirect: '/dashboard' },
        { path: '/dashboard', name: 'merchant-dashboard', component: DashboardView },
        { path: '/profile', name: 'merchant-profile', component: ProfileView },
        { path: '/products', name: 'merchant-products', component: ProductListView },
        { path: '/products/new', name: 'merchant-product-create', component: ProductFormView },
        { path: '/products/:id/edit', name: 'merchant-product-edit', component: ProductFormView },
        { path: '/products/:id', name: 'merchant-product-detail', component: ProductDetailView },
        { path: '/queues', name: 'merchant-queues', component: QueueListView },
        { path: '/queues/manual', name: 'merchant-queue-manual', component: ManualQueueView },
        { path: '/queues/:id', name: 'merchant-queue-detail', component: QueueDetailView },
    ],
    scrollBehavior() { return { top: 0 }; },
});
