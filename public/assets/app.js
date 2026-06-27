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

document.querySelectorAll('form[data-upload-form]').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"]');
        if (!button) {
            return;
        }
        button.disabled = true;
        button.textContent = form.getAttribute('data-upload-label') || 'Nahrávám...';
        form.setAttribute('aria-busy', 'true');
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

document.querySelectorAll('[data-timeline-controls]').forEach((controls) => {
    const target = document.querySelector('[data-timeline-target]');
    if (!target) {
        return;
    }

    controls.querySelectorAll('a[data-timeline-url]').forEach((link) => {
        link.addEventListener('click', async (event) => {
            event.preventDefault();
            try {
                const response = await fetch(link.getAttribute('data-timeline-url'), {
                    headers: { 'X-Requested-With': 'fetch' }
                });
                if (!response.ok) {
                    throw new Error('Timeline request failed');
                }
                target.innerHTML = await response.text();
                controls.querySelectorAll('a').forEach((item) => item.classList.remove('active'));
                link.classList.add('active');
                window.history.replaceState(null, '', link.href);
            } catch (error) {
                window.location.href = link.href;
            }
        });
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
        const substance = items.shift() || '';
        const dosing = items.shift() || '';
        const details = items;
        const structured = [
            substance ? `<dt>Účinná látka</dt><dd>${escapeHtml(substance)}</dd>` : '',
            dosing ? `<dt>Dávkování</dt><dd>${escapeHtml(dosing)}</dd>` : ''
        ].join('');
        info.innerHTML = [
            structured ? `<dl>${structured}</dl>` : '',
            details.length ? '<ul>' + details.map((item) => `<li>${escapeHtml(item)}</li>`).join('') + '</ul>' : '',
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
    let serviceWorkerReloaded = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (serviceWorkerReloaded) {
            return;
        }
        serviceWorkerReloaded = true;
        window.location.reload();
    });

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // PWA registrace je doplňková; aplikace musí fungovat i bez ní.
        });
    });
}
