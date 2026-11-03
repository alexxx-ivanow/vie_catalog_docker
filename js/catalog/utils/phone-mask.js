export function formatRuPhoneInput(value) {
    const digits = String(value || '').replace(/\D/g, '');
    let normalized = digits;
    if (normalized.startsWith('8')) {
        normalized = '7' + normalized.slice(1);
    }
    if (!normalized.startsWith('7') && normalized.length > 0) {
        normalized = '7' + normalized;
    }
    normalized = normalized.slice(0, 11);

    const parts = [
        normalized.slice(1, 4),
        normalized.slice(4, 7),
        normalized.slice(7, 9),
        normalized.slice(9, 11),
    ];

    let out = '+7';
    if (parts[0]) {
        out += ` (${parts[0]}`;
        if (parts[0].length === 3) {
            out += ')';
        }
    }
    if (parts[1]) {
        out += ` ${parts[1]}`;
    }
    if (parts[2]) {
        out += `-${parts[2]}`;
    }
    if (parts[3]) {
        out += `-${parts[3]}`;
    }

    return out;
}

/** Полный мобильный/городской РФ: 11 цифр, код страны 7 */
export function isValidRuPhone(value) {
    const digits = String(value || '').replace(/\D/g, '');
    let normalized = digits;
    if (normalized.startsWith('8') && normalized.length === 11) {
        normalized = '7' + normalized.slice(1);
    }
    if (normalized.length === 10) {
        normalized = '7' + normalized;
    }
    return normalized.length === 11 && normalized.startsWith('7');
}
