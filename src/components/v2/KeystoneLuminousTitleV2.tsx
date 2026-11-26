"use client";

import React from "react";
import { Sparkles } from "lucide-react";
import { V2_PILLAR_HEADERS } from "@/config/v2SalesCatalog";

interface KeystoneLuminousTitleV2Props {
  chapterIndex?: number;
  primaryText: string;
  secondaryText?: string;
  compact?: boolean;
  onClick?: () => void;
}

export default function KeystoneLuminousTitleV2({
  chapterIndex = 1,
  primaryText,
  secondaryText,
  compact = false,
  onClick,
}: KeystoneLuminousTitleV2Props) {
  const hdr = V2_PILLAR_HEADERS[chapterIndex] || V2_PILLAR_HEADERS[1];

  return (
    <div
      onClick={onClick}
      role={onClick ? "button" : undefined}
      tabIndex={onClick ? 0 : undefined}
      onKeyDown={(e) => {
        if (onClick && (e.key === "Enter" || e.key === " ")) onClick();
      }}
      style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
      className={`pointer-events-auto select-none flex flex-col items-center text-center px-4 relative group max-w-4xl mx-auto ${
        compact ? "py-0.5" : "py-2"
      } ${onClick ? "cursor-pointer" : ""}`}
    >
      {/* Subtle dark readability cushion */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -inset-x-10 -inset-y-3 rounded-full"
        style={{
          background:
            "radial-gradient(ellipse at 50% 50%, rgba(2, 6, 18, 0.80) 0%, rgba(2, 6, 18, 0.35) 60%, transparent 85%)",
        }}
      />

      {/* Kicker pill */}
      <div
        style={{
          backgroundColor: hdr.kickerBg,
          borderColor: hdr.kickerBorder,
          color: hdr.kickerColor,
        }}
        className={`relative z-10 inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full border font-bold uppercase tracking-[0.16em] backdrop-blur-md ${
          compact ? "text-[9.5px] mb-1.5" : "text-[10.5px] sm:text-[11px] mb-2.5"
        }`}
      >
        <Sparkles className="w-3 h-3 shrink-0" />
        <span>{hdr.kicker}</span>
      </div>

      {/* Main Headline — Never clipped, crisp metallic-jewel gradient */}
      <h2
        style={{
          textRendering: "geometricPrecision",
          WebkitFontSmoothing: "antialiased",
          backgroundImage: hdr.titleGradient,
          WebkitBackgroundClip: "text",
          backgroundClip: "text",
          color: "transparent",
          filter: "drop-shadow(0 4px 16px rgba(0, 0, 0, 0.95))",
        }}
        className={`relative z-10 font-extrabold tracking-[0.02em] leading-tight transition-transform duration-300 group-hover:scale-[1.01] ${
          compact
            ? "text-lg sm:text-2xl lg:text-[28px]"
            : "text-2xl sm:text-4xl lg:text-[42px]"
        }`}
      >
        {secondaryText || primaryText}
      </h2>

      {!compact && hdr.subheadline && (
        <p
          style={{
            textRendering: "geometricPrecision",
            WebkitFontSmoothing: "antialiased",
            textShadow: "0 2px 12px rgba(0, 0, 0, 0.95)",
          }}
          className="relative z-10 mt-2 max-w-2xl text-xs sm:text-sm lg:text-[15px] font-medium text-slate-200/90 leading-relaxed"
        >
          {hdr.subheadline}
        </p>
      )}
    </div>
  );
}
