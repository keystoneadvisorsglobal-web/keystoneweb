<?php
/**
 * Keystone DeepShield Treasury Service
 * Motor de Blindaje Criptográfico de Pagos y Aprobación Fuera de Banda (OOB)
 * Protege contra fraudes asistidos por IA, BEC (Business Email Compromise) y Deepfake Voice Cloning.
 */

class DeepShieldService {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->initSchema();
    }

    /**
     * Inicialización idempotente del esquema de DeepShield
     */
    private function initSchema(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS deepshield_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_code TEXT UNIQUE NOT NULL,
            company_name TEXT NOT NULL,
            request_type TEXT NOT NULL DEFAULT 'PAYMENT_TRANSFER',
            amount REAL NOT NULL,
            currency TEXT NOT NULL DEFAULT 'CLP',
            beneficiary_name TEXT NOT NULL,
            beneficiary_rut_dni TEXT NOT NULL,
            bank_name TEXT NOT NULL,
            account_number TEXT NOT NULL,
            account_type TEXT NOT NULL DEFAULT 'CORRIENTE',
            invoice_folio TEXT,
            approver_name TEXT NOT NULL,
            approver_email TEXT NOT NULL,
            approver_phone TEXT,
            token_hash TEXT UNIQUE NOT NULL,
            status TEXT NOT NULL DEFAULT 'PENDING_APPROVAL',
            security_challenge TEXT NOT NULL,
            approval_method TEXT,
            approved_by_ip TEXT,
            approved_user_agent TEXT,
            approved_at DATETIME,
            cryptographic_signature TEXT,
            audit_notes TEXT,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS deepshield_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            details TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES deepshield_orders(id)
        )");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_deepshield_order_code ON deepshield_orders(order_code)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_deepshield_token ON deepshield_orders(token_hash)");

        // Migración idempotente para Multi-Operador Keystone (Asignación, Bloqueo de Casos y SLA)
        try {
            $cols = $this->db->query("PRAGMA table_info(deepshield_orders)")->fetchAll(PDO::FETCH_ASSOC);
            $existing = array_column($cols, 'name');

            if (!in_array('assigned_to', $existing)) {
                $this->db->exec("ALTER TABLE deepshield_orders ADD COLUMN assigned_to TEXT;");
            }
            if (!in_array('assigned_at', $existing)) {
                $this->db->exec("ALTER TABLE deepshield_orders ADD COLUMN assigned_at DATETIME;");
            }
            if (!in_array('operator_notes', $existing)) {
                $this->db->exec("ALTER TABLE deepshield_orders ADD COLUMN operator_notes TEXT;");
            }
        } catch (Exception $e) {
            // Ignorar si ya existen
        }
    }

    /**
     * Asignar orden a un operador Keystone específico (Claiming / Locking suave)
     */
    public function assignOrder(int $orderId, string $operatorUsername, string $notes = ''): array {
        $stmt = $this->db->prepare("SELECT * FROM deepshield_orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return ['success' => false, 'error' => 'Orden no encontrada'];
        }

        $now = date('Y-m-d H:i:s');
        $up = $this->db->prepare("UPDATE deepshield_orders SET assigned_to = ?, assigned_at = ?, operator_notes = ? WHERE id = ?");
        $up->execute([$operatorUsername, $now, $notes, $orderId]);

        $this->logAudit($orderId, 'ORDER_CLAIMED', "Orden tomada para gestión por el operador: {$operatorUsername}");

        return ['success' => true, 'assigned_to' => $operatorUsername, 'assigned_at' => $now];
    }

    /**
     * Liberar orden de vuelta a la bandeja compartida
     */
    public function releaseOrder(int $orderId, string $operatorUsername): array {
        $up = $this->db->prepare("UPDATE deepshield_orders SET assigned_to = NULL, assigned_at = NULL WHERE id = ?");
        $up->execute([$orderId]);

        $this->logAudit($orderId, 'ORDER_RELEASED', "Orden liberada a la bandeja general por: {$operatorUsername}");

        return ['success' => true];
    }

    /**
     * Crear una nueva orden de pago / cambio de cuenta para validación OOB
     */
    public function createOrder(array $data): array {
        $orderCode = 'DST-' . strtoupper(bin2hex(random_bytes(4)));
        $rawToken = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawToken);
        
        // Desafío criptográfico o palabra de control de seguridad (Anti-AI Voice Cloning)
        $securityChallenge = strtoupper(substr(md5(random_bytes(16)), 0, 6));
        
        $expiresAt = date('Y-m-d H:i:s', time() + (48 * 3600)); // 48 horas de vigencia

        $stmt = $this->db->prepare("INSERT INTO deepshield_orders (
            order_code, tenant_slug, company_name, request_type, amount, currency,
            beneficiary_name, beneficiary_rut_dni, bank_name, account_number, account_type,
            invoice_folio, approver_name, approver_email, approver_phone,
            token_hash, status, security_challenge, expires_at
        ) VALUES (
            :order_code, :tenant_slug, :company_name, :request_type, :amount, :currency,
            :beneficiary_name, :beneficiary_rut_dni, :bank_name, :account_number, :account_type,
            :invoice_folio, :approver_name, :approver_email, :approver_phone,
            :token_hash, 'PENDING_APPROVAL', :security_challenge, :expires_at
        )");

        $stmt->execute([
            ':order_code' => $orderCode,
            ':tenant_slug' => trim($data['tenant_slug'] ?? ''),
            ':company_name' => trim($data['company_name'] ?? 'Empresa Cliente'),
            ':request_type' => $data['request_type'] ?? 'PAYMENT_TRANSFER',
            ':amount' => (float)($data['amount'] ?? 0),
            ':currency' => strtoupper(trim($data['currency'] ?? 'CLP')),
            ':beneficiary_name' => trim($data['beneficiary_name'] ?? ''),
            ':beneficiary_rut_dni' => trim($data['beneficiary_rut_dni'] ?? ''),
            ':bank_name' => trim($data['bank_name'] ?? ''),
            ':account_number' => trim($data['account_number'] ?? ''),
            ':account_type' => trim($data['account_type'] ?? 'Cuenta Corriente'),
            ':invoice_folio' => trim($data['invoice_folio'] ?? ''),
            ':approver_name' => trim($data['approver_name'] ?? 'Director / Gerente de Finanzas'),
            ':approver_email' => trim($data['approver_email'] ?? ''),
            ':approver_phone' => trim($data['approver_phone'] ?? ''),
            ':token_hash' => $tokenHash,
            ':security_challenge' => $securityChallenge,
            ':expires_at' => $expiresAt
        ]);

        $orderId = $this->db->lastInsertId();

        $this->logAudit($orderId, 'ORDER_CREATED', "Orden creada por tesorería. Token hash generado.");

        return [
            'success' => true,
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'raw_token' => $rawToken,
            'security_challenge' => $securityChallenge,
            'expires_at' => $expiresAt,
            'verification_url' => "https://www.keystoneadvisors.cl/shield/verify.php?token={$rawToken}"
        ];
    }

    /**
     * Obtener orden por token seguro (soporta tanto raw token de 48 caracteres como hash directo SHA-256 de 64 caracteres)
     */
    public function getOrderByToken(string $rawOrHashToken): ?array {
        $token = trim($rawOrHashToken);
        if (empty($token)) {
            return null;
        }

        // Si ya viene como un hash SHA-256 (64 hex characters)
        if (preg_match('/^[a-f0-9]{64}$/i', $token)) {
            $stmt = $this->db->prepare("SELECT * FROM deepshield_orders WHERE token_hash = ? LIMIT 1");
            $stmt->execute([strtolower($token)]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order) {
                return $order;
            }
        }

        // Si es el token crudo (raw token), calculamos su hash SHA-256
        $tokenHash = hash('sha256', $token);
        $stmt = $this->db->prepare("SELECT * FROM deepshield_orders WHERE token_hash = ? LIMIT 1");
        $stmt->execute([$tokenHash]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $order ?: null;
    }

    /**
     * Ejecutar la aprobación o rechazo de la orden
     */
    public function resolveOrder(string $rawToken, string $action, array $meta): array {
        $order = $this->getOrderByToken($rawToken);
        if (!$order) {
            return ['success' => false, 'error' => 'Orden o token de validación no encontrado'];
        }

        if ($order['status'] !== 'PENDING_APPROVAL') {
            return ['success' => false, 'error' => 'Esta orden ya fue procesada previamente: ' . $order['status']];
        }

        if (strtotime($order['expires_at']) < time()) {
            $this->db->prepare("UPDATE deepshield_orders SET status = 'EXPIRED' WHERE id = ?")->execute([$order['id']]);
            $this->logAudit($order['id'], 'ORDER_EXPIRED', "Intento de aprobación posterior al tiempo de expiración.");
            return ['success' => false, 'error' => 'El enlace de validación ha expirado por política de seguridad'];
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        if ($action === 'APPROVE') {
            // Generar Sello Criptográfico Inmutable SHA-256
            $payloadToSign = implode('|', [
                $order['order_code'],
                $order['amount'],
                $order['currency'],
                $order['beneficiary_rut_dni'],
                $order['account_number'],
                $ip,
                date('c')
            ]);
            $signature = hash('sha256', $payloadToSign);

            $stmt = $this->db->prepare("UPDATE deepshield_orders SET 
                status = 'APPROVED',
                approval_method = :approval_method,
                approved_by_ip = :ip,
                approved_user_agent = :ua,
                approved_at = CURRENT_TIMESTAMP,
                cryptographic_signature = :sig,
                audit_notes = :notes
                WHERE id = :id");
            
            $stmt->execute([
                ':approval_method' => $meta['method'] ?? 'BIOMETRIC_OR_SECURE_TOKEN',
                ':ip' => $ip,
                ':ua' => $ua,
                ':sig' => $signature,
                ':notes' => $meta['notes'] ?? 'Aprobación fuera de banda completada por directivo.',
                ':id' => $order['id']
            ]);

            $this->logAudit($order['id'], 'ORDER_APPROVED', "Aprobado por firmante. Firma criptográfica: {$signature}");

            return [
                'success' => true,
                'status' => 'APPROVED',
                'order_code' => $order['order_code'],
                'signature' => $signature,
                'approved_at' => date('Y-m-d H:i:s')
            ];
        } else {
            $stmt = $this->db->prepare("UPDATE deepshield_orders SET 
                status = 'REJECTED',
                approved_by_ip = :ip,
                approved_user_agent = :ua,
                approved_at = CURRENT_TIMESTAMP,
                audit_notes = :notes
                WHERE id = :id");
            
            $stmt->execute([
                ':ip' => $ip,
                ':ua' => $ua,
                ':notes' => $meta['notes'] ?? 'Rechazado por el directivo por sospecha de fraude o discrepancia.',
                ':id' => $order['id']
            ]);

            $this->logAudit($order['id'], 'ORDER_REJECTED', "Rechazo preventivo ejecutado por el directivo.");

            return [
                'success' => true,
                'status' => 'REJECTED',
                'order_code' => $order['order_code']
            ];
        }
    }

    /**
     * Listado paginado y con filtros para el Portal de Tesorería del Cliente o Admin con ordenamiento por urgencia SLA y filtro de operador
     */
    public function listOrdersPaged(string $tenantSlug = '', string $search = '', string $status = '', int $page = 1, int $perPage = 10, bool $sortByUrgency = false, string $assignedFilter = ''): array {
        $where = [];
        $params = [];

        if (!empty($tenantSlug)) {
            $where[] = "tenant_slug = :tenant_slug";
            $params[':tenant_slug'] = $tenantSlug;
        }

        if (!empty($status)) {
            $where[] = "status = :status";
            $params[':status'] = $status;
        }

        if (!empty($assignedFilter)) {
            if ($assignedFilter === 'UNASSIGNED') {
                $where[] = "(assigned_to IS NULL OR assigned_to = '')";
            } elseif ($assignedFilter === 'MINE') {
                // Se resolverá en la capa superior o pasando el username
            } else {
                $where[] = "assigned_to = :assigned_to";
                $params[':assigned_to'] = $assignedFilter;
            }
        }

        if (!empty($search)) {
            $where[] = "(order_code LIKE :q OR beneficiary_name LIKE :q OR beneficiary_rut_dni LIKE :q OR bank_name LIKE :q OR invoice_folio LIKE :q)";
            $params[':q'] = '%' . trim($search) . '%';
        }

        $whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        // Total registros
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM deepshield_orders {$whereClause}");
        $stmtCount->execute($params);
        $totalRows = (int)$stmtCount->fetchColumn();

        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        // Ordenamiento por Urgencia Operativa SLA para la Consola Admin:
        // 1. REJECTED (Fraude / Incidente en curso)
        // 2. PENDING_APPROVAL (Priorizando las más antiguas en espera > 15m)
        // 3. Demás estados (APPROVED, EXPIRED) por ID descendente
        if ($sortByUrgency) {
            $orderBy = "ORDER BY 
                CASE 
                    WHEN status = 'REJECTED' THEN 1 
                    WHEN status = 'PENDING_APPROVAL' THEN 2 
                    ELSE 3 
                END ASC,
                CASE 
                    WHEN status = 'PENDING_APPROVAL' THEN datetime(created_at) 
                END ASC,
                id DESC";
        } else {
            $orderBy = "ORDER BY id DESC";
        }

        // Consulta de filas
        $stmt = $this->db->prepare("SELECT * FROM deepshield_orders {$whereClause} {$orderBy} LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'orders' => $orders,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'current_page' => $page,
            'per_page' => $perPage
        ];
    }

    /**
     * Listado para consola de administración
     */
    public function listOrders(int $limit = 50): array {
        $stmt = $this->db->query("SELECT * FROM deepshield_orders ORDER BY id DESC LIMIT {$limit}");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Estadísticas de tesorería protegida
     */
    public function getMetrics(): array {
        $stats = [
            'total_orders' => 0,
            'approved_count' => 0,
            'rejected_count' => 0,
            'pending_count' => 0,
            'total_protected_amount_clp' => 0
        ];

        $res = $this->db->query("SELECT status, count(*) as cnt, sum(case when currency = 'CLP' then amount else 0 end) as total_clp 
                                 FROM deepshield_orders GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($res as $row) {
            $stats['total_orders'] += (int)$row['cnt'];
            if ($row['status'] === 'APPROVED') {
                $stats['approved_count'] = (int)$row['cnt'];
                $stats['total_protected_amount_clp'] += (float)$row['total_clp'];
            } elseif ($row['status'] === 'REJECTED') {
                $stats['rejected_count'] = (int)$row['cnt'];
            } elseif ($row['status'] === 'PENDING_APPROVAL') {
                $stats['pending_count'] = (int)$row['cnt'];
            }
        }

        return $stats;
    }

    /**
     * Registro de auditoría
     */
    public function logAudit(int $orderId, string $action, string $details = ''): void {
        $stmt = $this->db->prepare("INSERT INTO deepshield_audit_logs (order_id, action, ip_address, user_agent, details) 
                                    VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $orderId,
            $action,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            $_SERVER['HTTP_USER_AGENT'] ?? 'CLI/System',
            $details
        ]);
    }
}
