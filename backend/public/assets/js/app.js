(() => {
  const app = document.getElementById('app');
  const sidebar = document.getElementById('appSidebar');
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebarBackdrop = document.getElementById('sidebarBackdrop');
  const sidebarLinks = sidebar ? Array.from(sidebar.querySelectorAll('.nav-link')) : [];

  const syncSidebarAria = (open) => {
    if (sidebarToggle) {
      sidebarToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      sidebarToggle.setAttribute('aria-label', open
        ? 'Cerrar menú de navegación'
        : 'Abrir menú de navegación');
    }

    if (sidebar) {
      sidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
    }

    if (sidebarBackdrop) {
      sidebarBackdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
    }
  };

  const closeSidebar = () => {
    if (!document.body.classList.contains('sidebar-open')) {
      syncSidebarAria(false);
      return;
    }

    document.body.classList.remove('sidebar-open');
    syncSidebarAria(false);
  };

  const openSidebar = () => {
    if (document.body.classList.contains('sidebar-open')) {
      syncSidebarAria(true);
      return;
    }

    document.body.classList.add('sidebar-open');
    syncSidebarAria(true);
    if (sidebarLinks.length) {
      const [firstLink] = sidebarLinks;
      window.setTimeout(() => {
        try { firstLink.focus(); } catch (_) { /* noop */ }
      }, 50);
    }
  };

  const toggleSidebar = () => {
    if (document.body.classList.contains('sidebar-open')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  };

  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', (event) => {
      event.preventDefault();
      toggleSidebar();
    });
  }

  if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeSidebar);
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
      closeSidebar();
    }
  });

  syncSidebarAria(document.body.classList.contains('sidebar-open'));

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
    document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
    link.classList.add('active');
    closeSidebar();
  }

  async function fetchJSON(url, opts) {
    const res = await fetch(url, opts);
    const text = await res.text();
    try {
      if (!res.ok) throw new Error(`HTTP ${res.status} ${res.statusText}\n${text}`);
      return JSON.parse(text);
    } catch (e) {
      throw new Error(`Respuesta no JSON desde ${url}\n---\n${text}\n---`);
    }
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

  loadSettings().catch(() => {});

  const refreshBtn = document.getElementById('refreshBtn');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', async () => {
      const active = document.querySelector('.nav-link.active[data-page]')?.getAttribute('data-page') || 'dashboard';
      refreshBtn.classList.add('is-loading');
      refreshBtn.disabled = true;
      try {
        await loadSettings(true);
        document.querySelector(`.nav-link[data-page="${active}"]`)?.click();
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

  // ===== Dashboard =====
  async function renderDashboard() {
    try {
      const [ticketsResp, settings] = await Promise.all([
        fetchJSON(api('tickets')),
        loadSettings(),
      ]);

      const data = Array.isArray(ticketsResp?.data) ? ticketsResp.data : [];
      const state = { search: '', page: 1 };
      const pageSize = 20;

      const metrics = settings?.database?.metrics ?? {};
      const pending = Number(metrics.pending_invoices ?? 0);
      const summaryCards = [
        {
          title: 'Asistencias de hoy',
          badge: 'En vivo',
          value: formatNumber(data.length),
          detail: 'Lecturas registradas en el día',
          accent: 'primary',
        },
        {
          title: 'Tickets en base de datos',
          badge: 'Histórico',
          value: formatNumber(metrics.tickets_total),
          detail: metrics.tickets_last_sync ? `Último registro ${formatRelativeTime(metrics.tickets_last_sync)}` : 'Sin registros almacenados',
          accent: 'info',
        },
        {
          title: 'Facturas emitidas',
          badge: 'FEL',
          value: formatNumber(metrics.invoices_total),
          detail: metrics.invoices_last_sync ? `Última emisión ${formatRelativeTime(metrics.invoices_last_sync)}` : 'Sin facturas emitidas',
          accent: 'success',
        },
        {
          title: 'Pendientes por facturar',
          badge: pending > 0 ? 'Atención' : 'Al día',
          value: formatNumber(pending),
          detail: pending > 0 ? 'Genera FEL desde Facturación' : 'Sin pendientes',
          accent: pending > 0 ? 'warning' : 'neutral',
        },
      ];

      const summaryHtml = summaryCards.map((card) => `
        <div class="col-xxl-3 col-sm-6">
          <div class="stat-card" data-accent="${card.accent}">
            <div class="stat-badge">${escapeHtml(card.badge)}</div>
            <div class="stat-title">${escapeHtml(card.title)}</div>
            <div class="stat-value">${escapeHtml(card.value)}</div>
            <div class="stat-foot">${escapeHtml(card.detail)}</div>
          </div>
        </div>
      `).join('');

      app.innerHTML = `
        <div class="dashboard-view">
          <div class="row g-4 dashboard-summary">
            ${summaryHtml}
          </div>
          <div class="row g-4 align-items-stretch">
            <div class="col-xl-8">
              <div class="card shadow-sm h-100">
                <div class="card-body d-flex flex-column gap-3 h-100">
                  <div class="d-flex flex-wrap gap-2 align-items-start justify-content-between">
                    <div>
                      <h5 class="card-title mb-1">Asistencias registradas</h5>
                      <p class="text-muted small mb-0">Información consolidada desde la base de datos.</p>
                    </div>
                    <div class="ms-auto" style="max-width: 260px;">
                      <input type="search" id="dashSearch" class="form-control form-control-sm" placeholder="Buscar ticket, placa o nombre..." aria-label="Buscar asistencia" />
                    </div>
                  </div>
                  <div class="table-responsive flex-grow-1">
                    <table class="table table-sm align-middle mb-0">
                      <thead><tr><th>#</th><th>Nombre</th><th>Entrada</th><th>Salida</th></tr></thead>
                      <tbody id="dashBody"></tbody>
                    </table>
                  </div>
                  <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                    <small class="text-muted" id="dashMeta"></small>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Paginación de asistencias">
                      <button type="button" class="btn btn-outline-secondary" id="dashPrev">Anterior</button>
                      <button type="button" class="btn btn-outline-secondary" id="dashNext">Siguiente</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-xl-4">
              <div class="card shadow-sm h-100">
                <div class="card-body d-flex flex-column gap-3">
                  <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                      <h5 class="card-title mb-1">Actividad reciente</h5>
                      <p class="text-muted small mb-0">Últimos eventos sincronizados.</p>
                    </div>
                    <button class="btn btn-link btn-sm p-0" id="timelineRefresh">Actualizar</button>
                  </div>
                  <div class="timeline" id="activityTimeline"></div>
                </div>
              </div>
            </div>
          </div>
        </div>`;

      const timelineContainer = document.getElementById('activityTimeline');
      if (timelineContainer) {
        timelineContainer.innerHTML = buildTimeline(settings?.activity);
      }

      const timelineRefresh = document.getElementById('timelineRefresh');
      if (timelineRefresh) {
        timelineRefresh.addEventListener('click', async () => {
          const originalText = timelineRefresh.textContent;
          timelineRefresh.disabled = true;
          timelineRefresh.textContent = 'Actualizando…';
          let hadError = false;
          try {
            await loadSettings(true);
            await renderDashboard();
          } catch (err) {
            hadError = true;
            console.error(err);
            timelineRefresh.disabled = false;
            timelineRefresh.textContent = 'Reintentar';
          } finally {
            if (!hadError && timelineRefresh.isConnected) {
              timelineRefresh.textContent = originalText;
            }
          }
        });
      }

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
        if (state.page > totalPages) {
          state.page = totalPages;
        }
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
          const message = data.length && !filtered.length
            ? 'No se encontraron resultados'
            : 'Sin registros disponibles';
          tbody.innerHTML = `
            <tr>
              <td colspan="4" class="text-center text-muted">${escapeHtml(message)}</td>
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
      app.innerHTML = `
        <div class="card shadow-sm">
          <div class="card-body">
            <h5 class="card-title text-danger">No se pudo cargar el dashboard</h5>
            <p class="text-muted">Intenta nuevamente en unos segundos. Si el problema persiste revisa la conexión con la base de datos y las credenciales de las integraciones.</p>
            <pre class="small mb-0">${escapeHtml(String(e))}</pre>
          </div>
        </div>`;
    }
  }

  // ===== Facturación (tabla + Facturar) =====
  async function renderInvoices() {
    app.innerHTML = `
      <div class='card p-3'>
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
          <div>
            <h5 class="mb-1">Facturación (BD → G4S)</h5>
            <p class="text-muted small mb-0">Lista tickets <strong>CLOSED</strong> con pagos (o monto) y <strong>sin factura</strong>.</p>
          </div>
          <div class="ms-auto" style="max-width: 260px;">
            <input type="search" id="invSearch" class="form-control form-control-sm" placeholder="Buscar ticket..." aria-label="Buscar ticket pendiente" />
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Ticket</th>
                <th>Fecha</th>
                <th class="text-end">Total</th>
                <th>UUID</th>
                <th class="text-center">Acción</th>
              </tr>
            </thead>
            <tbody id="invRows">
              <tr><td colspan="5" class="text-muted">Cargando…</td></tr>
            </tbody>
          </table>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mt-3">
          <small class="text-muted" id="invMeta"></small>
          <div class="btn-group btn-group-sm" role="group" aria-label="Paginación de facturas">
            <button type="button" class="btn btn-outline-secondary" id="invPrev">Anterior</button>
            <button type="button" class="btn btn-outline-secondary" id="invNext">Siguiente</button>
          </div>
        </div>
      </div>
    `;

    const tbody = document.getElementById('invRows');
    const searchInput = document.getElementById('invSearch');
    const meta = document.getElementById('invMeta');
    const prevBtn = document.getElementById('invPrev');
    const nextBtn = document.getElementById('invNext');

    const state = { search: '', page: 1 };
    const pageSize = 20;
    let allRows = [];

    function filterRows() {
      if (!state.search) return allRows;
      const term = state.search.toLowerCase();
      return allRows.filter((row) =>
        Object.values(row).some((value) => String(value ?? '').toLowerCase().includes(term))
      );
    }

    function renderPage() {
      const filtered = filterRows();
      const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
      if (state.page > totalPages) {
        state.page = totalPages;
      }

      const start = (state.page - 1) * pageSize;
      const pageRows = filtered.slice(start, start + pageSize);

      if (!pageRows.length) {
        const message = allRows.length && !filtered.length
          ? 'No se encontraron resultados'
          : 'No hay pendientes por facturar.';
        tbody.innerHTML = `<tr><td colspan="5" class="text-muted">${message}</td></tr>`;
      } else {
        tbody.innerHTML = pageRows.map((d, index) => {
          const totalFmt = (d.total != null)
            ? Number(d.total).toLocaleString('es-GT', { style: 'currency', currency: 'GTQ' })
            : '';
          const payload = encodeURIComponent(JSON.stringify({
            ticket_no: d.ticket_no,
            receptor_nit: d.receptor || 'CF',
            serie: d.serie || 'A',
            numero: d.numero || null
          }));
          const disabled = d.uuid ? 'disabled' : '';
          return `
            <tr>
              <td>${d.ticket_no}</td>
              <td>${d.fecha ?? ''}</td>
              <td class="text-end">${totalFmt}</td>
              <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;">${d.uuid ?? ''}</td>
              <td class="text-center">
                <button class="btn btn-sm btn-outline-success" data-action="invoice" data-payload="${payload}" ${disabled}>
                  Facturar
                </button>
              </td>
            </tr>`;
        }).join('');

        tbody.querySelectorAll('[data-action="invoice"]').forEach((btn) => {
          btn.addEventListener('click', async () => {
            const payload = JSON.parse(decodeURIComponent(btn.getAttribute('data-payload')));
            btn.disabled = true;
            const originalText = btn.textContent;
            btn.textContent = 'Enviando…';
            try {
              const js = await fetchJSON(api('fel/invoice'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
              });
              alert(`OK: UUID ${js.uuid || '(verifique respuesta)'}`);
              loadList();
            } catch (e) {
              alert('Error al facturar: ' + e.message);
            } finally {
              btn.disabled = false;
              btn.textContent = originalText;
            }
          });
        });
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

    prevBtn.addEventListener('click', () => {
      if (state.page > 1) {
        state.page -= 1;
        renderPage();
      }
    });

    nextBtn.addEventListener('click', () => {
      const totalPages = Math.max(1, Math.ceil(filterRows().length / pageSize));
      if (state.page < totalPages) {
        state.page += 1;
        renderPage();
      }
    });

    async function loadList() {
      tbody.innerHTML = `<tr><td colspan="5" class="text-muted">Consultando BD…</td></tr>`;
      meta.textContent = '';
      try {
        const js = await fetchJSON(api('facturacion/list'));
        if (!js.ok && js.error) throw new Error(js.error);
        allRows = js.rows || [];
        state.page = 1;
        renderPage();
      } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-danger">Error: ${e.message}</td></tr>`;
        meta.textContent = '';
      }
    }

    loadList();
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
        alert('Permite ventanas emergentes para mostrar el reporte.');
        return;
      }
      w.document.write(html);
      w.document.close();
    };

    const exportCsv = (filename, headers, rows) => {
      if (!rows.length) {
        alert('No hay datos para exportar');
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
        .map(([key, value]) => `<li><strong>${filterLabels[key] ?? key}:</strong> ${escapeHtml(value)}</li>`)
        .join('') || '<li>Sin filtros aplicados</li>';

      const summaryCards = summary ? `
        <div class="summary-grid">
          <div class="summary-card">
            <strong>${escapeHtml(summary.total_tickets ?? 0)}</strong>
            <span>Tickets encontrados</span>
          </div>
          <div class="summary-card">
            <strong>${escapeHtml(formatCurrency(summary.total_amount ?? 0))}</strong>
            <span>Monto total</span>
          </div>
          <div class="summary-card">
            <strong>${escapeHtml(`${summary.with_payments ?? 0} / ${summary.total_tickets ?? 0}`)}</strong>
            <span>Con pagos</span>
          </div>
          <div class="summary-card">
            <strong>${escapeHtml(summary.average_minutes !== null && summary.average_minutes !== undefined ? formatDuration(summary.average_minutes) : '—')}</strong>
            <span>Estadía promedio</span>
          </div>
        </div>
      ` : '';

      const statusBreakdown = summary && summary.status_breakdown
        ? Object.entries(summary.status_breakdown).map(([k, v]) => `<span class="chip">${escapeHtml(formatTicketStatus(k))}: ${escapeHtml(v)}</span>`).join('')
        : '';

      const tableRows = rows.length ? rows.map((row) => `
        <tr>
          <td>${escapeHtml(row.ticket_no)}</td>
          <td>${escapeHtml(row.receptor_nit ?? 'CF')}</td>
          <td>${escapeHtml(formatTicketStatus(row.status))}</td>
          <td>${escapeHtml(formatDateValue(row.entry_at))}</td>
          <td>${escapeHtml(formatDateValue(row.exit_at))}</td>
          <td>${escapeHtml(formatDuration(row.duration_min))}</td>
          <td>${escapeHtml(formatCurrency(row.total))}</td>
          <td class="payments">
            ${(row.payments || []).length ? row.payments.map((p) => `
              <div>${escapeHtml(formatCurrency(p.amount))} · ${escapeHtml(p.method || 'Método no indicado')}<br><span>${escapeHtml(formatDateValue(p.paid_at))}</span></div>
            `).join('') : '<span class="chip chip-muted">Sin pagos</span>'}
          </td>
        </tr>
      `).join('') : `
        <tr>
          <td colspan="8" class="empty-row">No hay datos para mostrar.</td>
        </tr>
      `;

      return `<!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="utf-8" />
        <title>Reporte de tickets</title>
        <style>
          body { font-family: 'Inter', Arial, sans-serif; margin: 24px; color: #0f172a; }
          h1 { margin-bottom: 4px; }
          h2 { margin-bottom: 8px; color: #0369a1; }
          .subtitle { color: #64748b; margin-bottom: 18px; }
          .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin: 16px 0; }
          .summary-card { border: 1px solid #dbeafe; border-radius: 12px; padding: 12px; background: #eff6ff; }
          .summary-card strong { font-size: 1.3rem; display: block; }
          .summary-card span { color: #0369a1; font-size: 0.85rem; }
          .filters { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: 12px; margin: 0 0 16px; }
          .filters li { background: #f1f5f9; border-radius: 999px; padding: 6px 12px; font-size: 0.85rem; }
          .status-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
          .chip { display: inline-block; padding: 4px 10px; border-radius: 999px; background: #e0f2fe; color: #0369a1; font-size: 0.75rem; }
          .chip-muted { background: #e2e8f0; color: #475569; }
          table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
          th, td { border: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; vertical-align: top; }
          th { background: #f1f5f9; text-transform: uppercase; letter-spacing: .02em; font-size: 0.72rem; }
          td.payments div { margin-bottom: 6px; }
          .empty-row { text-align: center; color: #64748b; padding: 18px; }
          footer { margin-top: 28px; font-size: 0.8rem; color: #64748b; }
          @media print { body { margin: 12px; } }
        </style>
      </head>
      <body>
        <header>
          <h1>Reporte de tickets</h1>
          <p class="subtitle">Integración ZKTeco → FEL G4S · Generado ${escapeHtml(generated)}</p>
        </header>
        <section>
          <h2>Filtros aplicados</h2>
          <ul class="filters">${filterItems}</ul>
        </section>
        ${summaryCards}
        ${statusBreakdown ? `<div class="status-chips">${statusBreakdown}</div>` : ''}
        <section>
          <h2>Detalle</h2>
          <table>
            <thead>
              <tr>
                <th>Ticket</th>
                <th>Receptor</th>
                <th>Estado</th>
                <th>Entrada</th>
                <th>Salida</th>
                <th>Estadía</th>
                <th>Total</th>
                <th>Pagos</th>
              </tr>
            </thead>
            <tbody>${tableRows}</tbody>
          </table>
        </section>
        <footer>Reporte generado automáticamente. Fuente: Base de datos interna.</footer>
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
        .map(([key, value]) => `<li><strong>${filterLabels[key] ?? key}:</strong> ${escapeHtml(value)}</li>`)
        .join('') || '<li>Sin filtros aplicados</li>';

      const summaryCards = summary ? `
        <div class="summary-grid">
          <div class="summary-card">
            <strong>${escapeHtml(summary.total ?? 0)}</strong>
            <span>Facturas encontradas</span>
          </div>
          <div class="summary-card">
            <strong>${escapeHtml(formatCurrency(summary.total_amount ?? 0))}</strong>
            <span>Monto total</span>
          </div>
          <div class="summary-card">
            <strong>${escapeHtml(summary.ok ?? 0)}</strong>
            <span>Certificadas (OK)</span>
          </div>
          <div class="summary-card">
            <strong>${escapeHtml(summary.average_amount ? formatCurrency(summary.average_amount) : '—')}</strong>
            <span>Promedio por factura</span>
          </div>
        </div>
      ` : '';

      const statusBreakdown = summary ? [
        ['OK', summary.ok ?? 0],
        ['PENDING', summary.pending ?? 0],
        ['ERROR', summary.error ?? 0],
      ].filter(([, value]) => value > 0).map(([key, value]) => `<span class="chip">${escapeHtml(formatInvoiceStatus(key))}: ${escapeHtml(value)}</span>`).join('') : '';

      const tableRows = rows.length ? rows.map((row) => `
        <tr>
          <td>${escapeHtml(row.ticket_no)}</td>
          <td>${escapeHtml(formatDateValue(row.fecha))}</td>
          <td>${escapeHtml(formatCurrency(row.total))}</td>
          <td>${escapeHtml(row.receptor ?? 'CF')}</td>
          <td>${escapeHtml(row.uuid ?? '—')}</td>
          <td>${escapeHtml(formatInvoiceStatus(row.status))}</td>
        </tr>
      `).join('') : `
        <tr>
          <td colspan="6" class="empty-row">No hay facturas con los filtros dados.</td>
        </tr>
      `;

      return `<!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="utf-8" />
        <title>Reporte de facturas emitidas</title>
        <style>
          body { font-family: 'Inter', Arial, sans-serif; margin: 24px; color: #0f172a; }
          h1 { margin-bottom: 4px; }
          h2 { margin-bottom: 8px; color: #0f766e; }
          .subtitle { color: #475569; margin-bottom: 18px; }
          .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin: 16px 0; }
          .summary-card { border: 1px solid #99f6e4; border-radius: 12px; padding: 12px; background: #ecfeff; }
          .summary-card strong { font-size: 1.3rem; display: block; }
          .summary-card span { color: #0f766e; font-size: 0.85rem; }
          .filters { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: 12px; margin: 0 0 16px; }
          .filters li { background: #f1f5f9; border-radius: 999px; padding: 6px 12px; font-size: 0.85rem; }
          .status-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
          .chip { display: inline-block; padding: 4px 10px; border-radius: 999px; background: #ccfbf1; color: #0f766e; font-size: 0.75rem; }
          table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
          th, td { border: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; }
          th { background: #f1f5f9; text-transform: uppercase; letter-spacing: .02em; font-size: 0.72rem; }
          .empty-row { text-align: center; color: #64748b; padding: 18px; }
          footer { margin-top: 28px; font-size: 0.8rem; color: #64748b; }
          @media print { body { margin: 12px; } }
        </style>
      </head>
      <body>
        <header>
          <h1>Reporte de facturas emitidas</h1>
          <p class="subtitle">Integración ZKTeco → FEL G4S · Generado ${escapeHtml(generated)}</p>
        </header>
        <section>
          <h2>Filtros aplicados</h2>
          <ul class="filters">${filterItems}</ul>
        </section>
        ${summaryCards}
        ${statusBreakdown ? `<div class="status-chips">${statusBreakdown}</div>` : ''}
        <section>
          <h2>Detalle</h2>
          <table>
            <thead>
              <tr>
                <th>Ticket</th>
                <th>Fecha</th>
                <th>Total</th>
                <th>Receptor</th>
                <th>UUID</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>${tableRows}</tbody>
          </table>
        </section>
        <footer>Reporte generado automáticamente. Fuente: tabla de facturas (invoices).</footer>
        ${printable ? '<script>window.addEventListener("load",()=>{setTimeout(()=>window.print(),300);});</script>' : ''}
      </body>
      </html>`;
    };

    app.innerHTML = `
      <div class="d-flex flex-column gap-4">
        <section class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
              <div>
                <h5 class="card-title mb-1">Reporte de tickets</h5>
                <p class="text-muted small mb-0">Analiza tickets cerrados, montos recaudados y tiempos de permanencia.</p>
              </div>
              <span class="badge badge-soft">Tickets</span>
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
                <input type="text" id="ticketNit" class="form-control form-control-sm" placeholder="CF o NIT">
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
              <span class="badge badge-soft">Facturación</span>
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
          <div class="card shadow-sm border-0 bg-surface">
            <div class="card-body py-3">
              <div class="text-muted small">Tickets</div>
              <div class="fs-5 fw-semibold">${summary.total_tickets}</div>
              <div class="text-muted small">Registros encontrados</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-surface">
            <div class="card-body py-3">
              <div class="text-muted small">Monto total</div>
              <div class="fs-5 fw-semibold">${formatCurrency(summary.total_amount ?? 0)}</div>
              <div class="text-muted small">Incluye tickets sin pagos</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-surface">
            <div class="card-body py-3">
              <div class="text-muted small">Tickets con pagos</div>
              <div class="fs-5 fw-semibold">${summary.with_payments}/${summary.total_tickets}</div>
              <div class="text-muted small">Con al menos un cobro</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-surface">
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
            <td><span class="fw-semibold">${escapeHtml(row.ticket_no)}</span></td>
            <td>${escapeHtml(row.receptor_nit ?? 'CF')}</td>
            <td><span class="badge ${statusClass}">${escapeHtml(formatTicketStatus(row.status))}</span></td>
            <td>${escapeHtml(formatDateValue(row.entry_at))}</td>
            <td>${escapeHtml(formatDateValue(row.exit_at))}</td>
            <td>${escapeHtml(formatDuration(row.duration_min))}</td>
            <td class="text-end fw-semibold">${escapeHtml(formatCurrency(row.total))}</td>
            <td>${payments || '<span class="badge bg-light text-muted border">Sin pagos</span>'}</td>
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
          <div class="card shadow-sm border-0 bg-surface">
            <div class="card-body py-3">
              <div class="text-muted small">Facturas</div>
              <div class="fs-5 fw-semibold">${summary.total}</div>
              <div class="text-muted small">Documentos encontrados</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-surface">
            <div class="card-body py-3">
              <div class="text-muted small">Monto total</div>
              <div class="fs-5 fw-semibold">${formatCurrency(summary.total_amount ?? 0)}</div>
              <div class="text-muted small">Suma de montos certificados</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-surface">
            <div class="card-body py-3">
              <div class="text-muted small">Certificadas</div>
              <div class="fs-5 fw-semibold">${summary.ok}</div>
              <div class="text-muted small">Estado OK</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card shadow-sm border-0 bg-surface">
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
        let statusClass = 'bg-secondary-subtle text-secondary';
        if (status === 'OK') statusClass = 'bg-success-subtle text-success';
        else if (status === 'PENDING') statusClass = 'bg-warning-subtle text-warning';
        else if (status === 'ERROR') statusClass = 'bg-danger-subtle text-danger';
        const actions = row.uuid ? `
          <a class="btn btn-sm btn-outline-primary me-1" href="${api('fel/pdf')}?uuid=${encodeURIComponent(row.uuid)}" target="_blank" rel="noopener">PDF</a>
          <a class="btn btn-sm btn-outline-secondary" href="${api('fel/xml')}?uuid=${encodeURIComponent(row.uuid)}" target="_blank" rel="noopener">XML</a>
        ` : '<span class="badge bg-light text-muted border">Sin UUID</span>';
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

  // ===== Ajustes (igual que lo tenías) =====
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
              <div class="d-flex align-items-start justify-content-between gap-2">
                <div>
                  <h5 class="card-title mb-1">Actividad de sincronización</h5>
                  <p class="text-muted small mb-0">Resumen generado ${escapeHtml(formatRelativeTime(settings.generated_at) || 'hace instantes')}.</p>
                </div>
                <button class="btn btn-outline-primary btn-sm" id="settingsReload">Actualizar</button>
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
  }

  const renderers = { dashboard: renderDashboard, invoices: renderInvoices, reports: renderReports, settings: renderSettings };

  function goToPage(page) {
    const link = document.querySelector(`.nav-link[data-page="${page}"]`);
    if (link) {
      setActive(link);
    }
    (renderers[page] || renderDashboard)();
  }

  function initNav() {
    const links = document.querySelectorAll('[data-page]');
    links.forEach(link => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const page = link.getAttribute('data-page');
        goToPage(page);
      });
    });

    document.querySelectorAll('[data-go-page]').forEach((control) => {
      control.addEventListener('click', () => {
        const page = control.getAttribute('data-go-page');
        if (page) {
          goToPage(page);
        }
      });
    });

    const initial = document.querySelector('.nav-link.active[data-page]') || document.querySelector('[data-page="dashboard"]');
    if (initial) {
      goToPage(initial.getAttribute('data-page'));
    } else {
      renderDashboard();
    }
  }

  initNav();
})();
