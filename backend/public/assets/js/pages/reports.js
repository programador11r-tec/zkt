(() => {
  const Core = window.AppCore;
  if (!Core) return;
  const { api, fetchJSON, escapeHtml, formatNumber, formatDateTime } = Core;
  const { app } = Core.elements;

  async function renderReports() { 
    const today = new Date();
    // Fecha local YYYY-MM-DD (sin UTC)
    const toISODate = (d) => {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      return `${y}-${m}-${day}`;
    };
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

    // Ahora tenemos DOS estados biométricos, uno por dispositivo
    const biometricState158 = {
      rows: [],
      summary: null,
      page: 1,
      perPage: 20,
    };

    const biometricState155 = {
      rows: [],
      summary: null,
      page: 1,
      perPage: 20,
    };

    // === Helpers ===
    const escapeHtml = (v) => String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

    // Parseo robusto de fecha como LOCAL (incluye "YYYY-MM-DD HH:MM:SS")
    const parseDateLike = (v) => {
      if (!v) return null;
      if (v instanceof Date) {
        return isNaN(v.getTime()) ? null : v;
      }
      if (typeof v === 'number') {
        const dNum = new Date(v);
        return isNaN(dNum.getTime()) ? null : dNum;
      }
      if (typeof v === 'string') {
        let d = new Date(v);
        if (!isNaN(d.getTime())) return d;

        const m = v.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
        if (m) {
          const [_, yy, mm, dd, hh, mi, ss] = m;
          d = new Date(
            Number(yy),
            Number(mm) - 1,
            Number(dd),
            Number(hh),
            Number(mi),
            ss ? Number(ss) : 0
          );
          return isNaN(d.getTime()) ? null : d;
        }
      }
      return null;
    };

    // Para mostrar en tabla
    const formatDateValue = (v) => {
      const d = parseDateLike(v);
      if (!d) return '—';
      return d.toLocaleString('es-GT', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      });
    };

    // Para CSV: YYYY-MM-DD HH:mm:ss local
    const formatDateForCsv = (v) => {
      const d = parseDateLike(v);
      if (!d) return '';
      const yy = d.getFullYear();
      const mm = String(d.getMonth() + 1).padStart(2, '0');
      const dd = String(d.getDate()).padStart(2, '0');
      const hh = String(d.getHours()).padStart(2, '0');
      const mi = String(d.getMinutes()).padStart(2, '0');
      const ss = String(d.getSeconds()).padStart(2, '0');
      return `${yy}-${mm}-${dd} ${hh}:${mi}:${ss}`;
    };

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

    const getUuid = (r) => {
      if (r?.fel_uuid) return r.fel_uuid;
      if (r?.uuid) return r.uuid;
      try {
        const j = JSON.parse(r?.response_json || '{}');
        return j?.uuid || j?.data?.uuid || null;
      } catch { return null; }
    };

    const app = document.getElementById('app') || document.body;
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
            <div class="col-md-3">
              <label class="form-label small" for="invoiceTicket">Ticket</label>
              <input type="text" id="invoiceTicket" class="form-control form-control-sm" placeholder="Número de ticket">
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
                  <th>Placa</th>
                  <th>Fecha</th>
                  <th class="text-end">Total</th>
                  <th>Receptor</th>
                  <th>UUID</th>
                  <th>Estado</th>
                  <th class="text-center">Acciones</th>
                </tr>
              </thead>
              <tbody id="invoiceRows">
                <tr><td colspan="8" class="text-center text-muted">Consulta para ver resultados.</td></tr>
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

      <!-- Reporte de registros de dispositivos biométricos -->
      <section class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
              <h5 class="card-title mb-1">Registros de dispositivos biométricos</h5>
              <p class="text-muted small mb-0">Eventos de acceso obtenidos desde CVSecurity (dos dispositivos).</p>
            </div>
            <span class="badge text-bg-secondary">Biométrico</span>
          </div>

          <form id="biometricFilters" class="row g-3 mt-3">
            <div class="col-md-3">
              <label class="form-label small" for="biometricFrom">Desde</label>
              <input type="date" id="biometricFrom" class="form-control form-control-sm" value="${escapeHtml(defaultFrom)}">
            </div>
            <div class="col-md-3">
              <label class="form-label small" for="biometricTo">Hasta</label>
              <input type="date" id="biometricTo" class="form-control form-control-sm" value="${escapeHtml(defaultTo)}">
            </div>
            <div class="col-md-6">
              <label class="form-label small" for="biometricSearch">Buscar</label>
              <input type="text" id="biometricSearch" class="form-control form-control-sm"
                    placeholder="PIN, nombre, área, evento...">
            </div>
          </form>

          <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
            <div class="btn-group btn-group-sm" role="group">
              <button type="button" class="btn btn-primary" id="biometricFetch">Buscar</button>
              <button type="button" class="btn btn-outline-secondary" id="biometricReset">Limpiar</button>
            </div>
            <div class="ms-auto d-flex gap-2">
              <button type="button" class="btn btn-outline-primary btn-sm" id="biometricCsv" disabled>Exportar CSV</button>
            </div>
          </div>

          <div id="biometricAlert" class="mt-3"></div>

          <div id="biometricSummary" class="row g-3 mt-2"></div>

          <!-- Tabla dispositivo TDBD244800158 -->
          <h6 class="mt-3 mb-1">Dispositivo TDBD244800158</h6>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Fecha / hora</th>
                  <th>ID</th>
                  <th>PIN</th>
                  <th>Nombre</th>
                  <th>Apellido</th>
                  <th>Tipo verificación</th>
                  <th>Área</th>
                  <th>Dispositivo</th>
                  <th>Evento</th>
                </tr>
              </thead>
              <tbody id="biometricRows158">
                <tr><td colspan="9" class="text-center text-muted">Consulta para ver resultados.</td></tr>
              </tbody>
            </table>
          </div>
          <div id="biometricPagination158" class="mt-2"></div>

          <!-- Tabla dispositivo TDBD244800155 -->
          <h6 class="mt-4 mb-1">Dispositivo TDBD244800155</h6>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Fecha / hora</th>
                  <th>ID</th>
                  <th>PIN</th>
                  <th>Nombre</th>
                  <th>Apellido</th>
                  <th>Tipo verificación</th>
                  <th>Área</th>
                  <th>Dispositivo</th>
                  <th>Evento</th>
                </tr>
              </thead>
              <tbody id="biometricRows155">
                <tr><td colspan="9" class="text-center text-muted">Consulta para ver resultados.</td></tr>
              </tbody>
            </table>
          </div>
          <div id="biometricPagination155" class="mt-2"></div>

          <div id="biometricMessage" class="small text-muted mt-2"></div>
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

    const biometricRows158El = document.getElementById('biometricRows158');
    const biometricRows155El = document.getElementById('biometricRows155');
    const biometricSummaryEl = document.getElementById('biometricSummary');
    const biometricMsgEl = document.getElementById('biometricMessage');
    const biometricPagination158El = document.getElementById('biometricPagination158');
    const biometricPagination155El = document.getElementById('biometricPagination155');
    const biometricAlertEl = document.getElementById('biometricAlert');
    const biometricCsvBtn = document.getElementById('biometricCsv');

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
      invoiceRowsEl.innerHTML = `<tr><td colspan="8" class="text-center text-muted">Consultando...</td></tr>`;
      invoiceSummaryEl.innerHTML = '';
      invoiceMsgEl.textContent = '';
      clearAlert(invoiceAlertEl);
      invoiceCsvBtn.disabled = true;

      const params = new URLSearchParams();
      const from = document.getElementById('invoiceFrom').value;
      const to = document.getElementById('invoiceTo').value;
      const status = document.getElementById('invoiceStatus').value;
      const nit = document.getElementById('invoiceNit').value;
      const ticketNo = document.getElementById('invoiceTicket').value;

      if (from) params.set('from', from);
      if (to) params.set('to', to);
      if (status !== 'ANY') params.set('status', status);
      if (nit) params.set('nit', nit);
      if (ticketNo) params.set('ticket_no', ticketNo);

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
        invoiceRowsEl.innerHTML = `<tr><td colspan="8" class="text-center text-danger">Error: ${escapeHtml(err.message)}</td></tr>`;
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
        invoiceRowsEl.innerHTML = `<tr><td colspan="9" class="text-center text-muted">No hay registros.</td></tr>`;
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
        const discount = Number(r.discount_amount ?? 0);
        const actions = uuid
          ? `<button type="button" class="btn btn-sm btn-outline-primary me-1"
              data-action="pdf"
              data-uuid="${escapeHtml(uuid)}">PDF</button>`
          : '<span class="badge text-bg-light border">Sin UUID</span>';

        return `
          <tr>
            <td>${escapeHtml(r.ticket_no)}</td>
            <td>${escapeHtml(r.plate ?? '—')}</td>
            <td>${escapeHtml(formatDateValue(r.fecha))}</td>
            <td class="text-end">
              ${formatCurrency(r.total)}
              ${discount > 0 ? `<div class="small text-success">- ${formatCurrency(discount)}${r.discount_code ? ' (' + escapeHtml(r.discount_code) + ')' : ''}</div>` : ''}
            </td>
            <td>${escapeHtml(r.receptor ?? 'CF')}</td>
            <td class="text-truncate" style="max-width:220px;">${escapeHtml(uuid ?? '—')}</td>
            <td><span class="badge ${badge}">${formatInvoiceStatus(status)}</span></td>
            <td class="text-center">${actions}</td>
          </tr>`;
      }).join('');
      buildPagination(invoicePaginationEl, invoiceState, renderInvoiceRows);
    };

    // CSV emitidas
    invoiceCsvBtn.addEventListener('click', () => {
      const rows = invoiceState.rows || [];
      if (!rows.length) return;

      const headers = ['ticket_no','plate','fecha_hora_local','total','receptor','uuid','status'];
      const csv = [
        headers.join(','),
        ...rows.map(r => {
          const fechaLocal = formatDateForCsv(r.fecha);
          const vals = [
            String(r.ticket_no ?? '').replaceAll('"','""'),
            String(r.plate ?? '').replaceAll('"','""'),
            fechaLocal.replaceAll('"','""'),
            Number(r.total ?? 0).toFixed(2),
            String(r.receptor ?? 'CF').replaceAll('"','""'),
            String(getUuid(r) ?? '').replaceAll('"','""'),
            String((r.status || '').toUpperCase()).replaceAll('"','""'),
          ];
          return vals.map(v => `"${v}"`).join(',');
        }),
      ].join('\r\n');

      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      downloadBlob(blob, `facturas_${document.getElementById('invoiceFrom').value}_${document.getElementById('invoiceTo').value}.csv`);
    });

    // PDF emitidas — con sincronización de estado FEL antes de generar PDF
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
        // 1) Sincronizar estado FEL -> BD
        try {
          const statusResp = await fetchJSON(api('fel/invoice/status-sync') + '?uuid=' + encodeURIComponent(uuid));
          if (statusResp && statusResp.ok) {
            const msgParts = [];
            if (statusResp.document_status) {
              msgParts.push(`Estado FEL: ${statusResp.document_status}`);
            }
            if (statusResp.db_updated) {
              msgParts.push('Base de datos actualizada.');
            }
            if (msgParts.length) {
              showAlert(invoiceAlertEl, msgParts.join(' · '), 'info');
            }
            if (statusResp.db_updated) {
              // Recargar lista para reflejar el nuevo estado
              await fetchInvoiceReport();
            }
          } else if (statusResp && statusResp.error) {
            showAlert(
              invoiceAlertEl,
              `No se pudo sincronizar el estado FEL: ${statusResp.error}`,
              'warning'
            );
          }
        } catch (syncErr) {
          showAlert(
            invoiceAlertEl,
            `Error al sincronizar estado FEL: ${syncErr.message}`,
            'warning'
          );
        }

        // 2) Generar PDF como siempre
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

    // PDF manual
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

    // CSV facturas manuales
    manualInvoiceCsvBtn.addEventListener('click', () => {
      const rows = manualInvoiceState.rows || [];
      if (!rows.length) return;

      const headers = ['id','fecha_hora_local','monto','motivo','modo','nit','uuid','status'];
      const csv = [
        headers.join(','),
        ...rows.map(r => {
          const fechaLocal = formatDateForCsv(r.created_at || r.fecha || r.createdAt);
          const vals = [
            String(r.id ?? '').replaceAll('"','""'),
            fechaLocal.replaceAll('"','""'),
            Number(r.amount ?? r.total ?? 0).toFixed(2),
            String(r.reason ?? '').replaceAll('"','""'),
            String(r.mode ?? '').replaceAll('"','""'),
            String(r.receptor_nit ?? r.nit ?? 'CF').replaceAll('"','""'),
            String(getUuid(r) ?? '').replaceAll('"','""'),
            String((r.status || '').toUpperCase()).replaceAll('"','""'),
          ];
          return vals.map(v => `"${v}"`).join(',');
        }),
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

    // CSV aperturas
    manualOpenCsvBtn.addEventListener('click', () => {
      const rows = manualOpenState.rows || [];
      if (!rows.length) return;

      const headers = ['tipo','fecha_hora_local','motivo','canal'];
      const csv = [
        headers.join(','),
        ...rows.map(r => {
          const fechaLocal = formatDateForCsv(r.opened_at);
          const vals = [
            String((r.tipo || '').toUpperCase()).replaceAll('"','""'),
            fechaLocal.replaceAll('"','""'),
            String(r.reason ?? '').replaceAll('"','""'),
            String(r.channel_id ?? '').replaceAll('"','""'),
          ];
          return vals.map(v => `"${v}"`).join(',');
        }),
      ].join('\r\n');

      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      downloadBlob(blob, `aperturas_manuales_${document.getElementById('manualOpenFrom').value}_${document.getElementById('manualOpenTo').value}.csv`);
    });

    // === Reporte biométrico (dos dispositivos) ===
    const fetchBiometricReport = async () => {
      biometricRows158El.innerHTML = `<tr><td colspan="9" class="text-center text-muted">Consultando...</td></tr>`;
      biometricRows155El.innerHTML = `<tr><td colspan="9" class="text-center text-muted">Consultando...</td></tr>`;
      biometricSummaryEl.innerHTML = '';
      biometricMsgEl.textContent = '';
      clearAlert(biometricAlertEl);
      biometricCsvBtn.disabled = true;

      const params = new URLSearchParams();
      const from = document.getElementById('biometricFrom').value;
      const to = document.getElementById('biometricTo').value;
      const q = document.getElementById('biometricSearch').value;

      if (from) params.set('from', from);
      if (to) params.set('to', to);
      if (q) params.set('q', q);

      try {
        const baseUrl = api('reports/device-logs');

        const url158 = params.size
          ? `${baseUrl}?${params.toString()}&deviceSn=TDBD244800158`
          : `${baseUrl}?deviceSn=TDBD244800158`;

        const url155 = params.size
          ? `${baseUrl}?${params.toString()}&deviceSn=TDBD244800155`
          : `${baseUrl}?deviceSn=TDBD244800155`;

        const [js158, js155] = await Promise.all([
          fetchJSON(url158),
          fetchJSON(url155),
        ]);

        if (!js158 || js158.ok === false) {
          throw new Error(js158?.error || 'Error en logs de dispositivo TDBD244800158');
        }
        if (!js155 || js155.ok === false) {
          throw new Error(js155?.error || 'Error en logs de dispositivo TDBD244800155');
        }

        const rows158 = js158.rows || [];
        const rows155 = js155.rows || [];

        biometricState158.rows = rows158;
        biometricState155.rows = rows155;

        biometricState158.summary = { total: rows158.length };
        biometricState155.summary = { total: rows155.length };

        biometricState158.page = 1;
        biometricState155.page = 1;

        renderBiometricRows158();
        renderBiometricRows155();
        renderBiometricSummary();

        biometricCsvBtn.disabled = (rows158.length + rows155.length) === 0;
      } catch (err) {
        biometricRows158El.innerHTML = `<tr><td colspan="9" class="text-center text-danger">Error: ${escapeHtml(err.message)}</td></tr>`;
        biometricRows155El.innerHTML = `<tr><td colspan="9" class="text-center text-danger">Error: ${escapeHtml(err.message)}</td></tr>`;
        showAlert(biometricAlertEl, `No se pudo cargar el reporte de dispositivos: ${err.message}`, 'danger');
      }
    };

    const renderBiometricSummary = () => {
      const s158 = biometricState158.summary || { total: 0 };
      const s155 = biometricState155.summary || { total: 0 };
      const total = (s158.total || 0) + (s155.total || 0);

      biometricSummaryEl.innerHTML = `
        <div class="col-sm-6 col-lg-4">
          <div class="card border-0 bg-body-tertiary h-100">
            <div class="card-body py-3">
              <div class="text-muted small">Registros dispositivo TDBD244800158</div>
              <div class="fs-5 fw-semibold">${s158.total || 0}</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-4">
          <div class="card border-0 bg-body-tertiary h-100">
            <div class="card-body py-3">
              <div class="text-muted small">Registros dispositivo TDBD244800155</div>
              <div class="fs-5 fw-semibold">${s155.total || 0}</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-4">
          <div class="card border-0 bg-body-tertiary h-100">
            <div class="card-body py-3">
              <div class="text-muted small">Registros totales</div>
              <div class="fs-5 fw-semibold">${total}</div>
            </div>
          </div>
        </div>
      `;

      biometricMsgEl.textContent = '';
    };

    const renderBiometricRows158 = () => {
      const { slice } = paginate(biometricState158.rows, biometricState158.page, biometricState158.perPage);
      if (!slice.length) {
        biometricRows158El.innerHTML = `<tr><td colspan="9" class="text-center text-muted">No hay registros.</td></tr>`;
        biometricPagination158El.innerHTML = '';
        return;
      }

      biometricRows158El.innerHTML = slice.map((r) => {
        return `
          <tr>
            <td>${escapeHtml(formatDateValue(r.eventTime))}</td>
            <td>${escapeHtml(r.logId ?? r.id ?? '')}</td>
            <td>${escapeHtml(r.pin ?? '')}</td>
            <td>${escapeHtml(r.name ?? '')}</td>
            <td>${escapeHtml(r.lastName ?? '')}</td>
            <td>${escapeHtml(r.verifyModeName ?? r.verify_mode ?? '')}</td>
            <td>${escapeHtml(r.areaName ?? '')}</td>
            <td>${escapeHtml(r.devName ?? '')}</td>
            <td>${escapeHtml(r.eventName ?? '')}</td>
          </tr>`;
      }).join('');

      buildPagination(biometricPagination158El, biometricState158, renderBiometricRows158);
    };

    const renderBiometricRows155 = () => {
      const { slice } = paginate(biometricState155.rows, biometricState155.page, biometricState155.perPage);
      if (!slice.length) {
        biometricRows155El.innerHTML = `<tr><td colspan="9" class="text-center text-muted">No hay registros.</td></tr>`;
        biometricPagination155El.innerHTML = '';
        return;
      }

      biometricRows155El.innerHTML = slice.map((r) => {
        return `
          <tr>
            <td>${escapeHtml(formatDateValue(r.eventTime))}</td>
            <td>${escapeHtml(r.logId ?? r.id ?? '')}</td>
            <td>${escapeHtml(r.pin ?? '')}</td>
            <td>${escapeHtml(r.name ?? '')}</td>
            <td>${escapeHtml(r.lastName ?? '')}</td>
            <td>${escapeHtml(r.verifyModeName ?? r.verify_mode ?? '')}</td>
            <td>${escapeHtml(r.areaName ?? '')}</td>
            <td>${escapeHtml(r.devName ?? '')}</td>
            <td>${escapeHtml(r.eventName ?? '')}</td>
          </tr>`;
      }).join('');

      buildPagination(biometricPagination155El, biometricState155, renderBiometricRows155);
    };

    // CSV biométrico combinado (ambos dispositivos)
    biometricCsvBtn.addEventListener('click', () => {
      const rows158 = biometricState158.rows || [];
      const rows155 = biometricState155.rows || [];
      const rows = rows158.concat(rows155);
      if (!rows.length) return;

      const headers = [
        'eventTime','id','pin','nombre','apellido','tipo_verificacion',
        'area','dispositivo','evento'
      ];
      const csv = [
        headers.join(','),
        ...rows.map(r => {
          const vals = [
            formatDateForCsv(r.eventTime).replaceAll('"','""'),
            String(r.logId ?? r.id ?? '').replaceAll('"','""'),
            String(r.pin ?? '').replaceAll('"','""'),
            String(r.name ?? '').replaceAll('"','""'),
            String(r.lastName ?? '').replaceAll('"','""'),
            String(r.verifyModeName ?? r.verify_mode ?? '').replaceAll('"','""'),
            String(r.areaName ?? '').replaceAll('"','""'),
            String(r.devName ?? '').replaceAll('"','""'),
            String(r.eventName ?? '').replaceAll('"','""'),
          ];
          return vals.map(v => `"${v}"`).join(',');
        }),
      ].join('\r\n');

      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      downloadBlob(
        blob,
        `biometrico_${document.getElementById('biometricFrom').value}_${document.getElementById('biometricTo').value}.csv`
      );
    });

    // Listeners de búsqueda / reset
    document.getElementById('invoiceFetch').addEventListener('click', fetchInvoiceReport);
    document.getElementById('invoiceReset').addEventListener('click', () => {
      document.getElementById('invoiceFrom').value = defaultFrom;
      document.getElementById('invoiceTo').value = defaultTo;
      document.getElementById('invoiceStatus').value = 'ANY';
      document.getElementById('invoiceNit').value = '';
      document.getElementById('invoiceTicket').value = '';
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

    document.getElementById('biometricFetch').addEventListener('click', fetchBiometricReport);
    document.getElementById('biometricReset').addEventListener('click', () => {
      document.getElementById('biometricFrom').value = defaultFrom;
      document.getElementById('biometricTo').value = defaultTo;
      document.getElementById('biometricSearch').value = '';
      fetchBiometricReport();
    });

    // Carga inicial
    await fetchInvoiceReport();
    await fetchManualInvoiceReport();
    await fetchManualOpenReport();
    await fetchBiometricReport();
  }
  Core.registerPage('reports', renderReports);
})();
