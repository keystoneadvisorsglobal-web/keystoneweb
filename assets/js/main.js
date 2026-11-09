// State & Modal handlers
document.addEventListener('DOMContentLoaded', () => {
  // Generador de ID de sesión anónima para telemetría
  let sessionId = localStorage.getItem('ks_session_id');
  if (!sessionId) {
    sessionId = 'sess_' + Math.random().toString(36).substring(2, 15) + Date.now().toString(36);
    localStorage.setItem('ks_session_id', sessionId);
  }

  // Función asíncrona de telemetría de navegación y embudo
  const trackEvent = (eventName, extra = {}) => {
    try {
      fetch('api/track.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          session_id: sessionId,
          event: eventName,
          referrer: document.referrer || null,
          ...extra
        })
      }).catch(() => {});
    } catch (e) {}
  };

  // Registrar vista de página inicial
  trackEvent('page_view', { section: window.location.hash || 'inicio' });

  const modal = document.getElementById('checkoutModal');
  const modalClose = document.getElementById('modalClose');
  const modalTitle = document.getElementById('modalTitle');
  const modalSubtitle = document.getElementById('modalSubtitle');
  const productIdInput = document.getElementById('productId');
  const productNameInput = document.getElementById('productName');
  const formTypeInput = document.getElementById('formType');
  const requestForm = document.getElementById('requestForm');
  const formAlert = document.getElementById('formAlert');
  const btnSubmit = document.getElementById('btnSubmit');

  // Open modal for Product Purchase / Checkout
  document.querySelectorAll('.btn-buy-product').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const pId = btn.dataset.productId;
      const pName = btn.dataset.productName;
      const pPrice = btn.dataset.productPrice;

      // Telemetría: Registro de intención de compra
      trackEvent('modal_open', {
        product_id: pId,
        product_name: pName,
        section: 'planes_checkout'
      });

      modalTitle.textContent = `Contratar ${pName}`;
      modalSubtitle.textContent = `Precio de referencia: ${pPrice}. Ingrese sus datos para emitir la orden formal y coordinar inicio.`;
      productIdInput.value = pId;
      productNameInput.value = pName;
      formTypeInput.value = 'checkout';
      btnSubmit.innerHTML = `<span>Proceder a la Orden</span> ➜`;

      formAlert.style.display = 'none';
      modal.classList.add('active');
    });
  });

  // Open modal for General Contact / Advisory
  document.querySelectorAll('.btn-contact-open').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();

      // Telemetría: Apertura de contacto consultivo
      trackEvent('modal_open', {
        section: 'contacto_general'
      });

      modalTitle.textContent = 'Hablemos de su Proyecto';
      modalSubtitle.textContent = 'Un especialista senior de Keystone Advisors lo contactará en menos de 24 horas.';
      productIdInput.value = '';
      productNameInput.value = '';
      formTypeInput.value = 'lead';
      btnSubmit.innerHTML = `<span>Enviar Mensaje</span> ➜`;

      formAlert.style.display = 'none';
      modal.classList.add('active');
    });
  });

  // Close modal
  if (modalClose) {
    modalClose.addEventListener('click', () => {
      modal.classList.remove('active');
    });
  }

  window.addEventListener('click', (e) => {
    if (e.target === modal) {
      modal.classList.remove('active');
    }
  });

  // Form Submission
  if (requestForm) {
    requestForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      const payload = {
        type: formTypeInput.value,
        name: document.getElementById('clientName').value.trim(),
        email: document.getElementById('clientEmail').value.trim(),
        phone: document.getElementById('clientPhone').value.trim(),
        company: document.getElementById('clientCompany').value.trim(),
        country: document.getElementById('clientCountry').value,
        service: document.getElementById('clientService').value,
        product_id: productIdInput.value || null,
        product_name: productNameInput.value || null,
        message: document.getElementById('clientMessage').value.trim()
      };

      btnSubmit.disabled = true;
      btnSubmit.innerHTML = '<span>Procesando...</span> ⏳';

      try {
        const res = await fetch('api/contact.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (data.success) {
          formAlert.style.display = 'block';
          formAlert.className = 'alert-box alert-success';
          formAlert.innerHTML = `<strong>¡Excelente!</strong> ${data.message} <br><small>Referencia de gestión: <strong>${data.order_ref}</strong></small>`;
          requestForm.reset();
          setTimeout(() => {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = `<span>Listo</span> ✓`;
          }, 1500);
        } else {
          throw new Error(data.error || 'Ocurrió un error al procesar la solicitud.');
        }
      } catch (err) {
        formAlert.style.display = 'block';
        formAlert.className = 'alert-box alert-error';
        formAlert.innerHTML = `<strong>Error:</strong> ${err.message}`;
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = `<span>Reintentar</span> ➜`;
      }
    });
  }

  // Quick Direct Contact Form (Sección Contacto)
  const quickContactForm = document.getElementById('quickContactForm');
  const quickContactAlert = document.getElementById('quickContactAlert');
  const btnQcSubmit = document.getElementById('btnQcSubmit');

  if (quickContactForm) {
    quickContactForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      const payload = {
        type: 'contact_direct',
        name: document.getElementById('qcName').value.trim(),
        email: document.getElementById('qcEmail').value.trim(),
        phone: document.getElementById('qcPhone').value.trim(),
        company: document.getElementById('qcCompany').value.trim(),
        service: document.getElementById('qcService').value,
        message: document.getElementById('qcMessage').value.trim(),
        country: 'Chile'
      };

      btnQcSubmit.disabled = true;
      btnQcSubmit.innerHTML = '<span>Enviando mensaje...</span> ⏳';

      // Telemetría
      trackEvent('form_submit_contact', {
        section: 'contacto_directo',
        service: payload.service
      });

      try {
        const res = await fetch('api/contact.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (data.success) {
          quickContactAlert.style.display = 'block';
          quickContactAlert.className = 'alert-box alert-success';
          quickContactAlert.innerHTML = `<strong>¡Mensaje Enviado con Éxito!</strong> ${data.message} <br><small>Ticket de seguimiento: <strong>${data.order_ref}</strong></small>`;
          quickContactForm.reset();
          btnQcSubmit.disabled = false;
          btnQcSubmit.innerHTML = `<span>Mensaje Recibido</span> ✓`;
          setTimeout(() => {
            btnQcSubmit.innerHTML = `<span>Enviar Otro Mensaje</span> ➜`;
          }, 3000);
        } else {
          throw new Error(data.error || 'Error enviando el mensaje.');
        }
      } catch (err) {
        quickContactAlert.style.display = 'block';
        quickContactAlert.className = 'alert-box alert-error';
        quickContactAlert.innerHTML = `<strong>Error:</strong> ${err.message}`;
        btnQcSubmit.disabled = false;
        btnQcSubmit.innerHTML = `<span>Reintentar Envío</span> ➜`;
      }
    });
  }

  // Interactive Tabs System for Services
  const tabButtons = document.querySelectorAll('.tab-btn');
  const tabPanes = document.querySelectorAll('.tab-pane');

  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const targetTab = btn.dataset.tab;

      tabButtons.forEach(b => b.classList.remove('active'));
      tabPanes.forEach(p => p.classList.remove('active'));

      btn.classList.add('active');
      const targetPane = document.getElementById(targetTab);
      if (targetPane) {
        targetPane.classList.add('active');
      }
    });
  });

  // Free Cyber Exposure Scanner Handler (B2B Lead Magnet)
  const scannerForm = document.getElementById('cyberScannerForm');
  const scannerResults = document.getElementById('scannerResults');
  const scannerScoreVal = document.getElementById('scannerScoreVal');
  const scannerScoreText = document.getElementById('scannerScoreText');
  const scannerDomainDisplay = document.getElementById('scannerDomainDisplay');
  const scannerFindingsList = document.getElementById('scannerFindingsList');
  const btnRunScan = document.getElementById('btnRunScan');

  if (scannerForm) {
    scannerForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const domainInput = document.getElementById('scanDomain').value.trim();
      const emailInput = document.getElementById('scanEmail').value.trim();

      if (!domainInput || !emailInput) return;

      // Limpieza preventiva de resultados previos para evitar duplicación visual
      const prevPdfContainer = document.getElementById('scannerPdfNoticeContainer');
      if (prevPdfContainer) prevPdfContainer.innerHTML = '';
      if (scannerFindingsList) scannerFindingsList.innerHTML = '';
      if (scannerResults) scannerResults.classList.remove('active');

      btnRunScan.disabled = true;
      btnRunScan.innerHTML = '<span>Analizando registros DNS & SSL...</span> ⏳';

      try {
        const res = await fetch('api/scan.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ domain: domainInput, email: emailInput })
        });

        const data = await res.json();
        if (data.success) {
          scannerDomainDisplay.textContent = data.domain;
          scannerScoreVal.textContent = data.score;
          scannerScoreVal.style.color = data.risk_color;
          scannerScoreText.textContent = `Nivel de Exposición: ${data.risk_level.toUpperCase()} (${data.recommendations_count} brechas detectadas)`;
          scannerScoreText.style.color = data.risk_color;

          // Render findings
          let html = '';
          if (data.findings && data.findings.length > 0) {
            data.findings.forEach(f => {
              const itemClass = f.severity === 'critical' ? 'critical' : (f.severity === 'warning' ? 'warning' : '');
              html += `
                <div class="scanner-finding-item ${itemClass}">
                  <div class="scanner-finding-title">⚠️ ${f.title}</div>
                  <div class="scanner-finding-desc">${f.desc}</div>
                </div>
              `;
            });
          } else {
            html = `
              <div class="scanner-finding-item" style="border-left-color:#10b981; background:rgba(16,185,129,0.08);">
                <div class="scanner-finding-title" style="color:#34d399;">✓ Parámetros Esenciales Alineados</div>
                <div class="scanner-finding-desc">No se encontraron vulnerabilidades pasivas superficiales en DMARC ni SSL. Se recomienda avanzar a un Red Teaming asistido por IA para evaluar resiliencia de empleados.</div>
              </div>
            `;
          }
          scannerFindingsList.innerHTML = html;

          // Reset y renderizado limpio del contenedor de PDF (sin duplicaciones)
          const pdfContainer = document.getElementById('scannerPdfNoticeContainer');
          if (pdfContainer) {
            pdfContainer.innerHTML = ''; // Limpiar escaneos anteriores
            if (data.pdf_generated && data.pdf_url) {
              pdfContainer.innerHTML = `
                <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 8px; padding: 0.9rem 1.1rem; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
                  <div style="display:flex; align-items:center; gap:0.6rem;">
                    <span style="font-size:1.3rem;">📄</span>
                    <div>
                      <div style="font-size:0.88rem; font-weight:700; color:#10b981;">Reporte Ejecutivo Generado (Folio: ${data.pdf_folio || 'KG-EXECUTIVE'})</div>
                      <div style="font-size:0.78rem; color:#cbd5e1;">Enviado a ${data.email} y disponible para descarga inmediata.</div>
                    </div>
                  </div>
                  <a href="${data.pdf_url}" target="_blank" class="btn btn-sm" style="background:#10b981; color:#ffffff; padding:0.45rem 0.9rem; font-size:0.82rem; border-radius:6px; text-decoration:none; display:inline-flex; align-items:center; gap:0.35rem;">
                    <span>Descargar PDF</span> ⬇
                  </a>
                </div>
              `;
            }
          }

          scannerResults.classList.add('active');

          // Scroll smoothly to results
          scannerResults.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

          btnRunScan.disabled = false;
          btnRunScan.innerHTML = '<span>Escanear Otro Dominio</span> 🔍';
        } else {
          throw new Error(data.error || 'No fue posible completar el análisis.');
        }
      } catch (err) {
        alert('Error al escanear: ' + err.message);
        btnRunScan.disabled = false;
        btnRunScan.innerHTML = '<span>Reintentar Escaneo</span> ➜';
      }
    });
  }
});
