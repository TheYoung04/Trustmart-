/* TrustMart progressive UI enhancement layer.
   It adds navigation, keyboard and dialog accessibility without replacing
   page-specific business logic. */
(() => {
  'use strict';

  const ready = (callback) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
      callback();
    }
  };

  const visible = (element) => Boolean(
    element &&
    !element.hidden &&
    element.getAttribute('aria-hidden') !== 'true' &&
    getComputedStyle(element).display !== 'none' &&
    getComputedStyle(element).visibility !== 'hidden'
  );

  // Same test minus the aria-hidden term. setupDialogs() writes aria-hidden onto
  // the very backdrop it measures, so using visible() there is circular: a modal
  // that starts closed gets stamped aria-hidden="true", and from then on
  // visible() reports false even after it is opened. The observer never sees an
  // open transition, so the dialog stays hidden from assistive tech and neither
  // the focus-into-dialog nor the focus-restore step ever runs.
  const shown = (element) => Boolean(
    element &&
    !element.hidden &&
    getComputedStyle(element).display !== 'none' &&
    getComputedStyle(element).visibility !== 'hidden'
  );

  const focusable = (root) => [...root.querySelectorAll(
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), ' +
    'textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
  )].filter(visible);

  function identifyPage() {
    const file = location.pathname.split('/').filter(Boolean).pop() || 'index';
    document.body.classList.add(`tm-page-${file.replace(/\.php$/i, '').replace(/[^a-z0-9_-]/gi, '-')}`);
  }

  function addSkipLink() {
    const main = document.querySelector('.main, main, .login-right, body > .card');
    if (!main || document.querySelector('.tm-skip-link')) return;

    if (!main.id) main.id = 'main-content';
    if (!main.hasAttribute('tabindex')) main.tabIndex = -1;

    const link = document.createElement('a');
    link.className = 'tm-skip-link';
    link.href = `#${main.id}`;
    link.textContent = 'Langkau ke kandungan utama';
    document.body.prepend(link);
  }

  function setupMobileNavigation() {
    const sidebar = document.querySelector('.sidebar');
    const topbar = document.querySelector('.topbar');
    if (!sidebar || !topbar || topbar.querySelector('.tm-menu-toggle')) return;

    sidebar.id ||= 'primary-navigation';
    sidebar.setAttribute('aria-label', 'Navigasi utama');

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'tm-menu-toggle';
    toggle.setAttribute('aria-controls', sidebar.id);
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Buka menu navigasi');
    toggle.innerHTML = `
      <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <path d="M4 7h16M4 12h16M4 17h16"/>
      </svg>`;
    topbar.prepend(toggle);

    const backdrop = document.createElement('button');
    backdrop.type = 'button';
    backdrop.className = 'tm-nav-backdrop';
    backdrop.tabIndex = -1;
    backdrop.setAttribute('aria-label', 'Tutup menu navigasi');
    document.body.append(backdrop);

    let returnFocus = null;
    const setOpen = (open, restore = true) => {
      sidebar.classList.toggle('tm-open', open);
      backdrop.classList.toggle('tm-open', open);
      document.body.classList.toggle('tm-nav-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Tutup menu navigasi' : 'Buka menu navigasi');
      if (open) {
        returnFocus = document.activeElement;
        const target = sidebar.querySelector('.nav-item.active, .nav-item, a, button');
        window.setTimeout(() => target?.focus(), 80);
      } else if (restore && returnFocus instanceof HTMLElement) {
        returnFocus.focus();
      }
    };

    toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('tm-open')));
    backdrop.addEventListener('click', () => setOpen(false));
    sidebar.addEventListener('click', (event) => {
      if (event.target.closest('.nav-item, a[href]')) setOpen(false, false);
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && sidebar.classList.contains('tm-open')) setOpen(false);
    });
    window.addEventListener('resize', () => {
      if (window.innerWidth > 860 && sidebar.classList.contains('tm-open')) setOpen(false, false);
    }, { passive: true });
  }

  function setupInteractiveSemantics() {
    const makeKeyboardClickable = (element) => {
      if (element.matches('button, a[href], input, select, textarea')) return;
      element.tabIndex = element.tabIndex >= 0 ? element.tabIndex : 0;
      element.setAttribute('role', 'button');
      element.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          element.click();
        }
      });
    };

    document.querySelectorAll('.nav-item').forEach((item) => {
      makeKeyboardClickable(item);
      if (item.classList.contains('active')) item.setAttribute('aria-current', 'page');
    });

    document.querySelectorAll('.filter-btn').forEach((item) => {
      makeKeyboardClickable(item);
      item.setAttribute('aria-pressed', String(item.classList.contains('active')));
    });

    const stars = [...document.querySelectorAll('.star')];
    if (stars.length) {
      stars[0].parentElement?.setAttribute('role', 'radiogroup');
      stars[0].parentElement?.setAttribute('aria-label', 'Penilaian bintang');
      stars.forEach((star) => {
        makeKeyboardClickable(star);
        star.setAttribute('role', 'radio');
        star.setAttribute('aria-label', `${star.dataset.val || ''} daripada 5 bintang`);
        star.setAttribute('aria-checked', String(star.classList.contains('on')));
      });
    }

    const tabs = [...document.querySelectorAll('.tab')];
    if (tabs.length) {
      const tabList = tabs[0].parentElement;
      tabList?.setAttribute('role', 'tablist');
      tabList?.setAttribute('aria-label', 'Pilihan akaun');
      tabs.forEach((tab, index) => {
        makeKeyboardClickable(tab);
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-selected', String(tab.classList.contains('active')));
        tab.tabIndex = tab.classList.contains('active') ? 0 : -1;
        const panelId = index === 0 ? 'form-login' : 'form-register';
        if (document.getElementById(panelId)) tab.setAttribute('aria-controls', panelId);
        tab.addEventListener('keydown', (event) => {
          if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
          event.preventDefault();
          let next = index;
          if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
          if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
          if (event.key === 'Home') next = 0;
          if (event.key === 'End') next = tabs.length - 1;
          tabs[next].focus();
          tabs[next].click();
        });
      });
    }

    const syncState = () => {
      document.querySelectorAll('.nav-item').forEach((item) => {
        if (item.classList.contains('active')) item.setAttribute('aria-current', 'page');
        else item.removeAttribute('aria-current');
      });
      document.querySelectorAll('.filter-btn').forEach((item) => {
        item.setAttribute('aria-pressed', String(item.classList.contains('active')));
      });
      tabs.forEach((tab) => {
        const active = tab.classList.contains('active');
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
      });
      stars.forEach((star) => {
        star.setAttribute('aria-checked', String(star.classList.contains('on')));
      });
    };

    const observer = new MutationObserver(syncState);
    document.querySelectorAll('.nav-item, .filter-btn, .tab, .star').forEach((item) => {
      observer.observe(item, { attributes: true, attributeFilter: ['class'] });
    });
  }

  function setupForms() {
    document.querySelectorAll('label.form-label:not([for])').forEach((label) => {
      const field = label.parentElement?.querySelector('input, select, textarea');
      if (!field) return;
      field.id ||= `tm-field-${Math.random().toString(36).slice(2, 9)}`;
      label.htmlFor = field.id;
    });

    const fieldLimits = {
      'review-comment': 2000,
      'dispute-title': 200,
      'dispute-description': 5000,
      'add-name': 200,
      'edit-name': 200,
      'add-desc': 5000,
      'edit-desc': 5000,
      'chat-message-input': 2000,
    };
    Object.entries(fieldLimits).forEach(([id, limit]) => {
      const field = document.getElementById(id);
      if (field) field.maxLength = limit;
    });

    document.querySelectorAll('input[type="password"]:not([data-tm-password])').forEach((input) => {
      input.dataset.tmPassword = 'true';
      if (!input.hasAttribute('maxlength')) input.maxLength = 128;
      const wrapper = document.createElement('div');
      wrapper.className = 'tm-password-wrap';
      input.parentNode.insertBefore(wrapper, input);
      wrapper.appendChild(input);

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'tm-password-toggle';
      toggle.textContent = 'Tunjukkan';
      toggle.setAttribute('aria-label', 'Tunjukkan kata laluan');
      toggle.setAttribute('aria-pressed', 'false');
      toggle.addEventListener('click', () => {
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        toggle.textContent = showing ? 'Tunjukkan' : 'Sembunyikan';
        toggle.setAttribute('aria-label', showing ? 'Tunjukkan kata laluan' : 'Sembunyikan kata laluan');
        toggle.setAttribute('aria-pressed', String(!showing));
        input.focus({ preventScroll: true });
      });
      wrapper.appendChild(toggle);
    });
  }

  function setupTablesAndMedia() {
    document.querySelectorAll('.tbl-wrap').forEach((wrapper) => {
      wrapper.tabIndex = 0;
      wrapper.setAttribute('role', 'region');
      wrapper.setAttribute('aria-label', 'Jadual data yang boleh ditatal');
    });
    document.querySelectorAll('.tbl th').forEach((header) => header.setAttribute('scope', 'col'));
    document.querySelectorAll('img').forEach((image) => {
      image.loading ||= 'lazy';
      image.decoding = 'async';
    });

    ['chat-contacts-list', 'chat-messages-stream'].forEach((id) => {
      const element = document.getElementById(id);
      const candidate = element?.parentElement?.parentElement;
      if (candidate && getComputedStyle(candidate).display === 'grid') {
        candidate.classList.add('tm-chat-layout');
      }
    });
  }

  function setupLiveRegions() {
    document.querySelectorAll('.toast').forEach((toast) => {
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');
      toast.setAttribute('aria-atomic', 'true');
    });
    document.querySelectorAll('.alert').forEach((alert) => {
      const isError = alert.classList.contains('alert-error');
      alert.setAttribute('role', isError ? 'alert' : 'status');
      alert.setAttribute('aria-live', isError ? 'assertive' : 'polite');
      alert.setAttribute('aria-atomic', 'true');
    });
  }

  function setupDialogs() {
    const dialogs = [...document.querySelectorAll('.modal-bg, #forgot-modal')];
    let lastFocus = null;

    dialogs.forEach((backdrop, index) => {
      const dialog = backdrop.querySelector('.modal') || backdrop.firstElementChild;
      if (!dialog) return;

      dialog.setAttribute('role', 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.tabIndex = -1;

      const title = dialog.querySelector('.modal-title, .card-title, [class*="title"], h1, h2');
      if (title) {
        title.id ||= `tm-dialog-title-${index + 1}`;
        dialog.setAttribute('aria-labelledby', title.id);
      } else {
        dialog.setAttribute('aria-label', 'Tetingkap dialog');
      }

      let wasVisible = shown(backdrop);
      const update = () => {
        const isVisible = shown(backdrop);
        backdrop.setAttribute('aria-hidden', String(!isVisible));
        if (isVisible && !wasVisible) {
          lastFocus = document.activeElement;
          window.setTimeout(() => (focusable(dialog)[0] || dialog).focus(), 30);
        }
        if (!isVisible && wasVisible && lastFocus instanceof HTMLElement) {
          lastFocus.focus({ preventScroll: true });
        }
        wasVisible = isVisible;
      };

      new MutationObserver(update).observe(backdrop, {
        attributes: true,
        attributeFilter: ['style', 'class', 'hidden']
      });
      update();

      dialog.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') return;
        const items = focusable(dialog);
        if (!items.length) {
          event.preventDefault();
          dialog.focus();
          return;
        }
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      });
    });
  }

  function setupTabHistory() {
    if (typeof window.showTab !== 'function') return;

    const activateFromUrl = () => {
      const tab = new URL(location.href).searchParams.get('tab');
      if (tab && document.getElementById(`tab-${tab}`)) window.showTab(tab);
    };

    document.querySelectorAll('.nav-item[onclick*="showTab"]').forEach((item) => {
      item.addEventListener('click', () => {
        const match = item.getAttribute('onclick')?.match(/showTab\(['"]([^'"]+)['"]\)/);
        if (!match) return;
        const url = new URL(location.href);
        url.searchParams.set('tab', match[1]);
        if (match[1] !== 'chat') {
          ['seller_id', 'seller_name', 'product_id', 'product_name'].forEach(key => url.searchParams.delete(key));
        }
        history.replaceState({ tab: match[1] }, '', url);
      });
    });

    window.addEventListener('popstate', activateFromUrl);
    activateFromUrl();
  }

  function setupConnectivityFeedback() {
    const banner = document.createElement('div');
    banner.className = 'tm-offline-banner';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');
    banner.textContent = 'Tiada sambungan internet — perubahan mungkin belum disimpan';
    document.body.appendChild(banner);

    const sync = () => document.body.classList.toggle('tm-offline', !navigator.onLine);
    window.addEventListener('online', sync);
    window.addEventListener('offline', sync);
    sync();
  }

  ready(() => {
    identifyPage();
    addSkipLink();
    setupMobileNavigation();
    setupInteractiveSemantics();
    setupForms();
    setupTablesAndMedia();
    setupLiveRegions();
    setupDialogs();
    setupTabHistory();
    setupConnectivityFeedback();
  });
})();
