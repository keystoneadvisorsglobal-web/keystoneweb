/**
 * Keystone Growth Tracker v1.0
 * Script ligero de telemetría de intención de compra (Pre-Purchase Intent) y atribución multicanal.
 * Captura UTMs, GCLID, FBCLID, LI_FAT_ID, dwell-time en pricing, scroll depth y balizas automáticas.
 */
(function(window, document) {
    'use strict';

    var ENDPOINT = 'https://www.keystoneadvisors.cl/api/marketing/track.php';
    var COOKIE_NAME = 'ks_growth_uid';
    var TENANT_ID = window.__KS_GROWTH_TENANT || 'keystone'; // default

    // Generar o recuperar UUID
    function getOrCreateUid() {
        var uid = null;
        try {
            uid = localStorage.getItem(COOKIE_NAME);
        } catch(e) {}
        
        if (!uid) {
            var match = document.cookie.match(new RegExp('(^| )' + COOKIE_NAME + '=([^;]+)'));
            if (match) uid = match[2];
        }

        if (!uid) {
            uid = 'ks_' + Math.random().toString(36).substring(2, 12) + Date.now().toString(36);
            try { localStorage.setItem(COOKIE_NAME, uid); } catch(e) {}
            var d = new Date();
            d.setTime(d.getTime() + (90 * 24 * 60 * 60 * 1000)); // 90 días
            document.cookie = COOKIE_NAME + '=' + uid + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
        }
        return uid;
    }

    var uid = getOrCreateUid();

    // Extraer parámetros de URL
    function getQueryParams() {
        var params = {};
        var search = window.location.search.substring(1);
        if (search) {
            var pairs = search.split('&');
            for (var i = 0; i < pairs.length; i++) {
                var p = pairs[i].split('=');
                if (p.length === 2) {
                    params[decodeURIComponent(p[0])] = decodeURIComponent(p[1].replace(/\+/g, ' '));
                }
            }
        }
        return params;
    }

    var q = getQueryParams();

    // Enviar beacon o fetch
    function sendPayload(action, payload) {
        payload.action = action;
        payload.anon_id = uid;
        payload.tenant_id = TENANT_ID;
        payload.landing_url = window.location.href;
        payload.referrer = document.referrer || '';

        var jsonStr = JSON.stringify(payload);
        if (navigator.sendBeacon) {
            var blob = new Blob([jsonStr], { type: 'application/json' });
            navigator.sendBeacon(ENDPOINT + '?action=' + action, blob);
        } else {
            fetch(ENDPOINT + '?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: jsonStr,
                keepalive: true
            }).catch(function() {});
        }
    }

    // 1. Registrar Touchpoint de Entrada
    var touchpointSent = false;
    function recordInitialTouchpoint() {
        if (touchpointSent) return;
        touchpointSent = true;

        var touchData = {
            utm_source: q.utm_source || '',
            utm_medium: q.utm_medium || '',
            utm_campaign: q.utm_campaign || '',
            utm_content: q.utm_content || '',
            utm_term: q.utm_term || '',
            gclid: q.gclid || '',
            fbclid: q.fbclid || '',
            li_fat_id: q.li_fat_id || ''
        };

        // Si no hay UTMs pero hay referrer externo
        if (!touchData.utm_source && document.referrer) {
            var refHost = '';
            try { refHost = (new URL(document.referrer)).hostname; } catch(e) {}
            if (refHost && refHost !== window.location.hostname) {
                if (refHost.indexOf('google.') > -1) {
                    touchData.utm_source = 'google';
                    touchData.utm_medium = 'organic';
                } else if (refHost.indexOf('linkedin.') > -1) {
                    touchData.utm_source = 'linkedin';
                    touchData.utm_medium = 'social';
                } else if (refHost.indexOf('facebook.') > -1 || refHost.indexOf('instagram.') > -1) {
                    touchData.utm_source = 'meta';
                    touchData.utm_medium = 'social';
                } else {
                    touchData.utm_source = refHost;
                    touchData.utm_medium = 'referral';
                }
            }
        }

        sendPayload('touchpoint', touchData);
    }

    // 2. Telemetría de Intención de Compra (Pre-Purchase Intent)
    var maxScroll = 0;
    var pricingDwellSeconds = 0;
    var pricingInterval = null;
    var isPricingVisible = false;
    var interactionCount = 0;
    var intentLogged = false;

    // Scroll depth
    function checkScroll() {
        var h = document.documentElement, 
            b = document.body,
            st = 'scrollTop',
            sh = 'scrollHeight';
        var percent = Math.round((h[st]||b[st]) / ((h[sh]||b[sh]) - h.clientHeight) * 100);
        if (percent > maxScroll) maxScroll = percent;
    }
    window.addEventListener('scroll', checkScroll, { passive: true });

    // Observar sección de precios o planes si existe
    function observePricing() {
        var pricingEl = document.querySelector('#pricing, #precios, #planes, .pricing-section, .pricing-card');
        if (!pricingEl) return;

        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        if (!pricingInterval) {
                            pricingInterval = setInterval(function() {
                                pricingDwellSeconds++;
                            }, 1000);
                        }
                    } else {
                        if (pricingInterval) {
                            clearInterval(pricingInterval);
                            pricingInterval = null;
                        }
                    }
                });
            }, { threshold: 0.3 });
            observer.observe(pricingEl);
        }
    }

    // Clicks en CTAs de cotización o contacto
    document.addEventListener('click', function(e) {
        var target = e.target.closest('a, button');
        if (!target) return;
        var href = target.getAttribute('href') || '';
        var text = (target.innerText || '').toLowerCase();
        
        if (href.indexOf('#contact') > -1 || href.indexOf('cotizar') > -1 || text.indexOf('cotizar') > -1 || text.indexOf('agendar') > -1 || text.indexOf('comenzar') > -1) {
            interactionCount++;
            // Enviar pulso de intención
            sendPayload('intent', {
                dwell_pricing_seconds: pricingDwellSeconds,
                scroll_depth_pct: maxScroll,
                cta_clicks_count: interactionCount,
                event_type: 'cta_click'
            });
        }
    }, true);

    // Enviar resumen de intención al salir o cambiar de pestaña
    function sendExitIntent() {
        if (intentLogged) return;
        intentLogged = true;
        sendPayload('intent', {
            dwell_pricing_seconds: pricingDwellSeconds,
            scroll_depth_pct: maxScroll,
            cta_clicks_count: interactionCount,
            event_type: 'page_exit'
        });
    }

    window.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') {
            sendExitIntent();
        }
    });
    window.addEventListener('beforeunload', sendExitIntent);

    // Inicializar
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            recordInitialTouchpoint();
            observePricing();
        });
    } else {
        recordInitialTouchpoint();
        observePricing();
    }

    // Exponer API pública global para registrar conversiones desde formularios
    window.KeystoneGrowth = {
        getUid: function() { return uid; },
        trackConversion: function(conversionType, value, contactEmail, leadId) {
            sendPayload('conversion', {
                conversion_type: conversionType || 'lead',
                conversion_value: value || 0.0,
                contact_email: contactEmail || '',
                lead_id: leadId || null
            });
        }
    };

})(window, document);

