<?php
/**
 * Badge Dinámico SVG en Tiempo Real - TrustZero / KeyGuard
 * Entrega un sello gráfico SVG embebible en cualquier sitio web:
 * <img src="https://www.keystoneadvisors.cl/api/trust/badge.php?domain=keystoneadvisors.cl" alt="TrustZero Verified">
 */

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=600'); // 10 min de caché en navegador
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/TrustZeroTelemetryService.php';

$domain = isset($_GET['domain']) ? trim($_GET['domain']) : 'keystoneadvisors.cl';
$posture = TrustZeroTelemetryService::getSecurityPosture($domain);

$score = $posture['trust_score'] ?? 95;
$badgeColor = ($score >= 85) ? '#10b981' : '#0284c7';
$statusText = ($score >= 85) ? 'VERIFIED' : 'MONITORED';

echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="235" height="34" viewBox="0 0 235 34" fill="none">
  <defs>
    <linearGradient id="bgGrad" x1="0" y1="0" x2="235" y2="34" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#001730"/>
      <stop offset="100%" stop-color="#0F2A4A"/>
    </linearGradient>
    <linearGradient id="shieldGrad" x1="0" y1="0" x2="16" y2="18" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#00b4d8"/>
      <stop offset="100%" stop-color="{$badgeColor}"/>
    </linearGradient>
  </defs>

  <!-- Contenedor con borde elegante -->
  <rect x="0.5" y="0.5" width="234" height="33" rx="6.5" fill="url(#bgGrad)" stroke="#1e3a5f"/>

  <!-- Icono de Escudo de Seguridad -->
  <g transform="translate(10, 8)">
    <path d="M8 1L2 3.66667V8.11111C2 11.7944 4.55556 15.2267 8 16C11.4444 15.2267 14 11.7944 14 8.11111V3.66667L8 1Z" 
          fill="url(#shieldGrad)" stroke="#ffffff" stroke-width="0.8"/>
    <!-- Checkmark interno -->
    <path d="M5.5 8.2L7.2 9.9L10.5 6.6" stroke="#ffffff" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
  </g>

  <!-- Separador vertical -->
  <line x1="33" y1="7" x2="33" y2="27" stroke="#334e68" stroke-width="1"/>

  <!-- Textos -->
  <text x="41" y="15" fill="#94a3b8" font-family="Arial, sans-serif" font-size="8.5" font-weight="700" letter-spacing="0.5">KEYSTONE TRUSTZERO</text>
  <text x="41" y="26" fill="#ffffff" font-family="Arial, sans-serif" font-size="10.5" font-weight="700">Security Posture</text>

  <!-- Píldora de Estado -->
  <g transform="translate(160, 7)">
    <rect width="65" height="20" rx="4" fill="{$badgeColor}" fill-opacity="0.18" stroke="{$badgeColor}" stroke-width="0.8"/>
    <circle cx="9" cy="10" r="3" fill="{$badgeColor}"/>
    <text x="17" y="13.5" fill="{$badgeColor}" font-family="Arial, sans-serif" font-size="8.5" font-weight="800" letter-spacing="0.4">{$statusText}</text>
  </g>
</svg>
SVG;
exit;

