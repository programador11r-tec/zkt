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

  async function safeLoadSettings(force = false) {
    const payload = await loadSettings(force); // puede devolver { ok, settings } o solo settings
    return payload && typeof payload === 'object' && 'settings' in payload
      ? payload.settings
      : payload;
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
      // 1) Primero tickets (caseta sufre si paralelizamos con settings)
      const ticketsResp = await getTicketsSafe();
      const data = normalizeTickets(ticketsResp);

      // 2) Luego settings, desempaquetado
      let settings = null;
      try {
        settings = await safeLoadSettings(); // <<— aquí va el wrapper
      } catch (_) {
        settings = null; // si falla, seguimos con defaults
      }

      const state = { search: '', page: 1 };
      const pageSize = 20;

      // 3) Métricas con defaults/fallbacks
      const metrics = settings?.database?.metrics ?? {};
      const pending = Number.isFinite(Number(metrics.pending_invoices)) ? Number(metrics.pending_invoices) : 0;

      // Fallback para tickets_total si el backend no lo manda
      const ticketsTotal =
        Number.isFinite(Number(metrics.tickets_total))
          ? Number(metrics.tickets_total)
          : (Number(ticketsResp?.meta?.total) || data.length || 0);

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
          value: formatNumber(ticketsTotal),
          detail: metrics.tickets_last_sync
            ? `Último registro ${formatRelativeTime(metrics.tickets_last_sync)}`
            : (ticketsTotal > 0 ? 'Conteo local' : 'Sin registros almacenados'),
          accent: 'info',
          icon: 'bi-database-fill',
        },
        {
          title: 'Facturas emitidas',
          badge: { text: 'FEL', variant: 'success' },
          value: formatNumber(
            Number.isFinite(Number(metrics.invoices_total)) ? Number(metrics.invoices_total) : 0
          ),
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

      // Layout principal (igual al tuyo)
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

      // Timeline (usa settings desempaquetado)
      const timelineContainer = document.getElementById('activityTimeline');
      if (timelineContainer) {
        const tl = buildTimeline(settings?.activity);
        timelineContainer.innerHTML = tl || `<div class="text-muted small">Sin actividad reciente.</div>`;
      }

      // Botón refrescar actividad
      const timelineRefresh = document.getElementById('timelineRefresh');
      if (timelineRefresh) {
        timelineRefresh.addEventListener('click', async () => {
          const original = timelineRefresh.innerHTML;
          timelineRefresh.disabled = true;
          timelineRefresh.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Actualizando…`;
          let hadError = false;
          try {
            await safeLoadSettings(true);
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

      // Tabla + paginación (igual que tu versión)
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
          tbody.innerHTML = pageItems.map((row, index) => `
            <tr>
              <td>${escapeHtml(start + index + 1)}</td>
              <td>${escapeHtml(row.name)}</td>
              <td>${escapeHtml(row.checkIn || '-')}</td>
              <td>${escapeHtml(row.checkOut || '-')}</td>
            </tr>
          `).join('');
        } else {
          const message = data.length && !filtered.length ? 'No se encontraron resultados' : 'Sin registros disponibles';
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
          if (state.page > 1) { state.page -= 1; renderTable(); }
        });
      }
      if (nextBtn) {
        nextBtn.addEventListener('click', () => {
          const totalPages = Math.max(1, Math.ceil(filterData().length / pageSize));
          if (state.page < totalPages) { state.page += 1; renderTable(); }
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

    // ===== Facturación (tabla + Facturar) =====
 async function renderInvoices() {
  // === Carga de settings + helpers ===
  const settings = await loadSettings();

  function parseMoneyLike(x){
    if (x == null) return null;
    if (typeof x === 'number') return Number.isFinite(x) ? x : null;
    const s = String(x).trim(); if (!s) return null;
    const cleaned = s.replace(/[^\d.,-]/g,'').replace(/,/g,'.');
    const n = Number(cleaned);
    return Number.isFinite(n) ? n : null;
  }

  async function getHourlyRateFromOverview(){
    try {
      const res = await fetchJSON(api('settings'));
      const raw = res?.settings?.billing?.hourly_rate ?? null; // desempaqueta si viene envuelto
      const v = parseMoneyLike(raw);
      return (v && v > 0) ? v : null;
    } catch { return null; }
  }

  const hourlyFromOverview = await getHourlyRateFromOverview();
  const hourlyFromLoad     = parseMoneyLike(settings?.billing?.hourly_rate ?? null);
  const hourlyRateResolved = hourlyFromOverview ?? hourlyFromLoad; // puede ser null

  const debounce = (fn, ms = 500) => { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); }; };

  async function lookupNit(nitRaw) {
    const nit = (nitRaw ?? '').toString().trim().toUpperCase();
    const q = nit === 'CF' ? 'CF' : nit.replace(/\D+/g, '');
    return await fetchJSON(api(`g4s/lookup-nit?nit=${encodeURIComponent(q)}`), { method: 'GET' });
  }

  const escapeHtml = (v) => String(v ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');

  const formatCurrency = (value) => {
    if (value === null || value === undefined || value === '') return '—';
    const num = Number(value);
    if (!Number.isFinite(num)) return '—';
    try { return num.toLocaleString('es-GT', { style: 'currency', currency: 'GTQ' }); }
    catch { return `Q${num.toFixed(2)}`; }
  };

  const formatDateTime = (isoLike) => {
    try { return new Date(isoLike).toLocaleString('es-GT'); } catch { return String(isoLike ?? ''); }
  };
  const formatRelativeTime = (isoLike) => {
    try {
      const d = new Date(isoLike).getTime();
      const now = Date.now();
      const diff = Math.round((now - d) / 1000);
      if (!Number.isFinite(diff)) return '';
      if (diff < 60) return `hace ${diff}s`;
      const m = Math.round(diff/60); if (m < 60) return `hace ${m}m`;
      const h = Math.round(m/60); if (h < 24) return `hace ${h}h`;
      const dd = Math.round(h/24); return `hace ${dd}d`;
    } catch { return ''; }
  };

  const app = document.getElementById('app') || document.body;
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
            <input type="search" id="invSearch" class="form-control form-control-sm" placeholder="Buscar ticket..." />
            <div class="btn-group btn-group-sm" role="group">
              <button type="button" class="btn btn-outline-warning" id="btnManualOpen" title="Abrir SALIDA">
                <i class="bi bi-box-arrow-right me-1"></i> Salida
              </button>
              <button type="button" class="btn btn-outline-primary" id="btnManualOpenIn" title="Abrir ENTRADA">
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
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" id="invPrev"><i class="bi bi-chevron-left"></i> Anterior</button>
            <button type="button" class="btn btn-outline-secondary" id="invNext">Siguiente <i class="bi bi-chevron-right"></i></button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal de confirmación -->
    <div class="modal fade" id="invoiceConfirmModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="invoiceConfirmForm">
          <div class="modal-header">
            <h5 class="modal-title">Confirmar cobro</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar" data-action="cancel"></button>
          </div>
          <div class="modal-body">
            <div class="border rounded p-2 mb-3 bg-light">
              <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Ticket</span><span id="mSumTicket">—</span></div>
              <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Fecha</span><span id="mSumFecha">—</span></div>
              <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Tiempo computado</span><span id="mSumHoras">—</span></div>
              <div class="d-flex justify-content-between small" id="mSumTotalHourlyRow" hidden>
                <span class="text-muted">Total por hora</span><span id="mSumTotalHourly">—</span>
              </div>
            </div>

            <div class="form-check">
              <input class="form-check-input" type="radio" name="billingMode" id="mModeHourly" value="hourly">
              <label class="form-check-label" for="mModeHourly">
                Cobro por hora <strong class="ms-1" id="mHourlyLabel"></strong>
              </label>
              <div class="form-text" id="mHourlyHelp">Tarifa × horas (ceil de minutos/60).</div>
            </div>

            <div class="form-check mt-2">
              <input class="form-check-input" type="radio" name="billingMode" id="mModeGrace" value="grace">
              <label class="form-check-label" for="mModeGrace">Ticket de gracia <strong class="ms-1">Q0.00</strong></label>
              <div class="form-text">No se cobra ni se envía a FEL; se notifica a PayNotify.</div>
            </div>

            <div class="form-check mt-2">
              <input class="form-check-input" type="radio" name="billingMode" id="mModeCustom" value="custom">
              <label class="form-check-label" for="mModeCustom">Cobro personalizado</label>
            </div>

            <div class="input-group input-group-sm mt-2">
              <span class="input-group-text">Q</span>
              <input type="number" step="0.01" min="0" class="form-control" id="mCustomInput" placeholder="0.00" disabled>
            </div>

            <div class="mt-3">
              <label for="mNit" class="form-label mb-1">NIT del cliente</label>
              <input type="text" id="mNit" class="form-control" placeholder='Escribe el NIT o "CF"' value="CF" autocomplete="off" inputmode="numeric" aria-describedby="mNitHelp mNitStatus">
              <div class="form-text" id="mNitHelp">Escribe el NIT o “CF”. Si ingresas NIT, se consultará en SAT (G4S).</div>
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

  const tbody = document.getElementById('invRows');
  const searchInput = document.getElementById('invSearch');
  const meta = document.getElementById('invMeta');
  const prevBtn = document.getElementById('invPrev');
  const nextBtn = document.getElementById('invNext');
  const hourlyRateHint = document.getElementById('invoiceHourlyRateHint');

  const state = { search: '', page: 1 };
  const pageSize = 20;
  let allRows = [];

  // Aperturas manuales
  const CHANNEL_SALIDA  = '40288048981adc4601981b7cb2660b05';
  const CHANNEL_ENTRADA = '40288048981adc4601981b7c2d010aff';

  function wireManualOpen(buttonEl, { title, channelId }) {
    if (!buttonEl || buttonEl.dataset.bound === '1') return;
    buttonEl.dataset.bound = '1';
    let busy = false;

    buttonEl.addEventListener('click', async () => {
      if (busy) return;
      const proceed = window.confirm(`¿Aperturar barrera manual (${title})?`);
      if (!proceed) return;

      let reason = window.prompt(`Motivo de apertura (${title}):`) || '';
      reason = reason.trim();
      if (reason.length < 5) { alert('Motivo muy corto.'); return; }
      if (reason.length > 255) { alert('Motivo muy largo.'); return; }

      busy = true;
      buttonEl.disabled = true;
      const original = buttonEl.innerHTML;
      buttonEl.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Aperturando…`;

      try {
        const res = await fetchJSON(api('gate/manual-open'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ reason, channel_id: channelId })
        });

        if (!res.ok) throw new Error(res.message || 'No se pudo aperturar');
        alert(`Apertura manual (${title}) ejecutada.`);
      } catch (e) {
        alert(`Error: ${e.message}`);
      } finally {
        buttonEl.disabled = false;
        buttonEl.innerHTML = original;
        busy = false;
      }
    });
  }

  wireManualOpen(document.getElementById('btnManualOpen'),   { title: 'Salida',  channelId: CHANNEL_SALIDA });
  wireManualOpen(document.getElementById('btnManualOpenIn'), { title: 'Entrada', channelId: CHANNEL_ENTRADA });

  if (hourlyRateHint) {
    hourlyRateHint.textContent =
      Number.isFinite(hourlyRateResolved) && hourlyRateResolved > 0
        ? `Tarifa por hora ${formatCurrency(hourlyRateResolved)}.`
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

  // ---- Modal de confirmación
  function openInvoiceConfirmation(ticket, billingConfig = {}) {
    return new Promise((resolve) => {
      // minutos
      let minutes = null;
      const dm = Number(ticket.duration_minutes);
      if (Number.isFinite(dm) && dm > 0) minutes = Math.round(dm);
      else {
        const rawH = Number(ticket.hours);
        if (Number.isFinite(rawH) && rawH > 0) minutes = Math.round(rawH > 12 ? rawH : rawH * 60);
      }

      const hoursBilled = Number.isFinite(minutes) && minutes > 0 ? Math.ceil(minutes / 60) : null;

      const hourlyRateNumber = Number(billingConfig?.hourly_rate ?? null);
      const hourlyRate = Number.isFinite(hourlyRateNumber) && hourlyRateNumber > 0 ? hourlyRateNumber : null;

      const canHourly   = hourlyRate !== null && Number.isFinite(hoursBilled) && hoursBilled > 0;
      const hourlyTotal = canHourly ? Math.round(hoursBilled * hourlyRate * 100) / 100 : null;

      const dateCandidate = ticket.exit_at || ticket.entry_at || ticket.fecha || null;

      // Refs modal
      const modalEl  = document.getElementById('invoiceConfirmModal');
      const form     = document.getElementById('invoiceConfirmForm');
      const mErr     = document.getElementById('mError');
      const mCustom  = document.getElementById('mCustomInput');
      const mHourly  = document.getElementById('mModeHourly');
      const mGrace   = document.getElementById('mModeGrace');
      const mCustomR = document.getElementById('mModeCustom');
      const mNit     = document.getElementById('mNit');
      const mNitStatus = document.getElementById('mNitStatus');
      const mHourlyLabel = document.getElementById('mHourlyLabel');
      const mHourlyHelp  = document.getElementById('mHourlyHelp');

      // Resumen
      document.getElementById('mSumTicket').textContent = String(ticket.ticket_no ?? '');
      document.getElementById('mSumFecha').textContent  =
        dateCandidate ? `${formatDateTime(dateCandidate)} (${formatRelativeTime(dateCandidate)})` : '—';
      document.getElementById('mSumHoras').textContent  =
        Number.isFinite(minutes) && minutes > 0 ? `${hoursBilled} h (ceil), ${minutes} min` : '—';

      const totalHourlyRow = document.getElementById('mSumTotalHourlyRow');
      if (hourlyTotal != null) {
        totalHourlyRow.hidden = false;
        document.getElementById('mSumTotalHourly').textContent = formatCurrency(hourlyTotal);
        mHourlyLabel.textContent = formatCurrency(hourlyTotal);
        mHourlyHelp.textContent  = `Tarifa ${formatCurrency(hourlyRate)} × ${hoursBilled} h (ceil).`;
      } else {
        totalHourlyRow.hidden = true;
        mHourlyLabel.textContent = '';
        mHourlyHelp.textContent  = 'Define una tarifa por hora válida.';
      }

      // Estado inicial
      mHourly.disabled = !canHourly;
      if (canHourly) { mHourly.checked = true; mCustom.disabled = true; }
      else           { mGrace.checked = true; mCustom.disabled = true; }

      // NIT
      mNit.value = 'CF';
      mErr.hidden = true; mErr.textContent = '';
      mNitStatus.className = 'small mt-1 text-muted'; mNitStatus.textContent = 'Consumidor final (CF).';

      const normalizeNit = (v) => {
        v = (v || '').toUpperCase().trim();
        if (v === 'CF' || v === 'C') return v.length === 1 ? 'C' : 'CF';
        return v.replace(/\D+/g, '');
      };
      const setNitStatus = (text, cls = 'text-muted') => {
        mNitStatus.className = `small mt-1 ${cls}`;
        mNitStatus.textContent = text || '';
      };
      const doLookup = debounce(async () => {
        const v = mNit.value;
        if (!v || v.toUpperCase() === 'CF') { setNitStatus('Consumidor final (CF).', 'text-muted'); return; }
        if (!/\d{6,}/.test(v.replace(/\D+/g,''))) { setNitStatus('Ingresa al menos 6 dígitos para consultar.', 'text-muted'); return; }
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
        if (norm !== cur) { e.target.value = norm; e.target.setSelectionRange(norm.length, norm.length); }
        doLookup();
      });

      function updateCustomState() {
        const isCustom = document.getElementById('mModeCustom').checked;
        mCustom.disabled = !isCustom;
        if (isCustom) { mCustom.focus(); mCustom.select(); }
        mErr.hidden = true; mErr.textContent = '';
      }
      mHourly.addEventListener('change', updateCustomState);
      mGrace.addEventListener('change', updateCustomState);
      mCustomR.addEventListener('change', updateCustomState);

      function closeModal(retVal = null) {
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        const oldBackdrop = document.querySelector('.modal-backdrop');
        if (oldBackdrop) oldBackdrop.remove();
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        resolve(retVal);
      }
      form.querySelectorAll('[data-action="cancel"]').forEach((b) => b.addEventListener('click', () => closeModal(null), { once: true }));
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
          if (!canHourly || hourlyTotal == null) {
            mErr.textContent = 'Configura una tarifa por hora válida.';
            mErr.hidden = false; return;
          }
          closeModal({
            mode: 'hourly',
            total: hourlyTotal,
            label: `Cobro por hora ${formatCurrency(hourlyTotal)}`,
            receptor_nit: receptorNit,
            duration_minutes: minutes,
            hours_billed_used: hoursBilled,
            hourly_rate_used: hourlyRate
          });
          return;
        }

        if (selected === 'grace') {
          closeModal({ mode: 'grace', total: 0, label: 'Ticket de gracia (Q0.00, sin FEL)', receptor_nit: receptorNit });
          return;
        }

        const customVal = parseFloat(mCustom.value);
        if (!Number.isFinite(customVal) || customVal <= 0) {
          mErr.textContent = 'Ingresa un total personalizado mayor a cero.';
          mErr.hidden = false; return;
        }
        const normalized = Math.round(customVal * 100) / 100;
        closeModal({ mode: 'custom', total: normalized, label: `Cobro personalizado ${formatCurrency(normalized)}`, receptor_nit: receptorNit });
      };

      // mostrar modal
      modalEl.style.display = 'block';
      setTimeout(() => modalEl.classList.add('show'), 10);
      const backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop fade show';
      document.body.appendChild(backdrop);
      document.body.classList.add('modal-open');
    });
  }

  function attachInvoiceHandlers() {
    tbody.querySelectorAll('[data-action="invoice"]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const payload = JSON.parse(decodeURIComponent(btn.getAttribute('data-payload')));
        btn.disabled = true;
        const originalHTML = btn.innerHTML;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Confirmando…`;
        try {
          const settingsSnapshot = await loadSettings();
          const hourlySnapshotOverview = await getHourlyRateFromOverview();
          const hourlySnapshotLoad     = parseMoneyLike(settingsSnapshot?.billing?.hourly_rate);
          const hourlySnapshot         = hourlySnapshotOverview ?? hourlySnapshotLoad ?? null;

          const billingCfg = { ...(settingsSnapshot?.billing ?? {}), hourly_rate: hourlySnapshot };
          const confirmation = await openInvoiceConfirmation(payload, billingCfg);
          if (!confirmation) { btn.disabled = false; btn.innerHTML = originalHTML; return; }

          btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Enviando…`;

          const receptorNit = (confirmation.receptor_nit || payload.receptor_nit || 'CF').toString().toUpperCase();

          const requestPayload = {
            ticket_no: payload.ticket_no,
            plate: payload.plate,
            receptor_nit: receptorNit,
            serie: payload.serie || 'A',
            numero: payload.numero || null,
            mode: confirmation.mode
          };

          if (confirmation.mode === 'hourly') {
            const tot = Number(confirmation.total);
            requestPayload.total = Number.isFinite(tot) ? Math.round(tot * 100) / 100 : 0;
            requestPayload.duration_minutes  = Number.isFinite(Number(confirmation.duration_minutes)) ? Number(confirmation.duration_minutes) : 0;
            requestPayload.hours_billed_used = Number.isFinite(Number(confirmation.hours_billed_used)) ? Number(confirmation.hours_billed_used) : 0;
            requestPayload.hourly_rate_used  = Number.isFinite(Number(confirmation.hourly_rate_used)) ? Number(confirmation.hourly_rate_used) : 0;

          } else if (confirmation.mode === 'custom') {
            // Validación fuerte y envío de ambos campos
            const mCustomEl = document.getElementById('mCustomInput');
            const uiVal = mCustomEl ? Number(mCustomEl.value) : NaN;

            let val = Number(confirmation.total);
            if (!Number.isFinite(val) || val <= 0) val = uiVal;

            if (!Number.isFinite(val) || val <= 0) {
              alert('Monto personalizado inválido. Debe ser un número mayor a 0.');
              btn.disabled = false;
              btn.innerHTML = originalHTML;
              return;
            }
            const norm = Math.round(val * 100) / 100;
            requestPayload.custom_total = norm;
            requestPayload.total        = norm;

            // 👉 Blindaje para el back (evitar is_finite(null) en hourly_rate_used)
            requestPayload.duration_minutes  = 0;
            requestPayload.hours_billed_used = 0;
            requestPayload.hourly_rate_used  = 0;

          } else if (confirmation.mode === 'grace') {
            // También mandar ceros para blindar el back
            requestPayload.duration_minutes  = 0;
            requestPayload.hours_billed_used = 0;
            requestPayload.hourly_rate_used  = 0;
          }

          console.log('REQ → /api/fel/invoice', requestPayload);

          const js = await fetchJSON(api('fel/invoice'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestPayload),
          });

          if (!js.ok) throw new Error(js.error || 'No se pudo certificar.');

          const uuidTxt = js.uuid ? `UUID ${js.uuid}` : 'Sin UUID';
          alert(`Factura enviada (${confirmation.label}). ${uuidTxt}`);

          await loadList();
        } catch (e) {
          alert(`Error: ${e.message}`);
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
        const totalFmt = formatCurrency(parseMoneyLike(d.total));
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

  const invoiceState = {
    rows: [],
    summary: null,
    generatedAt: null,
    filters: { from: defaultFrom, to: defaultTo, status: 'ANY', nit: '', uuid: '' },
    page: 1,
    perPage: 10,
  };

  const manualInvoiceState = {
    rows: [],
    summary: null,
    page: 1,
    perPage: 10,
    filters: { from: defaultFrom, to: defaultTo, mode: 'ANY', nit: '', reason: '' },
  };

  const manualOpenState = {
    rows: [],
    summary: null,
    page: 1,
    perPage: 10,
    filters: { from: defaultFrom, to: defaultTo, q: '' },
  };

  // === Helpers ===
  const escapeHtml = (v) => String(v ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const formatDateValue = (v) => !v ? '—' : new Date(v).toLocaleString('es-GT');
  const formatCurrency = (n) => `Q ${Number(n ?? 0).toFixed(2)}`;
  const formatInvoiceStatus = (s) =>
    s === 'OK' ? 'Certificada'
      : s === 'PENDING' ? 'Pendiente'
      : s === 'ERROR' ? 'Error'
      : s || '—';

  const downloadBlob = (blob, filename) => {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  };

  const paginate = (rows, page, perPage) => {
    const totalPages = Math.ceil(rows.length / perPage) || 1;
    const p = Math.min(Math.max(page, 1), totalPages);
    const start = (p - 1) * perPage;
    const end = start + perPage;
    return { slice: rows.slice(start, end), totalPages, currentPage: p };
  };

  const buildPagination = (container, state, renderFn) => {
    const { totalPages, currentPage } = paginate(state.rows, state.page, state.perPage);
    if (totalPages <= 1) { container.innerHTML = ''; return; }
    let html = `<nav><ul class="pagination pagination-sm justify-content-center mb-0">`;
    for (let i = 1; i <= totalPages; i++) {
      html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
        <button class="page-link" data-page="${i}">${i}</button></li>`;
    }
    html += `</ul></nav>`;
    container.innerHTML = html;
    container.querySelectorAll('button[data-page]').forEach((b) => {
      b.addEventListener('click', () => { state.page = parseInt(b.dataset.page); renderFn(); });
    });
  };

  // ✅ saca uuid de varios posibles campos
  const getUuid = (r) => {
    if (r?.fel_uuid) return r.fel_uuid;
    if (r?.uuid) return r.uuid;
    try {
      const j = JSON.parse(r?.response_json || '{}');
      return j?.uuid || j?.data?.uuid || null;
    } catch { return null; }
  };

  // === UI principal ===
  app.innerHTML = `
  <div class="d-flex flex-column gap-4">
    <!-- Reporte facturas emitidas (automáticas) -->
    <section class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h5 class="card-title mb-1">Reporte de facturas emitidas</h5>
            <p class="text-muted small mb-0">Descarga documentos certificados y consulta su estado.</p>
          </div>
          <span class="badge text-bg-success">Facturación</span>
        </div>

        <form id="invoiceFilters" class="row g-3 mt-3">
          <div class="col-md-3">
            <label class="form-label small" for="invoiceFrom">Desde</label>
            <input type="date" id="invoiceFrom" class="form-control form-control-sm" value="${escapeHtml(defaultFrom)}">
          </div>
          <div class="col-md-3">
            <label class="form-label small" for="invoiceTo">Hasta</label>
            <input type="date" id="invoiceTo" class="form-control form-control-sm" value="${escapeHtml(defaultTo)}">
          </div>
          <div class="col-md-3">
            <label class="form-label small" for="invoiceStatus">Estado</label>
            <select id="invoiceStatus" class="form-select form-select-sm">
              <option value="ANY">Todos</option>
              <option value="OK">OK</option>
              <option value="PENDING">Pendiente</option>
              <option value="ERROR">Error</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small" for="invoiceNit">NIT</label>
            <input type="text" id="invoiceNit" class="form-control form-control-sm" placeholder="CF o NIT">
          </div>
        </form>

        <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-primary" id="invoiceFetch">Buscar</button>
            <button type="button" class="btn btn-outline-secondary" id="invoiceReset">Limpiar</button>
          </div>
          <div class="ms-auto d-flex gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm" id="invoiceCsv" disabled>Exportar CSV</button>
          </div>
        </div>

        <div id="invoiceAlert" class="mt-3"></div>

        <div id="invoiceSummary" class="row g-3 mt-2"></div>
        <div class="table-responsive mt-3">
          <table class="table table-sm table-bordered align-middle mb-0">
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
              <tr><td colspan="7" class="text-center text-muted">Consulta para ver resultados.</td></tr>
            </tbody>
          </table>
        </div>
        <div id="invoicePagination" class="mt-2"></div>
        <div id="invoiceMessage" class="small text-muted mt-2"></div>
      </div>
    </section>

    <!-- Reporte de facturas manuales -->
    <section class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h5 class="card-title mb-1">Reporte de facturas manuales</h5>
            <p class="text-muted small mb-0">Facturas creadas desde el módulo de facturación manual.</p>
          </div>
          <span class="badge text-bg-info">Manual</span>
        </div>

        <form id="manualInvoiceFilters" class="row g-3 mt-3">
          <div class="col-md-3">
            <label class="form-label small" for="manualInvoiceFrom">Desde</label>
            <input type="date" id="manualInvoiceFrom" class="form-control form-control-sm" value="${escapeHtml(defaultFrom)}">
          </div>
          <div class="col-md-3">
            <label class="form-label small" for="manualInvoiceTo">Hasta</label>
            <input type="date" id="manualInvoiceTo" class="form-control form-control-sm" value="${escapeHtml(defaultTo)}">
          </div>
          <div class="col-md-3">
            <label class="form-label small" for="manualInvoiceMode">Modo</label>
            <select id="manualInvoiceMode" class="form-select form-select-sm">
              <option value="ANY">Todos</option>
              <option value="custom">Monto libre</option>
              <option value="monthly">Mensualidad</option>
              <option value="grace">Gracia</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small" for="manualInvoiceNit">NIT</label>
            <input type="text" id="manualInvoiceNit" class="form-control form-control-sm" placeholder="CF o NIT">
          </div>
          <div class="col-md-6">
            <label class="form-label small" for="manualInvoiceReason">Motivo (contiene)</label>
            <input type="text" id="manualInvoiceReason" class="form-control form-control-sm" placeholder="Descripción / motivo">
          </div>
        </form>

        <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-primary" id="manualInvoiceFetch">Buscar</button>
            <button type="button" class="btn btn-outline-secondary" id="manualInvoiceReset">Limpiar</button>
          </div>
          <div class="ms-auto d-flex gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm" id="manualInvoiceCsv" disabled>Exportar CSV</button>
          </div>
        </div>

        <div id="manualInvoiceAlert" class="mt-3"></div>

        <div id="manualInvoiceSummary" class="row g-3 mt-2"></div>
        <div class="table-responsive mt-3">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Fecha</th>
                <th class="text-end">Monto</th>
                <th>Motivo</th>
                <th>Modo</th>
                <th>NIT</th>
                <th>UUID</th>
                <th>Estado</th>
                <th class="text-center">PDF</th>
              </tr>
            </thead>
            <tbody id="manualInvoiceRows">
              <tr><td colspan="9" class="text-center text-muted">Consulta para ver resultados.</td></tr>
            </tbody>
          </table>
        </div>
        <div id="manualInvoicePagination" class="mt-2"></div>
        <div id="manualInvoiceMessage" class="small text-muted mt-2"></div>
      </div>
    </section>

    <!-- Reporte de aperturas manuales -->
    <section class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h5 class="card-title mb-1">Reporte de aperturas manuales</h5>
            <p class="text-muted small mb-0">Log de aperturas manuales de barrera (entrada / salida).</p>
          </div>
          <span class="badge text-bg-warning">Aperturas</span>
        </div>

        <form id="manualOpenFilters" class="row g-3 mt-3">
          <div class="col-md-3">
            <label class="form-label small" for="manualOpenFrom">Desde</label>
            <input type="date" id="manualOpenFrom" class="form-control form-control-sm" value="${escapeHtml(defaultFrom)}">
          </div>
          <div class="col-md-3">
            <label class="form-label small" for="manualOpenTo">Hasta</label>
            <input type="date" id="manualOpenTo" class="form-control form-control-sm" value="${escapeHtml(defaultTo)}">
          </div>
          <div class="col-md-6">
            <label class="form-label small" for="manualOpenSearch">Texto (contiene)</label>
            <input type="text" id="manualOpenSearch" class="form-control form-control-sm"
                   placeholder="Buscar por motivo, canal o mensaje">
          </div>
        </form>

        <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-primary" id="manualOpenFetch">Buscar</button>
            <button type="button" class="btn btn-outline-secondary" id="manualOpenReset">Limpiar</button>
          </div>
          <div class="ms-auto d-flex gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm" id="manualOpenCsv" disabled>Exportar CSV</button>
          </div>
        </div>

        <div id="manualOpenAlert" class="mt-3"></div>

        <div id="manualOpenSummary" class="row g-3 mt-2"></div>
        <div class="table-responsive mt-3">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Tipo</th>
                <th>Fecha / hora</th>
                <th>Motivo</th>
                <th>Canal</th>
              </tr>
            </thead>
            <tbody id="manualOpenRows">
              <tr><td colspan="4" class="text-center text-muted">Consulta para ver resultados.</td></tr>
            </tbody>
          </table>
        </div>
        <div id="manualOpenPagination" class="mt-2"></div>
        <div id="manualOpenMessage" class="small text-muted mt-2"></div>
      </div>
    </section>
  </div>
  `;

  // === DOM refs ===
  const invoiceRowsEl = document.getElementById('invoiceRows');
  const invoiceSummaryEl = document.getElementById('invoiceSummary');
  const invoiceMsgEl = document.getElementById('invoiceMessage');
  const invoicePaginationEl = document.getElementById('invoicePagination');
  const invoiceAlertEl = document.getElementById('invoiceAlert');
  const invoiceCsvBtn = document.getElementById('invoiceCsv');

  const manualInvoiceRowsEl = document.getElementById('manualInvoiceRows');
  const manualInvoiceSummaryEl = document.getElementById('manualInvoiceSummary');
  const manualInvoiceMsgEl = document.getElementById('manualInvoiceMessage');
  const manualInvoicePaginationEl = document.getElementById('manualInvoicePagination');
  const manualInvoiceAlertEl = document.getElementById('manualInvoiceAlert');
  const manualInvoiceCsvBtn = document.getElementById('manualInvoiceCsv');

  const manualOpenRowsEl = document.getElementById('manualOpenRows');
  const manualOpenSummaryEl = document.getElementById('manualOpenSummary');
  const manualOpenMsgEl = document.getElementById('manualOpenMessage');
  const manualOpenPaginationEl = document.getElementById('manualOpenPagination');
  const manualOpenAlertEl = document.getElementById('manualOpenAlert');
  const manualOpenCsvBtn = document.getElementById('manualOpenCsv');

  const showAlert = (el, message, type = 'info') => {
    if (!el) return;
    el.innerHTML = `
      <div class="alert alert-${type} alert-dismissible fade show" role="alert">
        ${escapeHtml(message)}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>`;
  };
  const clearAlert = (el) => { if (el) el.innerHTML = ''; };

  // === Facturas emitidas ===
  const fetchInvoiceReport = async () => {
    invoiceRowsEl.innerHTML = `<tr><td colspan="7" class="text-center text-muted">Consultando...</td></tr>`;
    invoiceSummaryEl.innerHTML = '';
    invoiceMsgEl.textContent = '';
    clearAlert(invoiceAlertEl);
    invoiceCsvBtn.disabled = true;

    const params = new URLSearchParams();
    const from = document.getElementById('invoiceFrom').value;
    const to = document.getElementById('invoiceTo').value;
    const status = document.getElementById('invoiceStatus').value;
    const nit = document.getElementById('invoiceNit').value;
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    if (status !== 'ANY') params.set('status', status);
    if (nit) params.set('nit', nit);

    try {
      const url = params.size ? `${api('facturacion/emitidas')}?${params}` : api('facturacion/emitidas');
      const js = await fetchJSON(url);
      if (!js || js.ok === false) throw new Error(js.error || 'Sin respuesta');
      const rows = (js.rows || []).map((r) => ({ ...r, total: Number(r.total ?? 0) }));

      invoiceState.rows = rows;
      invoiceState.summary = {
        total: rows.length,
        total_amount: rows.reduce((a, r) => a + (r.total ?? 0), 0),
        ok: rows.filter(r => (r.status || '').toUpperCase() === 'OK').length,
        pending: rows.filter(r => (r.status || '').toUpperCase() === 'PENDING').length,
        error: rows.filter(r => (r.status || '').toUpperCase() === 'ERROR').length,
      };
      invoiceState.page = 1;
      renderInvoiceRows();
      renderInvoiceSummary();
      invoiceCsvBtn.disabled = rows.length === 0;
    } catch (err) {
      invoiceRowsEl.innerHTML = `<tr><td colspan="7" class="text-center text-danger">Error: ${escapeHtml(err.message)}</td></tr>`;
      showAlert(invoiceAlertEl, `No se pudo cargar el reporte: ${err.message}`, 'danger');
    }
  };

  const renderInvoiceSummary = () => {
    const s = invoiceState.summary;
    if (!s) return;
    invoiceSummaryEl.innerHTML = `
      <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-body-tertiary h-100"><div class="card-body py-3">
          <div class="text-muted small">Facturas</div>
          <div class="fs-5 fw-semibold">${s.total}</div>
        </div></div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-body-tertiary h-100"><div class="card-body py-3">
          <div class="text-muted small">Monto total</div>
          <div class="fs-5 fw-semibold">${formatCurrency(s.total_amount)}</div>
        </div></div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-body-tertiary h-100"><div class="card-body py-3">
          <div class="text-muted small">Certificadas</div>
          <div class="fs-5 fw-semibold">${s.ok}</div>
        </div></div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-body-tertiary h-100"><div class="card-body py-3">
          <div class="text-muted small">Pendientes</div>
          <div class="fs-5 fw-semibold">${s.pending}</div>
        </div></div>
      </div>
    `;
    invoiceMsgEl.textContent = `Errores: ${s.error}`;
  };

  const renderInvoiceRows = () => {
    const { slice } = paginate(invoiceState.rows, invoiceState.page, invoiceState.perPage);
    if (!slice.length) {
      invoiceRowsEl.innerHTML = `<tr><td colspan="7" class="text-center text-muted">No hay registros.</td></tr>`;
      invoicePaginationEl.innerHTML = '';
      return;
    }
    invoiceRowsEl.innerHTML = slice.map((r) => {
      const status = (r.status || '').toUpperCase();
      const badge = status === 'OK'
        ? 'text-bg-success'
        : status === 'PENDING'
        ? 'text-bg-warning'
        : status === 'ERROR'
        ? 'text-bg-danger'
        : 'text-bg-secondary';

      const uuid = getUuid(r);
      const actions = uuid
        ? `<button type="button" class="btn btn-sm btn-outline-primary me-1"
            data-action="pdf"
            data-uuid="${escapeHtml(uuid)}">PDF</button>`
        : '<span class="badge text-bg-light border">Sin UUID</span>';

      return `
        <tr>
          <td>${escapeHtml(r.ticket_no)}</td>
          <td>${escapeHtml(formatDateValue(r.fecha))}</td>
          <td class="text-end">${formatCurrency(r.total)}</td>
          <td>${escapeHtml(r.receptor ?? 'CF')}</td>
          <td class="text-truncate" style="max-width:220px;">${escapeHtml(uuid ?? '—')}</td>
          <td><span class="badge ${badge}">${formatInvoiceStatus(status)}</span></td>
          <td class="text-center">${actions}</td>
        </tr>`;
    }).join('');
    buildPagination(invoicePaginationEl, invoiceState, renderInvoiceRows);
  };

  invoiceCsvBtn.addEventListener('click', () => {
    const rows = invoiceState.rows || [];
    if (!rows.length) return;

    const headers = ['ticket_no','fecha','total','receptor','uuid','status'];
    const csv = [
      headers.join(','),
      ...rows.map(r => [
        String(r.ticket_no ?? '').replaceAll('"','""'),
        new Date(r.fecha ?? '').toISOString(),
        Number(r.total ?? 0).toFixed(2),
        String(r.receptor ?? 'CF').replaceAll('"','""'),
        String(getUuid(r) ?? '').replaceAll('"','""'),
        String((r.status || '').toUpperCase()).replaceAll('"','""'),
      ].map(v => `"${v}"`).join(',')),
    ].join('\r\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    downloadBlob(blob, `facturas_${document.getElementById('invoiceFrom').value}_${document.getElementById('invoiceTo').value}.csv`);
  });

  // ✅ PDF emitidas SOLO binario G4S
  invoiceRowsEl.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('button[data-action="pdf"]');
    if (!btn) return;

    const uuid = btn.dataset.uuid || '';
    if (!uuid) {
      showAlert(invoiceAlertEl, 'Este registro no tiene UUID.', 'warning');
      return;
    }

    btn.disabled = true;
    const old = btn.textContent;
    btn.textContent = 'Generando...';
    clearAlert(invoiceAlertEl);

    try {
      const resp = await fetch(api('fel/invoice/pdf') + '?uuid=' + encodeURIComponent(uuid));
      if (!resp.ok) {
        const txt = await resp.text().catch(() => '');
        throw new Error(txt || `HTTP ${resp.status}`);
      }
      const blob = await resp.blob();
      downloadBlob(blob, `Factura-${uuid}.pdf`);
      showAlert(invoiceAlertEl, 'PDF generado desde G4S.', 'success');
    } catch (e) {
      showAlert(invoiceAlertEl, `No se pudo generar el PDF: ${e.message}`, 'danger');
    } finally {
      btn.textContent = old;
      btn.disabled = false;
    }
  });

  // === Facturas manuales ===
  const fetchManualInvoiceReport = async () => {
    manualInvoiceRowsEl.innerHTML = `<tr><td colspan="9" class="text-center text-muted">Consultando...</td></tr>`;
    manualInvoiceSummaryEl.innerHTML = '';
    manualInvoiceMsgEl.textContent = '';
    clearAlert(manualInvoiceAlertEl);
    manualInvoiceCsvBtn.disabled = true;

    const params = new URLSearchParams();
    const from = document.getElementById('manualInvoiceFrom').value;
    const to = document.getElementById('manualInvoiceTo').value;
    const mode = document.getElementById('manualInvoiceMode').value;
    const nit = document.getElementById('manualInvoiceNit').value;
    const reason = document.getElementById('manualInvoiceReason').value;

    if (from) params.set('from', from);
    if (to) params.set('to', to);
    if (mode !== 'ANY') params.set('mode', mode);
    if (nit) params.set('nit', nit);
    if (reason) params.set('reason', reason);

    try {
      const url = params.size
        ? `${api('fel/report-manual-invoice-list')}?${params}`
        : api('fel/report-manual-invoice-list');

      const js = await fetchJSON(url);
      if (!js || js.ok === false) throw new Error(js.error || 'Sin respuesta');

      const rows = (js.rows || []).map((r) => {
        const uuid = getUuid(r);
        return {
          ...r,
          amount: Number(r.amount ?? r.total ?? 0),
          uuid,
        };
      });

      manualInvoiceState.rows = rows;
      manualInvoiceState.summary = {
        total: rows.length,
        total_amount: rows.reduce((a, r) => a + (r.amount ?? 0), 0),
      };
      manualInvoiceState.page = 1;
      renderManualInvoiceRows();
      renderManualInvoiceSummary();
      manualInvoiceCsvBtn.disabled = rows.length === 0;
    } catch (err) {
      manualInvoiceRowsEl.innerHTML = `<tr><td colspan="9" class="text-center text-danger">Error: ${escapeHtml(err.message)}</td></tr>`;
      showAlert(manualInvoiceAlertEl, `No se pudo cargar el reporte: ${err.message}`, 'danger');
    }
  };

  const renderManualInvoiceSummary = () => {
    const s = manualInvoiceState.summary;
    if (!s) return;
    manualInvoiceSummaryEl.innerHTML = `
      <div class="col-sm-6 col-lg-4">
        <div class="card border-0 bg-body-tertiary h-100">
          <div class="card-body py-3">
            <div class="text-muted small">Facturas manuales</div>
            <div class="fs-5 fw-semibold">${s.total}</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-4">
        <div class="card border-0 bg-body-tertiary h-100">
          <div class="card-body py-3">
            <div class="text-muted small">Monto total</div>
            <div class="fs-5 fw-semibold">${formatCurrency(s.total_amount)}</div>
          </div>
        </div>
      </div>
    `;
    manualInvoiceMsgEl.textContent = '';
  };

  const renderManualInvoiceRows = () => {
    const { slice } = paginate(manualInvoiceState.rows, manualInvoiceState.page, manualInvoiceState.perPage);

    if (!slice.length) {
      manualInvoiceRowsEl.innerHTML = `<tr><td colspan="9" class="text-center text-muted">No hay registros.</td></tr>`;
      manualInvoicePaginationEl.innerHTML = '';
      return;
    }

    manualInvoiceRowsEl.innerHTML = slice.map((r) => {
      const mode = (r.mode || '').toLowerCase();
      const status = (r.status || '').toUpperCase();
      const badge = status === 'OK'
        ? 'text-bg-success'
        : status === 'PENDING'
        ? 'text-bg-warning'
        : status === 'ERROR'
        ? 'text-bg-danger'
        : 'text-bg-secondary';

      let modeLabel = '—';
      if (mode === 'custom') modeLabel = 'Monto libre';
      else if (mode === 'monthly') modeLabel = 'Mensualidad';
      else if (mode === 'grace') modeLabel = 'Gracia';

      const uuid = r.uuid || getUuid(r);

      const pdfCell = uuid
        ? `<button type="button"
                class="btn btn-sm btn-outline-primary"
                data-action="pdf-manual"
                data-uuid="${escapeHtml(uuid)}">
            PDF
          </button>`
        : `<span class="badge text-bg-light border">Sin UUID</span>`;

      return `
        <tr>
          <td>${escapeHtml(String(r.id ?? ''))}</td>
          <td>${escapeHtml(formatDateValue(r.created_at || r.fecha || r.createdAt))}</td>
          <td class="text-end">${formatCurrency(r.amount)}</td>
          <td>${escapeHtml(r.reason ?? '')}</td>
          <td>${escapeHtml(modeLabel)}</td>
          <td>${escapeHtml(r.receptor_nit ?? r.nit ?? 'CF')}</td>
          <td class="text-truncate" style="max-width:220px;">${escapeHtml(uuid ?? '—')}</td>
          <td><span class="badge ${badge}">${escapeHtml(status || '—')}</span></td>
          <td class="text-center">${pdfCell}</td>
        </tr>`;
    }).join('');

    buildPagination(manualInvoicePaginationEl, manualInvoiceState, renderManualInvoiceRows);
  };

  // ✅ PDF manual por UUID => G4S igual que emitidas
  manualInvoiceRowsEl.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('button[data-action="pdf-manual"]');
    if (!btn) return;

    const uuid = btn.dataset.uuid || '';
    if (!uuid) {
      showAlert(manualInvoiceAlertEl, 'Este registro no tiene UUID.', 'warning');
      return;
    }

    btn.disabled = true;
    const old = btn.textContent;
    btn.textContent = 'Generando…';
    clearAlert(manualInvoiceAlertEl);

    try {
      const resp = await fetch(api('fel/invoice/pdf') + '?uuid=' + encodeURIComponent(uuid));
      if (!resp.ok) {
        const txt = await resp.text().catch(() => '');
        throw new Error(txt || `HTTP ${resp.status}`);
      }

      const blob = await resp.blob();
      downloadBlob(blob, `Factura-${uuid}.pdf`);
      showAlert(manualInvoiceAlertEl, 'PDF generado desde G4S.', 'success');
    } catch (e) {
      showAlert(manualInvoiceAlertEl, `No se pudo generar el PDF: ${e.message}`, 'danger');
    } finally {
      btn.textContent = old;
      btn.disabled = false;
    }
  });

  manualInvoiceCsvBtn.addEventListener('click', () => {
    const rows = manualInvoiceState.rows || [];
    if (!rows.length) return;

    const headers = ['id','fecha','monto','motivo','modo','nit','uuid','status'];
    const csv = [
      headers.join(','),
      ...rows.map(r => [
        String(r.id ?? '').replaceAll('"','""'),
        new Date(r.created_at || r.fecha || r.createdAt || '').toISOString(),
        Number(r.amount ?? r.total ?? 0).toFixed(2),
        String(r.reason ?? '').replaceAll('"','""'),
        String(r.mode ?? '').replaceAll('"','""'),
        String(r.receptor_nit ?? r.nit ?? 'CF').replaceAll('"','""'),
        String(getUuid(r) ?? '').replaceAll('"','""'),
        String((r.status || '').toUpperCase()).replaceAll('"','""'),
      ].map(v => `"${v}"`).join(',')),
    ].join('\r\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    downloadBlob(
      blob,
      `facturas_manuales_${document.getElementById('manualInvoiceFrom').value}_${document.getElementById('manualInvoiceTo').value}.csv`
    );
  });

  // === Aperturas manuales ===
  const fetchManualOpenReport = async () => {
    manualOpenRowsEl.innerHTML = `<tr><td colspan="4" class="text-center text-muted">Consultando...</td></tr>`;
    manualOpenSummaryEl.innerHTML = '';
    manualOpenMsgEl.textContent = '';
    clearAlert(manualOpenAlertEl);
    manualOpenCsvBtn.disabled = true;

    const params = new URLSearchParams();
    const from = document.getElementById('manualOpenFrom').value;
    const to = document.getElementById('manualOpenTo').value;
    const q = document.getElementById('manualOpenSearch').value;

    if (from) params.set('from', from);
    if (to) params.set('to', to);
    if (q) params.set('q', q);

    try {
      const url = params.size ? `${api('reports/manual-open')}?${params}` : api('reports/manual-open');
      const js = await fetchJSON(url);
      if (!js || js.ok === false) throw new Error(js.error || 'Sin respuesta');

      const rows = js.rows || [];
      manualOpenState.rows = rows;
      manualOpenState.summary = {
        total: rows.length,
        entradas: rows.filter(r => (r.tipo || '').toUpperCase() === 'ENTRADA').length,
        salidas:  rows.filter(r => (r.tipo || '').toUpperCase() === 'SALIDA').length,
      };
      manualOpenState.page = 1;
      renderManualOpenRows();
      renderManualOpenSummary();
      manualOpenCsvBtn.disabled = rows.length === 0;
    } catch (err) {
      manualOpenRowsEl.innerHTML = `<tr><td colspan="4" class="text-center text-danger">Error: ${escapeHtml(err.message)}</td></tr>`;
      showAlert(manualOpenAlertEl, `No se pudo cargar el reporte: ${err.message}`, 'danger');
    }
  };

  const renderManualOpenSummary = () => {
    const s = manualOpenState.summary;
    if (!s) return;
    manualOpenSummaryEl.innerHTML = `
      <div class="col-sm-6 col-lg-4">
        <div class="card border-0 bg-body-tertiary h-100"><div class="card-body py-3">
          <div class="text-muted small">Aperturas manuales</div>
          <div class="fs-5 fw-semibold">${s.total}</div>
        </div></div>
      </div>
      <div class="col-sm-6 col-lg-4">
        <div class="card border-0 bg-body-tertiary h-100"><div class="card-body py-3">
          <div class="text-muted small">Entradas</div>
          <div class="fs-5 fw-semibold">${s.entradas}</div>
        </div></div>
      </div>
      <div class="col-sm-6 col-lg-4">
        <div class="card border-0 bg-body-tertiary h-100"><div class="card-body py-3">
          <div class="text-muted small">Salidas</div>
          <div class="fs-5 fw-semibold">${s.salidas}</div>
        </div></div>
      </div>
    `;
    manualOpenMsgEl.textContent = '';
  };

  const renderManualOpenRows = () => {
    const { slice } = paginate(manualOpenState.rows, manualOpenState.page, manualOpenState.perPage);
    if (!slice.length) {
      manualOpenRowsEl.innerHTML = `<tr><td colspan="4" class="text-center text-muted">No hay registros.</td></tr>`;
      manualOpenPaginationEl.innerHTML = '';
      return;
    }

    manualOpenRowsEl.innerHTML = slice.map((r) => {
      const tipo = (r.tipo || '').toUpperCase();
      let badge = 'text-bg-secondary';
      if (tipo === 'ENTRADA') badge = 'text-bg-success';
      else if (tipo === 'SALIDA') badge = 'text-bg-danger';

      return `
        <tr>
          <td><span class="badge ${badge}">${escapeHtml(tipo || '—')}</span></td>
          <td>${escapeHtml(formatDateValue(r.opened_at))}</td>
          <td>${escapeHtml(r.reason ?? '')}</td>
          <td>${escapeHtml(r.channel_id ?? '')}</td>
        </tr>`;
    }).join('');

    buildPagination(manualOpenPaginationEl, manualOpenState, renderManualOpenRows);
  };

  manualOpenCsvBtn.addEventListener('click', () => {
    const rows = manualOpenState.rows || [];
    if (!rows.length) return;

    const headers = ['tipo','fecha','motivo','canal'];
    const csv = [
      headers.join(','),
      ...rows.map(r => [
        String((r.tipo || '').toUpperCase()).replaceAll('"','""'),
        new Date(r.opened_at || '').toISOString(),
        String(r.reason ?? '').replaceAll('"','""'),
        String(r.channel_id ?? '').replaceAll('"','""'),
      ].map(v => `"${v}"`).join(',')),
    ].join('\r\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    downloadBlob(blob, `aperturas_manuales_${document.getElementById('manualOpenFrom').value}_${document.getElementById('manualOpenTo').value}.csv`);
  });

  document.getElementById('invoiceFetch').addEventListener('click', fetchInvoiceReport);
  document.getElementById('invoiceReset').addEventListener('click', () => {
    document.getElementById('invoiceFrom').value = defaultFrom;
    document.getElementById('invoiceTo').value = defaultTo;
    document.getElementById('invoiceStatus').value = 'ANY';
    document.getElementById('invoiceNit').value = '';
    fetchInvoiceReport();
  });

  document.getElementById('manualInvoiceFetch').addEventListener('click', fetchManualInvoiceReport);
  document.getElementById('manualInvoiceReset').addEventListener('click', () => {
    document.getElementById('manualInvoiceFrom').value = defaultFrom;
    document.getElementById('manualInvoiceTo').value = defaultTo;
    document.getElementById('manualInvoiceMode').value = 'ANY';
    document.getElementById('manualInvoiceNit').value = '';
    document.getElementById('manualInvoiceReason').value = '';
    fetchManualInvoiceReport();
  });

  document.getElementById('manualOpenFetch').addEventListener('click', fetchManualOpenReport);
  document.getElementById('manualOpenReset').addEventListener('click', () => {
    document.getElementById('manualOpenFrom').value = defaultFrom;
    document.getElementById('manualOpenTo').value = defaultTo;
    document.getElementById('manualOpenSearch').value = '';
    fetchManualOpenReport();
  });

  await fetchInvoiceReport();
  await fetchManualInvoiceReport();
  await fetchManualOpenReport();
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
    // ===== Bootstrap-friendly Dialog shim =====
    const Dialog = (() => {
      function ensureHost() {
        let host = document.getElementById('app-toast-host');
        if (!host) {
          host = document.createElement('div');
          host.id = 'app-toast-host';
          host.style.position = 'fixed';
          host.style.zIndex = '1080';
          host.style.inset = 'auto 1rem 1rem auto';
          document.body.appendChild(host);
        }
        return host;
      }
      function bsToast(message, { title, variant = 'primary', delay = 4000 } = {}) {
        const host = ensureHost();
        const wrap = document.createElement('div');
        wrap.className = 'toast align-items-center text-bg-' + variant;
        wrap.role = 'alert';
        wrap.ariaLive = 'assertive';
        wrap.ariaAtomic = 'true';
        wrap.innerHTML = `
          <div class="d-flex">
            <div class="toast-body">
              ${title ? `<div class="fw-semibold mb-1">${title}</div>` : ''}
              ${message ?? ''}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
          </div>`;
        host.appendChild(wrap);
        try {
          if (window.bootstrap?.Toast) {
            const t = new bootstrap.Toast(wrap, { delay, autohide: true });
            t.show();
          } else {
            wrap.classList.add('show');
            setTimeout(() => wrap.remove(), delay);
          }
        } catch {
          wrap.classList.add('show');
          setTimeout(() => wrap.remove(), delay);
        }
      }
      function bsAlert({ title, message, variant = 'warning' } = {}) {
        const host = ensureHost();
        const div = document.createElement('div');
        div.className = `alert alert-${variant} alert-dismissible fade show`;
        div.role = 'alert';
        div.style.minWidth = '280px';
        div.innerHTML = `
          ${title ? `<div class="fw-semibold mb-1">${title}</div>` : ''}
          <div>${message ?? ''}</div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        host.appendChild(div);
        return { close: () => div.remove(), el: div };
      }
      function loading({ title, message } = {}) {
        const wrap = document.createElement('div');
        wrap.innerHTML = `
          <div class="modal-backdrop fade show"></div>
          <div style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;z-index:1080">
            <div class="card p-3 shadow-sm">
              <div class="d-flex align-items-center gap-3">
                <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
                <div>
                  <div class="fw-semibold">${title ?? 'Cargando'}</div>
                  <div class="small text-muted">${message ?? ''}</div>
                </div>
              </div>
            </div>
          </div>`;
        const el = document.createElement('div');
        el.style.zIndex = 1080;
        el.appendChild(wrap);
        document.body.appendChild(el);
        return { close() { el.remove(); } };
      }
      const base = {
        loading,
        toast: (msg, opts) => bsToast(msg, opts),
        alert: (opts) => bsAlert(opts),
        ok: (message, title = 'Listo') => bsToast(message, { title, variant: 'success' }),
        err: (e, title = 'Error') => bsAlert({ title, message: (e?.message ?? String(e)), variant: 'danger' }),
      };
      const ext = window.Dialog || {};
      return {
        ...base,
        ...ext,
        loading: ext.loading || base.loading,
        toast: ext.toast || base.toast,
        alert: ext.alert || base.alert,
        ok: ext.ok || base.ok,
        err: ext.err || base.err,
      };
    })();

    const escapeHtml = (v) =>
      String(v ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');

    // ===== PDF helpers =====
    function b64ToBlobPdf(b64){
      const byteChars = atob(b64), byteArrays = []; const chunk = 65536;
      for (let off=0; off<byteChars.length; off+=chunk){
        const slice = byteChars.slice(off, off+chunk);
        const arr = new Uint8Array(slice.length);
        for (let i=0;i<slice.length;i++) arr[i] = slice.charCodeAt(i);
        byteArrays.push(arr);
      }
      return new Blob(byteArrays, { type:'application/pdf' });
    }
    function downloadBase64Pdf(filename, b64){
      const url = URL.createObjectURL(b64ToBlobPdf(b64));
      const a = document.createElement('a'); a.href = url; a.download = filename || 'documento.pdf';
      document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url), 1000);
    }
    async function fetchPdfForManualInvoice({ id, uuid }){
      if (uuid) {
        try { const r1 = await fetchJSON(api('fel/document-pdf') + '?uuid=' + encodeURIComponent(uuid));
              if (r1?.ok && r1.pdf_base64) return r1.pdf_base64; } catch {}
      }
      if (id) {
        try { const r2 = await fetchJSON(api('fel/manual-invoice/pdf') + '?id=' + encodeURIComponent(id));
              if (r2?.ok && r2.pdf_base64) return r2.pdf_base64; } catch {}
        try { const r3 = await fetchJSON(api('fel/manual-invoice/one') + '?id=' + encodeURIComponent(id));
              const b64 = r3?.data?.fel_pdf_base64 || r3?.fel_pdf_base64; if (b64) return b64; } catch {}
      }
      throw new Error('No se pudo obtener el PDF.');
    }

    // ===== Helpers dinero =====
    function parseMoneyLike(x){
      if (x == null) return null;
      if (typeof x === 'number') return Number.isFinite(x) ? x : null;
      const s = String(x).trim(); if (!s) return null;
      const cleaned = s.replace(/[^\d.,-]/g,'').replace(/,/g,'.');
      const n = Number(cleaned);
      return Number.isFinite(n) ? n : null;
    }

    // ===== Cargar settings (tarifa mensual) =====
    const settings = await loadSettings().catch(()=>null);
    async function getMonthlyRateFromOverview(){
      try {
        const res = await fetchJSON(api('settings'));
        const raw = res?.settings?.billing?.monthly_rate ?? null;
        const v = parseMoneyLike(raw);
        return (v && v > 0) ? v : null;
      } catch { return null; }
    }
    const monthlyRateNumber = await getMonthlyRateFromOverview();
    const hasMonthly = monthlyRateNumber != null && monthlyRateNumber > 0;

    // ===== State para paginado =====
    const state = {
      rows: [],
      page: 1,
      perPage: 25,
      pages: 1,
    };

    // ===== Render estático =====
    const app = document.getElementById('app') || document.body;
    app.innerHTML = `
      <div class="card p-3">
        <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
          <div class="flex-grow-1">
            <h5 class="mb-1">Factura manual</h5>
            <p class="text-muted small mb-1">
              Indica <strong>motivo</strong> y <strong>NIT</strong>; elige <strong>mensual</strong> o <strong>personalizado</strong>. (Siempre se envía a FEL)
            </p>
            <div class="text-muted small" id="monthlyRateHint">
              ${hasMonthly ? `Tarifa mensual actual: Q ${monthlyRateNumber.toFixed(2)}` : 'No hay tarifa mensual configurada.'}
            </div>
          </div>
          <div class="ms-auto d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-primary" id="btnNewManualInv">Nueva factura manual</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshManualInv">Actualizar</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm mb-0" id="tblManualInvoices">
            <thead>
              <tr>
                <th>#</th><th>Fecha</th><th>Motivo</th><th>NIT</th><th>Monto</th>
                <th>Usó mensual</th><th>Estado FEL</th><th>UUID</th>
                <th class="text-end">PDF</th>
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

        <!-- Paginación -->
        <nav class="mt-3" aria-label="Paginación facturas">
          <ul class="pagination pagination-sm mb-0" id="manualInvPager"></ul>
        </nav>
      </div>

      <!-- Modal -->
      <div class="modal fade" id="manualInvModal" tabindex="-1" aria-labelledby="manualInvLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content manual-inv-modal">
            <form id="manualInvForm" autocomplete="off">
              <div class="modal-header">
                <h5 class="modal-title" id="manualInvLabel">Nueva factura manual</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
              </div>
              <div class="modal-body">

                <!-- Motivo -->
                <div class="mb-3">
                  <label class="form-label">Motivo</label>
                  <input type="text" class="form-control" name="reason" id="mReason" minlength="3" maxlength="255" required />
                  <div class="form-text">Este motivo se guardará junto con la factura.</div>
                </div>

                <!-- Resumen mensual -->
                <div class="border rounded p-2 mb-3 bg-light">
                  <div class="d-flex justify-content-between small">
                    <span class="text-muted">Tarifa mensual</span>
                    <span id="mMonthlyPreview">${hasMonthly ? 'Q ' + monthlyRateNumber.toFixed(2) : '—'}</span>
                  </div>
                </div>

                <!-- Opciones: mensual / personalizado -->
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="billingMode" id="mModeMonthly" value="monthly" ${hasMonthly ? '' : 'disabled'}>
                  <label class="form-check-label" for="mModeMonthly">
                    Cobro mensual ${hasMonthly ? `<strong class="ms-1">Q ${monthlyRateNumber.toFixed(2)}</strong>` : ''}
                  </label>
                  <div class="form-text">${hasMonthly ? 'Se aplicará la tarifa mensual configurada.' : 'Configura una tarifa mensual en Ajustes para habilitar esta opción.'}</div>
                </div>

                <div class="form-check mt-2">
                  <input class="form-check-input" type="radio" name="billingMode" id="mModeCustom" value="custom">
                  <label class="form-check-label" for="mModeCustom">Cobro personalizado</label>
                  <div class="form-text">Indica el total que deseas facturar manualmente.</div>
                </div>

                <div class="input-group input-group-sm mt-2">
                  <span class="input-group-text">Q</span>
                  <input type="number" step="0.01" min="0" class="form-control" id="mCustomInput" placeholder="0.00" disabled>
                </div>

                <!-- NIT (con estado debajo) -->
                <div class="mt-3">
                  <label class="form-label" for="mNit">NIT</label>
                  <div class="input-group">
                    <input type="text" class="form-control" name="nit" id="mNit" placeholder="CF o NIT" inputmode="text" pattern="^(?:\\d+|[Cc][Ff])$" autocomplete="off"/>
                    <button class="btn btn-outline-secondary" type="button" id="btnLookupNit" title="Buscar NIT">
                      <i class="bi bi-search"></i>
                    </button>
                  </div>
                  <div class="small mt-1 text-muted" id="mNitStatus" aria-live="polite">Escribe el NIT o “CF”.</div>
                </div>

              </div>
              <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="button" id="btnSubmitManualInv">Crear</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    `;

    // ===== Modal helper =====
    let manualInvModalRef = null;
    function bsModal(id){
      const el = document.getElementById(id);
      if (!el) return null;
      if (window.bootstrap?.Modal) {
        const inst = bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el);
        return inst;
      }
      return {
        show(){
          el.classList.add('show'); el.style.display='block'; el.removeAttribute('aria-hidden');
          document.body.classList.add('modal-open');
          if (!document.querySelector('.modal-backdrop')) {
            const bd = document.createElement('div');
            bd.className = 'modal-backdrop fade show';
            document.body.appendChild(bd);
          }
        },
        hide(){
          el.classList.remove('show'); el.style.display='none'; el.setAttribute('aria-hidden','true');
          document.body.classList.remove('modal-open');
          const bd = document.querySelector('.modal-backdrop'); if (bd) bd.remove();
        },
      };
    }

    // ===== NIT: sanitización + lookup =====
    const nitInput   = document.getElementById('mNit');
    const mNitStatus = document.getElementById('mNitStatus');
    function sanitizeNit(raw){
      const v = (raw || '').trim();
      if (/^[Cc][Ff]$/.test(v)) return 'CF';
      return v.replace(/\D+/g,'');
    }
    function validateNitInput(){
      const v = nitInput.value.trim();
      if (!v) { nitInput.setCustomValidity(''); return; }
      if (v.toUpperCase()==='CF' || /^\d+$/.test(v)) nitInput.setCustomValidity('');
      else nitInput.setCustomValidity('Escribe solo números o “CF”.');
    }
    function setNitStatus(t, cls='text-muted'){ mNitStatus.className = `small mt-1 ${cls}`; mNitStatus.textContent = t || ''; }
    async function tryLookupNit(){
      const val = nitInput.value.trim();
      if (!val || val.toUpperCase()==='CF'){ setNitStatus('Consumidor final (CF).'); return; }
      if (!(val.toUpperCase()==='CF' || /^\d+$/.test(val))) return;
      const dlg = Dialog.loading({ title:'Buscando NIT', message:'Consultando…' });
      try{
        const res = await fetchJSON(api('g4s/lookup-nit')+'?nit='+encodeURIComponent(val));
        if (res?.ok !== false){
          const nombre = (res?.nombre || res?.name || '').trim();
          const dir = (res?.direccion || res?.address || '').trim();
          setNitStatus(`Encontrado${nombre ? ': ' + nombre : ''}${dir ? ' — ' + dir : ''}.`, 'text-success');
        }else{
          setNitStatus(res?.error ? `No encontrado: ${res.error}` : 'NIT no encontrado.', 'text-warning');
        }
      }catch{ setNitStatus('Error al consultar.', 'text-danger'); }
      finally{ dlg.close(); }
    }
    nitInput.addEventListener('input', ()=>{
      const clean = sanitizeNit(nitInput.value);
      if (clean !== nitInput.value){ nitInput.value = clean; nitInput.setSelectionRange(clean.length, clean.length); }
      validateNitInput();
      if (/^\d{6,}$/.test(nitInput.value)) { setTimeout(tryLookupNit, 600); }
      else if (nitInput.value.toUpperCase()==='CF') setNitStatus('Consumidor final (CF).');
      else setNitStatus('Ingresa al menos 6 dígitos para consultar.');
    });
    nitInput.addEventListener('blur', ()=>{ validateNitInput(); tryLookupNit(); });
    document.getElementById('btnLookupNit')?.addEventListener('click', tryLookupNit);

    // ===== Abrir modal =====
    document.getElementById('btnNewManualInv')?.addEventListener('click', () => {
      manualInvModalRef = bsModal('manualInvModal');
      manualInvModalRef?.show();

      const reasonEl    = document.getElementById('mReason');
      const modeMonthly = document.getElementById('mModeMonthly');
      const modeCustom  = document.getElementById('mModeCustom');
      const customInput = document.getElementById('mCustomInput');

      if (hasMonthly) modeMonthly.removeAttribute('disabled');

      // Estado inicial
      if (hasMonthly) {
        modeMonthly.checked = true;
        customInput.value = monthlyRateNumber.toFixed(2);
        customInput.disabled = true;
      } else {
        modeCustom.checked = true;
        customInput.value = '';
        customInput.disabled = false;
      }

      // Sync radios
      const sync = () => {
        if (modeMonthly.checked && hasMonthly) {
          customInput.value = monthlyRateNumber.toFixed(2);
          customInput.disabled = true;
        } else {
          customInput.disabled = false;
          if (!customInput.value || customInput.value === '0.00') customInput.value = '';
        }
      };
      modeMonthly.addEventListener('change', sync);
      modeCustom.addEventListener('change', sync);
      sync();

      setTimeout(()=>reasonEl?.focus(), 120);
    });

    // ===== Submit único (sin duplicados): onsubmit =====
    const submitBtn = document.getElementById('btnSubmitManualInv');
    submitBtn.onclick = async () => {
      const form = document.getElementById('manualInvForm');
      if (form.dataset.busy === '1') return;        // guard contra doble clic
      form.dataset.busy = '1';
      submitBtn.setAttribute('disabled', 'disabled');

      const modeMonthly = document.getElementById('mModeMonthly');
      const modeCustom  = document.getElementById('mModeCustom');
      const customInput = document.getElementById('mCustomInput');

      const reason = document.getElementById('mReason').value.trim();
      const nitRaw = document.getElementById('mNit').value.trim();
      const receptor_nit = nitRaw ? sanitizeNit(nitRaw).toUpperCase() : 'CF';

      if (reason.length < 3) { Dialog.alert({ title:'Falta motivo', message:'Escribe un motivo de al menos 3 caracteres.' }); submitBtn.removeAttribute('disabled'); delete form.dataset.busy; return; }
      if (!(receptor_nit === 'CF' || /^\d+$/.test(receptor_nit))) { Dialog.alert({ title:'NIT inválido', message:'Escribe solo números o “CF”.' }); submitBtn.removeAttribute('disabled'); delete form.dataset.busy; return; }

      let amount = 0, used_monthly = false, label = '';
      if (modeMonthly.checked && hasMonthly){
        used_monthly = true;
        amount = monthlyRateNumber;
        label = `Cobro mensual Q ${amount.toFixed(2)}`;
      } else {
        const v = Number(customInput.value);
        if (!Number.isFinite(v) || v <= 0) { Dialog.alert({ title:'Monto inválido', message:'Ingresa un total personalizado mayor a 0.' }); submitBtn.removeAttribute('disabled'); delete form.dataset.busy; return; }
        amount = Math.round(v * 100) / 100;
        label = `Cobro personalizado Q ${amount.toFixed(2)}`;
      }

      const payload = { reason, receptor_nit, amount, used_monthly, send_to_fel: true };
      const idemKey = (window.crypto?.randomUUID?.() || `mid-${Date.now()}-${Math.random().toString(16).slice(2)}`);

      const dlg = Dialog.loading({ title:'Creando', message:'Enviando datos…' });
      try {
        const res = await fetchJSON(api('fel/manual-invoice'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Idempotency-Key': idemKey },
          body: JSON.stringify(payload),
        });

        if (res?.ok) {
          const uuidTxt = res.uuid ? `\nUUID: ${res.uuid}` : '';
          Dialog.ok(`Factura creada y enviada a FEL.\n${label}${uuidTxt}`, 'Éxito');
          try {
            if (window.bootstrap?.Modal) {
              const el = document.getElementById('manualInvModal');
              const inst = bootstrap.Modal.getInstance(el) || manualInvModalRef;
              inst?.hide?.();
            } else { manualInvModalRef?.hide?.(); }
            document.querySelector('.modal-backdrop')?.remove();
            document.body.classList.remove('modal-open');
          } catch {}
          await refreshManualInvoicesTable();
        } else {
          throw new Error(res?.error || 'No se pudo crear la factura manual');
        }
      } catch (e) {
        Dialog.err(e, 'Error al crear factura');
      } finally {
        dlg.close();
        submitBtn.removeAttribute('disabled');
        delete form.dataset.busy;
      }
    };

    // ===== Tabla + paginación =====
    const tbody = document.querySelector('#tblManualInvoices tbody');
    const pager = document.getElementById('manualInvPager');

    function renderPager() {
      const { page, pages } = state;
      if (!pager) return;
      if (pages <= 1) { pager.innerHTML = ''; return; }

      const mkPageItem = (p, label = p, active = false, disabled = false) => `
        <li class="page-item ${active ? 'active' : ''} ${disabled ? 'disabled' : ''}">
          <a class="page-link" href="#" data-page="${p}">${label}</a>
        </li>
      `;

      const items = [];
      items.push(mkPageItem(Math.max(1, page - 1), 'Anterior', false, page === 1));

      // ventana de páginas (máx 7 links numerados)
      const windowSize = 7;
      let start = Math.max(1, page - Math.floor(windowSize/2));
      let end = Math.min(pages, start + windowSize - 1);
      if (end - start + 1 < windowSize) start = Math.max(1, end - windowSize + 1);

      if (start > 1) items.push(mkPageItem(1, '1', false, false));
      if (start > 2) items.push(`<li class="page-item disabled"><span class="page-link">…</span></li>`);

      for (let p = start; p <= end; p++) {
        items.push(mkPageItem(p, String(p), p === page, false));
      }

      if (end < pages - 1) items.push(`<li class="page-item disabled"><span class="page-link">…</span></li>`);
      if (end < pages) items.push(mkPageItem(pages, String(pages), false, false));

      items.push(mkPageItem(Math.min(pages, page + 1), 'Siguiente', false, page === pages));

      pager.innerHTML = items.join('');

      pager.onclick = (ev) => {
        const a = ev.target.closest('a.page-link');
        if (!a) return;
        ev.preventDefault();
        const target = Number(a.dataset.page);
        if (!Number.isFinite(target) || target < 1 || target > state.pages || target === state.page) return;
        state.page = target;
        renderTablePage();
        renderPager();
      };
    }

    function renderTablePage() {
      const { rows, page, perPage } = state;
      if (!tbody) return;

      if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center text-muted py-4">No hay facturas manuales registradas todavía.</td></tr>`;
        return;
      }

      const startIdx = (page - 1) * perPage;
      const slice = rows.slice(startIdx, startIdx + perPage);

      tbody.innerHTML = slice.map(r=>{
        const canPdf = (String(r?.fel_status||'').trim()==='OK') && !!r?.fel_uuid;
        const title = r?.receptor_name ? 'Nombre: ' + String(r.receptor_name).replace(/"/g,'&quot;') : '';
        return `<tr title="${title}">
          <td>${r.id ?? ''}</td>
          <td>${r.created_at ?? ''}</td>
          <td>${r.reason ? escapeHtml(r.reason) : ''}</td>
          <td>${r.receptor_nit ?? ''}</td>
          <td>Q ${Number(r.amount ?? 0).toFixed(2)}</td>
          <td>${r.used_monthly ? 'Sí' : 'No'}</td>
          <td>${r.fel_status ? escapeHtml(r.fel_status) : ''}</td>
          <td>${r.fel_uuid ? escapeHtml(r.fel_uuid) : ''}</td>
          <td class="text-end">
            <button type="button" class="btn btn-sm ${canPdf?'btn-outline-primary':'btn-outline-secondary'} btnManualPdf"
              data-id="${r.id ?? ''}" data-uuid="${r.fel_uuid ?? ''}" ${canPdf?'':'disabled'}
              title="${canPdf ? 'Descargar/abrir PDF' : 'Sin PDF disponible'}">
              <i class="bi bi-file-pdf"></i>
            </button>
          </td>
        </tr>`;
      }).join('');

      // Delegación para botón PDF (una vez)
      tbody.onclick = async (ev)=>{
        const btn = ev.target.closest?.('.btnManualPdf'); if (!btn) return;
        const id = btn.getAttribute('data-id'); const uuid = btn.getAttribute('data-uuid');
        const dlg = Dialog.loading({ title:'Obteniendo PDF', message:'Consultando…' });
        try {
          const b64 = await fetchPdfForManualInvoice({ id, uuid });
          downloadBase64Pdf((uuid?`${uuid}.pdf`:`manual-invoice-${id}.pdf`), b64);
          dlg.close(); Dialog.toast?.('PDF listo', { variant:'success' });
        } catch(err){
          dlg.close(); Dialog.alert({ title:'No disponible', message:String(err), variant:'warning' });
        }
      };
    }

    async function refreshManualInvoicesTable(){
      if (!tbody) return;
      tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4">
        <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div><span class="ms-2">Actualizando…</span></td></tr>`;
      try{
        const js = await fetchJSON(api('fel/manual-invoice-list'));
        const rows = Array.isArray(js?.data) ? js.data : (Array.isArray(js) ? js : []);
        state.rows = rows;
        state.page = 1;
        state.pages = Math.max(1, Math.ceil((rows.length || 0) / state.perPage));
        renderTablePage();
        renderPager();
      }catch(e){
        tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4"><div class="text-danger">Error al cargar.</div><div class="small text-muted">${escapeHtml(String(e))}</div></td></tr>`;
        pager.innerHTML = '';
      }
    }

    document.getElementById('btnRefreshManualInv')?.addEventListener('click', refreshManualInvoicesTable);
    await refreshManualInvoicesTable();
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
