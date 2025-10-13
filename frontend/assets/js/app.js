(() => {
  const app = document.getElementById('app');
  const sidebar = document.getElementById('appSidebar');
  const sidebarLinks = sidebar ? Array.from(sidebar.querySelectorAll('.nav-link')) : [];
  let currentPage = null;
  let renderGeneration = 0;

  if (sidebar) {
    sidebar.setAttribute('aria-hidden', 'false');
  }

  function setActive(link) {
    sidebarLinks.forEach((a) => a.classList.remove('active'));
    if (link) {
      link.classList.add('active');
    }
  }

  async function fetchJSON(url, opts) {
    const res = await fetch(url, opts);
    return res.json();
  }

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  async function renderDashboard() {
    const { data } = await fetchJSON('/api/dummy/attendance');
    const state = { search: '', page: 1 };
    const pageSize = 20;

    app.innerHTML = `
      <div class="row g-4">
        <div class="col-md-4">
          <div class="card shadow-sm">
            <div class="card-body">
              <h5 class="card-title">Asistencias de hoy</h5>
              <p class="display-6 mb-0" id="attendanceTotal">${data.length}</p>
              <span class="badge badge-soft mt-2">Dummy</span>
            </div>
          </div>
        </div>
        <div class="col-md-8">
          <div class="card shadow-sm">
            <div class="card-body d-flex flex-column gap-3">
              <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <h5 class="card-title mb-0">Listado</h5>
                <div class="ms-auto" style="max-width: 240px;">
                  <input type="search" id="attendanceSearch" class="form-control form-control-sm" placeholder="Buscar..." aria-label="Buscar asistencia" />
                </div>
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead><tr><th>#</th><th>Nombre</th><th>Entrada</th><th>Salida</th></tr></thead>
                  <tbody id="attendanceBody"></tbody>
                </table>
              </div>
              <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <small class="text-muted" id="attendanceMeta"></small>
                <div class="btn-group btn-group-sm" role="group" aria-label="Paginación de asistencias">
                  <button type="button" class="btn btn-outline-secondary" id="attendancePrev">Anterior</button>
                  <button type="button" class="btn btn-outline-secondary" id="attendanceNext">Siguiente</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;

    const tbody = document.getElementById('attendanceBody');
    const meta = document.getElementById('attendanceMeta');
    const searchInput = document.getElementById('attendanceSearch');
    const prevBtn = document.getElementById('attendancePrev');
    const nextBtn = document.getElementById('attendanceNext');

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
              <td>${row.checkIn}</td>
              <td>${row.checkOut}</td>
            </tr>
          `)
          .join('');
      } else {
        const message = data.length && !filtered.length
          ? 'No se encontraron resultados'
          : 'Sin registros disponibles';
        tbody.innerHTML = `
          <tr>
            <td colspan="4" class="text-center text-muted">${message}</td>
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
  }

  async function renderInvoices() {
    app.innerHTML = `
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Emitir factura (simulada)</h5>
          <form id="felForm" class="row g-3">
            <div class="col-md-4">
              <label class="form-label">NIT Receptor</label>
              <input type="text" class="form-control" name="nit" value="CF" required />
            </div>
            <div class="col-md-8">
              <label class="form-label">Descripción</label>
              <input type="text" class="form-control" name="desc" value="Servicio de Control de Acceso" required />
            </div>
            <div class="col-md-3">
              <label class="form-label">Cantidad</label>
              <input type="number" class="form-control" name="cant" value="1" min="1" />
            </div>
            <div class="col-md-3">
              <label class="form-label">Precio</label>
              <input type="number" class="form-control" step="0.01" name="precio" value="100" />
            </div>
            <div class="col-12">
              <button class="btn btn-primary">Certificar en Sandbox (Dummy)</button>
            </div>
          </form>
          <hr />
          <pre id="felOut" class="bg-light p-3 rounded small"></pre>
        </div>
      </div>
    `;

    document.getElementById('felForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const item = {
        descripcion: fd.get('desc'),
        cantidad: Number(fd.get('cant')),
        precio: Number(fd.get('precio')),
      };
      const iva = +(item.precio * item.cantidad * 0.12).toFixed(2);
      const total = +(item.precio * item.cantidad + iva).toFixed(2);
      const payload = {
        invoice: {
          serie: 'PRUEBA',
          numero: 1,
          nitReceptor: fd.get('nit') || 'CF',
          items: [{ ...item, iva, total }],
          total
        }
      };
      const resp = await fetchJSON('/api/fel/invoice', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      document.getElementById('felOut').textContent = JSON.stringify(resp, null, 2);
    });
  }

  async function renderReports() {
    const { data } = await fetchJSON('/api/dummy/attendance');
    const rows = Array.isArray(data) ? data : [];

    const normaliseDate = (value) => {
      if (!value) return null;
      const safe = value.includes('T') ? value : value.replace(' ', 'T');
      const dt = new Date(safe);
      return Number.isNaN(dt.getTime()) ? null : dt;
    };

    const uniquePeople = new Set();
    const dayBuckets = new Map();

    rows.forEach((row) => {
      if (row?.name) {
        uniquePeople.add(row.name);
      }

      const when = normaliseDate(row?.checkIn) || normaliseDate(row?.checkOut);
      const key = when ? when.toISOString().slice(0, 10) : 'unknown';
      const label = when
        ? when.toLocaleDateString('es-GT', { weekday: 'short', day: '2-digit', month: 'short' })
        : 'Sin fecha';

      const bucket = dayBuckets.get(key) || { key, label, count: 0 };
      bucket.count += 1;
      dayBuckets.set(key, bucket);
    });

    const totalsByDay = Array.from(dayBuckets.values()).sort((a, b) => b.key.localeCompare(a.key));
    const topDays = totalsByDay.slice(0, 5);

    const lastEntries = rows.slice().sort((a, b) => {
      const aDate = normaliseDate(a?.checkIn) || normaliseDate(a?.checkOut);
      const bDate = normaliseDate(b?.checkIn) || normaliseDate(b?.checkOut);
      const aTime = aDate ? aDate.getTime() : 0;
      const bTime = bDate ? bDate.getTime() : 0;
      return bTime - aTime;
    }).slice(0, 6);

    const renderDayList = () => {
      if (!topDays.length) {
        return '<li class="text-muted small">Sin datos disponibles</li>';
      }

      return topDays.map((day) => `
        <li class="d-flex align-items-center justify-content-between">
          <span>${escapeHtml(day.label)}</span>
          <span class="badge bg-primary-subtle text-primary">${day.count}</span>
        </li>
      `).join('');
    };

    const renderRecent = () => {
      if (!lastEntries.length) {
        return '<div class="text-muted small">Sin registros recientes</div>';
      }

      return lastEntries.map((row) => {
        const when = normaliseDate(row?.checkIn) || normaliseDate(row?.checkOut);
        const whenLabel = when
          ? when.toLocaleString('es-GT', { dateStyle: 'medium', timeStyle: 'short' })
          : 'Sin fecha';
        return `
          <div class="report-entry">
            <div class="fw-semibold">${escapeHtml(row?.name ?? 'Sin nombre')}</div>
            <div class="text-muted small">${escapeHtml(whenLabel)}</div>
          </div>
        `;
      }).join('');
    };

    app.innerHTML = `
      <div class="row g-4">
        <div class="col-xl-4">
          <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column gap-3">
              <div>
                <h5 class="card-title mb-1">Reportes de asistencia</h5>
                <p class="text-muted small mb-0">Visualiza métricas generadas a partir de los datos dummy.</p>
              </div>
              <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                <li class="d-flex justify-content-between align-items-center">
                  <span>Total de registros</span>
                  <strong>${rows.length}</strong>
                </li>
                <li class="d-flex justify-content-between align-items-center">
                  <span>Colaboradores únicos</span>
                  <strong>${uniquePeople.size}</strong>
                </li>
                <li class="d-flex justify-content-between align-items-center">
                  <span>Días con actividad</span>
                  <strong>${totalsByDay.length}</strong>
                </li>
              </ul>
            </div>
          </div>
        </div>
        <div class="col-xl-4">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <h6 class="text-uppercase text-muted fs-7 mb-3">Días con más registros</h6>
              <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                ${renderDayList()}
              </ul>
            </div>
          </div>
        </div>
        <div class="col-xl-4">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <h6 class="text-uppercase text-muted fs-7 mb-3">Últimos ingresos</h6>
              <div class="d-flex flex-column gap-2">
                ${renderRecent()}
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  async function renderSettings() {
    app.innerHTML = `
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Ajustes</h5>
          <p class="text-muted">Configura variables de entorno en <code>backend/.env</code> (ver <code>.env.sample</code>).</p>
          <ul>
            <li><strong>ZKTeco:</strong> ZKTECO_BASE_URL, ZKTECO_APP_KEY, ZKTECO_APP_SECRET</li>
            <li><strong>G4S FEL:</strong> G4S_BASE_URL, G4S_APP_KEY, G4S_APP_SECRET</li>
            <li><strong>SAT Emisor:</strong> variables SAT_*</li>
          </ul>
        </div>
      </div>
    `;
  }

  const renderers = { dashboard: renderDashboard, invoices: renderInvoices, reports: renderReports, settings: renderSettings };

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
      console.error('Error al renderizar la vista dummy', error);
    } finally {
      if (renderGeneration === requestId) {
        currentPage = target;
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

    const initialLink = document.querySelector('.nav-link.active[data-page]') || sidebarLinks[0];
    const initialPage = initialLink?.getAttribute('data-page') || 'dashboard';
    void goToPage(initialPage, { force: true });
  }

  initNav();
})();
