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
} from "lucide-react";

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
  status: string;
  security_challenge: string;
  cryptographic_signature: string | null;
  approved_at: string | null;
}

export default function ShieldVerifyPage() {
  const [token, setToken] = useState<string>("");
  const [loading, setLoading] = useState<boolean>(true);
  const [order, setOrder] = useState<VerifyOrder | null>(null);
  const [err, setErr] = useState<string | null>(null);
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
          cryptographic_signature: d.signature,
        });
      } else {
        setErr(d?.error || "Error al procesar decisión.");
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <div
      className="min-h-screen flex items-center justify-center bg-[#080B11] text-white p-4"
      style={{ fontFamily: "var(--font-inter), sans-serif" }}
    >
      <div className="w-full max-w-md rounded-2xl bg-[#0D121C] border border-white/15 p-6 shadow-2xl space-y-5">
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
                <span className="font-semibold text-white">
                  {order.beneficiary_name}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-white/50">RUT / ID:</span>
                <span className="text-white">{order.beneficiary_rut_dni}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-white/50">Banco / Cuenta:</span>
                <span className="text-white">
                  {order.bank_name} · {order.account_number}
                </span>
              </div>
            </div>

            {order.status === "PENDING_APPROVAL" ? (
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
                  <span>Autorizar con Sello SHA-256</span>
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
                    : "bg-rose-500/15 border-rose-400/35 text-rose-200"
                }`}
              >
                <div className="flex items-center gap-2 font-bold text-sm">
                  <CheckCircle2 className="w-4 h-4" />
                  <span>
                    Estado:{" "}
                    {order.status === "APPROVED"
                      ? "AUTORIZADA"
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
