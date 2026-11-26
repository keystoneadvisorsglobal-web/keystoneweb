"use client";

import React, { useState, useEffect, useCallback, useRef } from "react";
import dynamic from "next/dynamic";
import Link from "next/link";
import {
  ChevronLeft,
  ChevronRight,
  ShieldCheck,
  Lock,
  Mail,
  MessageCircle,
  ChevronUp,
  ExternalLink,
  X,
  ArrowRight,
  CheckCircle2,
  Sparkles,
  Loader2,
  Palette,
  Radar,
  AlertTriangle,
} from "lucide-react";
import KeystoneLivingBrandV2 from "@/components/v2/KeystoneLivingBrandV2";
import KeystoneSpatialTextNavV2 from "@/components/v2/KeystoneSpatialTextNavV2";
import KeystonePartnersMarqueeV2 from "@/components/v2/KeystonePartnersMarqueeV2";
import KeystoneLuminousTitleV2 from "@/components/v2/KeystoneLuminousTitleV2";
import type { SpatialMenuItem } from "@/components/KeystoneSpatialTextNav";
import {
  CYBER_THEMES_V2,
  type CyberThemeId,
} from "@/components/v2/KeystoneDigitalTunnelEngine";
import {
  DEFAULT_SITE_CONFIG,
  type PillarCatalog,
  type ServiceCardOffer,
  type PublicSiteConfig,
} from "@/config/defaultSiteCatalog";
import {
  enrichPillarForV2,
  V2_CARD_SPECS,
  V2_PILLAR_HEADERS,
} from "@/config/v2SalesCatalog";

const KeystoneDigitalTunnelEngine = dynamic(
  () => import("@/components/v2/KeystoneDigitalTunnelEngine"),
  {
    ssr: false,
    loading: () => (
      <div className="fixed inset-0 z-0 bg-[#020510]" />
    ),
  }
);

type LegalModalTab = "terms" | "privacy" | "compliance" | null;

interface BecFinding {
  severity: "critical" | "high" | "medium" | "low" | "ok";
  title: string;
  desc: string;
}

interface BecScanResult {
  domain: string;
  score: number;
  risk_level: string;
  spf: string;
  dmarc: string;
  mx: string;
  https_hsts: string;
  findings: BecFinding[];
  scanned_at: string;
}

interface SubmittedLeadState {
  email: string;
}

const CYBER_THEME_OPTIONS: Array<{
  id: CyberThemeId;
  swatch: string;
  label: string;
}> = [
  {
    id: "sapphire_core",
    swatch: "#0ea5e9",
    label: "Sapphire Quantum Tunnel",
  },
  {
    id: "cobalt_quantum",
    swatch: "#3b82f6",
    label: "Cobalt Deep Horizon",
  },
  {
    id: "arctic_cyber",
    swatch: "#22d3ee",
    label: "Arctic Cyber Light",
  },
];

export default function HomePageV2() {
  const [scrollRatio, setScrollRatio] = useState<number>(0);
  const [activeChapterIdx, setActiveChapterIdx] = useState<number>(0);
  const [hudReady, setHudReady] = useState<boolean>(false);
  const [bootPct, setBootPct] = useState<number>(0);

  // Live Admin Site Configuration & V2 Cyber Light Preset
  const [siteConfig, setSiteConfig] = useState<PublicSiteConfig>(DEFAULT_SITE_CONFIG);
  const [cyberThemeId, setCyberThemeId] = useState<CyberThemeId>("sapphire_core");
  const [themePickerOpen, setThemePickerOpen] = useState<boolean>(false);

  // Click-to-Dissolve & Interactive Micro-Conversion States
  const [isDissolved, setIsDissolved] = useState<boolean>(false);
  const [isHoveringSculpture, setIsHoveringSculpture] = useState<boolean>(false);
  const [expandedCardId, setExpandedCardId] = useState<string | null>(null);
  const [emailInput, setEmailInput] = useState<string>("");
  const [submittingCardId, setSubmittingCardId] = useState<string | null>(null);
  const [cardError, setCardError] = useState<string | null>(null);
  const [submittedCards, setSubmittedCards] = useState<
    Record<string, SubmittedLeadState>
  >({});

  // Interactive BEC Live Domain Scanner Modal States
  const [becModalOpen, setBecModalOpen] = useState<boolean>(false);
  const [becDomainInput, setBecDomainInput] = useState<string>("");
  const [becScanning, setBecScanning] = useState<boolean>(false);
  const [becError, setBecError] = useState<string | null>(null);
  const [becResult, setBecResult] = useState<BecScanResult | null>(null);
  const [becEmailInput, setBecEmailInput] = useState<string>("");
  const [becSendingReport, setBecSendingReport] = useState<boolean>(false);
  const [becReportSentTo, setBecReportSentTo] = useState<string | null>(null);

  const [portalsOpen, setPortalsOpen] = useState<boolean>(false);
  const [legalModal, setLegalModal] = useState<LegalModalTab>(null);

  const isWrappingRef = useRef<boolean>(false);
  const hasScrolledDownRef = useRef<boolean>(false);
  const prevChapterRef = useRef<number>(0);
  const portalsMenuRef = useRef<HTMLDivElement>(null);
  const themeMenuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(
        "ks_v2_cyber_theme"
      ) as CyberThemeId | null;
      if (saved && CYBER_THEMES_V2[saved]) {
        setCyberThemeId(saved);
      }
    } catch {
      // ignore storage errors
    }

    fetch("/api/site_config.php", { cache: "no-store" })
      .then((r) => (r.ok ? r.json() : null))
      .then((payload) => {
        if (payload?.success && payload?.customized && payload?.config) {
          const remoteCfg = payload.config as PublicSiteConfig;
          setSiteConfig((prev) => ({
            ...prev,
            ...remoteCfg,
            chapters:
              Array.isArray(remoteCfg.chapters) && remoteCfg.chapters.length === 4
                ? remoteCfg.chapters
                : prev.chapters,
          }));
        }
      })
      .catch(() => {
        // silent fallback to built-in catalog
      });
  }, []);

  const selectCyberTheme = (nextTheme: CyberThemeId) => {
    setCyberThemeId(nextTheme);
    setThemePickerOpen(false);
    try {
      window.localStorage.setItem("ks_v2_cyber_theme", nextTheme);
    } catch {
      // ignore storage errors
    }
  };

  const handleBootProgress = useCallback((prog: number, ready: boolean) => {
    setBootPct(Math.round(prog * 100));
    if (ready) setHudReady(true);
  }, []);

  const handleSculptureClick = useCallback(() => {
    setIsDissolved(true);
    setExpandedCardId(null);
    setCardError(null);
  }, []);

  useEffect(() => {
    if (prevChapterRef.current !== activeChapterIdx) {
      prevChapterRef.current = activeChapterIdx;
      setIsDissolved(false);
      setIsHoveringSculpture(false);
      setExpandedCardId(null);
      setCardError(null);
    }
  }, [activeChapterIdx]);

  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        if (becModalOpen) setBecModalOpen(false);
        else if (legalModal) setLegalModal(null);
        else if (isDissolved) setIsDissolved(false);
      }
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [isDissolved, legalModal, becModalOpen]);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (
        portalsMenuRef.current &&
        !portalsMenuRef.current.contains(e.target as Node)
      ) {
        setPortalsOpen(false);
      }
      if (
        themeMenuRef.current &&
        !themeMenuRef.current.contains(e.target as Node)
      ) {
        setThemePickerOpen(false);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  // Seamless Infinite Vertical Circular Scroll Loop (950vh runway)
  useEffect(() => {
    const handleScroll = () => {
      if (isWrappingRef.current) return;

      const maxScroll = Math.max(
        document.documentElement.scrollHeight - window.innerHeight,
        1
      );
      const y = window.scrollY;
      const r = Math.min(1, Math.max(0, y / maxScroll));

      if (r > 0.12) {
        hasScrolledDownRef.current = true;
      }

      if (r >= 0.994) {
        isWrappingRef.current = true;
        window.scrollTo({ top: Math.round(maxScroll * 0.015), behavior: "auto" });
        setScrollRatio(0.015);
        setActiveChapterIdx(0);
        requestAnimationFrame(() => {
          isWrappingRef.current = false;
        });
        return;
      }

      if (hasScrolledDownRef.current && y <= 2) {
        isWrappingRef.current = true;
        window.scrollTo({ top: Math.round(maxScroll * 0.81), behavior: "auto" });
        setScrollRatio(0.81);
        setActiveChapterIdx(3);
        requestAnimationFrame(() => {
          isWrappingRef.current = false;
        });
        return;
      }

      setScrollRatio(r);

      if (r < 0.18 || r >= 0.93) setActiveChapterIdx(0);
      else if (r < 0.435) setActiveChapterIdx(1);
      else if (r < 0.695) setActiveChapterIdx(2);
      else setActiveChapterIdx(3);
    };

    handleScroll();
    window.addEventListener("scroll", handleScroll, { passive: true });
    return () => window.removeEventListener("scroll", handleScroll);
  }, []);

  const jumpToChapter = (idx: number, openServices = false) => {
    const totalCh = siteConfig.chapters.length || 4;
    const wrapped = (idx + totalCh) % totalCh;
    setExpandedCardId(null);

    if (wrapped === activeChapterIdx) {
      if (wrapped >= 1 && openServices) {
        setIsDissolved(true);
      } else {
        setIsDissolved(false);
      }
      return;
    }

    setIsDissolved(false);
    const maxScroll = Math.max(
      document.documentElement.scrollHeight - window.innerHeight,
      1
    );
    const chapterScrollStops = [0.03, 0.305, 0.565, 0.82];
    window.scrollTo({
      top: maxScroll * chapterScrollStops[wrapped],
      behavior: "smooth",
    });

    if (wrapped >= 1 && openServices) {
      window.setTimeout(() => {
        setIsDissolved(true);
      }, 650);
    }
  };

  const openEmailCapture = (card: ServiceCardOffer) => {
    if (card.opensBecScanner) {
      setBecModalOpen(true);
      setBecError(null);
      return;
    }
    setExpandedCardId(card.id);
    setCardError(null);
  };

  const handleRunBecScan = async (e: React.FormEvent) => {
    e.preventDefault();
    setBecError(null);
    setBecReportSentTo(null);

    const rawDom = becDomainInput.trim().toLowerCase();
    if (!rawDom || !rawDom.includes(".")) {
      setBecError("Ingresa un dominio corporativo válido (ej. tuempresa.cl).");
      return;
    }

    setBecScanning(true);
    try {
      const res = await fetch("/api/lead.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "bec_scan",
          domain: rawDom,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        setBecError(data.error || "No fue posible analizar el dominio.");
      } else {
        setBecResult({
          domain: data.domain,
          score: data.score,
          risk_level: data.risk_level,
          spf: data.spf,
          dmarc: data.dmarc,
          mx: data.mx,
          https_hsts: data.https_hsts,
          findings: data.findings || [],
          scanned_at: data.scanned_at || "",
        });
      }
    } catch {
      setBecError("Error de conexión al ejecutar el diagnóstico DNS.");
    } finally {
      setBecScanning(false);
    }
  };

  const handleSendBecReportEmail = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!becResult) return;
    const cleanEmail = becEmailInput.trim().toLowerCase();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(cleanEmail)) {
      setBecError("Ingresa un correo corporativo válido.");
      return;
    }

    setBecSendingReport(true);
    setBecError(null);
    try {
      await fetch("/api/lead.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "lead",
          email: cleanEmail,
          domain: becResult.domain,
          pillar: "DeepShield",
          service_name: `Informe Diagnóstico BEC (${becResult.domain})`,
          price_info: `Score ${becResult.score}/100 - ${becResult.risk_level}`,
          scan_score: becResult.score,
        }),
      });
      setBecReportSentTo(cleanEmail);
      setBecEmailInput("");
    } catch {
      setBecReportSentTo(cleanEmail);
    } finally {
      setBecSendingReport(false);
    }
  };

  const handleLeadSubmit = async (
    e: React.FormEvent,
    pillar: PillarCatalog,
    card: ServiceCardOffer
  ) => {
    e.preventDefault();
    setCardError(null);

    const cleanEmail = emailInput.trim().toLowerCase();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(cleanEmail)) {
      setCardError("Ingresa un correo corporativo válido.");
      return;
    }

    setSubmittingCardId(card.id);
    try {
      const res = await fetch("/api/lead.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "lead",
          email: cleanEmail,
          pillar: pillar.label,
          service_name: card.title,
          price_info: card.priceHighlight,
        }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        setCardError(data.error || "No se pudo procesar. Intenta nuevamente.");
      } else {
        setSubmittedCards((prev) => ({
          ...prev,
          [card.id]: { email: cleanEmail },
        }));
        setExpandedCardId(null);
        setEmailInput("");
      }
    } catch {
      setSubmittedCards((prev) => ({
        ...prev,
        [card.id]: { email: cleanEmail },
      }));
      setExpandedCardId(null);
      setEmailInput("");
    } finally {
      setSubmittingCardId(null);
    }
  };

  const activeChapters = (siteConfig.chapters || DEFAULT_SITE_CONFIG.chapters).map(
    enrichPillarForV2
  );
  const activePillar = activeChapters[activeChapterIdx] || activeChapters[0];
  const activeHeaderSpec =
    V2_PILLAR_HEADERS[activeChapterIdx] || V2_PILLAR_HEADERS[1];

  const spatialNavItems: SpatialMenuItem[] = activeChapters.map((ch) => ({
    id: `nav-v2-${ch.chapterIndex}`,
    label: ch.label,
    isActive: ch.chapterIndex === activeChapterIdx,
    onClick: () => jumpToChapter(ch.chapterIndex, ch.chapterIndex >= 1),
  }));

  return (
    <main
      className="relative min-h-[950vh] bg-[#020510] text-white selection:bg-sky-500 selection:text-white"
      style={{
        fontFamily: "var(--font-inter), sans-serif",
      }}
    >
      {/* =====================================================================
          1. FULLSCREEN 4K CINEMATIC WEBGL ENGINE:
             3D DIGITAL LANDSCAPE + FUTURISTIC BLUE LIGHT TUNNEL IN MOTION
      ====================================================================== */}
      <KeystoneDigitalTunnelEngine
        scrollRatio={scrollRatio}
        activeChapter={activeChapterIdx}
        cyberThemeId={cyberThemeId}
        isDissolved={isDissolved}
        onSculptureClick={handleSculptureClick}
        onSculptureHoverChange={setIsHoveringSculpture}
        onBootProgress={handleBootProgress}
      />

      {!hudReady && (
        <div className="pointer-events-none fixed bottom-8 left-8 z-20 text-[11px] font-medium tracking-[0.22em] text-sky-200/90 uppercase">
          <div>Keystone Advisors Global</div>
          <div className="text-sky-400/75 mt-0.5">
            Iniciando núcleo cuántico 4K {bootPct}%
          </div>
        </div>
      )}

      {/* =====================================================================
          2. OPTICAL CONTRAST SHIELDS (TOP & BOTTOM GRADIENT VEILS)
             Guarantees menus, brand logo, and typography NEVER blend or get lost!
      ====================================================================== */}
      <div
        className="pointer-events-none fixed inset-x-0 top-0 h-32 z-10"
        style={{
          background:
            "linear-gradient(180deg, rgba(2, 5, 16, 0.88) 0%, rgba(2, 5, 16, 0.42) 55%, rgba(2, 5, 16, 0) 100%)",
        }}
      />
      <div
        className="pointer-events-none fixed inset-x-0 bottom-0 h-44 z-10"
        style={{
          background:
            "linear-gradient(0deg, rgba(2, 5, 16, 0.94) 0%, rgba(2, 5, 16, 0.62) 52%, rgba(2, 5, 16, 0) 100%)",
        }}
      />
      <div
        className="pointer-events-none fixed inset-0 z-10 opacity-[0.07]"
        style={{
          backgroundImage:
            "radial-gradient(circle, rgba(125,211,252,0.9) 1px, transparent 1.2px)",
          backgroundSize: "72px 72px",
        }}
      />

      {/* =====================================================================
          3. EXECUTIVE HIGH-CONTRAST SPATIAL INTERFACE
      ====================================================================== */}
      <div
        className={`pointer-events-none fixed inset-0 z-20 flex flex-col justify-between p-4 sm:p-6 lg:px-8 lg:py-6 transition-opacity duration-700 ${
          hudReady ? "opacity-100" : "opacity-0"
        }`}
      >
        {/* TOP ROW: Luminous Platinum-Sapphire Logo + Frosted Obsidian Executive Utility Bar */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="pointer-events-auto">
            <KeystoneLivingBrandV2
              onSelectHome={() => jumpToChapter(0, false)}
            />
          </div>

          <div
            style={{
              backgroundColor: "rgba(4, 10, 25, 0.72)",
              backdropFilter: "blur(18px)",
              WebkitBackdropFilter: "blur(18px)",
            }}
            className="pointer-events-auto flex flex-wrap items-center gap-2 sm:gap-3.5 px-3.5 py-2 rounded-full border border-sky-400/20 shadow-[0_8px_30px_rgba(0,0,0,0.65)] text-[11px] sm:text-xs font-semibold text-slate-200"
          >
            {/* Restore 3D Emblem when Dissolved */}
            {activeChapterIdx >= 1 && isDissolved && (
              <button
                type="button"
                onClick={() => {
                  setIsDissolved(false);
                  setExpandedCardId(null);
                }}
                style={{
                  fontFamily: "var(--font-jakarta), sans-serif",
                }}
                className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-sky-500/20 text-sky-200 text-xs font-semibold border border-sky-400/40 hover:bg-sky-400 hover:text-[#020617] transition-all"
              >
                <X className="w-3.5 h-3.5" />
                <span>Emblema 3D</span>
              </button>
            )}

            <Link
              href="/trust/"
              className="hidden md:inline-flex items-center gap-1.5 text-slate-200 hover:text-white transition-colors"
            >
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.9)] animate-pulse" />
              <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" />
              <span>Trust Center</span>
            </Link>

            <div ref={portalsMenuRef} className="relative hidden md:block">
              <button
                type="button"
                onClick={() => setPortalsOpen((prev) => !prev)}
                className="inline-flex items-center gap-1 text-slate-200 hover:text-sky-300 transition-colors"
              >
                <Lock className="w-3.5 h-3.5 text-sky-400" />
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
                    backgroundColor: "rgba(4, 10, 24, 0.96)",
                    backdropFilter: "blur(24px)",
                  }}
                  className="absolute right-0 top-full mt-2.5 w-64 border border-sky-400/25 rounded-xl p-2 shadow-[0_20px_50px_rgba(0,0,0,0.85)] text-left text-white z-40"
                >
                  <Link
                    href="/shield/portal/"
                    className="flex items-start justify-between p-2.5 rounded-lg hover:bg-sky-500/15 transition-colors group"
                  >
                    <div>
                      <div className="text-xs font-semibold text-white group-hover:text-sky-300">
                        Portal DeepShield
                      </div>
                      <div className="text-[11px] text-slate-400">
                        Custodia OOB y órdenes de pago
                      </div>
                    </div>
                    <ExternalLink className="w-3.5 h-3.5 text-slate-500 group-hover:text-sky-300 mt-0.5" />
                  </Link>
                  <Link
                    href="/shield/portal/?tab=whitelist"
                    className="flex items-start justify-between p-2.5 rounded-lg hover:bg-sky-500/15 transition-colors group"
                  >
                    <div>
                      <div className="text-xs font-semibold text-white group-hover:text-sky-300">
                        Padrón Proveedores & DMARC
                      </div>
                      <div className="text-[11px] text-slate-400">
                        Cuentas verificadas y anti-BEC
                      </div>
                    </div>
                    <ExternalLink className="w-3.5 h-3.5 text-slate-500 group-hover:text-sky-300 mt-0.5" />
                  </Link>
                </div>
              )}
            </div>

            <button
              type="button"
              onClick={() => setLegalModal("terms")}
              className="hidden lg:inline-block text-slate-200 hover:text-sky-300 transition-colors"
            >
              Legal y Cumplimiento
            </button>

            <a
              href={`mailto:${siteConfig.contactEmail}`}
              title={siteConfig.contactEmail}
              className="hidden xl:inline-flex items-center gap-1 text-slate-200 hover:text-sky-300 transition-colors"
            >
              <Mail className="w-3.5 h-3.5 text-sky-400" />
              <span>{siteConfig.contactEmail}</span>
            </a>

            <a
              href={`https://wa.me/${siteConfig.whatsappNumber}`}
              target="_blank"
              rel="noopener noreferrer"
              title={`WhatsApp Corporativo: ${siteConfig.whatsappDisplay}`}
              className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-sky-500/20 hover:bg-sky-400 text-white hover:text-[#020617] border border-sky-400/35 transition-all shadow-[0_0_16px_rgba(14,165,233,0.25)]"
            >
              <MessageCircle className="w-3.5 h-3.5" />
              <span>{siteConfig.whatsappDisplay}</span>
            </a>

            {/* Cyber Light Tunnel Tone Switcher */}
            <div ref={themeMenuRef} className="relative">
              <button
                type="button"
                onClick={() => setThemePickerOpen((prev) => !prev)}
                title="Personalizar espectro de luz 3D"
                className="inline-flex items-center gap-1.5 p-1.5 rounded-full bg-white/10 hover:bg-sky-400 text-sky-300 hover:text-[#020617] border border-white/15 transition-all"
              >
                <Palette className="w-3.5 h-3.5" />
              </button>

              {themePickerOpen && (
                <div
                  style={{
                    backgroundColor: "rgba(4, 10, 24, 0.96)",
                    backdropFilter: "blur(22px)",
                  }}
                  className="absolute right-0 top-full mt-2.5 w-64 rounded-xl border border-sky-400/25 p-2 shadow-[0_20px_50px_rgba(0,0,0,0.85)] z-40 text-left text-white"
                >
                  <div className="px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.14em] text-sky-300/70">
                    Espectro Túnel Digital 4K
                  </div>
                  {CYBER_THEME_OPTIONS.map((opt) => {
                    const active = cyberThemeId === opt.id;
                    return (
                      <button
                        key={opt.id}
                        type="button"
                        onClick={() => selectCyberTheme(opt.id)}
                        className={`w-full flex items-center justify-between px-2.5 py-2 rounded-lg text-xs transition-colors ${
                          active
                            ? "bg-sky-500/20 text-white font-semibold"
                            : "text-slate-300 hover:bg-white/10 hover:text-white"
                        }`}
                      >
                        <span className="flex items-center gap-2">
                          <span
                            className="w-3 h-3 rounded-full border border-white/40 shadow-[0_0_8px_currentColor]"
                            style={{ backgroundColor: opt.swatch }}
                          />
                          <span>{opt.label}</span>
                        </span>
                        {active && (
                          <span className="w-1.5 h-1.5 rounded-full bg-sky-400 shadow-[0_0_8px_#38bdf8]" />
                        )}
                      </button>
                    );
                  })}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* =================================================================
            CENTER STAGE:
            - STATE A (Not Dissolved): Luminous Pillar Headline + Click-on-3D Trigger
            - STATE B (Dissolved): Symmetrical Sapphire-Obsidian Glass Service Cards
        ================================================================== */}
        <div className="relative flex-1 flex flex-col items-center justify-center my-2">
          {!isDissolved && (
            <div className="pointer-events-none absolute inset-x-0 top-1/2 -translate-y-1/2 flex items-center justify-between px-0 sm:px-3">
              <button
                type="button"
                onClick={() => jumpToChapter(activeChapterIdx - 1)}
                aria-label="Anterior"
                className="pointer-events-auto group flex items-center gap-2 py-3 px-3 text-sky-200/80 hover:text-white transition-all drop-shadow-[0_2px_10px_rgba(0,0,0,0.9)]"
              >
                <ChevronLeft className="w-5 h-5 group-hover:-translate-x-1 transition-transform" />
                <span className="hidden sm:inline-block w-9 h-[1px] bg-sky-400/60 group-hover:w-14 group-hover:bg-sky-300 transition-all" />
              </button>

              <button
                type="button"
                onClick={() => jumpToChapter(activeChapterIdx + 1)}
                aria-label="Siguiente"
                className="pointer-events-auto group flex items-center gap-2 py-3 px-3 text-sky-200/80 hover:text-white transition-all drop-shadow-[0_2px_10px_rgba(0,0,0,0.9)]"
              >
                <span className="hidden sm:inline-block w-9 h-[1px] bg-sky-400/60 group-hover:w-14 group-hover:bg-sky-300 transition-all" />
                <ChevronRight className="w-5 h-5 group-hover:translate-x-1 transition-transform" />
              </button>
            </div>
          )}

          {/* CHAPTER 0 (INICIO): SUBTLE EXECUTIVE VALUE HOOK PILL AT BOTTOM OF CENTER STAGE */}
          {activeChapterIdx === 0 && !isDissolved && (
            <div className="w-full max-w-4xl mx-auto flex flex-col items-center justify-end h-full pb-2 sm:pb-4 text-center">
              <div
                style={{
                  background:
                    "linear-gradient(135deg, rgba(6, 15, 36, 0.82) 0%, rgba(3, 8, 22, 0.92) 100%)",
                  backdropFilter: "blur(18px)",
                }}
                className="pointer-events-auto inline-flex flex-wrap items-center justify-center gap-2 sm:gap-3.5 px-4 sm:px-6 py-2.5 rounded-full border border-white/15 shadow-[0_14px_40px_rgba(0,0,0,0.75)] text-xs sm:text-[13px]"
              >
                <span className="font-semibold text-slate-200">
                  Ingeniería de Élite con Resultados Rápidos:
                </span>
                <button
                  type="button"
                  onClick={() => jumpToChapter(1, true)}
                  className="font-bold text-cyan-300 hover:text-white transition-colors"
                >
                  Salesforce desde USD $2.490
                </button>
                <span className="text-white/25">·</span>
                <button
                  type="button"
                  onClick={() => setBecModalOpen(true)}
                  className="font-bold text-emerald-300 hover:text-white transition-colors"
                >
                  Test Anti-Fraude Gratis
                </button>
                <span className="text-white/25">·</span>
                <button
                  type="button"
                  onClick={() => jumpToChapter(3, true)}
                  className="font-bold text-violet-300 hover:text-white transition-colors"
                >
                  SaaS & IA desde USD $8
                </button>
              </div>
            </div>
          )}

          {/* STATE A: CONTEMPLATION VIEW FOR PILLARS 1, 2, 3 */}
          {activeChapterIdx >= 1 && !isDissolved && (
            <div className="w-full max-w-4xl mx-auto flex flex-col items-center justify-between h-full py-0 sm:py-2 text-center">
              <KeystoneLuminousTitleV2
                chapterIndex={activeChapterIdx}
                primaryText={activePillar.label}
                secondaryText={activePillar.headline}
                onClick={handleSculptureClick}
              />

              <button
                type="button"
                onClick={handleSculptureClick}
                style={{
                  fontFamily: "var(--font-jakarta), sans-serif",
                  background: isHoveringSculpture
                    ? "#ffffff"
                    : "linear-gradient(135deg, rgba(14, 165, 233, 0.92) 0%, rgba(37, 99, 235, 0.95) 100%)",
                  boxShadow: isHoveringSculpture
                    ? "0 0 34px rgba(255,255,255,0.75)"
                    : "0 12px 32px rgba(14, 165, 233, 0.45)",
                }}
                className={`pointer-events-auto group inline-flex items-center gap-2.5 px-6 py-3 rounded-full text-xs sm:text-sm font-bold transition-all duration-300 border ${
                  isHoveringSculpture
                    ? "text-[#020617] border-white scale-105"
                    : "text-white border-white/35 hover:scale-105"
                }`}
              >
                <Sparkles className="w-4 h-4 shrink-0" />
                <span>{activeHeaderSpec.ctaHint}</span>
                <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
              </button>
            </div>
          )}

          {/* STATE B: DISSOLVED REVEAL — MULTI-COLOR JEWEL SHOWCASE CARDS */}
          {activeChapterIdx >= 1 && isDissolved && activePillar.cards && (
            <div className="pointer-events-auto w-full max-w-[1240px] mx-auto px-2 sm:px-5 max-h-[73vh] overflow-y-auto py-1">
              <div className="flex justify-center mb-3 sm:mb-4">
                <KeystoneLuminousTitleV2
                  chapterIndex={activeChapterIdx}
                  primaryText={activePillar.label}
                  secondaryText={activePillar.headline}
                  compact
                />
              </div>

              <div
                className={`grid grid-cols-1 md:grid-cols-2 ${
                  activePillar.cards.length === 3
                    ? "lg:grid-cols-3 max-w-5xl mx-auto"
                    : "lg:grid-cols-4"
                } gap-4 sm:gap-5 items-stretch pt-2`}
              >
                {activePillar.cards.map((card, cardIdx) => {
                  const isCardExpanded = expandedCardId === card.id;
                  const submitted = submittedCards[card.id];
                  const isBusy = submittingCardId === card.id;
                  const spec = V2_CARD_SPECS[card.id];
                  const col = spec?.colors;
                  const isVip = Boolean(spec?.isVipFeatured || card.isFeatured);
                  const vipLabel = spec?.vipBadge || card.featuredBadge;

                  return (
                    <div
                      key={card.id}
                      style={{
                        background: isVip
                          ? "linear-gradient(165deg, rgba(11, 24, 52, 0.96) 0%, rgba(4, 10, 26, 0.98) 100%)"
                          : "linear-gradient(165deg, rgba(7, 16, 36, 0.93) 0%, rgba(3, 8, 21, 0.96) 100%)",
                        backdropFilter: "blur(28px)",
                        WebkitBackdropFilter: "blur(28px)",
                        borderColor: isCardExpanded
                          ? col?.hoverBorderColor || "rgba(56,189,248,0.8)"
                          : col?.borderColor || "rgba(255,255,255,0.18)",
                        boxShadow:
                          col?.cardShadow || "0 20px 50px rgba(0,0,0,0.8)",
                      }}
                      className={`group/card relative flex flex-col justify-between border rounded-2xl p-5 sm:p-5.5 text-left transition-all duration-300 hover:-translate-y-1.5 ${
                        isVip ? "lg:-translate-y-1.5 ring-1 ring-amber-300/25" : ""
                      }`}
                    >
                      {/* Top Luminous Multi-Color Jewel Bar */}
                      <div
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-x-4 top-0 h-[3px] rounded-b-full opacity-95"
                        style={{
                          background:
                            col?.topBarGradient ||
                            "linear-gradient(90deg, #38bdf8, #818cf8)",
                        }}
                      />

                      {/* Ambient Jewel Spotlight inside Card Top */}
                      <div
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-x-0 top-0 h-36 rounded-t-2xl opacity-90 transition-opacity duration-300 group-hover/card:opacity-100"
                        style={{
                          background: col?.headerAura,
                        }}
                      />

                      {/* Floating VIP Crown Ribbon on Flagship Offer */}
                      {isVip && vipLabel && (
                        <div className="relative z-10 -mt-8 mb-2.5 flex justify-center">
                          <span
                            style={{
                              fontFamily: "var(--font-jakarta), sans-serif",
                              background:
                                col?.ribbonGradient ||
                                "linear-gradient(90deg, #f59e0b, #38bdf8)",
                            }}
                            className="inline-flex items-center gap-1 px-3.5 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-[0.10em] text-[#020617] shadow-[0_6px_20px_rgba(245,158,11,0.45)]"
                          >
                            <span>{vipLabel}</span>
                          </span>
                        </div>
                      )}

                      <div className="relative z-10">
                        {/* Top Row: Tier Number + Colored Value Pill */}
                        <div className="flex items-center justify-between gap-2 mb-2.5">
                          <span
                            style={{
                              backgroundColor: col?.tagBg,
                              borderColor: col?.tagBorder,
                              color: col?.tagText,
                              fontFamily: "var(--font-jakarta), sans-serif",
                            }}
                            className="inline-block px-2.5 py-0.5 rounded-md border text-[10px] font-extrabold uppercase tracking-[0.09em]"
                          >
                            {card.tag}
                          </span>
                          <span
                            style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                            className="text-[11px] font-bold tracking-[0.14em] text-white/30"
                          >
                            {spec?.tierNumber || `0${cardIdx + 1}`}
                          </span>
                        </div>

                        {/* Product Name */}
                        <h3
                          style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                          className="text-[17.5px] sm:text-[18.5px] font-extrabold tracking-[-0.01em] text-white leading-snug"
                        >
                          {card.title}
                        </h3>

                        {/* Client Benefit Hook Phrase */}
                        {spec?.hookHeadline && (
                          <p
                            style={{ color: col?.tagText || "#bae6fd" }}
                            className="text-[12.5px] font-semibold mt-1.5 leading-snug"
                          >
                            “{spec.hookHeadline}”
                          </p>
                        )}

                        {/* Clear Executive Value Description */}
                        <p className="text-xs sm:text-[12.5px] text-slate-300/90 mt-2 leading-relaxed">
                          {card.description}
                        </p>

                        {/* Tangible ROI / Speed Checkmark */}
                        {spec?.roiBullet && (
                          <div className="mt-3 flex items-center gap-1.5 text-[11.5px] font-semibold">
                            <span
                              className="w-2 h-2 rounded-full shrink-0 shadow-sm"
                              style={{
                                backgroundColor: col?.roiDotColor || "#38bdf8",
                                boxShadow: `0 0 8px ${col?.roiDotColor || "#38bdf8"}`,
                              }}
                            />
                            <span style={{ color: col?.roiTextColor || "#e0f2fe" }}>
                              {spec.roiBullet}
                            </span>
                          </div>
                        )}
                      </div>

                      {/* Bottom Conversion Block: Jewel-Highlighted Investment Showcase + Action CTA */}
                      <div className="relative z-10 mt-4 pt-3 border-t border-white/10">
                        <div
                          style={{
                            background: col?.priceBoxBg,
                            borderColor: col?.priceBoxBorder,
                          }}
                          className="rounded-xl border px-3.5 py-2.5 mb-3"
                        >
                          <div className="flex items-baseline flex-wrap gap-1.5">
                            {spec?.pricePrefix && (
                              <span className="text-[11px] font-bold uppercase tracking-[0.08em] text-slate-300">
                                {spec.pricePrefix}
                              </span>
                            )}
                            <span
                              style={{
                                fontFamily: "var(--font-jakarta), sans-serif",
                                backgroundImage:
                                  col?.priceGradient ||
                                  "linear-gradient(90deg, #fff, #7dd3fc)",
                                WebkitBackgroundClip: "text",
                                backgroundClip: "text",
                                color: "transparent",
                              }}
                              className="text-[22px] sm:text-[24px] font-extrabold tracking-tight leading-none"
                            >
                              {spec ? spec.priceMain : card.priceHighlight}
                            </span>
                            {spec?.priceSuffix && (
                              <span className="text-xs font-bold text-slate-200">
                                {spec.priceSuffix}
                              </span>
                            )}
                          </div>
                          {card.priceNote && (
                            <div className="text-[10.5px] font-medium text-slate-300/80 mt-1">
                              {card.priceNote}
                            </div>
                          )}
                        </div>

                        {submitted ? (
                          <div className="rounded-xl bg-emerald-500/15 border border-emerald-400/40 p-3 text-xs text-emerald-200">
                            <div className="flex items-start gap-2">
                              <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                              <span>
                                ¡Excelente! Te contactaremos hoy a{" "}
                                <strong className="text-white">
                                  {submitted.email}
                                </strong>{" "}
                                para activar tu plan.
                              </span>
                            </div>
                          </div>
                        ) : isCardExpanded ? (
                          <form
                            onSubmit={(e) =>
                              handleLeadSubmit(e, activePillar, card)
                            }
                            className="space-y-2"
                          >
                            <div className="flex items-center gap-1.5">
                              <input
                                type="email"
                                required
                                autoFocus
                                value={emailInput}
                                onChange={(e) => setEmailInput(e.target.value)}
                                placeholder="tu@empresa.cl"
                                className="w-full min-w-0 px-3 py-2.5 rounded-xl bg-black/50 border border-white/30 text-xs text-white placeholder:text-white/45 focus:outline-none focus:border-sky-300"
                              />
                              <button
                                type="submit"
                                disabled={isBusy}
                                style={{
                                  fontFamily: "var(--font-jakarta), sans-serif",
                                  background:
                                    col?.topBarGradient ||
                                    "linear-gradient(90deg, #38bdf8, #2563eb)",
                                }}
                                className="shrink-0 inline-flex items-center justify-center px-3.5 py-2.5 rounded-xl text-[#020617] text-xs font-extrabold transition-transform hover:scale-105 disabled:opacity-60"
                              >
                                {isBusy ? (
                                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                                ) : (
                                  <ArrowRight className="w-3.5 h-3.5" />
                                )}
                              </button>
                            </div>
                            {cardError && (
                              <p className="text-[11px] text-rose-300">
                                {cardError}
                              </p>
                            )}
                          </form>
                        ) : (
                          <button
                            type="button"
                            onClick={() => openEmailCapture(card)}
                            style={{
                              fontFamily: "var(--font-jakarta), sans-serif",
                              background: col?.ctaGradient,
                              boxShadow: col?.ctaShadow,
                              borderColor: col?.tagBorder,
                            }}
                            className={`w-full group inline-flex items-center justify-between px-4 py-2.5 rounded-xl text-xs font-extrabold border transition-all duration-300 ${
                              isVip
                                ? "text-white hover:brightness-110 hover:scale-[1.02]"
                                : "text-white hover:bg-white hover:text-[#020617] hover:border-white"
                            }`}
                          >
                            <span className="inline-flex items-center gap-1.5">
                              {card.opensBecScanner && (
                                <Radar className="w-3.5 h-3.5" />
                              )}
                              <span>{card.ctaLabel}</span>
                            </span>
                            <ArrowRight className="w-3.5 h-3.5 group-hover:translate-x-1 transition-transform" />
                          </button>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>

        {/* =================================================================
            BOTTOM ZONE:
            1) Floating Sapphire-Glass Spatial Typography Menu (100% crisp legibility)
            2) Luminous White & Cyan Official Partners Marquee
        ================================================================== */}
        <div className="w-full flex flex-col items-center gap-2.5 sm:gap-3 pt-1">
          <div className="pointer-events-auto">
            <KeystoneSpatialTextNavV2 items={spatialNavItems} />
          </div>

          {/* Mobile compact legal/trust links */}
          <div className="pointer-events-auto flex md:hidden items-center justify-center gap-4 text-[11px] font-semibold text-slate-300">
            <Link href="/trust/" className="hover:text-white">
              Trust Center
            </Link>
            <span>·</span>
            <button
              type="button"
              onClick={() => setLegalModal("terms")}
              className="hover:text-white"
            >
              Legal y Cumplimiento
            </button>
          </div>

          <div className="w-full border-t border-sky-400/20 pt-2">
            <KeystonePartnersMarqueeV2 items={siteConfig.partnersMarquee} />
          </div>
        </div>
      </div>

      {/* =====================================================================
          4. EXECUTIVE GLASS MODAL: TERMS, PRIVACY & COMPLIANCE
      ====================================================================== */}
      {legalModal && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-md"
          onClick={() => setLegalModal(null)}
        >
          <div
            style={{ backgroundColor: "rgba(4, 10, 24, 0.97)" }}
            className="w-full max-w-2xl border border-sky-400/25 rounded-2xl p-6 sm:p-8 text-white shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between border-b border-white/15 pb-4 mb-5">
              <div className="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  onClick={() => setLegalModal("terms")}
                  className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${
                    legalModal === "terms"
                      ? "bg-sky-400 text-[#020617]"
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
                      ? "bg-sky-400 text-[#020617]"
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
                      ? "bg-sky-400 text-[#020617]"
                      : "text-white/70 hover:text-white"
                  }`}
                >
                  Canal Ético y Denuncias
                </button>
              </div>
              <button
                type="button"
                onClick={() => setLegalModal(null)}
                aria-label="Cerrar"
                className="p-1.5 rounded-lg text-white/60 hover:text-white hover:bg-white/10 transition-colors"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {legalModal === "terms" && (
              <div className="space-y-3 text-xs sm:text-sm text-white/85 leading-relaxed max-h-[60vh] overflow-y-auto pr-2">
                <h3
                  className="text-base font-bold text-white"
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                >
                  Términos y Condiciones Corporativos — Keystone Advisors SpA
                </h3>
                <p>
                  Todos los servicios de consultoría Salesforce, arquitectura Cloud, ciberdefensa activa DeepShield y licenciamiento SaaS KeyHub se rigen bajo acuerdos marco de confidencialidad estricta (NDA), niveles de servicio (SLA) garantizados contractualmente y propiedad intelectual auditada.
                </p>
                <p>
                  Las tarifas expresadas en el catálogo comercial corresponden a valores referenciales netos en dólares estadounidenses (USD) o su equivalente en Unidades de Fomento (UF), sujetos al alcance técnico formalizado en cada propuesta ejecutiva.
                </p>
                <p className="text-white/55 text-xs pt-2">
                  © 2026 Keystone Advisors SpA · Operaciones en Chile, Perú, Panamá, República Dominicana y Canadá.
                </p>
              </div>
            )}

            {legalModal === "privacy" && (
              <div className="space-y-3 text-xs sm:text-sm text-white/85 leading-relaxed max-h-[60vh] overflow-y-auto pr-2">
                <h3
                  className="text-base font-bold text-white"
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                >
                  Política de Privacidad y Protección de Datos
                </h3>
                <p>
                  Keystone Advisors cumple rigurosamente con la Ley N° 19.628 / nueva Ley de Protección de Datos Personales de Chile y estándares internacionales ISO/IEC 27001. Los correos corporativos ingresados en nuestros flujos de diagnóstico se utilizan exclusivamente para coordinar la evaluación solicitada.
                </p>
                <p>
                  Los análisis pasivos de dominio (SPF, DKIM, DMARC) consultan únicamente registros DNS públicos sin intervenir ni almacenar tráfico privado de su organización.
                </p>
              </div>
            )}

            {legalModal === "compliance" && (
              <div className="space-y-3 text-xs sm:text-sm text-white/85 leading-relaxed max-h-[60vh] overflow-y-auto pr-2">
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

      {/* =====================================================================
          5. INTERACTIVE LIVE BEC DOMAIN SCANNER CONSOLE (DEEPSHIELD)
      ====================================================================== */}
      {becModalOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-md"
          onClick={() => setBecModalOpen(false)}
        >
          <div
            style={{
              backgroundColor: "rgba(4, 10, 24, 0.97)",
              backdropFilter: "blur(26px)",
            }}
            className="w-full max-w-2xl border border-emerald-400/30 rounded-2xl p-6 sm:p-8 text-white shadow-[0_24px_60px_rgba(0,0,0,0.75)] max-h-[88vh] overflow-y-auto"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-start justify-between gap-4 border-b border-white/15 pb-4">
              <div>
                <div
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-emerald-300"
                >
                  <Radar className="w-3.5 h-3.5" />
                  <span>DeepShield · Escáner Pasivo en Vivo</span>
                </div>
                <h3
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="text-xl sm:text-2xl font-bold text-white mt-1"
                >
                  Diagnóstico de Suplantación de Dominio (BEC)
                </h3>
                <p className="text-xs sm:text-sm text-white/70 mt-1">
                  Analiza en tiempo real los registros DNS públicos (DMARC, SPF, MX y TLS) para verificar si terceros pueden falsificar correos de tu empresa.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setBecModalOpen(false)}
                aria-label="Cerrar escáner"
                className="p-1.5 rounded-lg text-white/60 hover:text-white hover:bg-white/10 transition-colors"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleRunBecScan} className="mt-5">
              <label className="block text-xs font-medium text-white/75 mb-2">
                Ingresa el dominio corporativo a evaluar:
              </label>
              <div className="flex flex-col sm:flex-row gap-2">
                <input
                  type="text"
                  required
                  autoFocus
                  value={becDomainInput}
                  onChange={(e) => setBecDomainInput(e.target.value)}
                  placeholder="ejemplo: tuempresa.cl"
                  className="flex-1 px-4 py-2.5 rounded-xl bg-white/10 border border-white/20 text-sm text-white placeholder:text-white/40 focus:outline-none focus:border-emerald-400"
                />
                <button
                  type="submit"
                  disabled={becScanning}
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-400 hover:bg-emerald-300 text-[#020617] text-xs sm:text-sm font-bold transition-colors disabled:opacity-60"
                >
                  {becScanning ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin" />
                      <span>Analizando DNS...</span>
                    </>
                  ) : (
                    <>
                      <Radar className="w-4 h-4" />
                      <span>Analizar Dominio</span>
                    </>
                  )}
                </button>
              </div>
              {becError && (
                <p className="text-xs text-rose-300 mt-2">{becError}</p>
              )}
            </form>

            {becResult && (
              <div className="mt-6 space-y-4 border-t border-white/15 pt-5">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-xl bg-white/5 border border-white/12">
                  <div>
                    <div className="text-[11px] uppercase tracking-[0.12em] text-white/55">
                      Dominio Evaluado
                    </div>
                    <div
                      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                      className="text-lg font-bold text-white"
                    >
                      {becResult.domain}
                    </div>
                    <div
                      className={`text-xs font-semibold mt-0.5 ${
                        becResult.score >= 85
                          ? "text-emerald-400"
                          : becResult.score >= 55
                          ? "text-amber-300"
                          : "text-rose-400"
                      }`}
                    >
                      Estado: {becResult.risk_level}
                    </div>
                  </div>

                  <div className="flex items-center gap-3">
                    <div className="text-right">
                      <div className="text-[11px] text-white/55">
                        Índice de Blindaje
                      </div>
                      <div
                        style={{
                          fontFamily: "var(--font-jakarta), sans-serif",
                        }}
                        className={`text-3xl font-extrabold ${
                          becResult.score >= 85
                            ? "text-emerald-400"
                            : becResult.score >= 55
                            ? "text-amber-300"
                            : "text-rose-400"
                        }`}
                      >
                        {becResult.score}
                        <span className="text-sm font-medium text-white/50">
                          /100
                        </span>
                      </div>
                    </div>
                  </div>
                </div>

                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-xs">
                  <div className="p-3 rounded-xl bg-white/5 border border-white/10">
                    <div className="text-white/50 text-[11px]">Política DMARC</div>
                    <div className="font-semibold text-white mt-0.5">
                      {becResult.dmarc}
                    </div>
                  </div>
                  <div className="p-3 rounded-xl bg-white/5 border border-white/10">
                    <div className="text-white/50 text-[11px]">Registro SPF</div>
                    <div className="font-semibold text-white mt-0.5">
                      {becResult.spf}
                    </div>
                  </div>
                  <div className="p-3 rounded-xl bg-white/5 border border-white/10">
                    <div className="text-white/50 text-[11px]">Correo MX</div>
                    <div className="font-semibold text-white mt-0.5">
                      {becResult.mx}
                    </div>
                  </div>
                  <div className="p-3 rounded-xl bg-white/5 border border-white/10">
                    <div className="text-white/50 text-[11px]">Cifrado Web</div>
                    <div className="font-semibold text-white mt-0.5">
                      {becResult.https_hsts}
                    </div>
                  </div>
                </div>

                <div className="space-y-2">
                  <div className="text-xs font-semibold text-white/80">
                    Hallazgos técnicos detectados:
                  </div>
                  {becResult.findings.map((f, idx) => (
                    <div
                      key={idx}
                      className="flex items-start gap-2.5 p-3 rounded-xl bg-white/5 border border-white/10 text-xs"
                    >
                      {f.severity === "ok" ? (
                        <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                      ) : (
                        <AlertTriangle
                          className={`w-4 h-4 shrink-0 mt-0.5 ${
                            f.severity === "critical" || f.severity === "high"
                              ? "text-rose-400"
                              : "text-amber-300"
                          }`}
                        />
                      )}
                      <div>
                        <div className="font-semibold text-white">{f.title}</div>
                        <div className="text-white/70 mt-0.5 leading-relaxed">
                          {f.desc}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="p-4 rounded-xl bg-emerald-500/10 border border-emerald-400/25">
                  {becReportSentTo ? (
                    <div className="flex items-center gap-2 text-xs sm:text-sm text-emerald-200">
                      <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
                      <span>
                        ¡Listo! Enviaremos el plan de remediación DMARC/BEC de{" "}
                        <strong>{becResult.domain}</strong> a{" "}
                        <strong className="text-white">{becReportSentTo}</strong>.
                      </span>
                    </div>
                  ) : (
                    <form
                      onSubmit={handleSendBecReportEmail}
                      className="flex flex-col sm:flex-row sm:items-center justify-between gap-3"
                    >
                      <div className="text-xs text-white/90">
                        <strong className="text-emerald-300 block">
                          ¿Deseas recibir el informe ejecutivo y plan de remediación?
                        </strong>
                        Déjanos solo tu correo corporativo:
                      </div>
                      <div className="flex items-center gap-1.5 sm:w-72">
                        <input
                          type="email"
                          required
                          value={becEmailInput}
                          onChange={(e) => setBecEmailInput(e.target.value)}
                          placeholder="tu@empresa.cl"
                          className="w-full min-w-0 px-3 py-2 rounded-lg bg-black/40 border border-white/25 text-xs text-white placeholder:text-white/45 focus:outline-none focus:border-emerald-400"
                        />
                        <button
                          type="submit"
                          disabled={becSendingReport}
                          style={{
                            fontFamily: "var(--font-jakarta), sans-serif",
                          }}
                          className="shrink-0 inline-flex items-center gap-1 px-3.5 py-2 rounded-lg bg-white text-[#020617] hover:bg-white/90 text-xs font-bold transition-colors disabled:opacity-60"
                        >
                          {becSendingReport ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <>
                              <span>Enviar</span>
                              <ArrowRight className="w-3.5 h-3.5" />
                            </>
                          )}
                        </button>
                      </div>
                    </form>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </main>
  );
}
