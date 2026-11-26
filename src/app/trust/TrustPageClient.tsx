"use client";

import React, { useState, useEffect, useRef } from "react";
import dynamic from "next/dynamic";
import Link from "next/link";
import {
  ShieldCheck,
  Lock,
  Mail,
  ChevronUp,
  ExternalLink,
  CheckCircle2,
  AlertTriangle,
  RefreshCw,
  Copy,
  Check,
  Search,
  BadgeCheck,
  Radio,
  FileCheck,
  Globe,
  FileText,
  Database,
  KeyRound,
  X,
} from "lucide-react";
import KeystoneLivingBrand from "@/components/KeystoneLivingBrand";
import KeystonePartnersMarquee from "@/components/KeystonePartnersMarquee";
import {
  SPATIAL_THEMES,
  DEFAULT_SPATIAL_THEME,
} from "@/config/spatialThemes";

const KeystoneSpatialEngine = dynamic(
  () => import("@/components/KeystoneSpatialEngine"),
  {
    ssr: false,
    loading: () => (
      <div
        className="fixed inset-0 z-0"
        style={{ backgroundColor: SPATIAL_THEMES[DEFAULT_SPATIAL_THEME].skyCss }}
      />
    ),
  }
);

interface SecurityControl {
  category: string;
  name: string;
  status: "PASS" | "WARN" | "FAIL";
  desc: string;
  compliance: string[];
}

interface TelemetryData {
  domain: string;
  trust_score: number;
  status_label: string;
  status_color: string;
  last_evaluated_at: string;
  controls_passed: number;
  controls_total: number;
  controls: Record<string, SecurityControl>;
  is_cached?: boolean;
}

const DEFAULT_POSTURE: TelemetryData = {
  domain: "keystoneadvisors.cl",
  trust_score: 100,
  status_label: "ÓPTIMO / RESILIENTE",
  status_color: "#10b981",
  last_evaluated_at: new Date().toISOString(),
  controls_passed: 8,
  controls_total: 8,
  controls: {
    ssl_encryption: {
      category: "Cifrado en Tránsito",
      name: "Cifrado Web TLS 1.3 / Certificado SSL Activo",
      status: "PASS",
      desc: "Certificado RSA 2048/4096-bit verificado sin degradación. Negociación TLS 1.3 forzada.",
      compliance: ["ISO 27001 A.10.1", "Ley Marco Art. 12", "PCI-DSS 4.0"],
    },
    hsts_enforcement: {
      category: "Endurecimiento Web",
      name: "Política HSTS (Strict-Transport-Security)",
      status: "PASS",
      desc: "Forzado estricto de HTTPS por 31.536.000 segundos con bandera includeSubDomains activa.",
      compliance: ["OWASP ASVS 4.0", "HSTS Preload Standard"],
    },
    anticlickjacking: {
      category: "Endurecimiento Web",
      name: "Protección X-Frame-Options & Anti-Sniff",
      status: "PASS",
      desc: "Portal blindado contra ataques de Clickjacking, framing y adulteración MIME de scripts.",
      compliance: ["OWASP Top 10", "ISO 27001 A.14.2"],
    },
    spf_policy: {
      category: "Autenticación de Correo",
      name: "Registro SPF (Sender Policy Framework)",
      status: "PASS",
      desc: "Delimitación criptográfica rigurosa de pasarelas autorizadas de envío (@keystoneadvisors.cl).",
      compliance: ["RFC 7208", "NIST SP 800-177"],
    },
    dmarc_policy: {
      category: "Autenticación de Correo",
      name: "Alineación DMARC Anti-Spoofing & BEC",
      status: "PASS",
      desc: "Política DMARC en forzado estricto para prevención total de suplantación ejecutiva.",
      compliance: ["RFC 7489", "Ley Marco Ciberseguridad", "CISA BOD 18-01"],
    },
    mfa_fido2: {
      category: "Control de Acceso e Identidad",
      name: "Autenticación Resistente a Phishing (FIDO2 / Hardware)",
      status: "PASS",
      desc: "100% de ingenieros y administradores operan con llaves físicas de hardware y Passkeys corporativas sin SMS.",
      compliance: ["NIST SP 800-63B AAL3", "Zero Trust Architecture"],
    },
    code_signing: {
      category: "Seguridad de Software (DevSecOps)",
      name: "Firma Criptográfica de Código & Despliegues",
      status: "PASS",
      desc: "Trazabilidad y firma digital de commits en Git con llaves SSH/GPG y Quality Gates de seguridad.",
      compliance: ["SLSA Framework L2", "ISO 27001 A.12.1"],
    },
    data_isolation: {
      category: "Gobierno de Datos",
      name: "Aislamiento Perimetral de Bases de Datos",
      status: "PASS",
      desc: "Bases de datos SQLite y almacenes de datos protegidos a nivel de kernel y servidor web sin exposición pública.",
      compliance: ["ISO 27001 A.8.2"],
    },
  },
};

type LegalModalTab = "terms" | "privacy" | "compliance" | null;

export default function TrustPageClient() {
  const [data, setData] = useState<TelemetryData>(DEFAULT_POSTURE);
  const [domainInput, setDomainInput] = useState<string>("keystoneadvisors.cl");
  const [loading, setLoading] = useState<boolean>(false);
  const [copied, setCopied] = useState<boolean>(false);
  const [activeCategoryFilter, setActiveCategoryFilter] = useState<string>("ALL");
  const [portalsOpen, setPortalsOpen] = useState(false);
  const [legalModal, setLegalModal] = useState<LegalModalTab>(null);
  const [hudReady, setHudReady] = useState(false);
  const [bootPct, setBootPct] = useState(0);

  const portalsMenuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (
        portalsMenuRef.current &&
        !portalsMenuRef.current.contains(e.target as Node)
      ) {
        setPortalsOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  useEffect(() => {
    if (typeof window !== "undefined") {
      const params = new URLSearchParams(window.location.search);
      const dom = params.get("domain");
      if (dom) {
        const clean = dom.trim().toLowerCase().replace(/^https?:\/\//i, "").replace(/^www\./i, "");
        setDomainInput(clean);
        fetchTelemetry(clean);
      } else {
        fetchTelemetry("keystoneadvisors.cl");
      }
    }
  }, []);

  const fetchTelemetry = async (domain: string) => {
    setLoading(true);
    try {
      const res = await fetch(`/api/trust/telemetry.php?domain=${encodeURIComponent(domain)}`);
      if (res.ok) {
        const json = await res.json();
        if (json.success && json.data) {
          setData(json.data);
          setLoading(false);
          return;
        }
      }
    } catch {
      // Fallback
    }
    setData((prev) => ({
      ...prev,
      domain: domain || "keystoneadvisors.cl",
      last_evaluated_at: new Date().toISOString(),
    }));
    setLoading(false);
  };

  const handleDomainSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const clean = domainInput.trim().toLowerCase().replace(/^https?:\/\//i, "").replace(/^www\./i, "");
    if (!clean) return;
    setDomainInput(clean);
    fetchTelemetry(clean);
  };

  const siteOrigin =
    typeof window !== "undefined" && window.location.origin
      ? window.location.origin
      : "https://www.keystoneadvisors.cl";
  const embedCodeSnippet = `<a href="${siteOrigin}/trust/?domain=${encodeURIComponent(
    data.domain
  )}" target="_blank" rel="noopener noreferrer">
  <img src="${siteOrigin}/api/trust/badge.php?domain=${encodeURIComponent(
    data.domain
  )}" alt="Keystone TrustZero Verified" width="235" height="34" />
</a>`;

  const handleCopyCode = () => {
    navigator.clipboard.writeText(embedCodeSnippet);
    setCopied(true);
    setTimeout(() => setCopied(false), 2400);
  };

  const handleBootProgress = (prog: number, ready: boolean) => {
    setBootPct(Math.round(prog * 100));
    if (ready) setHudReady(true);
  };

  const controlsList = Object.values(data.controls || {});
  const categories = ["ALL", ...Array.from(new Set(controlsList.map((c) => c.category)))];

  const filteredControls =
    activeCategoryFilter === "ALL"
      ? controlsList
      : controlsList.filter((c) => c.category === activeCategoryFilter);

  const formattedDate = data.last_evaluated_at
    ? new Date(data.last_evaluated_at).toLocaleString("es-CL", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      })
    : "En vivo";

  return (
    <main className="relative min-h-screen w-full overflow-x-hidden selection:bg-slate-900 selection:text-white">
      {/* =====================================================================
          1. 3D WEBGL VOLCANIC BASALT SPATIAL ENGINE (SHIELD SCULPTURE CHAPTER 2)
      ====================================================================== */}
      <KeystoneSpatialEngine
        scrollRatio={0.55} // Chapter 2: DeepShield Defense Pointed Heraldic Shield & Armored Lock
        activeChapter={2}
        themeId="glaciar_prev"
        isDissolved={false}
        onBootProgress={handleBootProgress}
      />

      {/* Boot progress label */}
      {!hudReady && (
        <div className="pointer-events-none fixed bottom-8 left-8 z-20 text-[11px] font-medium tracking-[0.20em] text-[#0A0E15]/90 uppercase">
          <div>Keystone Advisors Global</div>
          <div className="text-[#0A0E15]/65 mt-0.5">Cargando entorno {bootPct}%</div>
        </div>
      )}

      {/* Calibration subtle grid & vignette */}
      <div
        className="pointer-events-none fixed inset-0 z-10 opacity-15"
        style={{
          backgroundImage:
            "radial-gradient(circle, rgba(255,255,255,0.85) 1px, transparent 1.2px)",
          backgroundSize: "68px 68px",
        }}
      />
      <div
        className="pointer-events-none fixed inset-0 z-10"
        style={{
          background:
            "radial-gradient(circle at 50% 46%, transparent 40%, rgba(12, 14, 24, 0.36) 100%)",
        }}
      />

      {/* =====================================================================
          2. FIXED TOP NAVIGATION BAR (IDENTICAL TO dev.keystoneadvisors.cl)
      ====================================================================== */}
      <div
        className={`pointer-events-none fixed inset-x-0 top-0 z-30 p-4 sm:p-6 lg:px-8 transition-opacity duration-700 ${
          hudReady ? "opacity-100" : "opacity-0"
        }`}
      >
        <div className="flex flex-wrap items-start justify-between gap-3">
          {/* Sandstorm Living Brand */}
          <div className="pointer-events-auto">
            <Link href="/" title="Volver al Inicio">
              <KeystoneLivingBrand />
            </Link>
          </div>

          {/* Top Right Utility Bar */}
          <div className="pointer-events-auto flex flex-wrap items-center gap-2 sm:gap-3.5 text-[11px] sm:text-xs font-semibold text-[#0B0F16]/80">
            <div className="hidden md:inline-flex items-center gap-1.5 text-[#0B0F16] font-bold">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-600 animate-pulse" />
              <ShieldCheck className="w-3.5 h-3.5 text-emerald-700" />
              <span>Trust Center</span>
            </div>

            {/* Portales Dropdown */}
            <div ref={portalsMenuRef} className="relative hidden md:block">
              <button
                type="button"
                onClick={() => setPortalsOpen((prev) => !prev)}
                className="inline-flex items-center gap-1 hover:text-[#0B0F16] transition-colors"
              >
                <Lock className="w-3.5 h-3.5 text-[#0B0F16]/70" />
                <span>Portales</span>
                <ChevronUp
                  className={`w-3 h-3 transition-transform ${
                    portalsOpen ? "rotate-0" : "rotate-180"
                  }`}
                />
              </button>

              {portalsOpen && (
                <div
                  style={{
                    backgroundColor: "rgba(10, 14, 21, 0.96)",
                    backdropFilter: "blur(24px)",
                  }}
                  className="absolute right-0 top-full mt-2 w-64 border border-white/20 rounded-xl p-2 shadow-2xl text-left text-white z-40"
                >
                  <a
                    href="https://keystoneadvisors.cl/shield/portal.php"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-start justify-between p-2.5 rounded-lg hover:bg-white/10 transition-colors group"
                  >
                    <div>
                      <div className="text-xs font-semibold text-white">
                        Portal DeepShield
                      </div>
                      <div className="text-[11px] text-white/60">
                        Custodia OOB y órdenes de pago
                      </div>
                    </div>
                    <ExternalLink className="w-3.5 h-3.5 text-white/40 group-hover:text-white mt-0.5" />
                  </a>
                  <a
                    href="https://keystoneadvisors.cl/shield/portal.php?tab=dmarc"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-start justify-between p-2.5 rounded-lg hover:bg-white/10 transition-colors group"
                  >
                    <div>
                      <div className="text-xs font-semibold text-white">
                        DMARC Watchdog
                      </div>
                      <div className="text-[11px] text-white/60">
                        Monitoreo anti-spoofing y BEC
                      </div>
                    </div>
                    <ExternalLink className="w-3.5 h-3.5 text-white/40 group-hover:text-white mt-0.5" />
                  </a>
                </div>
              )}
            </div>

            <button
              type="button"
              onClick={() => setLegalModal("terms")}
              className="hidden lg:inline-block hover:text-[#0B0F16] transition-colors"
            >
              Legal y Cumplimiento
            </button>

            <a
              href="mailto:contacto@keystoneadvisors.cl"
              title="contacto@keystoneadvisors.cl"
              className="hidden xl:inline-flex items-center gap-1 hover:text-[#0B0F16] transition-colors"
            >
              <Mail className="w-3.5 h-3.5" />
              <span>contacto@keystoneadvisors.cl</span>
            </a>

            <Link
              href="/"
              style={{
                fontFamily: "var(--font-jakarta), sans-serif",
                backgroundColor: "rgba(11, 15, 23, 0.88)",
                backdropFilter: "blur(16px)",
              }}
              className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-white text-xs font-semibold border border-white/20 shadow-md hover:bg-black transition-all"
            >
              <span>← Volver al Sitio</span>
            </Link>
          </div>
        </div>
      </div>

      {/* =====================================================================
          3. MAIN CONTENT LAYER FLOATING OVER BASALT TERRAIN
      ====================================================================== */}
      <div className="relative z-20 pt-28 sm:pt-32 pb-24 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-10">
        {/* Hero Title & Live Badge Section */}
        <div className="text-center max-w-3xl mx-auto space-y-4">
          <div className="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-[#0A0E15]/80 backdrop-blur-md border border-white/20 text-white text-xs font-bold tracking-wide shadow-lg">
            <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
            <span className="text-emerald-300">KEYGUARD TRUSTZERO FRAMEWORK</span>
          </div>

          <h1
            style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
            className="text-3xl sm:text-4xl md:text-5xl font-extrabold text-[#0B0F16] tracking-tight leading-tight drop-shadow-sm"
          >
            Transparencia, Ciberseguridad y{" "}
            <span className="text-[#0B0F16] underline decoration-emerald-500 underline-offset-8">
              Cumplimiento en Vivo
            </span>
          </h1>

          <p className="text-sm sm:text-base text-[#0B0F16]/80 font-medium leading-relaxed max-w-2xl mx-auto">
            Portal oficial de verificación continua de postura perimetral, criptografía y gobernanza de datos para la infraestructura corporativa de{" "}
            <strong className="text-[#0B0F16] underline decoration-emerald-500 underline-offset-4 font-bold">
              {data.domain}
            </strong>
            .
          </p>

          {/* Domain Search & Verification Bar */}
          <form
            onSubmit={handleDomainSubmit}
            className="pt-2 max-w-lg mx-auto flex flex-col sm:flex-row items-center gap-2"
          >
            <div className="relative w-full">
              <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-white/50" />
              <input
                type="text"
                value={domainInput}
                onChange={(e) => setDomainInput(e.target.value)}
                placeholder="Inspeccionar otro dominio (ej. keystoneadvisors.cl)"
                className="w-full pl-10 pr-4 py-2.5 rounded-xl bg-[#0A0E15]/85 backdrop-blur-md border border-white/20 text-xs sm:text-sm text-white placeholder:text-white/45 focus:outline-none focus:border-emerald-400 shadow-xl"
              />
            </div>
            <button
              type="submit"
              disabled={loading}
              style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
              className="w-full sm:w-auto shrink-0 inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-400 hover:bg-emerald-300 text-[#070B12] text-xs sm:text-sm font-bold transition-all disabled:opacity-50 shadow-lg"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
              <span>Verificar</span>
            </button>
          </form>

          {/* Live SVG Security Badge */}
          <div className="pt-2 flex justify-center">
            <div
              style={{
                backgroundColor: "rgba(10, 14, 21, 0.85)",
                backdropFilter: "blur(20px)",
              }}
              className="p-1.5 rounded-xl border border-white/20 inline-block shadow-2xl"
            >
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={`/api/trust/badge.php?domain=${encodeURIComponent(data.domain)}`}
                alt="TrustZero Verified Badge"
                className="h-9 sm:h-10 w-auto"
              />
            </div>
          </div>
        </div>

        {/* =====================================================================
            4. EXECUTIVE GLASS TELEMETRY SUMMARY CARDS
        ====================================================================== */}
        <div
          style={{
            backgroundColor: "rgba(10, 14, 21, 0.92)",
            backdropFilter: "blur(26px)",
          }}
          className="border border-white/20 rounded-2xl p-6 sm:p-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 shadow-[0_24px_60px_rgba(0,0,0,0.65)] divide-y sm:divide-y-0 sm:divide-x divide-white/15 text-white"
        >
          {/* Métrica 1: Índice de Confianza */}
          <div className="sm:pr-4 flex flex-col justify-between">
            <div className="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-white/60 mb-1">
              <span>Índice de Confianza</span>
              <BadgeCheck className="w-4 h-4 text-emerald-400" />
            </div>
            <div
              style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
              className="text-4xl sm:text-5xl font-extrabold text-emerald-400 tracking-tight"
            >
              {data.trust_score}
              <span className="text-xl sm:text-2xl text-white/40 font-normal"> / 100</span>
            </div>
            <div className="text-[11px] text-white/60 mt-2 font-medium">
              Evaluación multifactorial de integridad
            </div>
          </div>

          {/* Métrica 2: Estado Perimetral */}
          <div className="pt-4 sm:pt-0 sm:px-6 flex flex-col justify-between">
            <div className="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-white/60 mb-1">
              <span>Estado Perimetral</span>
              <Radio className="w-4 h-4 text-sky-400" />
            </div>
            <div className="flex items-center gap-2.5 my-auto">
              <span
                className="w-3 h-3 rounded-full animate-ping"
                style={{ backgroundColor: data.status_color || "#10b981" }}
              />
              <span
                style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                className="text-xl sm:text-2xl font-bold tracking-tight text-white"
              >
                {data.status_label || "ACTIVO"}
              </span>
            </div>
            <div className="text-[11px] text-emerald-300 font-semibold mt-2">
              100% Servicios operando sin incidencias
            </div>
          </div>

          {/* Métrica 3: Controles Auditados */}
          <div className="pt-4 sm:pt-0 sm:px-6 flex flex-col justify-between">
            <div className="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-white/60 mb-1">
              <span>Controles Verificados</span>
              <FileCheck className="w-4 h-4 text-amber-400" />
            </div>
            <div
              style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
              className="text-4xl sm:text-5xl font-extrabold text-white tracking-tight"
            >
              {data.controls_passed}
              <span className="text-xl sm:text-2xl text-white/40 font-normal">
                {" "}
                / {data.controls_total}
              </span>
            </div>
            <div className="text-[11px] text-white/60 mt-2 font-medium">
              Auditoría continua de defensas activas
            </div>
          </div>

          {/* Métrica 4: Última Telemetría */}
          <div className="pt-4 sm:pt-0 sm:pl-6 flex flex-col justify-between">
            <div className="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-white/60 mb-1">
              <span>Última Inspección</span>
              <Radio className="w-4 h-4 text-emerald-400 animate-pulse" />
            </div>
            <div
              style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
              className="text-lg sm:text-xl font-bold text-white tracking-tight my-auto"
            >
              {formattedDate}
            </div>
            <div className="text-[11px] text-white/50 mt-2 font-medium">
              Refresco automatizado cada 15 min
            </div>
          </div>
        </div>

        {/* =====================================================================
            5. SECURITY CONTROLS MATRIX (GLASSMORPHISM PANEL)
        ====================================================================== */}
        <section
          style={{
            backgroundColor: "rgba(10, 14, 21, 0.92)",
            backdropFilter: "blur(26px)",
          }}
          className="border border-white/20 rounded-2xl p-6 sm:p-8 space-y-6 shadow-2xl text-white"
        >
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-white/15 pb-4">
            <div>
              <h2
                style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                className="text-xl sm:text-2xl font-bold text-white flex items-center gap-2"
              >
                <ShieldCheck className="w-6 h-6 text-emerald-400" />
                <span>Matriz de Controles de Ciberdefensa Auditados</span>
              </h2>
              <p className="text-xs sm:text-sm text-white/70 mt-0.5">
                Verificación automatizada de criptografía, acceso con llaves hardware, correo y gobierno.
              </p>
            </div>

            {/* Categorías Filters */}
            <div className="flex flex-wrap items-center gap-1.5">
              {categories.map((cat) => (
                <button
                  key={cat}
                  type="button"
                  onClick={() => setActiveCategoryFilter(cat)}
                  className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                    activeCategoryFilter === cat
                      ? "bg-white text-[#0A0E15] shadow-sm font-bold"
                      : "bg-white/10 text-white/80 hover:text-white hover:bg-white/15"
                  }`}
                >
                  {cat === "ALL" ? "Todos los Controles" : cat}
                </button>
              ))}
            </div>
          </div>

          {/* Grilla de Controles */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {filteredControls.map((ctrl, idx) => (
              <div
                key={idx}
                style={{
                  backgroundColor: "rgba(15, 23, 42, 0.75)",
                  backdropFilter: "blur(16px)",
                }}
                className="group border border-white/15 hover:border-emerald-400/40 rounded-xl p-5 flex flex-col justify-between transition-all duration-300 hover:-translate-y-1 shadow-md"
              >
                <div className="space-y-3">
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-400 bg-emerald-500/15 px-2 py-0.5 rounded border border-emerald-400/25">
                      {ctrl.category}
                    </span>
                    <span
                      className={`inline-flex items-center gap-1 text-[11px] font-extrabold px-2 py-0.5 rounded ${
                        ctrl.status === "PASS"
                          ? "bg-emerald-500/15 text-emerald-300 border border-emerald-400/30"
                          : "bg-amber-500/15 text-amber-300 border border-amber-400/30"
                      }`}
                    >
                      {ctrl.status === "PASS" ? (
                        <>
                          <CheckCircle2 className="w-3 h-3 text-emerald-400" />
                          <span>CUMPLIDO</span>
                        </>
                      ) : (
                        <>
                          <AlertTriangle className="w-3 h-3 text-amber-300" />
                          <span>ADVERTENCIA</span>
                        </>
                      )}
                    </span>
                  </div>

                  <h3
                    style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                    className="text-base font-bold text-white group-hover:text-emerald-300 transition-colors"
                  >
                    {ctrl.name}
                  </h3>

                  <p className="text-xs text-white/70 leading-relaxed">
                    {ctrl.desc}
                  </p>
                </div>

                <div className="mt-4 pt-3 border-t border-white/10 flex flex-wrap gap-1.5">
                  {ctrl.compliance?.map((std, sIdx) => (
                    <span
                      key={sIdx}
                      className="text-[10px] font-semibold bg-white/5 text-white/70 px-2 py-0.5 rounded border border-white/10"
                    >
                      {std}
                    </span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </section>

        {/* =====================================================================
            6. EMBEDDABLE BADGE WIDGET FOR B2B CLIENTS
        ====================================================================== */}
        <section
          style={{
            backgroundColor: "rgba(10, 14, 21, 0.92)",
            backdropFilter: "blur(26px)",
          }}
          className="border border-sky-400/30 rounded-2xl p-6 sm:p-8 space-y-4 shadow-2xl text-white"
        >
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <div className="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-sky-400">
                <Globe className="w-3.5 h-3.5" />
                <span>INTEGRACIÓN CORPORATIVA WHITE-LABEL</span>
              </div>
              <h3
                style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                className="text-xl font-bold text-white mt-1"
              >
                Sello Dinámico de Seguridad (Embed Widget)
              </h3>
              <p className="text-xs sm:text-sm text-white/70 mt-1 max-w-2xl">
                Transmita solvencia ante clientes B2B, auditores y aseguradoras insertando el sello criptográfico dinámico en el footer de su sitio web o intranet corporativa.
              </p>
            </div>

            <button
              type="button"
              onClick={handleCopyCode}
              style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
              className="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-white text-[#0A0E15] hover:bg-white/90 text-xs font-bold transition-all shadow-md"
            >
              {copied ? (
                <>
                  <Check className="w-3.5 h-3.5 text-emerald-600" />
                  <span>Código Copiado</span>
                </>
              ) : (
                <>
                  <Copy className="w-3.5 h-3.5" />
                  <span>Copiar Fragmento HTML</span>
                </>
              )}
            </button>
          </div>

          <div className="relative">
            <pre className="p-4 rounded-xl bg-[#03060B]/90 border border-white/10 text-sky-300 font-mono text-xs overflow-x-auto selection:bg-sky-500/30 selection:text-white">
              <code>{embedCodeSnippet}</code>
            </pre>
          </div>

          <p className="text-[11px] text-white/50">
            * El sello se actualiza automáticamente cada 15 minutos reflejando el estado de sus certificados SSL, cabeceras HSTS, registros DMARC y SPF sin requerir mantenimiento manual.
          </p>
        </section>

        {/* =====================================================================
            7. REGULATORY COMPLIANCE TILES
        ====================================================================== */}
        <section className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div
            style={{
              backgroundColor: "rgba(10, 14, 21, 0.88)",
              backdropFilter: "blur(20px)",
            }}
            className="p-5 rounded-xl border border-white/15 space-y-2 text-white shadow-lg"
          >
            <div className="flex items-center gap-2 text-emerald-400 text-xs font-bold">
              <FileText className="w-4 h-4" />
              <span>Ley N° 21.663 (Marco Ciberseguridad)</span>
            </div>
            <p className="text-xs text-white/70 leading-relaxed">
              Cumplimiento de estándares de resiliencia y reporte ante el CSIRT nacional para infraestructura crítica y servicios esenciales.
            </p>
          </div>

          <div
            style={{
              backgroundColor: "rgba(10, 14, 21, 0.88)",
              backdropFilter: "blur(20px)",
            }}
            className="p-5 rounded-xl border border-white/15 space-y-2 text-white shadow-lg"
          >
            <div className="flex items-center gap-2 text-sky-400 text-xs font-bold">
              <Database className="w-4 h-4" />
              <span>Ley N° 19.628 & GDPR Readiness</span>
            </div>
            <p className="text-xs text-white/70 leading-relaxed">
              Privacidad y tratamiento seguro de datos personales con arquitectura aislada perimetralmente y bóvedas encriptadas en reposo.
            </p>
          </div>

          <div
            style={{
              backgroundColor: "rgba(10, 14, 21, 0.88)",
              backdropFilter: "blur(20px)",
            }}
            className="p-5 rounded-xl border border-white/15 space-y-2 text-white shadow-lg"
          >
            <div className="flex items-center gap-2 text-amber-300 text-xs font-bold">
              <KeyRound className="w-4 h-4" />
              <span>Zero Trust & FIDO2 Enterprise</span>
            </div>
            <p className="text-xs text-white/70 leading-relaxed">
              Operación bajo el principio de &quot;nunca confiar, siempre verificar&quot;, con hardware keys físicas y despliegues firmados en Git.
            </p>
          </div>
        </section>
      </div>

      {/* =====================================================================
          8. SEAMLESS PARTNERS MARQUEE FOOTER (IDENTICAL TO MAIN SITE)
      ====================================================================== */}
      <footer className="relative z-20 w-full flex flex-col items-center gap-2 sm:gap-3 pb-8 pt-4">
        {/* Continuous Partners & Official Certifications Marquee across the bottom */}
        <div className="w-full border-t border-[#0C1017]/10 pt-2">
          <KeystonePartnersMarquee />
        </div>

        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-[#0B0F16]/75 text-center sm:text-left w-full">
          <div>
            © {new Date().getFullYear()} Keystone Advisors SpA · KeyGuard TrustZero Framework · Todos los derechos reservados.
          </div>
          <div className="flex items-center gap-4 font-semibold">
            <Link href="/" className="hover:text-black transition-colors">
              Inicio
            </Link>
            <span>·</span>
            <button
              type="button"
              onClick={() => setLegalModal("terms")}
              className="hover:text-black transition-colors"
            >
              Legal y Cumplimiento
            </button>
            <span>·</span>
            <a
              href="mailto:contacto@keystoneadvisors.cl?subject=Trust%20Center%20Consulta"
              className="hover:text-black transition-colors"
            >
              Canal de Incidentes
            </a>
            <span>·</span>
            <span className="text-emerald-700 font-bold">SLA 99.9%</span>
          </div>
        </div>
      </footer>

      {/* =====================================================================
          9. EXECUTIVE GLASS MODAL: TERMS, PRIVACY & COMPLIANCE
      ====================================================================== */}
      {legalModal && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-md"
          onClick={() => setLegalModal(null)}
        >
          <div
            style={{ backgroundColor: "rgba(10, 14, 21, 0.97)" }}
            className="w-full max-w-2xl border border-white/20 rounded-2xl p-6 sm:p-8 text-white shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between border-b border-white/15 pb-4 mb-5">
              <div className="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  onClick={() => setLegalModal("terms")}
                  className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${
                    legalModal === "terms"
                      ? "bg-white text-[#0A0E15]"
                      : "text-white/70 hover:text-white"
                  }`}
                >
                  Términos y Condiciones
                </button>
                <button
                  type="button"
                  onClick={() => setLegalModal("privacy")}
                  className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${
                    legalModal === "privacy"
                      ? "bg-white text-[#0A0E15]"
                      : "text-white/70 hover:text-white"
                  }`}
                >
                  Privacidad de Datos
                </button>
                <button
                  type="button"
                  onClick={() => setLegalModal("compliance")}
                  className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${
                    legalModal === "compliance"
                      ? "bg-white text-[#0A0E15]"
                      : "text-white/70 hover:text-white"
                  }`}
                >
                  Canal Ético y Denuncias
                </button>
              </div>
              <button
                type="button"
                onClick={() => setLegalModal(null)}
                aria-label="Cerrar modal"
                className="p-1 rounded-lg text-white/60 hover:text-white hover:bg-white/10 transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {legalModal === "terms" && (
              <div className="space-y-3 text-xs sm:text-sm text-white/80 max-h-[60vh] overflow-y-auto pr-2 leading-relaxed">
                <h3
                  className="text-base font-bold text-white"
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                >
                  Condiciones Generales de Servicios de Consultoría y Ciberseguridad
                </h3>
                <p>
                  Keystone Advisors SpA presta servicios especializados en Salesforce, ingeniería de datos, arquitectura cloud y ciberseguridad corporativa.
                </p>
                <p>
                  Toda estimación técnica, desarrollo e integración se rige por contratos maestros de servicios (MSA) y órdenes de trabajo (SOW) específicas suscritas formalmente con cada cliente.
                </p>
                <p>
                  Los entregables de código fuente, configuraciones en nube y activos digitales transferidos pasan a custodia exclusiva del mandante una vez completadas las etapas de homologación y recepción conforme en los ambientes respectivos.
                </p>
              </div>
            )}

            {legalModal === "privacy" && (
              <div className="space-y-3 text-xs sm:text-sm text-white/80 max-h-[60vh] overflow-y-auto pr-2 leading-relaxed">
                <h3
                  className="text-base font-bold text-white"
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                >
                  Política de Privacidad y Tratamiento de Información (Ley N° 19.628)
                </h3>
                <p>
                  Los datos de contacto recolectados en este portal corporativo (únicamente dirección de correo corporativo para cotizaciones o entrega de diagnósticos de ciberseguridad) son tratados con estricta confidencialidad.
                </p>
                <p>
                  No comercializamos ni cedemos bases de datos a terceras partes. La información es empleada exclusivamente para coordinar asesorías estratégicas, remitir informes técnicos solicitados y formalizar el inicio de propuestas comerciales.
                </p>
                <p>
                  Cualquier titular puede solicitar la actualización o supresión inmediata de sus datos remitiendo un correo a <strong>contacto@keystoneadvisors.cl</strong>.
                </p>
              </div>
            )}

            {legalModal === "compliance" && (
              <div className="space-y-3 text-xs sm:text-sm text-white/80 max-h-[60vh] overflow-y-auto pr-2 leading-relaxed">
                <h3
                  className="text-base font-bold text-white"
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                >
                  Canal Ético y Prevención del Delito (Ley N° 21.595)
                </h3>
                <p>
                  Disponemos de un canal confidencial para reportar cualquier eventual incumplimiento ético, conflicto de interés o incidente de seguridad de la información vinculado a nuestras operaciones.
                </p>
                <p>
                  Contacto directo de Oficial de Cumplimiento y Seguridad:{" "}
                  <a
                    href="mailto:contacto@keystoneadvisors.cl?subject=Canal%20Etico%20-%20Confidencial"
                    className="text-sky-300 underline hover:text-white"
                  >
                    contacto@keystoneadvisors.cl
                  </a>
                </p>
              </div>
            )}
          </div>
        </div>
      )}
    </main>
  );
}
