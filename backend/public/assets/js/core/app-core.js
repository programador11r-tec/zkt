(() => {
  const app = document.getElementById('app');
  const sidebar = document.getElementById('appSidebar');
  const sidebarLinks = sidebar ? Array.from(sidebar.querySelectorAll('.nav-link')) : [];
  const state = { currentPage: null, renderGeneration: 0 };
  const settingsState = { cache: null, promise: null };
  const hamachiSyncState = { timer: null, promise: null };
  const pages = {};

  if (sidebar) {
    sidebar.setAttribute('aria-hidden', 'false');
  }

  // Detecta el directorio base (soporta /, /zkt/backend/public/, etc.)
  const base = (() => {
    const url = new URL(window.location.href);
    let p = url.pathname.replace(/index\.html?$/i, '');
    if (!p.endsWith('/')) p += '/';
    return p;
  })();

  const api = (path) => `${base}api/${path.replace(/^\/?api\//, '')}`;

  // Manda cookies siempre y redirige al login en 401
  (function patchFetch() {
    const origFetch = window.fetch.bind(window);
    const isOnLogin = () => location.pathname.endsWith('/login.html');
    window.fetch = async (input, init = {}) => {
      init.credentials = init.credentials || 'include';
      init.headers = Object.assign({ Accept: 'application/json' }, init.headers || {});
      const res = await origFetch(input, init);
      if (res.status === 401 && !isOnLogin()) {
        window.location.href = '/login.html';
        throw new Error('No autenticado');
      }
      return res;
    };
  })();

  async function safeJson(res) {
    const ct = (res.headers.get('content-type') || '').toLowerCase();
    if (ct.includes('application/json')) return await res.json();
    const txt = await res.text();
    return { ok: false, error: txt || res.statusText };
  }

  async function fetchJSON(url, opts = {}) {
    const res = await fetch(url, {
      credentials: 'include',
      headers: { Accept: 'application/json', ...(opts.headers || {}) },
      ...opts,
    });
    if (res.status === 401 && !location.pathname.endsWith('/login.html')) {
      window.location.href = '/login.html';
      throw new Error('No autenticado');
    }
    return safeJson(res);
  }

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const formatNumber = (value) => {
    if (value === null || value === undefined) return '—';
    const num = Number(value);
    if (Number.isNaN(num)) return String(value);
    try {
      return num.toLocaleString('es-GT');
    } catch (_) {
      return num.toString();
    }
  };

  const formatDateTime = (value) => {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    try {
      return date.toLocaleString('es-GT', { dateStyle: 'medium', timeStyle: 'short' });
    } catch (_) {
      return date.toISOString();
    }
  };

  const formatRelativeTime = (value) => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const diff = date.getTime() - Date.now();
    const units = [
      { unit: 'day', ms: 86400000 },
      { unit: 'hour', ms: 3600000 },
      { unit: 'minute', ms: 60000 },
      { unit: 'second', ms: 1000 },
    ];
    try {
      const rtf = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });
      for (const { unit, ms } of units) {
        if (Math.abs(diff) >= ms || unit === 'second') {
          return rtf.format(Math.round(diff / ms), unit);
        }
      }
    } catch (_) {
      // noop
    }
    const minutes = Math.round(Math.abs(diff) / 60000);
    return diff < 0 ? `hace ${minutes} min` : `en ${minutes} min`;
  };

  const statusToClass = (status) => {
    const normalized = String(status ?? '').toLowerCase();
    if (['ok', 'online', 'success', 'healthy', 'operational'].includes(normalized)) return 'ok';
    if (['warn', 'warning', 'degraded', 'pending'].includes(normalized)) return 'warn';
    if (['error', 'down', 'offline', 'failed'].includes(normalized)) return 'danger';
    return 'neutral';
  };

  const PAGE_STORAGE_KEY = 'zkt:lastPage';
  const rememberPage = (page) => {
    if (!window.localStorage || !page) return;
    try {
      window.localStorage.setItem(PAGE_STORAGE_KEY, page);
    } catch (error) {
      /* ignore */
    }
  };
  const restorePage = () => {
    if (!window.localStorage) return null;
    try {
      return window.localStorage.getItem(PAGE_STORAGE_KEY);
    } catch (error) {
      return null;
    }
  };

  const setActive = (link) => {
    sidebarLinks.forEach((a) => a.classList.remove('active'));
    if (link) link.classList.add('active');
  };

  const AppCore = {
    elements: { app, sidebar, sidebarLinks },
    state,
    settingsState,
    hamachiSyncState,
    api,
    fetchJSON,
    safeJson,
    escapeHtml,
    formatNumber,
    formatDateTime,
    formatRelativeTime,
    statusToClass,
    setActive,
    pages,
    registerPage(name, renderer) {
      pages[name] = renderer;
    },
    getPage(name) {
      return pages[name];
    },
    nextRenderId() {
      state.renderGeneration += 1;
      return state.renderGeneration;
    },
    rememberPage,
    restorePage,
    async loadSettings(force = false) {
      if (!force) {
        if (settingsState.cache) return settingsState.cache;
        if (settingsState.promise) return settingsState.promise;
      }

      const request = fetchJSON(api('settings'))
        .then((resp) => {
          const settings = resp?.settings ?? resp?.data ?? resp ?? null;
          settingsState.cache = settings;
          return settings;
        })
        .catch((error) => {
          console.warn('No se pudo obtener la configuración', error);
          return settingsState.cache ?? null;
        })
        .finally(() => {
          if (settingsState.promise === request) {
            settingsState.promise = null;
          }
        });

      settingsState.promise = request;
      return request;
    },
    async safeLoadSettings(force = false) {
      try {
        return await this.loadSettings(force);
      } catch (_) {
        return settingsState.cache ?? null;
      }
    },
    async getTicketsSafe() {
      try {
        const res = await fetch(api('tickets'), { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('tickets http ' + res.status);
        return await safeJson(res);
      } catch (e) {
        console.warn('[tickets] error:', e);
        return { data: [] };
      }
    },
    normalizeTickets(resp) {
      const arr = Array.isArray(resp?.data)
        ? resp.data
        : Array.isArray(resp?.rows)
        ? resp.rows
        : Array.isArray(resp)
        ? resp
        : [];

      return arr.map((r) => ({
        name: r.name || r.person_name || r.plate || r.ticket_no || '(sin nombre)',
        checkIn: r.checkIn || r.check_in || r.entry_at || r.in_time || r.fecha_entrada || null,
        checkOut: r.checkOut || r.check_out || r.exit_at || r.out_time || r.fecha_salida || null,
      }));
    },
    buildTimeline(items) {
      if (!Array.isArray(items) || !items.length) {
        return '<div class="empty small mb-0">Sin eventos recientes.</div>';
      }
      return items
        .slice(0, 15)
        .map((item) => {
          const title = escapeHtml(item?.title ?? 'Evento');
          const subtitle = item?.subtitle ? `<div class="timeline-subtitle">${escapeHtml(item.subtitle)}</div>` : '';
          const timestamp = item?.timestamp ?? item?.date ?? item?.when ?? null;
          const meta = `<div class="timeline-meta"><span>${escapeHtml(formatDateTime(timestamp))}</span>${
            formatRelativeTime(timestamp) ? `<span class="timeline-relative">${escapeHtml(formatRelativeTime(timestamp))}</span>` : ''
          }</div>`;
          return `
          <div class="timeline-item">
            <div class="timeline-dot ${statusToClass(item?.status)}"></div>
            <div>
              <div class="timeline-title">${title}</div>
              ${subtitle}
              ${meta}
            </div>
          </div>`;
        })
        .join('');
    },
    async triggerHamachiSync({ silent = false, force = false } = {}) {
      if (hamachiSyncState.promise) {
        if (!force) {
          return hamachiSyncState.promise;
        }
        try {
          await hamachiSyncState.promise;
        } catch (error) {
          if (!silent) throw error;
          console.warn('No se pudo sincronizar los registros remotos', error);
        }
      }

      let taskPromise;
      const task = async () => {
        try {
          return await fetchJSON(api('sync/park-records/hamachi'), { method: 'POST' });
        } catch (error) {
          if (!silent) throw error;
          console.warn('No se pudo sincronizar los registros remotos', error);
          return null;
        } finally {
          if (hamachiSyncState.promise === taskPromise) {
            hamachiSyncState.promise = null;
          }
        }
      };

      taskPromise = task();
      hamachiSyncState.promise = taskPromise;
      return taskPromise;
    },
  };

  window.AppCore = AppCore;
})();
