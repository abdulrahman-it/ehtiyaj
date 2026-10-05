// Confirm state-changing actions and avoid accidental duplicate form submissions.
document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm || 'هل أنت متأكد؟')) event.preventDefault();
}));
document.querySelectorAll('button[data-confirm],a[data-confirm]').forEach(control => {
    if (control.closest('form[data-confirm]')) return;
    control.addEventListener('click', event => {
        if (!window.confirm(control.dataset.confirm || 'هل أنت متأكد؟')) event.preventDefault();
    });
});
document.querySelectorAll('input[type=file][multiple]').forEach(input => input.addEventListener('change', () => {
    const files = [...input.files];
    if (files.length > 3) { alert('يمكن اختيار ثلاث صور كحد أقصى.'); input.value = ''; return; }
    if (files.some(file => file.size > 2 * 1024 * 1024)) { alert('الحد الأقصى لحجم الصورة الواحدة 2MB.'); input.value = ''; return; }
    if (files.some(file => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) { alert('الصيغ المقبولة JPEG وPNG وWEBP فقط.'); input.value = ''; }
}));
document.querySelectorAll('input[type=file]:not([multiple])').forEach(input => input.addEventListener('change', () => {
    const file = input.files?.[0];
    if (file && (file.size > 2 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
        alert('اختر صورة JPEG أو PNG أو WEBP لا يتجاوز حجمها 2MB.'); input.value = '';
    }
}));

// Client-side image previews improve confidence; server-side MIME/size checks remain authoritative.
document.querySelectorAll('[data-image-preview]').forEach(input => {
    const preview = document.getElementById(input.dataset.imagePreview);
    if (!preview) return;
    const count = input.parentElement.querySelector('[data-file-count]')
        || input.nextElementSibling?.querySelector('[data-file-count]');
    const render = () => {
        preview.replaceChildren();
        const files = [...(input.files || [])];
        if (count) count.textContent = `${files.length}/${input.multiple ? 3 : 1}`;
        files.forEach((file, index) => {
            const item = document.createElement('div');
            item.className = 'image-preview-item';
            const image = document.createElement('img');
            image.src = URL.createObjectURL(file);
            image.alt = `معاينة الصورة ${index + 1}`;
            image.onload = () => URL.revokeObjectURL(image.src);
            const name = document.createElement('span');
            name.textContent = file.name;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'image-preview-remove';
            remove.setAttribute('aria-label', `إزالة ${file.name}`);
            remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            remove.addEventListener('click', () => {
                const transfer = new DataTransfer();
                files.forEach((entry, fileIndex) => { if (fileIndex !== index) transfer.items.add(entry); });
                input.files = transfer.files;
                render();
            });
            item.append(image, name, remove);
            preview.append(item);
        });
        preview.classList.toggle('has-previews', files.length > 0);
    };
    input.addEventListener('change', render);
    render();
});
document.querySelectorAll('form:not([data-confirm]):not(.offer-review-form)').forEach(form => form.addEventListener('submit', () => {
    const submit = form.querySelector('button[type=submit],button:not([type])');
    if (submit && !submit.dataset.keepLabel) {
        submit.dataset.keepLabel = '1';
        submit.setAttribute('aria-busy', 'true');
        submit.disabled = true;
        submit.textContent = 'جارٍ الإرسال…';
    }
}));

// Accessible mobile navigation drawer: overlay, escape key, focus return and focus containment.
(() => {
    const toggle = document.querySelector('.mobile-menu-toggle');
    const drawer = document.querySelector('#mobile-menu');
    const scrim = document.querySelector('.mobile-menu-scrim');
    if (!toggle || !drawer || !scrim) return;
    const closeControls = drawer.querySelectorAll('[data-menu-close]');
    const focusable = () => [...drawer.querySelectorAll('a[href],button:not([disabled])')];

    function openMenu() {
        document.body.classList.add('menu-open');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'إغلاق القائمة');
        drawer.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => focusable()[0]?.focus(), 60);
    }
    function closeMenu() {
        document.body.classList.remove('menu-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'فتح القائمة');
        drawer.setAttribute('aria-hidden', 'true');
        toggle.focus();
    }
    toggle.addEventListener('click', () => toggle.getAttribute('aria-expanded') === 'true' ? closeMenu() : openMenu());
    closeControls.forEach(control => control.addEventListener('click', closeMenu));
    scrim.addEventListener('click', closeMenu);
    drawer.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
        document.body.classList.remove('menu-open');
        toggle.setAttribute('aria-expanded', 'false');
        drawer.setAttribute('aria-hidden', 'true');
    }));
    document.addEventListener('keydown', event => {
        if (toggle.getAttribute('aria-expanded') !== 'true') return;
        if (event.key === 'Escape') { closeMenu(); return; }
        if (event.key !== 'Tab') return;
        const elements = focusable();
        const first = elements[0], last = elements[elements.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    });
})();

// Offer acceptance is explicit: confirm the named helper, then open the resulting chat.
document.querySelectorAll('.offer-review-form').forEach(form => {
    const checks = [...form.querySelectorAll('.offer-accept-checkbox')];
    const batchButton = form.querySelector('[data-accept-selected]');
    const singleButtons = [...form.querySelectorAll('[data-accept-offer]')];
    const update = () => {
        if (batchButton) batchButton.disabled = !checks.some(check => check.checked);
    };
    checks.forEach(check => check.addEventListener('change', update));
    singleButtons.forEach(button => button.addEventListener('click', () => {
        checks.forEach(check => { check.checked = check.value === button.value; });
        update();
    }));
    form.addEventListener('submit', event => {
        const selected = checks.filter(check => check.checked);
        if (!selected.length && event.submitter?.dataset.acceptOffer) {
            const matching = checks.find(check => check.value === event.submitter.value);
            if (matching) { matching.checked = true; selected.push(matching); }
        }
        if (!selected.length) {
            event.preventDefault();
            return;
        }
        const names = selected.map(check => check.dataset.offerName || 'مقدم العرض');
        const question = names.length === 1
            ? `هل تريد قبول عرض ${names[0]}؟ بعد القبول سيتم إنشاء محادثة بينكما وفتحها مباشرة.`
            : `هل تريد قبول ${names.length} عروض؟ سيتم إنشاء محادثة مع كل مقدم عرض وفتح محادثات الطلب بعد التأكيد.`;
        if (!window.confirm(question)) {
            event.preventDefault();
            return;
        }
        try {
            sessionStorage.setItem('ehtiyaj-accepted-request', JSON.stringify({
                requestId: form.dataset.requestId,
                count: names.length,
                at: Date.now(),
            }));
        } catch { /* The confirmed server response remains the source of truth if storage is unavailable. */ }
        if (event.submitter) {
            event.submitter.disabled = true;
            event.submitter.setAttribute('aria-busy', 'true');
        }
    });
    update();
});

// After the existing server confirms acceptance, use the request context to open its chat.
(() => {
    const flash = document.querySelector('[data-flash-message]');
    if (!flash || flash.dataset.flashMessage !== 'تم قبول العروض المحددة وإنشاء المحادثات.') return;
    let flow;
    try { flow = JSON.parse(sessionStorage.getItem('ehtiyaj-accepted-request') || 'null'); } catch { flow = null; }
    if (flow && Date.now() - Number(flow.at) > 120000) flow = null;
    const requestId = new URLSearchParams(window.location.search).get('id');
    if (!requestId || (flow && String(flow.requestId) !== String(requestId))) return;
    try { sessionStorage.removeItem('ehtiyaj-accepted-request'); } catch { /* No storage permission is needed to open the chat. */ }
    const link = document.querySelector('[data-request-chat-link]');
    if (!link) return;
    const target = new URL(link.href, window.location.href);
    target.searchParams.set('request_id', String(flow.requestId));
    target.searchParams.set('after_accept', '1');
    flash.insertAdjacentHTML('beforeend', '<div class="accept-success-next"><i class="bi bi-chat-dots"></i> تم إنشاء المحادثة. جارٍ فتحها الآن…</div>');
    window.setTimeout(() => window.location.assign(target.href), 650);
})();

// A single accepted offer opens its conversation directly; batch acceptance shows only those chats.
(() => {
    const params = new URLSearchParams(window.location.search);
    const requestId = params.get('request_id');
    if (!requestId || !/^\d+$/.test(requestId)) return;
    const allCards = [...document.querySelectorAll('.conversation-card[data-request-id]')];
    let matching = allCards.filter(card => card.dataset.requestId === requestId);
    const acceptedFlow = params.get('after_accept') === '1';
    if (acceptedFlow) {
        const newlyOpen = matching.filter(card => card.dataset.conversationStatus === 'open');
        if (newlyOpen.length) matching = newlyOpen;
    }
    allCards.forEach(card => { card.hidden = !matching.includes(card); });
    if (matching.length === 1 && acceptedFlow) {
        const chatLink = matching[0].querySelector('[data-open-conversation]');
        if (chatLink) window.location.replace(chatLink.href);
        return;
    }
    if (acceptedFlow && matching.length > 1) {
        const notice = document.createElement('div');
        notice.className = 'ux-flow-notice';
        notice.innerHTML = '<i class="bi bi-check-circle"></i><span><strong>تم قبول العروض وإنشاء المحادثات.</strong> اختر مقدم العرض لفتح المحادثة المرتبطة بهذا الطلب.</span>';
        document.querySelector('.ux-page-header')?.after(notice);
    } else if (acceptedFlow && matching.length === 0) {
        allCards.forEach(card => { card.hidden = false; });
        const notice = document.createElement('div');
        notice.className = 'ux-flow-notice';
        notice.textContent = 'تم قبول العرض، لكن لم تظهر محادثة هذا الطلب في الصفحة الحالية. استخدم قائمة المحادثات أدناه للعثور عليها.';
        document.querySelector('.ux-page-header')?.after(notice);
    }
})();

// Live character counts keep long-form request fields within their limits.
document.querySelectorAll('[data-count]').forEach(field => {
    const counter = field.parentElement.querySelector('[data-count-output]')
        || field.nextElementSibling?.querySelector('[data-count-output]');
    if (!counter) return;
    const refresh = () => {
        counter.textContent = String(field.value.length);
        counter.classList.toggle('count-near-limit', field.value.length >= Number(field.dataset.count) * 0.9);
    };
    field.addEventListener('input', refresh);
    refresh();
});

// Password visibility toggle and matching confirmation.
document.querySelectorAll('[data-password-target]').forEach(button => button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.passwordTarget);
    if (!input) return;
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.setAttribute('aria-label', reveal ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
    button.innerHTML = `<i class="bi bi-${reveal ? 'eye-slash' : 'eye'}" aria-hidden="true"></i>`;
    input.focus();
}));
document.querySelectorAll('[data-match]').forEach(input => {
    const source = document.querySelector(input.dataset.match);
    if (!source) return;
    const validate = () => input.setCustomValidity(input.value && input.value !== source.value ? 'تأكيد كلمة المرور غير مطابق.' : '');
    input.addEventListener('input', validate);
    source.addEventListener('input', validate);
});

// Flash messages behave like gentle, dismissible toasts.
document.querySelectorAll('main .alert[role="status"]').forEach(alert => {
    window.setTimeout(() => {
        if (!alert.isConnected) return;
        alert.classList.add('toast-dismiss');
        window.setTimeout(() => alert.remove(), 260);
    }, 6500);
});

// Convert wide data tables into labelled mobile rows instead of forcing horizontal scrolling.
document.querySelectorAll('.table-responsive table').forEach(table => {
    const labels = [...table.querySelectorAll('thead th')].map(cell => cell.textContent.trim());
    if (!labels.length) return;
    table.classList.add('responsive-stack-table');
    table.querySelectorAll('tbody tr').forEach(row => {
        [...row.children].forEach((cell, index) => {
            if (cell.tagName === 'TD' && !cell.hasAttribute('colspan')) cell.dataset.label = labels[index] || '';
        });
    });
});

// Native <details> controls the account/more menus; active-link styling never opens them.
(() => {
    const menus = [...document.querySelectorAll('.nav-more')];
    if (!menus.length) return;

    const summaryFor = menu => menu.querySelector(':scope > summary');
    const syncExpanded = menu => {
        const summary = summaryFor(menu);
        if (summary) summary.setAttribute('aria-expanded', menu.open ? 'true' : 'false');
    };
    const closeMenu = menu => {
        menu.open = false;
        syncExpanded(menu);
    };
    const closeAll = () => menus.forEach(closeMenu);

    menus.forEach(menu => {
        const summary = summaryFor(menu);
        // Server markup always starts closed, including pages where a child link is active.
        closeMenu(menu);
        menu.addEventListener('toggle', () => syncExpanded(menu));
        menu.querySelectorAll('.nav-more-menu a[href]').forEach(link => {
            // Close synchronously, then allow the anchor's normal navigation to continue.
            link.addEventListener('click', () => closeMenu(menu));
        });
        summary?.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown' && menu.open) {
                event.preventDefault();
                menu.querySelector('.nav-more-menu a[href]')?.focus();
            }
        });
    });

    // Click outside closes the menu without cancelling the click's normal action.
    document.addEventListener('click', event => {
        menus.forEach(menu => {
            if (menu.open && !menu.contains(event.target)) closeMenu(menu);
        });
    });

    // Escape works anywhere while a menu is open and returns focus to its trigger.
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const openMenus = menus.filter(menu => menu.open);
        if (!openMenus.length) return;
        const focusedMenu = openMenus.find(menu => menu.contains(document.activeElement)) || openMenus[0];
        openMenus.forEach(closeMenu);
        summaryFor(focusedMenu)?.focus();
    });

    // Browsers may restore a page from BFCache; never restore an open navigation menu.
    window.addEventListener('pagehide', closeAll);
    window.addEventListener('pageshow', closeAll);
})();
