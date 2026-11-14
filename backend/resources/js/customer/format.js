const rupiahFormatter = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

export function formatRupiah(value) {
    const number = Number(value);

    return rupiahFormatter.format(Number.isFinite(number) ? number : 0);
}

export function toCents(value) {
    const number = Number(value);

    return Number.isFinite(number) ? Math.round(number * 100) : 0;
}

export function formatCents(cents) {
    return formatRupiah((Number(cents) || 0) / 100);
}

export function formatDateTime(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

export function safeHttpUrl(value) {
    return typeof value === 'string' && /^https?:\/\//i.test(value) ? value : null;
}
