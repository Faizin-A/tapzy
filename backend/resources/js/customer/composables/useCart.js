import { computed, ref, watch } from 'vue';
import { clearCart, getCart, saveCart } from '../storage';
import { formatCents, toCents } from '../format';

export const MAX_CART_ITEMS = 50;
export const MAX_ITEM_QUANTITY = 99;
export const MAX_ITEM_NOTES = 255;
export const MAX_ORDER_NOTES = 1000;

function sanitizeItems(items) {
    if (!Array.isArray(items)) {
        return [];
    }

    const seen = new Set();

    return items
        .flatMap((item) => {
            const productId = Number(item?.product_id);

            if (!Number.isInteger(productId) || productId <= 0 || seen.has(productId)) {
                return [];
            }

            seen.add(productId);

            const quantity = Math.min(
                MAX_ITEM_QUANTITY,
                Math.max(1, Number.parseInt(item.quantity, 10) || 1),
            );

            return [{
                product_id: productId,
                name: typeof item.name === 'string' ? item.name : 'Menu',
                price: String(item.price ?? '0'),
                image_url: typeof item.image_url === 'string' ? item.image_url : null,
                category: typeof item.category === 'string' ? item.category : null,
                quantity,
                notes: typeof item.notes === 'string' ? item.notes.slice(0, MAX_ITEM_NOTES) : '',
            }];
        })
        .slice(0, MAX_CART_ITEMS);
}

export function useCart(accessToken) {
    const token = computed(() => String(accessToken?.value ?? accessToken ?? ''));
    const stored = getCart(token.value);

    const items = ref(sanitizeItems(stored.items));
    const orderNotes = ref(
        typeof stored.orderNotes === 'string' ? stored.orderNotes.slice(0, MAX_ORDER_NOTES) : '',
    );
    const message = ref(null);

    let skipNextSave = false;

    watch([items, orderNotes], () => {
        if (!token.value) {
            return;
        }

        if (skipNextSave) {
            skipNextSave = false;

            return;
        }

        saveCart(token.value, {
            items: items.value,
            orderNotes: orderNotes.value,
        });
    }, { deep: true });

    const itemCount = computed(() => items.value.reduce((total, item) => total + item.quantity, 0));
    const lineCount = computed(() => items.value.length);
    const subtotalCents = computed(() => items.value.reduce(
        (total, item) => total + toCents(item.price) * item.quantity,
        0,
    ));
    const subtotalLabel = computed(() => formatCents(subtotalCents.value));
    const isEmpty = computed(() => items.value.length === 0);
    const isAtMaxItems = computed(() => items.value.length >= MAX_CART_ITEMS);

    function quantityFor(productId) {
        const id = Number(productId);

        return items.value.find((item) => item.product_id === id)?.quantity ?? 0;
    }

    function addItem(product) {
        const productId = Number(product?.id);

        if (!Number.isInteger(productId) || productId <= 0 || !product?.is_available) {
            return false;
        }

        const existing = items.value.find((item) => item.product_id === productId);

        if (existing) {
            return increment(productId);
        }

        if (items.value.length >= MAX_CART_ITEMS) {
            message.value = `Maksimal ${MAX_CART_ITEMS} jenis menu dalam satu pesanan.`;

            return false;
        }

        items.value.push({
            product_id: productId,
            name: typeof product.name === 'string' ? product.name : 'Menu',
            price: String(product.price ?? '0'),
            image_url: typeof product.image_url === 'string' ? product.image_url : null,
            category: typeof product.category === 'string' ? product.category : null,
            quantity: 1,
            notes: '',
        });
        message.value = null;

        return true;
    }

    function increment(productId) {
        const item = items.value.find((entry) => entry.product_id === Number(productId));

        if (!item) {
            return false;
        }

        if (item.quantity >= MAX_ITEM_QUANTITY) {
            message.value = `Maksimal ${MAX_ITEM_QUANTITY} porsi untuk satu menu.`;

            return false;
        }

        item.quantity += 1;
        message.value = null;

        return true;
    }

    function decrement(productId) {
        const item = items.value.find((entry) => entry.product_id === Number(productId));

        if (!item || item.quantity <= 1) {
            return false;
        }

        item.quantity -= 1;
        message.value = null;

        return true;
    }

    function removeItem(productId) {
        items.value = items.value.filter((item) => item.product_id !== Number(productId));
        message.value = null;
    }

    function setItemNotes(productId, notes) {
        const item = items.value.find((entry) => entry.product_id === Number(productId));

        if (item) {
            item.notes = String(notes ?? '').slice(0, MAX_ITEM_NOTES);
        }
    }

    function setOrderNotes(notes) {
        orderNotes.value = String(notes ?? '').slice(0, MAX_ORDER_NOTES);
    }

    function clear() {
        const hadContent = items.value.length > 0 || orderNotes.value !== '';

        if (token.value) {
            clearCart(token.value);
            skipNextSave = hadContent;
        }

        items.value = [];
        orderNotes.value = '';
        message.value = null;
    }

    function toPayload() {
        const payload = {
            items: items.value.map((item) => {
                const entry = {
                    product_id: item.product_id,
                    quantity: item.quantity,
                };

                if (item.notes.trim() !== '') {
                    entry.notes = item.notes.trim();
                }

                return entry;
            }),
        };

        if (orderNotes.value.trim() !== '') {
            payload.notes = orderNotes.value.trim();
        }

        return payload;
    }

    return {
        items,
        orderNotes,
        message,
        itemCount,
        lineCount,
        subtotalCents,
        subtotalLabel,
        isEmpty,
        isAtMaxItems,
        quantityFor,
        addItem,
        increment,
        decrement,
        removeItem,
        setItemNotes,
        setOrderNotes,
        clear,
        toPayload,
    };
}
