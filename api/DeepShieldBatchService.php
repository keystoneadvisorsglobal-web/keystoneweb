<?php
/**
 * Keystone DeepShield - Gestor de Padrón de Proveedores Verificados (Whitelist)
 * y Motor de Ingesta Masiva de Nóminas Bancarias (Excel / CSV)
 */

class DeepShieldBatchService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->initSchema();
    }

    private function initSchema(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS deepshield_trusted_beneficiaries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_slug TEXT NOT NULL,
            rut_dni TEXT NOT NULL,
            business_name TEXT NOT NULL,
            bank_name TEXT NOT NULL,
            account_number TEXT NOT NULL,
            account_type TEXT DEFAULT 'Cuenta Corriente',
            contact_email TEXT,
            status TEXT DEFAULT 'VERIFIED',
            verified_by TEXT DEFAULT 'Tesorería',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(tenant_slug, rut_dni, account_number)
        )");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dstb_tenant_rut ON deepshield_trusted_beneficiaries(tenant_slug, rut_dni)");
    }

    /**
     * Normalizar RUT Chileno (quitar puntos, guiones y mayúscula en K)
     */
    public static function cleanRut(string $rut): string {
        $clean = strtoupper(preg_replace('/[^0-9kK]/', '', $rut));
        if (strlen($clean) > 1) {
            $num = substr($clean, 0, -1);
            $dv = substr($clean, -1);
            return number_format((float)$num, 0, '', '.') . '-' . $dv;
        }
        return $clean;
    }

    /**
     * Normalizar número de cuenta (eliminar espacios y guiones)
     */
    public static function cleanAccount(string $account): string {
        return preg_replace('/[^0-9a-zA-Z]/', '', $account);
    }

    /**
     * Registrar o Actualizar Proveedor en el Padrón Verificado
     */
    public function saveBeneficiary(string $tenantSlug, array $data, string $operator = 'Tesorería'): array {
        $rut = self::cleanRut($data['rut_dni'] ?? '');
        $name = trim($data['business_name'] ?? '');
        $bank = trim($data['bank_name'] ?? '');
        $account = self::cleanAccount($data['account_number'] ?? '');
        $accType = trim($data['account_type'] ?? 'Cuenta Corriente');
        $email = trim($data['contact_email'] ?? '');

        if (empty($rut) || empty($name) || empty($bank) || empty($account)) {
            return ['success' => false, 'error' => 'RUT, Razón Social, Banco y Cuenta son obligatorios.'];
        }

        $stmt = $this->db->prepare("SELECT id FROM deepshield_trusted_beneficiaries WHERE tenant_slug = ? AND rut_dni = ? AND account_number = ? LIMIT 1");
        $stmt->execute([$tenantSlug, $rut, $account]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $upd = $this->db->prepare("UPDATE deepshield_trusted_beneficiaries SET business_name = ?, bank_name = ?, account_type = ?, contact_email = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$name, $bank, $accType, $email, $existing['id']]);
            return ['success' => true, 'action' => 'updated', 'id' => $existing['id']];
        } else {
            $ins = $this->db->prepare("INSERT INTO deepshield_trusted_beneficiaries (tenant_slug, rut_dni, business_name, bank_name, account_number, account_type, contact_email, verified_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$tenantSlug, $rut, $name, $bank, $account, $accType, $email, $operator]);
            return ['success' => true, 'action' => 'created', 'id' => $this->db->lastInsertId()];
        }
    }

    /**
     * Listar Proveedores Verificados de un Tenant
     */
    public function listBeneficiaries(string $tenantSlug, string $query = ''): array {
        if (!empty($query)) {
            $term = '%' . $query . '%';
            $stmt = $this->db->prepare("SELECT * FROM deepshield_trusted_beneficiaries WHERE tenant_slug = ? AND (rut_dni LIKE ? OR business_name LIKE ? OR bank_name LIKE ? OR account_number LIKE ?) ORDER BY business_name ASC");
            $stmt->execute([$tenantSlug, $term, $term, $term, $term]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM deepshield_trusted_beneficiaries WHERE tenant_slug = ? ORDER BY business_name ASC");
            $stmt->execute([$tenantSlug]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Eliminar Proveedor Verificado
     */
    public function deleteBeneficiary(string $tenantSlug, int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM deepshield_trusted_beneficiaries WHERE tenant_slug = ? AND id = ?");
        return $stmt->execute([$tenantSlug, $id]);
    }

    /**
     * Verificar Estado de una Transferencia contra la Whitelist
     * Retorna:
     * - 'MATCH': Proveedor conocido y cuenta coincide 100%.
     * - 'ACCOUNT_MISMATCH': El RUT está registrado pero la cuenta bancaria es DIFERENTE (🚨 ¡Alerta Crítica de Cambio de Cuenta / BEC!).
     * - 'NEW_BENEFICIARY': Proveedor nunca antes visto.
     */
    public function verifyTransferRisk(string $tenantSlug, string $rut, string $accountNumber): array {
        $cleanRut = self::cleanRut($rut);
        $cleanAcc = self::cleanAccount($accountNumber);

        // Buscar por RUT en este tenant
        $stmt = $this->db->prepare("SELECT * FROM deepshield_trusted_beneficiaries WHERE tenant_slug = ? AND rut_dni = ?");
        $stmt->execute([$tenantSlug, $cleanRut]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($records)) {
            return [
                'status' => 'NEW_BENEFICIARY',
                'risk_level' => 'MEDIUM',
                'label' => '🆕 Proveedor Nuevo',
                'reason' => 'Este RUT no figura en el padrón histórico verificado de la empresa.'
            ];
        }

        foreach ($records as $r) {
            if (self::cleanAccount($r['account_number']) === $cleanAcc) {
                return [
                    'status' => 'MATCH',
                    'risk_level' => 'LOW',
                    'label' => '✓ Cuenta Verificada',
                    'reason' => "Cuenta habitual registrada en {$r['bank_name']}.",
                    'record' => $r
                ];
            }
        }

        // Si el RUT existe pero ninguna cuenta coincidió: ¡ALERTA BEC ROJA!
        return [
            'status' => 'ACCOUNT_MISMATCH',
            'risk_level' => 'CRITICAL',
            'label' => '🚨 CAMBIO DE CUENTA DETECTADO',
            'reason' => "El proveedor está registrado con otra cuenta ({$records[0]['bank_name']} N° {$records[0]['account_number']}). Riesgo extremo de suplantación BEC.",
            'expected_account' => $records[0]['account_number'],
            'expected_bank' => $records[0]['bank_name']
        ];
    }

    /**
     * Procesar Archivo de Nómina Masiva (CSV / TXT / TSV)
     * Soporta formatos estándar:
     * - Genérico CSV: RUT, Razón Social, Banco, N° Cuenta, Tipo Cuenta, Monto, Folio Factura
     * - Banco Santander / Banco de Chile / BCI delimitados por coma, punto y coma o tabulador
     */
    public function parseBatchFile(string $filePath, string $tenantSlug, float $thresholdAmount): array {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ['success' => false, 'error' => 'No se puede leer el archivo cargado.'];
        }

        $content = file_get_contents($filePath);
        // Normalizar saltos de línea
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);

        $parsedRows = [];
        $headers = [];
        $delimiter = null;

        foreach ($lines as $idx => $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) continue;

            // Detectar delimitador en la primera línea
            if ($delimiter === null) {
                $countSemi = substr_count($trimmed, ';');
                $countComma = substr_count($trimmed, ',');
                $countTab = substr_count($trimmed, "\t");

                if ($countSemi >= $countComma && $countSemi >= $countTab && $countSemi > 0) {
                    $delimiter = ';';
                } elseif ($countTab >= $countComma && $countTab > 0) {
                    $delimiter = "\t";
                } else {
                    $delimiter = ',';
                }
            }

            $cols = str_getcsv($trimmed, $delimiter);

            // Si es la fila de encabezados
            if ($idx === 0 && (stripos($trimmed, 'rut') !== false || stripos($trimmed, 'monto') !== false || stripos($trimmed, 'banco') !== false)) {
                $headers = array_map(function($h) {
                    return strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $h)));
                }, $cols);
                continue;
            }

            // Mapear columnas según encabezado o posición por defecto
            $row = $this->mapRow($cols, $headers);
            if (!empty($row['rut_dni']) && !empty($row['amount'])) {
                // Evaluar riesgo contra la Whitelist
                $risk = $this->verifyTransferRisk($tenantSlug, $row['rut_dni'], $row['account_number']);
                $isAboveThreshold = ((float)$row['amount'] >= $thresholdAmount);

                $row['clean_rut'] = self::cleanRut($row['rut_dni']);
                $row['clean_account'] = self::cleanAccount($row['account_number']);
                $row['risk'] = $risk;
                $row['is_above_threshold'] = $isAboveThreshold;
                
                // Determinar si requiere autorización Fuera de Banda (OOB)
                // Criterio Zero-Trust: Monto >= Umbral O Cambio de Cuenta O Proveedor Nuevo
                $requiresOob = ($isAboveThreshold || $risk['status'] === 'ACCOUNT_MISMATCH' || $risk['status'] === 'NEW_BENEFICIARY');
                $row['requires_oob'] = $requiresOob;

                $parsedRows[] = $row;
            }
        }

        return [
            'success' => true,
            'total_rows' => count($parsedRows),
            'rows' => $parsedRows,
            'threshold' => $thresholdAmount
        ];
    }

    private function mapRow(array $cols, array $headers): array {
        $row = [
            'rut_dni' => '',
            'beneficiary_name' => '',
            'bank_name' => 'Banco de Chile / Edwards',
            'account_number' => '',
            'account_type' => 'Cuenta Corriente',
            'amount' => 0,
            'invoice_folio' => ''
        ];

        // Mapeo dinámico si hay encabezados
        if (!empty($headers)) {
            foreach ($headers as $i => $h) {
                if (!isset($cols[$i])) continue;
                $val = trim($cols[$i]);

                if (in_array($h, ['rut', 'rutbeneficiario', 'identificacion', 'taxid'])) {
                    $row['rut_dni'] = $val;
                } elseif (in_array($h, ['nombre', 'razonsocial', 'beneficiario', 'nombrebeneficiario'])) {
                    $row['beneficiary_name'] = $val;
                } elseif (in_array($h, ['banco', 'banconombre', 'entidad'])) {
                    $row['bank_name'] = $val;
                } elseif (in_array($h, ['cuenta', 'nrocuenta', 'numerocuenta', 'cta'])) {
                    $row['account_number'] = $val;
                } elseif (in_array($h, ['monto', 'valor', 'total', 'importe'])) {
                    $row['amount'] = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', $val));
                } elseif (in_array($h, ['folio', 'factura', 'documento', 'foliofactura'])) {
                    $row['invoice_folio'] = $val;
                }
            }
        } else {
            // Mapeo posicional estándar: RUT, Nombre, Banco, Cuenta, Monto, Folio
            $row['rut_dni'] = $cols[0] ?? '';
            $row['beneficiary_name'] = $cols[1] ?? 'Proveedor Sin Nombre';
            $row['bank_name'] = $cols[2] ?? 'Banco Santander Chile';
            $row['account_number'] = $cols[3] ?? '';
            $row['amount'] = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', $cols[4] ?? '0'));
            $row['invoice_folio'] = $cols[5] ?? '';
        }

        return $row;
    }
}
