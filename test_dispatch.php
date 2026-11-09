<?php
chdir('/home/u705937345/domains/keystoneadvisors.cl/public_html');
require 'api/LeadNotificationService.php';
require 'api/KeystoneSmtpClient.php';
require 'api/DeepShieldBecDossierPdfGenerator.php';
require 'api/DeepShieldBecForensicService.php';

$srv = new DeepShieldBecForensicService();
$res = $srv->dispatchDossierEmail(1, 'mvasquez@keystoneadvisors.cl');
echo "\nRESULTADO DISPATCH: " . json_encode($res, JSON_PRETTY_PRINT) . "\n";
