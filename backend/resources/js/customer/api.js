import axios from 'axios';

const api = axios.create({
    baseURL: '/api/v1',
    headers: {
        Accept: 'application/json',
    },
    timeout: 15000,
});

export function isNotFound(error) {
    return error?.response?.status === 404;
}

export function isRequestCanceled(error) {
    return axios.isCancel(error)
        || error?.code === 'ERR_CANCELED'
        || error?.name === 'AbortError'
        || error?.name === 'CanceledError';
}

export function isOrderingDisabled(error) {
    return error?.response?.status === 409
        && error?.response?.data?.error_code === 'ORDERING_DISABLED';
}

export function getErrorMessage(error) {
    if (error?.response?.status === 429) {
        return 'Terlalu banyak permintaan. Silakan tunggu sebentar lalu coba lagi.';
    }

    return error?.response?.data?.message
        ?? 'Terjadi kesalahan saat memuat data. Periksa koneksi lalu coba lagi.';
}

export function getQueueErrorMessage(error) {
    const code = error?.response?.data?.error_code;

    if (code === 'QUEUE_SESSION_INACTIVE') {
        return 'Sesi antrean belum dibuka atau sudah ditutup. Silakan coba lagi nanti.';
    }

    if (code === 'STORE_NOT_OPERATIONAL') {
        return 'Toko sedang tidak dapat menerima antrean.';
    }

    if (code === 'IDEMPOTENCY_RECOVERY_UNAVAILABLE') {
        return 'Antrean sudah diproses, tetapi aksesnya tidak dapat dipulihkan. Silakan hubungi petugas.';
    }

    return getErrorMessage(error);
}

export function getOrderErrorMessage(error) {
    const code = error?.response?.data?.error_code;

    switch (code) {
        case 'ORDERING_DISABLED':
            return 'Toko ini tidak menerima pesanan menu.';
        case 'STORE_NOT_OPERATIONAL':
            return 'Toko sedang tidak dapat menerima pesanan.';
        case 'QUEUE_SESSION_INACTIVE':
            return 'Sesi antrean sudah ditutup. Silakan ambil antrean baru.';
        case 'QUEUE_NOT_ORDERABLE':
            return 'Antrean ini sudah tidak dapat dipesan.';
        case 'ORDER_ALREADY_EXISTS':
            return 'Pesanan untuk antrean ini sudah dibuat.';
        case 'PRODUCT_NOT_AVAILABLE':
            return 'Ada menu yang sudah tidak tersedia. Silakan perbarui keranjang.';
        default:
            return getErrorMessage(error);
    }
}

export default api;
