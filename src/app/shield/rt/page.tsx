"use client";

import React, { useEffect } from "react";
import Link from "next/link";
import { ShieldAlert, CheckCircle2, Lock, Eye } from "lucide-react";

export default function ShieldRedTeamTrainingPage() {
  useEffect(() => {
    if (typeof window === "undefined") return;
    const t = new URLSearchParams(window.location.search).get("t") || "demo";
    fetch(`/api/shield/client_api.php?action=rt_telemetry&t=${encodeURIComponent(t)}`).catch(
      () => {}
    );
  }, []);

  return (
    <div
      className="min-h-screen flex items-center justify-center bg-[#080B11] text-white p-4 relative overflow-hidden"
      style={{ fontFamily: "var(--font-inter), sans-serif" }}
    >
      <div
        className="pointer-events-none absolute inset-0"
        style={{
          background:
            "radial-gradient(circle at 50% 18%, rgba(245, 158, 11, 0.14), transparent 55%)",
        }}
      />

      <div className="relative z-10 w-full max-w-xl rounded-2xl bg-[#0D121C] border border-amber-400/30 p-7 sm:p-8 shadow-2xl space-y-5">
        <div className="flex items-center justify-between">
          <span className="px-3 py-1 rounded-full bg-amber-400/15 border border-amber-400/35 text-[10px] font-bold uppercase tracking-widest text-amber-200">
            Entrenamiento Ético Red Teaming IA
          </span>
          <ShieldAlert className="w-6 h-6 text-amber-300" />
        </div>

        <div>
          <h1
            style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
            className="text-2xl font-bold text-white"
          >
            Detente un segundo: este fue un ejercicio controlado
          </h1>
          <p className="text-xs sm:text-sm text-white/65 mt-2 leading-relaxed">
            La comunicación con la que acabas de interactuar forma parte de un
            entrenamiento preventivo autorizado de{" "}
            <strong className="text-white">Keystone DeepShield</strong> frente a
            ingeniería social asistida por IA, fraude BEC y clonación de voz.
          </p>
        </div>

        <div className="rounded-xl bg-[#080B11] border border-white/10 p-5 space-y-3 text-xs">
          <div className="font-bold uppercase tracking-wider text-emerald-300 flex items-center gap-2">
            <Eye className="w-4 h-4" />
            <span>3 Señales Forenses para Detectar el Ataque Real</span>
          </div>
          <ul className="space-y-2.5 text-white/75 leading-relaxed">
            <li>
              <strong className="text-white">
                1. Urgencia + Aislamiento Telefónico:
              </strong>{" "}
              El atacante simula estar en directorio o viaje para impedir que
              confirmes por un canal independiente.
            </li>
            <li>
              <strong className="text-white">
                2. Cambio de Cuenta Corriente por Correo:
              </strong>{" "}
              Jamás modifiques datos bancarios de un proveedor basándote solo en
              un email o PDF adjunto.
            </li>
            <li>
              <strong className="text-white">
                3. Verificación Fuera de Banda (OOB):
              </strong>{" "}
              Exige siempre el desafío criptográfico Anti-Voz antes de liberar
              fondos críticos.
            </li>
          </ul>
        </div>

        <div className="p-3.5 rounded-xl bg-emerald-400/10 border border-emerald-400/25 flex items-center gap-3 text-xs text-emerald-200">
          <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />
          <span>
            Tu dispositivo y credenciales están 100% seguros. Ningún dato
            confidencial fue capturado durante esta simulación.
          </span>
        </div>

        <div className="pt-2 flex items-center justify-between text-[11px] text-white/45">
          <span className="inline-flex items-center gap-1.5">
            <Lock className="w-3.5 h-3.5" />
            <span>Keystone Advisors Global · Cyber Resilience</span>
          </span>
          <Link href="/trust/" className="text-emerald-300 hover:underline">
            Ver Trust Center →
          </Link>
        </div>
      </div>
    </div>
  );
}
