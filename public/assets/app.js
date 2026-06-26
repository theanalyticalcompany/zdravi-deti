document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const message = form.getAttribute('data-confirm') || 'Opravdu pokračovat?';
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('[data-dialog-open]').forEach((button) => {
    button.addEventListener('click', (event) => {
        const dialog = document.getElementById(button.getAttribute('data-dialog-open'));
        if (dialog && typeof dialog.showModal === 'function') {
            event.preventDefault();
            if (dialog.hasAttribute('open')) {
                dialog.removeAttribute('open');
            }
            dialog.showModal();
        } else if (dialog) {
            event.preventDefault();
            dialog.setAttribute('open', '');
        }
    });
});

document.querySelectorAll('dialog[data-open-on-load]').forEach((dialog) => {
    if (typeof dialog.showModal === 'function') {
        if (dialog.hasAttribute('open')) {
            dialog.removeAttribute('open');
        }
        dialog.showModal();
    }
});

document.querySelectorAll('dialog [data-dialog-close]').forEach((button) => {
    button.addEventListener('click', () => {
        const dialog = button.closest('dialog');
        if (dialog) {
            dialog.close();
        }
    });
});

document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
});

document.addEventListener('click', (event) => {
    document.querySelectorAll('.ehic-menu[open]').forEach((menu) => {
        if (!menu.contains(event.target)) {
            menu.removeAttribute('open');
        }
    });
});

document.querySelectorAll('.ehic-menu').forEach((menu) => {
    menu.addEventListener('click', (event) => {
        if (menu.hasAttribute('open') && event.target === menu) {
            menu.removeAttribute('open');
        }
    });
});

document.querySelectorAll('[data-ehic-close]').forEach((button) => {
    button.addEventListener('click', () => {
        const menu = button.closest('.ehic-menu');
        if (menu) {
            menu.removeAttribute('open');
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }
    document.querySelectorAll('.ehic-menu[open]').forEach((menu) => {
        menu.removeAttribute('open');
    });
});

document.querySelectorAll('select[name="medication_id"]').forEach((select) => {
    const form = select.closest('form');
    const info = form ? form.querySelector('[data-medication-info]') : null;
    if (!info) {
        return;
    }

    const updateInfo = () => {
        const selected = select.selectedOptions[0];
        const text = selected ? selected.getAttribute('data-info') || '' : '';
        info.hidden = text === '';
        if (text === '') {
            info.innerHTML = '';
            return;
        }
        const source = selected ? selected.getAttribute('data-source') || '' : '';
        const items = text
            .split(/\.\s+/)
            .map((item) => item.trim().replace(/\.$/, ''))
            .filter(Boolean);
        info.innerHTML = [
            '<strong>Informace k dávkování</strong>',
            '<ul>' + items.map((item) => `<li>${escapeHtml(item)}</li>`).join('') + '</ul>',
            source ? `<a href="${escapeAttribute(source)}" target="_blank" rel="noopener">Ověřit příbalovou informaci na SÚKL</a>` : ''
        ].join('');
    };

    select.addEventListener('change', updateInfo);
    updateInfo();
});

function escapeHtml(value) {
    return value.replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[char]);
}

function escapeAttribute(value) {
    return escapeHtml(value).replace(/`/g, '&#096;');
}

if ('serviceWorker' in navigator && (window.location.protocol === 'https:' || window.location.hostname === 'localhost')) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // PWA registrace je doplňková; aplikace musí fungovat i bez ní.
        });
    });
}
