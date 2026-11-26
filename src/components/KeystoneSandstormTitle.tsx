"use client";

import React, { useRef, useEffect } from "react";

interface KeystoneSandstormTitleProps {
  /** Top bold line matching "KEY STONE" in KeystoneLivingBrand (e.g. "DIGITAL CORE") */
  primaryText: string;
  /** Optional spaced secondary line matching "A D V I S O R S" (e.g. "IMPULSA TUS VENTAS Y AUTOMATIZA TU NEGOCIO") */
  secondaryText?: string;
  onClick?: () => void;
}

interface SandGrain {
  bx: number;
  by: number;
  seed: number;
  rad: number;
  isEdge: number;
}

interface DriftingSandGrain {
  x: number;
  y: number;
  vx: number;
  vy: number;
  life: number;
  maxLife: number;
  size: number;
}

export default function KeystoneSandstormTitle({
  primaryText,
  secondaryText,
  onClick,
}: KeystoneSandstormTitleProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const pointerRef = useRef<{ x: number; y: number; active: boolean }>({
    x: -999,
    y: -999,
    active: false,
  });

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const dpr = Math.min(window.devicePixelRatio || 1, 2.5);
    const W = 740;
    const H = 92;
    canvas.width = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);

    // High-res offscreen rasterizer using exact logo typography (Avenir Next Heavy + Medium)
    const scale = 2;
    const off = document.createElement("canvas");
    off.width = W * scale;
    off.height = H * scale;
    const octx = off.getContext("2d");
    if (!octx) return;

    octx.clearRect(0, 0, off.width, off.height);
    octx.fillStyle = "#ffffff";
    octx.textAlign = "center";
    octx.textBaseline = "middle";

    // Line 1: Bold primary title matching "KEY STONE"
    const pUpper = primaryText.toUpperCase();
    octx.font = `800 ${31 * scale}px "Avenir Next", "Plus Jakarta Sans", sans-serif`;
    octx.fillText(pUpper, off.width * 0.5, secondaryText ? 34 * scale : 46 * scale);

    // Line 2: Spaced secondary subtitle matching "A D V I S O R S"
    if (secondaryText) {
      const sUpper = secondaryText.toUpperCase();
      octx.font = `600 ${12.5 * scale}px "Avenir Next", "Plus Jakarta Sans", sans-serif`;
      // Draw with refined wide tracking
      const chars = sUpper.split("");
      const tracking = 3.2 * scale;
      const charWidths = chars.map((ch) => octx.measureText(ch).width);
      const totalSubW =
        charWidths.reduce((a, b) => a + b, 0) + tracking * Math.max(0, chars.length - 1);
      let cx = (off.width - totalSubW) * 0.5;
      const sy = 67 * scale;
      octx.textAlign = "left";
      for (let i = 0; i < chars.length; i++) {
        octx.fillText(chars[i], cx, sy);
        cx += charWidths[i] + tracking;
      }
    }

    const imgData = octx.getImageData(0, 0, off.width, off.height).data;
    const ow = off.width;
    const oh = off.height;

    const alphaAt = (x: number, y: number): number => {
      if (x < 0 || x >= ow || y < 0 || y >= oh) return 0;
      return imgData[(y * ow + x) * 4 + 3];
    };

    const grains: SandGrain[] = [];
    const edgeIndices: number[] = [];

    // Sample dense basalt sand grains across strokes + detect letter edges for wind shedding!
    const step = 1.65;
    for (let py = 6; py < oh - 6; py += step) {
      for (let px = 12; px < ow - 40; px += step) {
        const ix = Math.floor(px);
        const iy = Math.floor(py);
        const a = alphaAt(ix, iy);
        if (a > 90) {
          const isSub = iy > 52 * scale;
          const isEdge =
            alphaAt(ix + 2, iy) < 80 ||
            alphaAt(ix - 2, iy) < 80 ||
            alphaAt(ix, iy + 2) < 80 ||
            alphaAt(ix, iy - 2) < 80
              ? 1
              : 0;

          const idx = grains.length;
          grains.push({
            bx: (px + (Math.random() - 0.5) * 0.85) / scale,
            by: (py + (Math.random() - 0.5) * 0.85) / scale,
            seed: Math.random(),
            rad: isSub ? 0.66 + Math.random() * 0.24 : 0.78 + Math.random() * 0.34,
            isEdge,
          });
          if (isEdge === 1) edgeIndices.push(idx);
        }
      }
    }

    const spawnPool = edgeIndices.length > 0 ? edgeIndices : [0];
    const DRIFT_COUNT = 280;
    const drifters: DriftingSandGrain[] = [];

    const respawnDrifter = (d: DriftingSandGrain, randomizeAge = false) => {
      if (grains.length === 0) return;
      const gIdx = spawnPool[Math.floor(Math.random() * spawnPool.length)];
      const src = grains[gIdx] || grains[0];
      d.x = src.bx;
      d.y = src.by;
      d.vx = 16 + Math.random() * 32; // Wind blowing left-to-right just like KeystoneLivingBrand!
      d.vy = (Math.random() - 0.55) * 11;
      d.maxLife = 0.85 + Math.random() * 1.15;
      d.life = randomizeAge ? Math.random() * d.maxLife : 0;
      d.size = 0.65 + Math.random() * 0.65;
    };

    for (let i = 0; i < DRIFT_COUNT; i++) {
      const d: DriftingSandGrain = {
        x: 0,
        y: 0,
        vx: 0,
        vy: 0,
        life: 0,
        maxLife: 1,
        size: 0.8,
      };
      respawnDrifter(d, true);
      drifters.push(d);
    }

    let animId: number;
    let lastTime = performance.now();

    const render = (now: number) => {
      animId = requestAnimationFrame(render);
      const dt = Math.min(0.05, (now - lastTime) * 0.001);
      lastTime = now;
      const t = now * 0.001;

      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);

      const gustCenter = ((t * 115) % (W + 160)) - 80;
      const ptr = pointerRef.current;

      // 1. Render Core Dark Basalt Sandstorm Body (identical palette to KeystoneLivingBrand)
      for (let i = 0; i < grains.length; i++) {
        const g = grains[i];
        const distGust = Math.abs(g.bx - gustCenter);
        const gustStrength = Math.max(0, 1 - distGust / 68);

        const turbAmp =
          0.26 + (g.isEdge ? 0.55 : 0.18) + gustStrength * (g.isEdge ? 2.5 : 0.92);
        let ox =
          Math.sin(t * 5.2 + g.by * 0.28 + g.seed * 12.5) * turbAmp * 0.55 +
          gustStrength * gustStrength * (1.8 + g.seed * 2.4);
        let oy =
          Math.cos(t * 4.6 + g.bx * 0.22 + g.seed * 9.3) * turbAmp * 0.45 -
          gustStrength * (g.seed - 0.45) * 1.6;

        if (ptr.active) {
          const dx = g.bx - ptr.x;
          const dy = g.by - ptr.y;
          const dist = Math.sqrt(dx * dx + dy * dy);
          if (dist < 38 && dist > 0.01) {
            const force = Math.pow(1 - dist / 38, 2) * 6.5;
            ox += (dx / dist) * force + (-dy / dist) * force * 0.55;
            oy += (dy / dist) * force + (dx / dist) * force * 0.55;
          }
        }

        const shadeVal = g.seed * 0.55 + gustStrength * 0.45;
        const rC = Math.round(11 + shadeVal * 23);
        const gC = Math.round(15 + shadeVal * 27);
        const bC = Math.round(22 + shadeVal * 36);
        const alpha = Math.max(0.62, 0.96 - gustStrength * (g.isEdge ? 0.28 : 0.06));

        ctx.fillStyle = `rgba(${rC},${gC},${bC},${alpha.toFixed(2)})`;
        ctx.fillRect(g.bx + ox - g.rad * 0.88, g.by + oy - g.rad * 0.88, g.rad * 1.76, g.rad * 1.76);
      }

      // 2. Render Wind-Swept Loose Sand Grains Detaching & Blowing in Turbulent Eddies
      for (let i = 0; i < DRIFT_COUNT; i++) {
        const d = drifters[i];
        d.life += dt;
        if (d.life >= d.maxLife) {
          respawnDrifter(d, false);
        }

        const progress = d.life / d.maxLife;
        const eddyY = Math.sin(t * 3.8 + d.x * 0.09 + i) * 16.0;
        const gustBoost = 1.0 + Math.max(0, 1 - Math.abs(d.x - gustCenter) / 75) * 1.4;

        d.x += d.vx * gustBoost * dt;
        d.y += (d.vy + eddyY * 0.45) * dt;

        const alpha = Math.sin(progress * Math.PI) * 0.72;
        ctx.fillStyle = `rgba(15,20,29,${alpha.toFixed(2)})`;
        ctx.fillRect(d.x, d.y, d.size * 1.5, d.size * 1.5);
      }
    };

    animId = requestAnimationFrame(render);
    return () => cancelAnimationFrame(animId);
  }, [primaryText, secondaryText]);

  const handleMouseMove = (e: React.MouseEvent<HTMLDivElement>) => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const rect = canvas.getBoundingClientRect();
    pointerRef.current = {
      x: ((e.clientX - rect.left) / Math.max(rect.width, 1)) * 740,
      y: ((e.clientY - rect.top) / Math.max(rect.height, 1)) * 92,
      active: true,
    };
  };

  const handleMouseLeave = () => {
    pointerRef.current.active = false;
  };

  return (
    <div
      onClick={onClick}
      onMouseMove={handleMouseMove}
      onMouseLeave={handleMouseLeave}
      role={onClick ? "button" : undefined}
      tabIndex={onClick ? 0 : undefined}
      onKeyDown={(e) => {
        if (onClick && (e.key === "Enter" || e.key === " ")) onClick();
      }}
      className={`pointer-events-auto select-none flex justify-center ${
        onClick ? "cursor-pointer" : ""
      }`}
    >
      <canvas
        ref={canvasRef}
        className="w-[330px] h-[41px] sm:w-[580px] sm:h-[72px] lg:w-[700px] lg:h-[87px] block"
      />
    </div>
  );
}
