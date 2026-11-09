<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once 'domains/keystoneadvisors.cl/public_html/api/LeadNotificationService.php';
require_once 'domains/keystoneadvisors.cl/public_html/api/KeystoneSmtpClient.php';

$cfg = LeadNotificationService::getSmtpConfig();
echo 'SMTP Host: ' . $cfg['smtp_host'] . ' | Port: ' . $cfg['smtp_port'] . ' | User: ' . $cfg['smtp_user'] . ' | Pass length: ' . strlen($cfg['smtp_pass']) . '
';

$client = new KeystoneSmtpClient($cfg['smtp_host'], (int)$cfg['smtp_port'], $cfg['smtp_user'], $cfg['smtp_pass'], $cfg['smtp_from'], $cfg['smtp_name']);
$res = $client->testConnection('mvasquez@keystoneadvisors.cl');
echo 'Test result: ' . json_encode($res) . '
';
