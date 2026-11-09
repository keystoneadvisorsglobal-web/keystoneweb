<?php
/**
 * Keystone DeepShield Tenant & Client Provisioning Service
 * Permite al equipo de Keystone Advisors habilitar, parametrizar, aprovisionar y gestionar el ciclo de vida de empresas clientes.
 * Soporta múltiples operadores de tesorería y múltiples directores firmantes (approvers).
 */

class DeepShieldTenantService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->initSchema();
    }

    private function initSchema(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS deepshield_tenants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_slug TEXT UNIQUE NOT NULL,
            company_name TEXT NOT NULL,
            rut_dni TEXT NOT NULL,
            threshold_amount REAL DEFAULT 2000000,
            threshold_currency TEXT DEFAULT 'CLP',
            require_change_account_oob INTEGER DEFAULT 1,
            primary_approver_name TEXT NOT NULL,
            primary_approver_email TEXT NOT NULL,
            primary_approver_phone TEXT,
            treasury_operator_name TEXT,
            treasury_operator_email TEXT,
            treasury_operator_password TEXT,
            treasury_operator_phone TEXT,
            whatsapp_instance TEXT DEFAULT 'keystone-general',
            client_api_key TEXT UNIQUE NOT NULL,
            status TEXT DEFAULT 'ACTIVE',
            provisioned_by TEXT DEFAULT 'Keystone Admin',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS deepshield_operators (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_slug TEXT NOT NULL,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT 'OPERATOR',
            status TEXT DEFAULT 'ACTIVE',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(tenant_slug, email)
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS deepshield_approvers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_slug TEXT NOT NULL,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone_whatsapp TEXT NOT NULL,
            role_title TEXT DEFAULT 'Director / Firmante',
            max_approval_limit REAL DEFAULT 0,
            is_primary INTEGER DEFAULT 0,
            status TEXT DEFAULT 'ACTIVE',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_deepshield_tenant_slug ON deepshield_tenants(tenant_slug)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_deepshield_tenant_api ON deepshield_tenants(client_api_key)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ds_op_tenant ON deepshield_operators(tenant_slug)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ds_appr_tenant ON deepshield_approvers(tenant_slug)");
    }

    public function createTenant(array $data, string $adminUser = 'Keystone Admin'): array {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $data['tenant_slug'] ?? ''));
        if (empty($slug)) {
            $slug = 'cli_' . substr(md5(uniqid()), 0, 8);
        }

        $apiKey = 'dst_' . bin2hex(random_bytes(20));
        $rawPass = !empty($data['treasury_password']) ? trim($data['treasury_password']) : 'Tesoreria2026!';
        $passHash = password_hash($rawPass, PASSWORD_BCRYPT);
        $waInstance = !empty($data['whatsapp_instance']) ? trim($data['whatsapp_instance']) : "dst_{$slug}";

        $stmt = $this->db->prepare("INSERT INTO deepshield_tenants (
            tenant_slug, company_name, rut_dni, threshold_amount, threshold_currency,
            require_change_account_oob, primary_approver_name, primary_approver_email, primary_approver_phone,
            treasury_operator_name, treasury_operator_email, treasury_operator_phone, treasury_operator_password, whatsapp_instance, client_api_key, status, provisioned_by
        ) VALUES (
            :tenant_slug, :company_name, :rut_dni, :threshold_amount, :threshold_currency,
            :require_change_account_oob, :primary_approver_name, :primary_approver_email, :primary_approver_phone,
            :treasury_operator_name, :treasury_operator_email, :treasury_operator_phone, :treasury_operator_password, :whatsapp_instance, :client_api_key, 'ACTIVE', :provisioned_by
        )");

        $stmt->execute([
            ':tenant_slug' => $slug,
            ':company_name' => trim($data['company_name'] ?? 'Empresa Cliente'),
            ':rut_dni' => trim($data['rut_dni'] ?? ''),
            ':threshold_amount' => (float)($data['threshold_amount'] ?? 2000000),
            ':threshold_currency' => strtoupper(trim($data['threshold_currency'] ?? 'CLP')),
            ':require_change_account_oob' => !empty($data['require_change_account_oob']) ? 1 : 0,
            ':primary_approver_name' => trim($data['primary_approver_name'] ?? ''),
            ':primary_approver_email' => trim($data['primary_approver_email'] ?? ''),
            ':primary_approver_phone' => trim($data['primary_approver_phone'] ?? ''),
            ':treasury_operator_name' => trim($data['treasury_operator_name'] ?? 'Tesorero'),
            ':treasury_operator_email' => trim($data['treasury_operator_email'] ?? ''),
            ':treasury_operator_phone' => trim($data['treasury_operator_phone'] ?? ''),
            ':treasury_operator_password' => $passHash,
            ':whatsapp_instance' => $waInstance,
            ':client_api_key' => $apiKey,
            ':provisioned_by' => $adminUser
        ]);

        $tenantId = $this->db->lastInsertId();

        // Registrar automáticamente al operador principal en deepshield_operators
        if (!empty($data['treasury_operator_email'])) {
            $this->addOperator($slug, [
                'full_name' => $data['treasury_operator_name'] ?? 'Tesorero Principal',
                'email' => $data['treasury_operator_email'],
                'phone' => $data['treasury_operator_phone'] ?? '',
                'password' => $rawPass,
                'role' => 'LEAD_TREASURER'
            ]);
        }

        // Registrar automáticamente al director aprobador en deepshield_approvers
        if (!empty($data['primary_approver_name']) && !empty($data['primary_approver_phone'])) {
            $this->addApprover($slug, [
                'full_name' => $data['primary_approver_name'],
                'email' => $data['primary_approver_email'] ?? '',
                'phone_whatsapp' => $data['primary_approver_phone'],
                'role_title' => 'Director / CFO Principal',
                'is_primary' => 1
            ]);
        }

        return [
            'success' => true,
            'tenant_id' => $tenantId,
            'tenant_slug' => $slug,
            'client_api_key' => $apiKey,
            'whatsapp_instance' => $waInstance,
            'initial_password' => $rawPass
        ];
    }

    public function listTenants(): array {
        $stmt = $this->db->query("SELECT * FROM deepshield_tenants ORDER BY id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTenantBySlug(string $slug): ?array {
        $stmt = $this->db->prepare("SELECT * FROM deepshield_tenants WHERE tenant_slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getTenantById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM deepshield_tenants WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateTenant(int $id, array $data): bool {
        $sql = "UPDATE deepshield_tenants SET 
            company_name = :company_name,
            rut_dni = :rut_dni,
            threshold_amount = :threshold_amount,
            threshold_currency = :threshold_currency,
            primary_approver_name = :primary_approver_name,
            primary_approver_email = :primary_approver_email,
            primary_approver_phone = :primary_approver_phone,
            treasury_operator_name = :treasury_operator_name,
            treasury_operator_email = :treasury_operator_email,
            treasury_operator_phone = :treasury_operator_phone,
            whatsapp_instance = :whatsapp_instance,
            updated_at = CURRENT_TIMESTAMP";
        
        $params = [
            ':id' => $id,
            ':company_name' => trim($data['company_name'] ?? ''),
            ':rut_dni' => trim($data['rut_dni'] ?? ''),
            ':threshold_amount' => (float)($data['threshold_amount'] ?? 2000000),
            ':threshold_currency' => strtoupper(trim($data['threshold_currency'] ?? 'CLP')),
            ':primary_approver_name' => trim($data['primary_approver_name'] ?? ''),
            ':primary_approver_email' => trim($data['primary_approver_email'] ?? ''),
            ':primary_approver_phone' => trim($data['primary_approver_phone'] ?? ''),
            ':treasury_operator_name' => trim($data['treasury_operator_name'] ?? ''),
            ':treasury_operator_email' => trim($data['treasury_operator_email'] ?? ''),
            ':treasury_operator_phone' => trim($data['treasury_operator_phone'] ?? ''),
            ':whatsapp_instance' => trim($data['whatsapp_instance'] ?? 'keystone-general')
        ];

        if (!empty($data['treasury_password'])) {
            $sql .= ", treasury_operator_password = :treasury_operator_password";
            $params[':treasury_operator_password'] = password_hash(trim($data['treasury_password']), PASSWORD_BCRYPT);
        }

        $sql .= " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateStatus(int $tenantId, string $newStatus): bool {
        $validStatuses = ['ACTIVE', 'SUSPENDED', 'REVOKED'];
        if (!in_array($newStatus, $validStatuses, true)) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE deepshield_tenants SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$newStatus, $tenantId]);
    }

    // =========================================================================
    // GESTIÓN DE MÚLTIPLES OPERADORES DE TESORERÍA (deepshield_operators)
    // =========================================================================

    public function listOperators(string $tenantSlug): array {
        $stmt = $this->db->prepare("SELECT id, tenant_slug, full_name, email, phone, role, status, created_at FROM deepshield_operators WHERE tenant_slug = ? ORDER BY id ASC");
        $stmt->execute([$tenantSlug]);
        $ops = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: si no hay en tabla relacional pero existe en deepshield_tenants, retornarlo
        if (empty($ops)) {
            $t = $this->getTenantBySlug($tenantSlug);
            if ($t && !empty($t['treasury_operator_email'])) {
                return [[
                    'id' => 0,
                    'tenant_slug' => $tenantSlug,
                    'full_name' => $t['treasury_operator_name'] ?? 'Tesorero Principal',
                    'email' => $t['treasury_operator_email'],
                    'phone' => $t['treasury_operator_phone'] ?? '',
                    'role' => 'LEAD_TREASURER',
                    'status' => 'ACTIVE',
                    'created_at' => $t['created_at']
                ]];
            }
        }
        return $ops;
    }

    public function addOperator(string $tenantSlug, array $data): array {
        $name = trim($data['full_name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $phone = trim($data['phone'] ?? '');
        $role = trim($data['role'] ?? 'OPERATOR');
        $rawPass = !empty($data['password']) ? trim($data['password']) : 'Tesoreria2026!';

        if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Nombre y correo válido son obligatorios.'];
        }

        $hash = password_hash($rawPass, PASSWORD_BCRYPT);

        try {
            $stmt = $this->db->prepare("INSERT INTO deepshield_operators (tenant_slug, full_name, email, phone, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE')");
            $stmt->execute([$tenantSlug, $name, $email, $phone, $hash, $role]);
            return ['success' => true, 'operator_id' => $this->db->lastInsertId()];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'El correo ya se encuentra registrado para este cliente.'];
        }
    }

    public function deleteOperator(string $tenantSlug, int $operatorId): bool {
        $stmt = $this->db->prepare("DELETE FROM deepshield_operators WHERE tenant_slug = ? AND id = ?");
        return $stmt->execute([$tenantSlug, $operatorId]);
    }

    // =========================================================================
    // GESTIÓN DE MÚLTIPLES DIRECTORES FIRMANTES (deepshield_approvers)
    // =========================================================================

    public function listApprovers(string $tenantSlug): array {
        $stmt = $this->db->prepare("SELECT * FROM deepshield_approvers WHERE tenant_slug = ? ORDER BY is_primary DESC, id ASC");
        $stmt->execute([$tenantSlug]);
        $approvers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: si no hay en tabla relacional pero existe en deepshield_tenants, retornarlo
        if (empty($approvers)) {
            $t = $this->getTenantBySlug($tenantSlug);
            if ($t && !empty($t['primary_approver_name'])) {
                return [[
                    'id' => 0,
                    'tenant_slug' => $tenantSlug,
                    'full_name' => $t['primary_approver_name'],
                    'email' => $t['primary_approver_email'],
                    'phone_whatsapp' => $t['primary_approver_phone'],
                    'role_title' => 'Director / CFO Principal',
                    'max_approval_limit' => 0,
                    'is_primary' => 1,
                    'status' => 'ACTIVE'
                ]];
            }
        }
        return $approvers;
    }

    public function addApprover(string $tenantSlug, array $data): array {
        $name = trim($data['full_name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $phone = trim($data['phone_whatsapp'] ?? '');
        $role = trim($data['role_title'] ?? 'Director / Firmante');
        $limit = (float)($data['max_approval_limit'] ?? 0);
        $isPrimary = !empty($data['is_primary']) ? 1 : 0;

        if (empty($name) || empty($phone)) {
            return ['success' => false, 'error' => 'Nombre y celular WhatsApp son obligatorios.'];
        }

        // Si se marca como primario, desmarcar los anteriores
        if ($isPrimary) {
            $this->db->prepare("UPDATE deepshield_approvers SET is_primary = 0 WHERE tenant_slug = ?")->execute([$tenantSlug]);
        }

        $stmt = $this->db->prepare("INSERT INTO deepshield_approvers (tenant_slug, full_name, email, phone_whatsapp, role_title, max_approval_limit, is_primary, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE')");
        $stmt->execute([$tenantSlug, $name, $email, $phone, $role, $limit, $isPrimary]);

        return ['success' => true, 'approver_id' => $this->db->lastInsertId()];
    }

    public function deleteApprover(string $tenantSlug, int $approverId): bool {
        $stmt = $this->db->prepare("DELETE FROM deepshield_approvers WHERE tenant_slug = ? AND id = ?");
        return $stmt->execute([$tenantSlug, $approverId]);
    }

    // =========================================================================
    // AUTENTICACIÓN DINÁMICA MULTI-OPERADOR
    // =========================================================================

    public function authenticateOperator(string $tenantSlug, string $email, string $password): ?array {
        $tenant = $this->getTenantBySlug($tenantSlug);
        if (!$tenant || $tenant['status'] !== 'ACTIVE') {
            return null;
        }

        $cleanEmail = strtolower(trim($email));

        // 1. Intentar validar contra deepshield_operators
        $stmtOp = $this->db->prepare("SELECT * FROM deepshield_operators WHERE tenant_slug = ? AND email = ? AND status = 'ACTIVE' LIMIT 1");
        $stmtOp->execute([$tenantSlug, $cleanEmail]);
        $op = $stmtOp->fetch(PDO::FETCH_ASSOC);

        if ($op && password_verify($password, $op['password_hash'])) {
            $tenant['current_operator_id'] = $op['id'];
            $tenant['treasury_operator_name'] = $op['full_name'];
            $tenant['treasury_operator_email'] = $op['email'];
            $tenant['treasury_operator_role'] = $op['role'];
            return $tenant;
        }

        // 2. Fallback: validar contra el usuario legado de deepshield_tenants
        if (strcasecmp(trim($tenant['treasury_operator_email']), $cleanEmail) === 0) {
            if (password_verify($password, $tenant['treasury_operator_password'] ?? '')) {
                $tenant['current_operator_id'] = 0;
                $tenant['treasury_operator_role'] = 'LEAD_TREASURER';
                return $tenant;
            }
        }

        return null;
    }

    /**
     * Métricas Ejecutivas de Ciberseguridad Financiera para el CFO / Gerencia General
     */
    public function getExecutiveMetrics(string $tenantSlug): array {
        $stmt = $this->db->prepare("SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(amount), 0) as total_volume,
            COALESCE(SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END), 0) as approved_orders,
            COALESCE(SUM(CASE WHEN status = 'APPROVED' THEN amount ELSE 0 END), 0) as approved_volume,
            COALESCE(SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END), 0) as rejected_orders,
            COALESCE(SUM(CASE WHEN status = 'REJECTED' THEN amount ELSE 0 END), 0) as fraud_prevented_amount,
            COALESCE(SUM(CASE WHEN status = 'PENDING_APPROVAL' THEN 1 ELSE 0 END), 0) as pending_orders,
            COALESCE(SUM(CASE WHEN status = 'PENDING_APPROVAL' THEN amount ELSE 0 END), 0) as pending_volume
        FROM deepshield_orders WHERE tenant_slug = ?");
        $stmt->execute([$tenantSlug]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmtFrauds = $this->db->prepare("SELECT * FROM deepshield_orders WHERE tenant_slug = ? AND status = 'REJECTED' ORDER BY id DESC LIMIT 5");
        $stmtFrauds->execute([$tenantSlug]);
        $frauds = $stmtFrauds->fetchAll(PDO::FETCH_ASSOC);

        $stmtWl = $this->db->prepare("SELECT count(*) as total_trusted FROM deepshield_trusted_beneficiaries WHERE tenant_slug = ?");
        $stmtWl->execute([$tenantSlug]);
        $wlCount = (int)$stmtWl->fetchColumn();

        return [
            'stats' => $stats,
            'recent_frauds' => $frauds,
            'trusted_beneficiaries_count' => $wlCount
        ];
    }
}
