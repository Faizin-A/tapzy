const PREFIX = 'tapzy.customer';
const QUEUE_CONTEXT_KEY = `${PREFIX}.queue`;
const CART_PREFIX = `${PREFIX}.cart`;
const ORDER_PREFIX = `${PREFIX}.order`;

let memoryQueueContext = null;
const memoryCarts = new Map();
const memoryOrders = new Map();

function sessionStorage() {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

function read(key, fallback) {
    const storage = sessionStorage();

    if (!storage) {
        return fallback;
    }

    try {
        const raw = storage.getItem(key);

        return raw ? JSON.parse(raw) : fallback;
    } catch {
        return fallback;
    }
}

function write(key, value) {
    const storage = sessionStorage();

    if (!storage) {
        return;
    }

    try {
        storage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage can be unavailable in private browsing or when quota is full.
    }
}

function remove(key) {
    const storage = sessionStorage();

    if (!storage) {
        return;
    }

    try {
        storage.removeItem(key);
    } catch {
        // Ignore storage cleanup failures.
    }
}

function tokenKey(prefix, token) {
    return `${prefix}.${String(token ?? '')}`;
}

export function getQueueContext() {
    const context = read(QUEUE_CONTEXT_KEY, null);

    return context && typeof context === 'object' ? context : memoryQueueContext;
}

export function saveQueueContext(context) {
    memoryQueueContext = context;
    write(QUEUE_CONTEXT_KEY, context);
}

export function clearQueueContext() {
    memoryQueueContext = null;
    remove(QUEUE_CONTEXT_KEY);
}

export function getCart(token) {
    const key = String(token ?? '');
    const cart = read(tokenKey(CART_PREFIX, key), null) ?? memoryCarts.get(key);

    if (!cart || typeof cart !== 'object') {
        return { items: [], orderNotes: '' };
    }

    return cart;
}

export function saveCart(token, cart) {
    const key = String(token ?? '');
    memoryCarts.set(key, cart);
    write(tokenKey(CART_PREFIX, key), cart);
}

export function clearCart(token) {
    const key = String(token ?? '');
    memoryCarts.delete(key);
    remove(tokenKey(CART_PREFIX, key));
}

export function getOrder(token) {
    const key = String(token ?? '');
    const order = read(tokenKey(ORDER_PREFIX, key), null) ?? memoryOrders.get(key);

    return order && typeof order === 'object' ? order : null;
}

export function saveOrder(token, order) {
    const key = String(token ?? '');
    memoryOrders.set(key, order);
    write(tokenKey(ORDER_PREFIX, key), order);
}

export function clearOrder(token) {
    const key = String(token ?? '');
    memoryOrders.delete(key);
    remove(tokenKey(ORDER_PREFIX, key));
}
