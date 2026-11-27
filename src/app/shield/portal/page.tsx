"use client";

import React, { useState, useEffect, useCallback, useMemo } from "react";
import Link from "next/link";
import {
  ShieldCheck,
  ShieldAlert,
  Lock,
  Landmark,
  CheckCircle2,
  AlertTriangle,
  Plus,
  LogOut,
  ArrowUpRight,
  Loader2,
  Building2,
  ExternalLink,
  QrCode,
  RefreshCw,
  Smartphone,
  Upload,
  FileDown,
  Users,
  BookOpen,
  Trash2,
  X,
  ChevronDown,
  Search,
} from "lucide-react";

const CHILEAN_BANKS = [
  "Banco de Chile",
  "Banco Santander Chile",
  "BancoEstado (Banco del Estado de Chile)",
  "Banco BCI (Banco de Crédito e Inversiones)",
  "Scotiabank Chile",
  "Itaú Corpbanca (Itaú Chile)",
  "Banco BICE",
  "Banco Security",
  "Banco Falabella",
  "Banco Ripley",
  "Banco Consorcio",
  "Banco Internacional",
  "Coopeuch",
  "Tenpo Prepago",
  "Mercado Pago",
  "Mach (BCI)",
  "HSBC Bank Chile",
  "JP Morgan Chase Bank",
];

function formatRut(val: string): string {
  const clean = val.replace(/[^0-9kK]/g, "").toUpperCase();
  if (clean.length === 0) return "";
  if (clean.length === 1) return clean;
  const dv = clean.slice(-1);
  const body = clean.slice(0, -1);
  let formatted = "";
  let count = 0;
  for (let i = body.length - 1; i >= 0; i--) {
    formatted = body[i] + formatted;
    count++;
    if (count % 3 === 0 && i !== 0) {
      formatted = "." + formatted;
    }
  }
  return `${formatted}-${dv}`;
}

function formatThousands(val: string): string {
  const clean = val.replace(/[^0-9]/g, "");
  if (!clean) return "";
  return parseInt(clean, 10).toLocaleString("es-CL");
}

function parseRawNumber(formatted: string): number {
  const clean = formatted.replace(/[^0-9]/g, "");
  return clean ? parseInt(clean, 10) : 0;
}

interface TenantSession {
  id: number;
  tenant_slug: string;
  company_name: string;
  rut_dni: string;
  threshold_amount: number;
  threshold_currency: string;
  primary_approver_name: string;
  primary_approver_email: string;
  primary_approver_phone: string;
  secondary_approver_name?: string;
  secondary_approver_email?: string;
  secondary_approver_phone?: string;
  dual_approval_threshold?: number;
  whatsapp_instance?: string;
}

interface TreasuryOrder {
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

interface WhitelistSupplier {
  id: number;
  supplier_name: string;
  supplier_rut: string;
  bank_name: string;
  account_number: string;
  created_at: string;
}

function parseFlexibleCsv(text: string): string[][] {
  const clean = text.replace(/^\uFEFF/, "").trim();
  if (!clean) return [];
  const lines = clean.split(/\r?\n/).filter((l) => l.trim().length > 0);
  if (lines.length === 0) return [];
  const sep = lines[0].includes(";") ? ";" : ",";
  return lines.map((line) =>
    line.split(sep).map((cell) => cell.trim().replace(/^"|"$/g, ""))
  );
}

function downloadClientCsvTemplate(filename: string, csvContent: string) {
  const blob = new Blob(["\uFEFF" + csvContent], {
    type: "text/csv;charset=utf-8;",
  });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

export default function ShieldPortalPage() {
  const [loading, setLoading] = useState<boolean>(true);
  const [authed, setAuthed] = useState<boolean>(false);
  const [tenant, setTenant] = useState<TenantSession | null>(null);
  const [orders, setOrders] = useState<TreasuryOrder[]>([]);
  const [whitelist, setWhitelist] = useState<WhitelistSupplier[]>([]);
  const [tab, setTab] = useState<
    "orders" | "whitelist" | "signers" | "whatsapp"
  >("orders");
  const [portalNotice, setPortalNotice] = useState<string | null>(null);
  const [sopOpen, setSopOpen] = useState<boolean>(false);

  // Autoatención WhatsApp QR Propio
  const [waBusy, setWaBusy] = useState<boolean>(false);
  const [waConnected, setWaConnected] = useState<boolean>(false);
  const [waQrBase64, setWaQrBase64] = useState<string | null>(null);
  const [waStatusMsg, setWaStatusMsg] = useState<string | null>(null);

  // Login
  const [slugInput, setSlugInput] = useState<string>("keystone-demo");
  const [emailInput, setEmailInput] = useState<string>(
    "tesoreria@keystoneadvisors.cl"
  );
  const [passInput, setPassInput] = useState<string>("");
  const [loginErr, setLoginErr] = useState<string | null>(null);
  const [busy, setBusy] = useState<boolean>(false);

  // Order Form
  const [amount, setAmount] = useState<string>("");
  const [beneficiary, setBeneficiary] = useState<string>("");
  const [rut, setRut] = useState<string>("");
  const [bank, setBank] = useState<string>("");
  const [bankDropdownOpen, setBankDropdownOpen] = useState<boolean>(false);
  const [bankSearch, setBankSearch] = useState<string>("");
  const [account, setAccount] = useState<string>("");
  const [quorumMode, setQuorumMode] = useState<"auto" | "1" | "2">("auto");
  const [createdVerifyUrl, setCreatedVerifyUrl] = useState<string | null>(null);
  const [createdVerifyUrl2, setCreatedVerifyUrl2] = useState<string | null>(
    null
  );
  const [createdChallenge, setCreatedChallenge] = useState<string | null>(null);

  // Whitelist Form
  const [wlName, setWlName] = useState<string>("");
  const [wlRut, setWlRut] = useState<string>("");
  const [wlBank, setWlBank] = useState<string>("");
  const [wlBankDropdownOpen, setWlBankDropdownOpen] = useState<boolean>(false);
  const [wlBankSearch, setWlBankSearch] = useState<string>("");
  const [wlAcc, setWlAcc] = useState<string>("");

  // Multi-Signer Config Form
  const [pSignerName, setPSignerName] = useState<string>("");
  const [pSignerPhone, setPSignerPhone] = useState<string>("");
  const [sSignerName, setSSignerName] = useState<string>("");
  const [sSignerPhone, setSSignerPhone] = useState<string>("");
  const [dualThresholdInput, setDualThresholdInput] = useState<string>("0");

  const flashNotice = useCallback((msg: string) => {
    setPortalNotice(msg);
    setTimeout(() => {
      setPortalNotice((prev) => (prev === msg ? null : prev));
    }, 5500);
  }, []);

  const loadSession = useCallback(async () => {
    try {
      const r = await fetch("/api/shield/client_api.php?action=session", {
        credentials: "include",
      });
      const d = await r.json();
      if (d?.authenticated && d?.tenant) {
        setAuthed(true);
        setTenant(d.tenant);
        setOrders(d.orders || []);
        setWhitelist(d.whitelist || []);
        setPSignerName(d.tenant.primary_approver_name || "");
        setPSignerPhone(d.tenant.primary_approver_phone || "");
        setSSignerName(d.tenant.secondary_approver_name || "");
        setSSignerPhone(d.tenant.secondary_approver_phone || "");
        setDualThresholdInput(String(d.tenant.dual_approval_threshold || 0));
      }
    } catch {
      // ignore
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (typeof window !== "undefined") {
      const q = new URLSearchParams(window.location.search);
      if (q.get("tab") === "whitelist") setTab("whitelist");
      if (q.get("tab") === "signers") setTab("signers");
      if (q.get("tab") === "whatsapp") setTab("whatsapp");
    }
    loadSession();
  }, [loadSession]);

  // Detector Automático en Vivo contra Padrón Verificado (Whitelist Anti-BEC)
  const liveWhitelistCheck = useMemo(() => {
    const normRut = rut.replace(/[^0-9kK]/g, "").toUpperCase();
    const normAcc = account.replace(/[^a-zA-Z0-9]/g, "").toLowerCase();
    if (!normRut && !normAcc) return null;

    const matchingRutItems = whitelist.filter(
      (w) => w.supplier_rut.replace(/[^0-9kK]/g, "").toUpperCase() === normRut
    );

    if (normRut && matchingRutItems.length > 0) {
      if (!normAcc) {
        const ref = matchingRutItems[0];
        return {
          status: "INFO" as const,
          title: "Proveedor Reconocido en Padrón",
          detail: `${ref.supplier_name} tiene inscrita la cuenta ${ref.account_number} (${ref.bank_name}).`,
        };
      }
      const exact = matchingRutItems.find(
        (w) =>
          w.account_number.replace(/[^a-zA-Z0-9]/g, "").toLowerCase() ===
          normAcc
      );
      if (exact) {
        return {
          status: "VERIFIED" as const,
          title: "Cuenta Certificada en Padrón Oficial",
          detail: `Coincidencia exacta con ${exact.supplier_name} (${exact.bank_name} · Cta ${exact.account_number}).`,
        };
      }
      const ref = matchingRutItems[0];
      return {
        status: "ACCOUNT_MISMATCH" as const,
        title: "ALERTA CRÍTICA ANTI-BEC: Discrepancia de Cuenta Bancaria",
        detail: `El RUT ${ref.supplier_rut} (${ref.supplier_name}) tiene certificada oficialmente la cuenta ${ref.account_number} en ${ref.bank_name}, DISTINTA a la digitada (${account}). Confirme por teléfono antes de emitir.`,
      };
    }

    if (normRut.length >= 7) {
      return {
        status: "UNREGISTERED_SUPPLIER" as const,
        title: "Proveedor No Inscrito en Padrón",
        detail:
          "Este RUT aún no figura en su lista blanca de cuentas verificadas. Se advertirá al director firmante.",
      };
    }
    return null;
  }, [rut, account, whitelist]);

  const checkWhatsAppPortalStatus = useCallback(async () => {
    setWaBusy(true);
    try {
      const r = await fetch("/api/shield/client_api.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "whatsapp_check_qr" }),
      });
      const d = await r.json();
      if (d?.success) {
        setWaConnected(Boolean(d.connected));
        setWaQrBase64(d.qr_base64 || null);
        setWaStatusMsg(d.message || null);
        setTenant((prev) =>
          prev ? { ...prev, whatsapp_instance: d.whatsapp_instance } : null
        );
      }
    } finally {
      setWaBusy(false);
    }
  }, []);

  const handleEnableDedicatedWhatsApp = async () => {
    setWaBusy(true);
    try {
      const r = await fetch("/api/shield/client_api.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "whatsapp_enable_dedicated" }),
      });
      const d = await r.json();
      if (d?.success) {
        setWaConnected(Boolean(d.connected));
        setWaQrBase64(d.qr_base64 || null);
        setWaStatusMsg(d.message || null);
        setTenant((prev) =>
          prev ? { ...prev, whatsapp_instance: d.whatsapp_instance } : null
        );
      } else {
        setWaStatusMsg(d?.error || "Error generando código QR.");
      }
    } finally {
      setWaBusy(false);
    }
  };

  const handleUseSharedWhatsApp = async () => {
    setWaBusy(true);
    try {
      const r = await fetch("/api/shield/client_api.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "whatsapp_use_shared" }),
      });
      const d = await r.json();
      if (d?.success) {
        setWaConnected(true);
        setWaQrBase64(null);
        setWaStatusMsg(d.message || null);
        setTenant((prev) =>
          prev ? { ...prev, whatsapp_instance: "keystone-general" } : null
        );
      }
    } finally {
      setWaBusy(false);
    }
  };

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setLoginErr(null);
    try {
      const r = await fetch("/api/shield/client_api.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "login",
          tenant_slug: slugInput,
          email: emailInput,
          password: passInput,
        }),
      });
      const d = await r.json();
      if (!r.ok || !d.success) {
        setLoginErr(d.error || "Acceso denegado.");
      } else {
        setPassInput("");
        loadSession();
      }
    } catch {
      setLoginErr("Error de red.");
    } finally {
      setBusy(false);
    }
  };

  const handleEmitOrder = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setCreatedVerifyUrl(null);
    setCreatedVerifyUrl2(null);
    try {
      const rawAmt = parseRawNumber(amount);
      const r = await fetch("/api/shield/client_api.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "emit_order",
          amount: rawAmt,
          beneficiary_name: beneficiary,
          beneficiary_rut_dni: rut,
          bank_name: bank,
          account_number: account,
          required_approvals:
            quorumMode === "2" ? 2 : quorumMode === "1" ? 1 : 0,
        }),
      });
      const d = await r.json();
      if (d?.success) {
        setCreatedVerifyUrl(d.verify_url);
        setCreatedVerifyUrl2(d.secondary_verify_url || null);
        setCreatedChallenge(d.security_challenge);
        setAmount("");
        setBeneficiary("");
        setRut("");
        setBank("");
        setBankSearch("");
        setBankDropdownOpen(false);
        setAccount("");
        flashNotice(d.message || "Orden emitida exitosamente.");
        loadSession();
      } else {
        flashNotice(d?.error || "Error al emitir orden.");
      }
    } catch {
      flashNotice("Error de red al procesar la solicitud.");
    } finally {
      setBusy(false);
    }
  };

  const handleBatchOrdersCsv = async (
    e: React.ChangeEvent<HTMLInputElement>
  ) => {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    setBusy(true);
    try {
      const text = await file.text();
      const matrix = parseFlexibleCsv(text);
      if (matrix.length === 0) {
        flashNotice("Archivo CSV vacío.");
        return;
      }
      // Detectar si primera fila es cabecera
      const firstCell = (matrix[0][0] || "").toLowerCase();
      const dataRows =
        firstCell.includes("monto") || firstCell.includes("amount")
          ? matrix.slice(1)
          : matrix;

      const payloadRows = dataRows
        .filter((cols) => cols.length >= 5)
        .map((cols) => ({
          amount: parseFloat(cols[0].replace(/[^0-9.]/g, "")) || 0,
          beneficiary_name: cols[1] || "",
          beneficiary_rut_dni: cols[2] || "",
          bank_name: cols[3] || "",
          account_number: cols[4] || "",
          required_approvals: cols[5] ? parseInt(cols[5], 10) || 0 : 0,
        }));

      const r = await fetch("/api/shield/client_api.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "batch_emit_orders",
          rows: payloadRows,
        }),
      });
      const d = await r.json();
      if (d?.success) {
        flashNotice(d.message);
        loadSession();
      } else {
        flashNotice(d?.error || "Error procesando nómina CSV.");
      }
    } finally {
      setBusy(false);
    }
  };

  const handleAddWhitelist = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await fetch("/api/shield/client_api.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "add_whitelist",
          supplier_name: wlName,
          supplier_rut: wlRut,
          bank_name: wlBank,
          account_number: wlAcc,
        }),
      });
      const d = await r.json();
      if (d?.success) {
        setWlName("");
        setWlRut("");
        setWlBank("");
        setWlBankSearch("");
        setWlBankDropdownOpen(false);
        setWlAcc("");
        flashNotice(d.message || "Proveedor certificado.");
        loadSession();
      } else {
        flashNotice(d?.error || "Error al registrar proveedor en el padrón.");
      }
    } catch {
      flashNotice("Error de red al registrar la cuenta.");
    } finally {
      setBusy(false);
    }
  };

  const handleBatchWhitelistCsv = async (
    e: React.ChangeEvent<HTMLInputElement>
  ) => {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    setBusy(true);
    try {
      const text = await file.text();
      const matrix = parseFlexibleCsv(text);
      if (matrix.length === 0) return;
      const firstCell = (matrix[0][0] || "").toLowerCase();
      const dataRows =
        firstCell.includes("razon") ||
        firstCell.includes("proveedor") ||
        firstCell.includes("supplier")
          ? matrix.slice(1)
          : matrix;

      const payloadRows = dataRows
        .filter((cols) => cols.length >= 4)
        .map((cols) => ({
          supplier_name: cols[0] || "",
          supplier_rut: cols[1] || "",
          bank_name: cols[2] || "",
          account_number: cols[3] || "",
        }));

      const r = await fetch("/api/shield/client_api.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "batch_add_whitelist",
          rows: payloadRows,
        }),
      });
      const d = await r.json();
      if (d?.success) {
        flashNotice(d.message);
        loadSession();
      } else {
        flashNotice(d?.error || "Error importando CSV de proveedores.");
      }
    } finally {
      setBusy(false);
    }
  };

  const handleSaveSigners = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await fetch("/api/shield/client_api.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "update_signers_config",
          primary_approver_name: pSignerName,
          primary_approver_phone: pSignerPhone,
          secondary_approver_name: sSignerName,
          secondary_approver_phone: sSignerPhone,
          dual_approval_threshold: parseFloat(dualThresholdInput) || 0,
        }),
      });
      const d = await r.json();
      if (d?.success) {
        flashNotice(d.message);
        loadSession();
      } else {
        flashNotice(d?.error || "Error guardando firmantes.");
      }
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-[#080B11] text-white">
        <Loader2 className="w-5 h-5 animate-spin text-emerald-300" />
      </div>
    );
  }

  if (!authed || !tenant) {
    return (
      <div
        className="min-h-screen flex items-center justify-center bg-[#080B11] text-white px-4 relative overflow-hidden"
        style={{ fontFamily: "var(--font-inter), sans-serif" }}
      >
        <div
          className="pointer-events-none absolute inset-0"
          style={{
            background:
              "radial-gradient(circle at 50% 20%, rgba(16, 185, 129, 0.14), transparent 55%)",
          }}
        />
        <div className="relative z-10 w-full max-w-md space-y-6">
          <div className="text-center">
            <div className="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-emerald-400/10 border border-emerald-400/30 mb-3">
              <ShieldCheck className="w-6 h-6 text-emerald-300" />
            </div>
            <div className="text-[11px] font-bold uppercase tracking-[0.22em] text-emerald-300">
              Keystone DeepShield Treasury
            </div>
            <h1
              style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
              className="text-2xl font-bold text-white mt-1"
            >
              Portal Clientes & Custodia OOB
            </h1>
            <p className="text-xs text-white/55 mt-1">
              Validación criptográfica contra desvío BEC y clonación de voz IA
            </p>
          </div>

          <form
            onSubmit={handleLogin}
            className="rounded-2xl bg-[#0D121C]/90 backdrop-blur-xl border border-white/12 p-6 space-y-4 shadow-2xl"
          >
            <div>
              <label className="block text-[10px] font-bold uppercase tracking-wider text-white/55 mb-1">
                Identificador Empresa (Slug)
              </label>
              <input
                type="text"
                required
                value={slugInput}
                onChange={(e) => setSlugInput(e.target.value)}
                className="w-full px-3.5 py-2.5 rounded-xl bg-white/[0.05] border border-white/15 text-xs text-white"
              />
            </div>
            <div>
              <label className="block text-[10px] font-bold uppercase tracking-wider text-white/55 mb-1">
                Correo Operador Tesorería
              </label>
              <input
                type="email"
                required
                value={emailInput}
                onChange={(e) => setEmailInput(e.target.value)}
                className="w-full px-3.5 py-2.5 rounded-xl bg-white/[0.05] border border-white/15 text-xs text-white"
              />
            </div>
            <div>
              <label className="block text-[10px] font-bold uppercase tracking-wider text-white/55 mb-1">
                Contraseña
              </label>
              <input
                type="password"
                required
                value={passInput}
                onChange={(e) => setPassInput(e.target.value)}
                placeholder="••••••••••••"
                className="w-full px-3.5 py-2.5 rounded-xl bg-white/[0.05] border border-white/15 text-xs text-white"
              />
            </div>

            {loginErr && (
              <div className="p-3 rounded-xl bg-rose-500/15 border border-rose-400/30 text-xs text-rose-200 flex items-center gap-2">
                <AlertTriangle className="w-4 h-4 text-rose-400 shrink-0" />
                <span>{loginErr}</span>
              </div>
            )}

            <button
              type="submit"
              disabled={busy}
              style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
              className="w-full py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors flex items-center justify-center gap-2"
            >
              <Lock className="w-3.5 h-3.5" />
              <span>Ingresar a Bóveda de Pagos</span>
            </button>
          </form>

          <div className="flex items-center justify-between text-[11px] text-white/45 px-1">
            <Link
              href="/"
              className="inline-flex items-center gap-1 hover:text-white"
            >
              <span>Sitio Principal</span>
              <ArrowUpRight className="w-3.5 h-3.5" />
            </Link>
            <span>Zero Trust Architecture</span>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div
      className="min-h-screen bg-[#080B11] text-white"
      style={{ fontFamily: "var(--font-inter), sans-serif" }}
    >
      <header className="border-b border-white/[0.08] bg-[#0B0F17] px-6 py-4 flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl bg-emerald-400/15 border border-emerald-400/30 flex items-center justify-center">
            <ShieldCheck className="w-4 h-4 text-emerald-300" />
          </div>
          <div>
            <div className="text-xs font-bold text-white">
              {tenant.company_name}
            </div>
            <div className="text-[11px] text-white/45">
              RUT {tenant.rut_dni} · Firmante 1: {tenant.primary_approver_name}
              {tenant.secondary_approver_name
                ? ` · Firmante 2: ${tenant.secondary_approver_name}`
                : ""}
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={() => setTab("orders")}
            className={`px-3 py-1.5 rounded-xl text-xs font-semibold transition-colors ${
              tab === "orders"
                ? "bg-emerald-300 text-[#0A0E15]"
                : "bg-white/[0.05] text-white/70"
            }`}
          >
            Órdenes OOB ({orders.length})
          </button>
          <button
            type="button"
            onClick={() => setTab("whitelist")}
            className={`px-3 py-1.5 rounded-xl text-xs font-semibold transition-colors ${
              tab === "whitelist"
                ? "bg-emerald-300 text-[#0A0E15]"
                : "bg-white/[0.05] text-white/70"
            }`}
          >
            Padrón Verificado ({whitelist.length})
          </button>
          <button
            type="button"
            onClick={() => setTab("signers")}
            className={`px-3 py-1.5 rounded-xl text-xs font-semibold inline-flex items-center gap-1.5 transition-colors ${
              tab === "signers"
                ? "bg-emerald-300 text-[#0A0E15]"
                : "bg-white/[0.05] text-white/70"
            }`}
          >
            <Users className="w-3.5 h-3.5" />
            <span>Firmantes & Quórum</span>
          </button>
          <button
            type="button"
            onClick={() => {
              setTab("whatsapp");
              checkWhatsAppPortalStatus();
            }}
            className={`px-3 py-1.5 rounded-xl text-xs font-semibold inline-flex items-center gap-1.5 transition-colors ${
              tab === "whatsapp"
                ? "bg-emerald-300 text-[#0A0E15]"
                : "bg-white/[0.05] text-white/70"
            }`}
          >
            <QrCode className="w-3.5 h-3.5" />
            <span>Canal WhatsApp (QR)</span>
          </button>
          <button
            type="button"
            onClick={() => setSopOpen(true)}
            className="px-3 py-1.5 rounded-xl text-xs font-semibold bg-sky-400/15 hover:bg-sky-300 text-sky-200 hover:text-[#0A0E15] border border-sky-400/30 inline-flex items-center gap-1.5 transition-colors"
          >
            <BookOpen className="w-3.5 h-3.5" />
            <span>Manual SOP</span>
          </button>
          <button
            type="button"
            onClick={async () => {
              await fetch("/api/shield/client_api.php", {
                method: "POST",
                credentials: "include",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ action: "logout" }),
              });
              setAuthed(false);
              setTenant(null);
            }}
            className="p-2 rounded-xl bg-white/[0.05] hover:bg-rose-500/20 text-white/70 hover:text-rose-200"
          >
            <LogOut className="w-4 h-4" />
          </button>
        </div>
      </header>

      <main className="max-w-6xl mx-auto p-6 space-y-5">
        {portalNotice && (
          <div className="p-3.5 rounded-xl bg-emerald-400/15 border border-emerald-400/35 text-xs text-emerald-200 flex items-center justify-between">
            <span>{portalNotice}</span>
            <button
              type="button"
              onClick={() => setPortalNotice(null)}
              className="text-white/60 hover:text-white"
            >
              <X className="w-3.5 h-3.5" />
            </button>
          </div>
        )}

        {tab === "orders" && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="space-y-4">
              <form
                onSubmit={handleEmitOrder}
                className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3"
              >
                <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-300">
                  <Landmark className="w-4 h-4" />
                  <span>Solicitar Autorización OOB</span>
                </div>

                {whitelist.length > 0 && (
                  <div>
                    <label className="block text-[10px] uppercase tracking-wider text-white/45 font-bold mb-1">
                      Autocompletar desde Padrón Certificado
                    </label>
                    <select
                      defaultValue=""
                      onChange={(e) => {
                        const found = whitelist.find(
                          (w) => String(w.id) === e.target.value
                        );
                        if (found) {
                          setBeneficiary(found.supplier_name);
                          setRut(found.supplier_rut);
                          setBank(found.bank_name);
                          setAccount(found.account_number);
                        }
                      }}
                      className="w-full px-3 py-2 rounded-xl bg-[#080B11] border border-emerald-400/25 text-xs text-white"
                    >
                      <option value="">
                        -- Seleccionar proveedor empadronado --
                      </option>
                      {whitelist.map((w) => (
                        <option key={w.id} value={String(w.id)}>
                          {w.supplier_name} ({w.supplier_rut}) · {w.bank_name}
                        </option>
                      ))}
                    </select>
                  </div>
                )}

                <div className="space-y-1">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-white/50">
                    Monto a Transferir ({tenant.threshold_currency})
                  </label>
                  <div className="relative">
                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-xs font-bold text-emerald-300">
                      $
                    </span>
                    <input
                      type="text"
                      required
                      value={amount}
                      onChange={(e) => setAmount(formatThousands(e.target.value))}
                      placeholder="Ej: 2.500.000"
                      className="w-full pl-7 pr-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white font-mono focus:border-emerald-300 focus:outline-none"
                    />
                  </div>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-white/50">
                    Beneficiario / Proveedor
                  </label>
                  <input
                    type="text"
                    required
                    value={beneficiary}
                    onChange={(e) => setBeneficiary(e.target.value)}
                    placeholder="Razón Social Proveedor / Beneficiario"
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white focus:border-emerald-300 focus:outline-none"
                  />
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-white/50">
                    RUT / Tax ID Beneficiario
                  </label>
                  <input
                    type="text"
                    required
                    value={rut}
                    onChange={(e) => setRut(formatRut(e.target.value))}
                    placeholder="Ej: 76.938.743-9"
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white font-mono focus:border-emerald-300 focus:outline-none"
                  />
                </div>

                {/* Selector / Buscador Textual de Bancos */}
                <div className="space-y-1 relative">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-white/50">
                    Banco Destino
                  </label>
                  <div
                    onClick={() => setBankDropdownOpen(!bankDropdownOpen)}
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white flex items-center justify-between cursor-pointer hover:border-white/20 transition-colors"
                  >
                    <span className={bank ? "text-white font-medium" : "text-white/40"}>
                      {bank || "Seleccionar o buscar banco..."}
                    </span>
                    <ChevronDown className="w-4 h-4 text-white/50 shrink-0" />
                  </div>

                  {bankDropdownOpen && (
                    <div className="absolute z-30 left-0 right-0 mt-1 p-2 rounded-xl bg-[#0B0F17] border border-white/15 shadow-2xl space-y-2 backdrop-blur-md">
                      <div className="relative">
                        <Search className="w-3.5 h-3.5 text-white/40 absolute left-2.5 top-1/2 -translate-y-1/2" />
                        <input
                          type="text"
                          autoFocus
                          value={bankSearch}
                          onChange={(e) => setBankSearch(e.target.value)}
                          placeholder="Buscar o escribir banco..."
                          className="w-full pl-8 pr-2.5 py-1.5 rounded-lg bg-white/[0.06] border border-white/10 text-xs text-white focus:outline-none focus:border-emerald-300"
                        />
                      </div>
                      <div className="max-h-48 overflow-y-auto divide-y divide-white/[0.04] text-xs">
                        {CHILEAN_BANKS.filter((b) =>
                          b.toLowerCase().includes(bankSearch.toLowerCase())
                        ).map((b) => (
                          <div
                            key={b}
                            onClick={() => {
                              setBank(b);
                              setBankDropdownOpen(false);
                              setBankSearch("");
                            }}
                            className={`px-2.5 py-1.5 cursor-pointer rounded-lg hover:bg-emerald-300/10 hover:text-emerald-300 transition-colors ${
                              bank === b ? "bg-emerald-300/15 text-emerald-300 font-semibold" : "text-white/80"
                            }`}
                          >
                            {b}
                          </div>
                        ))}
                        {bankSearch.trim() &&
                          !CHILEAN_BANKS.some((b) =>
                            b.toLowerCase() === bankSearch.trim().toLowerCase()
                          ) && (
                            <div
                              onClick={() => {
                                setBank(bankSearch.trim());
                                setBankDropdownOpen(false);
                                setBankSearch("");
                              }}
                              className="px-2.5 py-1.5 cursor-pointer rounded-lg bg-emerald-400/10 hover:bg-emerald-400/20 text-emerald-300 font-semibold flex items-center justify-between"
                            >
                              <span>Usar: &quot;{bankSearch.trim()}&quot;</span>
                              <span className="text-[10px] uppercase font-bold text-emerald-400">Otro Banco</span>
                            </div>
                          )}
                      </div>
                    </div>
                  )}
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-white/50">
                    Número de Cuenta
                  </label>
                  <input
                    type="text"
                    required
                    value={account}
                    onChange={(e) => setAccount(e.target.value)}
                    placeholder="Número de Cuenta"
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white focus:border-emerald-300 focus:outline-none font-mono"
                  />
                </div>

                {/* DETECTOR EN VIVO DE DISCREPANCIA CONTRA PADRÓN (ANTI-BEC) */}
                {liveWhitelistCheck && (
                  <div
                    className={`p-3 rounded-xl border text-[11px] space-y-1 ${
                      liveWhitelistCheck.status === "ACCOUNT_MISMATCH"
                        ? "bg-rose-500/20 border-rose-400 text-rose-100"
                        : liveWhitelistCheck.status === "VERIFIED"
                        ? "bg-emerald-400/15 border-emerald-400/35 text-emerald-200"
                        : "bg-amber-400/15 border-amber-400/35 text-amber-200"
                    }`}
                  >
                    <div className="font-bold flex items-center gap-1.5">
                      {liveWhitelistCheck.status === "ACCOUNT_MISMATCH" ? (
                        <ShieldAlert className="w-4 h-4 text-rose-300 shrink-0" />
                      ) : liveWhitelistCheck.status === "VERIFIED" ? (
                        <CheckCircle2 className="w-4 h-4 text-emerald-300 shrink-0" />
                      ) : (
                        <AlertTriangle className="w-4 h-4 text-amber-300 shrink-0" />
                      )}
                      <span>{liveWhitelistCheck.title}</span>
                    </div>
                    <p className="leading-relaxed opacity-90">
                      {liveWhitelistCheck.detail}
                    </p>
                  </div>
                )}

                <div>
                  <label className="block text-[10px] uppercase tracking-wider text-white/45 font-bold mb-1">
                    Quórum de Firma Requerido
                  </label>
                  <select
                    value={quorumMode}
                    onChange={(e) =>
                      setQuorumMode(e.target.value as "auto" | "1" | "2")
                    }
                    className="w-full px-3 py-2 rounded-xl bg-[#080B11] border border-white/15 text-xs text-white"
                  >
                    <option value="auto">
                      Automático según política de empresa
                      {tenant.dual_approval_threshold &&
                      tenant.dual_approval_threshold > 0
                        ? ` (2 firmas desde ${tenant.threshold_currency} ${Number(
                            tenant.dual_approval_threshold
                          ).toLocaleString("es-CL")})`
                        : " (1 firma)"}
                    </option>
                    <option value="1">
                      1 Firma ({tenant.primary_approver_name})
                    </option>
                    {tenant.secondary_approver_name && (
                      <option value="2">
                        2 Firmas Mancomunadas ({tenant.primary_approver_name} +{" "}
                        {tenant.secondary_approver_name})
                      </option>
                    )}
                  </select>
                </div>

                <button
                  type="submit"
                  disabled={busy}
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="w-full py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
                >
                  Emitir y Despachar OOB
                </button>

                {createdVerifyUrl && (
                  <div className="p-3.5 rounded-xl bg-emerald-400/10 border border-emerald-400/30 space-y-2 text-xs">
                    <div className="font-bold text-emerald-300 flex items-center gap-1.5">
                      <CheckCircle2 className="w-4 h-4" />
                      <span>Desafío Anti-Voz: {createdChallenge}</span>
                    </div>
                    <a
                      href={createdVerifyUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1 text-white underline break-all text-[11px]"
                    >
                      <span>Enlace Firmante 1 ({tenant.primary_approver_name})</span>
                      <ExternalLink className="w-3 h-3 shrink-0" />
                    </a>
                    {createdVerifyUrl2 && (
                      <a
                        href={createdVerifyUrl2}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="block text-sky-300 underline break-all text-[11px]"
                      >
                        Enlace Firmante 2 ({tenant.secondary_approver_name}) ↗
                      </a>
                    )}
                  </div>
                )}
              </form>

              {/* CARGA MASIVA CSV DE NÓMINA DE PAGOS OOB */}
              <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3">
                <div className="flex items-center justify-between">
                  <div className="text-xs font-bold uppercase tracking-wider text-sky-300 flex items-center gap-1.5">
                    <Upload className="w-3.5 h-3.5" />
                    <span>Carga de Nómina Masiva CSV</span>
                  </div>
                  <button
                    type="button"
                    onClick={() =>
                      downloadClientCsvTemplate(
                        "Plantilla_Nomina_DeepShield.csv",
                        "monto;beneficiario;rut;banco;cuenta;firmas_requeridas\n4500000;Proveedor Tecnológico SpA;76123456-7;Banco de Chile;00-145-99821-01;1\n18500000;Constructora Industrial S.A.;96888111-K;Banco Santander;0-000-6543210-9;2\n"
                      )
                    }
                    className="text-[11px] text-sky-300 hover:underline inline-flex items-center gap-1"
                  >
                    <FileDown className="w-3 h-3" />
                    <span>Plantilla CSV</span>
                  </button>
                </div>
                <p className="text-[11px] text-white/55 leading-relaxed">
                  Importe el lote de pagos semanal desde su ERP. DeepShield
                  auditará automáticamente cada fila contra el Padrón Verificado
                  y enviará un resumen consolidado a los firmantes.
                </p>
                <label className="w-full py-2.5 px-3 rounded-xl bg-white/[0.06] hover:bg-white/15 border border-white/15 text-xs font-semibold text-white flex items-center justify-center gap-2 cursor-pointer transition-colors">
                  <Upload className="w-3.5 h-3.5 text-sky-300" />
                  <span>Seleccionar Archivo CSV de Nómina</span>
                  <input
                    type="file"
                    accept=".csv,text/csv"
                    onChange={handleBatchOrdersCsv}
                    className="hidden"
                  />
                </label>
              </div>
            </div>

            <div className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
              <div className="text-xs font-bold uppercase tracking-wider text-white/80">
                Historial Criptográfico & Semáforo Anti-BEC ({orders.length})
              </div>

              {orders.length === 0 ? (
                <div className="py-12 text-center text-xs text-white/45">
                  Sin instrucciones emitidas aún.
                </div>
              ) : (
                <div className="space-y-2.5">
                  {orders.map((o) => {
                    const reqS = Math.max(1, Number(o.required_approvals || 1));
                    const curS = Number(o.approval_count || 0);
                    return (
                      <div
                        key={o.id}
                        className={`p-4 rounded-xl bg-[#080B11] border flex flex-wrap items-center justify-between gap-3 text-xs ${
                          o.whitelist_status === "ACCOUNT_MISMATCH"
                            ? "border-rose-400/55"
                            : "border-white/10"
                        }`}
                      >
                        <div className="space-y-1 max-w-xl">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="font-mono font-bold text-emerald-300">
                              {o.order_code}
                            </span>
                            <span className="font-mono text-[10px] px-2 py-0.5 rounded bg-white/[0.06] text-white/75">
                              Clave Voz: {o.security_challenge}
                            </span>
                            {o.whitelist_status === "VERIFIED" && (
                              <span className="px-2 py-0.5 rounded bg-emerald-400/15 text-emerald-300 text-[10px] font-bold">
                                PADRÓN OK
                              </span>
                            )}
                            {o.whitelist_status === "ACCOUNT_MISMATCH" && (
                              <span className="px-2 py-0.5 rounded bg-rose-500/25 text-rose-200 border border-rose-400/40 text-[10px] font-bold">
                                ALERTA DISCREPANCIA CUENTA
                              </span>
                            )}
                            {o.whitelist_status === "UNREGISTERED_SUPPLIER" && (
                              <span className="px-2 py-0.5 rounded bg-amber-400/15 text-amber-200 text-[10px] font-bold">
                                PROVEEDOR NUEVO
                              </span>
                            )}
                            {reqS > 1 && (
                              <span className="px-2 py-0.5 rounded bg-sky-400/15 text-sky-200 text-[10px] font-bold">
                                QUÓRUM {curS}/{reqS} FIRMAS
                              </span>
                            )}
                          </div>
                          <div className="font-semibold text-white">
                            {o.beneficiary_name} ({o.beneficiary_rut_dni}) —{" "}
                            {o.currency}{" "}
                            {Number(o.amount).toLocaleString("es-CL")}
                          </div>
                          <div className="text-[11px] text-white/45">
                            {o.bank_name} · Cta {o.account_number}
                          </div>
                          {o.whitelist_status === "ACCOUNT_MISMATCH" &&
                            o.whitelist_detail && (
                              <div className="text-[11px] text-rose-300 pt-0.5">
                                {o.whitelist_detail}
                              </div>
                            )}
                        </div>

                        <div className="flex items-center gap-2">
                          <a
                            href={`/api/shield/client_api.php?action=treasury_pdf&id=${o.id}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="px-2.5 py-1 rounded-lg bg-white/[0.06] hover:bg-white text-white hover:text-[#0A0E15] text-[11px] font-semibold inline-flex items-center gap-1 transition-colors"
                            title="Descargar Certificado Pericial PDF SHA-256"
                          >
                            <FileDown className="w-3 h-3" />
                            <span>PDF</span>
                          </a>
                          <span
                            className={`px-3 py-1 rounded-lg text-[10px] font-bold uppercase ${
                              o.status === "APPROVED"
                                ? "bg-emerald-400/15 text-emerald-300"
                                : o.status === "PARTIAL_APPROVAL"
                                ? "bg-sky-400/20 text-sky-200"
                                : o.status === "REJECTED"
                                ? "bg-rose-500/20 text-rose-300"
                                : "bg-amber-400/15 text-amber-200"
                            }`}
                          >
                            {o.status === "PARTIAL_APPROVAL"
                              ? `1/${reqS} FIRMAS`
                              : o.status}
                          </span>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          </div>
        )}

        {tab === "whitelist" && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="space-y-4">
              <form
                onSubmit={handleAddWhitelist}
                className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3.5"
              >
                <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-300">
                  <Plus className="w-4 h-4" />
                  <span>Inscribir Cuenta Verificada</span>
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-white/50">
                    Razón Social Proveedor
                  </label>
                  <input
                    type="text"
                    required
                    value={wlName}
                    onChange={(e) => setWlName(e.target.value)}
                    placeholder="Razón Social Proveedor"
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white focus:border-emerald-300 focus:outline-none"
                  />
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-white/50">
                    RUT Proveedor
                  </label>
                  <input
                    type="text"
                    required
                    value={wlRut}
                    onChange={(e) => setWlRut(formatRut(e.target.value))}
                    placeholder="Ej: 76.938.743-9"
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white font-mono focus:border-emerald-300 focus:outline-none"
                  />
                </div>

                {/* Selector / Buscador Textual de Bancos para Padrón */}
                <div className="space-y-1 relative">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-white/50">
                    Banco Oficial
                  </label>
                  <div
                    onClick={() => setWlBankDropdownOpen(!wlBankDropdownOpen)}
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white flex items-center justify-between cursor-pointer hover:border-white/20 transition-colors"
                  >
                    <span className={wlBank ? "text-white font-medium" : "text-white/40"}>
                      {wlBank || "Seleccionar o buscar banco..."}
                    </span>
                    <ChevronDown className="w-4 h-4 text-white/50 shrink-0" />
                  </div>

                  {wlBankDropdownOpen && (
                    <div className="absolute z-30 left-0 right-0 mt-1 p-2 rounded-xl bg-[#0B0F17] border border-white/15 shadow-2xl space-y-2 backdrop-blur-md">
                      <div className="relative">
                        <Search className="w-3.5 h-3.5 text-white/40 absolute left-2.5 top-1/2 -translate-y-1/2" />
                        <input
                          type="text"
                          autoFocus
                          value={wlBankSearch}
                          onChange={(e) => setWlBankSearch(e.target.value)}
                          placeholder="Buscar o escribir banco..."
                          className="w-full pl-8 pr-2.5 py-1.5 rounded-lg bg-white/[0.06] border border-white/10 text-xs text-white focus:outline-none focus:border-emerald-300"
                        />
                      </div>
                      <div className="max-h-48 overflow-y-auto divide-y divide-white/[0.04] text-xs">
                        {CHILEAN_BANKS.filter((b) =>
                          b.toLowerCase().includes(wlBankSearch.toLowerCase())
                        ).map((b) => (
                          <div
                            key={b}
                            onClick={() => {
                              setWlBank(b);
                              setWlBankDropdownOpen(false);
                              setWlBankSearch("");
                            }}
                            className={`px-2.5 py-1.5 cursor-pointer rounded-lg hover:bg-emerald-300/10 hover:text-emerald-300 transition-colors ${
                              wlBank === b ? "bg-emerald-300/15 text-emerald-300 font-semibold" : "text-white/80"
                            }`}
                          >
                            {b}
                          </div>
                        ))}
                        {wlBankSearch.trim() &&
                          !CHILEAN_BANKS.some((b) =>
                            b.toLowerCase() === wlBankSearch.trim().toLowerCase()
                          ) && (
                            <div
                              onClick={() => {
                                setWlBank(wlBankSearch.trim());
                                setWlBankDropdownOpen(false);
                                setWlBankSearch("");
                              }}
                              className="px-2.5 py-1.5 cursor-pointer rounded-lg bg-emerald-400/10 hover:bg-emerald-400/20 text-emerald-300 font-semibold flex items-center justify-between"
                            >
                              <span>Usar: &quot;{wlBankSearch.trim()}&quot;</span>
                              <span className="text-[10px] uppercase font-bold text-emerald-400">Otro Banco</span>
                            </div>
                          )}
                      </div>
                    </div>
                  )}
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-white/50">
                    Número de Cuenta
                  </label>
                  <input
                    type="text"
                    required
                    value={wlAcc}
                    onChange={(e) => setWlAcc(e.target.value)}
                    placeholder="Cuenta Bancaria Oficial"
                    className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white focus:border-emerald-300 focus:outline-none font-mono"
                  />
                </div>
                <button
                  type="submit"
                  className="w-full py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
                >
                  Certificar Cuenta en Padrón
                </button>
              </form>

              <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3">
                <div className="flex items-center justify-between">
                  <div className="text-xs font-bold uppercase tracking-wider text-sky-300 flex items-center gap-1.5">
                    <Upload className="w-3.5 h-3.5" />
                    <span>Importar Maestro ERP (.CSV)</span>
                  </div>
                  <button
                    type="button"
                    onClick={() =>
                      downloadClientCsvTemplate(
                        "Plantilla_Padron_Proveedores.csv",
                        "razon_social;rut;banco;cuenta\nProveedor Tecnológico SpA;76123456-7;Banco de Chile;00-145-99821-01\nConstructora Industrial S.A.;96888111-K;Banco Santander;0-000-6543210-9\n"
                      )
                    }
                    className="text-[11px] text-sky-300 hover:underline inline-flex items-center gap-1"
                  >
                    <FileDown className="w-3 h-3" />
                    <span>Plantilla CSV</span>
                  </button>
                </div>
                <p className="text-[11px] text-white/55">
                  Cargue masivamente hasta 500 proveedores verificados desde
                  SAP, Softland, Defontana o NetSuite.
                </p>
                <label className="w-full py-2.5 px-3 rounded-xl bg-white/[0.06] hover:bg-white/15 border border-white/15 text-xs font-semibold text-white flex items-center justify-center gap-2 cursor-pointer transition-colors">
                  <Upload className="w-3.5 h-3.5 text-sky-300" />
                  <span>Cargar CSV de Proveedores</span>
                  <input
                    type="file"
                    accept=".csv,text/csv"
                    onChange={handleBatchWhitelistCsv}
                    className="hidden"
                  />
                </label>
              </div>
            </div>

            <div className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3">
              <div className="text-xs font-bold uppercase tracking-wider text-white/80">
                Padrón Inmutable de Proveedores Verificados (Anti-BEC)
              </div>
              {whitelist.length === 0 ? (
                <div className="py-10 text-center text-xs text-white/45">
                  Aún no hay cuentas inscritas en la lista blanca.
                </div>
              ) : (
                <div className="divide-y divide-white/[0.06] text-xs">
                  {whitelist.map((w) => (
                    <div
                      key={w.id}
                      className="py-3 flex items-center justify-between gap-3"
                    >
                      <div className="flex items-center gap-2.5">
                        <Building2 className="w-4 h-4 text-emerald-300 shrink-0" />
                        <div>
                          <div className="font-semibold text-white">
                            {w.supplier_name} ({w.supplier_rut})
                          </div>
                          <div className="text-[11px] text-white/50">
                            {w.bank_name} · Cuenta {w.account_number}
                          </div>
                        </div>
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="px-2.5 py-0.5 rounded bg-emerald-400/15 text-emerald-300 text-[10px] font-bold">
                          VERIFICADO
                        </span>
                        <button
                          type="button"
                          onClick={async () => {
                            if (
                              !window.confirm(
                                `¿Retirar la cuenta de ${w.supplier_name} del padrón verificado?`
                              )
                            )
                              return;
                            await fetch("/api/shield/client_api.php", {
                              method: "POST",
                              credentials: "include",
                              headers: { "Content-Type": "application/json" },
                              body: JSON.stringify({
                                action: "delete_whitelist",
                                id: w.id,
                              }),
                            });
                            loadSession();
                          }}
                          className="p-1.5 rounded-lg text-white/40 hover:text-rose-300 hover:bg-rose-500/15 transition-colors"
                          title="Retirar del padrón"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}

        {tab === "signers" && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <form
              onSubmit={handleSaveSigners}
              className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-6 space-y-5"
            >
              <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-300">
                <Users className="w-4 h-4" />
                <span>Matriz de Apoderados Firmantes OOB & Doble Firma</span>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="p-4 rounded-xl bg-[#080B11] border border-white/10 space-y-3">
                  <div className="text-[11px] font-bold uppercase tracking-wider text-emerald-300">
                    Firmante 1 (Principal / Obligatorio)
                  </div>
                  <div>
                    <label className="block text-[10px] text-white/50 mb-1">
                      Nombre y Cargo (Ej: CFO / Gte. Finanzas)
                    </label>
                    <input
                      type="text"
                      required
                      value={pSignerName}
                      onChange={(e) => setPSignerName(e.target.value)}
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                  </div>
                  <div>
                    <label className="block text-[10px] text-white/50 mb-1">
                      WhatsApp Móvil OOB (Ej: 56930640431)
                    </label>
                    <input
                      type="text"
                      required
                      value={pSignerPhone}
                      onChange={(e) => setPSignerPhone(e.target.value)}
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                  </div>
                </div>

                <div className="p-4 rounded-xl bg-[#080B11] border border-sky-400/25 space-y-3">
                  <div className="text-[11px] font-bold uppercase tracking-wider text-sky-300">
                    Firmante 2 Mancomunado (Opcional)
                  </div>
                  <div>
                    <label className="block text-[10px] text-white/50 mb-1">
                      Nombre y Cargo (Ej: Gerente General)
                    </label>
                    <input
                      type="text"
                      value={sSignerName}
                      onChange={(e) => setSSignerName(e.target.value)}
                      placeholder="Dejar vacío si solo opera con 1 firma"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                  </div>
                  <div>
                    <label className="block text-[10px] text-white/50 mb-1">
                      WhatsApp Móvil Firmante 2
                    </label>
                    <input
                      type="text"
                      value={sSignerPhone}
                      onChange={(e) => setSSignerPhone(e.target.value)}
                      placeholder="Ej: 56988776655"
                      className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
                    />
                  </div>
                </div>
              </div>

              <div className="p-4 rounded-xl bg-[#080B11] border border-white/10 space-y-2">
                <label className="block text-xs font-bold text-white">
                  Umbral Automático para Exigir 2 Firmas Mancomunadas (
                  {tenant.threshold_currency})
                </label>
                <p className="text-[11px] text-white/55">
                  Si indica un monto mayor a 0 (ej. 10000000), toda orden igual
                  o superior exigirá automáticamente la firma independiente del
                  Firmante 1 y del Firmante 2 antes de pasar a APPROVED.
                </p>
                <input
                  type="number"
                  min="0"
                  value={dualThresholdInput}
                  onChange={(e) => setDualThresholdInput(e.target.value)}
                  className="w-full sm:w-72 px-3 py-2 rounded-xl bg-white/[0.04] border border-white/15 text-xs text-white"
                />
              </div>

              <button
                type="submit"
                disabled={busy}
                style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                className="px-6 py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
              >
                Guardar Reglas de Quórum
              </button>
            </form>

            <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3 text-xs">
              <div className="font-bold uppercase tracking-wider text-white/80">
                Cómo opera la Firma Mancomunada
              </div>
              <p className="text-white/60 leading-relaxed">
                1. DeepShield genera{" "}
                <strong className="text-white">
                  dos enlaces criptográficos distintos
                </strong>
                , enviados por separado al celular de cada apoderado.
              </p>
              <p className="text-white/60 leading-relaxed">
                2. Cuando el primer ejecutivo autoriza, la orden pasa a{" "}
                <span className="font-mono text-sky-300">1/2 FIRMAS</span>{" "}
                (bloqueando que el mismo enlace firme por segunda vez).
              </p>
              <p className="text-white/60 leading-relaxed">
                3. Solo al estamparse la segunda firma pasa a{" "}
                <span className="font-mono text-emerald-300">APPROVED</span> con
                sello SHA-256 compuesto. Si cualquiera de los dos pulsa
                Bloquear, la orden queda rechazada de inmediato.
              </p>
            </div>
          </div>
        )}

        {tab === "whatsapp" && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
              <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-300">
                <Smartphone className="w-4 h-4" />
                <span>Autoatención Canal WhatsApp</span>
              </div>

              <div className="p-3.5 rounded-xl bg-[#080B11] border border-white/10 space-y-1.5 text-xs">
                <div className="text-[10px] uppercase tracking-wider text-white/45 font-bold">
                  Modalidad Activa de Despacho OOB
                </div>
                <div className="font-semibold text-white">
                  {!tenant.whatsapp_instance ||
                  tenant.whatsapp_instance === "keystone-general"
                    ? "Canal Oficial Compartido Keystone"
                    : "WhatsApp Corporativo Propio de su Empresa"}
                </div>
                <div className="font-mono text-[11px] text-emerald-300">
                  Instancia: {tenant.whatsapp_instance || "keystone-general"}
                </div>
              </div>

              <p className="text-xs text-white/60 leading-relaxed">
                Por defecto, las órdenes de tesorería se notifican al director
                firmante mediante el canal certificado de Keystone. Si su
                organización prefiere que los mensajes salgan desde un{" "}
                <strong className="text-white">
                  número WhatsApp corporativo propio
                </strong>
                , habilítelo aquí y escanee el código QR.
              </p>

              <div className="space-y-2 pt-1">
                {(!tenant.whatsapp_instance ||
                  tenant.whatsapp_instance === "keystone-general") && (
                  <button
                    type="button"
                    disabled={waBusy}
                    onClick={handleEnableDedicatedWhatsApp}
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="w-full py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors flex items-center justify-center gap-2"
                  >
                    <QrCode className="w-4 h-4" />
                    <span>
                      {waBusy
                        ? "Preparando Instancia..."
                        : "Habilitar WhatsApp Propio (Generar QR)"}
                    </span>
                  </button>
                )}

                {tenant.whatsapp_instance &&
                  tenant.whatsapp_instance !== "keystone-general" && (
                    <>
                      <button
                        type="button"
                        disabled={waBusy}
                        onClick={checkWhatsAppPortalStatus}
                        className="w-full py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors flex items-center justify-center gap-2"
                      >
                        <RefreshCw
                          className={`w-3.5 h-3.5 ${
                            waBusy ? "animate-spin" : ""
                          }`}
                        />
                        <span>Verificar Conexión / Actualizar QR</span>
                      </button>

                      <button
                        type="button"
                        disabled={waBusy}
                        onClick={handleUseSharedWhatsApp}
                        className="w-full py-2 rounded-xl bg-white/[0.06] hover:bg-white/15 text-white/80 text-xs font-semibold transition-colors"
                      >
                        Desvincular y volver a Canal Keystone (keystone-general)
                      </button>
                    </>
                  )}
              </div>
            </div>

            <div className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-6 flex flex-col justify-between space-y-5">
              <div className="space-y-2">
                <div className="text-xs font-bold uppercase tracking-wider text-white/80">
                  Vinculación Dispositivo Corporativo (QR Evolution API)
                </div>
                <p className="text-xs text-white/55">
                  {waStatusMsg ||
                    (!tenant.whatsapp_instance ||
                    tenant.whatsapp_instance === "keystone-general"
                      ? "Actualmente operando sin requerir teléfono propio gracias al canal maestro de Keystone. Pulse 'Habilitar WhatsApp Propio' a la izquierda si desea enlazar una línea de su empresa."
                      : "Verificando estado de su instancia dedicada...")}
                </p>
              </div>

              {tenant.whatsapp_instance &&
              tenant.whatsapp_instance !== "keystone-general" ? (
                <div className="p-5 rounded-2xl bg-[#080B11] border border-white/10 flex flex-col sm:flex-row items-center gap-6">
                  {waConnected ? (
                    <div className="w-40 h-40 rounded-2xl bg-emerald-400/10 border border-emerald-400/35 flex flex-col items-center justify-center text-center p-4 shrink-0">
                      <CheckCircle2 className="w-10 h-10 text-emerald-300 mb-2" />
                      <span className="text-xs font-bold text-emerald-300">
                        CONECTADO EN LÍNEA
                      </span>
                    </div>
                  ) : waQrBase64 ? (
                    /* eslint-disable-next-line @next/next/no-img-element */
                    <img
                      src={waQrBase64}
                      alt="Código QR WhatsApp Corporativo"
                      className="w-44 h-44 rounded-2xl bg-white p-2 shrink-0 shadow-xl"
                    />
                  ) : (
                    <div className="w-44 h-44 rounded-2xl bg-white/[0.04] border border-white/10 flex flex-col items-center justify-center text-center p-4 shrink-0">
                      <QrCode className="w-8 h-8 text-white/40 mb-2" />
                      <span className="text-[11px] text-white/50">
                        Pulse &ldquo;Verificar Conexión / Actualizar QR&rdquo;
                      </span>
                    </div>
                  )}

                  <div className="space-y-2.5 text-xs text-white/75">
                    <div className="font-bold text-white text-sm">
                      {waConnected
                        ? "Su línea WhatsApp corporativa está activa"
                        : "Pasos para enlazar el WhatsApp de su empresa:"}
                    </div>
                    {waConnected ? (
                      <p className="text-white/60 leading-relaxed">
                        Todas las solicitudes de firma OOB emitidas desde este
                        portal se enviarán directamente desde su número propio (
                        <span className="font-mono text-emerald-300">
                          {tenant.whatsapp_instance}
                        </span>
                        ). Si el teléfono corporativo llegara a apagarse o
                        quedarse sin batería, DeepShield activará
                        automáticamente el respaldo vía{" "}
                        <span className="font-mono">keystone-general</span> para
                        no detener pagos críticos.
                      </p>
                    ) : (
                      <ol className="list-decimal list-inside space-y-1.5 text-white/65">
                        <li>
                          Abra <strong>WhatsApp</strong> o{" "}
                          <strong>WhatsApp Business</strong> en el teléfono de
                          Tesorería / Finanzas de su empresa.
                        </li>
                        <li>
                          Toque Menú o Configuración y seleccione{" "}
                          <strong>Dispositivos vinculados</strong>.
                        </li>
                        <li>
                          Pulse <strong>Vincular un dispositivo</strong> y
                          apunte la cámara al código QR de esta pantalla.
                        </li>
                        <li>
                          Una vez escaneado, haga clic en{" "}
                          <strong>Verificar Conexión</strong> para confirmar el
                          enlace en verde.
                        </li>
                      </ol>
                    )}
                  </div>
                </div>
              ) : (
                <div className="p-5 rounded-2xl bg-[#080B11] border border-emerald-400/20 flex items-center gap-4 text-xs">
                  <CheckCircle2 className="w-6 h-6 text-emerald-300 shrink-0" />
                  <div className="text-white/70 leading-relaxed">
                    <strong className="text-white block mb-0.5">
                      Canal Certificado Keystone Activo (Cero Fricción)
                    </strong>
                    Las notificaciones al director firmante salen de inmediato
                    por la línea verificada de Keystone Advisors sin que su
                    empresa tenga que mantener un teléfono encendido.
                  </div>
                </div>
              )}
            </div>
          </div>
        )}
      </main>

      {/* MODAL INTERACTIVO SOP-SEC-TREASURY-01 EN PORTAL CLIENTES */}
      {sopOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm p-4">
          <div className="w-full max-w-2xl max-h-[88vh] overflow-y-auto rounded-2xl bg-[#0D121C] border border-white/15 p-6 space-y-4 shadow-2xl">
            <div className="flex items-start justify-between border-b border-white/10 pb-3">
              <div>
                <div className="text-[11px] font-bold uppercase tracking-widest text-emerald-300">
                  SOP-SEC-TREASURY-01 · Guía de Operación Segura
                </div>
                <h3
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="text-lg font-bold text-white mt-0.5"
                >
                  Protocolo Anti-BEC & Custodia de Pagos OOB
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setSopOpen(false)}
                className="text-white/60 hover:text-white"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-3.5 rounded-xl bg-emerald-400/10 border border-emerald-400/30 flex flex-wrap items-center justify-between gap-3 text-xs">
              <span className="text-white/85">
                Descargue el Manual Operativo Corporativo completo en Word
                (.docx):
              </span>
              <a
                href="/MANUAL_OPERATIVO_SOP_DEEPSHIELD_TREASURY.docx"
                download
                className="px-3.5 py-2 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] font-bold inline-flex items-center gap-1.5 transition-colors"
              >
                <FileDown className="w-3.5 h-3.5" />
                <span>Descargar Manual Word (.docx)</span>
              </a>
            </div>

            <div className="space-y-2.5 text-xs text-white/75">
              <div className="p-3.5 rounded-xl bg-[#080B11] border border-white/10">
                <strong className="text-white block mb-1">
                  1. Poblar el Padrón Verificado (Individual o CSV)
                </strong>
                Inscriba las cuentas bancarias históricas de sus proveedores
                críticos. Si un atacante intercepta un correo pidiendo cambiar
                la cuenta corriente, DeepShield disparará una Alerta Roja
                automática.
              </div>
              <div className="p-3.5 rounded-xl bg-[#080B11] border border-white/10">
                <strong className="text-white block mb-1">
                  2. Emitir Orden o Cargar Nómina CSV antes de pagar en Banco
                </strong>
                Ingrese la transferencia o cargue el lote CSV. El sistema
                enviará enlaces criptográficos independientes por WhatsApp a los
                apoderados firmantes (1 o 2 firmas mancomunadas).
              </div>
              <div className="p-3.5 rounded-xl bg-[#080B11] border border-white/10">
                <strong className="text-white block mb-1">
                  3. Exigir Desafío Anti-Voz IA ante llamadas urgentes
                </strong>
                Ante cualquier instrucción telefónica o audio pidiendo un pago
                urgente, exija los 6 caracteres del Desafío Anti-Voz vigente.
              </div>
              <div className="p-3.5 rounded-xl bg-[#080B11] border border-white/10">
                <strong className="text-white block mb-1">
                  4. Liberar en Portal Bancario solo con estado APPROVED
                </strong>
                Verifique el estado APPROVED y descargue el Certificado PDF con
                sello SHA-256 como respaldo contable y de auditoría.
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
