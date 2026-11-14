import axios from 'axios';

const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    headers: { Accept: 'application/json' },
    timeout: 15000,
});

export async function loadDashboard() { return (await api.get('/merchant/dashboard')).data.data; }
export async function loadProfile() { return (await api.get('/merchant/profile')).data.data; }
export async function updateProfile(payload) {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
    return (await api.patch('/merchant/profile', payload)).data.data;
}
export async function listProducts(params = {}) { return (await api.get('/merchant/products', { params })).data; }
export async function getProduct(id) { return (await api.get(`/merchant/products/${id}`)).data.data; }
export async function createProduct(payload) { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.post('/merchant/products', payload)).data.data; }
export async function updateProduct(id, payload) { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.patch(`/merchant/products/${id}`, payload)).data.data; }
export async function deleteProduct(id) { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return api.delete(`/merchant/products/${id}`); }
export async function updateProductStatus(id, payload) { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.patch(`/merchant/products/${id}/status`, payload)).data.data; }
export async function getQueueSession() { return (await api.get('/merchant/queue-session')).data.data; }
export async function openQueueSession() { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.post('/merchant/queue-session')).data.data; }
export async function closeQueueSession() { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.patch('/merchant/queue-session/close')).data.data; }
export async function resetQueueSession() { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.post('/merchant/queue-session/reset')).data.data; }
export async function listQueues(params = {}) { return (await api.get('/merchant/queues', { params })).data; }
export async function createManualQueue(payload, idempotencyKey) {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
    return (await api.post('/merchant/queues/manual', payload, { headers: { 'Idempotency-Key': idempotencyKey } })).data.data;
}
export async function getQueue(id) { return (await api.get(`/merchant/queues/${id}`)).data.data; }
export async function callNextQueue() { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.post('/merchant/queues/next/call')).data.data; }
export async function callQueue(id) { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.post(`/merchant/queues/${id}/call`)).data.data; }
export async function startQueue(id) { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.post(`/merchant/queues/${id}/start`)).data.data; }
export async function skipQueue(id) { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.post(`/merchant/queues/${id}/skip`)).data.data; }
export async function cancelQueue(id) { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.post(`/merchant/queues/${id}/cancel`)).data.data; }
export async function completeQueue(id) { await axios.get('/sanctum/csrf-cookie', { withCredentials: true }); return (await api.post(`/merchant/queues/${id}/complete`)).data.data; }
export function errorMessage(error) {
    if (error?.response?.status === 401) return 'Sesi login berakhir. Silakan login kembali.';
    if (error?.response?.status === 403) return 'Akses dashboard merchant tidak tersedia.';
    if (error?.response?.status === 422) return 'Periksa kembali data yang diisi.';
    if (error?.response?.status === 409) return error?.response?.data?.message ?? 'Operasi sesi antrean tidak dapat dilakukan saat ini.';
    if (error?.response?.status === 404) return 'Antrean tidak ditemukan atau sudah tidak tersedia.';
    return error?.response?.data?.message ?? 'Terjadi kesalahan. Silakan coba lagi.';
}
export default api;
