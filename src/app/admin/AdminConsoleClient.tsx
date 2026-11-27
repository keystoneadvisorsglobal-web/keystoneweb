"use client";

import React, { useState, useEffect, useCallback, useMemo } from "react";
import Link from "next/link";
import {
  Activity,
  Briefcase,
  Sliders,
  ShieldCheck,
  Users,
  Search,
  RefreshCw,
  LogOut,
  ArrowUpRight,
  Lock,
  Radar,
  CheckCircle2,
  AlertTriangle,
  Plus,
  X,
  Save,
  RotateCcw,
  Eye,
  KeyRound,
  UserPlus,
  Building2,
  Sparkles,
  Globe,
  Loader2,
  Trash2,
  Mail,
  MessageCircle,
  Send,
  Upload,
  Fingerprint,
  Crosshair,
  FileCode2,
  Landmark,
  Kanban,
  FileDown,
  Inbox,
  Megaphone,
  QrCode,
  BookOpen,
} from "lucide-react";
import {
  SPATIAL_THEMES,
  type SpatialThemeId,
} from "@/config/spatialThemes";
import {
  DEFAULT_SITE_CONFIG,
  DEFAULT_PARTNERS_MARQUEE,
  type PublicSiteConfig,
  type PillarCatalog,
  type ServiceCardOffer,
  type PartnerMarqueeItem,
} from "@/config/defaultSiteCatalog";

type AdminRole = "superadmin" | "comercial" | "secops";
type NavModule =
  | "pulse"
  | "pipeline"
  | "studio"
  | "secops"
  | "growth"
  | "channels"
  | "governance";

interface AdminUser {
  id: number;
  username: string;
  email?: string;
  full_name: string;
  role: AdminRole;
  totp_enabled?: boolean | number;
  is_active?: number;
  last_login?: string | null;
  created_at?: string;
}

interface MicroLeadRow {
  id: number;
  order_ref: string;
  action: string;
  email: string;
  domain: string | null;
  pillar: string | null;
  service_name: string | null;
  price_info: string | null;
  scan_summary: string | null;
  status: string;
  priority: string;
  assigned_to: string;
  notes: string;
  keyhub_card_code: string;
  keyhub_project_key: string;
  keyhub_synced_at: string | null;
  ip_address: string | null;
  created_at: string;
  updated_at: string | null;
  latest_bec_score: number | null;
  latest_dmarc: string | null;
}

interface BecScanRow {
  id: number;
  scan_ref: string;
  domain: string;
  email: string | null;
  score: number;
  risk_level: string;
  dmarc_label: string | null;
  spf_status: string | null;
  mx_status: string | null;
  tls_status: string | null;
  ip_address: string | null;
  created_at: string;
}

interface ExecutiveKpis {
  total_leads: number;
  new_leads: number;
  active_pipeline: number;
  won_leads: number;
  total_scans: number;
  avg_bec_score: number;
  exposed_scans: number;
  unique_organizations: number;
  by_pillar: Record<string, number>;
}

interface WatchedDomainRow {
  id: number;
  domain: string;
  client_name: string;
  tier: string;
  last_score: number;
  dmarc_policy: string;
  spf_status: string;
  tls_status: string;
  badge_installed?: number;
  badge_impressions?: number;
  badge_last_origin?: string;
  badge_last_seen_at?: string | null;
  last_checked_at: string | null;
}

interface TreasuryOrderRow {
  id: number;
  order_code: string;
  company_name: string;
  amount: number;
  currency: string;
  beneficiary_name: string;
  beneficiary_rut_dni: string;
  bank_name: string;
  account_number: string;
  approver_name: string;
  approver_email: string;
  approver_phone: string;
  secondary_approver_name?: string;
  status: string;
  security_challenge: string;
  cryptographic_signature: string | null;
  whitelist_status?: string;
  whitelist_detail?: string;
  required_approvals?: number;
  approval_count?: number;
  created_at: string;
}

interface DmarcRuaReportRow {
  id: number;
  report_id: string;
  org_name: string;
  domain: string;
  policy_p: string;
  date_begin: string;
  date_end: string;
  total_messages: number;
  total_pass: number;
  total_fail: number;
  spoofing_ips: number;
  raw_filename: string;
  created_at: string;
}

interface RedTeamCampaignRow {
  id: number;
  company_name: string;
  contact_name: string;
  contact_email: string;
  plan_type: string;
  target_domains: string;
  target_users_count: number;
  simulated_vectors: string;
  status: string;
  last_lure_subject: string;
  last_lure_body: string;
  lure_cta_label?: string;
  lure_accent_color?: string;
  custom_html_body?: string;
  targets_total?: number;
  targets_sent?: number;
  targets_opened?: number;
  targets_clicked?: number;
  targets_reported?: number;
  assets_count?: number;
  created_at: string;
}

interface RedTeamTemplateRow {
  id: number;
  client_scope: string;
  template_name: string;
  category: string;
  subject: string;
  body_text: string;
  custom_html: string;
  cta_label: string;
  accent_color: string;
  created_at: string;
}

interface RedTeamTargetRow {
  id: number;
  campaign_id: number;
  full_name: string;
  email: string;
  department: string;
  role_title: string;
  status: string;
  sent_at: string | null;
  opened_at: string | null;
  clicked_at: string | null;
  reported_at: string | null;
}

interface RedTeamAssetRow {
  id: number;
  campaign_id: number;
  client_label?: string;
  campaign_company?: string;
  asset_name: string;
  asset_type: string;
  file_size: number;
  uploaded_by: string;
  created_at: string;
}

interface OutboundDispatchRow {
  id: number;
  campaign_id: number;
  recipient_email: string;
  recipient_name: string;
  subject: string;
  status: string;
  opened_at: string | null;
  clicked_at: string | null;
  created_at: string;
}

interface TrustZeroControl {
  category: string;
  name: string;
  status: "PASS" | "WARN" | "FAIL";
  desc: string;
  compliance: string[];
}

interface TrustZeroPosture {
  domain: string;
  trust_score?: number;
  global_score?: number;
  status_label?: string;
  overall_status?: string;
  last_evaluated_at?: string;
  evaluated_at?: string;
  controls: Record<string, TrustZeroControl>;
}

interface AuditLogRow {
  id: number;
  actor: string;
  action_code: string;
  target_ref: string;
  detail: string;
  ip_address: string;
  created_at: string;
}

interface DispatchLogRow {
  id: number;
  channel: string;
  recipient: string;
  subject_or_ref: string;
  status: string;
  detail: string;
  created_at: string;
}

interface KeyHubProject {
  id: string;
  project_key: string;
  name: string;
}

interface DeepShieldTenantRow {
  id: number;
  tenant_slug: string;
  company_name: string;
  rut_dni: string;
  threshold_amount: number;
  threshold_currency: string;
  primary_approver_name: string;
  primary_approver_email: string;
  secondary_approver_name?: string;
  dual_approval_threshold?: number;
  treasury_operator_email: string;
  whatsapp_instance: string;
  client_api_key: string;
  status: string;
}

interface EmailReplyRow {
  id: number;
  sender_name: string;
  sender_email: string;
  subject: string;
  snippet: string;
  linked_lead_ref: string;
  received_at: string;
}

interface GrowthCampaignRow {
  id: number;
  campaign_name: string;
  channel: string;
  pillar_focus: string;
  target_audience: string;
  ai_copy_headline: string;
  ai_copy_body: string;
  status: string;
  published_url?: string;
  created_at: string;
}

interface NotificationSettingsState {
  smtp_enabled: boolean;
  smtp_host: string;
  smtp_port: number;
  smtp_user: string;
  smtp_pass: string;
  has_smtp_pass?: boolean;
  smtp_from: string;
  smtp_name: string;
  sales_alert_email: string;
  send_prospect_autoresponder: boolean;
  dmarc_rua_user?: string;
  dmarc_rua_pass?: string;
  has_rua_pass?: boolean;
  whatsapp_enabled: boolean;
  whatsapp_gateway_url: string;
  whatsapp_api_key: string;
  has_wa_key?: boolean;
  whatsapp_instance: string;
  whatsapp_alert_phone: string;
  google_sso_enabled: boolean;
  google_allowed_domain: string;
  google_client_id: string;
  google_client_secret: string;
  has_google_secret?: boolean;
  linkedin_access_token?: string;
  has_linkedin_token?: boolean;
  linkedin_org_urn?: string;
}

const LEAD_STATUS_META: Record<
  string,
  { label: string; badgeClass: string }
> = {
  NUEVO: {
    label: "Nuevo Ingreso",
    badgeClass: "bg-sky-400/15 text-sky-300 border-sky-400/35",
  },
  DIAGNOSTICO_ENVIADO: {
    label: "Diagnóstico Enviado",
    badgeClass: "bg-indigo-400/15 text-indigo-300 border-indigo-400/35",
  },
  REUNION_EJECUTIVA: {
    label: "Reunión Ejecutiva",
    badgeClass: "bg-amber-400/15 text-amber-200 border-amber-400/35",
  },
  PROPUESTA: {
    label: "Propuesta Activa",
    badgeClass: "bg-violet-400/15 text-violet-300 border-violet-400/35",
  },
  GANADO_KEYHUB: {
    label: "Activo en KeyHub",
    badgeClass: "bg-emerald-400/15 text-emerald-300 border-emerald-400/35",
  },
  DESCARTADO: {
    label: "Archivado",
    badgeClass: "bg-white/10 text-white/50 border-white/15",
  },
};

function pillarBadgeStyle(pillar: string | null): string {
  const p = (pillar || "").toLowerCase();
  if (p.includes("digital") || p.includes("crm")) {
    return "bg-sky-400/15 text-sky-300 border-sky-400/30";
  }
  if (p.includes("deep") || p.includes("bec")) {
    return "bg-emerald-400/15 text-emerald-300 border-emerald-400/30";
  }
  if (p.includes("keyhub")) {
    return "bg-violet-400/15 text-violet-300 border-violet-400/30";
  }
  return "bg-white/10 text-white/75 border-white/15";
}

function scoreColorClass(score: number | null): string {
  if (score === null || score === undefined) return "text-white/45";
  if (score >= 90) return "text-emerald-300";
  if (score >= 70) return "text-amber-300";
  return "text-rose-400";
}

export default function AdminConsoleClient() {
  const [booting, setBooting] = useState<boolean>(true);
  const [authenticated, setAuthenticated] = useState<boolean>(false);
  const [currentUser, setCurrentUser] = useState<AdminUser | null>(null);
  const [csrfToken, setCsrfToken] = useState<string>("");
  const [googleSsoEnabled, setGoogleSsoEnabled] = useState<boolean>(true);

  // Login & 2FA Gate
  const [loginUser, setLoginUser] = useState<string>("");
  const [loginPass, setLoginPass] = useState<string>("");
  const [requires2FaStep, setRequires2FaStep] = useState<boolean>(false);
  const [loginTotpCode, setLoginTotpCode] = useState<string>("");
  const [loginBusy, setLoginBusy] = useState<boolean>(false);
  const [loginError, setLoginError] = useState<string | null>(null);

  // Navigation & Search
  const [activeNav, setActiveNav] = useState<NavModule>("pulse");
  const [secopsTab, setSecopsTab] = useState<
    "scanner" | "treasury" | "tenants" | "dmarc" | "redteam" | "vault"
  >("scanner");
  const [globalQuery, setGlobalQuery] = useState<string>("");
  const [pipelineTab, setPipelineTab] = useState<"leads" | "scans">("leads");
  const [statusFilter, setStatusFilter] = useState<string>("ALL");
  const [refreshing, setRefreshing] = useState<boolean>(false);
  const [toastMsg, setToastMsg] = useState<string | null>(null);

  // Data States
  const [kpis, setKpis] = useState<ExecutiveKpis>({
    total_leads: 0,
    new_leads: 0,
    active_pipeline: 0,
    won_leads: 0,
    total_scans: 0,
    avg_bec_score: 100,
    exposed_scans: 0,
    unique_organizations: 0,
    by_pillar: { "Digital Core": 0, DeepShield: 0, KeyHub: 0 },
  });
  const [leads, setLeads] = useState<MicroLeadRow[]>([]);
  const [becScans, setBecScans] = useState<BecScanRow[]>([]);
  const [watchedDomains, setWatchedDomains] = useState<WatchedDomainRow[]>([]);
  const [treasuryOrders, setTreasuryOrders] = useState<TreasuryOrderRow[]>([]);
  const [dmarcReports, setDmarcReports] = useState<DmarcRuaReportRow[]>([]);
  const [redteamCampaigns, setRedteamCampaigns] = useState<RedTeamCampaignRow[]>(
    []
  );
  const [trustZero, setTrustZero] = useState<TrustZeroPosture | null>(null);
  const [usersList, setUsersList] = useState<AdminUser[]>([]);
  const [auditLogs, setAuditLogs] = useState<AuditLogRow[]>([]);
  const [keyhubProjects, setKeyhubProjects] = useState<KeyHubProject[]>([]);
  const [dsTenants, setDsTenants] = useState<DeepShieldTenantRow[]>([]);
  const [emailReplies, setEmailReplies] = useState<EmailReplyRow[]>([]);
  const [imapMailbox, setImapMailbox] = useState<string>(
    "contacto@keystoneadvisors.cl"
  );
  const [imapHostLabel, setImapHostLabel] = useState<string>(
    "imap.titan.email:993/SSL"
  );
  const [growthCampaigns, setGrowthCampaigns] = useState<GrowthCampaignRow[]>([]);
  const [imapSyncing, setImapSyncing] = useState<boolean>(false);

  // Tenant & QR States
  const [tenCompany, setTenCompany] = useState<string>("");
  const [tenSlug, setTenSlug] = useState<string>("");
  const [tenRut, setTenRut] = useState<string>("");
  const [tenOpEmail, setTenOpEmail] = useState<string>("");
  const [tenOpPass, setTenOpPass] = useState<string>("Tesoreria2026!");
  const [tenApprName, setTenApprName] = useState<string>("");
  const [tenApprPhone, setTenApprPhone] = useState<string>("");
  const [tenSecApprName, setTenSecApprName] = useState<string>("");
  const [tenSecApprPhone, setTenSecApprPhone] = useState<string>("");
  const [tenDualThreshold, setTenDualThreshold] = useState<string>("0");
  const [tenWaMode, setTenWaMode] = useState<"shared" | "dedicated">("shared");
  const [tenWaInstance, setTenWaInstance] = useState<string>("keystone-general");
  const [treasurySopModalOpen, setTreasurySopModalOpen] =
    useState<boolean>(false);

  // SOL-0342: Red Teaming Operations, Cron Daemon, Outbound B2B, ERP Webhook & Backup States
  const [activeRtCampaign, setActiveRtCampaign] =
    useState<RedTeamCampaignRow | null>(null);
  const [rtTargets, setRtTargets] = useState<RedTeamTargetRow[]>([]);
  const [rtAssets, setRtAssets] = useState<RedTeamAssetRow[]>([]);
  const [rtTargetName, setRtTargetName] = useState<string>("");
  const [rtTargetEmail, setRtTargetEmail] = useState<string>("");
  const [rtTargetDept, setRtTargetDept] = useState<string>("Finanzas");
  const [rtEditSubject, setRtEditSubject] = useState<string>("");
  const [rtEditBody, setRtEditBody] = useState<string>("");
  const [rtEditCta, setRtEditCta] = useState<string>(
    "REVISAR SOLICITUD EN PORTAL INTERNO"
  );
  const [rtEditAccent, setRtEditAccent] = useState<string>("#0F172A");
  const [rtEditCustomHtml, setRtEditCustomHtml] = useState<string>("");
  const [rtTemplates, setRtTemplates] = useState<RedTeamTemplateRow[]>([]);
  const [vaultAssets, setVaultAssets] = useState<RedTeamAssetRow[]>([]);
  const [selectedTplId, setSelectedTplId] = useState<number | null>(null);
  const [vTplName, setVTplName] = useState<string>("");
  const [vTplCategory, setVTplCategory] = useState<string>("Corporativo");
  const [vTplClientScope, setVTplClientScope] = useState<string>("GLOBAL");
  const [vTplSubject, setVTplSubject] = useState<string>("");
  const [vTplBody, setVTplBody] = useState<string>("");
  const [vTplHtml, setVTplHtml] = useState<string>("");
  const [vTplCta, setVTplCta] = useState<string>("REVISAR DOCUMENTO EN LINEA");
  const [vTplAccent, setVTplAccent] = useState<string>("#0F172A");
  const [vTplPreviewOpen, setVTplPreviewOpen] = useState<boolean>(false);
  const [vAssetClientLabel, setVAssetClientLabel] = useState<string>("General");
  const [rtSaveTplName, setRtSaveTplName] = useState<string>("");
  const [rtPreviewOpen, setRtPreviewOpen] = useState<boolean>(false);
  const [rtCloneSourceId, setRtCloneSourceId] = useState<string>("");
  const [rtModalNotice, setRtModalNotice] = useState<{
    type: "ok" | "err";
    text: string;
  } | null>(null);
  const [rtOpBusy, setRtOpBusy] = useState<boolean>(false);

  const [lastCronRun, setLastCronRun] = useState<{
    executed_at: string;
    duration_sec: number;
    watchdog_checked: number;
    rua_ingested: number;
    expired_orders: number;
  } | null>(null);
  const [cronRunning, setCronRunning] = useState<boolean>(false);

  const [outboundDispatches, setOutboundDispatches] = useState<
    OutboundDispatchRow[]
  >([]);
  const [outboundToEmail, setOutboundToEmail] = useState<string>("");
  const [outboundToName, setOutboundToName] = useState<string>("");
  const [outboundCampId, setOutboundCampId] = useState<number>(0);

  const [erpModalTenant, setErpModalTenant] =
    useState<DeepShieldTenantRow | null>(null);
  const [backupMeta, setBackupMeta] = useState<{
    staging_stats?: Record<string, number>;
    legacy_source?: {
      available: boolean;
      path_detected?: string;
      counts?: Record<string, number>;
    };
  } | null>(null);
  const [migratingLegacy, setMigratingLegacy] = useState<boolean>(false);
  const [qrModalData, setQrModalData] = useState<{
    instance: string;
    connected: boolean;
    qr_base64?: string | null;
    message?: string;
  } | null>(null);

  // Growth Campaign Form
  const [grName, setGrName] = useState<string>("");
  const [grPillar, setGrPillar] = useState<string>("Digital Core");
  const [grAudience, setGrAudience] = useState<string>("CIOs, CTOs y CFOs Banca/Retail");
  const [grBusy, setGrBusy] = useState<boolean>(false);

  // Marquee New Item
  const [mqName, setMqName] = useState<string>("");
  const [mqRole, setMqRole] = useState<string>("");
  const [mqHref, setMqHref] = useState<string>("");
  const [sopModalOpen, setSopModalOpen] = useState<boolean>(false);

  // Lead Drawer + KeyHub Bridge
  const [selectedLead, setSelectedLead] = useState<MicroLeadRow | null>(null);
  const [drawerSaving, setDrawerSaving] = useState<boolean>(false);
  const [selectedKhProject, setSelectedKhProject] =
    useState<string>("prj_ksweb_01");
  const [syncingKeyHub, setSyncingKeyHub] = useState<boolean>(false);

  const [newLeadModal, setNewLeadModal] = useState<boolean>(false);
  const [newLeadEmail, setNewLeadEmail] = useState<string>("");
  const [newLeadPillar, setNewLeadPillar] = useState<string>("Digital Core");
  const [newLeadService, setNewLeadService] = useState<string>(
    "Diagnóstico Ejecutivo"
  );
  const [newLeadNotes, setNewLeadNotes] = useState<string>("");

  // Live Studio State
  const [studioConfig, setStudioConfig] =
    useState<PublicSiteConfig>(DEFAULT_SITE_CONFIG);
  const [studioHasCustom, setStudioHasCustom] = useState<boolean>(false);
  const [studioUpdatedAt, setStudioUpdatedAt] = useState<string | null>(null);
  const [studioSelectedPillar, setStudioSelectedPillar] = useState<number>(1);
  const [studioSaving, setStudioSaving] = useState<boolean>(false);

  // SecOps Sub-Modules States
  const [secopsDomainInput, setSecopsDomainInput] = useState<string>("");
  const [secopsScanBusy, setSecopsScanBusy] = useState<boolean>(false);
  const [secopsScanResult, setSecopsScanResult] = useState<{
    domain: string;
    score: number;
    risk_level: string;
    spf: string;
    dmarc: string;
    mx: string;
    https_hsts: string;
    findings: Array<{ severity: string; title: string; desc: string }>;
  } | null>(null);
  const [watchClientInput, setWatchClientInput] = useState<string>("");
  const [watchDomainInput, setWatchDomainInput] = useState<string>("");
  const [watchTierInput, setWatchTierInput] = useState<string>("Enterprise");

  // Treasury Form
  const [trCompany, setTrCompany] = useState<string>("");
  const [trAmount, setTrAmount] = useState<string>("");
  const [trCurrency, setTrCurrency] = useState<string>("CLP");
  const [trBeneficiary, setTrBeneficiary] = useState<string>("");
  const [trRut, setTrRut] = useState<string>("");
  const [trBank, setTrBank] = useState<string>("");
  const [trAccount, setTrAccount] = useState<string>("");
  const [trApproverName, setTrApproverName] = useState<string>("");
  const [trApproverPhone, setTrApproverPhone] = useState<string>("");
  const [trBusy, setTrBusy] = useState<boolean>(false);

  // DMARC RUA Upload
  const [ruaUploading, setRuaUploading] = useState<boolean>(false);

  // Red Teaming Form
  const [rtCompany, setRtCompany] = useState<string>("");
  const [rtDomains, setRtDomains] = useState<string>("");
  const [rtContactEmail, setRtContactEmail] = useState<string>("");
  const [rtUsersCount, setRtUsersCount] = useState<string>("25");
  const [rtVector, setRtVector] = useState<string>("BEC_CEO_FRAUD");
  const [rtPlan, setRtPlan] = useState<string>("PROFESSIONAL");
  const [rtBusy, setRtBusy] = useState<boolean>(false);

  // Channels (SMTP + WhatsApp + SSO)
  const [notifSettings, setNotifSettings] = useState<NotificationSettingsState>({
    smtp_enabled: true,
    smtp_host: "smtp.titan.email",
    smtp_port: 465,
    smtp_user: "contacto@keystoneadvisors.cl",
    smtp_pass: "",
    smtp_from: "contacto@keystoneadvisors.cl",
    smtp_name: "Keystone Advisors Global",
    sales_alert_email: "contacto@keystoneadvisors.cl",
    send_prospect_autoresponder: true,
    whatsapp_enabled: true,
    whatsapp_gateway_url: "http://5.183.9.148:8080",
    whatsapp_api_key: "",
    whatsapp_instance: "keystone-general",
    whatsapp_alert_phone: "56930640431",
    google_sso_enabled: true,
    google_allowed_domain: "keystoneadvisors.cl",
    google_client_id: "",
    google_client_secret: "",
  });
  const [dispatchLogs, setDispatchLogs] = useState<DispatchLogRow[]>([]);
  const [notifSaving, setNotifSaving] = useState<boolean>(false);
  const [testEmailTo, setTestEmailTo] = useState<string>(
    "contacto@keystoneadvisors.cl"
  );
  const [testWaPhone, setTestWaPhone] = useState<string>("56930640431");

  // Governance & 2FA Enrollment
  const [newOpUsername, setNewOpUsername] = useState<string>("");
  const [newOpEmail, setNewOpEmail] = useState<string>("");
  const [newOpName, setNewOpName] = useState<string>("");
  const [newOpRole, setNewOpRole] = useState<AdminRole>("comercial");
  const [newOpPass, setNewOpPass] = useState<string>("");
  const [totpSetupSecret, setTotpSetupSecret] = useState<string | null>(null);
  const [totpSetupUrl, setTotpSetupUrl] = useState<string | null>(null);
  const [totpVerifyInput, setTotpVerifyInput] = useState<string>("");

  const notify = useCallback((msg: string) => {
    setToastMsg(msg);
    setTimeout(() => {
      setToastMsg((prev) => (prev === msg ? null : prev));
    }, 4200);
  }, []);

  const apiPost = useCallback(
    async (url: string, payload: Record<string, unknown>) => {
      let tokenToUse = csrfToken;
      let res = await fetch(url, {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": tokenToUse,
        },
        body: JSON.stringify(payload),
      });
      if (res.status === 403) {
        const sess = await fetch("/api/admin/auth.php?action=session", {
          credentials: "include",
        })
          .then((r) => r.json())
          .catch(() => null);
        if (sess?.csrf_token && sess.csrf_token !== tokenToUse) {
          tokenToUse = sess.csrf_token;
          setCsrfToken(tokenToUse);
          res = await fetch(url, {
            method: "POST",
            credentials: "include",
            headers: {
              "Content-Type": "application/json",
              "X-CSRF-Token": tokenToUse,
            },
            body: JSON.stringify(payload),
          });
        }
      }
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || "Error procesando solicitud");
      }
      return data;
    },
    [csrfToken]
  );

  const loadAllConsoleData = useCallback(async (role?: AdminRole) => {
    setRefreshing(true);
    try {
      const [leadsRes, catRes, secRes, khRes, grRes] = await Promise.all([
        fetch("/api/admin/leads.php", { credentials: "include" }).then((r) =>
          r.ok ? r.json() : null
        ),
        fetch("/api/admin/catalog.php", { credentials: "include" }).then((r) =>
          r.ok ? r.json() : null
        ),
        fetch("/api/admin/secops.php", { credentials: "include" }).then((r) =>
          r.ok ? r.json() : null
        ),
        fetch("/api/admin/keyhub_bridge.php", { credentials: "include" }).then(
          (r) => (r.ok ? r.json() : null)
        ),
        fetch("/api/admin/growth_inbox.php", { credentials: "include" }).then(
          (r) => (r.ok ? r.json() : null)
        ),
      ]);

      if (leadsRes?.success) {
        setKpis(leadsRes.kpis);
        setLeads(leadsRes.leads || []);
        setBecScans(leadsRes.bec_scans || []);
      }

      if (catRes?.success) {
        setStudioHasCustom(Boolean(catRes.has_custom_config));
        setStudioUpdatedAt(catRes.updated_at || null);
        if (catRes.config) {
          setStudioConfig({
            ...DEFAULT_SITE_CONFIG,
            ...catRes.config,
            chapters:
              Array.isArray(catRes.config.chapters) &&
                catRes.config.chapters.length === 4
                ? catRes.config.chapters
                : DEFAULT_SITE_CONFIG.chapters,
          });
        } else {
          setStudioConfig(JSON.parse(JSON.stringify(DEFAULT_SITE_CONFIG)));
        }
      }

      if (secRes?.success) {
        setWatchedDomains(secRes.watched_domains || []);
        setTrustZero(secRes.trustzero || null);
        setTreasuryOrders(secRes.treasury_orders || []);
        setDmarcReports(secRes.dmarc_reports || []);
        setRedteamCampaigns(secRes.redteam_campaigns || []);
        if (secRes.redteam_templates) {
          setRtTemplates(secRes.redteam_templates);
          if (secRes.redteam_templates.length > 0) {
            const first = secRes.redteam_templates[0] as RedTeamTemplateRow;
            setSelectedTplId((prev) => {
              if (prev !== null) return prev;
              setVTplName(first.template_name);
              setVTplCategory(first.category);
              setVTplClientScope(first.client_scope || "GLOBAL");
              setVTplSubject(first.subject);
              setVTplBody(first.body_text || "");
              setVTplHtml(first.custom_html || "");
              setVTplCta(first.cta_label || "REVISAR DOCUMENTO EN LINEA");
              setVTplAccent(first.accent_color || "#0F172A");
              return first.id;
            });
          }
        }
        if (secRes.vault_assets) {
          setVaultAssets(secRes.vault_assets);
        }
        setDsTenants(secRes.tenants || []);
        if (secRes.last_cron_run) setLastCronRun(secRes.last_cron_run);
      }

      if (grRes?.success) {
        setEmailReplies(grRes.replies || []);
        setGrowthCampaigns(grRes.growth_campaigns || []);
        setOutboundDispatches(grRes.outbound_dispatches || []);
        if (grRes.growth_campaigns?.length > 0) {
          setOutboundCampId((prev) => prev || grRes.growth_campaigns[0].id);
        }
        if (grRes.imap_mailbox) setImapMailbox(grRes.imap_mailbox);
        if (grRes.imap_host) setImapHostLabel(grRes.imap_host);
      }

      if (khRes?.success && Array.isArray(khRes.projects)) {
        setKeyhubProjects(khRes.projects);
      }

      if (role === "superadmin") {
        const [usrRes, notifRes, bkpRes] = await Promise.all([
          fetch("/api/admin/auth.php?action=users", {
            credentials: "include",
          }).then((r) => (r.ok ? r.json() : null)),
          fetch("/api/admin/notifications.php", {
            credentials: "include",
          }).then((r) => (r.ok ? r.json() : null)),
          fetch("/api/admin/backup_migrator.php", {
            credentials: "include",
          }).then((r) => (r.ok ? r.json() : null)),
        ]);

        if (usrRes?.success) {
          setUsersList(usrRes.users || []);
          setAuditLogs(usrRes.audit_logs || []);
        }
        if (notifRes?.success && notifRes.settings) {
          setNotifSettings((prev) => ({ ...prev, ...notifRes.settings }));
          setDispatchLogs(notifRes.dispatch_logs || []);
        }
        if (bkpRes?.success) {
          setBackupMeta(bkpRes);
        }
      }
    } catch {
      // ignore transient network errors
    } finally {
      setRefreshing(false);
    }
  }, []);

  // Boot & SSO Error Check
  useEffect(() => {
    if (typeof window !== "undefined") {
      const params = new URLSearchParams(window.location.search);
      const ssoErr = params.get("sso_error");
      if (ssoErr) {
        setLoginError(ssoErr);
      }
    }

    fetch("/api/admin/auth.php?action=session", { credentials: "include" })
      .then((r) => r.json())
      .then((data) => {
        if (data?.csrf_token) setCsrfToken(data.csrf_token);
        if (typeof data?.google_sso_enabled === "boolean") {
          setGoogleSsoEnabled(data.google_sso_enabled);
        }
        if (data?.pending_2fa) {
          setRequires2FaStep(true);
        }
        if (data?.authenticated && data?.user) {
          setAuthenticated(true);
          setCurrentUser(data.user);
          if (data.user.role === "secops") {
            setActiveNav("secops");
          }
          loadAllConsoleData(data.user.role);
        }
      })
      .catch(() => { })
      .finally(() => setBooting(false));
  }, [loadAllConsoleData]);

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoginBusy(true);
    setLoginError(null);
    try {
      const payload = requires2FaStep
        ? { action: "verify_2fa_login", totp_code: loginTotpCode.trim() }
        : {
          action: "login",
          username: loginUser.trim(),
          password: loginPass,
        };

      const res = await fetch("/api/admin/auth.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        setLoginError(data.error || "Credenciales inválidas.");
      } else if (data.requires_2fa) {
        setRequires2FaStep(true);
        setLoginError(null);
      } else {
        setCsrfToken(data.csrf_token || "");
        setCurrentUser(data.user);
        setAuthenticated(true);
        setRequires2FaStep(false);
        setLoginPass("");
        setLoginTotpCode("");
        if (data.user.role === "secops") {
          setActiveNav("secops");
        }
        loadAllConsoleData(data.user.role);
      }
    } catch {
      setLoginError("No fue posible conectar con el motor de autenticación.");
    } finally {
      setLoginBusy(false);
    }
  };

  const handleLogout = async () => {
    try {
      await apiPost("/api/admin/auth.php", { action: "logout" });
    } catch {
      // force client logout
    }
    setAuthenticated(false);
    setCurrentUser(null);
  };

  const handleSaveLeadDrawer = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedLead) return;
    setDrawerSaving(true);
    try {
      await apiPost("/api/admin/leads.php", {
        action: "update_lead",
        id: selectedLead.id,
        status: selectedLead.status,
        priority: selectedLead.priority,
        assigned_to: selectedLead.assigned_to,
        notes: selectedLead.notes,
      });
      notify(`Solicitud ${selectedLead.order_ref} actualizada.`);
      setSelectedLead(null);
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error al guardar");
    } finally {
      setDrawerSaving(false);
    }
  };

  const handleSyncLeadToKeyHub = async () => {
    if (!selectedLead) return;
    setSyncingKeyHub(true);
    try {
      const data = await apiPost("/api/admin/keyhub_bridge.php", {
        lead_id: selectedLead.id,
        project_id: selectedKhProject,
        card_type: "TASK",
      });
      notify(data.message || `Sincronizado como ${data.card_code}`);
      setSelectedLead((prev) =>
        prev
          ? {
            ...prev,
            status: "GANADO_KEYHUB",
            keyhub_card_code: data.card_code,
            keyhub_project_key: data.project_key,
          }
          : null
      );
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Fallo sincronizando a KeyHub");
    } finally {
      setSyncingKeyHub(false);
    }
  };

  const handleCreateManualLead = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await apiPost("/api/admin/leads.php", {
        action: "create_manual_lead",
        email: newLeadEmail,
        pillar: newLeadPillar,
        service_name: newLeadService,
        notes: newLeadNotes,
      });
      setNewLeadModal(false);
      setNewLeadEmail("");
      setNewLeadNotes("");
      notify("Oportunidad corporativa incorporada al pipeline.");
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error creando oportunidad");
    }
  };

  // Studio Handlers
  const handleSaveStudio = async () => {
    setStudioSaving(true);
    try {
      const data = await apiPost("/api/admin/catalog.php", {
        action: "save_config",
        config: studioConfig,
      });
      setStudioHasCustom(true);
      setStudioUpdatedAt(data.config?.updatedAt || "Ahora");
      notify("Configuración espacial y catálogo publicados en vivo.");
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error al publicar catálogo");
    } finally {
      setStudioSaving(false);
    }
  };

  const handleResetStudio = async () => {
    if (
      !window.confirm("¿Restaurar textos, precios y atmósfera base del código?")
    ) {
      return;
    }
    setStudioSaving(true);
    try {
      await apiPost("/api/admin/catalog.php", { action: "reset_defaults" });
      setStudioConfig(JSON.parse(JSON.stringify(DEFAULT_SITE_CONFIG)));
      setStudioHasCustom(false);
      setStudioUpdatedAt(null);
      notify("Catálogo restaurado a los valores originales.");
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error restaurando");
    } finally {
      setStudioSaving(false);
    }
  };

  const updateStudioPillarField = (
    chapterIndex: number,
    field: keyof PillarCatalog,
    value: string
  ) => {
    setStudioConfig((prev) => ({
      ...prev,
      chapters: prev.chapters.map((ch) =>
        ch.chapterIndex === chapterIndex ? { ...ch, [field]: value } : ch
      ),
    }));
  };

  const updateStudioCardField = (
    chapterIndex: number,
    cardId: string,
    field: keyof ServiceCardOffer,
    value: string
  ) => {
    setStudioConfig((prev) => ({
      ...prev,
      chapters: prev.chapters.map((ch) => {
        if (ch.chapterIndex !== chapterIndex || !ch.cards) return ch;
        return {
          ...ch,
          cards: ch.cards.map((c) =>
            c.id === cardId ? { ...c, [field]: value } : c
          ),
        };
      }),
    }));
  };

  // SecOps Handlers
  const handleSecopsScan = async (domainToScan?: string) => {
    const target = (domainToScan ?? secopsDomainInput).trim();
    if (!target) return;
    setSecopsScanBusy(true);
    try {
      const data = await apiPost("/api/admin/secops.php", {
        action: "scan_domain",
        domain: target,
      });
      setSecopsScanResult(data.result);
      notify(
        `Auditoría BEC completada para ${data.result.domain} (${data.result.score}/100).`
      );
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error escaneando dominio");
    } finally {
      setSecopsScanBusy(false);
    }
  };

  const handleAddWatchedDomain = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await apiPost("/api/admin/secops.php", {
        action: "add_watched_domain",
        client_name: watchClientInput,
        domain: watchDomainInput,
        tier: watchTierInput,
      });
      setWatchClientInput("");
      setWatchDomainInput("");
      notify("Dominio agregado a vigilancia activa DeepShield.");
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error al agregar dominio");
    }
  };

  const handleRemoveWatched = async (id: number) => {
    try {
      await apiPost("/api/admin/secops.php", {
        action: "remove_watched_domain",
        id,
      });
      notify("Dominio retirado de vigilancia.");
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "No autorizado");
    }
  };

  const handleUpdateWatchedTier = async (id: number, newTier: string) => {
    try {
      await apiPost("/api/admin/secops.php", {
        action: "update_watched_tier",
        id,
        tier: newTier,
      });
      notify(`Plan actualizado a ${newTier} exitosamente.`);
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error actualizando plan");
    }
  };

  const handleCreateTreasuryOrder = async (e: React.FormEvent) => {
    e.preventDefault();
    setTrBusy(true);
    try {
      const data = await apiPost("/api/admin/secops.php", {
        action: "create_treasury_order",
        company_name: trCompany,
        amount: parseFloat(trAmount),
        currency: trCurrency,
        beneficiary_name: trBeneficiary,
        beneficiary_rut_dni: trRut,
        bank_name: trBank,
        account_number: trAccount,
        approver_name: trApproverName || "Director Financiero",
        approver_phone: trApproverPhone,
      });
      setTrCompany("");
      setTrAmount("");
      setTrBeneficiary("");
      setTrRut("");
      setTrBank("");
      setTrAccount("");
      setTrApproverPhone("");
      notify(data.message);
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error emitiendo orden");
    } finally {
      setTrBusy(false);
    }
  };

  const handleResolveTreasury = async (
    id: number,
    decision: "APPROVE" | "REJECT"
  ) => {
    try {
      const data = await apiPost("/api/admin/secops.php", {
        action: "resolve_treasury_order",
        id,
        decision,
      });
      notify(data.message);
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error resolviendo orden");
    }
  };

  const handleUploadDmarcRua = async (
    e: React.ChangeEvent<HTMLInputElement>
  ) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setRuaUploading(true);
    try {
      const fd = new FormData();
      fd.append("action", "ingest_dmarc_rua");
      fd.append("csrf_token", csrfToken);
      fd.append("rua_file", file);

      const res = await fetch("/api/admin/secops.php", {
        method: "POST",
        credentials: "include",
        headers: { "X-CSRF-Token": csrfToken },
        body: fd,
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || "Error procesando XML DMARC");
      }
      notify(data.message);
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error subiendo RUA");
    } finally {
      setRuaUploading(false);
      e.target.value = "";
    }
  };

  const handleCreateRedTeam = async (e: React.FormEvent) => {
    e.preventDefault();
    setRtBusy(true);
    try {
      const data = await apiPost("/api/admin/secops.php", {
        action: "create_redteam_campaign",
        company_name: rtCompany,
        target_domains: rtDomains,
        contact_email: rtContactEmail,
        target_users_count: parseInt(rtUsersCount, 10) || 25,
        vector: rtVector,
        plan_type: rtPlan,
      });
      setRtCompany("");
      setRtDomains("");
      setRtContactEmail("");
      notify(data.message);
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error creando campaña");
    } finally {
      setRtBusy(false);
    }
  };

  // Notifications & Channels Handlers
  const handleSaveNotifSettings = async (e: React.FormEvent) => {
    e.preventDefault();
    setNotifSaving(true);
    try {
      const data = await apiPost("/api/admin/notifications.php", {
        action: "save",
        settings: notifSettings,
      });
      notify(data.message);
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error guardando canales");
    } finally {
      setNotifSaving(false);
    }
  };

  const handleTestSmtp = async () => {
    try {
      const data = await apiPost("/api/admin/notifications.php", {
        action: "test_smtp",
        to_email: testEmailTo,
      });
      notify(data.message);
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error en prueba SMTP");
    }
  };

  const handleTestWhatsApp = async () => {
    try {
      const data = await apiPost("/api/admin/notifications.php", {
        action: "test_whatsapp",
        to_phone: testWaPhone,
      });
      notify(data.message);
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error en prueba WhatsApp");
    }
  };

  // 2FA & Operators Handlers
  const handleStart2FaSetup = async () => {
    try {
      const data = await apiPost("/api/admin/auth.php", {
        action: "setup_2fa",
      });
      setTotpSetupSecret(data.secret);
      setTotpSetupUrl(data.otpauth_url);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error iniciando 2FA");
    }
  };

  const handleConfirm2Fa = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const data = await apiPost("/api/admin/auth.php", {
        action: "confirm_2fa",
        totp_code: totpVerifyInput,
      });
      setTotpSetupSecret(null);
      setTotpSetupUrl(null);
      setTotpVerifyInput("");
      setCurrentUser((prev) => (prev ? { ...prev, totp_enabled: true } : null));
      notify(data.message);
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Código TOTP inválido");
    }
  };

  const handleDisableMy2Fa = async () => {
    if (!window.confirm("¿Desactivar el segundo factor 2FA en su cuenta?"))
      return;
    try {
      const data = await apiPost("/api/admin/auth.php", {
        action: "disable_2fa",
      });
      setCurrentUser((prev) =>
        prev ? { ...prev, totp_enabled: false } : null
      );
      notify(data.message);
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error desactivando 2FA");
    }
  };

  const handleCreateUser = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await apiPost("/api/admin/auth.php", {
        action: "create_user",
        username: newOpUsername,
        email: newOpEmail,
        full_name: newOpName,
        role: newOpRole,
        password: newOpPass,
      });
      setNewOpUsername("");
      setNewOpEmail("");
      setNewOpName("");
      setNewOpPass("");
      notify("Operador creado con éxito.");
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error creando operador");
    }
  };

  const handleToggleUser = async (userId: number) => {
    try {
      await apiPost("/api/admin/auth.php", {
        action: "toggle_user",
        user_id: userId,
      });
      notify("Estado de acceso actualizado.");
      loadAllConsoleData(currentUser?.role);
    } catch (err) {
      notify(err instanceof Error ? err.message : "Error cambiando estado");
    }
  };

  // Filtered Lists
  const filteredLeads = useMemo(() => {
    const q = globalQuery.toLowerCase().trim();
    return leads.filter((l) => {
      if (statusFilter !== "ALL" && l.status !== statusFilter) return false;
      if (!q) return true;
      return (
        l.email.toLowerCase().includes(q) ||
        (l.domain || "").toLowerCase().includes(q) ||
        (l.service_name || "").toLowerCase().includes(q) ||
        (l.pillar || "").toLowerCase().includes(q) ||
        (l.keyhub_card_code || "").toLowerCase().includes(q) ||
        l.order_ref.toLowerCase().includes(q)
      );
    });
  }, [leads, globalQuery, statusFilter]);

  const filteredScans = useMemo(() => {
    const q = globalQuery.toLowerCase().trim();
    if (!q) return becScans;
    return becScans.filter(
      (s) =>
        s.domain.toLowerCase().includes(q) ||
        (s.email || "").toLowerCase().includes(q) ||
        s.scan_ref.toLowerCase().includes(q)
    );
  }, [becScans, globalQuery]);

  // =========================================================================
  // RENDER 1: BOOTING SCREEN
  // =========================================================================
  if (booting) {
    return (
      <div
        className="min-h-screen flex items-center justify-center bg-[#080B11] text-white"
        style={{ fontFamily: "var(--font-inter), sans-serif" }}
      >
        <div className="flex items-center gap-3 text-xs uppercase tracking-[0.2em] text-white/60">
          <Loader2 className="w-4 h-4 animate-spin text-violet-300" />
          <span>Inicializando Keystone Command Console v4.1</span>
        </div>
      </div>
    );
  }

  // =========================================================================
  // RENDER 2: SPATIAL OBSIDIAN LOGIN GATE (+ 2FA TOTP & GOOGLE SSO)
  // =========================================================================
  if (!authenticated || !currentUser) {
    return (
      <div
        className="relative min-h-screen flex items-center justify-center bg-[#080B11] text-white px-4 overflow-hidden"
        style={{ fontFamily: "var(--font-inter), sans-serif" }}
      >
        <div
          className="pointer-events-none absolute inset-0 opacity-[0.06]"
          style={{
            backgroundImage:
              "linear-gradient(to right, #ffffff 1px, transparent 1px), linear-gradient(to bottom, #ffffff 1px, transparent 1px)",
            backgroundSize: "64px 64px",
          }}
        />
        <div
          className="pointer-events-none absolute inset-0"
          style={{
            background:
              "radial-gradient(circle at 50% 22%, rgba(139, 92, 246, 0.16), transparent 55%), radial-gradient(circle at 80% 80%, rgba(56, 189, 248, 0.10), transparent 50%)",
          }}
        />

        <div className="relative z-10 w-full max-w-md">
          <div className="mb-7 text-center">
            <div className="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-white/[0.06] border border-white/15 mb-4 shadow-2xl">
              <span
                style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                className="text-base font-extrabold tracking-tight text-white"
              >
                KS
              </span>
            </div>
            <div
              style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
              className="text-[11px] font-semibold uppercase tracking-[0.24em] text-violet-300"
            >
              Keystone Advisors Global
            </div>
            <h1
              style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
              className="text-2xl font-bold text-white mt-1.5 tracking-tight"
            >
              Command Console v4.1
            </h1>
            <p className="text-xs text-white/55 mt-1.5">
              Gobierno B2B, Puente KeyHub, Estudio Espacial y Suite DeepShield
            </p>
          </div>

          <form
            onSubmit={handleLogin}
            style={{
              backgroundColor: "rgba(13, 18, 28, 0.88)",
              backdropFilter: "blur(24px)",
            }}
            className="rounded-2xl border border-white/12 p-6 sm:p-7 shadow-[0_24px_64px_rgba(0,0,0,0.65)] space-y-4"
          >
            {!requires2FaStep ? (
              <>
                <div>
                  <label className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-white/60 mb-1.5">
                    Operador o Correo Corporativo
                  </label>
                  <input
                    type="text"
                    required
                    autoFocus
                    autoComplete="username"
                    value={loginUser}
                    onChange={(e) => setLoginUser(e.target.value)}
                    placeholder="keystone_admin"
                    className="w-full px-3.5 py-2.5 rounded-xl bg-white/[0.05] border border-white/15 text-sm text-white placeholder:text-white/30 focus:outline-none focus:border-violet-300 transition-colors"
                  />
                </div>

                <div>
                  <label className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-white/60 mb-1.5">
                    Clave Ejecutiva
                  </label>
                  <input
                    type="password"
                    required
                    autoComplete="current-password"
                    value={loginPass}
                    onChange={(e) => setLoginPass(e.target.value)}
                    placeholder="••••••••••••"
                    className="w-full px-3.5 py-2.5 rounded-xl bg-white/[0.05] border border-white/15 text-sm text-white placeholder:text-white/30 focus:outline-none focus:border-violet-300 transition-colors"
                  />
                </div>
              </>
            ) : (
              <div className="space-y-2">
                <div className="flex items-center gap-2 text-emerald-300 text-xs font-semibold">
                  <Fingerprint className="w-4 h-4" />
                  <span>Verificación en Dos Pasos (TOTP RFC 6238)</span>
                </div>
                <p className="text-xs text-white/55">
                  Ingrese el código temporal de 6 dígitos generado por Google
                  Authenticator / Authy:
                </p>
                <input
                  type="text"
                  inputMode="numeric"
                  pattern="[0-9]{6}"
                  maxLength={6}
                  required
                  autoFocus
                  value={loginTotpCode}
                  onChange={(e) => setLoginTotpCode(e.target.value)}
                  placeholder="000000"
                  className="w-full px-4 py-3 rounded-xl bg-white/[0.06] border border-emerald-400/40 text-center font-mono text-xl tracking-[0.4em] text-white focus:outline-none focus:border-emerald-300"
                />
                <button
                  type="button"
                  onClick={() => {
                    setRequires2FaStep(false);
                    setLoginTotpCode("");
                  }}
                  className="text-[11px] text-white/45 hover:text-white"
                >
                  ← Volver al usuario y contraseña
                </button>
              </div>
            )}

            {loginError && (
              <div className="rounded-xl bg-rose-500/15 border border-rose-400/30 px-3.5 py-2.5 text-xs text-rose-200 flex items-center gap-2">
                <AlertTriangle className="w-4 h-4 text-rose-400 shrink-0" />
                <span>{loginError}</span>
              </div>
            )}

            <button
              type="submit"
              disabled={loginBusy}
              style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
              className="w-full py-2.5 px-4 rounded-xl bg-white text-[#0A0E15] hover:bg-violet-300 font-bold text-xs uppercase tracking-[0.1em] transition-all flex items-center justify-center gap-2 disabled:opacity-60"
            >
              {loginBusy ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <>
                  <Lock className="w-3.5 h-3.5" />
                  <span>
                    {requires2FaStep
                      ? "Verificar Código 2FA"
                      : "Ingresar a la Consola"}
                  </span>
                </>
              )}
            </button>

            {!requires2FaStep && googleSsoEnabled && (
              <div className="pt-3 border-t border-white/10">
                <a
                  href="/api/admin/sso_google.php?action=start"
                  className="w-full py-2.5 px-4 rounded-xl bg-white/[0.05] hover:bg-white/[0.12] border border-white/15 text-xs font-semibold text-white/90 transition-all flex items-center justify-center gap-2"
                >
                  <Globe className="w-3.5 h-3.5 text-sky-300" />
                  <span>Continuar con Google Workspace (@keystoneadvisors.cl)</span>
                </a>
              </div>
            )}
          </form>

          <div className="mt-6 flex items-center justify-between text-[11px] text-white/45 px-1">
            <Link
              href="/"
              className="inline-flex items-center gap-1 hover:text-white transition-colors"
            >
              <span>Volver al Sitio Espacial</span>
              <ArrowUpRight className="w-3.5 h-3.5" />
            </Link>
            <span className="inline-flex items-center gap-1.5">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
              <span>Zero Trust · 2FA TOTP · SSO</span>
            </span>
          </div>
        </div>
      </div>
    );
  }

  // =========================================================================
  // RENDER 3: AUTHENTICATED COMMAND CONSOLE v4.1
  // =========================================================================
  const navItems: Array<{
    id: NavModule;
    label: string;
    sub: string;
    icon: React.ComponentType<{ className?: string }>;
    roles: AdminRole[];
  }> = [
      {
        id: "pulse",
        label: "Pulso Ejecutivo",
        sub: "KPIs, demanda y postura",
        icon: Activity,
        roles: ["superadmin", "comercial"],
      },
      {
        id: "pipeline",
        label: "Demanda & KeyHub",
        sub: "Micro-leads + Puente 1-Clic",
        icon: Briefcase,
        roles: ["superadmin", "comercial"],
      },
      {
        id: "studio",
        label: "Gobierno del Sitio",
        sub: "Atmósfera 3D y 12 tarjetas",
        icon: Sliders,
        roles: ["superadmin", "comercial"],
      },
      {
        id: "secops",
        label: "Suite DeepShield",
        sub: "BEC, Treasury, Tenants & IA",
        icon: ShieldCheck,
        roles: ["superadmin", "secops", "comercial"],
      },
      {
        id: "growth",
        label: "Bandeja IMAP & Growth",
        sub: "Respuestas y LinkedIn IA",
        icon: Megaphone,
        roles: ["superadmin", "comercial"],
      },
      {
        id: "channels",
        label: "Motor SMTP & Alertas",
        sub: "Titan Mail, WhatsApp & SSO",
        icon: Send,
        roles: ["superadmin"],
      },
      {
        id: "governance",
        label: "Accesos, 2FA & Logs",
        sub: "Operadores RBAC y TOTP",
        icon: Users,
        roles: ["superadmin", "comercial", "secops"],
      },
    ];

  const allowedNav = navItems.filter(
    (item) =>
      currentUser.role === "superadmin" || item.roles.includes(currentUser.role)
  );

  const editingPillar =
    studioConfig.chapters.find((c) => c.chapterIndex === studioSelectedPillar) ||
    studioConfig.chapters[1];

  return (
    <div
      className="min-h-screen bg-[#080B11] text-white flex flex-col lg:flex-row"
      style={{ fontFamily: "var(--font-inter), sans-serif" }}
    >
      {/* TOAST SIEMPRE POR ENCIMA DE CUALQUIER MODAL */}
      {toastMsg && (
        <div className="fixed bottom-5 right-5 z-[120] rounded-xl bg-[#111726] border border-violet-400/40 px-4 py-3 shadow-2xl flex items-center gap-2.5 text-xs text-white max-w-md">
          <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
          <span>{toastMsg}</span>
        </div>
      )}

      {/* SIDEBAR */}
      <aside className="w-full lg:w-64 shrink-0 border-b lg:border-b-0 lg:border-r border-white/[0.08] bg-[#0B0F17] flex flex-col justify-between">
        <div>
          <div className="p-5 border-b border-white/[0.07] flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="w-9 h-9 rounded-xl bg-white/[0.07] border border-white/15 flex items-center justify-center">
                <span
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="text-xs font-extrabold tracking-wider text-white"
                >
                  KS
                </span>
              </div>
              <div>
                <div
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="text-xs font-bold uppercase tracking-[0.12em] text-white"
                >
                  Keystone
                </div>
                <div className="text-[11px] text-white/45">Console v4.1</div>
              </div>
            </div>
            <span className="px-2 py-0.5 rounded-md bg-emerald-400/10 border border-emerald-400/25 text-[10px] font-semibold uppercase tracking-wider text-emerald-300">
              DEV
            </span>
          </div>

          <nav className="p-3 space-y-1 flex lg:flex-col overflow-x-auto">
            {allowedNav.map((item) => {
              const Icon = item.icon;
              const active = activeNav === item.id;
              return (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => setActiveNav(item.id)}
                  className={`w-full text-left px-3.5 py-2.5 rounded-xl transition-all flex items-center gap-3 shrink-0 lg:shrink ${active
                      ? "bg-white/[0.09] text-white border border-white/15 shadow-sm"
                      : "text-white/60 hover:text-white hover:bg-white/[0.04] border border-transparent"
                    }`}
                >
                  <Icon
                    className={`w-4 h-4 shrink-0 ${active ? "text-violet-300" : "text-white/45"
                      }`}
                  />
                  <div className="min-w-0">
                    <div
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="text-xs font-semibold truncate"
                    >
                      {item.label}
                    </div>
                    <div className="hidden lg:block text-[10px] text-white/40 truncate">
                      {item.sub}
                    </div>
                  </div>
                </button>
              );
            })}
          </nav>
        </div>

        <div className="hidden lg:block p-4 border-t border-white/[0.07] space-y-3">
          <div className="rounded-xl bg-white/[0.03] border border-white/[0.07] p-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-white truncate">
                {currentUser.full_name}
              </span>
              {currentUser.totp_enabled ? (
                <span className="text-[9px] px-1.5 py-0.5 rounded bg-emerald-400/15 text-emerald-300 font-bold">
                  2FA ON
                </span>
              ) : null}
            </div>
            <div className="text-[11px] text-white/45 flex items-center justify-between mt-0.5">
              <span>@{currentUser.username}</span>
              <span className="uppercase text-[10px] font-bold text-violet-300">
                {currentUser.role}
              </span>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Link
              href="/"
              target="_blank"
              className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] border border-white/10 text-[11px] font-medium text-white/80 transition-colors"
            >
              <Eye className="w-3.5 h-3.5" />
              <span>Ver Sitio</span>
            </Link>
            <button
              type="button"
              onClick={handleLogout}
              title="Cerrar sesión"
              className="inline-flex items-center justify-center px-3 py-2 rounded-lg bg-white/[0.05] hover:bg-rose-500/20 border border-white/10 hover:border-rose-400/30 text-white/70 hover:text-rose-200 transition-colors"
            >
              <LogOut className="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      </aside>

      {/* MAIN WORKSPACE */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* TOP UTILITY BAR */}
        <header className="h-16 px-5 sm:px-8 border-b border-white/[0.08] bg-[#080B11]/90 backdrop-blur-xl flex items-center justify-between gap-4 sticky top-0 z-20">
          <div className="relative w-full max-w-md">
            <Search className="w-3.5 h-3.5 text-white/40 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={globalQuery}
              onChange={(e) => setGlobalQuery(e.target.value)}
              placeholder="Filtrar por empresa, dominio, correo o tarjeta KeyHub..."
              className="w-full pl-9 pr-3.5 py-1.5 rounded-xl bg-white/[0.04] border border-white/10 text-xs text-white placeholder:text-white/35 focus:outline-none focus:border-white/30"
            />
          </div>

          <div className="flex items-center gap-2.5 shrink-0">
            <Link
              href="/trust/"
              target="_blank"
              className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-400/10 border border-emerald-400/25 text-[11px] font-semibold text-emerald-300 hover:bg-emerald-400/20 transition-colors"
            >
              <ShieldCheck className="w-3.5 h-3.5" />
              <span>
                TrustZero{" "}
                {trustZero
                  ? `${trustZero.trust_score ?? trustZero.global_score ?? 100}%`
                  : "Live"}
              </span>
            </Link>

            <button
              type="button"
              onClick={() => loadAllConsoleData(currentUser.role)}
              disabled={refreshing}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white/[0.05] hover:bg-white/[0.1] border border-white/10 text-xs text-white/80 transition-colors"
            >
              <RefreshCw
                className={`w-3.5 h-3.5 ${refreshing ? "animate-spin" : ""}`}
              />
              <span className="hidden sm:inline">Sincronizar</span>
            </button>
          </div>
        </header>

        <main className="p-5 sm:p-8 space-y-6 max-w-7xl w-full mx-auto">
          {/* =================================================================
              MODULE 1: PULSO EJECUTIVO
          ================================================================== */}
          {activeNav === "pulse" && (
            <div className="space-y-6">
              <div className="flex flex-wrap items-end justify-between gap-4">
                <div>
                  <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-violet-300">
                    Ingeniería IA-Gobernada · Telemetría Comercial & SecOps
                  </div>
                  <h1
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-2xl font-bold text-white mt-1"
                  >
                    Pulso Ejecutivo en Vivo
                  </h1>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    setActiveNav("pipeline");
                    setNewLeadModal(true);
                  }}
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white text-[#0A0E15] hover:bg-sky-300 text-xs font-bold uppercase tracking-wider transition-colors"
                >
                  <Plus className="w-3.5 h-3.5" />
                  <span>Registrar Oportunidad</span>
                </button>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5">
                  <div className="text-[11px] font-semibold uppercase tracking-[0.1em] text-sky-300">
                    Solicitudes B2B
                  </div>
                  <div
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-3xl font-bold text-white mt-2"
                  >
                    {kpis.total_leads}
                  </div>
                  <div className="text-xs text-white/50 mt-1.5">
                    <span className="text-sky-300 font-semibold">
                      {kpis.new_leads} nuevas
                    </span>{" "}
                    · {kpis.won_leads} en KeyHub
                  </div>
                </div>

                <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5">
                  <div className="text-[11px] font-semibold uppercase tracking-[0.1em] text-emerald-300">
                    Auditorías Dominio BEC
                  </div>
                  <div
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-3xl font-bold text-white mt-2"
                  >
                    {kpis.total_scans}
                  </div>
                  <div className="text-xs text-white/50 mt-1.5">
                    Score promedio:{" "}
                    <span className="text-emerald-300 font-semibold">
                      {kpis.avg_bec_score}/100
                    </span>
                  </div>
                </div>

                <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5">
                  <div className="text-[11px] font-semibold uppercase tracking-[0.1em] text-violet-300">
                    Operaciones SecOps Activas
                  </div>
                  <div
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-3xl font-bold text-white mt-2"
                  >
                    {treasuryOrders.length + redteamCampaigns.length}
                  </div>
                  <div className="text-xs text-white/50 mt-1.5">
                    {treasuryOrders.length} órdenes OOB ·{" "}
                    {redteamCampaigns.length} campañas IA
                  </div>
                </div>

                <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5">
                  <div className="text-[11px] font-semibold uppercase tracking-[0.1em] text-white/70">
                    Atmósfera Espacial Activa
                  </div>
                  <div
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-lg font-bold text-white mt-2.5 truncate"
                  >
                    {SPATIAL_THEMES[studioConfig.defaultTheme]?.label ||
                      "Amethyst Slate"}
                  </div>
                  <div className="text-xs text-white/50 mt-2 flex items-center justify-between">
                    <span>
                      {studioHasCustom ? "Catálogo en vivo" : "Valores base"}
                    </span>
                    <button
                      type="button"
                      onClick={() => setActiveNav("studio")}
                      className="text-violet-300 hover:underline font-medium"
                    >
                      Configurar
                    </button>
                  </div>
                </div>
              </div>

              {/* DEMAND BY PILLAR + LATEST ACTIVITY */}
              <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                  <div
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-xs font-bold uppercase tracking-[0.12em] text-white/75"
                  >
                    Tracción por Pilar de Negocio
                  </div>

                  {[
                    {
                      name: "Digital Core (CRM)",
                      count: kpis.by_pillar["Digital Core"] || 0,
                      bar: "bg-sky-400",
                      text: "text-sky-300",
                    },
                    {
                      name: "DeepShield (DSX)",
                      count: kpis.by_pillar["DeepShield"] || 0,
                      bar: "bg-emerald-400",
                      text: "text-emerald-300",
                    },
                    {
                      name: "KeyHub & Agentes IA (KHS)",
                      count: kpis.by_pillar["KeyHub"] || 0,
                      bar: "bg-violet-400",
                      text: "text-violet-300",
                    },
                  ].map((p) => {
                    const pct =
                      kpis.total_leads > 0
                        ? Math.round((p.count / kpis.total_leads) * 100)
                        : 0;
                    return (
                      <div key={p.name} className="space-y-1.5">
                        <div className="flex items-center justify-between text-xs">
                          <span className="font-medium text-white/85">
                            {p.name}
                          </span>
                          <span className={`font-bold ${p.text}`}>
                            {p.count} ({pct}%)
                          </span>
                        </div>
                        <div className="h-1.5 rounded-full bg-white/[0.06] overflow-hidden">
                          <div
                            className={`h-full rounded-full ${p.bar}`}
                            style={{
                              width: `${Math.max(pct, p.count > 0 ? 8 : 0)}%`,
                            }}
                          />
                        </div>
                      </div>
                    );
                  })}

                  <div className="pt-3 border-t border-white/[0.07] text-[11px] text-white/50 leading-relaxed">
                    Al ingresar un correo en las tarjetas del sitio, el backend
                    evalúa automáticamente el DNS del dominio, dispara alerta
                    WhatsApp/SMTP y habilita su paso en 1 clic a KeyHub.
                  </div>
                </div>

                <div className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5">
                  <div className="flex items-center justify-between mb-4">
                    <div
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="text-xs font-bold uppercase tracking-[0.12em] text-white/75"
                    >
                      Últimas Micro-Conversiones & Tarjetas KeyHub
                    </div>
                    <button
                      type="button"
                      onClick={() => setActiveNav("pipeline")}
                      className="text-xs text-sky-300 hover:underline font-medium"
                    >
                      Abrir Pipeline Completo
                    </button>
                  </div>

                  {leads.length === 0 ? (
                    <div className="py-10 text-center text-xs text-white/45">
                      Sin solicitudes registradas aún en staging.
                    </div>
                  ) : (
                    <div className="divide-y divide-white/[0.06]">
                      {leads.slice(0, 5).map((item) => {
                        const st =
                          LEAD_STATUS_META[item.status] ||
                          LEAD_STATUS_META.NUEVO;
                        return (
                          <div
                            key={item.id}
                            onClick={() => {
                              setSelectedLead(item);
                              setActiveNav("pipeline");
                            }}
                            className="py-3 flex flex-wrap items-center justify-between gap-3 hover:bg-white/[0.03] px-2 rounded-lg cursor-pointer transition-colors"
                          >
                            <div className="min-w-0">
                              <div className="text-xs font-semibold text-white truncate">
                                {item.email}
                              </div>
                              <div className="text-[11px] text-white/50 truncate">
                                {item.service_name || "Consulta Ejecutiva"} ·{" "}
                                <span className="text-white/35">
                                  {item.order_ref}
                                </span>
                              </div>
                            </div>
                            <div className="flex items-center gap-2">
                              {item.keyhub_card_code && (
                                <span className="px-2 py-0.5 rounded-md text-[10px] font-mono font-bold bg-violet-400/20 text-violet-200 border border-violet-400/40">
                                  {item.keyhub_card_code}
                                </span>
                              )}
                              {item.pillar && (
                                <span
                                  className={`px-2 py-0.5 rounded-md text-[10px] font-semibold uppercase border ${pillarBadgeStyle(
                                    item.pillar
                                  )}`}
                                >
                                  {item.pillar}
                                </span>
                              )}
                              <span
                                className={`px-2 py-0.5 rounded-md text-[10px] font-semibold border ${st.badgeClass}`}
                              >
                                {st.label}
                              </span>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* =================================================================
              MODULE 2: DEMANDA & PUENTE KEYHUB
          ================================================================== */}
          {activeNav === "pipeline" && (
            <div className="space-y-5">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-sky-300">
                    Captura de 1 Clic · Sincronización Directa con KeyHub Producción
                  </div>
                  <h1
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-2xl font-bold text-white mt-1"
                  >
                    Demanda & Pipeline Corporativo
                  </h1>
                </div>

                <div className="flex items-center gap-2">
                  <div className="inline-flex p-1 rounded-xl bg-[#0D121C] border border-white/10">
                    <button
                      type="button"
                      onClick={() => setPipelineTab("leads")}
                      className={`px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-colors ${pipelineTab === "leads"
                          ? "bg-white text-[#0A0E15]"
                          : "text-white/65 hover:text-white"
                        }`}
                    >
                      Solicitudes ({filteredLeads.length})
                    </button>
                    <button
                      type="button"
                      onClick={() => setPipelineTab("scans")}
                      className={`px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-colors ${pipelineTab === "scans"
                          ? "bg-emerald-300 text-[#0A0E15]"
                          : "text-white/65 hover:text-white"
                        }`}
                    >
                      Escaneos BEC ({filteredScans.length})
                    </button>
                  </div>

                  <a
                    href={
                      pipelineTab === "leads"
                        ? "/api/admin/reports.php?type=leads_csv"
                        : "/api/admin/reports.php?type=scans_csv"
                    }
                    className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-white/[0.06] hover:bg-white/[0.12] border border-white/12 text-xs text-white/85 font-medium transition-colors"
                  >
                    <FileDown className="w-3.5 h-3.5 text-emerald-300" />
                    <span>CSV</span>
                  </a>

                  <button
                    type="button"
                    onClick={() => setNewLeadModal(true)}
                    className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-sky-400/15 hover:bg-sky-300 text-sky-200 hover:text-[#0A0E15] border border-sky-400/35 text-xs font-semibold transition-colors"
                  >
                    <Plus className="w-3.5 h-3.5" />
                    <span>Nueva</span>
                  </button>
                </div>
              </div>

              {pipelineTab === "leads" && (
                <>
                  <div className="flex flex-wrap items-center gap-1.5">
                    {["ALL", ...Object.keys(LEAD_STATUS_META)].map((stKey) => {
                      const active = statusFilter === stKey;
                      const label =
                        stKey === "ALL"
                          ? "Todos"
                          : LEAD_STATUS_META[stKey]?.label || stKey;
                      return (
                        <button
                          key={stKey}
                          type="button"
                          onClick={() => setStatusFilter(stKey)}
                          className={`px-3 py-1 rounded-lg text-[11px] font-semibold border transition-colors ${active
                              ? "bg-white/15 text-white border-white/30"
                              : "bg-white/[0.03] text-white/55 border-white/[0.07] hover:text-white"
                            }`}
                        >
                          {label}
                        </button>
                      );
                    })}
                  </div>

                  <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] overflow-hidden">
                    <div className="overflow-x-auto">
                      <table className="w-full text-left border-collapse">
                        <thead>
                          <tr className="border-b border-white/[0.08] text-[10px] font-bold uppercase tracking-[0.12em] text-white/45">
                            <th className="py-3.5 px-4">Ref / Fecha</th>
                            <th className="py-3.5 px-4">
                              Contacto & Organización
                            </th>
                            <th className="py-3.5 px-4">Pilar & Solución</th>
                            <th className="py-3.5 px-4">Postura BEC</th>
                            <th className="py-3.5 px-4">Estado / KeyHub</th>
                            <th className="py-3.5 px-4 text-right">Acción</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-white/[0.06] text-xs">
                          {filteredLeads.length === 0 ? (
                            <tr>
                              <td
                                colSpan={6}
                                className="py-10 text-center text-white/45"
                              >
                                Sin registros para el criterio actual.
                              </td>
                            </tr>
                          ) : (
                            filteredLeads.map((row) => {
                              const st =
                                LEAD_STATUS_META[row.status] ||
                                LEAD_STATUS_META.NUEVO;
                              return (
                                <tr
                                  key={row.id}
                                  className="hover:bg-white/[0.025] transition-colors"
                                >
                                  <td className="py-3.5 px-4 whitespace-nowrap">
                                    <div className="font-mono text-[11px] font-semibold text-white/90">
                                      {row.order_ref}
                                    </div>
                                    <div className="text-[10px] text-white/40">
                                      {row.created_at}
                                    </div>
                                  </td>
                                  <td className="py-3.5 px-4">
                                    <div className="font-semibold text-white">
                                      {row.email}
                                    </div>
                                    {row.domain && (
                                      <div className="text-[11px] text-white/45 flex items-center gap-1 mt-0.5">
                                        <Building2 className="w-3 h-3" />
                                        <span>{row.domain}</span>
                                      </div>
                                    )}
                                  </td>
                                  <td className="py-3.5 px-4">
                                    {row.pillar && (
                                      <span
                                        className={`inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase border mb-1 ${pillarBadgeStyle(
                                          row.pillar
                                        )}`}
                                      >
                                        {row.pillar}
                                      </span>
                                    )}
                                    <div className="text-white/85 font-medium">
                                      {row.service_name || "Consulta Directa"}
                                    </div>
                                    {row.price_info && (
                                      <div className="text-[11px] text-white/45">
                                        {row.price_info}
                                      </div>
                                    )}
                                  </td>
                                  <td className="py-3.5 px-4 whitespace-nowrap">
                                    {row.latest_bec_score !== null ? (
                                      <div>
                                        <span
                                          className={`font-bold ${scoreColorClass(
                                            row.latest_bec_score
                                          )}`}
                                        >
                                          {row.latest_bec_score}/100
                                        </span>
                                        <div className="text-[10px] text-white/45">
                                          {row.latest_dmarc}
                                        </div>
                                      </div>
                                    ) : row.domain ? (
                                      <button
                                        type="button"
                                        onClick={() =>
                                          handleSecopsScan(row.domain || "")
                                        }
                                        className="inline-flex items-center gap-1 text-[11px] text-emerald-300 hover:underline"
                                      >
                                        <Radar className="w-3 h-3" />
                                        <span>Auditar DNS</span>
                                      </button>
                                    ) : (
                                      <span className="text-white/30">—</span>
                                    )}
                                  </td>
                                  <td className="py-3.5 px-4 whitespace-nowrap space-y-1">
                                    <span
                                      className={`inline-block px-2.5 py-0.5 rounded-md text-[10px] font-semibold border ${st.badgeClass}`}
                                    >
                                      {st.label}
                                    </span>
                                    {row.keyhub_card_code && (
                                      <div className="text-[10px] font-mono font-bold text-violet-300">
                                        KeyHub: {row.keyhub_card_code}
                                      </div>
                                    )}
                                  </td>
                                  <td className="py-3.5 px-4 text-right whitespace-nowrap">
                                    <button
                                      type="button"
                                      onClick={() => setSelectedLead({ ...row })}
                                      className="px-3 py-1.5 rounded-lg bg-white/[0.06] hover:bg-white text-white hover:text-[#0A0E15] text-[11px] font-semibold transition-colors"
                                    >
                                      Gestionar
                                    </button>
                                  </td>
                                </tr>
                              );
                            })
                          )}
                        </tbody>
                      </table>
                    </div>
                  </div>
                </>
              )}

              {pipelineTab === "scans" && (
                <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] overflow-hidden">
                  <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                      <thead>
                        <tr className="border-b border-white/[0.08] text-[10px] font-bold uppercase tracking-[0.12em] text-white/45">
                          <th className="py-3.5 px-4">Ref / Fecha</th>
                          <th className="py-3.5 px-4">Dominio Evaluado</th>
                          <th className="py-3.5 px-4">Score</th>
                          <th className="py-3.5 px-4">Política DMARC</th>
                          <th className="py-3.5 px-4">SPF</th>
                          <th className="py-3.5 px-4 text-right">Re-Escanear</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-white/[0.06] text-xs">
                        {filteredScans.length === 0 ? (
                          <tr>
                            <td
                              colSpan={6}
                              className="py-10 text-center text-white/45"
                            >
                              Sin escaneos BEC registrados.
                            </td>
                          </tr>
                        ) : (
                          filteredScans.map((s) => (
                            <tr
                              key={s.id}
                              className="hover:bg-white/[0.025] transition-colors"
                            >
                              <td className="py-3.5 px-4 whitespace-nowrap">
                                <div className="font-mono text-[11px] font-semibold text-emerald-300">
                                  {s.scan_ref}
                                </div>
                                <div className="text-[10px] text-white/40">
                                  {s.created_at}
                                </div>
                              </td>
                              <td className="py-3.5 px-4 font-semibold text-white">
                                {s.domain}
                              </td>
                              <td className="py-3.5 px-4">
                                <span
                                  className={`font-bold ${scoreColorClass(
                                    s.score
                                  )}`}
                                >
                                  {s.score}/100
                                </span>
                                <span className="text-[11px] text-white/45 ml-2">
                                  ({s.risk_level})
                                </span>
                              </td>
                              <td className="py-3.5 px-4 text-white/80">
                                {s.dmarc_label || "—"}
                              </td>
                              <td className="py-3.5 px-4 text-white/70">
                                {s.spf_status || "—"}
                              </td>
                              <td className="py-3.5 px-4 text-right space-x-2 whitespace-nowrap">
                                <a
                                  href={`/api/admin/reports.php?type=bec_pdf&id=${s.id}`}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white/[0.06] hover:bg-white text-white hover:text-[#0A0E15] text-[11px] font-semibold transition-colors"
                                >
                                  <FileDown className="w-3 h-3" />
                                  <span>PDF</span>
                                </a>
                                <button
                                  type="button"
                                  onClick={() => {
                                    setSecopsDomainInput(s.domain);
                                    setActiveNav("secops");
                                    setSecopsTab("scanner");
                                    handleSecopsScan(s.domain);
                                  }}
                                  className="px-3 py-1.5 rounded-lg bg-emerald-400/15 hover:bg-emerald-300 text-emerald-200 hover:text-[#0A0E15] text-[11px] font-semibold transition-colors"
                                >
                                  Inspeccionar
                                </button>
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* =================================================================
              MODULE 3: GOBIERNO DEL SITIO & OFERTA EN VIVO
          ================================================================== */}
          {activeNav === "studio" && (
            <div className="space-y-6">
              <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                  <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-violet-300">
                    CMS Espacial sin Recompilación · Publicación Instantánea
                  </div>
                  <h1
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-2xl font-bold text-white mt-1"
                  >
                    Gobierno del Sitio & Catálogo de Pilares
                  </h1>
                  {studioUpdatedAt && (
                    <p className="text-[11px] text-white/45 mt-0.5">
                      Última publicación activa: {studioUpdatedAt}
                    </p>
                  )}
                </div>

                <div className="flex items-center gap-2.5">
                  <button
                    type="button"
                    onClick={handleResetStudio}
                    disabled={studioSaving}
                    className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white/[0.05] hover:bg-white/[0.1] border border-white/12 text-xs text-white/75 transition-colors"
                  >
                    <RotateCcw className="w-3.5 h-3.5" />
                    <span>Restaurar Base</span>
                  </button>
                  <button
                    type="button"
                    onClick={handleSaveStudio}
                    disabled={studioSaving}
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-violet-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors disabled:opacity-60"
                  >
                    {studioSaving ? (
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    ) : (
                      <Save className="w-3.5 h-3.5" />
                    )}
                    <span>Publicar en Vivo</span>
                  </button>
                </div>
              </div>

              <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                  <div
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-xs font-bold uppercase tracking-[0.12em] text-white/80"
                  >
                    Atmósfera 3D de Cordillera por Defecto
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    {(Object.keys(SPATIAL_THEMES) as SpatialThemeId[]).map(
                      (tKey) => {
                        const th = SPATIAL_THEMES[tKey];
                        const selected = studioConfig.defaultTheme === tKey;
                        return (
                          <button
                            key={tKey}
                            type="button"
                            onClick={() =>
                              setStudioConfig((prev) => ({
                                ...prev,
                                defaultTheme: tKey,
                              }))
                            }
                            className={`p-3 rounded-xl border text-left transition-all ${selected
                                ? "bg-violet-500/15 border-violet-300/60"
                                : "bg-white/[0.03] border-white/10 hover:border-white/25"
                              }`}
                          >
                            <div className="flex items-center gap-2">
                              <span
                                className="w-3.5 h-3.5 rounded-full border border-white/30"
                                style={{ backgroundColor: th.skyCss }}
                              />
                              <span className="text-xs font-semibold text-white truncate">
                                {th.label}
                              </span>
                            </div>
                            <p className="text-[10px] text-white/45 mt-1.5 line-clamp-2">
                              {th.description}
                            </p>
                          </button>
                        );
                      }
                    )}
                  </div>

                  <label className="flex items-center justify-between pt-2 border-t border-white/[0.07] cursor-pointer">
                    <div>
                      <div className="text-xs font-medium text-white">
                        Mostrar selector de atmósfera en barra superior pública
                      </div>
                      <div className="text-[11px] text-white/45">
                        Permite al visitante alternar entre los 3 presets de
                        montaña.
                      </div>
                    </div>
                    <input
                      type="checkbox"
                      checked={studioConfig.allowPublicThemeSwitcher}
                      onChange={(e) =>
                        setStudioConfig((prev) => ({
                          ...prev,
                          allowPublicThemeSwitcher: e.target.checked,
                        }))
                      }
                      className="w-4 h-4 accent-violet-400"
                    />
                  </label>
                </div>

                <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3.5">
                  <div
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-xs font-bold uppercase tracking-[0.12em] text-white/80"
                  >
                    Canales Directos de Cabecera
                  </div>

                  <div>
                    <label className="block text-[11px] text-white/55 mb-1">
                      Correo Ejecutivo Público
                    </label>
                    <input
                      type="email"
                      value={studioConfig.contactEmail}
                      onChange={(e) =>
                        setStudioConfig((prev) => ({
                          ...prev,
                          contactEmail: e.target.value,
                        }))
                      }
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-[11px] text-white/55 mb-1">
                        WhatsApp (Solo dígitos wa.me)
                      </label>
                      <input
                        type="text"
                        value={studioConfig.whatsappNumber}
                        onChange={(e) =>
                          setStudioConfig((prev) => ({
                            ...prev,
                            whatsappNumber: e.target.value,
                          }))
                        }
                        className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                    </div>
                    <div>
                      <label className="block text-[11px] text-white/55 mb-1">
                        Etiqueta Visible WhatsApp
                      </label>
                      <input
                        type="text"
                        value={studioConfig.whatsappDisplay}
                        onChange={(e) =>
                          setStudioConfig((prev) => ({
                            ...prev,
                            whatsappDisplay: e.target.value,
                          }))
                        }
                        className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                    </div>
                  </div>
                </div>
              </div>

              <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-white/[0.08] pb-4">
                  <div className="flex items-center gap-2">
                    {[
                      {
                        idx: 1,
                        name: "1 · Digital Core (CRM)",
                        activeCls: "bg-sky-300 text-[#0A0E15]",
                      },
                      {
                        idx: 2,
                        name: "2 · DeepShield (DSX)",
                        activeCls: "bg-emerald-300 text-[#0A0E15]",
                      },
                      {
                        idx: 3,
                        name: "3 · KeyHub (KHS)",
                        activeCls: "bg-violet-300 text-[#0A0E15]",
                      },
                    ].map((tab) => (
                      <button
                        key={tab.idx}
                        type="button"
                        onClick={() => setStudioSelectedPillar(tab.idx)}
                        style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                        className={`px-3.5 py-2 rounded-xl text-xs font-bold transition-all ${studioSelectedPillar === tab.idx
                            ? tab.activeCls
                            : "bg-white/[0.05] text-white/70 hover:text-white"
                          }`}
                      >
                        {tab.name}
                      </button>
                    ))}
                  </div>
                </div>

                {editingPillar && (
                  <div className="space-y-5">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div>
                        <label className="block text-[11px] text-white/55 mb-1">
                          Titular de Arena del Pilar (Subtítulo superior)
                        </label>
                        <input
                          type="text"
                          value={editingPillar.headline || ""}
                          onChange={(e) =>
                            updateStudioPillarField(
                              editingPillar.chapterIndex,
                              "headline",
                              e.target.value
                            )
                          }
                          className="w-full px-3.5 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                        />
                      </div>
                      <div>
                        <label className="block text-[11px] text-white/55 mb-1">
                          Texto Botón de Acción sobre Escultura 3D
                        </label>
                        <input
                          type="text"
                          value={editingPillar.clickHint || ""}
                          onChange={(e) =>
                            updateStudioPillarField(
                              editingPillar.chapterIndex,
                              "clickHint",
                              e.target.value
                            )
                          }
                          className="w-full px-3.5 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                        />
                      </div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      {(editingPillar.cards || []).map((card) => (
                        <div
                          key={card.id}
                          className="rounded-xl bg-[#080B11] border border-white/10 p-4 space-y-3"
                        >
                          <div className="flex items-center justify-between">
                            <span className="font-mono text-[10px] text-white/40">
                              ID: {card.id}
                            </span>
                            {card.isFeatured && (
                              <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase text-violet-300">
                                <Sparkles className="w-3 h-3" />
                                Destacada
                              </span>
                            )}
                          </div>

                          <div className="grid grid-cols-2 gap-2.5">
                            <div>
                              <label className="block text-[10px] uppercase text-white/45 mb-1">
                                Título 1 (Centrado)
                              </label>
                              <input
                                type="text"
                                value={card.tag}
                                onChange={(e) =>
                                  updateStudioCardField(
                                    editingPillar.chapterIndex,
                                    card.id,
                                    "tag",
                                    e.target.value
                                  )
                                }
                                className="w-full px-2.5 py-1.5 rounded-lg bg-white/[0.05] border border-white/12 text-xs text-white"
                              />
                            </div>
                            <div>
                              <label className="block text-[10px] uppercase text-white/45 mb-1">
                                Subtítulo (MAYÚSCULAS)
                              </label>
                              <input
                                type="text"
                                value={card.title}
                                onChange={(e) =>
                                  updateStudioCardField(
                                    editingPillar.chapterIndex,
                                    card.id,
                                    "title",
                                    e.target.value
                                  )
                                }
                                className="w-full px-2.5 py-1.5 rounded-lg bg-white/[0.05] border border-white/12 text-xs text-white font-semibold"
                              />
                            </div>
                          </div>

                          <div>
                            <label className="block text-[10px] uppercase text-white/45 mb-1">
                              Promesa Ejecutiva
                            </label>
                            <textarea
                              rows={2}
                              value={card.description}
                              onChange={(e) =>
                                updateStudioCardField(
                                  editingPillar.chapterIndex,
                                  card.id,
                                  "description",
                                  e.target.value
                                )
                              }
                              className="w-full px-2.5 py-1.5 rounded-lg bg-white/[0.05] border border-white/12 text-xs text-white resize-none"
                            />
                          </div>

                          <div className="grid grid-cols-3 gap-2">
                            <div>
                              <label className="block text-[10px] uppercase text-white/45 mb-1">
                                Precio
                              </label>
                              <input
                                type="text"
                                value={card.priceHighlight}
                                onChange={(e) =>
                                  updateStudioCardField(
                                    editingPillar.chapterIndex,
                                    card.id,
                                    "priceHighlight",
                                    e.target.value
                                  )
                                }
                                className="w-full px-2.5 py-1.5 rounded-lg bg-white/[0.05] border border-white/12 text-xs text-white"
                              />
                            </div>
                            <div>
                              <label className="block text-[10px] uppercase text-white/45 mb-1">
                                Nota Precio
                              </label>
                              <input
                                type="text"
                                value={card.priceNote || ""}
                                onChange={(e) =>
                                  updateStudioCardField(
                                    editingPillar.chapterIndex,
                                    card.id,
                                    "priceNote",
                                    e.target.value
                                  )
                                }
                                className="w-full px-2.5 py-1.5 rounded-lg bg-white/[0.05] border border-white/12 text-xs text-white"
                              />
                            </div>
                            <div>
                              <label className="block text-[10px] uppercase text-white/45 mb-1">
                                Texto CTA
                              </label>
                              <input
                                type="text"
                                value={card.ctaLabel}
                                onChange={(e) =>
                                  updateStudioCardField(
                                    editingPillar.chapterIndex,
                                    card.id,
                                    "ctaLabel",
                                    e.target.value
                                  )
                                }
                                className="w-full px-2.5 py-1.5 rounded-lg bg-white/[0.05] border border-white/12 text-xs text-white"
                              />
                            </div>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>

              {/* MARQUEE PARTNERS & CERTIFICATIONS LIVE EDITOR */}
              <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <div
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="text-xs font-bold uppercase tracking-[0.12em] text-white/80"
                    >
                      Cinta Inferior de Partners & Certificaciones (Marquee)
                    </div>
                    <p className="text-[11px] text-white/45">
                      Agregue o retire insignias corporativas al pie de las montañas (recuerde pulsar &ldquo;Publicar en Vivo&rdquo; arriba).
                    </p>
                  </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  <input
                    type="text"
                    value={mqName}
                    onChange={(e) => setMqName(e.target.value)}
                    placeholder="INSIGNIA (ej: SOC 2 TYPE II)"
                    className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                  />
                  <input
                    type="text"
                    value={mqRole}
                    onChange={(e) => setMqRole(e.target.value)}
                    placeholder="Subtítulo / Rol"
                    className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                  />
                  <input
                    type="text"
                    value={mqHref}
                    onChange={(e) => setMqHref(e.target.value)}
                    placeholder="URL opcional (https://...)"
                    className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                  />
                  <button
                    type="button"
                    onClick={() => {
                      if (!mqName.trim()) return;
                      const list: PartnerMarqueeItem[] = [
                        ...(studioConfig.partnersMarquee ||
                          DEFAULT_PARTNERS_MARQUEE),
                        {
                          name: mqName.trim().toUpperCase(),
                          role: mqRole.trim() || "Partner Estratégico",
                          icon: "shield",
                          href: mqHref.trim() || undefined,
                        },
                      ];
                      setStudioConfig((prev) => ({
                        ...prev,
                        partnersMarquee: list,
                      }));
                      setMqName("");
                      setMqRole("");
                      setMqHref("");
                    }}
                    className="px-3.5 py-2 rounded-xl bg-violet-400/20 hover:bg-violet-300 text-violet-200 hover:text-[#0A0E15] text-xs font-bold transition-colors inline-flex items-center gap-1"
                  >
                    <Plus className="w-3.5 h-3.5" />
                    <span>Añadir a Cinta</span>
                  </button>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5">
                  {(
                    studioConfig.partnersMarquee || DEFAULT_PARTNERS_MARQUEE
                  ).map((item, idx) => (
                    <div
                      key={`${item.name}-${idx}`}
                      className="p-3 rounded-xl bg-[#080B11] border border-white/10 flex items-center justify-between gap-2 text-xs"
                    >
                      <div className="min-w-0">
                        <div className="font-bold text-white truncate">
                          {item.name}
                        </div>
                        <div className="text-[10px] text-white/45 truncate">
                          {item.role}
                        </div>
                      </div>
                      <button
                        type="button"
                        onClick={() => {
                          const next = (
                            studioConfig.partnersMarquee ||
                            DEFAULT_PARTNERS_MARQUEE
                          ).filter((_, i) => i !== idx);
                          setStudioConfig((prev) => ({
                            ...prev,
                            partnersMarquee: next,
                          }));
                        }}
                        className="text-white/35 hover:text-rose-400 shrink-0"
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}

          {/* =================================================================
              MODULE 4: SUITE INTEGRAL DEEPSHIELD (4 SUB-TABS)
          ================================================================== */}
          {activeNav === "secops" && (
            <div className="space-y-6">
              <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                  <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-emerald-300">
                    Defensa Cibernética Zero Trust · Ley N° 21.459 & ISO 27001
                  </div>
                  <h1
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-2xl font-bold text-white mt-1"
                  >
                    Suite Operativa DeepShield
                  </h1>
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    <button
                      type="button"
                      onClick={() => setSopModalOpen(true)}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-400/15 hover:bg-sky-300 text-sky-200 hover:text-[#0A0E15] border border-sky-400/35 text-[11px] font-bold transition-colors"
                    >
                      <BookOpen className="w-3.5 h-3.5" />
                      <span>Manual DMARC (SOP-SEC-DMARC-01)</span>
                    </button>
                    <button
                      type="button"
                      onClick={() => setTreasurySopModalOpen(true)}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-400/15 hover:bg-emerald-300 text-emerald-200 hover:text-[#0A0E15] border border-emerald-400/35 text-[11px] font-bold transition-colors"
                    >
                      <Landmark className="w-3.5 h-3.5" />
                      <span>Manual Treasury (SOP-SEC-TREASURY-01)</span>
                    </button>
                  </div>
                </div>

                <div className="inline-flex flex-wrap p-1 rounded-xl bg-[#0D121C] border border-white/10 gap-1">
                  {[
                    {
                      id: "scanner",
                      label: "Radar BEC & Watchdog",
                      icon: Radar,
                      roles: ["superadmin", "secops", "comercial"],
                    },
                    {
                      id: "treasury",
                      label: `Treasury OOB (${treasuryOrders.length})`,
                      icon: Landmark,
                      roles: ["superadmin", "secops"],
                    },
                    {
                      id: "tenants",
                      label: `Tenants & QR (${dsTenants.length})`,
                      icon: Building2,
                      roles: ["superadmin", "secops"],
                    },
                    {
                      id: "dmarc",
                      label: `DMARC RUA (${dmarcReports.length})`,
                      icon: FileCode2,
                      roles: ["superadmin", "secops"],
                    },
                    {
                      id: "redteam",
                      label: `Red Teaming (${redteamCampaigns.length})`,
                      icon: Crosshair,
                      roles: ["superadmin", "secops", "comercial"],
                    },
                    {
                      id: "vault",
                      label: `Bóveda & Plantillas (${rtTemplates.length})`,
                      icon: BookOpen,
                      roles: ["superadmin", "secops"],
                    },
                  ]
                    .filter(
                      (tb) =>
                        currentUser.role === "superadmin" ||
                        tb.roles.includes(currentUser.role)
                    )
                    .map((tb) => {
                      const TIcon = tb.icon;
                      const active = secopsTab === tb.id;
                      return (
                        <button
                          key={tb.id}
                          type="button"
                          onClick={() =>
                            setSecopsTab(
                              tb.id as
                              | "scanner"
                              | "treasury"
                              | "tenants"
                              | "dmarc"
                              | "redteam"
                              | "vault"
                            )
                          }
                          className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${active
                              ? "bg-emerald-300 text-[#0A0E15]"
                              : "text-white/65 hover:text-white"
                            }`}
                        >
                          <TIcon className="w-3.5 h-3.5" />
                          <span>{tb.label}</span>
                        </button>
                      );
                    })}
                </div>
              </div>

              {/* SUB-TAB A: SCANNER + TRUSTZERO + WATCHDOG */}
              {secopsTab === "scanner" && (
                <>
                  <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                      <div
                        style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                        className="text-xs font-bold uppercase tracking-[0.12em] text-emerald-300"
                      >
                        Consola de Diagnóstico DNS / BEC en Tiempo Real
                      </div>

                      <form
                        onSubmit={(e) => {
                          e.preventDefault();
                          handleSecopsScan();
                        }}
                        className="flex items-center gap-2"
                      >
                        <input
                          type="text"
                          value={secopsDomainInput}
                          onChange={(e) => setSecopsDomainInput(e.target.value)}
                          placeholder="ejemplo: bancoempresa.cl"
                          className="flex-1 px-3.5 py-2.5 rounded-xl bg-white/[0.04] border border-white/15 text-xs text-white placeholder:text-white/35 focus:outline-none focus:border-emerald-300"
                        />
                        <button
                          type="submit"
                          disabled={secopsScanBusy}
                          style={{
                            fontFamily: "var(--font-jakarta), sans-serif",
                          }}
                          className="px-4 py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors flex items-center gap-1.5 disabled:opacity-60"
                        >
                          {secopsScanBusy ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <Radar className="w-3.5 h-3.5" />
                          )}
                          <span>Auditar</span>
                        </button>
                      </form>

                      {secopsScanResult && (
                        <div className="rounded-xl bg-[#080B11] border border-white/10 p-4 space-y-3">
                          <div className="flex items-center justify-between">
                            <div>
                              <div className="text-sm font-bold text-white">
                                {secopsScanResult.domain}
                              </div>
                              <div className="text-[11px] text-white/50">
                                {secopsScanResult.risk_level}
                              </div>
                            </div>
                            <div
                              className={`text-2xl font-extrabold ${scoreColorClass(
                                secopsScanResult.score
                              )}`}
                            >
                              {secopsScanResult.score}/100
                            </div>
                          </div>

                          <div className="grid grid-cols-2 gap-2 text-[11px]">
                            <div className="p-2 rounded-lg bg-white/[0.03] border border-white/[0.06]">
                              <span className="text-white/45 block">DMARC</span>
                              <span className="text-white font-medium">
                                {secopsScanResult.dmarc}
                              </span>
                            </div>
                            <div className="p-2 rounded-lg bg-white/[0.03] border border-white/[0.06]">
                              <span className="text-white/45 block">SPF</span>
                              <span className="text-white font-medium">
                                {secopsScanResult.spf}
                              </span>
                            </div>
                          </div>

                          <div className="space-y-1.5">
                            {secopsScanResult.findings.map((f, idx) => (
                              <div
                                key={idx}
                                className="text-[11px] text-white/75 bg-white/[0.03] rounded-lg px-3 py-2 border border-white/[0.06]"
                              >
                                <strong className="text-white">
                                  {f.title}:
                                </strong>{" "}
                                {f.desc}
                              </div>
                            ))}
                          </div>
                        </div>
                      )}
                    </div>

                    <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                      <div className="flex items-center justify-between">
                        <div
                          style={{
                            fontFamily: "var(--font-jakarta), sans-serif",
                          }}
                          className="text-xs font-bold uppercase tracking-[0.12em] text-white/80"
                        >
                          Telemetría Propia TrustZero
                        </div>
                        {trustZero && (
                          <span className="px-2.5 py-0.5 rounded-full bg-emerald-400/15 border border-emerald-400/30 text-xs font-bold text-emerald-300">
                            {trustZero.trust_score ??
                              trustZero.global_score ??
                              100}
                            % ·{" "}
                            {trustZero.status_label ??
                              trustZero.overall_status ??
                              "ÓPTIMO"}
                          </span>
                        )}
                      </div>

                      {trustZero ? (
                        <div className="space-y-2 max-h-72 overflow-y-auto pr-1">
                          {Object.entries(trustZero.controls).map(
                            ([key, ctrl]) => (
                              <div
                                key={key}
                                className="p-3 rounded-xl bg-[#080B11] border border-white/[0.07] flex items-start justify-between gap-3"
                              >
                                <div>
                                  <div className="text-xs font-semibold text-white">
                                    {ctrl.name}
                                  </div>
                                  <div className="text-[11px] text-white/50 mt-0.5">
                                    {ctrl.desc}
                                  </div>
                                </div>
                                <span
                                  className={`px-2 py-0.5 rounded text-[10px] font-bold shrink-0 ${ctrl.status === "PASS"
                                      ? "bg-emerald-400/15 text-emerald-300"
                                      : "bg-amber-400/15 text-amber-300"
                                    }`}
                                >
                                  {ctrl.status}
                                </span>
                              </div>
                            )
                          )}
                        </div>
                      ) : (
                        <div className="text-xs text-white/45 py-8 text-center">
                          Cargando postura TrustZero...
                        </div>
                      )}
                    </div>
                  </div>

                  {/* WATCHED DOMAINS */}
                  <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div
                        style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                        className="text-xs font-bold uppercase tracking-[0.12em] text-white/80"
                      >
                        Dominios Corporativos Bajo Vigilancia Watchdog
                      </div>

                      <form
                        onSubmit={handleAddWatchedDomain}
                        className="flex flex-wrap items-center gap-2"
                      >
                        <input
                          type="text"
                          required
                          value={watchClientInput}
                          onChange={(e) => setWatchClientInput(e.target.value)}
                          placeholder="Razón Social / Cliente"
                          className="px-3 py-1.5 rounded-lg bg-white/[0.04] border border-white/12 text-xs text-white"
                        />
                        <input
                          type="text"
                          required
                          value={watchDomainInput}
                          onChange={(e) => setWatchDomainInput(e.target.value)}
                          placeholder="dominio.cl"
                          className="px-3 py-1.5 rounded-lg bg-white/[0.04] border border-white/12 text-xs text-white"
                        />
                        <select
                          value={watchTierInput}
                          onChange={(e) => setWatchTierInput(e.target.value)}
                          className="px-3 py-1.5 rounded-lg bg-[#080B11] border border-white/12 text-xs text-white"
                        >
                          <option value="Starter">Starter</option>
                          <option value="Pro">Pro</option>
                          <option value="Enterprise">Enterprise</option>
                        </select>
                        <button
                          type="submit"
                          className="px-3 py-1.5 rounded-lg bg-emerald-400/20 hover:bg-emerald-300 text-emerald-200 hover:text-[#0A0E15] border border-emerald-400/35 text-xs font-semibold transition-colors inline-flex items-center gap-1"
                        >
                          <Globe className="w-3.5 h-3.5" />
                          <span>Agregar</span>
                        </button>
                      </form>
                    </div>

                    <div className="overflow-x-auto">
                      <table className="w-full text-left border-collapse text-xs">
                        <thead>
                          <tr className="border-b border-white/[0.08] text-[10px] uppercase tracking-[0.12em] text-white/45">
                            <th className="py-3 px-3">Cliente</th>
                            <th className="py-3 px-3">Dominio</th>
                            <th className="py-3 px-3">Plan</th>
                            <th className="py-3 px-3">Sello Trust</th>
                            <th className="py-3 px-3">Score</th>
                            <th className="py-3 px-3">DMARC</th>
                            <th className="py-3 px-3 text-right">Acciones</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-white/[0.06]">
                          {watchedDomains.map((w) => (
                            <tr key={w.id} className="hover:bg-white/[0.02]">
                              <td className="py-3 px-3 font-semibold text-white">
                                {w.client_name}
                              </td>
                              <td className="py-3 px-3 font-mono text-emerald-300">
                                {w.domain}
                              </td>
                              <td className="py-2.5 px-3">
                                {w.domain === "keystoneadvisors.cl" ? (
                                  <span className="text-white/65 text-xs font-medium">
                                    {w.tier}
                                  </span>
                                ) : (
                                  <select
                                    value={w.tier}
                                    onChange={(e) =>
                                      handleUpdateWatchedTier(w.id, e.target.value)
                                    }
                                    className="px-2.5 py-1 rounded-lg bg-[#080B11] border border-white/15 text-xs text-emerald-300 font-semibold focus:outline-none focus:border-emerald-300 cursor-pointer transition-colors"
                                  >
                                    <option value="Starter">Starter</option>
                                    <option value="Pro">Pro</option>
                                    <option value="Enterprise">Enterprise</option>
                                  </select>
                                )}
                              </td>
                              <td className="py-3 px-3">
                                {w.badge_installed === 1 ? (
                                  <div className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-400/15 border border-emerald-400/30 text-emerald-300 text-[11px] font-semibold" title={`Última impresión: ${w.badge_last_seen_at || 'Reciente'}\nOrigen: ${w.badge_last_origin || 'Web'}\nTotal impresiones: ${w.badge_impressions || 1}`}>
                                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                                    <span>Activo en Web</span>
                                    {(w.badge_impressions ?? 0) > 0 && (
                                      <span className="text-white/45 text-[10px]">
                                        ({w.badge_impressions})
                                      </span>
                                    )}
                                  </div>
                                ) : (
                                  <div className="inline-flex items-center gap-1 text-white/40 text-[11px]" title="El cliente aún no inserta el código del badge en su sitio web">
                                    <span className="w-1.5 h-1.5 rounded-full bg-white/20" />
                                    <span>Pendiente</span>
                                  </div>
                                )}
                              </td>
                              <td className="py-3 px-3">
                                <span
                                  className={`font-bold ${scoreColorClass(
                                    w.last_score
                                  )}`}
                                >
                                  {w.last_score}/100
                                </span>
                              </td>
                              <td className="py-3 px-3 text-white/80">
                                {w.dmarc_policy}
                              </td>
                              <td className="py-3 px-3 text-right space-x-2">
                                <button
                                  type="button"
                                  onClick={() => handleSecopsScan(w.domain)}
                                  className="px-2.5 py-1 rounded bg-white/[0.06] hover:bg-white text-white hover:text-[#0A0E15] text-[11px] font-medium transition-colors"
                                >
                                  Verificar
                                </button>
                                {w.domain !== "keystoneadvisors.cl" && (
                                  <button
                                    type="button"
                                    onClick={() => handleRemoveWatched(w.id)}
                                    className="p-1 text-white/40 hover:text-rose-400 transition-colors"
                                  >
                                    <Trash2 className="w-3.5 h-3.5 inline" />
                                  </button>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                </>
              )}

              {/* SUB-TAB B: DEEPSHIELD TREASURY OOB */}
              {secopsTab === "treasury" && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
                  <form
                    onSubmit={handleCreateTreasuryOrder}
                    className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3"
                  >
                    <div
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="text-xs font-bold uppercase tracking-[0.12em] text-emerald-300"
                    >
                      Emitir Orden de Pago OOB Anti-Fraude
                    </div>

                    <input
                      type="text"
                      required
                      value={trCompany}
                      onChange={(e) => setTrCompany(e.target.value)}
                      placeholder="Empresa Ordenante"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                    <div className="grid grid-cols-3 gap-2">
                      <select
                        value={trCurrency}
                        onChange={(e) => setTrCurrency(e.target.value)}
                        className="px-2.5 py-2 rounded-xl bg-[#080B11] border border-white/12 text-xs text-white"
                      >
                        <option value="CLP">CLP</option>
                        <option value="USD">USD</option>
                      </select>
                      <input
                        type="number"
                        required
                        value={trAmount}
                        onChange={(e) => setTrAmount(e.target.value)}
                        placeholder="Monto"
                        className="col-span-2 px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                    </div>
                    <input
                      type="text"
                      required
                      value={trBeneficiary}
                      onChange={(e) => setTrBeneficiary(e.target.value)}
                      placeholder="Razón Social Beneficiario"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                    <input
                      type="text"
                      required
                      value={trRut}
                      onChange={(e) => setTrRut(e.target.value)}
                      placeholder="RUT / Tax ID Beneficiario"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                    <div className="grid grid-cols-2 gap-2">
                      <input
                        type="text"
                        required
                        value={trBank}
                        onChange={(e) => setTrBank(e.target.value)}
                        placeholder="Banco"
                        className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                      <input
                        type="text"
                        required
                        value={trAccount}
                        onChange={(e) => setTrAccount(e.target.value)}
                        placeholder="N° Cuenta"
                        className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                    </div>
                    <input
                      type="text"
                      value={trApproverName}
                      onChange={(e) => setTrApproverName(e.target.value)}
                      placeholder="Nombre Firmante (Ej: CFO)"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                    <input
                      type="text"
                      value={trApproverPhone}
                      onChange={(e) => setTrApproverPhone(e.target.value)}
                      placeholder="WhatsApp OOB Firmante (ej: 569...)"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />

                    <button
                      type="submit"
                      disabled={trBusy}
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="w-full py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
                    >
                      {trBusy
                        ? "Emitiendo..."
                        : "Generar Desafío Anti-Voz & Enviar OOB"}
                    </button>
                  </form>

                  <div className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                    <div
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="text-xs font-bold uppercase tracking-[0.12em] text-white/80"
                    >
                      Libro Criptográfico de Órdenes Treasury
                    </div>

                    {treasuryOrders.length === 0 ? (
                      <div className="py-12 text-center text-xs text-white/45">
                        No hay órdenes de custodia registradas en este ambiente.
                      </div>
                    ) : (
                      <div className="space-y-2.5">
                        {treasuryOrders.map((ord) => (
                          <div
                            key={ord.id}
                            className="p-4 rounded-xl bg-[#080B11] border border-white/10 flex flex-wrap items-center justify-between gap-3 text-xs"
                          >
                            <div className="space-y-1">
                              <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono font-bold text-emerald-300">
                                  {ord.order_code}
                                </span>
                                <span className="px-2 py-0.5 rounded bg-violet-400/15 text-violet-200 font-mono text-[10px]">
                                  Desafío Voz: {ord.security_challenge}
                                </span>
                                {ord.whitelist_status === "VERIFIED" && (
                                  <span className="px-2 py-0.5 rounded bg-emerald-400/15 text-emerald-300 text-[10px] font-bold">
                                    PADRÓN OK
                                  </span>
                                )}
                                {ord.whitelist_status === "ACCOUNT_MISMATCH" && (
                                  <span className="px-2 py-0.5 rounded bg-rose-500/25 text-rose-200 border border-rose-400/40 text-[10px] font-bold">
                                    ALERTA DISCREPANCIA CTA
                                  </span>
                                )}
                                {(ord.required_approvals || 1) > 1 && (
                                  <span className="px-2 py-0.5 rounded bg-sky-400/15 text-sky-200 text-[10px] font-bold">
                                    {ord.approval_count || 0}/{ord.required_approvals} FIRMAS
                                  </span>
                                )}
                              </div>
                              <div className="font-semibold text-white">
                                {ord.company_name} → {ord.beneficiary_name} (
                                {ord.currency}{" "}
                                {Number(ord.amount).toLocaleString("es-CL")})
                              </div>
                              <div className="text-[11px] text-white/45">
                                {ord.bank_name} · Cuenta {ord.account_number}
                              </div>
                            </div>

                            <div className="flex items-center gap-2">
                              <a
                                href={`/api/admin/reports.php?type=treasury_pdf&id=${ord.id}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="px-2.5 py-1.5 rounded-lg bg-white/[0.06] hover:bg-white text-white hover:text-[#0A0E15] font-semibold inline-flex items-center gap-1"
                              >
                                <FileDown className="w-3 h-3" />
                                <span>PDF</span>
                              </a>
                              {ord.status === "PENDING_APPROVAL" ? (
                                <>
                                  <button
                                    type="button"
                                    onClick={() =>
                                      handleResolveTreasury(ord.id, "APPROVE")
                                    }
                                    className="px-3 py-1.5 rounded-lg bg-emerald-400/20 hover:bg-emerald-300 text-emerald-200 hover:text-[#0A0E15] font-semibold"
                                  >
                                    Firmar SHA-256
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() =>
                                      handleResolveTreasury(ord.id, "REJECT")
                                    }
                                    className="px-3 py-1.5 rounded-lg bg-rose-500/20 hover:bg-rose-400 text-rose-200 hover:text-[#0A0E15] font-semibold"
                                  >
                                    Bloquear
                                  </button>
                                </>
                              ) : (
                                <span
                                  className={`px-2.5 py-1 rounded-md text-[10px] font-bold uppercase ${ord.status === "APPROVED"
                                      ? "bg-emerald-400/15 text-emerald-300"
                                      : "bg-rose-500/15 text-rose-300"
                                    }`}
                                >
                                  {ord.status}
                                </span>
                              )}
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* SUB-TAB TENANTS & WHATSAPP QR */}
              {secopsTab === "tenants" && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
                  <form
                    onSubmit={async (e) => {
                      e.preventDefault();
                      const cleanSlug =
                        tenSlug
                          .trim()
                          .toLowerCase()
                          .replace(/[^a-z0-9_-]/g, "") ||
                        tenCompany
                          .trim()
                          .toLowerCase()
                          .replace(/[^a-z0-9]/g, "")
                          .slice(0, 18);
                      const resolvedWaInst =
                        tenWaMode === "dedicated"
                          ? `dst_${cleanSlug}`
                          : tenWaInstance || "keystone-general";
                      try {
                        const d = await apiPost("/api/admin/secops.php", {
                          action: "create_tenant",
                          company_name: tenCompany,
                          tenant_slug: cleanSlug,
                          rut_dni: tenRut,
                          treasury_operator_email: tenOpEmail,
                          treasury_operator_password: tenOpPass,
                          primary_approver_name: tenApprName || "CFO",
                          primary_approver_phone: tenApprPhone,
                          secondary_approver_name: tenSecApprName,
                          secondary_approver_phone: tenSecApprPhone,
                          dual_approval_threshold:
                            parseFloat(tenDualThreshold) || 0,
                          whatsapp_instance: resolvedWaInst,
                        });
                        setTenCompany("");
                        setTenSlug("");
                        setTenRut("");
                        setTenOpEmail("");
                        setTenApprPhone("");
                        setTenSecApprName("");
                        setTenSecApprPhone("");
                        setTenDualThreshold("0");
                        notify(d.message);
                        loadAllConsoleData(currentUser.role);
                      } catch (err) {
                        notify(
                          err instanceof Error
                            ? err.message
                            : "Error aprovisionando tenant"
                        );
                      }
                    }}
                    className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3"
                  >
                    <div
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="text-xs font-bold uppercase tracking-[0.12em] text-emerald-300"
                    >
                      Aprovisionar Empresa Cliente (Multi-Tenant)
                    </div>
                    <input
                      type="text"
                      required
                      value={tenCompany}
                      onChange={(e) => setTenCompany(e.target.value)}
                      placeholder="Razón Social Cliente"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                    <div className="grid grid-cols-2 gap-2">
                      <input
                        type="text"
                        value={tenSlug}
                        onChange={(e) => setTenSlug(e.target.value)}
                        placeholder="Slug (ej: banco-x)"
                        className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                      <input
                        type="text"
                        required
                        value={tenRut}
                        onChange={(e) => setTenRut(e.target.value)}
                        placeholder="RUT Empresa"
                        className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                    </div>
                    <input
                      type="email"
                      required
                      value={tenOpEmail}
                      onChange={(e) => setTenOpEmail(e.target.value)}
                      placeholder="Correo Operador Tesorería"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                    <input
                      type="text"
                      required
                      value={tenOpPass}
                      onChange={(e) => setTenOpPass(e.target.value)}
                      placeholder="Clave Inicial Portal"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                    <input
                      type="text"
                      value={tenApprName}
                      onChange={(e) => setTenApprName(e.target.value)}
                      placeholder="Firmante 1 (Ej: CFO / Gte. Finanzas)"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                    <input
                      type="text"
                      value={tenApprPhone}
                      onChange={(e) => setTenApprPhone(e.target.value)}
                      placeholder="WhatsApp Firmante 1 (ej: +56930640431)"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                    <div className="grid grid-cols-2 gap-2">
                      <input
                        type="text"
                        value={tenSecApprName}
                        onChange={(e) => setTenSecApprName(e.target.value)}
                        placeholder="Firmante 2 Mancomunado (Opc.)"
                        className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                      <input
                        type="text"
                        value={tenSecApprPhone}
                        onChange={(e) => setTenSecApprPhone(e.target.value)}
                        placeholder="WA Firmante 2 (Opc.)"
                        className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                    </div>
                    {tenSecApprName.trim() !== "" && (
                      <input
                        type="number"
                        min="0"
                        value={tenDualThreshold}
                        onChange={(e) => setTenDualThreshold(e.target.value)}
                        placeholder="Umbral Doble Firma CLP (Ej: 10000000)"
                        className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-sky-400/25 text-xs text-white"
                      />
                    )}
                    <div className="space-y-1.5 pt-1">
                      <label className="block text-[10px] font-bold uppercase tracking-wider text-white/55">
                        Modalidad Canal WhatsApp OOB
                      </label>
                      <select
                        value={tenWaMode}
                        onChange={(e) => {
                          const mode = e.target.value as "shared" | "dedicated";
                          setTenWaMode(mode);
                          if (mode === "shared") {
                            setTenWaInstance("keystone-general");
                          }
                        }}
                        className="w-full px-3 py-2 rounded-xl bg-[#080B11] border border-white/15 text-xs text-white"
                      >
                        <option value="shared">
                          Canal Compartido Keystone (keystone-general)
                        </option>
                        <option value="dedicated">
                          WhatsApp Propio de la Empresa (Escaneo QR en Portal)
                        </option>
                      </select>
                      <div className="text-[10px] text-white/45 leading-relaxed">
                        {tenWaMode === "dedicated"
                          ? `Creará la instancia dedicada dst_${
                              tenSlug
                                .trim()
                                .toLowerCase()
                                .replace(/[^a-z0-9_-]/g, "") || "empresa"
                            } para que el cliente escanee su QR en /shield/portal/.`
                          : "El cliente puede migrar a su propio WhatsApp en cualquier momento escaneando el QR en su portal."}
                      </div>
                    </div>
                    <button
                      type="submit"
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="w-full py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
                    >
                      Habilitar Portal Cliente
                    </button>
                  </form>

                  <div className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                    <div className="flex items-center justify-between">
                      <div
                        style={{
                          fontFamily: "var(--font-jakarta), sans-serif",
                        }}
                        className="text-xs font-bold uppercase tracking-[0.12em] text-white/80"
                      >
                        Empresas en /shield/portal/ & Gobernanza Multi-Tenant
                      </div>
                      <Link
                        href="/shield/portal/"
                        target="_blank"
                        className="text-xs text-emerald-300 hover:underline"
                      >
                        Abrir Portal Clientes →
                      </Link>
                    </div>

                    <div className="space-y-2.5">
                      {dsTenants.map((t) => {
                        const isSharedWa =
                          !t.whatsapp_instance ||
                          t.whatsapp_instance === "keystone-general";
                        const isActive = t.status === "ACTIVE";
                        return (
                          <div
                            key={t.id}
                            className={`p-4 rounded-xl bg-[#080B11] border flex flex-wrap items-center justify-between gap-3 text-xs ${
                              isActive
                                ? "border-white/10"
                                : "border-amber-400/30 opacity-75"
                            }`}
                          >
                            <div className="space-y-1">
                              <div className="flex flex-wrap items-center gap-2">
                                <span className="font-bold text-white">
                                  {t.company_name}
                                </span>
                                <span className="font-mono text-emerald-300">
                                  [{t.tenant_slug}]
                                </span>
                                <span
                                  className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                                    isActive
                                      ? "bg-emerald-400/15 text-emerald-300"
                                      : "bg-amber-400/20 text-amber-200"
                                  }`}
                                >
                                  {isActive ? "ACTIVO" : "SUSPENDIDO"}
                                </span>
                              </div>
                              <div className="text-[11px] text-white/50">
                                Operador: {t.treasury_operator_email} · Canal WA:{" "}
                                <span
                                  className={`font-mono ${
                                    isSharedWa
                                      ? "text-white/75"
                                      : "text-emerald-300 font-semibold"
                                  }`}
                                >
                                  {t.whatsapp_instance}
                                </span>{" "}
                                <span className="text-white/40">
                                  ({isSharedWa ? "Compartido Keystone" : "Propio Dedicado"})
                                </span>
                              </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-1.5">
                              <button
                                type="button"
                                onClick={() => setErpModalTenant(t)}
                                className="px-2.5 py-1.5 rounded-lg bg-violet-400/15 hover:bg-violet-300 text-violet-200 hover:text-[#0A0E15] font-semibold inline-flex items-center gap-1 transition-colors"
                                title="Integración API REST / Webhook ERP (SAP, Softland, NetSuite)"
                              >
                                <KeyRound className="w-3.5 h-3.5" />
                                <span>API ERP</span>
                              </button>

                              <button
                                type="button"
                                onClick={async () => {
                                  try {
                                    const res = await apiPost(
                                      "/api/admin/secops.php",
                                      {
                                        action: "switch_tenant_whatsapp",
                                        id: t.id,
                                        mode: isSharedWa
                                          ? "dedicated"
                                          : "shared",
                                      }
                                    );
                                    notify(res.message);
                                    if (res.qr) {
                                      setQrModalData({
                                        instance: res.whatsapp_instance,
                                        connected: Boolean(res.qr.connected),
                                        qr_base64: res.qr.qr_base64,
                                        message: res.qr.message,
                                      });
                                    }
                                    loadAllConsoleData(currentUser.role);
                                  } catch (err) {
                                    notify(
                                      err instanceof Error
                                        ? err.message
                                        : "Error cambiando modalidad WhatsApp"
                                    );
                                  }
                                }}
                                className="px-2.5 py-1.5 rounded-lg bg-white/[0.06] hover:bg-white/15 text-white/85 font-semibold inline-flex items-center gap-1.5 transition-colors"
                                title={
                                  isSharedWa
                                    ? "Asignar instancia WhatsApp propia dst_" +
                                      t.tenant_slug
                                    : "Volver al canal compartido keystone-general"
                                }
                              >
                                <MessageCircle className="w-3.5 h-3.5 text-emerald-300" />
                                <span>
                                  {isSharedWa
                                    ? "Usar WA Propio"
                                    : "Usar WA Keystone"}
                                </span>
                              </button>

                              <button
                                type="button"
                                onClick={async () => {
                                  try {
                                    const qr = await apiPost(
                                      "/api/admin/secops.php",
                                      {
                                        action: "whatsapp_qr",
                                        instance: t.whatsapp_instance,
                                      }
                                    );
                                    setQrModalData({
                                      instance: t.whatsapp_instance,
                                      connected: Boolean(qr.connected),
                                      qr_base64: qr.qr_base64,
                                      message: qr.message,
                                    });
                                  } catch (err) {
                                    notify(
                                      err instanceof Error
                                        ? err.message
                                        : "Error consultando QR"
                                    );
                                  }
                                }}
                                className="px-2.5 py-1.5 rounded-lg bg-emerald-400/15 hover:bg-emerald-300 text-emerald-200 hover:text-[#0A0E15] font-semibold inline-flex items-center gap-1.5 transition-colors"
                              >
                                <QrCode className="w-3.5 h-3.5" />
                                <span>Estado / QR</span>
                              </button>

                              <button
                                type="button"
                                onClick={async () => {
                                  const actionWord = isActive
                                    ? "inactivar / suspender"
                                    : "reactivar";
                                  if (
                                    !window.confirm(
                                      `¿Desea ${actionWord} el servicio para ${t.company_name} [${t.tenant_slug}]?`
                                    )
                                  ) {
                                    return;
                                  }
                                  try {
                                    const res = await apiPost(
                                      "/api/admin/secops.php",
                                      {
                                        action: "toggle_tenant_status",
                                        id: t.id,
                                      }
                                    );
                                    notify(res.message);
                                    loadAllConsoleData(currentUser.role);
                                  } catch (err) {
                                    notify(
                                      err instanceof Error
                                        ? err.message
                                        : "Error cambiando estado"
                                    );
                                  }
                                }}
                                className={`px-2.5 py-1.5 rounded-lg font-semibold inline-flex items-center gap-1 transition-colors ${
                                  isActive
                                    ? "bg-amber-400/15 hover:bg-amber-400/30 text-amber-200"
                                    : "bg-emerald-400/20 hover:bg-emerald-300 text-emerald-200 hover:text-[#0A0E15]"
                                }`}
                              >
                                <Lock className="w-3.5 h-3.5" />
                                <span>{isActive ? "Inactivar" : "Reactivar"}</span>
                              </button>

                              <button
                                type="button"
                                onClick={async () => {
                                  if (
                                    !window.confirm(
                                      `¿Eliminar definitivamente la empresa ${t.company_name} [${t.tenant_slug}] del servicio? Si solo desea pausar su acceso conservando la cuenta, utilice 'Inactivar'.`
                                    )
                                  ) {
                                    return;
                                  }
                                  try {
                                    const res = await apiPost(
                                      "/api/admin/secops.php",
                                      {
                                        action: "delete_tenant",
                                        id: t.id,
                                      }
                                    );
                                    notify(res.message);
                                    loadAllConsoleData(currentUser.role);
                                  } catch (err) {
                                    notify(
                                      err instanceof Error
                                        ? err.message
                                        : "Error eliminando empresa"
                                    );
                                  }
                                }}
                                className="p-1.5 rounded-lg bg-rose-500/15 hover:bg-rose-500/30 text-rose-300 transition-colors"
                                title="Eliminar empresa del servicio"
                              >
                                <Trash2 className="w-3.5 h-3.5" />
                              </button>
                            </div>
                          </div>
                        );
                      })}
                    </div>

                    {qrModalData && (
                      <div className="p-4 rounded-xl bg-white/[0.04] border border-emerald-400/35 flex items-center justify-between gap-4">
                        <div className="text-xs space-y-1">
                          <div className="font-bold text-emerald-300">
                            Instancia WhatsApp: {qrModalData.instance}
                          </div>
                          <div className="text-white/75">
                            {qrModalData.connected
                              ? qrModalData.message ||
                              "Conectada y transmitiendo en línea."
                              : qrModalData.message ||
                              "Escanee el código QR con WhatsApp Corporativo para enlazar."}
                          </div>
                          {qrModalData.qr_base64 && (
                            /* eslint-disable-next-line @next/next/no-img-element */
                            <img
                              src={qrModalData.qr_base64}
                              alt="WhatsApp QR"
                              className="w-40 h-40 rounded-lg mt-2 bg-white p-1"
                            />
                          )}
                        </div>
                        <button
                          type="button"
                          onClick={() => setQrModalData(null)}
                          className="text-white/50 hover:text-white"
                        >
                          <X className="w-4 h-4" />
                        </button>
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* SUB-TAB C: DMARC RUA INGESTION + CRON DAEMON 24/7 */}
              {secopsTab === "dmarc" && (
                <div className="space-y-5">
                  <div className="rounded-2xl bg-[#0D121C] border border-sky-400/25 p-5 flex flex-wrap items-center justify-between gap-4">
                    <div className="space-y-1">
                      <div className="text-[11px] font-bold uppercase tracking-wider text-sky-300">
                        Daemon Autónomo 24/7 · Auto-Ingesta IMAP RUA + Watchdog + Expiración OOB
                      </div>
                      <div className="text-xs text-white/65">
                        {lastCronRun ? (
                          <span>
                            Último ciclo:{" "}
                            <strong className="text-white">
                              {lastCronRun.executed_at}
                            </strong>{" "}
                            ({lastCronRun.duration_sec}s) · Dominios:{" "}
                            {lastCronRun.watchdog_checked} · RUA nuevos:{" "}
                            {lastCronRun.rua_ingested} · Expiradas:{" "}
                            {lastCronRun.expired_orders}
                          </span>
                        ) : (
                          "Ejecute el daemon para sincronizar reportes RUA desde su casilla IMAP, re-escanear dominios y expirar órdenes OOB vencidas."
                        )}
                      </div>
                    </div>
                    <button
                      type="button"
                      disabled={cronRunning}
                      onClick={async () => {
                        setCronRunning(true);
                        try {
                          const d = await apiPost(
                            "/api/admin/cron_daemon.php",
                            {}
                          );
                          notify(d.message);
                          loadAllConsoleData(currentUser.role);
                        } catch (err) {
                          notify(
                            err instanceof Error
                              ? err.message
                              : "Error ejecutando daemon"
                          );
                        } finally {
                          setCronRunning(false);
                        }
                      }}
                      className="px-4 py-2.5 rounded-xl bg-sky-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider inline-flex items-center gap-2 transition-colors"
                    >
                      <RefreshCw
                        className={`w-3.5 h-3.5 ${
                          cronRunning ? "animate-spin" : ""
                        }`}
                      />
                      <span>
                        {cronRunning
                          ? "Ejecutando Ciclo..."
                          : "Ejecutar Daemon Ahora"}
                      </span>
                    </button>
                  </div>

                  <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 flex flex-wrap items-center justify-between gap-4">
                    <div>
                      <div
                        style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                        className="text-sm font-bold text-white"
                      >
                        Ingesta Forense de Reportes Agregados DMARC (RFC 7489)
                      </div>
                      <p className="text-xs text-white/50 mt-0.5">
                        Suba archivos `.xml`, `.xml.gz` o `.zip` emitidos por
                        Google Workspace, Microsoft 365 o Yahoo para auditar IPs
                        suplantadoras.
                      </p>
                    </div>

                    <label className="cursor-pointer inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors">
                      {ruaUploading ? (
                        <Loader2 className="w-4 h-4 animate-spin" />
                      ) : (
                        <Upload className="w-4 h-4" />
                      )}
                      <span>
                        {ruaUploading ? "Procesando..." : "Cargar Archivo RUA"}
                      </span>
                      <input
                        type="file"
                        accept=".xml,.gz,.zip"
                        onChange={handleUploadDmarcRua}
                        className="hidden"
                      />
                    </label>
                  </div>

                  <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] overflow-hidden">
                    <table className="w-full text-left border-collapse text-xs">
                      <thead>
                        <tr className="border-b border-white/[0.08] text-[10px] uppercase tracking-[0.12em] text-white/45">
                          <th className="py-3.5 px-4">Proveedor / Report ID</th>
                          <th className="py-3.5 px-4">Dominio</th>
                          <th className="py-3.5 px-4">Política</th>
                          <th className="py-3.5 px-4">Volumen</th>
                          <th className="py-3.5 px-4">Autenticados</th>
                          <th className="py-3.5 px-4">Spoofing / Fallos</th>
                          <th className="py-3.5 px-4 text-right">Acta PDF</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-white/[0.06]">
                        {dmarcReports.length === 0 ? (
                          <tr>
                            <td
                              colSpan={7}
                              className="py-10 text-center text-white/45"
                            >
                              Sin reportes RUA cargados aún.
                            </td>
                          </tr>
                        ) : (
                          dmarcReports.map((r) => (
                            <tr key={r.id}>
                              <td className="py-3.5 px-4">
                                <div className="font-semibold text-white">
                                  {r.org_name}
                                </div>
                                <div className="font-mono text-[10px] text-white/40">
                                  {r.report_id}
                                </div>
                              </td>
                              <td className="py-3.5 px-4 font-mono text-emerald-300">
                                {r.domain}
                              </td>
                              <td className="py-3.5 px-4 uppercase font-bold text-white/80">
                                p={r.policy_p}
                              </td>
                              <td className="py-3.5 px-4 text-white">
                                {r.total_messages}
                              </td>
                              <td className="py-3.5 px-4 text-emerald-300 font-semibold">
                                {r.total_pass}
                              </td>
                              <td className="py-3.5 px-4 text-rose-400 font-semibold">
                                {r.total_fail} ({r.spoofing_ips} IPs)
                              </td>
                              <td className="py-3.5 px-4 text-right">
                                <a
                                  href={`/api/admin/reports.php?type=dmarc_pdf&id=${r.id}`}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  className="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-white/[0.06] hover:bg-white text-white hover:text-[#0A0E15] font-semibold"
                                >
                                  <FileDown className="w-3 h-3" />
                                  <span>PDF</span>
                                </a>
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}

              {/* SUB-TAB D: RED TEAMING IA */}
              {secopsTab === "redteam" && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
                  <form
                    onSubmit={handleCreateRedTeam}
                    className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3"
                  >
                    <div
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="text-xs font-bold uppercase tracking-[0.12em] text-emerald-300"
                    >
                      Nueva Campaña Red Teaming con IA
                    </div>

                    <input
                      type="text"
                      required
                      value={rtCompany}
                      onChange={(e) => setRtCompany(e.target.value)}
                      placeholder="Empresa Cliente"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                    <input
                      type="text"
                      required
                      value={rtDomains}
                      onChange={(e) => setRtDomains(e.target.value)}
                      placeholder="Dominio alcance (ej: cliente.cl)"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                    <input
                      type="email"
                      value={rtContactEmail}
                      onChange={(e) => setRtContactEmail(e.target.value)}
                      placeholder="Correo contraparte CISO/TI"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />

                    <div className="grid grid-cols-2 gap-2">
                      <select
                        value={rtPlan}
                        onChange={(e) => setRtPlan(e.target.value)}
                        className="px-2.5 py-2 rounded-xl bg-[#080B11] border border-white/12 text-xs text-white"
                      >
                        <option value="STARTER">Starter</option>
                        <option value="PROFESSIONAL">Professional</option>
                        <option value="ENTERPRISE">Enterprise</option>
                      </select>
                      <input
                        type="number"
                        value={rtUsersCount}
                        onChange={(e) => setRtUsersCount(e.target.value)}
                        placeholder="Colaboradores"
                        className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                    </div>

                    <select
                      value={rtVector}
                      onChange={(e) => setRtVector(e.target.value)}
                      className="w-full px-3 py-2 rounded-xl bg-[#080B11] border border-white/12 text-xs text-white"
                    >
                      <option value="BEC_CEO_FRAUD">
                        Urgencia Ejecutiva / Gestión Reservada
                      </option>
                      <option value="SUPPLIER_ACCOUNT_CHANGE">
                        Solicitud Cambio Cuenta Proveedor (Anti-BEC)
                      </option>
                      <option value="VISHING_DEEPFAKE_CALLBACK">
                        Validación Híbrida Vishing / Desafío Anti-Voz
                      </option>
                      <option value="CORPORATE_BILLING_DOC_DRILL">
                        Visación Documento Tributario / Facturación
                      </option>
                      <option value="PAYROLL_POLICY_DRILL">
                        Actualización Procedimiento Tesorería & Nómina
                      </option>
                    </select>

                    <button
                      type="submit"
                      disabled={rtBusy}
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="w-full py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
                    >
                      {rtBusy
                        ? "Generando..."
                        : "Generar Campaña & Plantilla"}
                    </button>
                  </form>

                  <div className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                    <div
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="text-xs font-bold uppercase tracking-[0.12em] text-white/80"
                    >
                      Campañas Autorizadas, Telemetría Humana & Bóveda de Insumos
                    </div>

                    {redteamCampaigns.length === 0 ? (
                      <div className="py-12 text-center text-xs text-white/45">
                        Aún no hay campañas Red Teaming creadas.
                      </div>
                    ) : (
                      <div className="space-y-3">
                        {redteamCampaigns.map((c) => (
                          <div
                            key={c.id}
                            className="p-4 rounded-xl bg-[#080B11] border border-white/10 space-y-2.5 text-xs"
                          >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                              <div>
                                <span className="font-bold text-white text-sm">
                                  {c.company_name}
                                </span>
                                <span className="text-white/45 ml-2">
                                  ({c.target_domains})
                                </span>
                              </div>
                              <div className="flex flex-wrap items-center gap-1.5">
                                <button
                                  type="button"
                                  onClick={async () => {
                                    setActiveRtCampaign(c);
                                    setRtEditSubject(c.last_lure_subject || "");
                                    setRtEditBody(c.last_lure_body || "");
                                    setRtEditCta(
                                      c.lure_cta_label ||
                                        "REVISAR SOLICITUD EN PORTAL INTERNO"
                                    );
                                    setRtEditAccent(
                                      c.lure_accent_color || "#0F172A"
                                    );
                                    setRtEditCustomHtml(
                                      c.custom_html_body || ""
                                    );
                                    setRtModalNotice(null);
                                    setRtPreviewOpen(false);
                                    try {
                                      const r = await fetch(
                                        `/api/admin/secops.php?action=redteam_detail&campaign_id=${c.id}`,
                                        { credentials: "include" }
                                      );
                                      const d = await r.json();
                                      if (d?.success) {
                                        setRtTargets(d.targets || []);
                                        setRtAssets(d.assets || []);
                                        if (d.templates) {
                                          setRtTemplates(d.templates);
                                        }
                                      }
                                    } catch {
                                      // ignore
                                    }
                                  }}
                                  className="px-2.5 py-1 rounded bg-emerald-400/20 hover:bg-emerald-300 text-emerald-200 hover:text-[#0A0E15] text-[10px] font-bold inline-flex items-center gap-1 transition-colors"
                                >
                                  <Users className="w-3 h-3" />
                                  <span>Nómina, Insumos & Despacho</span>
                                </button>
                                <a
                                  href={`/api/admin/reports.php?type=redteam_pdf&id=${c.id}`}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  className="px-2.5 py-1 rounded bg-white/[0.07] hover:bg-white text-white hover:text-[#0A0E15] text-[10px] font-bold inline-flex items-center gap-1"
                                >
                                  <FileDown className="w-3 h-3" />
                                  <span>Dossier PDF</span>
                                </a>
                                <span className="px-2.5 py-0.5 rounded bg-emerald-400/15 text-emerald-300 text-[10px] font-bold">
                                  {c.status}
                                </span>
                              </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-2 text-[10px] font-mono">
                              <span className="px-2 py-0.5 rounded bg-white/[0.05] text-white/75">
                                Inscritos: {c.targets_total || 0}
                              </span>
                              <span className="px-2 py-0.5 rounded bg-sky-400/15 text-sky-200">
                                Enviados: {c.targets_sent || 0}
                              </span>
                              <span className="px-2 py-0.5 rounded bg-amber-400/15 text-amber-200">
                                Aperturas: {c.targets_opened || 0} · Cápsula:{" "}
                                {c.targets_clicked || 0}
                              </span>
                              <span className="px-2 py-0.5 rounded bg-emerald-400/15 text-emerald-300">
                                Reportes SOC: {c.targets_reported || 0}
                              </span>
                              <span className="px-2 py-0.5 rounded bg-violet-400/15 text-violet-200">
                                Insumos: {c.assets_count || 0}
                              </span>
                            </div>

                            {c.last_lure_subject && (
                              <div className="p-3 rounded-lg bg-white/[0.03] border border-white/[0.06] space-y-1">
                                <div className="font-semibold text-amber-200">
                                  Asunto Ejercicio: {c.last_lure_subject}
                                </div>
                                <p className="text-[11px] text-white/65 whitespace-pre-line">
                                  {c.last_lure_body}
                                </p>
                              </div>
                            )}
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* SUB-TAB E: BÓVEDA CENTRAL DE RECURSOS & ESTUDIO MAESTRO DE PLANTILLAS (INDEPENDIENTE DE CAMPAÑAS) */}
              {secopsTab === "vault" && (
                <div className="space-y-6">
                  {/* BLOQUE 1: ESTUDIO MAESTRO DE PLANTILLAS BASE */}
                  <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
                    <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3">
                      <div className="flex items-center justify-between">
                        <div
                          style={{
                            fontFamily: "var(--font-jakarta), sans-serif",
                          }}
                          className="text-xs font-bold uppercase tracking-[0.12em] text-amber-200"
                        >
                          Biblioteca de Plantillas ({rtTemplates.length})
                        </div>
                        <button
                          type="button"
                          onClick={() => {
                            setSelectedTplId(null);
                            setVTplName("");
                            setVTplCategory("Plantilla Cliente");
                            setVTplClientScope("GLOBAL");
                            setVTplSubject(
                              "[Revisión Interna] Confirmación Documental — {{EMPRESA}}"
                            );
                            setVTplBody(
                              "Estimado(a) {{NOMBRE}} ({{DEPARTAMENTO}}),\n\nSe requiere validar la documentación adjunta correspondiente al período actual de {{EMPRESA}}."
                            );
                            setVTplHtml("");
                            setVTplCta("REVISAR DOCUMENTO EN LINEA");
                            setVTplAccent("#0F172A");
                          }}
                          className="px-2.5 py-1 rounded-lg bg-emerald-300 hover:bg-white text-[#0A0E15] text-[11px] font-bold inline-flex items-center gap-1 transition-colors"
                        >
                          <Plus className="w-3 h-3" />
                          <span>Nueva</span>
                        </button>
                      </div>

                      <div className="space-y-2 max-h-[520px] overflow-y-auto pr-1">
                        {rtTemplates.map((tp) => {
                          const isSel = selectedTplId === tp.id;
                          return (
                            <div
                              key={tp.id}
                              onClick={() => {
                                setSelectedTplId(tp.id);
                                setVTplName(tp.template_name);
                                setVTplCategory(tp.category);
                                setVTplClientScope(tp.client_scope || "GLOBAL");
                                setVTplSubject(tp.subject);
                                setVTplBody(tp.body_text || "");
                                setVTplHtml(tp.custom_html || "");
                                setVTplCta(
                                  tp.cta_label || "REVISAR DOCUMENTO EN LINEA"
                                );
                                setVTplAccent(tp.accent_color || "#0F172A");
                              }}
                              className={`p-3 rounded-xl border cursor-pointer transition-all text-xs space-y-1 ${
                                isSel
                                  ? "bg-emerald-400/10 border-emerald-400/45"
                                  : "bg-[#080B11] border-white/10 hover:border-white/25"
                              }`}
                            >
                              <div className="flex items-center justify-between gap-2">
                                <span className="px-2 py-0.5 rounded bg-white/[0.06] text-[10px] font-bold text-sky-300 truncate">
                                  {tp.category}
                                </span>
                                <button
                                  type="button"
                                  onClick={async (ev) => {
                                    ev.stopPropagation();
                                    if (
                                      !window.confirm(
                                        `¿Eliminar la plantilla '${tp.template_name}' de la biblioteca?`
                                      )
                                    )
                                      return;
                                    const d = await apiPost(
                                      "/api/admin/secops.php",
                                      {
                                        action: "delete_redteam_template",
                                        id: tp.id,
                                      }
                                    );
                                    setRtTemplates(d.templates || []);
                                    if (selectedTplId === tp.id) {
                                      setSelectedTplId(null);
                                    }
                                    notify(d.message);
                                  }}
                                  className="text-white/35 hover:text-rose-400"
                                  title="Eliminar plantilla"
                                >
                                  <Trash2 className="w-3.5 h-3.5" />
                                </button>
                              </div>
                              <div className="font-bold text-white leading-snug">
                                {tp.template_name}
                              </div>
                              <div className="text-[11px] text-white/45 truncate">
                                {tp.subject}
                              </div>
                            </div>
                          );
                        })}
                      </div>
                    </div>

                    <div className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3.5 text-xs">
                      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-white/[0.08] pb-3">
                        <div>
                          <div className="text-[11px] font-bold uppercase tracking-wider text-emerald-300">
                            {selectedTplId
                              ? `Editando Plantilla #${selectedTplId}`
                              : "Creando Nueva Plantilla Base"}
                          </div>
                          <p className="text-[11px] text-white/50">
                            Trabaje sus plantillas HTML sin requerir campaña activa. Variables:{" "}
                            <span className="font-mono text-emerald-300">
                              {"{{NOMBRE}}"} {"{{CORREO}}"} {"{{DEPARTAMENTO}}"}{" "}
                              {"{{EMPRESA}}"} {"{{LINK_CAPSULA}}"}
                            </span>
                          </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-1.5">
                          <button
                            type="button"
                            onClick={() => {
                              const exportHtml =
                                vTplHtml.trim() !== ""
                                  ? vTplHtml
                                  : `<!DOCTYPE html>\n<html lang="es">\n<head><meta charset="utf-8"></head>\n<body style="font-family:Arial,Helvetica,sans-serif;background:#F1F5F9;margin:0;padding:24px;color:#1E293B;">\n  <table width="620" align="center" cellpadding="0" cellspacing="0" style="background:#FFFFFF;border:1px solid #CBD5E1;border-radius:6px;overflow:hidden;">\n    <tr>\n      <td style="background:${vTplAccent};color:#FFFFFF;padding:18px 24px;">\n        <div style="font-size:16px;font-weight:bold;">PORTAL DOCUMENTAL · {{EMPRESA}}</div>\n      </td>\n    </tr>\n    <tr>\n      <td style="padding:24px;">\n        <div style="font-size:13.5px;line-height:1.6;">${vTplBody.replace(
                                      /\n/g,
                                      "<br/>"
                                    )}</div>\n        <div style="text-align:center;margin:22px 0;">\n          <a href="{{LINK_CAPSULA}}" style="display:inline-block;background:${vTplAccent};color:#FFFFFF;text-decoration:none;padding:11px 24px;border-radius:5px;font-size:13px;font-weight:bold;">${vTplCta}</a>\n        </div>\n      </td>\n    </tr>\n  </table>\n</body>\n</html>`;
                              const blob = new Blob([exportHtml], {
                                type: "text/html;charset=utf-8;",
                              });
                              const url = URL.createObjectURL(blob);
                              const a = document.createElement("a");
                              a.href = url;
                              a.download = `${(vTplName || "Plantilla_Base")
                                .replace(/[^a-zA-Z0-9_-]/g, "_")
                                .slice(0, 40)}.html`;
                              document.body.appendChild(a);
                              a.click();
                              document.body.removeChild(a);
                              URL.revokeObjectURL(url);
                            }}
                            className="px-2.5 py-1.5 rounded-lg bg-emerald-400/15 hover:bg-emerald-300 text-emerald-200 hover:text-[#0A0E15] font-semibold inline-flex items-center gap-1 transition-colors"
                          >
                            <FileDown className="w-3.5 h-3.5" />
                            <span>Descargar .html</span>
                          </button>

                          <label className="px-2.5 py-1.5 rounded-lg bg-white/[0.06] hover:bg-white/15 border border-white/12 text-sky-300 font-semibold cursor-pointer inline-flex items-center gap-1">
                            <Upload className="w-3.5 h-3.5" />
                            <span>Importar .html / .txt</span>
                            <input
                              type="file"
                              accept=".html,.htm,.txt"
                              className="hidden"
                              onChange={async (e) => {
                                const f = e.target.files?.[0];
                                e.target.value = "";
                                if (!f) return;
                                const content = await f.text();
                                if (
                                  f.name.toLowerCase().endsWith(".html") ||
                                  f.name.toLowerCase().endsWith(".htm") ||
                                  content.includes("<table") ||
                                  content.includes("<div")
                                ) {
                                  setVTplHtml(content);
                                } else {
                                  setVTplBody(content);
                                }
                                if (!vTplName) {
                                  setVTplName(
                                    f.name.replace(/\.[^.]+$/, "")
                                  );
                                }
                                notify(`Archivo '${f.name}' cargado en el editor.`);
                              }}
                            />
                          </label>

                          <button
                            type="button"
                            onClick={() => setVTplPreviewOpen((v) => !v)}
                            className="px-2.5 py-1.5 rounded-lg bg-violet-400/15 hover:bg-violet-300 text-violet-200 hover:text-[#0A0E15] font-semibold inline-flex items-center gap-1 transition-colors"
                          >
                            <Eye className="w-3.5 h-3.5" />
                            <span>
                              {vTplPreviewOpen
                                ? "Ocultar Vista Previa"
                                : "Vista Previa"}
                            </span>
                          </button>
                        </div>
                      </div>

                      <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                        <input
                          type="text"
                          value={vTplName}
                          onChange={(e) => setVTplName(e.target.value)}
                          placeholder="Nombre de la Plantilla"
                          className="sm:col-span-2 px-3 py-2 rounded-xl bg-white/[0.04] border border-white/15 text-white"
                        />
                        <input
                          type="text"
                          value={vTplCategory}
                          onChange={(e) => setVTplCategory(e.target.value)}
                          placeholder="Categoría / País (ej: RD / AR)"
                          className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/15 text-white"
                        />
                      </div>

                      <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5 items-center">
                        <input
                          type="text"
                          value={vTplSubject}
                          onChange={(e) => setVTplSubject(e.target.value)}
                          placeholder="Asunto del Correo"
                          className="sm:col-span-2 px-3 py-2 rounded-xl bg-white/[0.04] border border-white/15 text-white"
                        />
                        <div className="flex items-center gap-2">
                          <input
                            type="color"
                            value={vTplAccent}
                            onChange={(e) => setVTplAccent(e.target.value)}
                            className="w-9 h-9 rounded bg-transparent border border-white/15 cursor-pointer shrink-0"
                          />
                          <input
                            type="text"
                            value={vTplCta}
                            onChange={(e) => setVTplCta(e.target.value)}
                            placeholder="Texto Botón CTA"
                            className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/15 text-white"
                          />
                        </div>
                      </div>

                      <div>
                        <label className="block text-[10px] uppercase tracking-wider text-white/45 font-bold mb-1">
                          Texto Base / Contenido Guiado
                        </label>
                        <textarea
                          rows={3}
                          value={vTplBody}
                          onChange={(e) => setVTplBody(e.target.value)}
                          className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/15 text-white resize-y"
                        />
                      </div>

                      <div>
                        <label className="block text-[10px] uppercase tracking-wider text-sky-300 font-bold mb-1">
                          Código HTML Completo (Editable — si está presente se usa como diseño exacto del correo)
                        </label>
                        <textarea
                          rows={7}
                          value={vTplHtml}
                          onChange={(e) => setVTplHtml(e.target.value)}
                          placeholder="<!DOCTYPE html>..."
                          className="w-full px-3 py-2 rounded-xl bg-[#080B11] border border-white/15 font-mono text-[11px] text-sky-100 resize-y"
                        />
                      </div>

                      {vTplPreviewOpen && (
                        <div className="rounded-xl bg-slate-100 p-4 text-slate-900 border border-white/20 space-y-2">
                          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">
                            Vista Previa Renderizada — Asunto:{" "}
                            {vTplSubject.replace(
                              /\{\{EMPRESA\}\}/g,
                              "Empresa Ejemplo S.A."
                            )}
                          </div>
                          {vTplHtml.trim() !== "" ? (
                            <div
                              className="rounded bg-white p-3 border border-slate-300 text-xs overflow-x-auto"
                              dangerouslySetInnerHTML={{
                                __html: vTplHtml
                                  .replace(/\{\{NOMBRE\}\}/g, "Ejecutivo Demo")
                                  .replace(
                                    /\{\{CORREO\}\}/g,
                                    "finanzas@empresa.com"
                                  )
                                  .replace(
                                    /\{\{DEPARTAMENTO\}\}/g,
                                    "Administración y Finanzas"
                                  )
                                  .replace(
                                    /\{\{EMPRESA\}\}/g,
                                    "Empresa Ejemplo S.A."
                                  )
                                  .replace(
                                    /\{\{LINK_CAPSULA\}\}/g,
                                    "/shield/rt/?t=demo"
                                  ),
                              }}
                            />
                          ) : (
                            <div className="max-w-xl mx-auto bg-white rounded-lg border border-slate-300 overflow-hidden shadow">
                              <div
                                style={{ backgroundColor: vTplAccent }}
                                className="text-white px-5 py-3 font-bold text-xs uppercase"
                              >
                                Empresa Ejemplo S.A.
                              </div>
                              <div className="p-5 space-y-3 text-xs text-slate-700 whitespace-pre-line">
                                {vTplBody
                                  .replace(/\{\{NOMBRE\}\}/g, "Ejecutivo Demo")
                                  .replace(
                                    /\{\{EMPRESA\}\}/g,
                                    "Empresa Ejemplo S.A."
                                  )
                                  .replace(
                                    /\{\{DEPARTAMENTO\}\}/g,
                                    "Administración y Finanzas"
                                  )}
                              </div>
                            </div>
                          )}
                        </div>
                      )}

                      <div className="flex flex-wrap items-center justify-end gap-2 pt-1">
                        {selectedTplId && (
                          <button
                            type="button"
                            onClick={async () => {
                              try {
                                const d = await apiPost(
                                  "/api/admin/secops.php",
                                  {
                                    action: "save_redteam_template",
                                    template_name: `${vTplName} (Copia)`,
                                    category: vTplCategory,
                                    client_scope: vTplClientScope,
                                    subject: vTplSubject,
                                    body_text: vTplBody,
                                    custom_html: vTplHtml,
                                    cta_label: vTplCta,
                                    accent_color: vTplAccent,
                                  }
                                );
                                setRtTemplates(d.templates || []);
                                notify("Copia guardada como nueva plantilla.");
                              } catch (err) {
                                notify(
                                  err instanceof Error
                                    ? err.message
                                    : "Error duplicando"
                                );
                              }
                            }}
                            className="px-3.5 py-2 rounded-xl bg-white/[0.07] hover:bg-white/15 text-white font-semibold transition-colors"
                          >
                            Guardar como Copia Nueva
                          </button>
                        )}

                        <button
                          type="button"
                          onClick={async () => {
                            try {
                              const d = await apiPost("/api/admin/secops.php", {
                                action: selectedTplId
                                  ? "update_redteam_template"
                                  : "save_redteam_template",
                                id: selectedTplId || undefined,
                                template_name: vTplName,
                                category: vTplCategory,
                                client_scope: vTplClientScope,
                                subject: vTplSubject,
                                body_text: vTplBody,
                                custom_html: vTplHtml,
                                cta_label: vTplCta,
                                accent_color: vTplAccent,
                              });
                              setRtTemplates(d.templates || []);
                              notify(d.message);
                            } catch (err) {
                              notify(
                                err instanceof Error
                                  ? err.message
                                  : "Error guardando plantilla"
                              );
                            }
                          }}
                          style={{
                            fontFamily: "var(--font-jakarta), sans-serif",
                          }}
                          className="px-5 py-2 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] font-bold uppercase tracking-wider inline-flex items-center gap-1.5 transition-colors"
                        >
                          <Save className="w-3.5 h-3.5" />
                          <span>
                            {selectedTplId
                              ? "Guardar Cambios en Plantilla"
                              : "Crear Plantilla en Biblioteca"}
                          </span>
                        </button>
                      </div>
                    </div>
                  </div>

                  {/* BLOQUE 2: BÓVEDA CENTRAL DE RECURSOS E INSUMOS (SIN CAMPAÑA REQUERIDA) */}
                  <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div>
                        <div
                          style={{
                            fontFamily: "var(--font-jakarta), sans-serif",
                          }}
                          className="text-xs font-bold uppercase tracking-[0.12em] text-violet-300"
                        >
                          Bóveda Central de Recursos & Documentos ({vaultAssets.length})
                        </div>
                        <p className="text-xs text-white/50 mt-0.5">
                          Suba, revise, descargue o cargue en el editor sus recursos (`PDF`, `DOCX`, `HTML`, `TXT`, `CSV`, imágenes) organizados por cliente o país sin requerir campaña activa.
                        </p>
                      </div>

                      <div className="flex flex-wrap items-center gap-2 text-xs">
                        <input
                          type="text"
                          value={vAssetClientLabel}
                          onChange={(e) => setVAssetClientLabel(e.target.value)}
                          placeholder="Cliente / País (ej: Rep. Dominicana)"
                          className="px-3 py-2 rounded-xl bg-[#080B11] border border-white/15 text-white w-56"
                        />
                        <label className="px-4 py-2 rounded-xl bg-violet-300 hover:bg-white text-[#0A0E15] font-bold uppercase tracking-wider inline-flex items-center gap-1.5 cursor-pointer transition-colors">
                          <Upload className="w-3.5 h-3.5" />
                          <span>Subir Recurso a Bóveda</span>
                          <input
                            type="file"
                            accept=".pdf,.docx,.html,.htm,.txt,.csv,.png,.jpg,.jpeg"
                            className="hidden"
                            onChange={async (e) => {
                              const f = e.target.files?.[0];
                              e.target.value = "";
                              if (!f) return;
                              const fd = new FormData();
                              fd.append("action", "upload_redteam_asset");
                              fd.append("campaign_id", "0");
                              fd.append(
                                "client_label",
                                vAssetClientLabel || "General"
                              );
                              fd.append("asset_file", f);
                              try {
                                const r = await fetch("/api/admin/secops.php", {
                                  method: "POST",
                                  credentials: "include",
                                  headers: { "X-CSRF-Token": csrfToken },
                                  body: fd,
                                });
                                const d = await r.json();
                                if (d?.success) {
                                  notify(d.message);
                                  loadAllConsoleData(currentUser.role);
                                } else {
                                  notify(d?.error || "Error subiendo archivo");
                                }
                              } catch {
                                notify("Error de red subiendo recurso");
                              }
                            }}
                          />
                        </label>
                      </div>
                    </div>

                    {vaultAssets.length === 0 ? (
                      <div className="py-10 text-center text-xs text-white/40">
                        La bóveda central aún no tiene documentos cargados. Suba aquí sus insumos y maquetas para tenerlos siempre disponibles.
                      </div>
                    ) : (
                      <div className="divide-y divide-white/[0.06] text-xs">
                        {vaultAssets.map((va) => {
                          const isTextOrHtml = ["HTML", "HTM", "TXT"].includes(
                            (va.asset_type || "").toUpperCase()
                          );
                          return (
                            <div
                              key={va.id}
                              className="py-3 flex flex-wrap items-center justify-between gap-3"
                            >
                              <div className="space-y-0.5">
                                <div className="flex items-center gap-2">
                                  <span className="px-2 py-0.5 rounded bg-violet-400/15 text-violet-200 font-mono text-[10px] font-bold">
                                    {va.asset_type}
                                  </span>
                                  <span className="font-semibold text-white">
                                    {va.asset_name}
                                  </span>
                                  <span className="px-2 py-0.5 rounded bg-white/[0.05] text-white/65 text-[10px]">
                                    {va.campaign_company
                                      ? `Campaña: ${va.campaign_company}`
                                      : va.client_label || "Bóveda General"}
                                  </span>
                                </div>
                                <div className="text-[11px] text-white/40 font-mono">
                                  {Math.max(1, Math.round(va.file_size / 1024))}{" "}
                                  KB · Subido por @{va.uploaded_by} ·{" "}
                                  {va.created_at}
                                </div>
                              </div>

                              <div className="flex items-center gap-2">
                                {isTextOrHtml && (
                                  <button
                                    type="button"
                                    onClick={async () => {
                                      const r = await fetch(
                                        `/api/admin/secops.php?action=read_vault_text&id=${va.id}`,
                                        { credentials: "include" }
                                      ).then((res) => res.json());
                                      if (r?.success) {
                                        setSelectedTplId(null);
                                        setVTplName(
                                          va.asset_name.replace(/\.[^.]+$/, "")
                                        );
                                        if (
                                          (va.asset_type || "")
                                            .toUpperCase()
                                            .startsWith("HTM")
                                        ) {
                                          setVTplHtml(r.content || "");
                                        } else {
                                          setVTplBody(r.content || "");
                                        }
                                        notify(
                                          `'${va.asset_name}' cargado arriba en el Estudio de Plantillas.`
                                        );
                                      }
                                    }}
                                    className="px-2.5 py-1 rounded-lg bg-sky-400/15 hover:bg-sky-300 text-sky-200 hover:text-[#0A0E15] font-semibold transition-colors"
                                  >
                                    Abrir en Editor
                                  </button>
                                )}

                                <a
                                  href={`/api/admin/secops.php?action=download_vault_asset&id=${va.id}`}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  className="px-2.5 py-1 rounded-lg bg-white/[0.07] hover:bg-white text-white hover:text-[#0A0E15] font-semibold inline-flex items-center gap-1 transition-colors"
                                >
                                  <FileDown className="w-3 h-3" />
                                  <span>Revisar / Descargar</span>
                                </a>

                                <button
                                  type="button"
                                  onClick={async () => {
                                    if (
                                      !window.confirm(
                                        `¿Eliminar '${va.asset_name}' de la bóveda?`
                                      )
                                    )
                                      return;
                                    await apiPost("/api/admin/secops.php", {
                                      action: "delete_redteam_asset",
                                      id: va.id,
                                    });
                                    loadAllConsoleData(currentUser.role);
                                  }}
                                  className="p-1.5 rounded-lg text-white/40 hover:text-rose-400 hover:bg-rose-500/15"
                                >
                                  <Trash2 className="w-3.5 h-3.5" />
                                </button>
                              </div>
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>
          )}

          {/* =================================================================
              MODULE GROWTH & BANDEJA ENTRADA IMAP
          ================================================================== */}
          {activeNav === "growth" && (
            <div className="space-y-6">
              <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                  <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-violet-300">
                    Seguimiento Comercial Bidireccional · Posicionamiento Ejecutivo
                  </div>
                  <h1
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-2xl font-bold text-white mt-1"
                  >
                    Bandeja IMAP & Growth LinkedIn IA
                  </h1>
                  <p className="text-xs text-white/50 mt-1 flex items-center gap-2">
                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
                    <span>
                      Casilla enlazada:{" "}
                      <strong className="text-white font-mono">
                        {imapMailbox}
                      </strong>{" "}
                      ({imapHostLabel})
                    </span>
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  {emailReplies.length > 0 && (
                    <button
                      type="button"
                      onClick={async () => {
                        if (
                          !window.confirm(
                            "¿Limpiar el historial local de respuestas sincronizadas? (No borra correos en su servidor Titan Mail)."
                          )
                        ) {
                          return;
                        }
                        try {
                          const d = await apiPost(
                            "/api/admin/growth_inbox.php",
                            {
                              action: "clear_imap_replies",
                            }
                          );
                          notify(d.message);
                          loadAllConsoleData(currentUser.role);
                        } catch (err) {
                          notify(
                            err instanceof Error
                              ? err.message
                              : "Error limpiando bandeja"
                          );
                        }
                      }}
                      className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white/[0.05] hover:bg-rose-500/20 border border-white/12 hover:border-rose-400/35 text-xs font-semibold text-white/75 hover:text-rose-200 transition-colors"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                      <span>Limpiar Bandeja</span>
                    </button>
                  )}

                  <button
                    type="button"
                    disabled={imapSyncing}
                    onClick={async () => {
                      setImapSyncing(true);
                      try {
                        const d = await apiPost("/api/admin/growth_inbox.php", {
                          action: "sync_imap",
                        });
                        notify(d.message);
                        loadAllConsoleData(currentUser.role);
                      } catch (err) {
                        notify(
                          err instanceof Error
                            ? err.message
                            : "Error sincronizando IMAP"
                        );
                      } finally {
                        setImapSyncing(false);
                      }
                    }}
                    className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-sky-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
                  >
                    {imapSyncing ? (
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    ) : (
                      <Inbox className="w-3.5 h-3.5" />
                    )}
                    <span>Sincronizar Casilla IMAP</span>
                  </button>
                </div>
              </div>

              {/* IMAP REPLIES */}
              <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3">
                <div className="text-xs font-bold uppercase tracking-[0.12em] text-sky-300">
                  Respuestas Entrantes de Prospectos & Clientes ({emailReplies.length})
                </div>

                {emailReplies.length === 0 ? (
                  <div className="py-8 text-center text-xs text-white/45">
                    Pulse &ldquo;Sincronizar Casilla IMAP&rdquo; para leer respuestas recientes en Titan Mail y vincularlas con leads activos.
                  </div>
                ) : (
                  <div className="divide-y divide-white/[0.06] text-xs">
                    {emailReplies.map((rep) => (
                      <div
                        key={rep.id}
                        className="py-3 flex flex-wrap items-start justify-between gap-3"
                      >
                        <div className="space-y-1 max-w-3xl">
                          <div className="flex items-center gap-2">
                            <span className="font-bold text-white">
                              {rep.sender_name || rep.sender_email}
                            </span>
                            <span className="text-white/45">
                              &lt;{rep.sender_email}&gt;
                            </span>
                            {rep.linked_lead_ref && (
                              <span className="px-2 py-0.5 rounded bg-sky-400/15 text-sky-300 font-mono text-[10px] font-bold">
                                {rep.linked_lead_ref}
                              </span>
                            )}
                          </div>
                          <div className="font-semibold text-white/90">
                            {rep.subject}
                          </div>
                          <p className="text-white/55 text-[11px]">
                            {rep.snippet}
                          </p>
                        </div>
                        <span className="text-[11px] font-mono text-white/40">
                          {rep.received_at}
                        </span>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              {/* GROWTH & LINKEDIN IA */}
              <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <form
                  onSubmit={async (e) => {
                    e.preventDefault();
                    setGrBusy(true);
                    try {
                      const d = await apiPost("/api/admin/growth_inbox.php", {
                        action: "create_growth_campaign",
                        campaign_name: grName,
                        pillar_focus: grPillar,
                        target_audience: grAudience,
                      });
                      setGrName("");
                      notify(d.message);
                      loadAllConsoleData(currentUser.role);
                    } catch (err) {
                      notify(
                        err instanceof Error
                          ? err.message
                          : "Error generando pieza"
                      );
                    } finally {
                      setGrBusy(false);
                    }
                  }}
                  className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3.5"
                >
                  <div className="text-xs font-bold uppercase tracking-[0.12em] text-violet-300">
                    Generador de Contenido Ejecutivo LinkedIn IA
                  </div>
                  <input
                    type="text"
                    required
                    value={grName}
                    onChange={(e) => setGrName(e.target.value)}
                    placeholder="Tema / Iniciativa (ej: Q4 Banca FSC)"
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                  />
                  <select
                    value={grPillar}
                    onChange={(e) => setGrPillar(e.target.value)}
                    className="w-full px-3 py-2 rounded-xl bg-[#080B11] border border-white/12 text-xs text-white"
                  >
                    <option value="Digital Core">
                      Digital Core (Squads Híbridos & FSC)
                    </option>
                    <option value="DeepShield">
                      DeepShield (Anti-BEC & Clonación Voz)
                    </option>
                    <option value="KeyHub">
                      KeyHub (Gobierno Ingeniería & 97% Tokens)
                    </option>
                  </select>
                  <input
                    type="text"
                    value={grAudience}
                    onChange={(e) => setGrAudience(e.target.value)}
                    placeholder="Audiencia objetivo"
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                  />
                  <button
                    type="submit"
                    disabled={grBusy}
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="w-full py-2.5 rounded-xl bg-violet-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
                  >
                    {grBusy ? "Redactando..." : "Generar Pieza Ejecutiva"}
                  </button>
                </form>

                <div className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3">
                  <div className="text-xs font-bold uppercase tracking-[0.12em] text-white/80">
                    Piezas & Campañas B2B Listas para Publicación
                  </div>

                  {growthCampaigns.length === 0 ? (
                    <div className="py-10 text-center text-xs text-white/45">
                      Genere su primera publicación técnica ejecutiva en el panel izquierdo.
                    </div>
                  ) : (
                    <div className="space-y-3">
                      {growthCampaigns.map((g) => (
                        <div
                          key={g.id}
                          className="p-4 rounded-xl bg-[#080B11] border border-white/10 space-y-2 text-xs"
                        >
                          <div className="flex items-center justify-between">
                            <div>
                              <span className="font-bold text-white">
                                {g.campaign_name}
                              </span>
                              <span className="text-violet-300 ml-2 text-[11px]">
                                · {g.pillar_focus}
                              </span>
                            </div>
                            <button
                              type="button"
                              onClick={async () => {
                                try {
                                  const d = await apiPost(
                                    "/api/admin/growth_inbox.php",
                                    {
                                      action: "publish_linkedin",
                                      id: g.id,
                                    }
                                  );
                                  notify(d.message);
                                  loadAllConsoleData(currentUser.role);
                                } catch (err) {
                                  notify(
                                    err instanceof Error
                                      ? err.message
                                      : "Error publicando"
                                  );
                                }
                              }}
                              className="px-3 py-1 rounded-lg bg-violet-400/20 hover:bg-violet-300 text-violet-200 hover:text-[#0A0E15] text-[11px] font-bold transition-colors"
                            >
                              {g.status === "PUBLISHED"
                                ? "Publicado en LinkedIn"
                                : "Publicar / Aprobar"}
                            </button>
                          </div>
                          <div className="font-semibold text-sky-200">
                            {g.ai_copy_headline}
                          </div>
                          <p className="text-white/65 whitespace-pre-line text-[11px] leading-relaxed">
                            {g.ai_copy_body}
                          </p>
                          {g.published_url && (
                            <a
                              href={g.published_url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="inline-block text-[11px] text-emerald-300 underline"
                            >
                              Ver publicación en vivo en LinkedIn ↗
                            </a>
                          )}
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>

              {/* OUTBOUND B2B CON TRACKING DE APERTURA Y CLIC */}
              <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                <div className="text-xs font-bold uppercase tracking-[0.12em] text-emerald-300">
                  Despacho Outbound B2B con Telemetría de Apertura & Clic (/api/track.php)
                </div>
                <form
                  onSubmit={async (e) => {
                    e.preventDefault();
                    try {
                      const d = await apiPost("/api/admin/growth_inbox.php", {
                        action: "send_outbound_b2b",
                        campaign_id: outboundCampId,
                        recipient_email: outboundToEmail,
                        recipient_name: outboundToName || "Ejecutivo",
                      });
                      setOutboundToEmail("");
                      setOutboundToName("");
                      notify(d.message);
                      loadAllConsoleData(currentUser.role);
                    } catch (err) {
                      notify(
                        err instanceof Error
                          ? err.message
                          : "Error enviando outbound"
                      );
                    }
                  }}
                  className="grid grid-cols-1 md:grid-cols-4 gap-2.5 text-xs"
                >
                  <select
                    value={String(outboundCampId)}
                    onChange={(e) => setOutboundCampId(Number(e.target.value))}
                    className="px-3 py-2 rounded-xl bg-[#080B11] border border-white/15 text-white"
                  >
                    <option value="0">-- Seleccionar Pieza Growth --</option>
                    {growthCampaigns.map((gc) => (
                      <option key={gc.id} value={String(gc.id)}>
                        {gc.campaign_name} ({gc.pillar_focus})
                      </option>
                    ))}
                  </select>
                  <input
                    type="text"
                    value={outboundToName}
                    onChange={(e) => setOutboundToName(e.target.value)}
                    placeholder="Nombre Ejecutivo (Ej: Carlos Soto)"
                    className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-white"
                  />
                  <input
                    type="email"
                    required
                    value={outboundToEmail}
                    onChange={(e) => setOutboundToEmail(e.target.value)}
                    placeholder="Correo Corporativo Destino"
                    className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-white"
                  />
                  <button
                    type="submit"
                    className="py-2 px-4 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] font-bold uppercase tracking-wider inline-flex items-center justify-center gap-1.5 transition-colors"
                  >
                    <Send className="w-3.5 h-3.5" />
                    <span>Enviar con Tracking</span>
                  </button>
                </form>

                {outboundDispatches.length > 0 && (
                  <div className="divide-y divide-white/[0.06] text-xs max-h-52 overflow-y-auto">
                    {outboundDispatches.map((od) => (
                      <div
                        key={od.id}
                        className="py-2.5 flex flex-wrap items-center justify-between gap-2"
                      >
                        <div>
                          <span className="font-semibold text-white">
                            {od.recipient_name}
                          </span>{" "}
                          <span className="text-white/50">
                            &lt;{od.recipient_email}&gt;
                          </span>{" "}
                          <span className="text-white/40 text-[11px]">
                            · {od.subject}
                          </span>
                        </div>
                        <div className="flex items-center gap-2">
                          <span
                            className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                              od.status === "CLICKED"
                                ? "bg-emerald-400/20 text-emerald-300"
                                : od.status === "OPENED"
                                ? "bg-sky-400/20 text-sky-300"
                                : "bg-white/[0.07] text-white/70"
                            }`}
                          >
                            {od.status}
                          </span>
                          <span className="font-mono text-[10px] text-white/35">
                            {od.clicked_at || od.opened_at || od.created_at}
                          </span>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          )}

          {/* =================================================================
              MODULE 5: MOTOR SMTP TITAN, WHATSAPP EVOLUTION & GOOGLE SSO
          ================================================================== */}
          {activeNav === "channels" && currentUser.role === "superadmin" && (
            <div className="space-y-6">
              <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                  <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-sky-300">
                    Despacho Transaccional en Tiempo Real · Evolution API & Titan Mail
                  </div>
                  <h1
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-2xl font-bold text-white mt-1"
                  >
                    Motor SMTP, Alertas WhatsApp & SSO
                  </h1>
                </div>
              </div>

              <form onSubmit={handleSaveNotifSettings} className="space-y-5">
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                  {/* SMTP TITAN */}
                  <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3.5">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] text-sky-300">
                        <Mail className="w-4 h-4" />
                        <span>Servidor SMTP Corporativo</span>
                      </div>
                      <input
                        type="checkbox"
                        checked={notifSettings.smtp_enabled}
                        onChange={(e) =>
                          setNotifSettings({
                            ...notifSettings,
                            smtp_enabled: e.target.checked,
                          })
                        }
                        className="w-4 h-4 accent-sky-400"
                      />
                    </div>

                    <div className="grid grid-cols-3 gap-2.5">
                      <div className="col-span-2">
                        <label className="block text-[10px] uppercase text-white/45 mb-1">
                          Host SMTP
                        </label>
                        <input
                          type="text"
                          value={notifSettings.smtp_host}
                          onChange={(e) =>
                            setNotifSettings({
                              ...notifSettings,
                              smtp_host: e.target.value,
                            })
                          }
                          className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                        />
                      </div>
                      <div>
                        <label className="block text-[10px] uppercase text-white/45 mb-1">
                          Puerto SSL/TLS
                        </label>
                        <input
                          type="number"
                          value={notifSettings.smtp_port}
                          onChange={(e) =>
                            setNotifSettings({
                              ...notifSettings,
                              smtp_port: Number(e.target.value),
                            })
                          }
                          className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                        />
                      </div>
                    </div>

                    <div className="grid grid-cols-2 gap-2.5">
                      <div>
                        <label className="block text-[10px] uppercase text-white/45 mb-1">
                          Usuario SMTP
                        </label>
                        <input
                          type="text"
                          value={notifSettings.smtp_user}
                          onChange={(e) =>
                            setNotifSettings({
                              ...notifSettings,
                              smtp_user: e.target.value,
                            })
                          }
                          className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                        />
                      </div>
                      <div>
                        <label className="block text-[10px] uppercase text-white/45 mb-1">
                          Clave SMTP{" "}
                          {notifSettings.has_smtp_pass && "(Configurada)"}
                        </label>
                        <input
                          type="password"
                          value={notifSettings.smtp_pass}
                          onChange={(e) =>
                            setNotifSettings({
                              ...notifSettings,
                              smtp_pass: e.target.value,
                            })
                          }
                          placeholder={
                            notifSettings.has_smtp_pass
                              ? "•••••••• (dejar vacío mantiene)"
                              : "Ingrese contraseña"
                          }
                          className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                        />
                      </div>
                    </div>

                    <div className="grid grid-cols-2 gap-2.5">
                      <div>
                        <label className="block text-[10px] uppercase text-white/45 mb-1">
                          Correo Receptor Alertas B2B
                        </label>
                        <input
                          type="email"
                          value={notifSettings.sales_alert_email}
                          onChange={(e) =>
                            setNotifSettings({
                              ...notifSettings,
                              sales_alert_email: e.target.value,
                            })
                          }
                          className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                        />
                      </div>
                      <div className="flex items-end pb-2">
                        <label className="flex items-center gap-2 text-xs text-white/80 cursor-pointer">
                          <input
                            type="checkbox"
                            checked={notifSettings.send_prospect_autoresponder}
                            onChange={(e) =>
                              setNotifSettings({
                                ...notifSettings,
                                send_prospect_autoresponder: e.target.checked,
                              })
                            }
                            className="accent-sky-400"
                          />
                          <span>Auto-respuesta al cliente</span>
                        </label>
                      </div>
                    </div>

                    <div className="pt-2 border-t border-white/[0.07] flex items-center gap-2">
                      <input
                        type="email"
                        value={testEmailTo}
                        onChange={(e) => setTestEmailTo(e.target.value)}
                        className="flex-1 px-3 py-1.5 rounded-lg bg-[#080B11] border border-white/12 text-xs text-white"
                      />
                      <button
                        type="button"
                        onClick={handleTestSmtp}
                        className="px-3 py-1.5 rounded-lg bg-sky-400/20 hover:bg-sky-300 text-sky-200 hover:text-[#0A0E15] text-xs font-semibold transition-colors"
                      >
                        Probar SMTP
                      </button>
                    </div>
                  </div>

                  {/* WHATSAPP EVOLUTION API + GOOGLE SSO */}
                  <div className="space-y-5">
                    <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3.5">
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] text-emerald-300">
                          <MessageCircle className="w-4 h-4" />
                          <span>Alertas WhatsApp (Evolution API)</span>
                        </div>
                        <input
                          type="checkbox"
                          checked={notifSettings.whatsapp_enabled}
                          onChange={(e) =>
                            setNotifSettings({
                              ...notifSettings,
                              whatsapp_enabled: e.target.checked,
                            })
                          }
                          className="w-4 h-4 accent-emerald-400"
                        />
                      </div>

                      <div className="grid grid-cols-2 gap-2.5">
                        <div>
                          <label className="block text-[10px] uppercase text-white/45 mb-1">
                            Instancia Activa
                          </label>
                          <input
                            type="text"
                            value={notifSettings.whatsapp_instance}
                            onChange={(e) =>
                              setNotifSettings({
                                ...notifSettings,
                                whatsapp_instance: e.target.value,
                              })
                            }
                            className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                          />
                        </div>
                        <div>
                          <label className="block text-[10px] uppercase text-white/45 mb-1">
                            Teléfono Alerta Socio
                          </label>
                          <input
                            type="text"
                            value={notifSettings.whatsapp_alert_phone}
                            onChange={(e) =>
                              setNotifSettings({
                                ...notifSettings,
                                whatsapp_alert_phone: e.target.value,
                              })
                            }
                            className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                          />
                        </div>
                      </div>

                      <div className="pt-2 border-t border-white/[0.07] flex items-center gap-2">
                        <input
                          type="text"
                          value={testWaPhone}
                          onChange={(e) => setTestWaPhone(e.target.value)}
                          className="flex-1 px-3 py-1.5 rounded-lg bg-[#080B11] border border-white/12 text-xs text-white"
                        />
                        <button
                          type="button"
                          onClick={handleTestWhatsApp}
                          className="px-3 py-1.5 rounded-lg bg-emerald-400/20 hover:bg-emerald-300 text-emerald-200 hover:text-[#0A0E15] text-xs font-semibold transition-colors"
                        >
                          Probar WhatsApp
                        </button>
                      </div>
                    </div>

                    <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3">
                      <div className="flex items-center justify-between">
                        <div className="text-xs font-bold uppercase tracking-[0.12em] text-violet-300">
                          Google Workspace SSO Corporativo
                        </div>
                        <input
                          type="checkbox"
                          checked={notifSettings.google_sso_enabled}
                          onChange={(e) =>
                            setNotifSettings({
                              ...notifSettings,
                              google_sso_enabled: e.target.checked,
                            })
                          }
                          className="w-4 h-4 accent-violet-400"
                        />
                      </div>
                      <div className="grid grid-cols-2 gap-2.5">
                        <div>
                          <label className="block text-[10px] uppercase text-white/45 mb-1">
                            Dominio Permitido
                          </label>
                          <input
                            type="text"
                            value={notifSettings.google_allowed_domain}
                            onChange={(e) =>
                              setNotifSettings({
                                ...notifSettings,
                                google_allowed_domain: e.target.value,
                              })
                            }
                            className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                          />
                        </div>
                        <div>
                          <label className="block text-[10px] uppercase text-white/45 mb-1">
                            OAuth Client ID
                          </label>
                          <input
                            type="text"
                            value={notifSettings.google_client_id}
                            onChange={(e) =>
                              setNotifSettings({
                                ...notifSettings,
                                google_client_id: e.target.value,
                              })
                            }
                            className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                          />
                        </div>
                      </div>

                      <div className="pt-2 border-t border-white/10 space-y-2.5">
                        <div className="text-[11px] font-bold uppercase tracking-wider text-violet-300">
                          Conector API Oficial LinkedIn (REST v202610) & Casilla DMARC RUA
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                          <input
                            type="password"
                            value={notifSettings.linkedin_access_token || ""}
                            onChange={(e) =>
                              setNotifSettings({
                                ...notifSettings,
                                linkedin_access_token: e.target.value,
                              })
                            }
                            placeholder={
                              notifSettings.has_linkedin_token
                                ? "LinkedIn Token configurado (dejar vacío para mantener)"
                                : "LinkedIn OAuth Access Token"
                            }
                            className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                          />
                          <input
                            type="text"
                            value={notifSettings.linkedin_org_urn || ""}
                            onChange={(e) =>
                              setNotifSettings({
                                ...notifSettings,
                                linkedin_org_urn: e.target.value,
                              })
                            }
                            placeholder="Organization URN / ID (Opc.)"
                            className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                          />
                          <input
                            type="email"
                            value={notifSettings.dmarc_rua_user || ""}
                            onChange={(e) =>
                              setNotifSettings({
                                ...notifSettings,
                                dmarc_rua_user: e.target.value,
                              })
                            }
                            placeholder="Casilla RUA (ej: dmarc-rua@keystoneadvisors.cl)"
                            className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                          />
                          <input
                            type="password"
                            value={notifSettings.dmarc_rua_pass || ""}
                            onChange={(e) =>
                              setNotifSettings({
                                ...notifSettings,
                                dmarc_rua_pass: e.target.value,
                              })
                            }
                            placeholder={
                              notifSettings.has_rua_pass
                                ? "Clave RUA configurada (vacío mantiene)"
                                : "Clave IMAP Casilla RUA"
                            }
                            className="px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                          />
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div className="flex justify-end">
                  <button
                    type="submit"
                    disabled={notifSaving}
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="px-5 py-2.5 rounded-xl bg-white hover:bg-violet-300 text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
                  >
                    {notifSaving
                      ? "Guardando..."
                      : "Guardar Configuración de Canales"}
                  </button>
                </div>
              </form>

              {/* DISPATCH LOGS */}
              <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3">
                <div
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="text-xs font-bold uppercase tracking-[0.12em] text-white/80"
                >
                  Bitácora de Despachos SMTP & WhatsApp (Últimos 30)
                </div>
                <div className="divide-y divide-white/[0.05] text-xs max-h-56 overflow-y-auto">
                  {dispatchLogs.length === 0 ? (
                    <div className="py-6 text-center text-white/40">
                      Sin despachos registrados aún.
                    </div>
                  ) : (
                    dispatchLogs.map((d) => (
                      <div
                        key={d.id}
                        className="py-2 flex flex-wrap items-center justify-between gap-2"
                      >
                        <div className="flex items-center gap-2">
                          <span className="px-2 py-0.5 rounded bg-white/[0.06] font-mono text-[10px] text-sky-300">
                            {d.channel}
                          </span>
                          <span className="font-medium text-white">
                            {d.recipient}
                          </span>
                          <span className="text-white/50 truncate max-w-xs">
                            {d.subject_or_ref}
                          </span>
                        </div>
                        <div className="flex items-center gap-2">
                          <span
                            className={`text-[10px] font-bold ${d.status === "SENT"
                                ? "text-emerald-300"
                                : "text-amber-300"
                              }`}
                          >
                            {d.status}
                          </span>
                          <span className="text-[10px] text-white/35 font-mono">
                            {d.created_at}
                          </span>
                        </div>
                      </div>
                    ))
                  )}
                </div>
              </div>
            </div>
          )}

          {/* =================================================================
              MODULE 6: ACCESOS RBAC, SEGUNDO FACTOR 2FA TOTP & AUDITORÍA
          ================================================================== */}
          {activeNav === "governance" && (
            <div className="space-y-6">
              <div>
                <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-violet-300">
                  Seguridad de Identidad · TOTP RFC 6238 & Control RBAC
                </div>
                <h1
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="text-2xl font-bold text-white mt-1"
                >
                  Mi Seguridad 2FA & Operadores
                </h1>
              </div>

              {/* MY 2FA TOTP CARD */}
              <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                  <div className="flex items-start gap-3">
                    <Fingerprint className="w-6 h-6 text-emerald-300 shrink-0 mt-0.5" />
                    <div>
                      <div className="text-sm font-bold text-white">
                        Autenticación de Dos Factores (2FA TOTP) — @
                        {currentUser.username}
                      </div>
                      <p className="text-xs text-white/55 mt-0.5">
                        Proteja su sesión ejecutiva con códigos rotativos cada
                        30 segundos compatibles con Google Authenticator, Authy
                        o 1Password.
                      </p>
                    </div>
                  </div>

                  {currentUser.totp_enabled ? (
                    <button
                      type="button"
                      onClick={handleDisableMy2Fa}
                      className="px-4 py-2 rounded-xl bg-rose-500/15 hover:bg-rose-500/25 border border-rose-400/30 text-xs font-semibold text-rose-200"
                    >
                      Desactivar 2FA
                    </button>
                  ) : (
                    <button
                      type="button"
                      onClick={handleStart2FaSetup}
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="px-4 py-2 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider"
                    >
                      Configurar 2FA Ahora
                    </button>
                  )}
                </div>

                {totpSetupSecret && (
                  <form
                    onSubmit={handleConfirm2Fa}
                    className="rounded-xl bg-[#080B11] border border-emerald-400/30 p-4 space-y-3"
                  >
                    <div className="text-xs text-white/80">
                      1. Ingrese esta clave secreta Base32 en su aplicación
                      autenticadora (o escanee vía URI):
                    </div>
                    <div className="font-mono text-sm font-bold tracking-widest text-emerald-300 bg-white/[0.04] px-3 py-2 rounded-lg inline-block">
                      {totpSetupSecret}
                    </div>
                    {totpSetupUrl && (
                      <div className="text-[11px] text-white/40 break-all font-mono">
                        {totpSetupUrl}
                      </div>
                    )}
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                      <input
                        type="text"
                        required
                        maxLength={6}
                        value={totpVerifyInput}
                        onChange={(e) => setTotpVerifyInput(e.target.value)}
                        placeholder="Código 6 dígitos"
                        className="px-3.5 py-2 rounded-xl bg-white/[0.06] border border-white/20 font-mono text-sm text-white w-44"
                      />
                      <button
                        type="submit"
                        className="px-4 py-2 rounded-xl bg-emerald-300 text-[#0A0E15] text-xs font-bold uppercase"
                      >
                        Confirmar y Activar
                      </button>
                    </div>
                  </form>
                )}
              </div>

              {currentUser.role === "superadmin" && (
                <>
                  <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
                    <form
                      onSubmit={handleCreateUser}
                      className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3"
                    >
                      <div
                        style={{
                          fontFamily: "var(--font-jakarta), sans-serif",
                        }}
                        className="text-xs font-bold uppercase tracking-[0.12em] text-white/80 flex items-center gap-2"
                      >
                        <UserPlus className="w-4 h-4 text-violet-300" />
                        <span>Habilitar Nuevo Operador</span>
                      </div>

                      <input
                        type="text"
                        required
                        value={newOpUsername}
                        onChange={(e) => setNewOpUsername(e.target.value)}
                        placeholder="Usuario (ej: socio.comercial)"
                        className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                      <input
                        type="email"
                        value={newOpEmail}
                        onChange={(e) => setNewOpEmail(e.target.value)}
                        placeholder="Correo SSO (@keystoneadvisors.cl)"
                        className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                      <input
                        type="text"
                        required
                        value={newOpName}
                        onChange={(e) => setNewOpName(e.target.value)}
                        placeholder="Nombre Completo"
                        className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                      <select
                        value={newOpRole}
                        onChange={(e) =>
                          setNewOpRole(e.target.value as AdminRole)
                        }
                        className="w-full px-3 py-2 rounded-xl bg-[#080B11] border border-white/12 text-xs text-white"
                      >
                        <option value="comercial">Comercial & Catálogo</option>
                        <option value="secops">SecOps & DeepShield</option>
                        <option value="superadmin">Superadmin Total</option>
                      </select>
                      <input
                        type="password"
                        required
                        minLength={8}
                        value={newOpPass}
                        onChange={(e) => setNewOpPass(e.target.value)}
                        placeholder="Contraseña inicial (mín. 8)"
                        className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                      />
                      <button
                        type="submit"
                        style={{
                          fontFamily: "var(--font-jakarta), sans-serif",
                        }}
                        className="w-full py-2.5 rounded-xl bg-white hover:bg-violet-300 text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
                      >
                        Crear Operador
                      </button>
                    </form>

                    <div className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
                      <div
                        style={{
                          fontFamily: "var(--font-jakarta), sans-serif",
                        }}
                        className="text-xs font-bold uppercase tracking-[0.12em] text-white/80"
                      >
                        Nómina de Operadores Autorizados
                      </div>

                      <div className="divide-y divide-white/[0.06]">
                        {usersList.map((u) => (
                          <div
                            key={u.id}
                            className="py-3 flex flex-wrap items-center justify-between gap-3 text-xs"
                          >
                            <div>
                              <div className="font-semibold text-white">
                                {u.full_name || u.username}{" "}
                                <span className="text-white/40 font-normal">
                                  (@{u.username})
                                </span>
                              </div>
                              <div className="text-[11px] text-white/45">
                                {u.email ? `${u.email} · ` : ""}Último acceso:{" "}
                                {u.last_login || "Sin registro"}
                              </div>
                            </div>

                            <div className="flex items-center gap-2">
                              {Number(u.totp_enabled) === 1 && (
                                <span className="px-2 py-0.5 rounded bg-emerald-400/15 text-emerald-300 text-[10px] font-bold">
                                  2FA
                                </span>
                              )}
                              <span className="px-2.5 py-0.5 rounded-md bg-violet-400/15 border border-violet-400/30 text-[10px] font-bold uppercase text-violet-300">
                                {u.role}
                              </span>
                              {u.id !== currentUser.id && (
                                <button
                                  type="button"
                                  onClick={() => handleToggleUser(u.id)}
                                  className={`px-2.5 py-1 rounded-lg text-[11px] font-semibold border transition-colors ${u.is_active
                                      ? "bg-emerald-400/15 text-emerald-300 border-emerald-400/30"
                                      : "bg-rose-500/15 text-rose-300 border-rose-400/30"
                                    }`}
                                >
                                  {u.is_active ? "Activo" : "Suspendido"}
                                </button>
                              )}
                              <button
                                type="button"
                                onClick={async () => {
                                  const np = window.prompt(
                                    `Nueva clave para @${u.username} (mín. 8 caracteres):`
                                  );
                                  if (!np) return;
                                  try {
                                    await apiPost("/api/admin/auth.php", {
                                      action: "reset_password",
                                      user_id: u.id,
                                      new_password: np,
                                    });
                                    notify("Contraseña actualizada.");
                                  } catch (err) {
                                    notify(
                                      err instanceof Error
                                        ? err.message
                                        : "Error al rotar clave"
                                    );
                                  }
                                }}
                                title="Rotar contraseña"
                                className="p-1.5 rounded-lg bg-white/[0.05] hover:bg-white/15 text-white/70"
                              >
                                <KeyRound className="w-3.5 h-3.5" />
                              </button>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>

                  {/* RESPALDO ATÓMICO SQLITE & MIGRADOR PRE-CORTE A PRODUCCIÓN */}
                  <div className="rounded-2xl bg-[#0D121C] border border-emerald-400/25 p-5 space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div>
                        <div
                          style={{
                            fontFamily: "var(--font-jakarta), sans-serif",
                          }}
                          className="text-xs font-bold uppercase tracking-[0.12em] text-emerald-300"
                        >
                          Continuidad Operativa · Respaldo SQLite & Migrador Pre-Paso a Producción
                        </div>
                        <p className="text-xs text-white/55 mt-0.5">
                          Genere snapshots consistentes (`VACUUM INTO`) de `spatial_leads.sqlite` o importe datos históricos en modo lectura segura sin tocar producción.
                        </p>
                      </div>

                      <div className="flex flex-wrap items-center gap-2">
                        <a
                          href="/api/admin/backup_migrator.php?action=download_sqlite"
                          className="px-3.5 py-2 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider inline-flex items-center gap-1.5 transition-colors"
                        >
                          <FileDown className="w-3.5 h-3.5" />
                          <span>Descargar Snapshot .sqlite</span>
                        </a>

                        {backupMeta?.legacy_source?.available && (
                          <button
                            type="button"
                            disabled={migratingLegacy}
                            onClick={async () => {
                              if (
                                !window.confirm(
                                  "¿Importar registros históricos en modo 100% lectura segura hacia staging (sin modificar producción)?"
                                )
                              ) {
                                return;
                              }
                              setMigratingLegacy(true);
                              try {
                                const d = await apiPost(
                                  "/api/admin/backup_migrator.php",
                                  {
                                    action: "import_legacy_readonly",
                                  }
                                );
                                notify(d.message);
                                loadAllConsoleData(currentUser.role);
                              } catch (err) {
                                notify(
                                  err instanceof Error
                                    ? err.message
                                    : "Error importando histórico"
                                );
                              } finally {
                                setMigratingLegacy(false);
                              }
                            }}
                            className="px-3.5 py-2 rounded-xl bg-sky-400/20 hover:bg-sky-300 text-sky-200 hover:text-[#0A0E15] border border-sky-400/35 text-xs font-bold inline-flex items-center gap-1.5 transition-colors"
                          >
                            <RefreshCw
                              className={`w-3.5 h-3.5 ${
                                migratingLegacy ? "animate-spin" : ""
                              }`}
                            />
                            <span>Sincronizar Histórico Legacy</span>
                          </button>
                        )}
                      </div>
                    </div>

                    {backupMeta?.staging_stats && (
                      <div className="grid grid-cols-2 sm:grid-cols-5 gap-2.5 text-xs">
                        {Object.entries(backupMeta.staging_stats).map(
                          ([k, v]) => (
                            <div
                              key={k}
                              className="p-2.5 rounded-xl bg-[#080B11] border border-white/10"
                            >
                              <div className="text-[10px] uppercase text-white/45 font-mono">
                                {k}
                              </div>
                              <div className="text-sm font-bold text-white mt-0.5">
                                {v}
                              </div>
                            </div>
                          )
                        )}
                      </div>
                    )}
                  </div>

                  {/* AUDIT TRAIL */}
                  <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3">
                    <div
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="text-xs font-bold uppercase tracking-[0.12em] text-white/80"
                    >
                      Bitácora de Cadena de Custodia (Últimos 40 Eventos)
                    </div>

                    <div className="divide-y divide-white/[0.05] text-xs max-h-64 overflow-y-auto">
                      {auditLogs.map((log) => (
                        <div
                          key={log.id}
                          className="py-2 flex flex-wrap items-center justify-between gap-2"
                        >
                          <div className="flex items-center gap-2.5">
                            <span className="font-mono text-[10px] px-2 py-0.5 rounded bg-white/[0.06] text-violet-300">
                              {log.action_code}
                            </span>
                            <span className="text-white font-medium">
                              @{log.actor}
                            </span>
                            <span className="text-white/60">{log.detail}</span>
                          </div>
                          <span className="text-[11px] text-white/35 font-mono">
                            {log.created_at} · {log.ip_address}
                          </span>
                        </div>
                      ))}
                    </div>
                  </div>
                </>
              )}
            </div>
          )}
        </main>
      </div>

      {/* =====================================================================
          SLIDE-OVER DRAWER: GESTIÓN DE SOLICITUD B2B + PUENTE KEYHUB 1-CLIC
      ====================================================================== */}
      {selectedLead && (
        <div className="fixed inset-0 z-50 flex justify-end bg-black/60 backdrop-blur-sm">
          <div className="w-full max-w-md bg-[#0B0F17] border-l border-white/15 h-full p-6 flex flex-col justify-between overflow-y-auto shadow-2xl space-y-6">
            <div className="space-y-5">
              <form onSubmit={handleSaveLeadDrawer} className="space-y-4">
                <div className="flex items-start justify-between border-b border-white/10 pb-4">
                  <div>
                    <span className="font-mono text-[11px] text-sky-300">
                      {selectedLead.order_ref}
                    </span>
                    <h3
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="text-lg font-bold text-white mt-0.5 break-all"
                    >
                      {selectedLead.email}
                    </h3>
                    <p className="text-xs text-white/50">
                      {selectedLead.pillar} · {selectedLead.service_name}
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={() => setSelectedLead(null)}
                    className="p-1.5 rounded-lg bg-white/[0.06] text-white/60 hover:text-white"
                  >
                    <X className="w-4 h-4" />
                  </button>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-[11px] text-white/55 mb-1">
                      Etapa del Pipeline
                    </label>
                    <select
                      value={selectedLead.status}
                      onChange={(e) =>
                        setSelectedLead({
                          ...selectedLead,
                          status: e.target.value,
                        })
                      }
                      className="w-full px-3 py-2 rounded-xl bg-[#080B11] border border-white/15 text-xs text-white"
                    >
                      {Object.entries(LEAD_STATUS_META).map(([k, v]) => (
                        <option key={k} value={k}>
                          {v.label}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div>
                    <label className="block text-[11px] text-white/55 mb-1">
                      Prioridad
                    </label>
                    <select
                      value={selectedLead.priority}
                      onChange={(e) =>
                        setSelectedLead({
                          ...selectedLead,
                          priority: e.target.value,
                        })
                      }
                      className="w-full px-3 py-2 rounded-xl bg-[#080B11] border border-white/15 text-xs text-white"
                    >
                      <option value="CRITICA">Crítica</option>
                      <option value="ALTA">Alta</option>
                      <option value="MEDIA">Media</option>
                      <option value="BAJA">Baja</option>
                    </select>
                  </div>
                </div>

                <div>
                  <label className="block text-[11px] text-white/55 mb-1">
                    Socio / Director Asignado
                  </label>
                  <input
                    type="text"
                    value={selectedLead.assigned_to}
                    onChange={(e) =>
                      setSelectedLead({
                        ...selectedLead,
                        assigned_to: e.target.value,
                      })
                    }
                    placeholder="Ej: Rodrigo Díaz"
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/15 text-xs text-white"
                  />
                </div>

                <div>
                  <label className="block text-[11px] text-white/55 mb-1">
                    Bitácora Ejecutiva & Contexto de Negocio
                  </label>
                  <textarea
                    rows={4}
                    value={selectedLead.notes}
                    onChange={(e) =>
                      setSelectedLead({
                        ...selectedLead,
                        notes: e.target.value,
                      })
                    }
                    placeholder="Acuerdos de alcance, dolores detectados o hallazgos DMARC..."
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/15 text-xs text-white resize-none"
                  />
                </div>

                <div className="flex items-center gap-2">
                  <button
                    type="submit"
                    disabled={drawerSaving}
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="flex-1 py-2.5 rounded-xl bg-sky-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
                  >
                    {drawerSaving ? "Guardando..." : "Guardar Cambios"}
                  </button>
                  <a
                    href={`mailto:${selectedLead.email}?subject=${encodeURIComponent(
                      `Keystone Advisors | ${selectedLead.service_name || "Diagnóstico Ejecutivo"
                      }`
                    )}`}
                    className="px-3.5 py-2.5 rounded-xl bg-white/[0.07] hover:bg-white/15 text-xs font-semibold text-white transition-colors"
                  >
                    Correo
                  </a>
                </div>
              </form>

              {/* PUENTE NATIVO 1-CLIC HACIA KEYHUB PRODUCCIÓN */}
              <div className="rounded-xl bg-violet-500/10 border border-violet-400/30 p-4 space-y-3">
                <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.1em] text-violet-300">
                  <Kanban className="w-4 h-4" />
                  <span>Puente Directo a KeyHub</span>
                </div>

                {selectedLead.keyhub_card_code ? (
                  <div className="text-xs text-emerald-200 flex items-center justify-between">
                    <span>
                      Vinculada a tarjeta{" "}
                      <strong className="font-mono text-white">
                        {selectedLead.keyhub_card_code}
                      </strong>
                    </span>
                    <CheckCircle2 className="w-4 h-4 text-emerald-400" />
                  </div>
                ) : (
                  <>
                    <p className="text-[11px] text-white/65">
                      Crea una tarjeta formal en el tablero oficial de KeyHub
                      inyectando contacto, servicio cotizado y score BEC del
                      dominio:
                    </p>
                    <select
                      value={selectedKhProject}
                      onChange={(e) => setSelectedKhProject(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg bg-[#080B11] border border-violet-400/30 text-xs text-white"
                    >
                      {keyhubProjects.length > 0 ? (
                        keyhubProjects.map((p) => (
                          <option key={p.id} value={p.id}>
                            [{p.project_key}] {p.name}
                          </option>
                        ))
                      ) : (
                        <option value="prj_ksweb_01">
                          [KSWEB] Sitio Web Corporativo & Ecosistema
                        </option>
                      )}
                    </select>
                    <button
                      type="button"
                      disabled={syncingKeyHub}
                      onClick={handleSyncLeadToKeyHub}
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="w-full py-2 rounded-lg bg-violet-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors flex items-center justify-center gap-1.5"
                    >
                      {syncingKeyHub ? (
                        <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      ) : (
                        <Kanban className="w-3.5 h-3.5" />
                      )}
                      <span>Sincronizar a KeyHub en 1 Clic</span>
                    </button>
                  </>
                )}
              </div>
            </div>

            <div className="pt-4 border-t border-white/10 text-[11px] text-white/40 flex items-center justify-between">
              <span>IP Origen: {selectedLead.ip_address || "N/D"}</span>
              {currentUser.role === "superadmin" && (
                <button
                  type="button"
                  onClick={async () => {
                    if (!window.confirm("¿Eliminar definitivamente este lead?"))
                      return;
                    await apiPost("/api/admin/leads.php", {
                      action: "delete_lead",
                      id: selectedLead.id,
                    });
                    setSelectedLead(null);
                    loadAllConsoleData(currentUser.role);
                  }}
                  className="text-rose-400 hover:underline"
                >
                  Eliminar registro
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* MODAL NUEVA OPORTUNIDAD */}
      {newLeadModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
          <form
            onSubmit={handleCreateManualLead}
            className="w-full max-w-md rounded-2xl bg-[#0D121C] border border-white/15 p-6 space-y-4 shadow-2xl"
          >
            <div className="flex items-center justify-between">
              <h3
                style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                className="text-base font-bold text-white"
              >
                Registrar Oportunidad B2B
              </h3>
              <button
                type="button"
                onClick={() => setNewLeadModal(false)}
                className="text-white/50 hover:text-white"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div>
              <label className="block text-[11px] text-white/55 mb-1">
                Correo Corporativo
              </label>
              <input
                type="email"
                required
                value={newLeadEmail}
                onChange={(e) => setNewLeadEmail(e.target.value)}
                placeholder="gerencia@empresa.cl"
                className="w-full px-3 py-2 rounded-xl bg-white/[0.05] border border-white/15 text-xs text-white"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-[11px] text-white/55 mb-1">
                  Pilar
                </label>
                <select
                  value={newLeadPillar}
                  onChange={(e) => setNewLeadPillar(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl bg-[#080B11] border border-white/15 text-xs text-white"
                >
                  <option value="Digital Core">Digital Core</option>
                  <option value="DeepShield">DeepShield</option>
                  <option value="KeyHub">KeyHub</option>
                </select>
              </div>
              <div>
                <label className="block text-[11px] text-white/55 mb-1">
                  Servicio / Solución
                </label>
                <input
                  type="text"
                  value={newLeadService}
                  onChange={(e) => setNewLeadService(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl bg-white/[0.05] border border-white/15 text-xs text-white"
                />
              </div>
            </div>

            <div>
              <label className="block text-[11px] text-white/55 mb-1">
                Notas Iniciales
              </label>
              <textarea
                rows={3}
                value={newLeadNotes}
                onChange={(e) => setNewLeadNotes(e.target.value)}
                className="w-full px-3 py-2 rounded-xl bg-white/[0.05] border border-white/15 text-xs text-white resize-none"
              />
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => setNewLeadModal(false)}
                className="px-4 py-2 rounded-xl bg-white/[0.06] text-xs text-white/75"
              >
                Cancelar
              </button>
              <button
                type="submit"
                style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                className="px-4 py-2 rounded-xl bg-sky-300 text-[#0A0E15] text-xs font-bold uppercase tracking-wider"
              >
                Crear Registro
              </button>
            </div>
          </form>
        </div>
      )}

      {/* MODAL INTERACTIVO: MANUAL OPERATIVO ESTÁNDAR (SOP-SEC-DMARC-01) */}
      {sopModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm p-4">
          <div className="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-2xl bg-[#0D121C] border border-white/15 p-6 sm:p-7 shadow-2xl space-y-5">
            <div className="flex items-start justify-between border-b border-white/10 pb-4">
              <div>
                <div className="text-[11px] font-bold uppercase tracking-[0.14em] text-sky-300">
                  SOP-SEC-DMARC-01 · Keystone Cyber Defense Suite
                </div>
                <h3
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="text-xl font-bold text-white mt-1"
                >
                  Manual Operativo: Remediación DMARC & Evaluación Ejecutiva
                </h3>
                <p className="text-xs text-white/55 mt-0.5">
                  Procedimiento paso a paso para ejecutar el blindaje Quick Win sin fricción técnica.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setSopModalOpen(false)}
                className="p-1.5 rounded-lg bg-white/[0.06] text-white/60 hover:text-white"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="rounded-xl bg-sky-400/10 border border-sky-400/30 p-4 flex flex-wrap items-center justify-between gap-3 text-xs">
              <div className="text-sky-100 max-w-xl leading-relaxed">
                <strong className="text-sky-300">Meta del Procedimiento:</strong>{" "}
                Llevar a cualquier empresa con exposición crítica (30/100) a{" "}
                <strong className="text-white">Blindaje DMARC Strict (100/100)</strong>{" "}
                en un ciclo de 7 días, emitiendo el Dosier Pericial final para el Directorio.
              </div>
              <a
                href="/MANUAL_OPERATIVO_SOP_DMARC_QUICKWIN.docx"
                download
                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-sky-300 hover:bg-white text-[#0A0E15] font-bold transition-colors shrink-0"
              >
                <FileDown className="w-3.5 h-3.5" />
                <span>Descargar Manual Word (.docx)</span>
              </a>
            </div>

            <div className="space-y-3 text-xs">
              {[
                {
                  step: "Paso 1: Captura de Lead y Respaldo de Diagnóstico Inicial",
                  day: "Día 1",
                  badge: "bg-rose-500/20 text-rose-300",
                  desc: "Ingresar a Escaneos BEC, ubicar al cliente y descargar el Dosier PDF preliminar con puntaje de exposición inicial como constancia pericial antes de aplicar cambios.",
                },
                {
                  step: "Paso 2: Incorporar Dominio en Watchdog & Crear Tenant",
                  day: "Día 1",
                  badge: "bg-sky-400/15 text-sky-300",
                  desc: "En Radar BEC & Watchdog o Tenants & QR, dar de alta la razón social y el dominio raíz exacto (sin www) para aislar su telemetría.",
                },
                {
                  step: "Paso 3: Entregar el Registro DNS de Auditoría al Cliente",
                  day: "Días 1 a 2",
                  badge: "bg-sky-400/15 text-sky-300",
                  desc: "Enviar al administrador TI del cliente la instrucción para publicar el registro TXT inicial en modo monitoreo:",
                  code: 'Host: _dmarc | Tipo: TXT | Valor: "v=DMARC1; p=none; rua=mailto:dmarc-rua@keystoneadvisors.cl; ruf=mailto:dmarc-rua@keystoneadvisors.cl; fo=1;"',
                },
                {
                  step: "Paso 4: Monitoreo Forense e Ingesta RUA",
                  day: "Días 2 a 5",
                  badge: "bg-amber-400/15 text-amber-200",
                  desc: "Cargar en la pestaña DMARC RUA los reportes agregados XML/GZ/ZIP de Google y Microsoft, separando emisores legítimos (Workspace, M365, ERP/CRM) de IPs suplantadoras.",
                },
                {
                  step: "Paso 5: Ajuste de SPF y Cierre a Modo Estricto (p=reject)",
                  day: "Día 6",
                  badge: "bg-emerald-400/15 text-emerald-300",
                  desc: "Verificado el 100% de tráfico legítimo autenticado, instruir la actualización definitiva a bloqueo criptográfico:",
                  code: 'Host: _dmarc | Tipo: TXT | Valor: "v=DMARC1; p=reject; rua=mailto:dmarc-rua@keystoneadvisors.cl; aspf=r; adkim=r; pct=100;"',
                },
                {
                  step: "Paso 6: Endurecimiento Web (Cabeceras HSTS y Anti-Clickjacking)",
                  day: "Día 6",
                  badge: "bg-white/10 text-white/70",
                  desc: "Habilitar cabeceras Strict-Transport-Security: max-age=31536000; includeSubDomains; preload y X-Frame-Options: SAMEORIGIN.",
                },
                {
                  step: "Paso 7: Re-escaneo Final y Emisión del Certificado 100/100",
                  day: "Día 7 (Cierre)",
                  badge: "bg-emerald-400 text-[#0A0E15]",
                  desc: "Ejecutar Auditar en vivo para certificar propagación DNS 100/100 (Blindado) y emitir el Dosier Pericial PDF de Cierre para Directorio.",
                },
              ].map((item, i) => (
                <div
                  key={i}
                  className="rounded-xl bg-[#080B11] border border-white/10 p-4 space-y-2"
                >
                  <div className="flex items-center justify-between gap-2">
                    <strong className="text-white text-[13px]">
                      {item.step}
                    </strong>
                    <span
                      className={`px-2.5 py-0.5 rounded-full text-[10px] font-bold shrink-0 ${item.badge}`}
                    >
                      {item.day}
                    </span>
                  </div>
                  <p className="text-white/65 leading-relaxed">{item.desc}</p>
                  {item.code && (
                    <div className="p-2.5 rounded-lg bg-[#0D1422] border border-sky-400/25 font-mono text-[11px] text-sky-300 break-all">
                      {item.code}
                    </div>
                  )}
                </div>
              ))}
            </div>

            <div className="flex justify-end pt-2">
              <button
                type="button"
                onClick={() => setSopModalOpen(false)}
                style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                className="px-5 py-2 rounded-xl bg-white hover:bg-emerald-300 text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
              >
                Cerrar Guía Operativa
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL INTERACTIVO: MANUAL OPERATIVO TREASURY OOB (SOP-SEC-TREASURY-01) */}
      {treasurySopModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm p-4">
          <div className="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-2xl bg-[#0D121C] border border-white/15 p-6 sm:p-7 shadow-2xl space-y-5">
            <div className="flex items-start justify-between border-b border-white/10 pb-4">
              <div>
                <div className="text-[11px] font-bold uppercase tracking-[0.14em] text-emerald-300">
                  SOP-SEC-TREASURY-01 · Keystone DeepShield Suite
                </div>
                <h3
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="text-xl font-bold text-white mt-1"
                >
                  Manual Operativo: Custodia de Tesorería Out-of-Band & Anti-BEC
                </h3>
                <p className="text-xs text-white/55 mt-0.5">
                  Procedimiento estándar desde que una empresa contrata el servicio hasta la auditoría SHA-256 diaria.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setTreasurySopModalOpen(false)}
                className="p-1.5 rounded-lg bg-white/[0.06] text-white/60 hover:text-white"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="rounded-xl bg-emerald-400/10 border border-emerald-400/30 p-4 flex flex-wrap items-center justify-between gap-3 text-xs">
              <div className="text-emerald-100 max-w-xl leading-relaxed">
                <strong className="text-emerald-300">Meta del Servicio:</strong>{" "}
                Bloquear desvíos fraudulentos por cambio de cuenta bancaria de proveedores (BEC) y clonación de voz ejecutiva IA mediante validación móvil fuera de banda.
              </div>
              <a
                href="/MANUAL_OPERATIVO_SOP_DEEPSHIELD_TREASURY.docx"
                download
                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] font-bold transition-colors shrink-0"
              >
                <FileDown className="w-3.5 h-3.5" />
                <span>Descargar Manual Word (.docx)</span>
              </a>
            </div>

            <div className="space-y-3 text-xs">
              {[
                {
                  step: "Paso 1: Onboarding y Creación del Tenant en /admin",
                  phase: "Día 1 · Alta",
                  badge: "bg-emerald-400/15 text-emerald-300",
                  desc: "En Suite SecOps -> Tenants & QR, registrar Razón Social, RUT, Slug, correo del operador de tesorería y datos del Firmante 1 (CFO) + Firmante 2 Mancomunado opcional con su umbral de doble firma.",
                },
                {
                  step: "Paso 2: Selección de Canal WhatsApp (Compartido o Propio QR)",
                  phase: "Día 1 · Canal",
                  badge: "bg-sky-400/15 text-sky-300",
                  desc: "Operar de inmediato con keystone-general o dejar que el cliente vincule su propio teléfono corporativo en /shield/portal/ -> Canal WhatsApp (QR), con failover automático a keystone-general si su equipo se apaga.",
                },
                {
                  step: "Paso 3: Carga del Padrón Verificado de Proveedores (CSV ERP)",
                  phase: "Día 1 · Whitelist",
                  badge: "bg-sky-400/15 text-sky-300",
                  desc: "En /shield/portal/ -> Padrón Verificado, importar el maestro CSV exportado desde SAP, Softland, Defontana o NetSuite (razon_social;rut;banco;cuenta) para activar el detector automático Anti-BEC.",
                },
                {
                  step: "Paso 4: Emisión Individual o Carga Masiva de Nómina CSV",
                  phase: "Operación Diaria",
                  badge: "bg-violet-400/15 text-violet-200",
                  desc: "Antes de liberar pagos en el banco, tesorería ingresa la orden o sube la nómina CSV. Si el RUT existe en el padrón con otra cuenta, estalla una Alerta Roja automática de Discrepancia Bancaria.",
                },
                {
                  step: "Paso 5: Firma Móvil OOB, Quórum Mancomunado y Certificado PDF",
                  phase: "Cierre SHA-256",
                  badge: "bg-emerald-400 text-[#0A0E15]",
                  desc: "Los apoderados revisan el semáforo de Padrón en su móvil (/shield/verify/), exigen el Desafío Anti-Voz de 6 caracteres ante llamadas urgentes y estampan su sello SHA-256. Con estado APPROVED se libera en el banco y se archiva el PDF.",
                },
              ].map((item, i) => (
                <div
                  key={i}
                  className="rounded-xl bg-[#080B11] border border-white/10 p-4 space-y-1.5"
                >
                  <div className="flex items-center justify-between gap-2">
                    <strong className="text-white text-[13px]">
                      {item.step}
                    </strong>
                    <span
                      className={`px-2.5 py-0.5 rounded-full text-[10px] font-bold shrink-0 ${item.badge}`}
                    >
                      {item.phase}
                    </span>
                  </div>
                  <p className="text-white/65 leading-relaxed">{item.desc}</p>
                </div>
              ))}
            </div>

            <div className="flex justify-end pt-2">
              <button
                type="button"
                onClick={() => setTreasurySopModalOpen(false)}
                style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                className="px-5 py-2 rounded-xl bg-white hover:bg-emerald-300 text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
              >
                Cerrar Manual Treasury
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL OPERATIVO DE CAMPAÑA RED TEAMING ÉTICO (NÓMINA, INSUMOS & DESPACHO) */}
      {activeRtCampaign && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm p-4">
          <div className="w-full max-w-4xl max-h-[92vh] overflow-y-auto rounded-2xl bg-[#0D121C] border border-white/15 p-6 shadow-2xl space-y-5 text-xs">
            <div className="flex items-start justify-between border-b border-white/10 pb-3">
              <div>
                <div className="text-[11px] font-bold uppercase tracking-wider text-emerald-300">
                  Operación de Campaña Autorizada · Dominios: {activeRtCampaign.target_domains}
                </div>
                <h3
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="text-lg font-bold text-white mt-0.5"
                >
                  {activeRtCampaign.company_name}
                </h3>
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={async () => {
                    if (
                      !window.confirm(
                        `¿Eliminar la campaña de ${activeRtCampaign.company_name}?`
                      )
                    )
                      return;
                    await apiPost("/api/admin/secops.php", {
                      action: "delete_redteam_campaign",
                      id: activeRtCampaign.id,
                    });
                    setActiveRtCampaign(null);
                    loadAllConsoleData(currentUser.role);
                  }}
                  className="px-2.5 py-1.5 rounded-lg bg-rose-500/15 hover:bg-rose-500/30 text-rose-300 font-semibold inline-flex items-center gap-1"
                >
                  <Trash2 className="w-3.5 h-3.5" />
                  <span>Eliminar</span>
                </button>
                <button
                  type="button"
                  onClick={() => setActiveRtCampaign(null)}
                  className="p-1.5 rounded-lg bg-white/[0.06] text-white/60 hover:text-white"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>
            </div>

            {rtModalNotice && (
              <div
                className={`p-3 rounded-xl border flex items-center justify-between gap-2 ${
                  rtModalNotice.type === "ok"
                    ? "bg-emerald-400/15 border-emerald-400/35 text-emerald-200"
                    : "bg-rose-500/20 border-rose-400/40 text-rose-200"
                }`}
              >
                <span className="font-medium">{rtModalNotice.text}</span>
                <button
                  type="button"
                  onClick={() => setRtModalNotice(null)}
                  className="text-white/60 hover:text-white"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              </div>
            )}

            {/* 1. BIBLIOTECA & ESTUDIO DE PLANTILLAS HTML DEL CLIENTE */}
            <div className="p-4 rounded-xl bg-[#080B11] border border-white/10 space-y-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="font-bold uppercase tracking-wider text-amber-200 text-[11px]">
                  1. Estudio de Plantillas HTML & Biblioteca Reutilizable (Enlaza a /shield/rt/ sin captura de datos)
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    onClick={() => {
                      const htmlToExport =
                        rtEditCustomHtml.trim() !== ""
                          ? rtEditCustomHtml
                          : `<!DOCTYPE html>\n<html lang="es">\n<head><meta charset="utf-8"></head>\n<body style="font-family:Arial,Helvetica,sans-serif;background:#F1F5F9;margin:0;padding:24px;color:#1E293B;">\n  <table width="620" align="center" cellpadding="0" cellspacing="0" style="background:#FFFFFF;border:1px solid #CBD5E1;border-radius:6px;overflow:hidden;">\n    <tr>\n      <td style="background:${rtEditAccent};color:#FFFFFF;padding:18px 24px;">\n        <div style="font-size:16px;font-weight:bold;">PORTAL DOCUMENTAL · {{EMPRESA}}</div>\n      </td>\n    </tr>\n    <tr>\n      <td style="padding:24px;">\n        <p style="font-size:13.5px;">Estimado(a) <strong>{{NOMBRE}}</strong>,</p>\n        <div style="font-size:13.5px;line-height:1.6;">${rtEditBody.replace(
                              /\n/g,
                              "<br/>"
                            )}</div>\n        <div style="text-align:center;margin:22px 0;">\n          <a href="{{LINK_CAPSULA}}" style="display:inline-block;background:${rtEditAccent};color:#FFFFFF;text-decoration:none;padding:11px 24px;border-radius:5px;font-size:13px;font-weight:bold;">${rtEditCta}</a>\n        </div>\n      </td>\n    </tr>\n  </table>\n</body>\n</html>`;
                      const blob = new Blob([htmlToExport], {
                        type: "text/html;charset=utf-8;",
                      });
                      const url = URL.createObjectURL(blob);
                      const a = document.createElement("a");
                      a.href = url;
                      a.download = `Plantilla_${activeRtCampaign.company_name.replace(
                        /[^a-zA-Z0-9]/g,
                        "_"
                      )}.html`;
                      document.body.appendChild(a);
                      a.click();
                      document.body.removeChild(a);
                      URL.revokeObjectURL(url);
                    }}
                    className="px-2.5 py-1 rounded-lg bg-emerald-400/15 hover:bg-emerald-300 text-emerald-200 hover:text-[#0A0E15] font-semibold inline-flex items-center gap-1 transition-colors"
                  >
                    <FileDown className="w-3 h-3" />
                    <span>Descargar .html</span>
                  </button>
                  <label className="px-2.5 py-1 rounded-lg bg-white/[0.06] hover:bg-white/15 border border-white/12 text-sky-300 font-semibold cursor-pointer inline-flex items-center gap-1">
                    <Upload className="w-3 h-3" />
                    <span>Cargar .html / .txt Cliente</span>
                    <input
                      type="file"
                      accept=".html,.htm,.txt"
                      className="hidden"
                      onChange={async (e) => {
                        const f = e.target.files?.[0];
                        e.target.value = "";
                        if (!f) return;
                        const content = await f.text();
                        if (
                          f.name.toLowerCase().endsWith(".html") ||
                          f.name.toLowerCase().endsWith(".htm") ||
                          content.includes("<table") ||
                          content.includes("<div")
                        ) {
                          setRtEditCustomHtml(content);
                          setRtModalNotice({
                            type: "ok",
                            text: `HTML personalizado cargado (${f.name}). Pulse 'Guardar en esta Campaña' o 'Vista Previa'.`,
                          });
                        } else {
                          setRtEditBody(content);
                          setRtModalNotice({
                            type: "ok",
                            text: `Texto de plantilla cargado (${f.name}).`,
                          });
                        }
                      }}
                    />
                  </label>
                  <button
                    type="button"
                    onClick={() => setRtPreviewOpen((v) => !v)}
                    className="px-2.5 py-1 rounded-lg bg-violet-400/15 hover:bg-violet-300 text-violet-200 hover:text-[#0A0E15] font-semibold inline-flex items-center gap-1 transition-colors"
                  >
                    <Eye className="w-3 h-3" />
                    <span>
                      {rtPreviewOpen ? "Ocultar Vista Previa" : "Vista Previa Correo"}
                    </span>
                  </button>
                </div>
              </div>

              {/* SELECTOR DE PLANTILLAS DE LA BIBLIOTECA */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-2">
                <select
                  defaultValue=""
                  onChange={(e) => {
                    const tpl = rtTemplates.find(
                      (x) => String(x.id) === e.target.value
                    );
                    if (tpl) {
                      setRtEditSubject(tpl.subject);
                      setRtEditBody(tpl.body_text || "");
                      setRtEditCustomHtml(tpl.custom_html || "");
                      setRtEditCta(
                        tpl.cta_label || "REVISAR DOCUMENTO EN LINEA"
                      );
                      setRtEditAccent(tpl.accent_color || "#0F172A");
                      setRtModalNotice({
                        type: "ok",
                        text: `Plantilla '${tpl.template_name}' cargada en el editor. Pulse 'Aplicar a Campaña' para fijarla.`,
                      });
                    }
                  }}
                  className="md:col-span-2 px-3 py-2 rounded-lg bg-[#0D121C] border border-amber-400/30 text-white"
                >
                  <option value="">
                    -- Cargar Formato Institucional o Plantilla Guardada ({rtTemplates.length} disponibles) --
                  </option>
                  {rtTemplates.map((tp) => (
                    <option key={tp.id} value={String(tp.id)}>
                      [{tp.category}] {tp.template_name}
                    </option>
                  ))}
                </select>

                <div className="flex items-center gap-2">
                  <label className="text-[11px] text-white/55 shrink-0">
                    Color Cabecera:
                  </label>
                  <input
                    type="color"
                    value={rtEditAccent}
                    onChange={(e) => setRtEditAccent(e.target.value)}
                    className="w-9 h-8 rounded bg-transparent border border-white/15 cursor-pointer"
                  />
                  <input
                    type="text"
                    value={rtEditCta}
                    onChange={(e) => setRtEditCta(e.target.value)}
                    placeholder="Texto Botón CTA"
                    className="w-full px-2.5 py-1.5 rounded-lg bg-white/[0.04] border border-white/12 text-white"
                  />
                </div>
              </div>

              <div className="text-[10.5px] text-white/45">
                Variables dinámicas automáticas:{" "}
                <span className="font-mono text-emerald-300">
                  {"{{NOMBRE}}"} {"{{CORREO}}"} {"{{DEPARTAMENTO}}"}{" "}
                  {"{{EMPRESA}}"} {"{{LINK_CAPSULA}}"}
                </span>
              </div>

              <input
                type="text"
                value={rtEditSubject}
                onChange={(e) => setRtEditSubject(e.target.value)}
                placeholder="Asunto del correo"
                className="w-full px-3 py-2 rounded-lg bg-white/[0.04] border border-white/12 text-white"
              />
              <textarea
                rows={3}
                value={rtEditBody}
                onChange={(e) => setRtEditBody(e.target.value)}
                placeholder="Cuerpo del mensaje (soporta {{NOMBRE}}, {{EMPRESA}}, {{DEPARTAMENTO}})..."
                className="w-full px-3 py-2 rounded-lg bg-white/[0.04] border border-white/12 text-white resize-none"
              />

              <details className="rounded-lg bg-white/[0.02] border border-white/[0.07] p-2.5">
                <summary className="cursor-pointer text-[11px] font-semibold text-sky-300">
                   Código HTML Propio del Cliente (Opcional — reemplaza el diseño guiado si se completa)
                </summary>
                <textarea
                  rows={4}
                  value={rtEditCustomHtml}
                  onChange={(e) => setRtEditCustomHtml(e.target.value)}
                  placeholder="Pegue aquí el HTML corporativo propio del cliente (use href='{{LINK_CAPSULA}}' en el botón principal)..."
                  className="mt-2 w-full px-3 py-2 rounded-lg bg-[#080B11] border border-white/12 font-mono text-[11px] text-sky-100 resize-y"
                />
              </details>

              {rtPreviewOpen && (
                <div className="rounded-xl bg-slate-100 p-4 text-slate-900 border border-white/20 space-y-2">
                  <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">
                    Vista Previa Renderizada — Asunto:{" "}
                    {rtEditSubject.replace(
                      /\{\{EMPRESA\}\}/g,
                      activeRtCampaign.company_name
                    )}
                  </div>
                  {rtEditCustomHtml.trim() !== "" ? (
                    <div
                      className="rounded bg-white p-3 border border-slate-300 text-xs overflow-x-auto"
                      dangerouslySetInnerHTML={{
                        __html: rtEditCustomHtml
                          .replace(/\{\{NOMBRE\}\}/g, "Paulina Ríos")
                          .replace(
                            /\{\{EMPRESA\}\}/g,
                            activeRtCampaign.company_name
                          )
                          .replace(/\{\{DEPARTAMENTO\}\}/g, "Contabilidad")
                          .replace(/\{\{LINK_CAPSULA\}\}/g, "/shield/rt/?t=demo"),
                      }}
                    />
                  ) : (
                    <div className="max-w-xl mx-auto bg-white rounded-lg border border-slate-300 overflow-hidden shadow">
                      <div
                        style={{ backgroundColor: rtEditAccent }}
                        className="text-white px-5 py-3 flex items-center justify-between"
                      >
                        <strong className="text-xs uppercase">
                          {activeRtCampaign.company_name}
                        </strong>
                        <span className="text-[10px] opacity-85">
                          Notificación Electrónica
                        </span>
                      </div>
                      <div className="p-5 space-y-3 text-xs text-slate-700">
                        <p>
                          Estimado(a) <strong>Paulina Ríos</strong>,
                        </p>
                        <p className="whitespace-pre-line leading-relaxed">
                          {rtEditBody
                            .replace(/\{\{NOMBRE\}\}/g, "Paulina Ríos")
                            .replace(
                              /\{\{EMPRESA\}\}/g,
                              activeRtCampaign.company_name
                            )
                            .replace(/\{\{DEPARTAMENTO\}\}/g, "Contabilidad")}
                        </p>
                        <div className="p-3 bg-slate-50 border border-slate-200 rounded text-center">
                          <span
                            style={{ backgroundColor: rtEditAccent }}
                            className="inline-block text-white font-bold px-4 py-2 rounded text-xs"
                          >
                            {rtEditCta}
                          </span>
                        </div>
                      </div>
                    </div>
                  )}
                </div>
              )}

              <div className="flex flex-wrap items-center justify-between gap-2 pt-1">
                <div className="flex items-center gap-1.5">
                  <input
                    type="text"
                    value={rtSaveTplName}
                    onChange={(e) => setRtSaveTplName(e.target.value)}
                    placeholder="Nombre para guardar en biblioteca..."
                    className="px-2.5 py-1.5 rounded-lg bg-white/[0.04] border border-white/12 text-white w-56"
                  />
                  <button
                    type="button"
                    disabled={rtOpBusy}
                    onClick={async () => {
                      if (!rtSaveTplName.trim()) {
                        setRtModalNotice({
                          type: "err",
                          text: "Escriba un nombre para guardar esta plantilla en la biblioteca.",
                        });
                        return;
                      }
                      setRtOpBusy(true);
                      try {
                        const d = await apiPost("/api/admin/secops.php", {
                          action: "save_redteam_template",
                          client_scope: activeRtCampaign.company_name,
                          template_name: rtSaveTplName,
                          category: activeRtCampaign.company_name,
                          subject: rtEditSubject,
                          body_text: rtEditBody,
                          custom_html: rtEditCustomHtml,
                          cta_label: rtEditCta,
                          accent_color: rtEditAccent,
                        });
                        setRtTemplates(d.templates || []);
                        setRtSaveTplName("");
                        setRtModalNotice({ type: "ok", text: d.message });
                      } catch (err) {
                        setRtModalNotice({
                          type: "err",
                          text:
                            err instanceof Error
                              ? err.message
                              : "Error guardando en biblioteca",
                        });
                      } finally {
                        setRtOpBusy(false);
                      }
                    }}
                    className="px-3 py-1.5 rounded-lg bg-amber-400/15 hover:bg-amber-300 text-amber-200 hover:text-[#0A0E15] font-bold transition-colors"
                  >
                    + Guardar en Biblioteca
                  </button>
                </div>

                <button
                  type="button"
                  disabled={rtOpBusy}
                  onClick={async () => {
                    setRtOpBusy(true);
                    try {
                      const d = await apiPost("/api/admin/secops.php", {
                        action: "update_redteam_campaign",
                        id: activeRtCampaign.id,
                        last_lure_subject: rtEditSubject,
                        last_lure_body: rtEditBody,
                        lure_cta_label: rtEditCta,
                        lure_accent_color: rtEditAccent,
                        custom_html_body: rtEditCustomHtml,
                      });
                      setRtModalNotice({ type: "ok", text: d.message });
                      notify(d.message);
                      loadAllConsoleData(currentUser.role);
                    } catch (err) {
                      const msg =
                        err instanceof Error ? err.message : "Error guardando";
                      setRtModalNotice({ type: "err", text: msg });
                    } finally {
                      setRtOpBusy(false);
                    }
                  }}
                  className="px-4 py-1.5 rounded-lg bg-emerald-300 hover:bg-white text-[#0A0E15] font-bold transition-colors"
                >
                  Aplicar Plantilla a esta Campaña
                </button>
              </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
              {/* NÓMINA CRUD + CLONAR ENTRE CAMPAÑAS + CSV + DESPACHO */}
              <div className="p-4 rounded-xl bg-[#080B11] border border-white/10 space-y-3">
                <div className="flex items-center justify-between">
                  <span className="font-bold uppercase tracking-wider text-emerald-300 text-[11px]">
                    2. Nómina Colaboradores ({rtTargets.length})
                  </span>
                  <label className="text-[11px] text-sky-300 hover:underline cursor-pointer inline-flex items-center gap-1">
                    <Upload className="w-3 h-3" />
                    <span>Importar CSV (nombre;correo;area)</span>
                    <input
                      type="file"
                      accept=".csv,text/csv"
                      className="hidden"
                      onChange={async (e) => {
                        const f = e.target.files?.[0];
                        e.target.value = "";
                        if (!f) return;
                        const txt = await f.text();
                        const lines = txt
                          .replace(/^\uFEFF/, "")
                          .split(/\r?\n/)
                          .filter((l) => l.trim().length > 0);
                        const sep = lines[0]?.includes(";") ? ";" : ",";
                        const rows = lines
                          .map((l) => l.split(sep).map((x) => x.trim()))
                          .filter((c) => c.length >= 2 && c[1].includes("@"))
                          .map((c) => ({
                            full_name: c[0],
                            email: c[1],
                            department: c[2] || "General",
                            role_title: c[3] || "",
                          }));
                        try {
                          const d = await apiPost("/api/admin/secops.php", {
                            action: "batch_redteam_targets",
                            campaign_id: activeRtCampaign.id,
                            rows,
                          });
                          setRtModalNotice({ type: "ok", text: d.message });
                          const det = await fetch(
                            `/api/admin/secops.php?action=redteam_detail&campaign_id=${activeRtCampaign.id}`,
                            { credentials: "include" }
                          ).then((r) => r.json());
                          if (det?.success) setRtTargets(det.targets || []);
                          loadAllConsoleData(currentUser.role);
                        } catch (err) {
                          setRtModalNotice({
                            type: "err",
                            text:
                              err instanceof Error
                                ? err.message
                                : "Error cargando CSV",
                          });
                        }
                      }}
                    />
                  </label>
                </div>

                {/* CLONAR PARTICIPANTES DESDE OTRA CAMPAÑA DEL MISMO CLIENTE EN 1 CLIC */}
                {redteamCampaigns.some(
                  (rc) =>
                    rc.id !== activeRtCampaign.id && (rc.targets_total || 0) > 0
                ) && (
                  <div className="p-2.5 rounded-lg bg-sky-400/10 border border-sky-400/25 flex items-center gap-2">
                    <select
                      value={rtCloneSourceId}
                      onChange={(e) => setRtCloneSourceId(e.target.value)}
                      className="flex-1 px-2.5 py-1.5 rounded bg-[#0D121C] border border-white/15 text-[11px] text-white"
                    >
                      <option value="">
                        -- Copiar participantes de otra campaña --
                      </option>
                      {redteamCampaigns
                        .filter(
                          (rc) =>
                            rc.id !== activeRtCampaign.id &&
                            (rc.targets_total || 0) > 0
                        )
                        .map((rc) => (
                          <option key={rc.id} value={String(rc.id)}>
                            #{rc.id} {rc.company_name} ({rc.targets_total}{" "}
                            participantes)
                          </option>
                        ))}
                    </select>
                    <button
                      type="button"
                      onClick={async () => {
                        if (!rtCloneSourceId) return;
                        try {
                          const d = await apiPost("/api/admin/secops.php", {
                            action: "clone_redteam_targets",
                            source_campaign_id: Number(rtCloneSourceId),
                            target_campaign_id: activeRtCampaign.id,
                          });
                          setRtModalNotice({ type: "ok", text: d.message });
                          const det = await fetch(
                            `/api/admin/secops.php?action=redteam_detail&campaign_id=${activeRtCampaign.id}`,
                            { credentials: "include" }
                          ).then((r) => r.json());
                          if (det?.success) setRtTargets(det.targets || []);
                          loadAllConsoleData(currentUser.role);
                        } catch (err) {
                          setRtModalNotice({
                            type: "err",
                            text:
                              err instanceof Error
                                ? err.message
                                : "Error copiando participantes",
                          });
                        }
                      }}
                      className="px-3 py-1.5 rounded bg-sky-300 hover:bg-white text-[#0A0E15] font-bold text-[11px] shrink-0"
                    >
                      Copiar Nómina
                    </button>
                  </div>
                )}

                <form
                  onSubmit={async (e) => {
                    e.preventDefault();
                    setRtModalNotice(null);
                    try {
                      const d = await apiPost("/api/admin/secops.php", {
                        action: "add_redteam_target",
                        campaign_id: activeRtCampaign.id,
                        full_name: rtTargetName,
                        email: rtTargetEmail,
                        department: rtTargetDept,
                      });
                      setRtTargetName("");
                      setRtTargetEmail("");
                      setRtModalNotice({ type: "ok", text: d.message });
                      const det = await fetch(
                        `/api/admin/secops.php?action=redteam_detail&campaign_id=${activeRtCampaign.id}`,
                        { credentials: "include" }
                      ).then((r) => r.json());
                      if (det?.success) setRtTargets(det.targets || []);
                      loadAllConsoleData(currentUser.role);
                    } catch (err) {
                      const msg =
                        err instanceof Error
                          ? err.message
                          : "Error agregando colaborador";
                      setRtModalNotice({ type: "err", text: msg });
                    }
                  }}
                  className="space-y-2"
                >
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <input
                      type="text"
                      required
                      value={rtTargetName}
                      onChange={(e) => setRtTargetName(e.target.value)}
                      placeholder="Nombre Completo (ej: Paulina Ríos)"
                      className="px-3 py-2 rounded-lg bg-white/[0.04] border border-white/15 text-white"
                    />
                    <input
                      type="text"
                      required
                      value={rtTargetEmail}
                      onChange={(e) => setRtTargetEmail(e.target.value)}
                      placeholder={`Correo o usuario (@${activeRtCampaign.target_domains})`}
                      className="px-3 py-2 rounded-lg bg-white/[0.04] border border-white/15 text-white"
                    />
                  </div>
                  <div className="flex gap-2">
                    <input
                      type="text"
                      value={rtTargetDept}
                      onChange={(e) => setRtTargetDept(e.target.value)}
                      placeholder="Área / Departamento (ej: Contabilidad)"
                      className="flex-1 px-3 py-2 rounded-lg bg-white/[0.04] border border-white/15 text-white"
                    />
                    <button
                      type="submit"
                      className="px-4 py-2 rounded-lg bg-emerald-300 hover:bg-white text-[#0A0E15] font-bold transition-colors shrink-0"
                    >
                      + Agregar Participante
                    </button>
                  </div>
                </form>

                <button
                  type="button"
                  disabled={rtOpBusy}
                  onClick={async () => {
                    setRtOpBusy(true);
                    try {
                      const d = await apiPost("/api/admin/secops.php", {
                        action: "dispatch_redteam_drill",
                        campaign_id: activeRtCampaign.id,
                      });
                      notify(d.message);
                      const det = await fetch(
                        `/api/admin/secops.php?action=redteam_detail&campaign_id=${activeRtCampaign.id}`,
                        { credentials: "include" }
                      ).then((r) => r.json());
                      if (det?.success) setRtTargets(det.targets || []);
                      loadAllConsoleData(currentUser.role);
                    } catch (err) {
                      notify(
                        err instanceof Error
                          ? err.message
                          : "Error despachando ejercicio"
                      );
                    } finally {
                      setRtOpBusy(false);
                    }
                  }}
                  className="w-full py-2 rounded-lg bg-emerald-300 hover:bg-white text-[#0A0E15] font-bold uppercase tracking-wider flex items-center justify-center gap-1.5 transition-colors"
                >
                  <Send className="w-3.5 h-3.5" />
                  <span>Despachar Cápsula a Pendientes</span>
                </button>

                <div className="max-h-44 overflow-y-auto divide-y divide-white/[0.06]">
                  {rtTargets.map((tg) => (
                    <div
                      key={tg.id}
                      className="py-2 flex items-center justify-between gap-2"
                    >
                      <div>
                        <div className="font-semibold text-white">
                          {tg.full_name}{" "}
                          <span className="text-white/45 font-normal">
                            ({tg.department})
                          </span>
                        </div>
                        <div className="text-[11px] text-white/50">
                          {tg.email}
                        </div>
                      </div>
                      <div className="flex items-center gap-1.5">
                        <span
                          className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                            tg.status === "REPORTED"
                              ? "bg-emerald-400/20 text-emerald-300"
                              : tg.status === "CLICKED"
                              ? "bg-amber-400/20 text-amber-200"
                              : tg.status === "OPENED"
                              ? "bg-sky-400/20 text-sky-200"
                              : "bg-white/[0.07] text-white/65"
                          }`}
                        >
                          {tg.status}
                        </span>
                        <button
                          type="button"
                          onClick={async () => {
                            await apiPost("/api/admin/secops.php", {
                              action: "delete_redteam_target",
                              id: tg.id,
                            });
                            setRtTargets((prev) =>
                              prev.filter((x) => x.id !== tg.id)
                            );
                            loadAllConsoleData(currentUser.role);
                          }}
                          className="text-white/35 hover:text-rose-400"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              {/* BÓVEDA DE INSUMOS DEL CLIENTE (SOL-0193) */}
              <div className="p-4 rounded-xl bg-[#080B11] border border-white/10 space-y-3">
                <div className="font-bold uppercase tracking-wider text-violet-300 text-[11px]">
                  3. Bóveda Aislada de Insumos del Cliente ({rtAssets.length})
                </div>
                <p className="text-[11px] text-white/55">
                  Resguarde documentos de referencia autorizados por el cliente
                  (formatos internos PDF, DOCX, TXT o imágenes) fuera de la raíz
                  pública web.
                </p>
                <label className="w-full py-2 px-3 rounded-lg bg-white/[0.06] hover:bg-white/15 border border-white/15 font-semibold text-white flex items-center justify-center gap-2 cursor-pointer transition-colors">
                  <Upload className="w-3.5 h-3.5 text-violet-300" />
                  <span>Subir Insumo a Bóveda</span>
                  <input
                    type="file"
                    accept=".pdf,.docx,.txt,.csv,.png,.jpg,.jpeg"
                    className="hidden"
                    onChange={async (e) => {
                      const f = e.target.files?.[0];
                      e.target.value = "";
                      if (!f) return;
                      const fd = new FormData();
                      fd.append("action", "upload_redteam_asset");
                      fd.append("campaign_id", String(activeRtCampaign.id));
                      fd.append("asset_file", f);
                      try {
                        const r = await fetch("/api/admin/secops.php", {
                          method: "POST",
                          credentials: "include",
                          headers: { "X-CSRF-Token": csrfToken },
                          body: fd,
                        });
                        const d = await r.json();
                        if (d?.success) {
                          notify(d.message);
                          const det = await fetch(
                            `/api/admin/secops.php?action=redteam_detail&campaign_id=${activeRtCampaign.id}`,
                            { credentials: "include" }
                          ).then((res) => res.json());
                          if (det?.success) setRtAssets(det.assets || []);
                          loadAllConsoleData(currentUser.role);
                        } else {
                          notify(d?.error || "Error subiendo insumo");
                        }
                      } catch {
                        notify("Error de red subiendo insumo");
                      }
                    }}
                  />
                </label>

                <div className="max-h-56 overflow-y-auto divide-y divide-white/[0.06]">
                  {rtAssets.length === 0 ? (
                    <div className="py-8 text-center text-white/40">
                      Sin insumos cargados en esta campaña.
                    </div>
                  ) : (
                    rtAssets.map((as) => (
                      <div
                        key={as.id}
                        className="py-2.5 flex items-center justify-between gap-2"
                      >
                        <div>
                          <div className="font-semibold text-white">
                            {as.asset_name}
                          </div>
                          <div className="text-[10px] text-white/45 font-mono">
                            {as.asset_type} · {Math.round(as.file_size / 1024)}{" "}
                            KB · @{as.uploaded_by}
                          </div>
                        </div>
                        <button
                          type="button"
                          onClick={async () => {
                            await apiPost("/api/admin/secops.php", {
                              action: "delete_redteam_asset",
                              id: as.id,
                            });
                            setRtAssets((prev) =>
                              prev.filter((x) => x.id !== as.id)
                            );
                            loadAllConsoleData(currentUser.role);
                          }}
                          className="text-white/40 hover:text-rose-400"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    ))
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* MODAL INTEGRACIÓN API REST / WEBHOOK ERP (SAP, NETSUITE, SOFTLAND) */}
      {erpModalTenant && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm p-4">
          <div className="w-full max-w-2xl rounded-2xl bg-[#0D121C] border border-white/15 p-6 shadow-2xl space-y-4 text-xs">
            <div className="flex items-start justify-between border-b border-white/10 pb-3">
              <div>
                <div className="text-[11px] font-bold uppercase tracking-wider text-violet-300">
                  Conector Machine-to-Machine ERP · DeepShield Treasury
                </div>
                <h3
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="text-lg font-bold text-white mt-0.5"
                >
                  {erpModalTenant.company_name} [{erpModalTenant.tenant_slug}]
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setErpModalTenant(null)}
                className="text-white/60 hover:text-white"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-3.5 rounded-xl bg-[#080B11] border border-violet-400/30 flex flex-wrap items-center justify-between gap-2">
              <div>
                <div className="text-[10px] uppercase text-white/45 font-bold">
                  Cabecera X-DeepShield-Key
                </div>
                <div className="font-mono text-emerald-300 font-bold break-all mt-0.5">
                  {erpModalTenant.client_api_key}
                </div>
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => {
                    navigator.clipboard?.writeText(
                      erpModalTenant.client_api_key
                    );
                    notify("API Key copiada al portapapeles.");
                  }}
                  className="px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white text-white hover:text-[#0A0E15] font-bold"
                >
                  Copiar Key
                </button>
                <button
                  type="button"
                  onClick={async () => {
                    if (
                      !window.confirm(
                        "¿Rotar llave API ERP? El ERP deberá actualizar su cabecera X-DeepShield-Key."
                      )
                    )
                      return;
                    const d = await apiPost("/api/admin/secops.php", {
                      action: "rotate_tenant_api_key",
                      id: erpModalTenant.id,
                    });
                    setErpModalTenant({
                      ...erpModalTenant,
                      client_api_key: d.client_api_key,
                    });
                    notify(d.message);
                    loadAllConsoleData(currentUser.role);
                  }}
                  className="px-3 py-1.5 rounded-lg bg-amber-400/15 hover:bg-amber-300 text-amber-200 hover:text-[#0A0E15] font-bold"
                >
                  Rotar Key
                </button>
              </div>
            </div>

            <div className="space-y-2">
              <div className="font-bold text-white/85">
                Ejemplo 1: Emitir Orden OOB desde SAP / Softland / NetSuite
              </div>
              <pre className="p-3 rounded-xl bg-[#080B11] border border-white/10 font-mono text-[10.5px] text-sky-200 overflow-x-auto">{`curl -X POST ${typeof window !== "undefined" ? window.location.origin : "https://www.keystoneadvisors.cl"}/api/shield/erp_webhook.php \\
  -H "Content-Type: application/json" \\
  -H "X-DeepShield-Key: ${erpModalTenant.client_api_key}" \\
  -d '{"amount":12500000,"beneficiary_name":"Proveedor S.A.","beneficiary_rut":"76123456-7","bank_name":"Banco de Chile","account_number":"00-145-99821-01","invoice_folio":"FE-1042"}'`}</pre>
            </div>

            <div className="space-y-2">
              <div className="font-bold text-white/85">
                Ejemplo 2: Verificar Autorización SHA-256 antes de liberar archivo TEF
              </div>
              <pre className="p-3 rounded-xl bg-[#080B11] border border-white/10 font-mono text-[10.5px] text-emerald-200 overflow-x-auto">{`curl -H "X-DeepShield-Key: ${erpModalTenant.client_api_key}" \\
  "${typeof window !== "undefined" ? window.location.origin : "https://www.keystoneadvisors.cl"}/api/shield/erp_webhook.php?order_code=DST-XXXXXXXX"`}</pre>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
