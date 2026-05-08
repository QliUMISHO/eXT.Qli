export function byId(id) {
    return document.getElementById(id);
}

export function qsa(selector) {
    return Array.prototype.slice.call(document.querySelectorAll(selector));
}

export function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
