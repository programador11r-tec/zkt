(() => {
  const Core = window.AppCore;
  if (!Core) return;

  const pages = Core.pages;
  const { app } = Core.elements;
  let allowedPages = null;
  let hiddenPages = null;
  let currentUser = null;

  const notImpl = (name) => {
    if (!app) return;
    app.innerHTML = `
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-2">${name}</h5>
          <p class="text-muted mb-0">Este módulo aún no está implementado.</p>
        </div>
      </div>`;
  };

  const setActiveNav = (page) => {
    document.querySelectorAll('#sidebarCollapse .nav-link').forEach((a) => {
      if (a.dataset.page === page) a.classList.add('active');
      else a.classList.remove('active');
    });
    document.querySelectorAll('#sidebarOffcanvas .nav-link').forEach((a) => {
      if (a.dataset.page === page) a.classList.add('active');
      else a.classList.remove('active');
    });
  };

  const parseHash = () => {
    const h = (location.hash || '').replace(/^#\/?/, '').trim();
    return h || 'dashboard';
  };

  const renderError = (page, error) => {
    if (!app) return;
    app.innerHTML = `
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title text-danger">Error al cargar ${page}</h5>
          <pre class="small mb-0">${(error && error.message) ? error.message : String(error)}</pre>
        </div>
      </div>`;
  };

  const isAllowed = (page) => {
    if (!allowedPages) return true;
    return allowedPages.has(page);
  };

  async function goToPage(page, { force = false, push = true } = {}) {
    const availablePages = Core.pages || {};
    const allowed = allowedPages;
    const fallbackCandidate = availablePages.dashboard ? 'dashboard' : Object.keys(availablePages)[0] || 'dashboard';
    const fallback = allowed ? (allowed.has(fallbackCandidate) ? fallbackCandidate : Array.from(allowed)[0] || fallbackCandidate) : fallbackCandidate;
    const target = (availablePages[page] && isAllowed(page)) ? page : fallback;

    if (!force && target === Core.state.currentPage) {
      setActiveNav(target);
      return;
    }

    if (push) location.hash = `#/${target}`;

    const renderId = Core.nextRenderId();
    setActiveNav(target);

    const renderer = availablePages[target] || (() => notImpl(target));
    try {
      await renderer();
    } catch (e) {
      renderError(target, e);
      console.error(e);
    } finally {
      if (Core.state.renderGeneration === renderId) {
        Core.state.currentPage = target;
        Core.rememberPage(target);
      }
    }
  }

  const bindNavClicks = () => {
    const side = document.getElementById('sidebarCollapse');
    if (side) {
      side.addEventListener('click', (ev) => {
        const a = ev.target.closest('a.nav-link[data-page]');
        if (!a) return;
        ev.preventDefault();
        const page = a.dataset.page;
        goToPage(page);
      });
    }

    const off = document.getElementById('sidebarOffcanvas');
    if (off) {
      off.addEventListener('click', (ev) => {
        const a = ev.target.closest('a.nav-link[data-page]');
        if (!a) return;
        ev.preventDefault();
        const page = a.dataset.page;
        goToPage(page);
        const bsOff = window.bootstrap?.Offcanvas?.getInstance?.(off);
        if (bsOff) bsOff.hide();
      });
    }

    document.addEventListener('click', (ev) => {
      const control = ev.target.closest('[data-go-page]');
      if (!control) return;
      const page = control.getAttribute('data-go-page');
      if (!page) return;
      ev.preventDefault();
      goToPage(page);
    });
  };

  window.addEventListener('hashchange', () => {
    const page = parseHash();
    goToPage(page, { push: false });
  });

  async function filterNavByRole() {
    const keep = (link) => {
      const page = link.getAttribute('data-page');
      if (!page) return false;
      if (hiddenPages && hiddenPages.has(page)) return false;
      if (allowedPages) return allowedPages.has(page);
      return true;
    };
    // Oculta (no elimina) para mantener estructura
    document.querySelectorAll('#sidebarCollapse .nav-link').forEach((a) => {
      if (!keep(a)) a.parentElement?.classList.add('d-none');
      else a.parentElement?.classList.remove('d-none');
    });
    document.querySelectorAll('#sidebarOffcanvas .nav-link').forEach((a) => {
      if (!keep(a)) a.parentElement?.classList.add('d-none');
      else a.parentElement?.classList.remove('d-none');
    });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    bindNavClicks();

    // Descubre usuario para ajustar navegación
    try {
      const me = await Core.fetchJSON(Core.api('auth/me'));
      const user = me?.user || me?.data?.user || null;
      if (user) {
        currentUser = user;
        Core.currentUser = user;
        const role = (user.role || '').toLowerCase();
        if (role === 'caseta') {
          // Caseta puede consumir datos de todos los mÃ³dulos, pero ocultamos el resto del sidebar
          allowedPages = null; // sin restricciÃ³n de rutas
          hiddenPages = new Set(['settings', 'reports', 'ManualInvoice']);
          filterNavByRole();
        }
      }
    } catch (_) { /* ignore */ }

    // Logout button
    const logoutBtn = document.getElementById('btnLogout');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', async (ev) => {
        ev.preventDefault();
        logoutBtn.disabled = true;
        try {
          await Core.fetchJSON(Core.api('auth/logout'), { method: 'POST' });
        } catch (_) { /* ignore errors */ }
        window.location.href = '/login.html';
      });
    }

    const stored = Core.restorePage();
    const firstNav = document.querySelector('.nav-link[data-page]');
    const initialCandidate = (stored && pages[stored]) ? stored : (firstNav?.dataset.page || 'dashboard');
    const initial = allowedPages && !allowedPages.has(initialCandidate)
      ? Array.from(allowedPages)[0] || 'dashboard'
      : initialCandidate;

    goToPage(initial, { force: true });
  });
})();
