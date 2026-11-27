"use client";

import React, { useState, useEffect } from "react";
import Link from "next/link";
import {
  ShieldCheck,
  ShieldAlert,
  CheckCircle2,
  XCircle,
  Loader2,
  Fingerprint,
  AlertTriangle,
  Users,
} from "lucide-react";

interface ApprovalLogEntry {
  slot: string;
  name: string;
  decision: string;
  at: string;
  ip: string;
  sig: string;
}

interface VerifyOrder {
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
  approvals_log_json?: string;
  signer_slot?: string;
  signer_name?: string;
  approved_at: string | null;
}

export default function ShieldVerifyPage() {
  const [token, setToken] = useState<string>("");
  const [loading, setLoading] = useState<boolean>(true);
  const [order, setOrder] = useState<VerifyOrder | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [actionErr, setActionErr] = useState<string | null>(null);
  const [notes, setNotes] = useState<string>("");
  const [busy, setBusy] = useState<boolean>(false);

  useEffect(() => {
    if (typeof window === "undefined") return;
    const t = new URLSearchParams(window.location.search).get("token") || "";
    setToken(t);
    if (!t) {
      setErr("Enlace sin token criptográfico de autorización.");
      setLoading(false);
      return;
    }

    fetch(
      `/api/shield/client_api.php?action=order_by_token&token=${encodeURIComponent(
        t
      )}`
    )
      .then((r) => r.json())
      .then((d) => {
        if (!d?.success || !d?.order) {
          setErr(d?.error || "Orden no encontrada o expirada.");
        } else {
          setOrder(d.order);
        }
      })
      .catch(() => setErr("No se pudo verificar la firma con el servidor."))
      .finally(() => setLoading(false));
  }, []);

  const resolveOrder = async (decision: "APPROVE" | "REJECT") => {
    setBusy(true);
    setActionErr(null);
    try {
      const r = await fetch("/api/shield/client_api.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "resolve_oob",
          token,
          decision,
          notes,
        }),
      });
      const d = await r.json();
      if (d?.success && order) {
        setOrder({
          ...order,
          status: d.status,
          approval_count: d.approval_count,
          required_approvals: d.required_approvals,
          cryptographic_signature: d.signature,
          approvals_log_json: JSON.stringify(d.approvals_log || []),
        });
      } else {
        setActionErr(d?.error || "Error al procesar decisión.");
      }
    } finally {
      setBusy(false);
    }
  };

  const parsedLog: ApprovalLogEntry[] = (() => {
    if (!order?.approvals_log_json) return [];
    try {
      const arr = JSON.parse(order.approvals_log_json);
      return Array.isArray(arr) ? arr : [];
    } catch {
      return [];
    }
  })();

  const reqSigners = Math.max(1, Number(order?.required_approvals || 1));
  const curSigners = Number(order?.approval_count || 0);
  const canStillSign =
    order &&
    (order.status === "PENDING_APPROVAL" ||
      order.status === "PARTIAL_APPROVAL") &&
    !parsedLog.some(
      (e) => e.slot === order.signer_slot && e.decision === "APPROVED"
    );

  return (
    <div
      className="min-h-screen flex items-center justify-center bg-[#080B11] text-white p-4"
      style={{ fontFamily: "var(--font-inter), sans-serif" }}
    >
      <div className="w-full max-w-md rounded-2xl bg-[#0D121C] border border-white/15 p-6 shadow-2xl space-y-4">
        <div className="text-center border-b border-white/10 pb-4">
          <div className="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.2em] text-emerald-300">
            <ShieldCheck className="w-4 h-4" />
            <span>DeepShield Out-of-Band</span>
          </div>
          <h1
            style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
            className="text-xl font-bold text-white mt-1"
          >
            Autorización Criptográfica de Pago
          </h1>
          {order?.signer_name && (
            <div className="text-[11px] text-white/60 mt-1">
              Sesión exclusiva:{" "}
              <strong className="text-emerald-300">{order.signer_name}</strong>
              {reqSigners > 1 && (
                <span>
                  {" "}
                  · Quórum Mancomunado ({curSigners}/{reqSigners} firmas)
                </span>
              )}
            </div>
          )}
        </div>

        {loading ? (
          <div className="py-12 flex justify-center">
            <Loader2 className="w-6 h-6 animate-spin text-emerald-300" />
          </div>
        ) : err || !order ? (
          <div className="p-4 rounded-xl bg-rose-500/15 border border-rose-400/30 text-xs text-rose-200 text-center space-y-3">
            <ShieldAlert className="w-8 h-8 text-rose-400 mx-auto" />
            <p>{err}</p>
            <Link href="/" className="inline-block text-white underline">
              Volver a Keystone Advisors
            </Link>
          </div>
        ) : (
          <div className="space-y-4">
            {/* DICTAMEN AUTOMÁTICO DE PADRÓN VERIFICADO (WHITELIST ANTI-BEC) */}
            {order.whitelist_status === "ACCOUNT_MISMATCH" ? (
              <div className="rounded-xl bg-rose-500/20 border-2 border-rose-400 p-3.5 text-xs space-y-1">
                <div className="flex items-center gap-1.5 font-bold text-rose-200 uppercase tracking-wider text-[11px]">
                  <ShieldAlert className="w-4 h-4 text-rose-300 shrink-0" />
                  <span>Alerta Crítica Anti-BEC: Discrepancia de Cuenta</span>
                </div>
                <p className="text-rose-100/90 leading-relaxed text-[11px]">
                  {order.whitelist_detail}
                </p>
              </div>
            ) : order.whitelist_status === "VERIFIED" ? (
              <div className="rounded-xl bg-emerald-400/15 border border-emerald-400/35 p-3 text-xs flex items-start gap-2.5">
                <CheckCircle2 className="w-4 h-4 text-emerald-300 shrink-0 mt-0.5" />
                <div>
                  <div className="font-bold text-emerald-300 text-[11px] uppercase tracking-wider">
                    Cuenta Certificada en Padrón Oficial
                  </div>
                  <div className="text-white/75 text-[11px] mt-0.5">
                    {order.whitelist_detail}
                  </div>
                </div>
              </div>
            ) : (
              <div className="rounded-xl bg-amber-400/15 border border-amber-400/35 p-3 text-xs flex items-start gap-2.5">
                <AlertTriangle className="w-4 h-4 text-amber-300 shrink-0 mt-0.5" />
                <div>
                  <div className="font-bold text-amber-200 text-[11px] uppercase tracking-wider">
                    Proveedor No Inscrito en Padrón
                  </div>
                  <div className="text-white/75 text-[11px] mt-0.5">
                    {order.whitelist_detail ||
                      "Primera transferencia a este RUT o proveedor aún no certificado en lista blanca."}
                  </div>
                </div>
              </div>
            )}

            <div className="rounded-xl bg-[#080B11] border border-violet-400/35 p-4 text-center">
              <div className="text-[10px] uppercase tracking-widest text-violet-300 font-bold">
                Desafío Anti-Clonación de Voz IA
              </div>
              <div className="font-mono text-2xl font-extrabold tracking-[0.28em] text-white mt-1">
                {order.security_challenge}
              </div>
              <p className="text-[11px] text-white/50 mt-1">
                Si alguien le llamó por teléfono pidiendo este pago, exija que
                confirme este código exacto.
              </p>
            </div>

            <div className="space-y-2 text-xs bg-white/[0.03] rounded-xl p-4 border border-white/[0.07]">
              <div className="flex justify-between">
                <span className="text-white/50">Orden:</span>
                <span className="font-mono font-bold text-white">
                  {order.order_code}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-white/50">Empresa:</span>
                <span className="font-semibold text-white">
                  {order.company_name}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-white/50">Monto:</span>
                <span className="font-bold text-emerald-300 text-sm">
                  {order.currency}{" "}
                  {Number(order.amount).toLocaleString("es-CL")}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-white/50">Beneficiario:</span>
                <span className="font-semibold text-white text-right">
                  {order.beneficiary_name}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-white/50">RUT / ID:</span>
                <span className="text-white">{order.beneficiary_rut_dni}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-white/50">Banco / Cuenta:</span>
                <span className="text-white text-right">
                  {order.bank_name} · {order.account_number}
                </span>
              </div>
            </div>

            {reqSigners > 1 && (
              <div className="rounded-xl bg-white/[0.03] border border-white/10 p-3 text-xs space-y-1.5">
                <div className="flex items-center justify-between font-bold text-white/85">
                  <span className="inline-flex items-center gap-1.5">
                    <Users className="w-3.5 h-3.5 text-emerald-300" />
                    <span>Quórum Mancomunado Requerido</span>
                  </span>
                  <span className="font-mono text-emerald-300">
                    {curSigners} / {reqSigners} Firmas
                  </span>
                </div>
                {parsedLog.map((lg, idx) => (
                  <div
                    key={idx}
                    className="text-[11px] text-white/65 flex items-center justify-between"
                  >
                    <span>
                      {lg.name} ({lg.decision === "APPROVED" ? "Firmó" : "Bloqueó"})
                    </span>
                    <span className="font-mono text-[10px] text-white/45">
                      {lg.at}
                    </span>
                  </div>
                ))}
              </div>
            )}

            {actionErr && (
              <div className="p-3 rounded-xl bg-rose-500/15 border border-rose-400/35 text-xs text-rose-200">
                {actionErr}
              </div>
            )}

            {canStillSign ? (
              <div className="space-y-3">
                <textarea
                  rows={2}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Observación opcional de auditoría..."
                  className="w-full px-3 py-2 rounded-xl bg-white/[0.05] border border-white/15 text-xs text-white resize-none"
                />

                <button
                  type="button"
                  disabled={busy}
                  onClick={() => resolveOrder("APPROVE")}
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="w-full py-3 rounded-xl bg-emerald-300 hover:bg-white text-[#0A0E15] text-xs font-bold uppercase tracking-wider flex items-center justify-center gap-2 transition-colors"
                >
                  <Fingerprint className="w-4 h-4" />
                  <span>
                    {reqSigners > 1
                      ? `Estampar Firma (${curSigners + 1}/${reqSigners}) SHA-256`
                      : "Autorizar con Sello SHA-256"}
                  </span>
                </button>

                <button
                  type="button"
                  disabled={busy}
                  onClick={() => resolveOrder("REJECT")}
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="w-full py-2.5 rounded-xl bg-rose-500/20 hover:bg-rose-500 text-rose-200 hover:text-white border border-rose-400/40 text-xs font-bold uppercase tracking-wider flex items-center justify-center gap-2 transition-colors"
                >
                  <XCircle className="w-4 h-4" />
                  <span>Bloquear por Sospecha de Fraude</span>
                </button>
              </div>
            ) : (
              <div
                className={`p-4 rounded-xl border text-xs space-y-2 ${
                  order.status === "APPROVED"
                    ? "bg-emerald-400/15 border-emerald-400/35 text-emerald-200"
                    : order.status === "PARTIAL_APPROVAL"
                    ? "bg-sky-400/15 border-sky-400/35 text-sky-200"
                    : "bg-rose-500/15 border-rose-400/35 text-rose-200"
                }`}
              >
                <div className="flex items-center gap-2 font-bold text-sm">
                  <CheckCircle2 className="w-4 h-4" />
                  <span>
                    Estado:{" "}
                    {order.status === "APPROVED"
                      ? "AUTORIZADA (QUÓRUM COMPLETO)"
                      : order.status === "PARTIAL_APPROVAL"
                      ? `FIRMA REGISTRADA (${curSigners}/${reqSigners}) — PENDIENTE 2DO APODERADO`
                      : order.status === "EXPIRED"
                      ? "ENLACE EXPIRADO (VENTANA 48H VENCIDA)"
                      : "BLOQUEADA PREVENTIVAMENTE"}
                  </span>
                </div>
                {order.cryptographic_signature && (
                  <div className="font-mono text-[10px] break-all text-white/70">
                    SHA-256: {order.cryptographic_signature}
                  </div>
                )}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
