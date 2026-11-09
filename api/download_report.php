<?php
/**
 * Descargador seguro de reportes generados - KeyGuard
 */
$file = isset($_GET['file']) ? basename($_GET['file']) : '';

if (empty($file) || !preg_match('/^Reporte_Ciberriesgo_[a-zA-Z0-9_-]+\.pdf$/', $file)) {
    http_response_code(400);
    die('Solicitud de reporte inválida');
}

$path = __DIR__ . '/../data/reports/' . $file;

if (!file_exists($path)) {
    http_response_code(404);
    die('Reporte no encontrado o expirado');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
