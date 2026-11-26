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
import KeystoneLivingBrand from "@/components/KeystoneLivingBrand";
import KeystoneSpatialTextNav, {
  type SpatialMenuItem,
} from "@/components/KeystoneSpatialTextNav";
import KeystonePartnersMarquee from "@/components/KeystonePartnersMarquee";
import KeystoneSandstormTitle from "@/components/KeystoneSandstormTitle";
import {
  SPATIAL_THEMES,
  DEFAULT_SPATIAL_THEME,
  type SpatialThemeId,
} from "@/config/spatialThemes";
import {
  DEFAULT_SITE_CONFIG,
  type PillarCatalog,
  type ServiceCardOffer,
  type PublicSiteConfig,
} from "@/config/defaultSiteCatalog";

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

const THEME_OPTIONS: Array<{ id: SpatialThemeId; swatch: string; shortName: string }> = [
  { id: "amethyst_slate", swatch: "#7c739e", shortName: "Amethyst" },
  { id: "graphite_mist", swatch: "#64748b", shortName: "Graphite" },
  { id: "glaciar_prev", swatch: "#cbd5e1", shortName: "Glaciar (Ant.)" },
];

export default function HomePage() {
  const [scrollRatio, setScrollRatio] = useState<number>(0);
  const [activeChapterIdx, setActiveChapterIdx] = useState<number>(0);
  const [hudReady, setHudReady] = useState<boolean>(false);
  const [bootPct, setBootPct] = useState<number>(0);

  // Dynamic Mountain Atmosphere Theme & Live Admin Site Configuration
  const [siteConfig, setSiteConfig] = useState<PublicSiteConfig>(DEFAULT_SITE_CONFIG);
  const [themeId, setThemeId] = useState<SpatialThemeId>(DEFAULT_SPATIAL_THEME);
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

  // Restore saved theme preference & hydrate Live Admin Catalog (/api/site_config.php)
  useEffect(() => {
    let hasLocalOverride = false;
    try {
      const saved = window.localStorage.getItem("ks_spatial_theme") as SpatialThemeId | null;
      if (saved && SPATIAL_THEMES[saved]) {
        setThemeId(saved);
        hasLocalOverride = true;
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
          if (!hasLocalOverride && remoteCfg.defaultTheme && SPATIAL_THEMES[remoteCfg.defaultTheme]) {
            setThemeId(remoteCfg.defaultTheme);
          }
        }
      })
      .catch(() => {
        // silent fallback to built-in catalog
      });
  }, []);

  const selectTheme = (nextTheme: SpatialThemeId) => {
    setThemeId(nextTheme);
    setThemePickerOpen(false);
    try {
      window.localStorage.setItem("ks_spatial_theme", nextTheme);
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

  // Deep Seamless Infinite Vertical Circular Scroll Loop (950vh runway for silky transitions!)
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

      // Aligned strictly with computeContinuousMorph plateaus!
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

  const activeChapters = siteConfig.chapters || DEFAULT_SITE_CONFIG.chapters;
  const activePillar = activeChapters[activeChapterIdx] || activeChapters[0];
  const currentThemeObj = SPATIAL_THEMES[themeId] || SPATIAL_THEMES[DEFAULT_SPATIAL_THEME];

  const spatialNavItems: SpatialMenuItem[] = activeChapters.map((ch) => ({
    id: `nav-${ch.chapterIndex}`,
    label: ch.label,
    isActive: ch.chapterIndex === activeChapterIdx,
    onClick: () => jumpToChapter(ch.chapterIndex, ch.chapterIndex >= 1),
  }));

  return (
    <main
      className="relative min-h-[950vh] text-white selection:bg-slate-900 selection:text-white transition-colors duration-700"
      style={{
        fontFamily: "var(--font-inter), sans-serif",
        backgroundColor: currentThemeObj.skyCss,
      }}
    >
      {/* =====================================================================
          1. FULLSCREEN WEBGL WORLD: 72,000 CONTINUOUS PARTICLES OVER TERRAIN
      ====================================================================== */}
      <KeystoneSpatialEngine
        scrollRatio={scrollRatio}
        activeChapter={activeChapterIdx}
        themeId={themeId}
        isDissolved={isDissolved}
        onSculptureClick={handleSculptureClick}
        onSculptureHoverChange={setIsHoveringSculpture}
        onBootProgress={handleBootProgress}
      />

      {!hudReady && (
        <div className="pointer-events-none fixed bottom-8 left-8 z-20 text-[11px] font-medium tracking-[0.20em] text-[#0A0E15]/90 uppercase">
          <div>Keystone Advisors Global</div>
          <div className="text-[#0A0E15]/65 mt-0.5">Cargando entorno {bootPct}%</div>
        </div>
      )}

      {/* =====================================================================
          2. SUBTLE CALIBRATION GRID & ATMOSPHERIC VIGNETTE
      ====================================================================== */}
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
          3. EXECUTIVE SPATIAL INTERFACE
      ====================================================================== */}
      <div
        className={`pointer-events-none fixed inset-0 z-20 flex flex-col justify-between p-4 sm:p-6 lg:px-8 lg:py-6 transition-opacity duration-700 ${
          hudReady ? "opacity-100" : "opacity-0"
        }`}
      >
        {/* TOP ROW: Dark Sandstorm Keystone Logo + Subtle Perimeter Utility Links */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="pointer-events-auto">
            <KeystoneLivingBrand onSelectHome={() => jumpToChapter(0, false)} />
          </div>

          <div className="pointer-events-auto flex flex-wrap items-center gap-2 sm:gap-3.5 text-[11px] sm:text-xs font-semibold text-[#0B0F16]/80">
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
                  backgroundColor: "rgba(11, 15, 23, 0.88)",
                  backdropFilter: "blur(16px)",
                }}
                className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-white text-xs font-semibold border border-white/20 shadow-md hover:bg-black transition-all"
              >
                <X className="w-3.5 h-3.5" />
                <span>Emblema 3D</span>
              </button>
            )}

            <Link
              href="/trust/"
              className="hidden md:inline-flex items-center gap-1.5 hover:text-[#0B0F16] transition-colors"
            >
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-600 animate-pulse" />
              <ShieldCheck className="w-3.5 h-3.5 text-emerald-700" />
              <span>Trust Center</span>
            </Link>

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
                  <Link
                    href="/shield/portal/"
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
                  </Link>
                  <Link
                    href="/shield/portal/?tab=whitelist"
                    className="flex items-start justify-between p-2.5 rounded-lg hover:bg-white/10 transition-colors group"
                  >
                    <div>
                      <div className="text-xs font-semibold text-white">
                        Padrón Proveedores & DMARC
                      </div>
                      <div className="text-[11px] text-white/60">
                        Cuentas verificadas y anti-BEC
                      </div>
                    </div>
                    <ExternalLink className="w-3.5 h-3.5 text-white/40 group-hover:text-white mt-0.5" />
                  </Link>
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
              href={`mailto:${siteConfig.contactEmail}`}
              title={siteConfig.contactEmail}
              className="hidden xl:inline-flex items-center gap-1 hover:text-[#0B0F16] transition-colors"
            >
              <Mail className="w-3.5 h-3.5" />
              <span>{siteConfig.contactEmail}</span>
            </a>

            <a
              href={`https://wa.me/${siteConfig.whatsappNumber}`}
              target="_blank"
              rel="noopener noreferrer"
              title={`WhatsApp Corporativo: ${siteConfig.whatsappDisplay}`}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-[#0B0F16]/12 hover:bg-[#0B0F16] text-[#0B0F16] hover:text-white border border-[#0B0F16]/20 transition-all"
            >
              <MessageCircle className="w-3.5 h-3.5" />
              <span>{siteConfig.whatsappDisplay}</span>
            </a>

            {/* Discreet Atmosphere Preset Switcher (Controlled by Admin Console) */}
            {siteConfig.allowPublicThemeSwitcher && (
              <div ref={themeMenuRef} className="relative">
              <button
                type="button"
                onClick={() => setThemePickerOpen((prev) => !prev)}
                title="Personalizar atmósfera de montaña"
                className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full bg-[#0B0F16]/12 hover:bg-[#0B0F16] text-[#0B0F16] hover:text-white border border-[#0B0F16]/20 transition-all"
              >
                <Palette className="w-3.5 h-3.5" />
              </button>

              {themePickerOpen && (
                <div
                  style={{
                    backgroundColor: "rgba(10, 14, 21, 0.96)",
                    backdropFilter: "blur(20px)",
                  }}
                  className="absolute right-0 top-full mt-2 w-60 rounded-xl border border-white/15 p-2 shadow-2xl z-40 text-left text-white"
                >
                  <div className="px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.12em] text-white/45">
                    Tono de Montañas (Editable en Admin)
                  </div>
                  {THEME_OPTIONS.map((opt) => {
                    const active = themeId === opt.id;
                    return (
                      <button
                        key={opt.id}
                        type="button"
                        onClick={() => selectTheme(opt.id)}
                        className={`w-full flex items-center justify-between px-2.5 py-2 rounded-lg text-xs transition-colors ${
                          active
                            ? "bg-white/15 text-white font-semibold"
                            : "text-white/75 hover:bg-white/10 hover:text-white"
                        }`}
                      >
                        <span className="flex items-center gap-2">
                          <span
                            className="w-3 h-3 rounded-full border border-white/30"
                            style={{ backgroundColor: opt.swatch }}
                          />
                          <span>{SPATIAL_THEMES[opt.id].label}</span>
                        </span>
                        {active && (
                          <span className="w-1.5 h-1.5 rounded-full bg-violet-400" />
                        )}
                      </button>
                    );
                  })}
                </div>
              )}
              </div>
            )}
          </div>
        </div>

        {/* =================================================================
            CENTER STAGE:
            - STATE A (Not Dissolved): Clean Pillar Headline + Click-on-3D Trigger
            - STATE B (Dissolved): Symmetrical Dark Obsidian Glass Service Cards
        ================================================================== */}
        <div className="relative flex-1 flex flex-col items-center justify-center my-2">
          {!isDissolved && (
            <div className="pointer-events-none absolute inset-x-0 top-1/2 -translate-y-1/2 flex items-center justify-between px-0 sm:px-3">
              <button
                type="button"
                onClick={() => jumpToChapter(activeChapterIdx - 1)}
                aria-label="Anterior"
                className="pointer-events-auto group flex items-center gap-2 py-3 px-3 text-[#0A0E15]/80 hover:text-[#0A0E15] transition-all"
              >
                <ChevronLeft className="w-4 h-4 group-hover:-translate-x-1 transition-transform" />
                <span className="hidden sm:inline-block w-9 h-[1px] bg-[#0A0E15]/50 group-hover:w-14 group-hover:bg-[#0A0E15] transition-all" />
              </button>

              <button
                type="button"
                onClick={() => jumpToChapter(activeChapterIdx + 1)}
                aria-label="Siguiente"
                className="pointer-events-auto group flex items-center gap-2 py-3 px-3 text-[#0A0E15]/80 hover:text-[#0A0E15] transition-all"
              >
                <span className="hidden sm:inline-block w-9 h-[1px] bg-[#0A0E15]/50 group-hover:w-14 group-hover:bg-[#0A0E15] transition-all" />
                <ChevronRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
              </button>
            </div>
          )}

          {/* STATE A: CONTEMPLATION VIEW FOR PILLARS 1, 2, 3 */}
          {activeChapterIdx >= 1 && !isDissolved && (
            <div className="w-full max-w-3xl mx-auto flex flex-col items-center justify-between h-full py-0 sm:py-2 text-center">
              {/* Sandstorm Pillar Title matching top-left Keystone Advisors Logo (Zero dark pill box!) */}
              <KeystoneSandstormTitle
                primaryText={activePillar.label}
                secondaryText={activePillar.headline}
                onClick={handleSculptureClick}
              />

              <button
                type="button"
                onClick={handleSculptureClick}
                style={{
                  fontFamily: "var(--font-jakarta), sans-serif",
                  backgroundColor: isHoveringSculpture
                    ? "#ffffff"
                    : "rgba(10, 14, 21, 0.90)",
                  backdropFilter: "blur(16px)",
                }}
                className={`pointer-events-auto group inline-flex items-center gap-2.5 px-5 py-2.5 rounded-full text-xs sm:text-sm font-semibold transition-all duration-300 border shadow-xl ${
                  isHoveringSculpture
                    ? "text-[#0A0E15] border-white scale-105"
                    : "text-white border-white/20 hover:bg-white hover:text-[#0A0E15]"
                }`}
              >
                <Sparkles className="w-3.5 h-3.5 text-violet-300 group-hover:text-[#0A0E15] transition-colors" />
                <span>{activePillar.clickHint}</span>
                <ArrowRight className="w-3.5 h-3.5 group-hover:translate-x-1 transition-transform" />
              </button>
            </div>
          )}

          {/* STATE B: DISSOLVED REVEAL — SYMMETRICAL OBSIDIAN GLASS CARDS */}
          {activeChapterIdx >= 1 && isDissolved && activePillar.cards && (
            <div className="pointer-events-auto w-full max-w-6xl mx-auto px-1 sm:px-4 max-h-[68vh] overflow-y-auto">
              <div className="flex justify-center mb-3 sm:mb-4">
                <KeystoneSandstormTitle
                  primaryText={activePillar.label}
                  secondaryText={activePillar.headline}
                />
              </div>

              <div
                className={`grid grid-cols-1 md:grid-cols-2 ${
                  activePillar.cards.length === 3
                    ? "lg:grid-cols-3 max-w-5xl mx-auto"
                    : "lg:grid-cols-4"
                } gap-3.5 sm:gap-4`}
              >
                {activePillar.cards.map((card) => {
                  const isCardExpanded = expandedCardId === card.id;
                  const submitted = submittedCards[card.id];
                  const isBusy = submittingCardId === card.id;

                  return (
                    <div
                      key={card.id}
                      style={{
                        backgroundColor: card.isFeatured
                          ? "rgba(22, 18, 42, 0.94)"
                          : "rgba(10, 14, 21, 0.91)",
                        backdropFilter: "blur(22px)",
                      }}
                      className={`relative flex flex-col justify-between border ${
                        card.isFeatured
                          ? "border-violet-400/60 shadow-[0_20px_52px_rgba(139,92,246,0.28)]"
                          : isCardExpanded
                          ? activePillar.accentBorderClass || "border-white/40"
                          : "border-white/15 hover:border-white/35 shadow-[0_20px_48px_rgba(8,11,18,0.42)]"
                      } rounded-2xl p-5 text-left transition-all duration-300`}
                    >
                      <div>
                        {card.isFeatured && card.featuredBadge && (
                          <div className="mb-2 flex justify-center">
                            <div className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-violet-500/25 border border-violet-300/40 text-[10px] font-bold uppercase tracking-[0.08em] text-violet-200">
                              <Sparkles className="w-3 h-3 text-violet-300" />
                              <span>{card.featuredBadge}</span>
                            </div>
                          </div>
                        )}
                        <div
                          style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                          className={`text-[11px] font-semibold tracking-[0.06em] uppercase text-center ${
                            activePillar.accentTextClass || "text-sky-300"
                          }`}
                        >
                          {card.tag}
                        </div>

                        <h3
                          style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                          className="text-[16px] sm:text-[17px] font-bold uppercase tracking-[0.02em] text-white mt-1.5 leading-snug text-center"
                        >
                          {card.title}
                        </h3>

                        <p className="text-xs sm:text-[13px] text-white/80 mt-2 leading-relaxed">
                          {card.description}
                        </p>

                        <div className="mt-3.5 pt-3 border-t border-white/12">
                          <span
                            style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                            className="text-sm font-bold text-white"
                          >
                            {card.priceHighlight}
                          </span>
                          {card.priceNote && (
                            <span className="text-[11px] text-white/60 ml-1.5">
                              ({card.priceNote})
                            </span>
                          )}
                        </div>
                      </div>

                      <div className="mt-4">
                        {submitted ? (
                          <div className="rounded-xl bg-emerald-500/15 border border-emerald-400/30 p-3 text-xs text-emerald-200">
                            <div className="flex items-start gap-2">
                              <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                              <span>
                                ¡Listo! Te escribiremos hoy a{" "}
                                <strong className="text-white">
                                  {submitted.email}
                                </strong>{" "}
                                para coordinar.
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
                                className="w-full min-w-0 px-3 py-2 rounded-lg bg-white/10 border border-white/25 text-xs text-white placeholder:text-white/45 focus:outline-none focus:border-white"
                              />
                              <button
                                type="submit"
                                disabled={isBusy}
                                style={{
                                  fontFamily: "var(--font-jakarta), sans-serif",
                                }}
                                className={`shrink-0 inline-flex items-center justify-center px-3 py-2 rounded-lg bg-white text-[#0A0E15] text-xs font-bold transition-colors disabled:opacity-60 ${
                                  activePillar.accentBtnHoverClass ||
                                  "hover:bg-sky-300"
                                }`}
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
                            }}
                            className={`w-full group inline-flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-semibold border transition-all ${
                              card.opensBecScanner
                                ? `bg-emerald-500/20 text-emerald-200 border-emerald-400/40 ${
                                    activePillar.accentBtnHoverClass ||
                                    "hover:bg-emerald-300 hover:text-[#0A0E15] hover:border-emerald-300"
                                  }`
                                : card.isFeatured
                                ? `bg-violet-500/25 text-violet-100 border-violet-400/50 ${
                                    activePillar.accentBtnHoverClass ||
                                    "hover:bg-violet-300 hover:text-[#0A0E15] hover:border-violet-300"
                                  }`
                                : `bg-white/12 text-white border-white/20 ${
                                    activePillar.accentBtnHoverClass ||
                                    "hover:bg-sky-300 hover:text-[#0A0E15] hover:border-sky-300"
                                  }`
                            }`}
                          >
                            <span className="inline-flex items-center gap-1.5">
                              {card.opensBecScanner && (
                                <Radar className="w-3.5 h-3.5" />
                              )}
                              <span>{card.ctaLabel}</span>
                            </span>
                            <ArrowRight className="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform" />
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
            BOTTOM ZONE (NO BAR):
            1) Upper Active Typography Menu ("SUMERGETE"-style basalt grains that illuminate on click & open services)
            2) Lower Seamless Partners & Certifications Marquee blended right over the mountain base
        ================================================================== */}
        <div className="w-full flex flex-col items-center gap-2 sm:gap-3 pt-1">
          {/* 1. Living Basalt-Grain Text Menu (Positioned slightly higher, zero container bar!) */}
          <div className="pointer-events-auto">
            <KeystoneSpatialTextNav items={spatialNavItems} />
          </div>

          {/* Mobile compact legal/trust links */}
          <div className="pointer-events-auto flex md:hidden items-center justify-center gap-4 text-[11px] font-semibold text-[#0B0F16]/75">
            <Link href="/trust/">
              Trust Center
            </Link>
            <span>·</span>
            <button type="button" onClick={() => setLegalModal("terms")}>
              Legal y Cumplimiento
            </button>
          </div>

          {/* 2. Continuous Partners & Official Certifications Marquee across the bottom */}
          <div className="w-full border-t border-[#070B12]/25 pt-2.5">
            <KeystonePartnersMarquee items={siteConfig.partnersMarquee} />
          </div>
        </div>
      </div>

      {/* =====================================================================
          4. EXECUTIVE GLASS MODAL: TERMS, PRIVACY & COMPLIANCE
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
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/65 backdrop-blur-md"
          onClick={() => setBecModalOpen(false)}
        >
          <div
            style={{
              backgroundColor: "rgba(10, 14, 21, 0.97)",
              backdropFilter: "blur(26px)",
            }}
            className="w-full max-w-2xl border border-emerald-400/25 rounded-2xl p-6 sm:p-8 text-white shadow-[0_24px_60px_rgba(0,0,0,0.65)] max-h-[88vh] overflow-y-auto"
            onClick={(e) => e.stopPropagation()}
          >
            {/* Header */}
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

            {/* Domain Input Bar */}
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
                  className="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-400 hover:bg-emerald-300 text-[#0A0E15] text-xs sm:text-sm font-bold transition-colors disabled:opacity-60"
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

            {/* Live Scan Results Dashboard */}
            {becResult && (
              <div className="mt-6 space-y-4 border-t border-white/15 pt-5">
                {/* Score & Risk Banner */}
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

                {/* 4 Protocol Telemetry Pills */}
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

                {/* Key Findings */}
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

                {/* Single-Field Email Capture for Full Remediation Plan */}
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
                          className="shrink-0 inline-flex items-center gap-1 px-3.5 py-2 rounded-lg bg-white text-[#0A0E15] hover:bg-white/90 text-xs font-bold transition-colors disabled:opacity-60"
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
