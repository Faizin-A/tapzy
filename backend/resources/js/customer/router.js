import { createRouter, createWebHistory } from 'vue-router';
import StoreView from './views/StoreView.vue';
import MenuView from './views/MenuView.vue';
import CartView from './views/CartView.vue';
import ConfirmView from './views/ConfirmView.vue';
import OrderSuccessView from './views/OrderSuccessView.vue';
import NotFoundView from './views/NotFoundView.vue';
import { getQueueContext } from './storage';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/t/:token',
            name: 'store',
            component: StoreView,
        },
        {
            path: '/t/:token/menu',
            name: 'menu',
            component: MenuView,
        },
        {
            path: '/t/:token/cart',
            name: 'cart',
            component: CartView,
        },
        {
            path: '/t/:token/confirm',
            name: 'confirm',
            component: ConfirmView,
        },
        {
            path: '/q/:accessToken',
            name: 'order-success',
            component: OrderSuccessView,
        },
        {
            path: '/:pathMatch(.*)*',
            name: 'not-found',
            component: NotFoundView,
        },
    ],
    scrollBehavior() {
        return { top: 0 };
    },
});

router.beforeEach((to) => {
    if (!['menu', 'cart', 'confirm'].includes(to.name)) {
        return true;
    }

    const token = String(to.params.token ?? '');
    const context = getQueueContext();

    if (!context || context.store_token !== token || !context.access_token) {
        return {
            name: 'store',
            params: { token },
        };
    }

    if (context.ordering_mode === 'queue_only') {
        return {
            name: 'order-success',
            params: { accessToken: context.access_token },
        };
    }

    return true;
});

export default router;
