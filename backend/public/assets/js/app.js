(() => {
  const app = document.getElementById('app');

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

  // ===== Dashboard =====
  async function renderDashboard() {
    try {
      const { data } = await fetchJSON(api('tickets'));
      const state = { search: '', page: 1 };
      const pageSize = 20;

      app.innerHTML = `
        <div class="row g-4">
          <div class="col-md-4">
            <div class="card shadow-sm">
              <div class="card-body">
                <h5 class="card-title">Asistencias de hoy</h5>
                <p class="display-6 mb-0">${data.length}</p>
                <span class="badge badge-soft mt-2">Datos de G4S</span>
              </div>
            </div>
          </div>
          <div class="col-md-8">
            <div class="card shadow-sm">
              <div class="card-body d-flex flex-column gap-3">
                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                  <h5 class="card-title mb-0">Listado</h5>
                  <div class="ms-auto" style="max-width: 240px;">
                    <input type="search" id="dashSearch" class="form-control form-control-sm" placeholder="Buscar..." aria-label="Buscar asistencia" />
                  </div>
                </div>
                <div class="table-responsive">
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
        </div>`;

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
                <td>${start + index + 1}</td>
                <td>${row.name}</td>
                <td>${row.checkIn || '-'}</td>
                <td>${row.checkOut || '-'}</td>
              </tr>
            `)
            .join('');
        } else {
          tbody.innerHTML = `
            <tr>
              <td colspan="4" class="text-center text-muted">${data.length ? 'No se encontraron resultados' : 'Sin registros disponibles'}</td>
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

        prevBtn.disabled = state.page <= 1;
        nextBtn.disabled = state.page >= totalPages;
      }

      searchInput.addEventListener('input', (event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        renderTable();
      });

      prevBtn.addEventListener('click', () => {
        if (state.page > 1) {
          state.page -= 1;
          renderTable();
        }
      });

      nextBtn.addEventListener('click', () => {
        const totalPages = Math.max(1, Math.ceil(filterData().length / pageSize));
        if (state.page < totalPages) {
          state.page += 1;
          renderTable();
        }
      });

      renderTable();
    } catch (e) {
      app.innerHTML = `
        <div class="alert alert-danger">
          No se pudo cargar el dashboard.<br/>
          <pre class="small mb-0">${String(e).replace(/[<>&]/g,s=>({"<":"&lt;",">":"&gt;","&":"&amp;"}[s]))}</pre>
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
        <div class="card-body">
          <h5 class="card-title">Ajustes</h5>
          <p class="text-muted">Configura variables de entorno en <code>backend/.env</code> (ver <code>.env.sample</code>).</p>
          <ul>
            <li><strong>ZKTeco:</strong> ZKTECO_BASE_URL, ZKTECO_APP_KEY, ZKTECO_APP_SECRET</li>
            <li><strong>G4S FEL:</strong> (usa RequestTransaction) FEL_G4S_* en .env</li>
            <li><strong>SAT Emisor:</strong> variables SAT_*</li>
          </ul>
        </div>
      </div>
    `;
  }

  const renderers = { dashboard: renderDashboard, invoices: renderInvoices, reports: renderReports, settings: renderSettings };

  function initNav() {
    const links = document.querySelectorAll('[data-page]');
    links.forEach(link => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const page = link.getAttribute('data-page');
        setActive(link);
        (renderers[page] || renderDashboard)();
      });
    });
    // Activa la que tenga .active o dashboard por defecto
    const initial = document.querySelector('.nav-link.active[data-page]') || document.querySelector('[data-page="dashboard"]');
    if (initial) { setActive(initial); (renderers[initial.getAttribute('data-page')] || renderDashboard)(); }
    else { renderDashboard(); }
  }

  

  initNav();
})();
