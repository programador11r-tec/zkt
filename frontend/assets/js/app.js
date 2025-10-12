(() => {
  const app = document.getElementById('app');
  const sidebar = document.getElementById('appSidebar');
  const sidebarLinks = sidebar ? Array.from(sidebar.querySelectorAll('.nav-link')) : [];

  if (sidebar) {
    sidebar.setAttribute('aria-hidden', 'false');
  }

  function setActive(link) {
    document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
    link.classList.add('active');
  }

  async function fetchJSON(url, opts) {
    const res = await fetch(url, opts);
    return res.json();
  }

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

  const renderers = { dashboard: renderDashboard, invoices: renderInvoices, settings: renderSettings };

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