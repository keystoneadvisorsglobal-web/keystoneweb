"use client";

import React, { useRef, useEffect } from "react";

export interface SpatialMenuItem {
  id: string;
  label: string;
  isActive: boolean;
  onClick: () => void;
}

interface KeystoneSpatialTextNavProps {
  items: SpatialMenuItem[];
}

interface Grain {
  itemIdx: number;
  bx: number;
  by: number;
  seed: number;
  rad: number;
}

interface ItemBounds {
  minX: number;
  maxX: number;
}

export default function KeystoneSpatialTextNav({
  items,
}: KeystoneSpatialTextNavProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const itemsRef = useRef<SpatialMenuItem[]>(items);
  const hoverIdxRef = useRef<number>(-1);
  const flashIdxRef = useRef<{ idx: number; time: number }>({
    idx: -1,
    time: -10,
  });
  const boundsRef = useRef<ItemBounds[]>([]);

  itemsRef.current = items;

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const dpr = Math.min(window.devicePixelRatio || 1, 2.5);
    const logicalW = 900;
    const logicalH = 66;
    canvas.width = Math.round(logicalW * dpr);
    canvas.height = Math.round(logicalH * dpr);

    // Offscreen high-res rasterizer to sample dense basalt grains (+4pt larger: 22px!)
    const off = document.createElement("canvas");
    const scale = 2;
    off.width = logicalW * scale;
    off.height = logicalH * scale;
    const octx = off.getContext("2d");
    if (!octx) return;

    octx.clearRect(0, 0, off.width, off.height);
    octx.font = `800 ${22 * scale}px "Avenir Next", "Plus Jakarta Sans", sans-serif`;
    octx.textBaseline = "middle";

    const labels = itemsRef.current.map((it) => it.label.toUpperCase());
    const gap = 52 * scale;
    const widths = labels.map((lbl) => octx.measureText(lbl).width);
    const totalTextW =
      widths.reduce((acc, w) => acc + w, 0) + gap * (labels.length - 1);

    let cursorX = (off.width - totalTextW) * 0.5;
    const centerY = off.height * 0.52;
    const itemBounds: ItemBounds[] = [];
    const grains: Grain[] = [];

    for (let idx = 0; idx < labels.length; idx++) {
      octx.clearRect(0, 0, off.width, off.height);
      octx.fillStyle = "#ffffff";
      octx.fillText(labels[idx], cursorX, centerY);

      const w = widths[idx];
      const startX = Math.max(0, Math.floor(cursorX - 6));
      const endX = Math.min(off.width, Math.ceil(cursorX + w + 6));
      const img = octx.getImageData(0, 0, off.width, off.height).data;

      itemBounds.push({
        minX: cursorX / scale - 16,
        maxX: (cursorX + w) / scale + 16,
      });

      // Sample crisp dense mineral grains across the letter strokes
      const step = 1.65;
      for (let py = 8; py < off.height - 8; py += step) {
        for (let px = startX; px < endX; px += step) {
          const ix = Math.floor(px);
          const iy = Math.floor(py);
          const alpha = img[(iy * off.width + ix) * 4 + 3];
          if (alpha > 95) {
            grains.push({
              itemIdx: idx,
              bx: (px + (Math.random() - 0.5) * 0.9) / scale,
              by: (py + (Math.random() - 0.5) * 0.9) / scale,
              seed: Math.random(),
              rad: 0.72 + Math.random() * 0.42,
            });
          }
        }
      }

      // Draw subtle separator dot in grains between items (not after last)
      if (idx < labels.length - 1) {
        const dotX = (cursorX + w + gap * 0.5) / scale;
        const dotY = centerY / scale;
        for (let k = 0; k < 10; k++) {
          const ang = Math.random() * Math.PI * 2;
          const r = Math.random() * 1.6;
          grains.push({
            itemIdx: -1,
            bx: dotX + Math.cos(ang) * r,
            by: dotY + Math.sin(ang) * r,
            seed: Math.random(),
            rad: 0.68,
          });
        }
      }

      cursorX += w + gap;
    }

    boundsRef.current = itemBounds;

    // Smooth illumination factor per item [0..1]
    const glowLevels = new Float32Array(labels.length);
    let animId: number;

    const render = (now: number) => {
      animId = requestAnimationFrame(render);
      const t = now * 0.001;

      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, logicalW, logicalH);

      const curItems = itemsRef.current;
      const hovered = hoverIdxRef.current;
      const flash = flashIdxRef.current;

      // Update smooth glow per item + draw ambient halo behind active/pressed word
      for (let idx = 0; idx < curItems.length; idx++) {
        const isAct = curItems[idx]?.isActive ? 1.0 : 0.0;
        const isHov = hovered === idx ? 0.65 : 0.0;
        const flashAge = t - flash.time;
        const flashBoost =
          flash.idx === idx && flashAge >= 0 && flashAge < 0.75
            ? Math.sin((1 - flashAge / 0.75) * Math.PI) * 1.0
            : 0.0;

        const targetGlow = Math.min(1.35, Math.max(isAct, isHov) + flashBoost * 0.55);
        glowLevels[idx] += (targetGlow - glowLevels[idx]) * 0.14;

        if (glowLevels[idx] > 0.05 && itemBounds[idx]) {
          const b = itemBounds[idx];
          const cx = (b.minX + b.maxX) * 0.5;
          const cy = logicalH * 0.52;
          const rx = (b.maxX - b.minX) * 0.56;
          const grad = ctx.createRadialGradient(cx, cy, 2, cx, cy, rx);
          const gAlpha = Math.min(0.46, glowLevels[idx] * 0.34);
          grad.addColorStop(0, `rgba(255, 255, 255, ${gAlpha.toFixed(3)})`);
          grad.addColorStop(0.55, `rgba(196, 181, 253, ${(gAlpha * 0.55).toFixed(3)})`);
          grad.addColorStop(1, "rgba(196, 181, 253, 0)");
          ctx.fillStyle = grad;
          ctx.fillRect(b.minX - 18, 0, b.maxX - b.minX + 36, logicalH);
        }
      }

      const gustCenter = ((t * 88) % (logicalW + 160)) - 80;

      for (let i = 0; i < grains.length; i++) {
        const g = grains[i];
        const distGust = Math.abs(g.bx - gustCenter);
        const gust = Math.max(0, 1 - distGust / 52);

        const glow = g.itemIdx >= 0 ? glowLevels[g.itemIdx] : 0;

        // Subtle wind sandstorm micro-motion matching SUMERGETE & top-left logo
        const amp = 0.24 + gust * 0.85 + glow * 0.22;
        const ox = Math.sin(t * 4.4 + g.by * 0.3 + g.seed * 10.0) * amp;
        const oy = Math.cos(t * 3.8 + g.bx * 0.22 + g.seed * 8.4) * amp * 0.65;

        // Base color: exact dark volcanic basalt (#0B0F16 -> #222B3B)
        // Illuminated color (when active/hovered/pressed): Crisp radiant white-pearl glow (#FFFFFF)!
        const baseR = 11 + g.seed * 20;
        const baseG = 15 + g.seed * 24;
        const baseB = 23 + g.seed * 32;

        const clampGlow = Math.min(1, glow);
        const r = Math.round(baseR + (255 - baseR) * clampGlow);
        const gr = Math.round(baseG + (255 - baseG) * clampGlow);
        const b = Math.round(baseB + (255 - baseB) * clampGlow);
        const alpha = g.itemIdx === -1 ? 0.45 : 0.92 + clampGlow * 0.08;

        ctx.fillStyle = `rgba(${r},${gr},${b},${alpha.toFixed(2)})`;
        const sz = g.rad * (1.42 + clampGlow * 0.22);
        ctx.fillRect(g.bx + ox - sz * 0.5, g.by + oy - sz * 0.5, sz, sz);
      }

      // Crisp luminous underline bar beneath the active illuminated item
      for (let idx = 0; idx < curItems.length; idx++) {
        if (glowLevels[idx] > 0.25 && itemBounds[idx]) {
          const b = itemBounds[idx];
          const wSpan = (b.maxX - b.minX - 28) * Math.min(1, glowLevels[idx]);
          const cx = (b.minX + b.maxX) * 0.5;
          ctx.fillStyle = `rgba(255, 255, 255, ${(Math.min(1, glowLevels[idx]) * 0.85).toFixed(2)})`;
          ctx.fillRect(cx - wSpan * 0.5, logicalH - 8, wSpan, 1.6);
        }
      }
    };

    animId = requestAnimationFrame(render);
    return () => cancelAnimationFrame(animId);
  }, []);

  const resolveHoveredIndex = (clientX: number): number => {
    const canvas = canvasRef.current;
    if (!canvas) return -1;
    const rect = canvas.getBoundingClientRect();
    const x = ((clientX - rect.left) / Math.max(rect.width, 1)) * 900;
    const bList = boundsRef.current;
    for (let i = 0; i < bList.length; i++) {
      if (x >= bList[i].minX && x <= bList[i].maxX) {
        return i;
      }
    }
    return -1;
  };

  const handleMouseMove = (e: React.MouseEvent<HTMLCanvasElement>) => {
    const idx = resolveHoveredIndex(e.clientX);
    hoverIdxRef.current = idx;
    if (canvasRef.current) {
      canvasRef.current.style.cursor = idx >= 0 ? "pointer" : "default";
    }
  };

  const handleMouseLeave = () => {
    hoverIdxRef.current = -1;
  };

  const handleClick = (e: React.MouseEvent<HTMLCanvasElement>) => {
    const idx = resolveHoveredIndex(e.clientX);
    if (idx >= 0 && itemsRef.current[idx]) {
      flashIdxRef.current = { idx, time: performance.now() * 0.001 };
      itemsRef.current[idx].onClick();
    }
  };

  return (
    <div className="w-full flex flex-col items-center">
      {/* Desktop / Tablet Living Basalt-Particle Typography Menu (+4pt larger) */}
      <canvas
        ref={canvasRef}
        onMouseMove={handleMouseMove}
        onMouseLeave={handleMouseLeave}
        onClick={handleClick}
        className="hidden sm:block w-[620px] md:w-[800px] lg:w-[900px] h-[46px] md:h-[58px] lg:h-[66px] select-none"
      />

      {/* Compact Mobile Fallback with identical typography & illumination */}
      <nav
        aria-label="Navegación Principal"
        className="flex sm:hidden flex-wrap items-center justify-center gap-3.5 px-2 py-1"
      >
        {items.map((item) => (
          <button
            key={item.id}
            type="button"
            onClick={item.onClick}
            style={{ fontFamily: "var(--font-jakarta), sans-serif" }}
            className={`text-base font-extrabold uppercase tracking-[0.12em] transition-all ${
              item.isActive
                ? "text-white drop-shadow-[0_0_10px_rgba(255,255,255,0.9)] underline underline-offset-4"
                : "text-[#0B0F16]/85 hover:text-white"
            }`}
          >
            {item.label}
          </button>
        ))}
      </nav>
    </div>
  );
}
