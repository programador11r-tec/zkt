// utils/modals.js
(function () {
  const root = document.getElementById('modal-root');

  function trapFocus(container) {
    const focusables = container.querySelectorAll(
      'button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])'
    );
    const first = focusables[0], last = focusables[focusables.length - 1];
    function onKey(e) {
      if (e.key === 'Escape') closeTop();
      if (e.key === 'Tab') {
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault(); last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault(); first.focus();
        }
      }
    }
    container.addEventListener('keydown', onKey);
    return () => container.removeEventListener('keydown', onKey);
  }

  const stack = [];
  function closeTop() {
    const top = stack.pop();
    if (!top) return;
    top.cleanup && top.cleanup();
    top.el.remove();
    if (stack.length) stack[stack.length - 1].el.querySelector('.modal-card').focus();
  }

  function render({ title = 'Mensaje', html = '', variant = 'info', actions = [] } = {}) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');

    overlay.innerHTML = `
      <div class="modal-card" tabindex="-1">
        <div class="modal-head">
          <div class="modal-title">
            <span class="badge ${variant}">${variant.toUpperCase()}</span>
            <span style="margin-left:8px">${title}</span>
          </div>
          <button class="modal-close" aria-label="Cerrar">×</button>
        </div>
        <div class="modal-body">${html}</div>
        <div class="modal-foot"></div>
      </div>
    `;

    // Acciones
    const foot = overlay.querySelector('.modal-foot');
    (actions.length ? actions : [{ label: 'Aceptar', class: 'btn-primary' }]).forEach((a, i) => {
      const btn = document.createElement('button');
      btn.className = `btn ${a.class || 'btn-outline'}`;
      btn.textContent = a.label || 'Aceptar';
      btn.addEventListener('click', async () => {
        if (a.onClick) await a.onClick();
        closeTop();
      });
      if (a.dismiss === true) {
        btn.addEventListener('click', closeTop);
      }
      if (i === 0) btn.dataset.primary = '1';
      foot.appendChild(btn);
    });

    overlay.querySelector('.modal-close').addEventListener('click', closeTop);
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeTop(); // click afuera cierra
    });

    root.appendChild(overlay);
    const card = overlay.querySelector('.modal-card');
    const cleanupFocus = trapFocus(card);
    stack.push({ el: overlay, cleanup: cleanupFocus });

    // foco inicial
    setTimeout(() => {
      const primary = overlay.querySelector('[data-primary="1"]') || card;
      primary.focus();
    }, 0);

    return {
      close: closeTop,
      setBody(htmlStr) { overlay.querySelector('.modal-body').innerHTML = htmlStr; },
      setTitle(t) {
        const tEl = overlay.querySelector('.modal-title');
        tEl.lastElementChild.textContent = t;
      },
    };
  }
  // === Helpers “Dialog” encima de Modals ===
window.Dialog = {
  ok(message, title = 'Operación exitosa') {
    return Modals.show({
      title, variant: 'ok',
      html: `<p>${message}</p>`,
      actions: [{ label:'Aceptar', class:'btn-primary' }]
    });
  },
  err(error, title = 'Ocurrió un error') {
    const msg = (error && error.message) ? error.message : String(error ?? 'Error desconocido');
    return Modals.show({
      title, variant: 'danger',
      html: `<p>${msg}</p>`,
      actions: [{ label:'Entendido', class:'btn-primary' }]
    });
  },
  loading({ title = 'Procesando…', message = 'Por favor espera' } = {}) {
    // loader genérico (spinner)
    const m = Modals.show({
      title, variant:'info',
      html: `<div class="ticket-loader">
               <div class="spinner-border spinner-border-sm" aria-hidden="true"></div>
               <div>${message}</div>
             </div>`,
      actions: [] // sin botones
    });
    // marca tipo loading para estilos compactos
    m._el = document.querySelector('#modal-root .modal-overlay:last-child .modal-card');
    if (m._el) m._el.setAttribute('data-kind','loading');
    return m;
  },
  loadingTicket(title = 'Enviando…', message = 'Contactando G4S…') {
    // loader con animación de ticket
    const m = Modals.show({
      title, variant:'info',
      html: `<div class="ticket-loader">
               <div class="ticket-anim">
                 <div class="paper"></div>
                 <div class="printer"></div>
                 <div class="slot"></div>
               </div>
               <div>${message}</div>
             </div>`,
      actions: []
    });
    m._el = document.querySelector('#modal-root .modal-overlay:last-child .modal-card');
    if (m._el) m._el.setAttribute('data-kind','loading');
    return m;
  }
};

  // Helpers simples
  window.Modals = {
    show(opts) { return render(opts); },
    alert({ title = 'Aviso', message = '' , variant = 'info'} = {}) {
      return render({ title, variant, html: `<p>${message}</p>` });
    },
    confirm({ title = 'Confirmar', message = '', variant = 'warn', okText = 'Sí', cancelText = 'Cancelar' } = {}) {
      return new Promise((resolve) => {
        render({
          title, variant, html: `<p>${message}</p>`,
          actions: [
            { label: cancelText, class: 'btn-outline', onClick: () => resolve(false) },
            { label: okText, class: 'btn-primary', onClick: () => resolve(true) },
          ]
        });
      });
    },
    loading({ title = 'Procesando…', message = 'Por favor espera', style = 'spinner' } = {}) {
    // style: 'spinner' | 'ticket'
    const ticketHTML = `
        <div class="loader-row" aria-live="polite">
        <span class="ticket-loader" aria-hidden="true">
            <svg class="ticket-spinner" viewBox="0 0 64 40">
            <!-- Boleto con muescas -->
            <path class="outline"
                d="M6 8
                h44
                a4 4 0 0 0 4 -4
                a8 8 0 0 1 0 16
                a8 8 0 0 1 0 16
                a4 4 0 0 0 -4 -4
                H6
                a4 4 0 0 1 -4 -4
                a8 8 0 0 0 0 -16
                a4 4 0 0 1 4 -4 z"
            />
            <!-- Línea perforada -->
            <line class="perf" x1="24" y1="10" x2="24" y2="30" />
            <!-- Flecha de “avance/envío” -->
            <path class="arrow" d="M33 20 h18 M49 20 l-4 -4 M49 20 l-4 4" />
            </svg>
        </span>
        <div>
            <div style="font-weight:600; margin-bottom:2px">${title}</div>
            <div class="text-muted">${message}</div>
        </div>
        </div>`;

    const spinnerHTML = `
        <p style="display:flex;align-items:center;gap:10px">
        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>${message}
        </p>`;

    return render({
        title, variant: 'info',
        html: style === 'ticket' ? ticketHTML : spinnerHTML,
        actions: [] // sin botones
    });
    },
    error(err, title = 'Error') {
      const msg = (err && err.message) ? err.message : String(err ?? 'Ocurrió un error');
      return render({ title, variant:'danger', html: `<p>${msg}</p>` });
    }
  };
})();
