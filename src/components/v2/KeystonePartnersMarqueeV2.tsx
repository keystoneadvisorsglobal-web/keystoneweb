"use client";

import React from "react";
import { Cloud, ShieldCheck, Award, Layers, Cpu, Globe2, Lock } from "lucide-react";
import {
  DEFAULT_PARTNERS_MARQUEE,
  type PartnerMarqueeItem,
} from "@/config/defaultSiteCatalog";

function BadgeIcon({ type }: { type: PartnerMarqueeItem["icon"] }) {
  const cls =
    "w-[17px] h-[17px] sm:w-[19px] sm:h-[19px] shrink-0 text-sky-400 drop-shadow-[0_0_8px_rgba(56,189,248,0.55)]";
  switch (type) {
    case "cloud":
      return <Cloud className={cls} strokeWidth={2.2} />;
    case "shield":
      return <ShieldCheck className={cls} strokeWidth={2.2} />;
    case "award":
      return <Award className={cls} strokeWidth={2.2} />;
    case "cpu":
      return <Cpu className={cls} strokeWidth={2.2} />;
    case "globe":
      return <Globe2 className={cls} strokeWidth={2.2} />;
    case "lock":
      return <Lock className={cls} strokeWidth={2.2} />;
    default:
      return <Layers className={cls} strokeWidth={2.2} />;
  }
}

interface KeystonePartnersMarqueeV2Props {
  items?: PartnerMarqueeItem[];
}

export default function KeystonePartnersMarqueeV2({
  items,
}: KeystonePartnersMarqueeV2Props) {
  const activeItems =
    Array.isArray(items) && items.length > 0 ? items : DEFAULT_PARTNERS_MARQUEE;
  const loopList = [...activeItems, ...activeItems];

  return (
    <div className="pointer-events-auto w-full max-w-7xl mx-auto overflow-hidden ks-marquee-mask py-2 select-none">
      <div className="ks-marquee-track items-center">
        {loopList.map((item, idx) => {
          const inner = (
            <div className="group flex items-center gap-3 mx-7 sm:mx-10 transition-transform duration-300 hover:scale-[1.03]">
              <BadgeIcon type={item.icon} />
              <div className="flex items-baseline gap-2.5 whitespace-nowrap">
                <span
                  style={{
                    fontFamily: "var(--font-jakarta), sans-serif",
                    textShadow: "0 2px 12px rgba(0, 0, 0, 0.9)",
                  }}
                  className="text-xs sm:text-[14.5px] font-extrabold tracking-[0.14em] uppercase text-white group-hover:text-sky-300 transition-colors"
                >
                  {item.name}
                </span>
                <span
                  style={{
                    textShadow: "0 2px 10px rgba(0, 0, 0, 0.9)",
                  }}
                  className="hidden md:inline-block text-[11px] sm:text-xs font-medium tracking-[0.04em] text-sky-200/75 group-hover:text-white transition-colors"
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
