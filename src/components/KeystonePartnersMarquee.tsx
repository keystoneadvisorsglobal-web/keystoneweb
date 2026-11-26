"use client";

import React from "react";
import { Cloud, ShieldCheck, Award, Layers, Cpu, Globe2, Lock } from "lucide-react";
import {
  DEFAULT_PARTNERS_MARQUEE,
  type PartnerMarqueeItem,
} from "@/config/defaultSiteCatalog";

function BadgeIcon({ type }: { type: PartnerMarqueeItem["icon"] }) {
  const cls =
    "w-[18px] h-[18px] sm:w-[20px] sm:h-[20px] shrink-0 text-[#05080D] drop-shadow-[0_1px_0_rgba(255,255,255,0.55)]";
  switch (type) {
    case "cloud":
      return <Cloud className={cls} strokeWidth={2.3} />;
    case "shield":
      return <ShieldCheck className={cls} strokeWidth={2.3} />;
    case "award":
      return <Award className={cls} strokeWidth={2.3} />;
    case "cpu":
      return <Cpu className={cls} strokeWidth={2.3} />;
    case "globe":
      return <Globe2 className={cls} strokeWidth={2.3} />;
    case "lock":
      return <Lock className={cls} strokeWidth={2.3} />;
    default:
      return <Layers className={cls} strokeWidth={2.3} />;
  }
}

interface KeystonePartnersMarqueeProps {
  items?: PartnerMarqueeItem[];
}

export default function KeystonePartnersMarquee({
  items,
}: KeystonePartnersMarqueeProps) {
  const activeItems =
    Array.isArray(items) && items.length > 0 ? items : DEFAULT_PARTNERS_MARQUEE;
  const loopList = [...activeItems, ...activeItems];

  return (
    <div className="pointer-events-auto w-full max-w-7xl mx-auto overflow-hidden ks-marquee-mask py-2.5 select-none">
      <div className="ks-marquee-track items-center">
        {loopList.map((item, idx) => {
          const inner = (
            <div className="group flex items-center gap-3 mx-7 sm:mx-10 transition-transform duration-300 hover:scale-[1.03]">
              <BadgeIcon type={item.icon} />
              <div className="flex items-baseline gap-2.5 whitespace-nowrap">
                <span
                  style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
                  className="ks-black-metallic-text text-sm sm:text-[15.5px] font-black tracking-[0.13em] uppercase"
                >
                  {item.name}
                </span>
                <span
                  style={{
                    textShadow:
                      "0 1px 0 rgba(255, 255, 255, 0.55), 0 0 8px rgba(255, 255, 255, 0.30)",
                  }}
                  className="hidden md:inline-block text-xs sm:text-[12.5px] font-bold tracking-[0.04em] text-[#070B12]/90 group-hover:text-[#020306] transition-colors"
                >
                  {item.role}
                </span>
              </div>
            </div>
          );

          if (item.href) {
            return (
              <a
                key={`${item.name}-${idx}`}
                href={item.href}
                target={item.href.startsWith("http") ? "_blank" : undefined}
                rel={
                  item.href.startsWith("http")
                    ? "noopener noreferrer"
                    : undefined
                }
                className="inline-flex items-center"
              >
                {inner}
              </a>
            );
          }

          return (
            <div key={`${item.name}-${idx}`} className="inline-flex items-center">
              {inner}
            </div>
          );
        })}
      </div>
    </div>
  );
}
