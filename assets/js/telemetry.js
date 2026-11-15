/**
 * Keystone Advisors - Telemetría Forense Ligera Universal v2.0
 * Captura rutas de navegación, tiempo en página, referrers y clicks CTA
 */
(function() {
  'use strict';

  // 1. Identificador Persistente de Sesión
  let sessionId = localStorage.getItem('ks_session_id');
  if (!sessionId) {
    sessionId = 'sess_' + Math.random().toString(36).substring(2, 10) + '_' + Date.now().toString(36);
    localStorage.setItem('ks_session_id', sessionId);
  }

  // 2. Despachador de Eventos Asíncrono
  function sendTelemetry(eventName, payload) {
    const body = Object.assign({
      session_id: sessionId,
      event: eventName,
      page_url: window.location.pathname + window.location.search + window.location.hash,
      page_title: document.title,
      referrer: document.referrer || null,
      screen_res: window.screen.width + 'x' + window.screen.height
    }, payload || {});

    // Preferir sendBeacon para garantizar despacho antes de descargar la página
    if (navigator.sendBeacon) {
      const blob = new Blob([JSON.stringify(body)], { type: 'application/json' });
      navigator.sendBeacon('/api/track.php', blob);
    } else {
      fetch('/api/track.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        keepalive: true
      }).catch(function() {});
    }
  }

  // 3. Registrar Vista de Página Inicial
  const startTime = Date.now();
  sendTelemetry('page_view', {
    section: window.location.pathname
  });

  // 4. Medir Tiempo de Permanencia al salir o cambiar de pestaña
  function reportTimeSpent() {
    const elapsedSeconds = Math.round((Date.now() - startTime) / 1000);
    if (elapsedSeconds >= 2) {
      sendTelemetry('time_spent', {
        duration_seconds: elapsedSeconds,
        section: window.location.pathname
      });
    }
  }

  window.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') {
      reportTimeSpent();
    }
  });
  window.addEventListener('beforeunload', reportTimeSpent);

  // 5. Capturar Clics en Enlaces y Botones Clave (CTAs, WhatsApp, Scanner, Menú)
  document.addEventListener('click', function(e) {
    const target = e.target.closest('a, button, .btn, .cta-button, [role="button"]');
    if (!target) return;

    const href = target.getAttribute('href') || '';
    const text = (target.innerText || target.getAttribute('title') || target.getAttribute('aria-label') || '').trim().substring(0, 80);

    // Detección de CTAs de Alto Valor
    let ctaType = null;
    if (href.indexOf('wa.me') !== -1 || href.indexOf('whatsapp') !== -1) {
      ctaType = 'whatsapp_click';
    } else if (href.indexOf('ciberseguridad-bec') !== -1 || text.toLowerCase().indexOf('escan') !== -1) {
      ctaType = 'scanner_click';
    } else if (href.indexOf('#contacto') !== -1 || href.indexOf('mailto:') !== -1 || text.toLowerCase().indexOf('asesor') !== -1 || text.toLowerCase().indexOf('contact') !== -1) {
      ctaType = 'contact_click';
    } else if (href.indexOf('salesforce-fsc') !== -1 || href.indexOf('keyhub-saas') !== -1 || href.indexOf('desarrollo-offshore') !== -1) {
      ctaType = 'navigation_landing_click';
    }

    if (ctaType) {
      sendTelemetry('cta_click', {
        cta_type: ctaType,
        cta_text: text,
        cta_target: href,
        section: window.location.pathname
      });
    }
  }, { passive: true });

})();
