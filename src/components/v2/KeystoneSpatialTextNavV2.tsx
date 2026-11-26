"use client";

import React from "react";
import type { SpatialMenuItem } from "../KeystoneSpatialTextNav";

interface KeystoneSpatialTextNavV2Props {
  items: SpatialMenuItem[];
}

const NAV_TONES: Record<
  number,
  {
    aura: string;
    underline: string;
    shadow: string;
    dot: string;
  }
> = {
  0: {
    aura: "radial-gradient(ellipse at 50% 55%, rgba(56, 189, 248, 0.25) 0%, transparent 78%)",
    underline:
      "linear-gradient(90deg, transparent 0%, #38bdf8 28%, #ffffff 50%, #38bdf8 72%, transparent 100%)",
    shadow: "0 0 12px rgba(56, 189, 248, 0.9)",
    dot: "bg-sky-400/60 border-sky-200/50",
  },
  1: {
    aura: "radial-gradient(ellipse at 50% 55%, rgba(34, 211, 238, 0.26) 0%, transparent 78%)",
    underline:
      "linear-gradient(90deg, transparent 0%, #22d3ee 28%, #fde68a 50%, #38bdf8 72%, transparent 100%)",
    shadow: "0 0 14px rgba(34, 211, 238, 0.9)",
    dot: "bg-cyan-400/60 border-cyan-200/50",
  },
  2: {
    aura: "radial-gradient(ellipse at 50% 55%, rgba(52, 211, 153, 0.28) 0%, transparent 78%)",
    underline:
      "linear-gradient(90deg, transparent 0%, #34d399 28%, #ffffff 50%, #10b981 72%, transparent 100%)",
    shadow: "0 0 14px rgba(52, 211, 153, 0.9)",
    dot: "bg-emerald-400/60 border-emerald-200/50",
  },
  3: {
    aura: "radial-gradient(ellipse at 50% 55%, rgba(167, 139, 250, 0.28) 0%, transparent 78%)",
    underline:
      "linear-gradient(90deg, transparent 0%, #a78bfa 28%, #fde68a 50%, #c084fc 72%, transparent 100%)",
    shadow: "0 0 14px rgba(167, 139, 250, 0.9)",
    dot: "bg-violet-400/60 border-violet-200/50",
  },
};

export default function KeystoneSpatialTextNavV2({
  items,
}: KeystoneSpatialTextNavV2Props) {
  return (
    <div className="w-full flex flex-col items-center">
      <nav
        aria-label="Navegación Principal"
        style={{
          background:
            "linear-gradient(180deg, rgba(6, 15, 36, 0.80) 0%, rgba(2, 6, 18, 0.94) 100%)",
          backdropFilter: "blur(22px)",
          WebkitBackdropFilter: "blur(22px)",
          fontFamily: "var(--font-jakarta), sans-serif",
        }}
        className="relative flex flex-wrap sm:flex-nowrap items-center justify-center gap-y-2 px-5 sm:px-9 md:px-12 py-3 sm:py-3.5 rounded-2xl sm:rounded-full border border-white/15 shadow-[0_16px_44px_rgba(0,0,0,0.82),inset_0_1px_0_rgba(255,255,255,0.12)]"
      >
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-x-10 top-0 h-[1px]"
          style={{
            background:
              "linear-gradient(90deg, transparent 0%, rgba(56,189,248,0.6) 28%, rgba(52,211,153,0.6) 52%, rgba(167,139,250,0.6) 76%, transparent 100%)",
          }}
        />

        {items.map((item, idx) => {
          const active = item.isActive;
          const tone = NAV_TONES[idx] || NAV_TONES[0];
          return (
            <React.Fragment key={item.id}>
              <button
                type="button"
                onClick={item.onClick}
                className="group relative px-3 sm:px-5 md:px-6 py-1.5 focus:outline-none select-none transition-transform duration-300 active:scale-[0.98]"
              >
                <span
                  aria-hidden="true"
                  className={`pointer-events-none absolute inset-0 rounded-full transition-opacity duration-500 ${
                    active ? "opacity-100" : "opacity-0 group-hover:opacity-70"
                  }`}
                  style={{ background: tone.aura }}
                />

                <span
                  style={{
                    textRendering: "geometricPrecision",
                    WebkitFontSmoothing: "antialiased",
                    textShadow: active
                      ? "0 0 22px rgba(255, 255, 255, 0.45), 0 2px 10px rgba(0, 0, 0, 0.9)"
                      : "0 2px 10px rgba(0, 0, 0, 0.9)",
                  }}
                  className={`relative z-10 block text-[18px] sm:text-[22px] md:text-[25px] lg:text-[28px] leading-none uppercase tracking-[0.18em] transition-all duration-300 ${
                    active
                      ? "font-bold text-white"
                      : "font-semibold text-slate-300/85 group-hover:text-white"
                  }`}
                >
                  {item.label}
                </span>

                <span
                  aria-hidden="true"
                  className={`pointer-events-none absolute left-1/2 -translate-x-1/2 -bottom-1 h-[2.2px] rounded-full transition-all duration-300 ${
                    active
                      ? "w-4/5 opacity-100"
                      : "w-0 opacity-0 group-hover:w-3/5 group-hover:opacity-75"
                  }`}
                  style={{
                    background: tone.underline,
                    boxShadow: tone.shadow,
                  }}
                />
              </button>

              {idx < items.length - 1 && (
                <span
                  aria-hidden="true"
                  className={`hidden sm:inline-block w-1.5 h-1.5 rotate-45 mx-1 md:mx-2 border shadow-sm shrink-0 ${tone.dot}`}
                />
              )}
            </React.Fragment>
          );
        })}
      </nav>
    </div>
  );
}
