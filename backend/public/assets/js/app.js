(() => {
  const app = document.getElementById('app');
  const sidebar = document.getElementById('appSidebar');
  const sidebarLinks = sidebar ? Array.from(sidebar.querySelectorAll('.nav-link')) : [];
  let currentPage = null;
  let renderGeneration = 0;

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

  function api(path) {
    // Siempre relativo a /public/
    return `${base}api/${path.replace(/^\/?api\//, '')}`;
  }

  function setActive(link) {
    sidebarLinks.forEach((a) => a.classList.remove('active'));
    if (link) {
      link.classList.add('active');
    }
  }
// --- parche global ---
// --- parche global ---
// Manda cookies siempre y redirige al login en 401,
// excepto si YA estás en /login.html para evitar bucle.
(function patchFetch(){
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

// Fallback lector de JSON
async function safeJson(res){
  const ct = (res.headers.get('content-type') || '').toLowerCase();
  if (ct.includes('application/json')) return await res.json();
  const txt = await res.text();
  return { ok:false, error: txt || res.statusText };
}

// Wrapper cómodo para tus peticiones
async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, {
    ...opts,
    credentials: 'include',                      // cookie de sesión
    headers: { 'Accept': 'application/json', ...(opts.headers || {}) },
  });
  if (res.status === 401 && !location.pathname.endsWith('/login.html')) {
    window.location.href = '/login.html';
    throw new Error('No autenticado');
  }
  return safeJson(res);
}


// Wrapper global que envía cookies y redirige al login si hay 401
async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, {
    credentials: 'include',                      // <-- ENVÍA LA COOKIE DE SESIÓN
    headers: { 'Accept': 'application/json', ...(opts.headers || {}) },
    ...opts,
  });

  if (res.status === 401) {
    // No autenticado -> manda al login
    window.location.href = '/login.html';
    throw new Error('No autenticado');
  }

  // Intenta parsear JSON
  let data = null;
  try { data = await res.json(); } catch { data = null; }

  if (!res.ok) {
    const msg = (data && (data.error || data.message)) || res.statusText;
    throw new Error(msg);
  }
  return data;
}



  const settingsState = { cache: null, promise: null };

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

  function updateChromeWithSettings(settings) {
    const envBadge = document.getElementById('envBadge');
    if (envBadge) {
      const env = settings?.app?.environment ?? 'unknown';
      envBadge.textContent = settings?.app?.environment_label ?? (settings ? env.toUpperCase() : 'Sin conexión');
      envBadge.dataset.status = settings ? env : 'offline';
    }

    if (settings?.app?.environment) {
      document.body.dataset.environment = settings.app.environment;
    } else {
      delete document.body.dataset.environment;
    }

    const subtitle = document.getElementById('heroSubtitle');
    if (subtitle) {
      const base = subtitle.getAttribute('data-default') || subtitle.textContent;
      subtitle.textContent = settings?.app?.name ? `${settings.app.name} • ${base}` : base;
    }

    const lastSyncValue = document.getElementById('heroLastSync');
    const lastSyncRelative = document.getElementById('heroLastSyncRelative');
    const lastSync = settings?.database?.metrics?.tickets_last_sync ?? null;
    if (lastSyncValue) lastSyncValue.textContent = formatDateTime(lastSync);
    if (lastSyncRelative) {
      const rel = formatRelativeTime(lastSync);
      lastSyncRelative.textContent = rel;
      lastSyncRelative.style.display = rel ? '' : 'none';
    }

    const metrics = settings?.database?.metrics ?? {};

    const heroInvoicesTotal = document.getElementById('heroInvoicesTotal');
    if (heroInvoicesTotal) {
      const invoicesTotal = metrics?.invoices_total;
      heroInvoicesTotal.textContent = invoicesTotal !== undefined && invoicesTotal !== null
        ? formatNumber(invoicesTotal)
        : '—';
    }

    const heroInvoicesLastSync = document.getElementById('heroInvoicesLastSync');
    if (heroInvoicesLastSync) {
      const lastInvoice = metrics?.invoices_last_sync ?? null;
      if (lastInvoice) {
        const rel = formatRelativeTime(lastInvoice);
        const timestamp = formatDateTime(lastInvoice);
        heroInvoicesLastSync.textContent = rel ? `Última factura ${rel}` : `Última factura ${timestamp}`;
        heroInvoicesLastSync.style.display = '';
      } else {
        heroInvoicesLastSync.textContent = 'Sin facturas registradas';
        heroInvoicesLastSync.style.display = '';
      }
    }

    const heroPendingInvoices = document.getElementById('heroPendingInvoices');
    const heroPendingDetail = document.getElementById('heroPendingDetail');
    if (heroPendingInvoices) {
      const pending = Number(metrics?.pending_invoices ?? 0);
      let tone = 'neutral';
      let label = 'Sin datos';
      if (settings) {
        if (!Number.isFinite(pending) || pending < 0) {
          label = 'Sin datos';
        } else if (pending === 0) {
          label = 'Sin pendientes';
          tone = 'ok';
        } else {
          label = `${formatNumber(pending)} por certificar`;
          tone = 'warn';
        }
      }
      heroPendingInvoices.textContent = label;
      heroPendingInvoices.className = 'hero-highlight-chip';
      heroPendingInvoices.dataset.tone = tone;
    }
    if (heroPendingDetail) {
      const pending = Number(metrics?.pending_invoices ?? 0);
      if (settings && Number.isFinite(pending)) {
        heroPendingDetail.textContent = pending > 0
          ? `Tickets listos para FEL: ${formatNumber(pending)}`
          : 'No hay tickets pendientes de certificación';
      } else {
        heroPendingDetail.textContent = 'Sincroniza para ver pendientes de certificación';
      }
    }

    const heroGeneratedAt = document.getElementById('heroGeneratedAt');
    if (heroGeneratedAt) {
      const generated = settings?.generated_at ?? settings?.app?.generated_at ?? null;
      const label = generated ? `${formatDateTime(generated)} (${formatRelativeTime(generated) || 'recién'})` : 'Sincronización no disponible';
      heroGeneratedAt.textContent = `Configuración actualizada: ${label}`;
    }
  }

  async function loadSettings(force = false) {
    if (!force) {
      if (settingsState.cache) return settingsState.cache;
      if (settingsState.promise) return settingsState.promise;
    }

    const request = fetchJSON(api('settings'))
      .then((resp) => {
        const settings = resp?.settings ?? null;
        settingsState.cache = settings;
        updateChromeWithSettings(settings);
        return settings;
      })
      .catch((error) => {
        console.warn('No se pudo obtener la configuración', error);
        if (!settingsState.cache) updateChromeWithSettings(null);
        return null;
      })
      .finally(() => {
        if (settingsState.promise === request) {
          settingsState.promise = null;
        }
      });

    settingsState.promise = request;
    return request;
  }

  const hamachiSyncState = { timer: null, promise: null };

  async function triggerHamachiSync({ silent = false, force = false } = {}) {
    if (hamachiSyncState.promise) {
      if (!force) {
        return hamachiSyncState.promise;
      }
      try {
        await hamachiSyncState.promise;
      } catch (error) {
        if (!silent) {
          throw error;
        }
        console.warn('No se pudo sincronizar los registros remotos', error);
      }
    }

    let taskPromise;
    const task = async () => {
      try {
        return await fetchJSON(api('sync/park-records/hamachi'), { method: 'POST' });
      } catch (error) {
        if (!silent) {
          throw error;
        }
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
  }

  function startHamachiSyncPolling() {
    if (hamachiSyncState.timer) {
      return;
    }

    const POLL_INTERVAL = 30000;

    const poll = async () => {
      try {
        const response = await triggerHamachiSync({ silent: true });
        if (response) {
          await loadSettings(true);
        }
      } catch (error) {
        console.warn('Error durante la sincronización automática de tickets remotos', error);
      }
    };

    hamachiSyncState.timer = window.setInterval(poll, POLL_INTERVAL);
    void poll();
  }

  function buildTimeline(items) {
    if (!Array.isArray(items) || !items.length) {
      return '<div class="empty small mb-0">Sin eventos recientes.</div>';
    }
    return items.map((item) => {
      const title = escapeHtml(item?.title ?? 'Evento');
      const subtitle = item?.subtitle ? `<div class="timeline-subtitle">${escapeHtml(item.subtitle)}</div>` : '';
      const timestamp = item?.timestamp ?? item?.date ?? item?.when ?? null;
      const meta = `<div class="timeline-meta"><span>${escapeHtml(formatDateTime(timestamp))}</span>${formatRelativeTime(timestamp) ? `<span class="timeline-relative">${escapeHtml(formatRelativeTime(timestamp))}</span>` : ''}</div>`;
      return `
        <div class="timeline-item">
          <div class="timeline-dot ${statusToClass(item?.status)}"></div>
          <div>
            <div class="timeline-title">${title}</div>
            ${subtitle}
            ${meta}
          </div>
        </div>
      `;
    }).join('');
  }

  startHamachiSyncPolling();
  loadSettings().catch(() => {});

  const refreshBtn = document.getElementById('refreshBtn');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', async () => {
      const active = currentPage || document.querySelector('.nav-link.active[data-page]')?.getAttribute('data-page') || 'dashboard';
      refreshBtn.classList.add('is-loading');
      refreshBtn.disabled = true;
      try {
        await loadSettings(true);
        await goToPage(active, { force: true });
      } catch (err) {
        console.error(err);
      } finally {
        setTimeout(() => {
          refreshBtn.classList.remove('is-loading');
          refreshBtn.disabled = false;
        }, 400);
      }
    });
  }

/* ============================
   Bootstrap de red y helpers
   ============================ */

// --- parche global ---
// Manda cookies siempre y redirige al login en 401,
// excepto si YA estás en /login.html para evitar bucle.
(function patchFetch(){
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

// Fallback lector de JSON (evita "Unexpected end of JSON input")
async function safeJson(res){
  const ct = (res.headers.get('content-type') || '').toLowerCase();
  if (ct.includes('application/json')) return await res.json();
  const txt = await res.text();
  return { ok:false, error: txt || res.statusText };
}

// Wrapper cómodo para tus peticiones (con cookies + 401 controlado)
async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, {
    ...opts,
    credentials: 'include',
    headers: { 'Accept': 'application/json', ...(opts.headers || {}) },
  });
  if (res.status === 401 && !location.pathname.endsWith('/login.html')) {
    window.location.href = '/login.html';
    throw new Error('No autenticado');
  }
  return safeJson(res);
}

// Helper api(path) (ajústalo si ya tienes uno)
function api(path=''){
  path = String(path || '').replace(/^\/+/, '');
  return `/api/${path}`;
}



/* ===================================
   Settings tolerante (caseta/admin)
   =================================== */

async function loadSettings(quiet = false) {
  try {
    const res = await fetch(api('settings'), { headers: { Accept: 'application/json' } });
    if (res.status === 401) throw new Error('No autenticado');
    if (!res.ok) {
      if (!quiet) console.warn('[settings] HTTP', res.status, '→ usando defaults');
      return { ok: false, database: { metrics: {} }, activity: [] };
    }
    const js = await safeJson(res);
    return js || { ok: false, database: { metrics: {} }, activity: [] };
  } catch (err) {
    if (!quiet) console.warn('[settings] error:', err);
    return { ok: false, database: { metrics: {} }, activity: [] };
  }
}

/* ===================================
   Tickets safe + normalización
   =================================== */

async function getTicketsSafe() {
  try {
    const res = await fetch(api('tickets'), { headers: { Accept:'application/json' }});
    if (!res.ok) throw new Error('tickets http '+res.status);
    return await safeJson(res);
  } catch (e) {
    console.warn('[tickets] error:', e);
    return { data: [] };
  }
}

function normalizeTickets(resp) {
  const arr = Array.isArray(resp?.data)
    ? resp.data
    : Array.isArray(resp?.rows)
    ? resp.rows
    : Array.isArray(resp)
    ? resp
    : [];

  return arr.map(r => ({
    // muestra algo útil aunque no haya nombre
    name:  r.name || r.person_name || r.plate || r.ticket_no || '(sin nombre)',
    checkIn:  r.checkIn || r.check_in || r.entry_at || r.in_time || r.fecha_entrada || null,
    checkOut: r.checkOut || r.check_out || r.exit_at  || r.out_time || r.fecha_salida  || null,
  }));
}

// Si tienes un buildTimeline propio, úsalo; si no, deja un fallback
function buildTimeline(activity) {
  const items = Array.isArray(activity) ? activity : [];
  if (!items.length) return '';
  return `
    <div class="list-group list-group-flush">
      ${items.slice(0,15).map(it => `
        <div class="list-group-item d-flex align-items-start gap-2">
          <div class="flex-grow-1">
            <div class="small">${escapeHtml(it.title || it.event || 'Evento')}</div>
            <div class="text-muted small">${escapeHtml(it.detail || '')}</div>
          </div>
          <div class="text-nowrap text-muted small">${escapeHtml(formatRelativeTime(it.when || it.date || it.at || new Date()))}</div>
        </div>
      `).join('')}
    </div>
  `;
}

/* ============================
   DASHBOARD (Bootstrap)
   ============================ */

async function renderDashboard() {
  try {
    // ¡No usar Promise.all con fetchJSON(settings), porque caseta no puede!
    const [ticketsResp, settings] = await Promise.all([
      getTicketsSafe(),
      loadSettings(),
    ]);

    const data = normalizeTickets(ticketsResp);
    const state = { search: '', page: 1 };
    const pageSize = 20;

    const metrics = settings?.database?.metrics ?? {};
    const pending = Number(metrics.pending_invoices ?? 0);

    const summaryCards = [
      {
        title: 'Asistencias de hoy',
        badge: { text: 'En vivo', variant: 'primary' },
        value: formatNumber(data.length),
        detail: 'Lecturas registradas en el día',
        accent: 'primary',
        icon: 'bi-people-fill',
      },
      {
        title: 'Tickets en base de datos',
        badge: { text: 'Histórico', variant: 'info' },
        value: formatNumber(metrics.tickets_total),
        detail: metrics.tickets_last_sync
          ? `Último registro ${formatRelativeTime(metrics.tickets_last_sync)}`
          : 'Sin registros almacenados',
        accent: 'info',
        icon: 'bi-database-fill',
      },
      {
        title: 'Facturas emitidas',
        badge: { text: 'FEL', variant: 'success' },
        value: formatNumber(metrics.invoices_total),
        detail: metrics.invoices_last_sync
          ? `Última emisión ${formatRelativeTime(metrics.invoices_last_sync)}`
          : 'Sin facturas emitidas',
        accent: 'success',
        icon: 'bi-receipt-cutoff',
      },
      {
        title: 'Pendientes por facturar',
        badge: { text: pending > 0 ? 'Atención' : 'Al día', variant: pending > 0 ? 'warning' : 'secondary' },
        value: formatNumber(pending),
        detail: pending > 0 ? 'Genera FEL desde Facturación' : 'Sin pendientes',
        accent: pending > 0 ? 'warning' : 'secondary',
        icon: pending > 0 ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill',
      },
    ];

    const summaryHtml = summaryCards
      .map((c) => `
        <div class="col">
          <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex flex-column">
              <div class="d-flex align-items-start justify-content-between">
                <span class="badge bg-${c.badge.variant}">${escapeHtml(c.badge.text)}</span>
                <i class="bi ${c.icon} fs-4 text-${c.accent}"></i>
              </div>
              <h6 class="mt-2 mb-1 text-muted">${escapeHtml(c.title)}</h6>
              <div class="fs-3 fw-semibold mb-1 text-${c.accent}">${escapeHtml(c.value)}</div>
              <div class="text-muted small">${escapeHtml(c.detail)}</div>
            </div>
          </div>
        </div>
      `).join('');

    // Layout principal (solo Bootstrap)
    const app = document.getElementById('app') || document.body;
    app.innerHTML = `
      <div class="container-fluid px-0">
        <!-- Resumen -->
        <div class="row row-cols-1 row-cols-sm-2 row-cols-xxl-4 g-3 mb-4">
          ${summaryHtml}
        </div>

        <div class="row g-4 align-items-stretch">
          <!-- Tabla -->
          <div class="col-xl-8">
            <div class="card h-100 shadow-sm">
              <div class="card-body d-flex flex-column gap-3 h-100">
                <div class="d-flex flex-wrap gap-2 align-items-start justify-content-between">
                  <div>
                    <h5 class="card-title mb-1">Asistencias registradas</h5>
                    <p class="text-muted small mb-0">Información consolidada desde la base de datos.</p>
                  </div>
                  <div class="ms-auto" style="max-width: 260px;">
                    <input type="search" id="dashSearch" class="form-control form-control-sm"
                          placeholder="Buscar ticket, placa o nombre..." aria-label="Buscar asistencia">
                  </div>
                </div>

                <div class="table-responsive flex-grow-1">
                  <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th style="width:72px">#</th>
                        <th>Nombre</th>
                        <th>Entrada</th>
                        <th>Salida</th>
                      </tr>
                    </thead>
                    <tbody id="dashBody">
                      <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                          Cargando registros…
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between pt-2">
                  <small class="text-muted" id="dashMeta"></small>
                  <div class="btn-group btn-group-sm" role="group" aria-label="Paginación de asistencias">
                    <button type="button" class="btn btn-outline-secondary" id="dashPrev">
                      <i class="bi bi-chevron-left"></i> Anterior
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="dashNext">
                      Siguiente <i class="bi bi-chevron-right"></i>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Actividad -->
          <div class="col-xl-4">
            <div class="card h-100 shadow-sm">
              <div class="card-body d-flex flex-column gap-3">
                <div class="d-flex align-items-start justify-content-between gap-2">
                  <div>
                    <h5 class="card-title mb-1">Actividad reciente</h5>
                    <p class="text-muted small mb-0">Últimos eventos sincronizados.</p>
                  </div>
                  <button class="btn btn-link btn-sm p-0" id="timelineRefresh">
                    <i class="bi bi-arrow-clockwise"></i> Actualizar
                  </button>
                </div>

                <div id="activityTimeline">
                  <div class="text-muted small">Cargando actividad…</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>`;

    // Timeline
    const timelineContainer = document.getElementById('activityTimeline');
    if (timelineContainer) {
      const tl = buildTimeline(settings?.activity);
      timelineContainer.innerHTML = tl || `<div class="text-muted small">Sin actividad reciente.</div>`;
    }

    // Botón de refresco de actividad
    const timelineRefresh = document.getElementById('timelineRefresh');
    if (timelineRefresh) {
      timelineRefresh.addEventListener('click', async () => {
        const original = timelineRefresh.innerHTML;
        timelineRefresh.disabled = true;
        timelineRefresh.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Actualizando…`;
        let hadError = false;
        try {
          await loadSettings(true);
          await renderDashboard();
        } catch (err) {
          hadError = true;
          console.error(err);
          timelineRefresh.disabled = false;
          timelineRefresh.innerHTML = `<i class="bi bi-exclamation-triangle me-1"></i> Reintentar`;
        } finally {
          if (!hadError && timelineRefresh.isConnected) {
            timelineRefresh.innerHTML = original;
          }
        }
      });
    }

    // Tabla + paginación
    const tbody = document.getElementById('dashBody');
    const meta = document.getElementById('dashMeta');
    const searchInput = document.getElementById('dashSearch');
    const prevBtn = document.getElementById('dashPrev');
    const nextBtn = document.getElementById('dashNext');

    function filterData() {
      if (!state.search) return data;
      const term = state.search.toLowerCase();
      return data.filter((row) =>
        Object.values(row).some((value) => String(value ?? '').toLowerCase().includes(term))
      );
    }

    function renderTable() {
      const filtered = filterData();
      const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
      if (state.page > totalPages) state.page = totalPages;

      const start = (state.page - 1) * pageSize;
      const pageItems = filtered.slice(start, start + pageSize);

      if (pageItems.length) {
        tbody.innerHTML = pageItems
          .map((row, index) => `
            <tr>
              <td>${escapeHtml(start + index + 1)}</td>
              <td>${escapeHtml(row.name)}</td>
              <td>${escapeHtml(row.checkIn || '-')}</td>
              <td>${escapeHtml(row.checkOut || '-')}</td>
            </tr>
          `)
          .join('');
      } else {
        const message =
          data.length && !filtered.length ? 'No se encontraron resultados' : 'Sin registros disponibles';
        tbody.innerHTML = `
          <tr>
            <td colspan="4" class="text-center text-muted py-4">
              ${escapeHtml(message)}
            </td>
          </tr>
        `;
      }

      if (filtered.length) {
        meta.textContent = `Mostrando ${start + 1} - ${Math.min(start + pageItems.length, filtered.length)} de ${filtered.length} registros`;
      } else if (data.length) {
        meta.textContent = 'No se encontraron resultados para la búsqueda actual';
      } else {
        meta.textContent = 'Sin registros para mostrar';
      }

      prevBtn.disabled = state.page <= 1 || !filtered.length;
      nextBtn.disabled = state.page >= totalPages || !filtered.length;
    }

    if (searchInput) {
      searchInput.addEventListener('input', (event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        renderTable();
      });
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', () => {
        if (state.page > 1) {
          state.page -= 1;
          renderTable();
        }
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        const totalPages = Math.max(1, Math.ceil(filterData().length / pageSize));
        if (state.page < totalPages) {
          state.page += 1;
          renderTable();
        }
      });
    }

    renderTable();
  } catch (e) {
    const app = document.getElementById('app') || document.body;
    app.innerHTML = `
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title text-danger mb-2">No se pudo cargar el dashboard</h5>
          <p class="text-muted">Intenta nuevamente en unos segundos. Si el problema persiste, revisa la conexión con la base de datos y las credenciales.</p>
          <pre class="small mb-0">${escapeHtml(String(e))}</pre>
        </div>
      </div>`;
  }
}

/* ============================
   Boot de la SPA
   ============================ */

(async () => {
  // Chequeo de sesión: si no hay, login
  const r = await fetch(api('auth/me')); // el parche global ya manda cookies
  if (r.status === 401) {
    if (!location.pathname.endsWith('/login.html')) location.href = '/login.html';
    return;
  }
  // Carga dashboard
  await renderDashboard();
})();

  // ===== Facturación (tabla + Facturar) — Bootstrap =====
  async function renderInvoices() {
    const settings = await loadSettings();
    const hourlyRateSetting = settings?.billing?.hourly_rate ?? null;

    // ——— helpers (mínimos) ———
    const debounce = (fn, ms = 500) => {
      let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    };
    
    async function lookupNit(nitRaw) {
      // Acepta dígitos o "CF"
      const nit = (nitRaw ?? '').toString().trim().toUpperCase();
      const q = nit === 'CF' ? 'CF' : nit.replace(/\D+/g, ''); // solo dígitos si no es CF
      const url = api(`g4s/lookup-nit?nit=${encodeURIComponent(q)}`);

      return await fetchJSON(url, { method: 'GET' });
    }


    app.innerHTML = `
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
            <div class="flex-grow-1">
              <h5 class="card-title mb-1">Facturación (BD → G4S)</h5>
              <p class="text-muted small mb-1">Lista tickets <strong>CLOSED</strong> con pagos (o monto) y <strong>sin factura</strong>.</p>
              <div class="text-muted small" id="invoiceHourlyRateHint"></div>
            </div>

            <div class="ms-auto d-flex flex-wrap align-items-center gap-2" style="max-width: 520px;">
              <input type="search" id="invSearch" class="form-control form-control-sm" placeholder="Buscar ticket..." aria-label="Buscar ticket pendiente" />
              <div class="btn-group btn-group-sm" role="group" aria-label="Aperturas manuales">
                <button type="button" class="btn btn-outline-warning" id="btnManualOpen" title="Abrir barrera de SALIDA (Apertura manual)">
                  <i class="bi bi-box-arrow-right me-1"></i> Salida
                </button>
                <button type="button" class="btn btn-outline-primary" id="btnManualOpenIn" title="Abrir barrera de ENTRADA (Apertura manual)">
                  <i class="bi bi-box-arrow-in-left me-1"></i> Entrada
                </button>
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Ticket / Placa</th>
                  <th>Fecha</th>
                  <th class="text-end">Total</th>
                  <th>UUID</th>
                  <th class="text-center">Acción</th>
                </tr>
              </thead>
              <tbody id="invRows">
                <tr>
                  <td colspan="5" class="text-muted text-center py-4">
                    <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                    Cargando…
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mt-3">
            <small class="text-muted" id="invMeta"></small>
            <div class="btn-group btn-group-sm" role="group" aria-label="Paginación de facturas">
              <button type="button" class="btn btn-outline-secondary" id="invPrev"><i class="bi bi-chevron-left"></i> Anterior</button>
              <button type="button" class="btn btn-outline-secondary" id="invNext">Siguiente <i class="bi bi-chevron-right"></i></button>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal de confirmación (Bootstrap-styled, controlado por JS) -->
      <div class="modal fade" id="invoiceConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <form class="modal-content" id="invoiceConfirmForm">
            <div class="modal-header">
              <h5 class="modal-title">Confirmar cobro</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar" data-action="cancel"></button>
            </div>
            <div class="modal-body">
              <div class="border rounded p-2 mb-3 bg-light">
                <div class="d-flex justify-content-between small mb-1">
                  <span class="text-muted">Ticket</span><span id="mSumTicket">—</span>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                  <span class="text-muted">Fecha</span><span id="mSumFecha">—</span>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                  <span class="text-muted">Horas registradas</span><span id="mSumHoras">—</span>
                </div>
                <div class="d-flex justify-content-between small">
                  <span class="text-muted">Total en BD</span><span id="mSumTotalDb">—</span>
                </div>
                <div class="d-flex justify-content-between small mt-1" id="mSumTotalHourlyRow" hidden>
                  <span class="text-muted">Total por hora</span><span id="mSumTotalHourly">—</span>
                </div>
              </div>

              <div class="form-check">
                <input class="form-check-input" type="radio" name="billingMode" id="mModeHourly" value="hourly">
                <label class="form-check-label" for="mModeHourly">
                  Cobro por hora <strong class="ms-1" id="mHourlyLabel"></strong>
                </label>
                <div class="form-text" id="mHourlyHelp">Define una tarifa por hora en Ajustes para habilitar esta opción.</div>
              </div>

              <div class="form-check mt-2">
                <input class="form-check-input" type="radio" name="billingMode" id="mModeGrace" value="grace">
                <label class="form-check-label" for="mModeGrace">
                  Ticket de gracia <strong class="ms-1">Q0.00</strong>
                </label>
                <div class="form-text">No se cobra, no se envía a FEL; se registra en BD y se notifica a PayNotify.</div>
              </div>

              <div class="form-check mt-2">
                <input class="form-check-input" type="radio" name="billingMode" id="mModeCustom" value="custom">
                <label class="form-check-label" for="mModeCustom">
                  Cobro personalizado
                </label>
                <div class="form-text">Indica el total que deseas facturar manualmente.</div>
              </div>

              <div class="input-group input-group-sm mt-2">
                <span class="input-group-text">Q</span>
                <input type="number" step="0.01" min="0" class="form-control" id="mCustomInput" placeholder="0.00" disabled>
              </div>

              <div class="mt-3">
                <label for="mNit" class="form-label mb-1">NIT del cliente</label>
                <input
                  type="text"
                  id="mNit"
                  class="form-control"
                  placeholder='Escribe el NIT o "CF"'
                  value="CF"
                  autocomplete="off"
                  inputmode="numeric"
                  aria-describedby="mNitHelp mNitStatus"
                >
                <div class="form-text" id="mNitHelp">Escribe el NIT o “CF” (consumidor final). Si ingresas NIT, se consultará en SAT (G4S).</div>
                <div class="small mt-1 text-muted" id="mNitStatus" aria-live="polite"></div>
              </div>

              <div class="text-danger small mt-2" id="mError" hidden></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary btn-sm" data-action="cancel">Cancelar</button>
              <button type="submit" class="btn btn-primary btn-sm">Confirmar</button>
            </div>
          </form>
        </div>
      </div>
    `;

    // --------- refs
    const tbody = document.getElementById('invRows');
    const searchInput = document.getElementById('invSearch');
    const meta = document.getElementById('invMeta');
    const prevBtn = document.getElementById('invPrev');
    const nextBtn = document.getElementById('invNext');
    const hourlyRateHint = document.getElementById('invoiceHourlyRateHint');

    const state = { search: '', page: 1 };
    const pageSize = 20;
    let allRows = [];

    // --------- Botones apertura manual (Bootstrap buttons, mismo backend)
    const manualOpenBtn   = document.getElementById('btnManualOpen');    // Salida
    const manualOpenInBtn = document.getElementById('btnManualOpenIn'); // Entrada
    const CHANNEL_SALIDA  = '40288048981adc4601981b7cb2660b05';
    const CHANNEL_ENTRADA = '40288048981adc4601981b7c2d010aff';

    function wireManualOpen(buttonEl, { title, channelId }) {
      if (!buttonEl || buttonEl.dataset.bound === '1') return;
      buttonEl.dataset.bound = '1';
      let busy = false;

      buttonEl.addEventListener('click', async () => {
        if (busy) return;
        const proceed = await (Dialog?.confirm?.(
          `¿Deseas realizar una APERTURA MANUAL de la barrera (${title})?`,
          'Confirmar apertura'
        ) ?? Promise.resolve(window.confirm(`¿Aperturar barrera manual (${title})?`)));
        if (!proceed) return;

        let reason =
          (await (Dialog?.prompt?.({
            title: `Motivo de apertura (${title})`,
            label: 'Describe brevemente el motivo (mín. 5 caracteres):',
            placeholder: 'Ej. Emergencia, fallo del lector, visita autorizada, etc.',
            confirmText: 'Continuar',
            cancelText: 'Cancelar'
          }) ?? Promise.resolve(window.prompt(`Motivo de apertura (${title}):`)))) || '';

        reason = (reason || '').trim();
        if (reason.length < 5) { (Dialog?.ok?.('El motivo debe tener al menos 5 caracteres.', 'Motivo inválido')) || alert('Motivo muy corto.'); return; }
        if (reason.length > 255) { (Dialog?.ok?.('El motivo no debe superar 255 caracteres.', 'Motivo demasiado largo')) || alert('Motivo muy largo.'); return; }

        busy = true;
        buttonEl.disabled = true;
        const original = buttonEl.innerHTML;
        buttonEl.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Aperturando…`;
        const m = Dialog?.loading?.({ title: `Apertura manual (${title})`, message: 'Contactando servicio…' });

        try {
          const res = await fetchJSON(api('gate/manual-open'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reason, channel_id: channelId })
          });

          if (!res.ok) {
            if (res.debug) console.warn('gate/manual-open debug:', res.debug);
            throw new Error(res.message || (res.field === 'reason' ? 'Motivo inválido.' : 'No se pudo aperturar'));
          }

          (Dialog?.ok?.(
            [
              `Apertura manual (${title}) ejecutada.`,
              res.opened_at ? `\nHora: ${res.opened_at}` : '',
              res.code !== undefined ? `\nCódigo: ${res.code}` : '',
              res.message ? `\nMensaje: ${res.message}` : '',
              `\nMotivo registrado: ${reason}`
            ].join(''),
            `Apertura manual (${title})`
          )) || alert(`Apertura manual (${title}) ejecutada.`);
        } catch (e) {
          Dialog?.err?.(e, `Error en apertura manual (${title})`) || alert(`Error: ${e.message}`);
        } finally {
          if (m && typeof m.close === 'function') m.close();
          buttonEl.disabled = false;
          buttonEl.innerHTML = original;
          busy = false;
        }
      });
    }

    wireManualOpen(manualOpenBtn,   { title: 'Salida',  channelId: CHANNEL_SALIDA });
    wireManualOpen(manualOpenInBtn, { title: 'Entrada', channelId: CHANNEL_ENTRADA });

    // --------- util
    const formatCurrency = (value) => {
      if (value === null || value === undefined || value === '') return '—';
      const num = Number(value);
      if (!Number.isFinite(num)) return '—';
      try {
        return num.toLocaleString('es-GT', { style: 'currency', currency: 'GTQ' });
      } catch {
        return `Q${num.toFixed(2)}`;
      }
    };

    if (hourlyRateHint) {
      const hourlyNumber = Number(hourlyRateSetting);
      hourlyRateHint.textContent =
        Number.isFinite(hourlyNumber) && hourlyNumber > 0
          ? `Tarifa por hora ${formatCurrency(hourlyNumber)}.`
          : 'Configura una tarifa por hora en Ajustes para habilitar los cálculos automáticos.';
    }

    function filterRows() {
      if (!state.search) return allRows;
      const term = state.search.toLowerCase();
      return allRows.filter((row) =>
        Object.values(row).some((value) => String(value ?? '').toLowerCase().includes(term))
      );
    }

    const buildPayload = (row) => ({
      ticket_no: row.ticket_no,
      plate: row.plate,
      receptor_nit: row.receptor || 'CF',
      serie: row.serie || 'A',
      numero: row.numero || null,
      fecha: row.fecha ?? null,
      total: row.total ?? null,
      hours: row.hours ?? row.duration_minutes ?? null,
      duration_minutes: row.duration_minutes ?? null,
      entry_at: row.entry_at ?? null,
      exit_at: row.exit_at ?? null,
    });

    // --------- Modal de confirmación (con validación NIT y búsqueda automática)
    function openInvoiceConfirmation(ticket, billingConfig = {}) {
      return new Promise((resolve) => {
        // cálculos
        const hoursValue   = typeof ticket.hours === 'number' ? ticket.hours : Number(ticket.hours);
        const hours        = Number.isFinite(hoursValue) && hoursValue > 0 ? Number(hoursValue.toFixed(2)) : null;
        const minutesValue = typeof ticket.duration_minutes === 'number' ? ticket.duration_minutes : Number(ticket.duration_minutes);
        const minutes      = Number.isFinite(minutesValue) && minutesValue > 0 ? Math.round(minutesValue) : null;

        const hourlyRateNumber = Number(billingConfig?.hourly_rate ?? null);
        const hourlyRate = Number.isFinite(hourlyRateNumber) && hourlyRateNumber > 0 ? hourlyRateNumber : null;

        const canHourly   = hourlyRate !== null && hours !== null;
        const hourlyTotal = canHourly ? Math.round(hours * hourlyRate * 100) / 100 : null;

        const totalFromDb = ticket.total != null && ticket.total !== '' ? Number(ticket.total) : null;
        const normalizedDbTotal = Number.isFinite(totalFromDb) ? Number(totalFromDb.toFixed(2)) : null;

        const customDefault = hourlyTotal ?? normalizedDbTotal;
        const customValue = customDefault != null ? customDefault.toFixed(2) : '';
        const dateCandidate = ticket.exit_at || ticket.entry_at || ticket.fecha || null;

        // refs modal
        const modalEl  = document.getElementById('invoiceConfirmModal');
        const form     = document.getElementById('invoiceConfirmForm');
        const mErr     = document.getElementById('mError');
        const mCustom  = document.getElementById('mCustomInput');
        const mHourly  = document.getElementById('mModeHourly');
        const mGrace   = document.getElementById('mModeGrace');
        const mCustomR = document.getElementById('mModeCustom');
        const mNit     = document.getElementById('mNit');
        const mNitHelp = document.getElementById('mNitHelp');
        const mNitStatus = document.getElementById('mNitStatus');
        const mHourlyLabel = document.getElementById('mHourlyLabel');
        const mHourlyHelp  = document.getElementById('mHourlyHelp');

        // resumen
        document.getElementById('mSumTicket').textContent   = String(ticket.ticket_no ?? '');
        document.getElementById('mSumFecha').textContent    = dateCandidate ? `${formatDateTime(dateCandidate)} (${formatRelativeTime(dateCandidate)})` : '—';
        document.getElementById('mSumHoras').textContent    = hours != null ? `${hours.toFixed(2)} h${minutes && minutes % 60 ? ` (${minutes} min)` : ''}` : '—';
        document.getElementById('mSumTotalDb').textContent  = normalizedDbTotal != null ? formatCurrency(normalizedDbTotal) : '—';

        const totalHourlyRow = document.getElementById('mSumTotalHourlyRow');
        if (hourlyTotal != null) {
          totalHourlyRow.hidden = false;
          document.getElementById('mSumTotalHourly').textContent = formatCurrency(hourlyTotal);
          mHourlyLabel.textContent = formatCurrency(hourlyTotal);
          mHourlyHelp.textContent  = `Tarifa ${hourlyRate != null ? formatCurrency(hourlyRate) : '—'} × ${hours != null ? `${hours.toFixed(2)} h` : '—'}.`;
        } else {
          totalHourlyRow.hidden = true;
          mHourlyLabel.textContent = '';
          mHourlyHelp.textContent  = 'Define una tarifa por hora en Ajustes para habilitar esta opción.';
        }

        // estado inicial radios
        mHourly.disabled = !canHourly;
        if (canHourly) { mHourly.checked = true; mCustom.disabled = true; }
        else           { mGrace.checked = true; mCustom.disabled = true; }
        mCustom.value = customValue;
        mNit.value = 'CF';
        mErr.hidden = true; mErr.textContent = '';
        mNitStatus.className = 'small mt-1 text-muted'; mNitStatus.textContent = 'Consumidor final (CF).';

        // ——— NIT: sanitizar + lookup automático ———
        const normalizeNit = (v) => {
          v = (v || '').toUpperCase().trim();
          if (v === 'CF' || v === 'C') return v.length === 1 ? 'C' : 'CF';
          return v.replace(/\D+/g, ''); // solo dígitos
        };
        const setNitStatus = (text, cls = 'text-muted') => {
          mNitStatus.className = `small mt-1 ${cls}`;
          mNitStatus.textContent = text || '';
        };

        const doLookup = debounce(async () => {
          const v = mNit.value; // <-- sin toUpper aquí; normaliza lookupNit()
          if (!v || v.toUpperCase() === 'CF') {
            setNitStatus('Consumidor final (CF).', 'text-muted');
            return;
          }
          if (!/\d{6,}/.test(v.replace(/\D+/g,''))) {
            setNitStatus('Ingresa al menos 6 dígitos para consultar.', 'text-muted');
            return;
          }
          try {
            setNitStatus('Buscando en SAT…', 'text-info');
            const res = await lookupNit(v);
            if (res?.ok) {
              const nombre = (res.nombre || res.name || '').trim();
              const dir = (res.direccion || res.address || '').trim();
              setNitStatus(`Encontrado: ${nombre || '(sin nombre)'}${dir ? ' — ' + dir : ''}`, 'text-success');
            } else {
              setNitStatus(res?.error ? `No encontrado: ${res.error}` : 'NIT no encontrado.', 'text-warning');
            }
          } catch (err) {
            setNitStatus(`Error al consultar: ${err.message || err}`, 'text-danger');
          }
        }, 500);

        mNit.addEventListener('input', (e) => {
          const cur = e.target.value;
          const norm = normalizeNit(cur);
          if (norm !== cur) {
            e.target.value = norm;
            e.target.setSelectionRange(norm.length, norm.length);
          }
          doLookup();
        });
        mNit.addEventListener('keydown', (e) => {
          const k = e.key;
          const ctrl = e.ctrlKey || e.metaKey;
          if (ctrl || ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End'].includes(k)) return;
          if (/^[a-zA-Z]$/.test(k)) { if (!['c','f','C','F'].includes(k)) e.preventDefault(); return; }
          if (/^\d$/.test(k)) return;
          e.preventDefault();
        });

        // activar/desactivar input custom
        function updateCustomState() {
          const isCustom = document.getElementById('mModeCustom').checked;
          mCustom.disabled = !isCustom;
          if (isCustom && !mCustom.value) mCustom.value = customValue;
          mErr.hidden = true; mErr.textContent = '';
        }
        mHourly.addEventListener('change', updateCustomState);
        mGrace.addEventListener('change', updateCustomState);
        mCustomR.addEventListener('change', updateCustomState);

        // cerrar
        function closeModal(retVal = null) {
          modalEl.classList.remove('show');
          modalEl.style.display = 'none';
          const oldBackdrop = document.querySelector('.modal-backdrop');
          if (oldBackdrop) oldBackdrop.remove();
          document.body.classList.remove('modal-open');
          document.body.style.removeProperty('padding-right');
          resolve(retVal);
        }
        form.querySelectorAll('[data-action="cancel"]').forEach((b) => {
          b.addEventListener('click', () => closeModal(null), { once: true });
        });
        modalEl.addEventListener('click', (ev) => { if (ev.target === modalEl) closeModal(null); });
        function onEsc(ev) { if (ev.key === 'Escape') { ev.preventDefault(); closeModal(null); document.removeEventListener('keydown', onEsc); } }
        document.addEventListener('keydown', onEsc, { once: true });

        // submit
        form.onsubmit = (e) => {
          e.preventDefault();
          mErr.hidden = true; mErr.textContent = '';
          const selected = form.querySelector('input[name="billingMode"]:checked')?.value;
          if (!selected) { mErr.textContent = 'Selecciona el tipo de cobro.'; mErr.hidden = false; return; }

          const rawNit = mNit.value.trim().toUpperCase();
          const receptorNit = (rawNit === 'CF' || rawNit === '') ? 'CF' : rawNit.replace(/\D+/g, '');
          if (receptorNit !== 'CF' && !/^\d{6,}$/.test(receptorNit)) {
            mErr.textContent = 'NIT inválido. Debe ser CF o solo dígitos (mín. 6).';
            mErr.hidden = false; return;
          }

          if (selected === 'hourly') {
            if (!canHourly || hourlyTotal == null) { mErr.textContent = 'Configura una tarifa por hora válida en Ajustes para usar esta opción.'; mErr.hidden = false; return; }
            closeModal({ mode: 'hourly', total: hourlyTotal, label: `Cobro por hora ${formatCurrency(hourlyTotal)}`, receptor_nit: receptorNit });
            return;
          }
          if (selected === 'grace') {
            closeModal({ mode: 'grace', total: 0, label: 'Ticket de gracia (Q0.00, sin FEL)', receptor_nit: receptorNit });
            return;
          }
          const customVal = parseFloat(mCustom.value);
          if (!Number.isFinite(customVal) || customVal <= 0) { mErr.textContent = 'Ingresa un total personalizado mayor a cero.'; mErr.hidden = false; return; }
          const normalized = Math.round(customVal * 100) / 100;
          closeModal({ mode: 'custom', total: normalized, label: `Cobro personalizado ${formatCurrency(normalized)}`, receptor_nit: receptorNit });
        };

        // mostrar modal con backdrop estilo Bootstrap
        modalEl.style.display = 'block';
        setTimeout(() => modalEl.classList.add('show'), 10);
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(backdrop);
        document.body.classList.add('modal-open');
      });
    }

    // --------- handlers de la tabla (igual que tu versión)
    function attachInvoiceHandlers() {
      tbody.querySelectorAll('[data-action="invoice"]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const payload = JSON.parse(decodeURIComponent(btn.getAttribute('data-payload')));
          btn.disabled = true;
          const originalHTML = btn.innerHTML;
          btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Confirmando…`;
          try {
            const settingsSnapshot = await loadSettings();
            const confirmation = await openInvoiceConfirmation(payload, settingsSnapshot?.billing ?? {});
            if (!confirmation) { btn.disabled = false; btn.innerHTML = originalHTML; return; }

            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Enviando…`;

            const receptorNit = confirmation.receptor_nit || payload.receptor_nit || 'CF';
            const requestPayload = {
              ticket_no: payload.ticket_no,
              plate: payload.plate,
              receptor_nit: receptorNit,
              serie: payload.serie || 'A',
              numero: payload.numero || null,
              mode: confirmation.mode,
              total: Number(confirmation.total) || 0,
            };
            if (confirmation.mode === 'custom') requestPayload.custom_total = Number(confirmation.total);

            const js = await fetchJSON(api('fel/invoice'), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(requestPayload),
            });

            if (!js.ok) throw new Error(js.error || 'No se pudo certificar.');

            const uuidTxt = js.uuid ? `UUID ${js.uuid}` : 'Sin UUID (revise respuesta)';
            const reason =
              Number(js.billing_amount) <= 0
                ? 'Dato de billing = 0.'
                : (js.pay_notify_ack === false
                    ? (js.pay_notify_error ? `PayNotify sin confirmación (${js.pay_notify_error}).` : 'PayNotify sin confirmación.')
                    : null);

            if (js.manual_open) {
              (Dialog?.ok?.(
                [
                  'Pago registrado (apertura manual requerida).',
                  uuidTxt,
                  reason ? `Motivo: ${reason}` : null,
                  '',
                  'La barrera debe aperturarse manualmente.'
                ].filter(Boolean).join('\n'),
                'Apertura manual'
              )) || alert(`Pago registrado. ${uuidTxt}`);
            } else {
              (Dialog?.ok?.(
                [
                  `Factura enviada (${confirmation.label}).`,
                  uuidTxt,
                  js.pay_notify_ack ? 'Notificación a ZKBio confirmada.' : null
                ].filter(Boolean).join(' '),
                'FEL enviado'
              )) || alert(`Factura enviada. ${uuidTxt}`);
            }

            await loadList();
          } catch (e) {
            Dialog?.err?.(e, 'Error al facturar') || alert(`Error: ${e.message}`);
          } finally {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
          }
        });
      });
    }

    function renderPage() {
      const filtered = filterRows();
      const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
      if (state.page > totalPages) state.page = totalPages;

      const start = (state.page - 1) * pageSize;
      const pageRows = filtered.slice(start, start + pageSize);

      if (!pageRows.length) {
        const message = allRows.length && !filtered.length
          ? 'No se encontraron resultados'
          : 'No hay pendientes por facturar.';
        tbody.innerHTML = `
          <tr>
            <td colspan="5" class="text-muted text-center py-4">${escapeHtml(message)}</td>
          </tr>`;
      } else {
        tbody.innerHTML = pageRows.map((d) => {
          const totalFmt = formatCurrency(d.total);
          const payload = encodeURIComponent(JSON.stringify(buildPayload(d)));
          const disabled = d.uuid ? 'disabled' : '';
          const ticketText = d.ticket_no ? `${d.ticket_no}${d.plate ? ' · ' + d.plate : ''}` : (d.plate ?? '(sin placa)');
          return `
            <tr>
              <td>${escapeHtml(ticketText)}</td>
              <td>${escapeHtml(d.fecha ?? '')}</td>
              <td class="text-end">${totalFmt}</td>
              <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(d.uuid ?? '')}</td>
              <td class="text-center">
                <button class="btn btn-sm btn-outline-success" data-action="invoice" data-payload="${payload}" ${disabled}>
                  <i class="bi bi-receipt me-1"></i> Facturar
                </button>
              </td>
            </tr>`;
        }).join('');
        attachInvoiceHandlers();
      }

      if (filtered.length) {
        meta.textContent = `Mostrando ${start + 1} - ${Math.min(start + pageRows.length, filtered.length)} de ${filtered.length} tickets`;
      } else if (allRows.length) {
        meta.textContent = 'No se encontraron resultados para la búsqueda actual';
      } else {
        meta.textContent = 'Sin tickets pendientes por facturar';
      }

      prevBtn.disabled = state.page <= 1 || !filtered.length;
      nextBtn.disabled = state.page >= totalPages || !filtered.length;
    }

    // --------- eventos de búsqueda y paginación
    searchInput.addEventListener('input', (event) => {
      state.search = event.target.value.trim();
      state.page = 1;
      renderPage();
    });
    prevBtn.addEventListener('click', () => { if (state.page > 1) { state.page -= 1; renderPage(); } });
    nextBtn.addEventListener('click', () => {
      const totalPages = Math.max(1, Math.ceil(filterRows().length / pageSize));
      if (state.page < totalPages) { state.page += 1; renderPage(); }
    });

    // --------- carga inicial
    async function loadList() {
      tbody.innerHTML = `
        <tr>
          <td colspan="5" class="text-muted text-center py-4">
            <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
            Consultando BD…
          </td>
        </tr>`;
      meta.textContent = '';
      try {
        const js = await fetchJSON(api('facturacion/list'));
        if (!js.ok && js.error) throw new Error(js.error);
        allRows = js.rows || [];
        state.page = 1;
        renderPage();
      } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-danger text-center">Error: ${escapeHtml(e.message)}</td></tr>`;
        meta.textContent = '';
      }
    }
    await loadList();
  }

  // ===== Reportes =====
  async function renderReports() {
    const today = new Date();
    const toISODate = (d) => d.toISOString().slice(0, 10);
    const defaultTo = toISODate(today);
    const defaultFrom = toISODate(new Date(today.getTime() - 6 * 24 * 60 * 60 * 1000));

    const ticketState = {
      rows: [],
      summary: null,
      generatedAt: null,
      filters: {
        from: defaultFrom,
        to: defaultTo,
        status: 'ANY',
        plate: '',
        nit: '',
        min_total: '',
        max_total: '',
      },
    };

    const invoiceState = {
      rows: [],
      summary: null,
      generatedAt: null,
      filters: {
        from: defaultFrom,
        to: defaultTo,
        status: 'ANY',
        nit: '',
        uuid: '',
      },
    };

    const escapeHtml = (value) => String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

    const formatDateValue = (value, withTime = true) => {
      if (!value) return '—';
      const normalised = typeof value === 'string' && value.includes('T')
        ? value
        : typeof value === 'string' ? value.replace(' ', 'T') : value;
      const dt = new Date(normalised);
      if (Number.isNaN(dt.getTime())) return value;
      return withTime ? dt.toLocaleString('es-GT') : dt.toLocaleDateString('es-GT');
    };

    const formatDuration = (minutes) => {
      if (minutes === null || minutes === undefined || Number.isNaN(minutes)) return '—';
      const totalMinutes = Math.max(0, Number(minutes));
      const hours = Math.floor(totalMinutes / 60);
      const mins = Math.round(totalMinutes % 60);
      if (hours === 0) return `${mins} min`;
      return `${hours} h ${mins.toString().padStart(2, '0')} min`;
    };

    const formatCurrency = (amount) => {
      try {
        return new Intl.NumberFormat('es-GT', { style: 'currency', currency: 'GTQ' }).format(Number(amount ?? 0));
      } catch (_) {
        return `Q ${Number(amount ?? 0).toFixed(2)}`;
      }
    };

    const formatTicketStatus = (status) => {
      switch (String(status ?? '').toUpperCase()) {
        case 'CLOSED': return 'Cerrado';
        case 'OPEN': return 'Abierto';
        default: return status || '—';
      }
    };

    const formatInvoiceStatus = (status) => {
      switch (String(status ?? '').toUpperCase()) {
        case 'OK': return 'Certificada';
        case 'PENDING': return 'Pendiente';
        case 'ERROR': return 'Error';
        default: return status || '—';
      }
    };

    const downloadHtml = (filename, html) => {
      const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    };

    const openPrintWindow = (html) => {
      const w = window.open('', '_blank');
      if (!w) {
        Dialog.warn('Permite ventanas emergentes para mostrar el reporte.', 'Ventana bloqueada');
        return;
      }
      w.document.write(html);
      w.document.close();
    };

    const exportCsv = (filename, headers, rows) => {
      if (!rows.length) {
        Dialog.info('No hay datos para exportar', 'Exportación CSV')
        return;
      }
      const headerLine = headers.map((h) => `"${h.label}"`).join(',');
      const lines = rows.map((row) => headers.map((h) => {
        const raw = h.accessor(row);
        const text = raw === null || raw === undefined ? '' : String(raw);
        return `"${text.replace(/"/g, '""')}"`;
      }).join(','));
      const blob = new Blob([headerLine, ...lines].join('\n'), { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    };

    // ==== Reportes HTML con Bootstrap ====

    const BOOTSTRAP_HEAD = `
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <style>
        body { color: #0f172a; }
        .chip { display: inline-block; padding: .25rem .5rem; border-radius: 50rem; font-size: .8rem; }
        .chip-info { background: #e7f1ff; color: #0a58ca; }
        .chip-muted { background: #e9ecef; color: #6c757d; }
        @media print {
          .no-print { display: none !important; }
          body { margin: 6mm; }
        }
        /* Ajuste de tabla en impresión */
        table { font-size: .86rem; }
        th { white-space: nowrap; }
      </style>
    `;

    const buildTicketReportHtml = (printable = false) => {
      const summary = ticketState.summary;
      const rows = ticketState.rows;
      const filters = ticketState.filters;
      const generated = ticketState.generatedAt ? formatDateValue(ticketState.generatedAt) : formatDateValue(new Date().toISOString());

      const filterLabels = {
        from: 'Desde',
        to: 'Hasta',
        status: 'Estado',
        plate: 'Placa',
        nit: 'NIT receptor',
        min_total: 'Mínimo',
        max_total: 'Máximo',
      };

      const filterItems = Object.entries(filters)
        .filter(([, value]) => value && value !== 'ANY')
        .map(([key, value]) => `<span class="badge text-bg-light me-2 mb-2"><strong class="me-1">${filterLabels[key] ?? key}:</strong> ${escapeHtml(value)}</span>`)
        .join('') || '<span class="text-muted">Sin filtros aplicados</span>';

      const summaryCards = summary ? `
        <div class="row g-3 my-3">
          <div class="col-sm-6 col-lg-3">
            <div class="card border-0 bg-body-tertiary h-100">
              <div class="card-body py-3">
                <div class="text-muted small">Tickets encontrados</div>
                <div class="fs-5 fw-semibold">${escapeHtml(summary.total_tickets ?? 0)}</div>
              </div>
            </div>
          </div>
          <div class="col-sm-6 col-lg-3">
            <div class="card border-0 bg-body-tertiary h-100">
              <div class="card-body py-3">
                <div class="text-muted small">Monto total</div>
                <div class="fs-5 fw-semibold">${escapeHtml(formatCurrency(summary.total_amount ?? 0))}</div>
              </div>
            </div>
          </div>
          <div class="col-sm-6 col-lg-3">
            <div class="card border-0 bg-body-tertiary h-100">
              <div class="card-body py-3">
                <div class="text-muted small">Con pagos</div>
                <div class="fs-5 fw-semibold">${escapeHtml(`${summary.with_payments ?? 0} / ${summary.total_tickets ?? 0}`)}</div>
              </div>
            </div>
          </div>
          <div class="col-sm-6 col-lg-3">
            <div class="card border-0 bg-body-tertiary h-100">
              <div class="card-body py-3">
                <div class="text-muted small">Estadía promedio</div>
                <div class="fs-5 fw-semibold">${escapeHtml(summary.average_minutes !== null && summary.average_minutes !== undefined ? formatDuration(summary.average_minutes) : '—')}</div>
              </div>
            </div>
          </div>
        </div>
      ` : '';

      const statusBreakdown = summary && summary.status_breakdown
        ? Object.entries(summary.status_breakdown).map(([k, v]) => `<span class="badge rounded-pill text-bg-info me-2 mb-2">${escapeHtml(formatTicketStatus(k))}: ${escapeHtml(v)}</span>`).join('')
        : '';

      const tableRows = rows.length ? rows.map((row) => `
        <tr>
          <td>${escapeHtml(row.plate)}</td>
          <td>${escapeHtml(row.receptor_nit ?? 'CF')}</td>
          <td>${escapeHtml(formatTicketStatus(row.status))}</td>
          <td>${escapeHtml(formatDateValue(row.entry_at))}</td>
          <td>${escapeHtml(formatDateValue(row.exit_at))}</td>
          <td>${escapeHtml(formatDuration(row.duration_min))}</td>
          <td class="text-end">${escapeHtml(formatCurrency(row.total))}</td>
          <td>
            ${(row.payments || []).length ? row.payments.map((p) => `
              <div class="small mb-1">
                <strong>${escapeHtml(formatCurrency(p.amount))}</strong>
                <div class="text-muted">${escapeHtml(p.method || 'Método no indicado')}</div>
                <div class="text-muted">${escapeHtml(formatDateValue(p.paid_at))}</div>
              </div>
            `).join('') : '<span class="badge text-bg-light">Sin pagos</span>'}
          </td>
        </tr>
      `).join('') : `
        <tr>
          <td colspan="8" class="text-center text-muted py-4">No hay datos para mostrar.</td>
        </tr>
      `;

      return `<!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="utf-8" />
        <title>Reporte de tickets</title>
        ${BOOTSTRAP_HEAD}
      </head>
      <body class="p-4">
        <header class="mb-3">
          <h1 class="h3 mb-1">Reporte de tickets</h1>
          <p class="text-muted mb-0">Integración ZKTeco → FEL G4S · Generado ${escapeHtml(generated)}</p>
        </header>

        <section class="mb-3">
          <h2 class="h5">Filtros aplicados</h2>
          <div>${filterItems}</div>
        </section>

        ${summaryCards}
        ${statusBreakdown ? `<div class="mb-3">${statusBreakdown}</div>` : ''}

        <section>
          <h2 class="h5">Detalle</h2>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
              <thead class="table-light">
                <tr>
                  <th>Ticket</th>
                  <th>Receptor</th>
                  <th>Estado</th>
                  <th>Entrada</th>
                  <th>Salida</th>
                  <th>Estadía</th>
                  <th class="text-end">Total</th>
                  <th>Pagos</th>
                </tr>
              </thead>
              <tbody>${tableRows}</tbody>
            </table>
          </div>
        </section>

        <footer class="mt-4 text-muted small">Reporte generado automáticamente. Fuente: Base de datos interna.</footer>
        ${printable ? '<script>window.addEventListener("load",()=>{setTimeout(()=>window.print(),300);});</script>' : ''}
      </body>
      </html>`;
    };

    const buildInvoiceReportHtml = (printable = false) => {
      const summary = invoiceState.summary;
      const rows = invoiceState.rows;
      const filters = invoiceState.filters;
      const generated = invoiceState.generatedAt ? formatDateValue(invoiceState.generatedAt) : formatDateValue(new Date().toISOString());

      const filterLabels = {
        from: 'Desde',
        to: 'Hasta',
        status: 'Estado',
        nit: 'NIT receptor',
        uuid: 'UUID',
      };

      const filterItems = Object.entries(filters)
        .filter(([, value]) => value && value !== 'ANY')
        .map(([key, value]) => `<span class="badge text-bg-light me-2 mb-2"><strong class="me-1">${filterLabels[key] ?? key}:</strong> ${escapeHtml(value)}</span>`)
        .join('') || '<span class="text-muted">Sin filtros aplicados</span>';

      const summaryCards = summary ? `
        <div class="row g-3 my-3">
          <div class="col-sm-6 col-lg-3">
            <div class="card border-0 bg-body-tertiary h-100">
              <div class="card-body py-3">
                <div class="text-muted small">Facturas encontradas</div>
                <div class="fs-5 fw-semibold">${escapeHtml(summary.total ?? 0)}</div>
              </div>
            </div>
          </div>
          <div class="col-sm-6 col-lg-3">
            <div class="card border-0 bg-body-tertiary h-100">
              <div class="card-body py-3">
                <div class="text-muted small">Monto total</div>
                <div class="fs-5 fw-semibold">${escapeHtml(formatCurrency(summary.total_amount ?? 0))}</div>
              </div>
            </div>
          </div>
          <div class="col-sm-6 col-lg-3">
            <div class="card border-0 bg-body-tertiary h-100">
              <div class="card-body py-3">
                <div class="text-muted small">Certificadas (OK)</div>
                <div class="fs-5 fw-semibold">${escapeHtml(summary.ok ?? 0)}</div>
              </div>
            </div>
          </div>
          <div class="col-sm-6 col-lg-3">
            <div class="card border-0 bg-body-tertiary h-100">
              <div class="card-body py-3">
                <div class="text-muted small">Promedio por factura</div>
                <div class="fs-5 fw-semibold">${escapeHtml(summary.average_amount ? formatCurrency(summary.average_amount) : '—')}</div>
              </div>
            </div>
          </div>
        </div>
      ` : '';

      const statusBreakdown = summary ? [
        ['OK', summary.ok ?? 0],
        ['PENDING', summary.pending ?? 0],
        ['ERROR', summary.error ?? 0],
      ].filter(([, value]) => value > 0).map(([key, value]) =>
        `<span class="badge rounded-pill ${key==='OK'?'text-bg-success':key==='PENDING'?'text-bg-warning':'text-bg-danger'} me-2 mb-2">${escapeHtml(formatInvoiceStatus(key))}: ${escapeHtml(value)}</span>`
      ).join('') : '';

      const tableRows = rows.length ? rows.map((row) => `
        <tr>
          <td>${escapeHtml(row.ticket_no)}</td>
          <td>${escapeHtml(formatDateValue(row.fecha))}</td>
          <td class="text-end">${escapeHtml(formatCurrency(row.total))}</td>
          <td>${escapeHtml(row.receptor ?? 'CF')}</td>
          <td class="text-truncate" style="max-width: 260px;">${escapeHtml(row.uuid ?? '—')}</td>
          <td>${escapeHtml(formatInvoiceStatus(row.status))}</td>
        </tr>
      `).join('') : `
        <tr>
          <td colspan="6" class="text-center text-muted py-4">No hay facturas con los filtros dados.</td>
        </tr>
      `;

      return `<!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="utf-8" />
        <title>Reporte de facturas emitidas</title>
        ${BOOTSTRAP_HEAD}
      </head>
      <body class="p-4">
        <header class="mb-3">
          <h1 class="h3 mb-1">Reporte de facturas emitidas</h1>
          <p class="text-muted mb-0">Integración ZKTeco → FEL G4S · Generado ${escapeHtml(generated)}</p>
        </header>

        <section class="mb-3">
          <h2 class="h5">Filtros aplicados</h2>
          <div>${filterItems}</div>
        </section>

        ${summaryCards}
        ${statusBreakdown ? `<div class="mb-3">${statusBreakdown}</div>` : ''}

        <section>
          <h2 class="h5">Detalle</h2>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
              <thead class="table-light">
                <tr>
                  <th>Ticket</th>
                  <th>Fecha</th>
                  <th class="text-end">Total</th>
                  <th>Receptor</th>
                  <th>UUID</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>${tableRows}</tbody>
            </table>
          </div>
        </section>

        <footer class="mt-4 text-muted small">Reporte generado automáticamente. Fuente: tabla de facturas (invoices).</footer>
        ${printable ? '<script>window.addEventListener("load",()=>{setTimeout(()=>window.print(),300);});</script>' : ''}
      </body>
      </html>`;
    };

    // ====== UI principal (ajustes mínimos a Bootstrap puro) ======
    app.innerHTML = `
      <div class="d-flex flex-column gap-4">
        <section class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
              <div>
                <h5 class="card-title mb-1">Reporte de tickets</h5>
                <p class="text-muted small mb-0">Analiza tickets cerrados, montos recaudados y tiempos de permanencia.</p>
              </div>
              <span class="badge text-bg-info">Tickets</span>
            </div>
            <form id="ticketFilters" class="row g-3 mt-3">
              <div class="col-md-3">
                <label class="form-label small" for="ticketFrom">Desde</label>
                <input type="date" id="ticketFrom" class="form-control form-control-sm">
              </div>
              <div class="col-md-3">
                <label class="form-label small" for="ticketTo">Hasta</label>
                <input type="date" id="ticketTo" class="form-control form-control-sm">
              </div>
              <div class="col-md-3">
                <label class="form-label small" for="ticketStatus">Estado</label>
                <select id="ticketStatus" class="form-select form-select-sm">
                  <option value="ANY">Todos</option>
                  <option value="CLOSED">Cerrados</option>
                  <option value="OPEN">Abiertos</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label small" for="ticketPlate">Placa</label>
                <input type="text" id="ticketPlate" class="form-control form-control-sm" placeholder="Parcial o completa">
              </div>
              <div class="col-md-3">
                <label class="form-label small" for="ticketNit">NIT receptor</label>
                <input type="text" id="ticketNit" class="form-control form-control-sm" placeholder="Solo números"
                  value="${escapeHtml(ticketState.filters.nit || '')}"
                  inputmode="numeric" pattern="\\d*" maxlength="15"/>
              </div>
              <div class="col-md-3">
                <label class="form-label small" for="ticketMin">Monto mínimo</label>
                <input type="number" step="0.01" id="ticketMin" class="form-control form-control-sm" placeholder="Q">
              </div>
              <div class="col-md-3">
                <label class="form-label small" for="ticketMax">Monto máximo</label>
                <input type="number" step="0.01" id="ticketMax" class="form-control form-control-sm" placeholder="Q">
              </div>
            </form>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
              <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-primary" id="ticketFetch">Consultar</button>
                <button type="button" class="btn btn-outline-secondary" id="ticketReset">Limpiar</button>
              </div>
              <div class="ms-auto d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" id="ticketHtml" disabled>Descargar HTML</button>
                <button type="button" class="btn btn-outline-primary btn-sm" id="ticketPdf" disabled>Vista PDF</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="ticketCsv" disabled>Exportar CSV</button>
              </div>
            </div>
            <div id="ticketSummary" class="row g-3 mt-3"></div>
            <div class="table-responsive mt-3">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Ticket</th>
                    <th>Receptor</th>
                    <th>Estado</th>
                    <th>Entrada</th>
                    <th>Salida</th>
                    <th>Estadía</th>
                    <th class="text-end">Total</th>
                    <th>Pagos</th>
                  </tr>
                </thead>
                <tbody id="ticketRows">
                  <tr><td colspan="8" class="text-center text-muted">Selecciona filtros y consulta para ver resultados.</td></tr>
                </tbody>
              </table>
            </div>
            <div id="ticketMessage" class="small text-muted mt-2"></div>
          </div>
        </section>

        <section class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
              <div>
                <h5 class="card-title mb-1">Reporte de facturas emitidas</h5>
                <p class="text-muted small mb-0">Consulta documentos certificados, descárgalos y obtén estadísticas por estado.</p>
              </div>
              <span class="badge text-bg-success">Facturación</span>
            </div>
            <form id="invoiceFilters" class="row g-3 mt-3">
              <div class="col-md-3">
                <label class="form-label small" for="invoiceFrom">Desde</label>
                <input type="date" id="invoiceFrom" class="form-control form-control-sm">
              </div>
              <div class="col-md-3">
                <label class="form-label small" for="invoiceTo">Hasta</label>
                <input type="date" id="invoiceTo" class="form-control form-control-sm">
              </div>
              <div class="col-md-3">
                <label class="form-label small" for="invoiceStatus">Estado</label>
                <select id="invoiceStatus" class="form-select form-select-sm">
                  <option value="ANY">Todos</option>
                  <option value="OK">OK</option>
                  <option value="PENDING">Pendientes</option>
                  <option value="ERROR">Errores</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label small" for="invoiceNit">NIT receptor</label>
                <input type="text" id="invoiceNit" class="form-control form-control-sm" placeholder="CF o NIT">
              </div>
              <div class="col-md-3">
                <label class="form-label small" for="invoiceUuid">UUID</label>
                <input type="text" id="invoiceUuid" class="form-control form-control-sm" placeholder="Coincidencia exacta">
              </div>
            </form>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
              <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-primary" id="invoiceFetch">Buscar</button>
                <button type="button" class="btn btn-outline-secondary" id="invoiceReset">Limpiar</button>
              </div>
              <div class="ms-auto d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" id="invoiceHtml" disabled>Descargar HTML</button>
                <button type="button" class="btn btn-outline-primary btn-sm" id="invoicePdf" disabled>Vista PDF</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="invoiceCsv" disabled>Exportar CSV</button>
              </div>
            </div>
            <div id="invoiceSummary" class="row g-3 mt-3"></div>
            <div class="table-responsive mt-3">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Ticket</th>
                    <th>Fecha</th>
                    <th class="text-end">Total</th>
                    <th>Receptor</th>
                    <th>UUID</th>
                    <th>Estado</th>
                    <th class="text-center">Acciones</th>
                  </tr>
                </thead>
                <tbody id="invoiceRows">
                  <tr><td colspan="7" class="text-center text-muted">Selecciona filtros y consulta para ver resultados.</td></tr>
                </tbody>
              </table>
            </div>
            <div id="invoiceMessage" class="small text-muted mt-2"></div>
          </div>
        </section>
      </div>
    `;

    const nitInput = document.getElementById('ticketNit');
    if (nitInput) {
      nitInput.addEventListener('input', () => {
        nitInput.value = nitInput.value.replace(/\D+/g, '');
      });
    }

    // Solo números: elimina cualquier carácter no numérico
    nitInput && nitInput.addEventListener('input', () => {
      nitInput.value = nitInput.value.replace(/\D+/g, '');
    });

    const ticketInputs = {
      from: document.getElementById('ticketFrom'),
      to: document.getElementById('ticketTo'),
      status: document.getElementById('ticketStatus'),
      plate: document.getElementById('ticketPlate'),
      nit: document.getElementById('ticketNit'),
      min_total: document.getElementById('ticketMin'),
      max_total: document.getElementById('ticketMax'),
    };

    const invoiceInputs = {
      from: document.getElementById('invoiceFrom'),
      to: document.getElementById('invoiceTo'),
      status: document.getElementById('invoiceStatus'),
      nit: document.getElementById('invoiceNit'),
      uuid: document.getElementById('invoiceUuid'),
    };

    const ticketSummaryEl = document.getElementById('ticketSummary');
    const ticketRowsEl = document.getElementById('ticketRows');
    const ticketMsgEl = document.getElementById('ticketMessage');

    const invoiceSummaryEl = document.getElementById('invoiceSummary');
    const invoiceRowsEl = document.getElementById('invoiceRows');
    const invoiceMsgEl = document.getElementById('invoiceMessage');

    const syncTicketForm = () => {
      ticketInputs.from.value = ticketState.filters.from || '';
      ticketInputs.to.value = ticketState.filters.to || '';
      ticketInputs.status.value = ticketState.filters.status || 'ANY';
      ticketInputs.plate.value = ticketState.filters.plate || '';
      ticketInputs.nit.value = ticketState.filters.nit || '';
      ticketInputs.min_total.value = ticketState.filters.min_total || '';
      ticketInputs.max_total.value = ticketState.filters.max_total || '';
    };

    const syncInvoiceForm = () => {
      invoiceInputs.from.value = invoiceState.filters.from || '';
      invoiceInputs.to.value = invoiceState.filters.to || '';
      invoiceInputs.status.value = invoiceState.filters.status || 'ANY';
      invoiceInputs.nit.value = invoiceState.filters.nit || '';
      invoiceInputs.uuid.value = invoiceState.filters.uuid || '';
    };

    syncTicketForm();
    syncInvoiceForm();

    const getTicketFilters = () => {
      const filters = {
        from: ticketInputs.from.value || '',
        to: ticketInputs.to.value || '',
        status: ticketInputs.status.value || 'ANY',
        plate: ticketInputs.plate.value.trim(),
        nit: ticketInputs.nit.value.trim(),
        min_total: ticketInputs.min_total.value !== '' ? ticketInputs.min_total.value : '',
        max_total: ticketInputs.max_total.value !== '' ? ticketInputs.max_total.value : '',
      };
      ticketState.filters = { ...ticketState.filters, ...filters };
      return filters;
    };

    const getInvoiceFilters = () => {
      const filters = {
        from: invoiceInputs.from.value || '',
        to: invoiceInputs.to.value || '',
        status: invoiceInputs.status.value || 'ANY',
        nit: invoiceInputs.nit.value.trim(),
        uuid: invoiceInputs.uuid.value.trim(),
      };
      invoiceState.filters = { ...invoiceState.filters, ...filters };
      return filters;
    };

    const renderTicketSummary = () => {
      const summary = ticketState.summary;
      if (!summary) {
        ticketSummaryEl.innerHTML = '';
        ticketMsgEl.textContent = ticketState.rows.length ? '' : 'No hay datos para mostrar.';
        return;
      }
      ticketSummaryEl.innerHTML = `
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-body-tertiary">
            <div class="card-body py-3">
              <div class="text-muted small">Tickets</div>
              <div class="fs-5 fw-semibold">${summary.total_tickets}</div>
              <div class="text-muted small">Registros encontrados</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-body-tertiary">
            <div class="card-body py-3">
              <div class="text-muted small">Monto total</div>
              <div class="fs-5 fw-semibold">${formatCurrency(summary.total_amount ?? 0)}</div>
              <div class="text-muted small">Incluye tickets sin pagos</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-body-tertiary">
            <div class="card-body py-3">
              <div class="text-muted small">Tickets con pagos</div>
              <div class="fs-5 fw-semibold">${summary.with_payments}/${summary.total_tickets}</div>
              <div class="text-muted small">Con al menos un cobro</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-body-tertiary">
            <div class="card-body py-3">
              <div class="text-muted small">Estadía promedio</div>
              <div class="fs-5 fw-semibold">${summary.average_minutes !== null && summary.average_minutes !== undefined ? formatDuration(summary.average_minutes) : '—'}</div>
              <div class="text-muted small">Minutos por ticket</div>
            </div>
          </div>
        </div>
      `;

      const breakdown = summary.status_breakdown || {};
      const chips = Object.entries(breakdown)
        .map(([k, v]) => `<span class="badge rounded-pill text-bg-light me-1">${formatTicketStatus(k)}: ${v}</span>`)
        .join('');
      const updated = ticketState.generatedAt ? formatDateValue(ticketState.generatedAt) : formatDateValue(new Date().toISOString());
      ticketMsgEl.innerHTML = `${chips ? chips + ' · ' : ''}<span class="text-muted">Actualizado: ${updated}</span>`;
    };

    const renderTicketRows = () => {
      if (!ticketState.rows.length) {
        ticketRowsEl.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No se encontraron tickets con los filtros seleccionados.</td></tr>';
        return;
      }
      ticketRowsEl.innerHTML = ticketState.rows.map((row) => {
        const payments = (row.payments || []).map((p) => `
          <div class="small">
            <strong>${formatCurrency(p.amount)}</strong>
            <div class="text-muted">${p.method || 'Método no indicado'}</div>
            <div class="text-muted">${formatDateValue(p.paid_at)}</div>
          </div>
        `).join('');
        const status = String(row.status || '').toUpperCase();
        const statusClass = status === 'CLOSED' ? 'bg-success-subtle text-success' : status === 'OPEN' ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-secondary';
        return `
          <tr>
            <td><span class="fw-semibold">${escapeHtml(row.plate)}</span></td>
            <td>${escapeHtml(row.receptor_nit ?? 'CF')}</td>
            <td><span class="badge ${statusClass}">${escapeHtml(formatTicketStatus(row.status))}</span></td>
            <td>${escapeHtml(formatDateValue(row.entry_at))}</td>
            <td>${escapeHtml(formatDateValue(row.exit_at))}</td>
            <td>${escapeHtml(formatDuration(row.duration_min))}</td>
            <td class="text-end fw-semibold">${escapeHtml(formatCurrency(row.total))}</td>
            <td>${payments || '<span class="badge text-bg-light border">Sin pagos</span>'}</td>
          </tr>
        `;
      }).join('');
    };

    const updateTicketActions = () => {
      const disabled = !ticketState.rows.length;
      document.getElementById('ticketHtml').disabled = disabled;
      document.getElementById('ticketPdf').disabled = disabled;
      document.getElementById('ticketCsv').disabled = disabled;
    };

    const renderInvoiceSummary = () => {
      const summary = invoiceState.summary;
      if (!summary) {
        invoiceSummaryEl.innerHTML = '';
        invoiceMsgEl.textContent = invoiceState.rows.length ? '' : 'No hay datos para mostrar.';
        return;
      }
      invoiceSummaryEl.innerHTML = `
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-body-tertiary">
            <div class="card-body py-3">
              <div class="text-muted small">Facturas</div>
              <div class="fs-5 fw-semibold">${summary.total}</div>
              <div class="text-muted small">Documentos encontrados</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-body-tertiary">
            <div class="card-body py-3">
              <div class="text-muted small">Monto total</div>
              <div class="fs-5 fw-semibold">${formatCurrency(summary.total_amount ?? 0)}</div>
              <div class="text-muted small">Suma de montos certificados</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-body-tertiary">
            <div class="card-body py-3">
              <div class="text-muted small">Certificadas</div>
              <div class="fs-5 fw-semibold">${summary.ok}</div>
              <div class="text-muted small">Estado OK</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-body-tertiary">
            <div class="card-body py-3">
              <div class="text-muted small">Promedio</div>
              <div class="fs-5 fw-semibold">${summary.average_amount !== null && summary.average_amount !== undefined ? formatCurrency(summary.average_amount) : '—'}</div>
              <div class="text-muted small">Por factura</div>
            </div>
          </div>
        </div>
      `;

      const parts = [];
      if (summary.ok) parts.push(`OK: ${summary.ok}`);
      if (summary.pending) parts.push(`Pendientes: ${summary.pending}`);
      if (summary.error) parts.push(`Errores: ${summary.error}`);
      const updated = invoiceState.generatedAt ? formatDateValue(invoiceState.generatedAt) : formatDateValue(new Date().toISOString());
      invoiceMsgEl.innerHTML = `${parts.join(' · ')}${parts.length ? ' · ' : ''}<span class="text-muted">Actualizado: ${updated}</span>`;
    };

    const renderInvoiceRows = () => {
      if (!invoiceState.rows.length) {
        invoiceRowsEl.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No se encontraron facturas con los filtros seleccionados.</td></tr>';
        return;
      }
      invoiceRowsEl.innerHTML = invoiceState.rows.map((row) => {
        const status = String(row.status || '').toUpperCase();
        let statusClass = 'text-bg-secondary';
        if (status === 'OK') statusClass = 'text-bg-success';
        else if (status === 'PENDING') statusClass = 'text-bg-warning';
        else if (status === 'ERROR') statusClass = 'text-bg-danger';
        const actions = row.uuid ? `
          <a class="btn btn-sm btn-outline-primary me-1" href="${api('fel/pdf')}?uuid=${encodeURIComponent(row.uuid)}" target="_blank" rel="noopener">PDF</a>
          <a class="btn btn-sm btn-outline-secondary" href="${api('fel/xml')}?uuid=${encodeURIComponent(row.uuid)}" target="_blank" rel="noopener">XML</a>
        ` : '<span class="badge text-bg-light border">Sin UUID</span>';
        return `
          <tr>
            <td><span class="fw-semibold">${escapeHtml(row.ticket_no)}</span></td>
            <td>${escapeHtml(formatDateValue(row.fecha))}</td>
            <td class="text-end fw-semibold">${escapeHtml(formatCurrency(row.total))}</td>
            <td>${escapeHtml(row.receptor ?? 'CF')}</td>
            <td class="text-truncate" style="max-width: 220px;">${escapeHtml(row.uuid ?? '—')}</td>
            <td><span class="badge ${statusClass}">${escapeHtml(formatInvoiceStatus(row.status))}</span></td>
            <td class="text-center">${actions}</td>
          </tr>
        `;
      }).join('');
    };

    const updateInvoiceActions = () => {
      const disabled = !invoiceState.rows.length;
      document.getElementById('invoiceHtml').disabled = disabled;
      document.getElementById('invoicePdf').disabled = disabled;
      document.getElementById('invoiceCsv').disabled = disabled;
    };

    const fetchTicketReport = async () => {
      const filters = getTicketFilters();
      const params = new URLSearchParams();
      Object.entries(filters).forEach(([key, value]) => {
        if (value && (key !== 'status' || value !== 'ANY')) {
          params.set(key, value);
        }
      });
      const query = params.toString();
      ticketRowsEl.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Generando reporte…</td></tr>';
      ticketSummaryEl.innerHTML = '';
      ticketMsgEl.textContent = '';
      try {
        const url = query ? api(`reports/tickets?${query}`) : api('reports/tickets');
        const js = await fetchJSON(url);
        if (js.ok === false && js.error) throw new Error(js.error);
        ticketState.rows = js.rows || [];
        ticketState.summary = js.summary || null;
        ticketState.generatedAt = js.generated_at || null;
        if (js.filters) {
          ticketState.filters = { ...ticketState.filters, ...js.filters };
          syncTicketForm();
        }
        renderTicketRows();
        renderTicketSummary();
      } catch (err) {
        ticketRowsEl.innerHTML = `<tr><td colspan="8" class="text-center text-danger">Error: ${escapeHtml(err.message)}</td></tr>`;
        ticketSummaryEl.innerHTML = '';
        ticketMsgEl.textContent = '';
        ticketState.rows = [];
        ticketState.summary = null;
      }
      updateTicketActions();
    };

    const fetchInvoiceReport = async () => {
      const filters = getInvoiceFilters();
      const params = new URLSearchParams();
      Object.entries(filters).forEach(([key, value]) => {
        if (value && (key !== 'status' || value !== 'ANY')) {
          params.set(key === 'uuid' ? 'uuid' : key, value);
        }
      });
      const query = params.toString();
      invoiceRowsEl.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Consultando facturas…</td></tr>';
      invoiceSummaryEl.innerHTML = '';
      invoiceMsgEl.textContent = '';
      try {
        const url = query ? `${api('facturacion/emitidas')}?${query}` : api('facturacion/emitidas');
        const js = await fetchJSON(url);
        if (js.ok === false && js.error) throw new Error(js.error);
        const rows = (js.rows || []).map((row) => ({
          ...row,
          total: Number(row.total ?? 0),
        }));
        invoiceState.rows = rows;
        const total = rows.length;
        const totalAmount = rows.reduce((acc, row) => acc + Number(row.total ?? 0), 0);
        const ok = rows.filter((row) => String(row.status || '').toUpperCase() === 'OK').length;
        const pending = rows.filter((row) => String(row.status || '').toUpperCase() === 'PENDING').length;
        const error = rows.filter((row) => String(row.status || '').toUpperCase() === 'ERROR').length;
        invoiceState.summary = {
          total,
          total_amount: Number(totalAmount.toFixed(2)),
          ok,
          pending,
          error,
          average_amount: total ? Number((totalAmount / total).toFixed(2)) : 0,
        };
        invoiceState.generatedAt = new Date().toISOString();
        if (js.filters) {
          invoiceState.filters = { ...invoiceState.filters, ...js.filters };
          syncInvoiceForm();
        }
        renderInvoiceRows();
        renderInvoiceSummary();
      } catch (err) {
        invoiceRowsEl.innerHTML = `<tr><td colspan="7" class="text-center text-danger">Error: ${escapeHtml(err.message)}</td></tr>`;
        invoiceSummaryEl.innerHTML = '';
        invoiceMsgEl.textContent = '';
        invoiceState.rows = [];
        invoiceState.summary = null;
      }
      updateInvoiceActions();
    };

    document.getElementById('ticketFetch').addEventListener('click', (e) => {
      e.preventDefault();
      fetchTicketReport();
    });

    document.getElementById('ticketReset').addEventListener('click', (e) => {
      e.preventDefault();
      ticketState.filters = { ...ticketState.filters, ...{
        from: defaultFrom,
        to: defaultTo,
        status: 'ANY',
        plate: '',
        nit: '',
        min_total: '',
        max_total: '',
      } };
      syncTicketForm();
      fetchTicketReport();
    });

    document.getElementById('ticketHtml').addEventListener('click', () => {
      const html = buildTicketReportHtml(false);
      const todayIso = new Date().toISOString().slice(0, 10);
      downloadHtml(`reporte_tickets_${todayIso}.html`, html);
    });

    document.getElementById('ticketPdf').addEventListener('click', () => {
      const html = buildTicketReportHtml(true);
      openPrintWindow(html);
    });

    document.getElementById('ticketCsv').addEventListener('click', () => {
      const headers = [
        { label: 'ticket_no', accessor: (row) => row.ticket_no },
        { label: 'receptor', accessor: (row) => row.receptor_nit ?? 'CF' },
        { label: 'status', accessor: (row) => row.status },
        { label: 'entry_at', accessor: (row) => row.entry_at },
        { label: 'exit_at', accessor: (row) => row.exit_at },
        { label: 'duration_min', accessor: (row) => row.duration_min },
        { label: 'total', accessor: (row) => row.total },
        { label: 'pagos', accessor: (row) => (row.payments || []).map((p) => `${p.amount} ${p.method || ''} ${p.paid_at || ''}`).join(' | ') },
      ];
      const todayIso = new Date().toISOString().slice(0, 10);
      exportCsv(`reporte_tickets_${todayIso}.csv`, headers, ticketState.rows);
    });

    document.getElementById('invoiceFetch').addEventListener('click', (e) => {
      e.preventDefault();
      fetchInvoiceReport();
    });

    document.getElementById('invoiceReset').addEventListener('click', (e) => {
      e.preventDefault();
      invoiceState.filters = { ...invoiceState.filters, ...{
        from: defaultFrom,
        to: defaultTo,
        status: 'ANY',
        nit: '',
        uuid: '',
      } };
      syncInvoiceForm();
      fetchInvoiceReport();
    });

    document.getElementById('invoiceHtml').addEventListener('click', () => {
      const html = buildInvoiceReportHtml(false);
      const todayIso = new Date().toISOString().slice(0, 10);
      downloadHtml(`reporte_facturas_${todayIso}.html`, html);
    });

    document.getElementById('invoicePdf').addEventListener('click', () => {
      const html = buildInvoiceReportHtml(true);
      openPrintWindow(html);
    });

    document.getElementById('invoiceCsv').addEventListener('click', () => {
      const headers = [
        { label: 'ticket_no', accessor: (row) => row.ticket_no },
        { label: 'fecha', accessor: (row) => row.fecha },
        { label: 'total', accessor: (row) => row.total },
        { label: 'receptor', accessor: (row) => row.receptor ?? 'CF' },
        { label: 'uuid', accessor: (row) => row.uuid ?? '' },
        { label: 'status', accessor: (row) => row.status },
      ];
      const todayIso = new Date().toISOString().slice(0, 10);
      exportCsv(`reporte_facturas_${todayIso}.csv`, headers, invoiceState.rows);
    });

    await fetchTicketReport();
    await fetchInvoiceReport();
  }

  // ===== Ajustes  =====
  async function renderSettings() {
    app.innerHTML = `
      <div class="card shadow-sm">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
          <span class="text-muted">Cargando configuración activa…</span>
        </div>
      </div>
    `;

    const settings = await loadSettings(true);

    if (!settings) {
      app.innerHTML = `
        <div class="empty">
          No fue posible obtener la configuración actual.<br />
          <button class="btn btn-primary btn-sm mt-3" id="retrySettings">Reintentar</button>
        </div>
      `;
      document.getElementById('retrySettings')?.addEventListener('click', () => renderSettings());
      return;
    }

    const metrics = settings.database?.metrics ?? {};
    const hourlyRate = settings.billing?.hourly_rate ?? null;
    const hourlyRateValue = hourlyRate !== null && hourlyRate !== undefined && hourlyRate !== ''
      ? Number(hourlyRate).toFixed(2)
      : '';
    const monthlyRate = settings.billing?.monthly_rate ?? null;
    const monthlyRateValue = monthlyRate !== null && monthlyRate !== undefined && monthlyRate !== ''
      ? Number(monthlyRate).toFixed(2)
      : '';
    const env = String(settings.app?.environment ?? '').toLowerCase();
    let envClass = 'neutral';
    if (['production', 'prod'].includes(env)) envClass = 'danger';
    else if (['staging', 'pre', 'testing', 'qa'].includes(env)) envClass = 'warn';
    else if (env) envClass = 'ok';

    const dbStatus = settings.database?.status ?? 'unknown';
    const dbLabelMap = {
      online: 'Conectada',
      success: 'Conectada',
      healthy: 'Conectada',
      offline: 'Desconectada',
      down: 'Desconectada',
    };
    const dbLabel = dbLabelMap[dbStatus] || (dbStatus ? dbStatus.toString().toUpperCase() : 'Desconocido');

    const integrationItems = Object.values(settings.integrations ?? {});
    const integrationList = integrationItems.length
      ? integrationItems.map((integration) => {
          const statusClass = integration?.configured ? 'ok' : 'warn';
          const statusLabel = integration?.configured ? 'Configurada' : 'Incompleta';
          const detailParts = [];
          if (integration?.base_url) detailParts.push(`URL ${integration.base_url}`);
          if (integration?.mode) detailParts.push(`Modo ${integration.mode}`);
          if (integration?.requestor) detailParts.push(`ID ${integration.requestor}`);
          if (integration?.app_key) detailParts.push(`Clave ${integration.app_key}`);
          const details = detailParts.join(' · ');
          return `
            <li class="integration-item">
              <div>
                <strong>${escapeHtml(integration?.label ?? 'Integración')}</strong>
                <div class="integration-meta">${escapeHtml(details || 'Variables pendientes por completar')}</div>
              </div>
              <span class="status-pill ${statusClass}">${escapeHtml(statusLabel)}</span>
            </li>
          `;
        }).join('')
      : '<li class="integration-item"><div><strong>Sin integraciones definidas</strong><div class="integration-meta">Agrega las credenciales correspondientes en el archivo .env.</div></div></li>';

    app.innerHTML = `
      <div class="row g-4">
        <div class="col-xl-4 col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column gap-3">
              <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                  <h5 class="card-title mb-1">Entorno de ejecución</h5>
                  <p class="text-muted small mb-0">${escapeHtml(settings.app?.name ?? 'Integración FEL')}</p>
                </div>
                <span class="status-pill ${envClass}">${escapeHtml(settings.app?.environment_label ?? 'Desconocido')}</span>
              </div>
              <div class="settings-list">
                <div class="settings-list-item"><span>Zona horaria</span><span>${escapeHtml(settings.app?.timezone ?? '—')}</span></div>
                <div class="settings-list-item"><span>Servidor</span><span>${escapeHtml(settings.app?.server ?? '—')}</span></div>
                <div class="settings-list-item"><span>PHP</span><span>${escapeHtml(settings.app?.php_version ?? '—')}</span></div>
              </div>
              <small class="text-muted">Actualizado ${escapeHtml(formatRelativeTime(settings.generated_at) || 'recientemente')}.</small>
            </div>
          </div>
        </div>
        <div class="col-xl-4 col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column gap-3">
              <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                  <h5 class="card-title mb-1">Base de datos</h5>
                  <p class="text-muted small mb-0">${escapeHtml((settings.database?.host && settings.database?.name) ? `${settings.database.host} · ${settings.database.name}` : 'Configura las variables DB_* en el archivo .env')}</p>
                </div>
                <span class="status-pill ${statusToClass(dbStatus)}">${escapeHtml(dbLabel)}</span>
              </div>
              <div class="settings-list">
                <div class="settings-list-item"><span>Motor</span><span>${escapeHtml(settings.database?.driver ?? '—')}</span></div>
                <div class="settings-list-item"><span>Usuario</span><span>${escapeHtml(settings.database?.user ?? '—')}</span></div>
                <div class="settings-list-item"><span>Tickets</span><span>${escapeHtml(formatNumber(metrics.tickets_total ?? 0))}</span></div>
                <div class="settings-list-item"><span>Pagos</span><span>${escapeHtml(formatNumber(metrics.payments_total ?? 0))}</span></div>
                <div class="settings-list-item"><span>Facturas</span><span>${escapeHtml(formatNumber(metrics.invoices_total ?? 0))}</span></div>
                <div class="settings-list-item"><span>Último ticket</span><span>${escapeHtml(formatDateTime(metrics.tickets_last_sync))}</span></div>
                <div class="settings-list-item"><span>Último pago</span><span>${escapeHtml(formatDateTime(metrics.payments_last_sync))}</span></div>
                <div class="settings-list-item"><span>Última factura</span><span>${escapeHtml(formatDateTime(metrics.invoices_last_sync))}</span></div>
                <div class="settings-list-item"><span>Pendientes FEL</span><span>${escapeHtml(formatNumber(metrics.pending_invoices ?? 0))}</span></div>
              </div>
              ${settings.database?.error ? `<div class="alert alert-warning mb-0 small">${escapeHtml(settings.database.error)}</div>` : ''}
            </div>
          </div>
        </div>
        <div class="col-xl-4">
          <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column gap-3">
              <div>
                <h5 class="card-title mb-1">Seguridad &amp; ingestas</h5>
                <p class="text-muted small mb-0">Claves utilizadas para la comunicación con ZKTeco y servicios externos.</p>
              </div>
              <div class="settings-list">
                <div class="settings-list-item"><span>Token de ingesta</span><span>${escapeHtml(settings.security?.ingest_key ?? 'No configurado')}</span></div>
              </div>
              <small class="text-muted">Las credenciales se leen desde <code>backend/.env</code>.</small>
            </div>
          </div>
        </div>
        <div class="col-xl-4 col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column gap-3">
              <div>
                <h5 class="card-title mb-1">Facturación automática</h5>
                <p class="text-muted small mb-0">Define la tarifa por hora para aplicar cobros automáticos en las facturas.</p>
              </div>
              <form id="hourlyRateForm" class="d-flex flex-column gap-3" autocomplete="off" novalidate>
                <div>
                  <label for="hourlyRateInput" class="form-label small mb-1">Tarifa por hora (GTQ)</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">Q</span>
                    <input type="number" step="0.01" min="0" class="form-control" id="hourlyRateInput" value="${escapeHtml(hourlyRateValue)}" placeholder="0.00" />
                  </div>
                  <small class="text-muted d-block mt-1">Se aplicará automáticamente a los tickets facturados por hora.</small>
                </div>
                <div>
                  <label for="monthlyRateInput" class="form-label small mb-1">Tarifa mensual (GTQ)</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">Q</span>
                    <input type="number" step="0.01" min="0" class="form-control" id="monthlyRateInput" value="${escapeHtml(monthlyRateValue)}" placeholder="0.00" />
                  </div>
                  <small class="text-muted d-block mt-1">Se aplicará al seleccionar cobro mensual en las facturas.</small>
                </div>
                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-primary btn-sm" id="hourlyRateSave">Guardar</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="hourlyRateClear">Limpiar</button>
                </div>
                <div class="alert alert-success py-2 px-3 small mb-0 d-none" id="hourlyRateFeedback">Tarifas actualizadas correctamente.</div>
              </form>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="card shadow-sm">
            <div class="card-body d-flex flex-column gap-3">
              <div>
                <h5 class="card-title mb-1">Integraciones activas</h5>
                <p class="text-muted small mb-0">Estado actual de cada conector configurado.</p>
              </div>
              <ul class="integration-list">${integrationList}</ul>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="card shadow-sm">
            <div class="card-body d-flex flex-column gap-3">
              <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                <div>
                  <h5 class="card-title mb-1">Actividad de sincronización</h5>
                  <p class="text-muted small mb-0">Resumen generado ${escapeHtml(formatRelativeTime(settings.generated_at) || 'hace instantes')}.</p>
                </div>
                <div class="d-flex flex-wrap gap-2 justify-content-end">
                  <button class="btn btn-primary btn-sm" id="settingsManualSync">actualizacion de data manual</button>
                  <button class="btn btn-outline-primary btn-sm" id="settingsReload">Actualizar</button>
                </div>
              </div>
              <div class="timeline" id="settingsTimeline">${buildTimeline(settings.activity)}</div>
            </div>
          </div>
        </div>
      </div>
    `;

    const reload = document.getElementById('settingsReload');
    if (reload) {
      reload.addEventListener('click', () => {
        reload.classList.add('is-loading');
        reload.disabled = true;
        renderSettings();
      });
    }

    const manualSyncBtn = document.getElementById('settingsManualSync');
    if (manualSyncBtn) {
      manualSyncBtn.addEventListener('click', async () => {
        manualSyncBtn.classList.add('is-loading');
        manualSyncBtn.disabled = true;
        let shouldRefresh = false;
        const m = Dialog.loading({ title:'Sincronizando', message:'Consultando registros remotos…' });
        try {
          await triggerHamachiSync({ silent:false, force:true });
          await loadSettings(true);
          shouldRefresh = true;
        } catch (error) {
          Dialog.err(error, 'No se pudo sincronizar');
        } finally {
          m.close();
          manualSyncBtn.classList.remove('is-loading');
          manualSyncBtn.disabled = false;
          if (shouldRefresh && currentPage === 'settings') {
            void renderSettings();
          }
        }
      });
    }

    const hourlyForm = document.getElementById('hourlyRateForm');
    const hourlyInput = document.getElementById('hourlyRateInput');
    const monthlyInput = document.getElementById('monthlyRateInput');
    const hourlyFeedback = document.getElementById('hourlyRateFeedback');
    const hourlyClear = document.getElementById('hourlyRateClear');
    const hourlySave = document.getElementById('hourlyRateSave');
    if (hourlyForm && hourlyInput) {
      hourlyInput.addEventListener('input', () => {
        if (hourlyFeedback) hourlyFeedback.classList.add('d-none');
      });
      if (monthlyInput) {
        monthlyInput.addEventListener('input', () => {
          if (hourlyFeedback) hourlyFeedback.classList.add('d-none');
        });
      }

      if (hourlyClear) {
        hourlyClear.addEventListener('click', (event) => {
          event.preventDefault();
          hourlyInput.value = '';
          if (monthlyInput) monthlyInput.value = '';
          if (hourlyFeedback) hourlyFeedback.classList.add('d-none');
        });
      }

      hourlyForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const rawHourly = hourlyInput.value.trim();
        const rawMonthly = monthlyInput ? monthlyInput.value.trim() : '';

        // Normaliza a número o null
        const body = {
          hourly_rate: rawHourly === '' ? null : Number(rawHourly),
          monthly_rate: monthlyInput ? (rawMonthly === '' ? null : Number(rawMonthly)) : undefined,
        };

        if (hourlySave) {
          hourlySave.disabled = true;
          hourlySave.classList.add('is-loading');
        }
        if (hourlyFeedback) hourlyFeedback.classList.add('d-none');

        try {
          // ✅ Solo guardar tarifas (NADA de fel/invoice aquí)
          await fetchJSON(api('settings/hourly-rate'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
          });

          // refresca y muestra valores formateados
          const refreshed = await loadSettings(true);

          const refreshedRate = refreshed?.billing?.hourly_rate ?? null;
          hourlyInput.value =
            refreshedRate !== null && refreshedRate !== undefined && refreshedRate !== ''
              ? Number(refreshedRate).toFixed(2)
              : '';

          if (monthlyInput) {
            const refreshedMonthly = refreshed?.billing?.monthly_rate ?? null;
            monthlyInput.value =
              refreshedMonthly !== null && refreshedMonthly !== undefined && refreshedMonthly !== ''
                ? Number(refreshedMonthly).toFixed(2)
                : '';
          }

          if (hourlyFeedback) {
            const hasHourly = hourlyInput.value !== '';
            const hasMonthly = monthlyInput ? monthlyInput.value !== '' : false;
            hourlyFeedback.textContent = (hasHourly || hasMonthly)
              ? 'Tarifas actualizadas correctamente.'
              : 'Tarifas eliminadas. Configura valores para habilitar los cálculos automáticos.';
            hourlyFeedback.classList.remove('d-none');
          }
        } catch (error) {
          Dialog.err(error, 'No se pudo guardar la tarifa');
        } finally {
          if (hourlySave) {
            hourlySave.classList.remove('is-loading');
            hourlySave.disabled = false;
          }
        }
      });

    }
  }

  // ===== facturacion manual ======
async function renderManualInvoiceModule() {
  const settings = await loadSettings();
  const monthlyRate = settings?.billing?.monthly_rate ?? null;

  app.innerHTML = `
    <div class="card p-3">
      <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
        <div class="flex-grow-1">
          <h5 class="mb-1">Factura manual</h5>
          <p class="text-muted small mb-1">
            Genera una factura manual indicando <strong>motivo</strong>, <strong>NIT</strong> y <strong>monto</strong>,
            o usa la <strong>tarifa mensual</strong>. También permite factura de <strong>gracia</strong> (Q 0.00, sin FEL).
          </p>
          <div class="text-muted small">${monthlyRate != null ? `Tarifa mensual actual: Q ${Number(monthlyRate).toFixed(2)}` : 'No hay tarifa mensual configurada.'}</div>
        </div>
        <div class="ms-auto d-flex flex-wrap align-items-center gap-2">
          <button type="button" class="btn btn-sm btn-primary" id="btnNewManualInv">
            Nueva factura manual
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshManualInv">
            Actualizar
          </button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm mb-0" id="tblManualInvoices">
          <thead>
            <tr>
              <th>#</th><th>Fecha</th><th>Motivo</th><th>NIT</th><th>Monto</th>
              <th>Usó mensual</th><th>Env. FEL</th><th>Estado FEL</th><th>UUID</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colspan="9" class="text-center py-4">
                <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
                <span class="ms-2">Cargando…</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="manualInvModal" tabindex="-1" aria-labelledby="manualInvLabel" aria-hidden="true">
      <div class="modal-dialog">
        <form class="modal-content" id="manualInvForm">
          <div class="modal-header">
            <h5 class="modal-title" id="manualInvLabel">Nueva factura manual</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <div class="mb-2">
              <label class="form-label">Motivo</label>
              <input type="text" class="form-control" name="reason" placeholder="Ej.: Mensualidad Noviembre" required>
            </div>

            <div class="mb-2">
              <label class="form-label">NIT del cliente</label>
              <div class="input-group">
                <input type="text" class="form-control" name="receptor_nit" id="nitField" placeholder="CF o NIT" required>
                <button class="btn btn-outline-secondary" type="button" id="btnCheckNit" title="Buscar NIT">
                  <i class="bi bi-search"></i>
                </button>
              </div>
              <div class="form-text" id="nitHelp">Escribe el NIT (o CF). Se buscará el nombre automáticamente.</div>
              <input type="hidden" name="receptor_name" id="receptorNameHidden" />
            </div>

            <div class="mb-2">
              <label class="form-label">Modo</label>
              <select class="form-select" name="mode" id="modeSel">
                <option value="custom" selected>Personalizado</option>
                <option value="monthly">Usar tarifa mensual</option>
                <option value="grace">Gracia (Q 0.00, sin FEL)</option>
              </select>
            </div>

            <div class="mb-2" id="amountWrap">
              <label class="form-label">Monto (Q)</label>
              <input type="number" step="0.01" min="0" class="form-control" name="amount" id="amountField" placeholder="0.00">
              <div class="form-text" id="monthlyHint"></div>
            </div>

            <div class="alert alert-warning small d-none" id="warnMonthly">
              Se usará la tarifa mensual configurada. El campo Monto será ignorado.
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-success" id="btnSubmitManualInv">Generar</button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          </div>
        </form>
      </div>
    </div>
  `;

  async function refreshManualInvoicesTable() {
    const tbody = document.querySelector('#tblManualInvoices tbody');
    if (!tbody) return;

    tbody.innerHTML = `
      <tr>
        <td colspan="9" class="text-center py-4">
          <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
          <span class="ms-2">Actualizando…</span>
        </td>
      </tr>`;

    try {
      const js = await fetchJSON(api('fel/manual-invoice'));
      const rows = Array.isArray(js?.data) ? js.data : (Array.isArray(js) ? js : []);

      if (!rows.length) {
        tbody.innerHTML = `
          <tr>
            <td colspan="9" class="text-center text-muted py-4">
              No hay facturas manuales registradas todavía.
            </td>
          </tr>`;
        return;
      }

      tbody.innerHTML = rows.map(r => `
        <tr title="${r.receptor_name ? 'Nombre: ' + String(r.receptor_name).replace(/"/g,'&quot;') : ''}">
          <td>${r.id ?? ''}</td>
          <td>${r.created_at ?? ''}</td>
          <td>${r.reason ? escapeHtml(r.reason) : ''}</td>
          <td>${r.receptor_nit ?? ''}</td>
          <td>Q ${Number(r.amount ?? 0).toFixed(2)}</td>
          <td>${r.used_monthly ? 'Sí' : 'No'}</td>
          <td>${r.send_to_fel ? 'Sí' : 'No'}</td>
          <td>${r.fel_status ? escapeHtml(r.fel_status) : ''}</td>
          <td>${r.fel_uuid ? escapeHtml(r.fel_uuid) : ''}</td>
        </tr>
      `).join('');
    } catch (e) {
      console.error(e);
      tbody.innerHTML = `
        <tr>
          <td colspan="9" class="text-center py-4">
            <div class="text-danger">Error al cargar la tabla.</div>
            <div class="small text-muted">${escapeHtml(String(e))}</div>
          </td>
        </tr>`;
    }
  }

  document.getElementById('btnRefreshManualInv')?.addEventListener('click', refreshManualInvoicesTable);
  await refreshManualInvoicesTable();

  // Abrir modal
  document.getElementById('btnNewManualInv')?.addEventListener('click', () => {
    const modalEl = document.getElementById('manualInvModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: true, keyboard: true });

    // limpiar posibles restos si algo se quedó antes
    modalEl.addEventListener('hidden.bs.modal', () => {
      document.body.classList.remove('modal-open');
      document.body.style.removeProperty('padding-right');
      document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
    }, { once: true });

    // Hints
    const hint = document.getElementById('monthlyHint');
    hint.textContent = (monthlyRate != null) ? `Tarifa mensual actual: Q ${Number(monthlyRate).toFixed(2)}` : 'Sin tarifa mensual configurada';
    const modeSel = document.getElementById('modeSel');
    const amountWrap = document.getElementById('amountWrap');
    const amountField = document.getElementById('amountField');
    const warnMonthly = document.getElementById('warnMonthly');

    function syncMode() {
      const mode = modeSel.value;
      if (mode === 'monthly') {
        warnMonthly.classList.remove('d-none');
        amountWrap.classList.remove('d-none');
        if (monthlyRate != null) amountField.value = Number(monthlyRate).toFixed(2);
        amountField.readOnly = true;
      } else if (mode === 'grace') {
        warnMonthly.classList.add('d-none');
        amountWrap.classList.add('d-none');
        amountField.value = '';
        amountField.readOnly = false;
      } else {
        warnMonthly.classList.add('d-none');
        amountWrap.classList.remove('d-none');
        amountField.readOnly = false;
        if (!amountField.value && monthlyRate != null) amountField.placeholder = Number(monthlyRate).toFixed(2);
      }
    }
    modeSel.onchange = syncMode;
    syncMode();

    // Lookup NIT
    const nitField = document.getElementById('nitField');
    const nitHelp = document.getElementById('nitHelp');
    const receptorNameHidden = document.getElementById('receptorNameHidden');
    const btnCheckNit = document.getElementById('btnCheckNit');
    let nitLookupTimer = null;
    let lastNitQueried = '';

    function normalizeNit(raw) {
      const s = String(raw || '').trim().toUpperCase();
      if (s === 'C/F' || s === 'CF') return 'CF';
      return s.replace(/\s+/g, '');
    }

    async function doNitLookup(force = false) {
      const raw = nitField.value;
      const nit = normalizeNit(raw);
      receptorNameHidden.value = '';

      if (!nit) {
        nitHelp.textContent = 'Escribe el NIT (o CF). Se buscará el nombre automáticamente.';
        return;
      }
      if (nit === 'CF') {
        nitHelp.textContent = 'Consumidor Final';
        return;
      }
      if (!force && nit === lastNitQueried) return;
      lastNitQueried = nit;

      nitHelp.innerHTML = 'Buscando NIT… <span class="spinner-border spinner-border-sm align-middle" role="status" aria-hidden="true"></span>';

      try {
        const url = api('g4s/lookup-nit') + '?nit=' + encodeURIComponent(nit);
        const js = await fetchJSON(url);
        if (js?.ok) {
          const nombre = js.nombre || '';
          receptorNameHidden.value = nombre;
          nitHelp.innerHTML = nombre
            ? `Nombre: <strong>${escapeHtml(nombre)}</strong>`
            : 'NIT válido. No se encontró nombre.';
        } else {
          receptorNameHidden.value = '';
          nitHelp.textContent = js?.error ? `No encontrado: ${js.error}` : 'No se encontró el NIT.';
        }
      } catch (err) {
        receptorNameHidden.value = '';
        nitHelp.textContent = 'Error al consultar el NIT.';
        console.error(err);
      }
    }

    function debounceLookup() {
      if (nitLookupTimer) clearTimeout(nitLookupTimer);
      nitLookupTimer = setTimeout(() => doNitLookup(false), 450);
    }

    nitField.addEventListener('input', debounceLookup);
    nitField.addEventListener('blur', () => doNitLookup(false));
    btnCheckNit?.addEventListener('click', () => doNitLookup(true));

    // Submit — ENLAZAR SOLO UNA VEZ
    const form = document.getElementById('manualInvForm');
    if (!form.dataset.bound) {
      form.dataset.bound = '1';
      let submitting = false;

      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        if (submitting) return;        // ← evita submit doble
        submitting = true;

        const btnSubmit = document.getElementById('btnSubmitManualInv');
        btnSubmit?.setAttribute('disabled', 'disabled');

        const fd = new FormData(form);
        const payload = {
          reason: (fd.get('reason') || '').toString().trim(),
          receptor_nit: (fd.get('receptor_nit') || 'CF').toString().trim().toUpperCase(),
          mode: (fd.get('mode') || 'custom').toString(),
        };
        const receptorName = (fd.get('receptor_name') || '').toString().trim();
        if (receptorName) payload.receptor_name = receptorName;
        if (payload.mode === 'custom') payload.amount = Number(fd.get('amount') || 0);
        if (payload.mode === 'monthly' && monthlyRate != null) payload.amount = Number(monthlyRate);

        const m = Dialog.loading({ title: 'Generando', message: 'Creando factura…' });
        try {
          const res = await fetchJSON(api('fel/manual-invoice'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          });

          if (!res?.ok) {
            m.close();
            Dialog.alert({ title: 'Error', message: res?.error || 'No se pudo generar la factura' });
            return;
          }

          const msg = [
            `<b>Factura manual</b>`,
            `Motivo: ${escapeHtml(payload.reason)}`,
            `NIT: ${escapeHtml(payload.receptor_nit)}${payload.receptor_name ? ' — ' + escapeHtml(payload.receptor_name) : ''}`,
            `Monto: Q ${Number(res.billing_amount ?? 0).toFixed(2)}`,
            res.uuid ? `UUID: ${escapeHtml(res.uuid)}` : ''
          ].filter(Boolean).join('<br>');

          m.close();
          Dialog.alert({ title: 'Listo', html: msg });

          await refreshManualInvoicesTable();

          const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
          modal.hide();

          // Limpieza total por si Bootstrap dejó restos
          setTimeout(() => {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
            document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
          }, 150);
        } catch (err) {
          m.close();
          Dialog.alert({ title: 'Error', message: String(err) });
        } finally {
          submitting = false;
          btnSubmit?.removeAttribute('disabled');
        }
      });
    }

    modal.show();
  });

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
}


  const PAGE_STORAGE_KEY = 'zkt:lastPage';

  function rememberPage(page) {
    if (!window.localStorage || !page) return;
    try {
      window.localStorage.setItem(PAGE_STORAGE_KEY, page);
    } catch (error) {
      /* ignore storage errors */
    }
  }

  function restorePage() {
    if (!window.localStorage) return null;
    try {
      return window.localStorage.getItem(PAGE_STORAGE_KEY);
    } catch (error) {
      return null;
    }
  }

  // ===== Router simple para sidebar/offcanvas =====

  // 1) Mapa de renderizadores (asegúrate de que existan esas funciones)
  const renderers = {
    dashboard: typeof renderDashboard === 'function' ? renderDashboard : async () => notImpl('Dashboard'),
    invoices:  typeof renderInvoices  === 'function' ? renderInvoices  : async () => notImpl('Facturación'),
    reports:   typeof renderReports   === 'function' ? renderReports   : async () => notImpl('Reportes'),
    settings:  typeof renderSettings  === 'function' ? renderSettings  : async () => notImpl('Ajustes'),
    ManualInvoice: typeof renderManualInvoiceModule === 'function' ? renderManualInvoiceModule : async () => notImpl('Factura manual'),
  };

  // (opcional) placeholder si falta algún módulo
  async function notImpl(name){
    const el = document.getElementById('app');
    if (!el) return;
    el.innerHTML = `
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-2">${name}</h5>
          <p class="text-muted mb-0">Este módulo aún no está implementado.</p>
        </div>
      </div>`;
  }

  // 2) Helpers para activar el item seleccionado en ambos menús
  function setActiveNav(page){
    // Desktop
    document.querySelectorAll('#sidebarCollapse .nav-link').forEach(a=>{
      if (a.dataset.page === page) a.classList.add('active');
      else a.classList.remove('active');
    });
    // Offcanvas móvil
    document.querySelectorAll('#sidebarOffcanvas .nav-link').forEach(a=>{
      if (a.dataset.page === page) a.classList.add('active');
      else a.classList.remove('active');
    });
  }

  // 3) Navegar/renderizar
  async function renderPage(page){
    const fn = renderers[page];
    if (!fn){
      // Fallback si el hash es raro
      location.hash = '#/dashboard';
      return;
    }
    setActiveNav(page);
    try {
      await fn();
    } catch (e){
      const el = document.getElementById('app');
      if (el){
        el.innerHTML = `
          <div class="card shadow-sm">
            <div class="card-body">
              <h5 class="card-title text-danger">Error al cargar ${page}</h5>
              <pre class="small mb-0">${(e && e.message) ? e.message : String(e)}</pre>
            </div>
          </div>`;
      }
      console.error(e);
    }
  }

  function parseHash(){
    // soporta #/invoices, #invoices, o vacío
    const h = (location.hash || '').replace(/^#\/?/, '').trim();
    return h || 'dashboard';
  }

  async function navigate(page, {push=true} = {}){
    if (push) location.hash = `#/${page}`;
    await renderPage(page);
  }

  // 4) Eventos de click en ambos menús (delegación)
  function bindNavClicks(){
    // Desktop
    const side = document.getElementById('sidebarCollapse');
    if (side){
      side.addEventListener('click', (ev)=>{
        const a = ev.target.closest('a.nav-link[data-page]');
        if (!a) return;
        ev.preventDefault();
        const page = a.dataset.page;
        navigate(page);
      });
    }
    // Offcanvas móvil
    const off = document.getElementById('sidebarOffcanvas');
    if (off){
      off.addEventListener('click', (ev)=>{
        const a = ev.target.closest('a.nav-link[data-page]');
        if (!a) return;
        ev.preventDefault();
        const page = a.dataset.page;
        navigate(page);
        // El offcanvas se cierra solo por data-bs-dismiss en el <a>, pero por si acaso:
        const bsOff = bootstrap.Offcanvas.getInstance(off);
        if (bsOff) bsOff.hide();
      });
    }
  }

  // 5) Soporte de navegación por hash (back/forward del navegador)
  window.addEventListener('hashchange', () => {
    const page = parseHash();
    renderPage(page);
  });

  // 6) Arranque
  document.addEventListener('DOMContentLoaded', () => {
    bindNavClicks();
    renderPage(parseHash());
  });

  async function goToPage(page, { force = false } = {}) {
    const target = renderers[page] ? page : 'dashboard';

    if (!force && target === currentPage) {
      const activeLink = document.querySelector(`.nav-link[data-page="${target}"]`);
      if (activeLink) {
        setActive(activeLink);
      }
      return;
    }

    const requestId = ++renderGeneration;
    const link = document.querySelector(`.nav-link[data-page="${target}"]`);
    if (link) {
      setActive(link);
      link.setAttribute('aria-current', 'page');
    }
    sidebarLinks.forEach((item) => {
      if (item !== link) {
        item.removeAttribute('aria-current');
      }
    });

    const renderer = renderers[target] || renderDashboard;
    try {
      await renderer();
    } catch (error) {
      console.error('Error al renderizar la vista', error);
    } finally {
      if (renderGeneration === requestId) {
        currentPage = target;
        rememberPage(target);
      }
    }
  }

  function initNav() {
    sidebarLinks.forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        const page = link.getAttribute('data-page');
        if (page) {
          void goToPage(page);
        }
      });
    });

    document.addEventListener('click', (event) => {
      const control = event.target.closest('[data-go-page]');
      if (!control) return;
      const page = control.getAttribute('data-go-page');
      if (!page) return;
      event.preventDefault();
      void goToPage(page);
    });

    const availablePages = new Set(
      sidebarLinks
        .map((link) => link.getAttribute('data-page'))
        .filter(Boolean)
    );

    const storedPage = restorePage();
    const initialLink = document.querySelector('.nav-link.active[data-page]') || sidebarLinks[0];
    let initialPage = initialLink?.getAttribute('data-page') || 'dashboard';

    if (storedPage && availablePages.has(storedPage)) {
      initialPage = storedPage;
    }

    void goToPage(initialPage, { force: true });
  }

  initNav();
})();
