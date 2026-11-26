"use client";

import React, { useState, useEffect, useCallback } from "react";
import Link from "next/link";
import {
  ShieldCheck,
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
} from "lucide-react";

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
  status: string;
  security_challenge: string;
  cryptographic_signature: string | null;
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

export default function ShieldPortalPage() {
  const [loading, setLoading] = useState<boolean>(true);
  const [authed, setAuthed] = useState<boolean>(false);
  const [tenant, setTenant] = useState<TenantSession | null>(null);
  const [orders, setOrders] = useState<TreasuryOrder[]>([]);
  const [whitelist, setWhitelist] = useState<WhitelistSupplier[]>([]);
  const [tab, setTab] = useState<"orders" | "whitelist">("orders");

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
  const [account, setAccount] = useState<string>("");
  const [createdVerifyUrl, setCreatedVerifyUrl] = useState<string | null>(null);
  const [createdChallenge, setCreatedChallenge] = useState<string | null>(null);

  // Whitelist Form
  const [wlName, setWlName] = useState<string>("");
  const [wlRut, setWlRut] = useState<string>("");
  const [wlBank, setWlBank] = useState<string>("");
  const [wlAcc, setWlAcc] = useState<string>("");

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
    }
    loadSession();
  }, [loadSession]);

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
    try {
      const r = await fetch("/api/shield/client_api.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "emit_order",
          amount: parseFloat(amount),
          beneficiary_name: beneficiary,
          beneficiary_rut_dni: rut,
          bank_name: bank,
          account_number: account,
        }),
      });
      const d = await r.json();
      if (d?.success) {
        setCreatedVerifyUrl(d.verify_url);
        setCreatedChallenge(d.security_challenge);
        setAmount("");
        setBeneficiary("");
        setRut("");
        setBank("");
        setAccount("");
        loadSession();
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
        setWlAcc("");
        loadSession();
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
              RUT {tenant.rut_dni} · Firmante OOB:{" "}
              {tenant.primary_approver_name}
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setTab("orders")}
            className={`px-3.5 py-1.5 rounded-xl text-xs font-semibold transition-colors ${
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
            className={`px-3.5 py-1.5 rounded-xl text-xs font-semibold transition-colors ${
              tab === "whitelist"
                ? "bg-emerald-300 text-[#0A0E15]"
                : "bg-white/[0.05] text-white/70"
            }`}
          >
            Padrón Verificado ({whitelist.length})
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

      <main className="max-w-6xl mx-auto p-6 space-y-6">
        {tab === "orders" && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <form
              onSubmit={handleEmitOrder}
              className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3.5"
            >
              <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-300">
                <Landmark className="w-4 h-4" />
                <span>Solicitar Autorización OOB</span>
              </div>

              <input
                type="number"
                required
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                placeholder={`Monto en ${tenant.threshold_currency}`}
                className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
              />
              <input
                type="text"
                required
                value={beneficiary}
                onChange={(e) => setBeneficiary(e.target.value)}
                placeholder="Razón Social Proveedor / Beneficiario"
                className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
              />
              <input
                type="text"
                required
                value={rut}
                onChange={(e) => setRut(e.target.value)}
                placeholder="RUT / Tax ID Beneficiario"
                className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
              />
              <input
                type="text"
                required
                value={bank}
                onChange={(e) => setBank(e.target.value)}
                placeholder="Banco Destino"
                className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
              />
              <input
                type="text"
                required
                value={account}
                onChange={(e) => setAccount(e.target.value)}
                placeholder="Número de Cuenta"
                className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
              />

              <button
                type="submit"
                disabled={busy}
                style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                className="w-full py-2.5 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider transition-colors"
              >
                Emitir y Despachar al Director
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
                    <span>Abrir Enlace Móvil de Firma OOB</span>
                    <ExternalLink className="w-3 h-3 shrink-0" />
                  </a>
                </div>
              )}
            </form>

            <div className="lg:col-span-2 rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-4">
              <div className="text-xs font-bold uppercase tracking-wider text-white/80">
                Historial Criptográfico de Transferencias
              </div>

              {orders.length === 0 ? (
                <div className="py-12 text-center text-xs text-white/45">
                  Sin instrucciones emitidas aún.
                </div>
              ) : (
                <div className="space-y-2.5">
                  {orders.map((o) => (
                    <div
                      key={o.id}
                      className="p-4 rounded-xl bg-[#080B11] border border-white/10 flex flex-wrap items-center justify-between gap-3 text-xs"
                    >
                      <div>
                        <div className="flex items-center gap-2">
                          <span className="font-mono font-bold text-emerald-300">
                            {o.order_code}
                          </span>
                          <span className="font-mono text-[10px] px-2 py-0.5 rounded bg-white/[0.06] text-white/70">
                            Clave Voz: {o.security_challenge}
                          </span>
                        </div>
                        <div className="font-semibold text-white mt-1">
                          {o.beneficiary_name} ({o.beneficiary_rut_dni}) —{" "}
                          {o.currency}{" "}
                          {Number(o.amount).toLocaleString("es-CL")}
                        </div>
                        <div className="text-[11px] text-white/45">
                          {o.bank_name} · Cta {o.account_number}
                        </div>
                      </div>
                      <span
                        className={`px-3 py-1 rounded-lg text-[10px] font-bold uppercase ${
                          o.status === "APPROVED"
                            ? "bg-emerald-400/15 text-emerald-300"
                            : o.status === "REJECTED"
                            ? "bg-rose-500/20 text-rose-300"
                            : "bg-amber-400/15 text-amber-200"
                        }`}
                      >
                        {o.status}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}

        {tab === "whitelist" && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <form
              onSubmit={handleAddWhitelist}
              className="rounded-2xl bg-[#0D121C] border border-white/[0.08] p-5 space-y-3.5"
            >
              <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-300">
                <Plus className="w-4 h-4" />
                <span>Inscribir Cuenta Verificada</span>
              </div>
              <input
                type="text"
                required
                value={wlName}
                onChange={(e) => setWlName(e.target.value)}
                placeholder="Razón Social Proveedor"
                className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
              />
              <input
                type="text"
                required
                value={wlRut}
                onChange={(e) => setWlRut(e.target.value)}
                placeholder="RUT Proveedor"
                className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
              />
              <input
                type="text"
                required
                value={wlBank}
                onChange={(e) => setWlBank(e.target.value)}
                placeholder="Banco"
                className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
              />
              <input
                type="text"
                required
                value={wlAcc}
                onChange={(e) => setWlAcc(e.target.value)}
                placeholder="Cuenta Bancaria Oficial"
                className="w-full px-3 py-2 rounded-xl bg-white/[0.04] border border-white/12 text-xs text-white"
              />
              <button
                type="submit"
                className="w-full py-2.5 rounded-xl bg-emerald-300 text-[#0A0E15] text-xs font-bold uppercase tracking-wider"
              >
                Certificar Cuenta en Padrón
              </button>
            </form>

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
                      className="py-3 flex items-center justify-between"
                    >
                      <div className="flex items-center gap-2.5">
                        <Building2 className="w-4 h-4 text-emerald-300" />
                        <div>
                          <div className="font-semibold text-white">
                            {w.supplier_name} ({w.supplier_rut})
                          </div>
                          <div className="text-[11px] text-white/50">
                            {w.bank_name} · Cuenta {w.account_number}
                          </div>
                        </div>
                      </div>
                      <span className="px-2.5 py-0.5 rounded bg-emerald-400/15 text-emerald-300 text-[10px] font-bold">
                        VERIFICADO
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}
      </main>
    </div>
  );
}
