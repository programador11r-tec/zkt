(() => {
  const app = document.getElementById('app');

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
    app.innerHTML = `
      <div class="row g-4">
        <div class="col-md-4">
          <div class="card shadow-sm">
            <div class="card-body">
              <h5 class="card-title">Asistencias de hoy</h5>
              <p class="display-6 mb-0">${data.length}</p>
              <span class="badge badge-soft mt-2">Dummy</span>
            </div>
          </div>
        </div>
        <div class="col-md-8">
          <div class="card shadow-sm">
            <div class="card-body">
              <h5 class="card-title">Listado</h5>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>#</th><th>Nombre</th><th>Entrada</th><th>Salida</th></tr></thead>
                  <tbody>
                    ${data.map((r,i)=>`
                      <tr>
                        <td>${i+1}</td>
                        <td>${r.name}</td>
                        <td>${r.checkIn}</td>
                        <td>${r.checkOut}</td>
                      </tr>`).join('')}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
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

  function initNav() {
    const links = document.querySelectorAll('[data-page]');
    links.forEach(link => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const page = link.getAttribute('data-page');
        setActive(link);
        renderers[page]();
      });
    });
    // default
    setActive(document.querySelector('[data-page="dashboard"]'));
    renderDashboard();
  }

  initNav();
})();