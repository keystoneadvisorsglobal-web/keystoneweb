<?php
/**
 * Cliente SMTP Socket Autenticado Nativo en PHP Puro (Sin dependencias externas)
 * Soporta SSL (Puerto 465) y TLS (Puerto 587) con autenticación AUTH LOGIN
 */

class KeystoneSmtpClient {
    private $host;
    private $port;
    private $username;
    private $password;
    private $fromEmail;
    private $fromName;
    private $timeout;
    private $lastLog = [];

    public function __construct($config = []) {
        $this->host = $config['smtp_host'] ?? 'smtp.titan.email';
        $this->port = (int)($config['smtp_port'] ?? 465);
        $this->username = $config['smtp_user'] ?? 'contacto@keystoneadvisors.cl';
        $this->password = $config['smtp_pass'] ?? '';
        $this->fromEmail = $config['smtp_from'] ?? $this->username;
        $this->fromName = $config['smtp_name'] ?? 'Keystone Advisors';
        $this->timeout = 10;
    }

    public function getLog() {
        return implode("\n", $this->lastLog);
    }

    private function log($msg) {
        $this->lastLog[] = $msg;
    }

    /**
     * Enviar correo con soporte de HTML y adjuntos (PDF, etc.)
     */
    public function send($toEmail, $subject, $htmlBody, $attachmentPath = '', $attachmentName = '') {
        $this->lastLog = [];
        $toEmail = trim($toEmail);

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $this->log("Error: Destinatario inválido ({$toEmail})");
            return false;
        }

        // Determinar protocolo de transporte
        $socketHost = $this->host;
        if ($this->port === 465) {
            $socketHost = 'ssl://' . $this->host;
        }

        $this->log("Conectando a {$socketHost}:{$this->port}...");
        $socket = @fsockopen($socketHost, $this->port, $errno, $errstr, $this->timeout);

        if (!$socket) {
            $this->log("Fallo socket: {$errstr} ({$errno})");
            return false;
        }

        stream_set_timeout($socket, $this->timeout);
        $res = $this->readResponse($socket);
        if (!$this->checkCode($res, '220')) {
            fclose($socket);
            return false;
        }

        // Saludo EHLO
        $this->writeCommand($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'keystoneadvisors.cl'));
        $res = $this->readResponse($socket);
        if (!$this->checkCode($res, '250')) {
            fclose($socket);
            return false;
        }

        // STARTTLS si es puerto 587
        if ($this->port === 587) {
            $this->writeCommand($socket, "STARTTLS");
            $res = $this->readResponse($socket);
            if (!$this->checkCode($res, '220')) {
                fclose($socket);
                return false;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->log("Fallo al establecer TLS");
                fclose($socket);
                return false;
            }
            $this->writeCommand($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'keystoneadvisors.cl'));
            $res = $this->readResponse($socket);
        }

        // Autenticación AUTH LOGIN
        if (!empty($this->username) && !empty($this->password)) {
            $this->writeCommand($socket, "AUTH LOGIN");
            $res = $this->readResponse($socket);
            if (!$this->checkCode($res, '334')) {
                fclose($socket);
                return false;
            }

            $this->writeCommand($socket, base64_encode($this->username));
            $res = $this->readResponse($socket);
            if (!$this->checkCode($res, '334')) {
                fclose($socket);
                return false;
            }

            $this->writeCommand($socket, base64_encode($this->password));
            $res = $this->readResponse($socket);
            if (!$this->checkCode($res, '235')) {
                $this->log("Error en autenticación SMTP. Verifique usuario o contraseña.");
                fclose($socket);
                return false;
            }
        }

        // MAIL FROM
        $this->writeCommand($socket, "MAIL FROM:<{$this->fromEmail}>");
        $res = $this->readResponse($socket);
        if (!$this->checkCode($res, '250')) {
            fclose($socket);
            return false;
        }

        // RCPT TO
        $this->writeCommand($socket, "RCPT TO:<{$toEmail}>");
        $res = $this->readResponse($socket);
        if (!$this->checkCode($res, '250') && !$this->checkCode($res, '251')) {
            fclose($socket);
            return false;
        }

        // DATA
        $this->writeCommand($socket, "DATA");
        $res = $this->readResponse($socket);
        if (!$this->checkCode($res, '354')) {
            fclose($socket);
            return false;
        }

        // Construcción del mensaje MIME
        $boundary = "----=_KeyGuardPart_" . md5(uniqid(time()));
        $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";

        $msg = "From: =?UTF-8?B?" . base64_encode($this->fromName) . "?= <{$this->fromEmail}>\r\n";
        $msg .= "To: <{$toEmail}>\r\n";
        $msg .= "Subject: {$encodedSubject}\r\n";
        $msg .= "Date: " . date('r') . "\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Message-ID: <" . md5(uniqid(time())) . "@" . ($_SERVER['SERVER_NAME'] ?? 'keystoneadvisors.cl') . ">\r\n";
        $msg .= "X-Mailer: Keystone-KeyGuard-Mailer/2.0\r\n";
        $msg .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";

        // Cuerpo HTML
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $msg .= $htmlBody . "\r\n\r\n";

        // Adjunto si existe
        if (!empty($attachmentPath) && file_exists($attachmentPath)) {
            $attName = !empty($attachmentName) ? $attachmentName : basename($attachmentPath);
            $content = file_get_contents($attachmentPath);
            $msg .= "--{$boundary}\r\n";
            $msg .= "Content-Type: application/pdf; name=\"{$attName}\"\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\n";
            $msg .= "Content-Disposition: attachment; filename=\"{$attName}\"\r\n\r\n";
            $msg .= chunk_split(base64_encode($content)) . "\r\n\r\n";
        }

        $msg .= "--{$boundary}--\r\n";
        $msg .= ".\r\n"; // Fin de mensaje SMTP

        $this->writeCommand($socket, $msg, false);
        $res = $this->readResponse($socket);

        $success = $this->checkCode($res, '250');
        if ($success) {
            $this->log("Correo despachado con éxito vía SMTP Titan.");
        } else {
            $this->log("Fallo al entregar mensaje DATA: {$res}");
        }

        // Cierre
        $this->writeCommand($socket, "QUIT");
        fclose($socket);

        return $success;
    }

    private function writeCommand($socket, $cmd, $appendCrlf = true) {
        $data = $appendCrlf ? $cmd . "\r\n" : $cmd;
        fwrite($socket, $data);
    }

    private function readResponse($socket) {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        $this->log("SMTP: " . trim($response));
        return $response;
    }

    private function checkCode($response, $expectedCode) {
        return (substr($response, 0, 3) === $expectedCode);
    }
}
