<?php

require_once 'domains/keystoneadvisors.cl/public_html/api/Database.php';
require_once 'domains/keystoneadvisors.cl/public_html/api/DeepShieldBecForensicService.php';
$db = Database::getInstance();
$svc = new DeepShieldBecForensicService($db);

$fakeHeaders = "From: Gerente General <ceo@empresa.cl>
Reply-To: desvio.pagos@hacker-server.ru
Return-Path: <bounce@hacker-server.ru>
Received: from unknown [185.220.101.5]
Authentication-Results: mx.empresa.cl; spf=fail; dkim=none; dmarc=fail";

$order = $db->query("SELECT id FROM deepshield_orders WHERE status = 'REJECTED' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($order) {
    $res = $svc->analyzeAndGenerateDossier((int)$order['id'], $fakeHeaders);
    echo json_encode(['success' => $res['success'], 'spoofing' => $res['analysis']['spoofing_detected'], 'pdf' => $res['pdf_path']]) . '
';
} else {
    echo 'No rejected order found
';
}
