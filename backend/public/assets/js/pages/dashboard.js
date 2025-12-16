(() => {
  const Core = window.AppCore;
  if (!Core) return;
  const {
    api,
    fetchJSON,
    escapeHtml,
    formatNumber,
    formatDateTime,
    formatRelativeTime,
    statusToClass,
    getTicketsSafe,
    normalizeTickets,
    safeLoadSettings,
    buildTimeline,
    triggerHamachiSync,
    hamachiSyncState,
  } = Core;

  const HAMACHI_AUTO_SYNC_MS = 30000; // 30s
  const autoRefreshState = { running: false };

  async function syncHamachiOnce({ silent = true } = {}) {
    if (typeof triggerHamachiSync !== 'function') return;
    try {
      await triggerHamachiSync({ silent });
    } catch (err) {
      if (!silent) throw err;
      console.warn('[hamachi] sync failed', err);
    }
  }

  function ensureHamachiAutoSync() {
    if (!hamachiSyncState) return;
    if (hamachiSyncState.timer) return;
    hamachiSyncState.timer = setInterval(async () => {
      if (Core.state.currentPage !== 'dashboard') return;
      if (autoRefreshState.running) return;
      autoRefreshState.running = true;
      try {
        await syncHamachiOnce({ silent: true });
        // Vuelve a renderizar para recargar tickets y métricas
        await renderDashboard();
      } catch (err) {
        console.warn('[hamachi] auto-refresh failed', err);
      } finally {
        autoRefreshState.running = false;
      }
    }, HAMACHI_AUTO_SYNC_MS);
  }

  // Fallbacks defensivos si algУn helper no estß presente
  const getTicketsSafeFn = typeof getTicketsSafe === 'function'
    ? getTicketsSafe
    : async () => ({ data: [] });
  const normalizeTicketsFn = typeof normalizeTickets === 'function'
    ? normalizeTickets
    : (resp) => Array.isArray(resp?.data) ? resp.data : [];
  const safeLoadSettingsFn = typeof safeLoadSettings === 'function'
    ? safeLoadSettings
    : async () => null;
  const { app } = Core.elements;

  async function renderDashboard() {  
    try {
      // Sincroniza con el parqueo remoto antes de pintar el dashboard
      await syncHamachiOnce({ silent: true });
      ensureHamachiAutoSync();

      // 1) Primero tickets
      const ticketsResp = await getTicketsSafeFn();
      const data = normalizeTicketsFn(ticketsResp);

      // 2) Luego settings
      let settings = null;
      try {
        settings = await safeLoadSettingsFn(); 
      } catch (_) {
        settings = null;
      }

      const state = { search: '', page: 1 };
      const pageSize = 20;

      // 3) Métricas
      const metrics = settings?.database?.metrics ?? {};
      const pending = Number.isFinite(Number(metrics.pending_invoices)) ? Number(metrics.pending_invoices) : 0;

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

      // Layout principal
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
                          <th style="width:170px">Acciones</th>
                        </tr>
                      </thead>
                      <tbody id="dashBody">
                        <tr>
                          <td colspan="5" class="text-center text-muted py-4">
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

      // === Helper de alertas bonitas ===
      function showFancyAlert(type, message) {
        let container = document.getElementById('dashAlerts');
        if (!container) {
          container = document.createElement('div');
          container.id = 'dashAlerts';
          container.className = 'position-fixed top-0 end-0 p-3';
          container.style.zIndex = '1080';
          document.body.appendChild(container);
        }

        const id = 'alert-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        let variant = 'info';
        if (type === 'error') variant = 'danger';
        else if (type === 'success') variant = 'success';
        else if (type === 'warning') variant = 'warning';

        container.insertAdjacentHTML(
          'beforeend',
          `
          <div id="${id}" class="alert alert-${variant} alert-dismissible fade show shadow-sm mb-2 small" role="alert">
            ${escapeHtml(String(message))}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>`
        );

        // Auto-cierre a los 5s
        setTimeout(() => {
          const el = document.getElementById(id);
          if (!el) return;
          el.classList.remove('show');
          el.addEventListener('transitionend', () => el.remove(), { once: true });
        }, 5000);
      }

      // Timeline
      const timelineContainer = document.getElementById('activityTimeline');
      if (timelineContainer) {
            const tl = typeof buildTimeline === 'function'
              ? buildTimeline(settings?.activity)
              : '';
        timelineContainer.innerHTML = tl || `<div class="text-muted small">Sin actividad reciente.</div>`;
      }

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
            showFancyAlert('error', 'No se pudo actualizar la actividad.');
          } finally {
            if (!hadError && timelineRefresh.isConnected) {
              timelineRefresh.innerHTML = original;
            }
          }
        });
      }

      // --- Helpers para fechas / payNotify ---
      function parseLocalDateTime(str) {
        if (!str) return null;
        const [datePart, timePart = '00:00:00'] = String(str).split(' ');
        const [y, m, d] = datePart.split('-').map(Number);
        const [hh, mm, ss = 0] = timePart.split(':').map(Number);
        if (!y || !m || !d) return null;
        return new Date(y, m - 1, d, hh || 0, mm || 0, ss || 0);
      }

      function canRetryPayNotify(row) {
        let exitStr = row.rawExitAt || row.checkOut;
        if (!exitStr || exitStr === '-') return false;

        const exitDt = parseLocalDateTime(exitStr);
        if (!exitDt) return false;

        const nowMs = Date.now();
        const diffMs = nowMs - exitDt.getTime();
        if (diffMs < 0) return false;
        const diffMin = diffMs / 60000;
        return diffMin <= 5;
      }

      async function callPayNotifyAgain(ticketNo) {
        const res = await fetchJSON(api('fel/pay-notify-again'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ticket_no: ticketNo }),
        });
        return res;
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
          const rowsHtml = pageItems.map((row, index) => {
            const ticketNo = row.ticket_no || row.ticketNo || row.name || '';

            let exitStr = row.rawExitAt || row.checkOut || '';
            if (exitStr === '-') exitStr = '';

            const hasExit  = !!exitStr;
            const canRetry = ticketNo && hasExit && canRetryPayNotify(row);

            let btnHtml = '';

            if (ticketNo && hasExit) {
              if (canRetry) {
                btnHtml = `
                  <button type="button"
                          class="btn btn-sm btn-outline-primary dash-paynotify-btn"
                          data-enabled="1"
                          data-ticket="${escapeHtml(ticketNo)}"
                          data-exit="${escapeHtml(exitStr)}">
                    <i class="bi bi-arrow-repeat me-1"></i>Reenviar pago
                  </button>`;
              } else {
                btnHtml = `
                  <button type="button"
                          class="btn btn-sm btn-success"
                          disabled
                          aria-disabled="true">
                    <i class="bi bi-check-lg me-1"></i>OK
                  </button>`;
              }
            }

            return `
              <tr>
                <td>${escapeHtml(start + index + 1)}</td>
                <td>${escapeHtml(row.name)}</td>
                <td>${escapeHtml(row.checkIn || '-')}</td>
                <td>${escapeHtml(row.checkOut || '-')}</td>
                <td>${btnHtml}</td>
              </tr>
            `;
          }).join('');

          tbody.innerHTML = rowsHtml;
        } else {
          const message = data.length && !filtered.length ? 'No se encontraron resultados' : 'Sin registros disponibles';
          tbody.innerHTML = `
            <tr>
              <td colspan="5" class="text-center text-muted py-4">
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

        // Eventos de los botones "Reenviar pago"
        const btns = tbody.querySelectorAll('.dash-paynotify-btn[data-enabled="1"]');
        btns.forEach((btn) => {
          if (btn.dataset.bound === '1') return;
          btn.dataset.bound = '1';

          btn.addEventListener('click', async () => {
            const ticketNo = btn.getAttribute('data-ticket') || '';
            if (!ticketNo) return;

            const exitStr = btn.getAttribute('data-exit') || '';
            const exitDt  = parseLocalDateTime(exitStr);
            if (!exitDt) {
              showFancyAlert('error', 'No se pudo determinar la hora de salida del ticket.');
              return;
            }

            // Antes de enviar, validamos ventana de 5 minutos
            let nowMs = Date.now();
            let diffMin = (nowMs - exitDt.getTime()) / 60000;
            if (diffMin < 0 || diffMin > 5) {
              // Fuera de ventana: convertir botón en OK verde
              btn.classList.remove('btn-outline-primary');
              btn.classList.add('btn-success');
              btn.innerHTML = `<i class="bi bi-check-lg me-1"></i>OK`;
              btn.disabled = true;
              btn.removeAttribute('data-enabled');
              showFancyAlert('warning', 'Fuera de la ventana de 5 minutos para reenviar el pago.');
              return;
            }

            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Enviando…`;

            try {
              const res = await callPayNotifyAgain(ticketNo);

              if (!res || res.ok === false) {
                const errMsg = res?.error || res?.pay_notify_error || 'No se pudo reenviar el payNotify.';
                showFancyAlert('error', errMsg);
              } else {
                if (res.pay_notify_ack) {
                  showFancyAlert('success', 'PayNotify reenviado y confirmado por el sistema de parqueo.');
                } else {
                  const msg = res.pay_notify_error || 'El sistema de parqueo no confirmó el ticket.';
                  showFancyAlert('warning', 'Se envió el payNotify, pero hubo un problema: ' + msg);
                }
              }
            } catch (err) {
              console.error(err);
              showFancyAlert('error', 'Error de red al reenviar payNotify: ' + String(err));
            } finally {
              // Al terminar, ver si aún está dentro de la ventana de 5 min
              nowMs = Date.now();
              diffMin = (nowMs - exitDt.getTime()) / 60000;

              if (diffMin < 0 || diffMin > 5) {
                // Ya se pasó el tiempo: lo cambiamos a OK verde
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-success');
                btn.innerHTML = `<i class="bi bi-check-lg me-1"></i>OK`;
                btn.disabled = true;
                btn.removeAttribute('data-enabled');
              } else {
                // Sigue dentro de la ventana: se puede volver a usar
                btn.disabled = false;
                btn.innerHTML = originalHtml;
              }
            }
          });
        });
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

  Core.registerPage('dashboard', renderDashboard);
})();
