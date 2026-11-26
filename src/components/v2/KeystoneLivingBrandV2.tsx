"use client";

import React, { useRef, useEffect } from "react";
import ksBrandGrains from "../ksBrandGrains.json";

interface KeystoneLivingBrandV2Props {
  onSelectHome?: () => void;
}

interface DriftingPhoton {
  x: number;
  y: number;
  vx: number;
  vy: number;
  life: number;
  maxLife: number;
  size: number;
  isCyan: boolean;
}

export default function KeystoneLivingBrandV2({
  onSelectHome,
}: KeystoneLivingBrandV2Props) {
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
    const W = ksBrandGrains.w + 52; // 362
    const H = ksBrandGrains.h + 16; // 92
    canvas.width = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);

    const grains = ksBrandGrains.grains; // [x, y, shade, rad, isEdge]
    const totalGrains = grains.length;

    const edgeIndices: number[] = [];
    for (let i = 0; i < totalGrains; i++) {
      if (grains[i][4] === 1) edgeIndices.push(i);
    }
    const spawnPool = edgeIndices.length > 0 ? edgeIndices : [0];

    const DRIFT_COUNT = 235;
    const drifters: DriftingPhoton[] = [];

    const respawnDrifter = (d: DriftingPhoton, randomizeAge = false) => {
      const idx = spawnPool[Math.floor(Math.random() * spawnPool.length)];
      const src = grains[idx];
      d.x = src[0] + 6;
      d.y = src[1] + 8;
      d.vx = 13 + Math.random() * 28;
      d.vy = (Math.random() - 0.54) * 9.5;
      d.maxLife = 0.95 + Math.random() * 1.25;
      d.life = randomizeAge ? Math.random() * d.maxLife : 0;
      d.size = 0.72 + Math.random() * 0.68;
      d.isCyan = Math.random() > 0.32;
    };

    for (let i = 0; i < DRIFT_COUNT; i++) {
      const d: DriftingPhoton = {
        x: 0,
        y: 0,
        vx: 0,
        vy: 0,
        life: 0,
        maxLife: 1,
        size: 0.85,
        isCyan: true,
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

      // 1. Subtle deep obsidian contrast cushion so the brighter effects pop cleanly
      const bgGlow = ctx.createRadialGradient(
        W * 0.42,
        H * 0.5,
        8,
        W * 0.44,
        H * 0.5,
        W * 0.54
      );
      bgGlow.addColorStop(0, "rgba(2, 6, 18, 0.52)");
      bgGlow.addColorStop(0.72, "rgba(2, 6, 18, 0.20)");
      bgGlow.addColorStop(1, "rgba(2, 6, 18, 0)");
      ctx.fillStyle = bgGlow;
      ctx.fillRect(0, 0, W, H);

      const pulseCenter = ((t * 90) % (W + 145)) - 72;
      const ptr = pointerRef.current;

      // 2. Refined traveling sapphire-cyan aura following the photon wave (restrained, elegant)
      const waveAura = ctx.createRadialGradient(
        Math.max(12, Math.min(W - 30, pulseCenter)),
        H * 0.48,
        2,
        Math.max(12, Math.min(W - 30, pulseCenter)),
        H * 0.48,
        68
      );
      waveAura.addColorStop(0, "rgba(125, 211, 252, 0.16)");
      waveAura.addColorStop(0.55, "rgba(56, 189, 248, 0.06)");
      waveAura.addColorStop(1, "rgba(56, 189, 248, 0)");
      ctx.fillStyle = waveAura;
      ctx.fillRect(0, 0, W, H);

      // 3. Render Brighter Diamond-White & Electric Ice-Cyan Body of KS + KEY STONE ADVISORS
      for (let i = 0; i < totalGrains; i++) {
        const g = grains[i];
        const bx = g[0] + 6;
        const by = g[1] + 8;
        const seed = g[2];
        const rad = g[3];
        const isEdge = g[4];

        const distPulse = Math.abs(bx - pulseCenter);
        const pulseStrength = Math.max(0, 1 - distPulse / 58);

        const turbAmp =
          0.17 + (isEdge ? 0.36 : 0.11) + pulseStrength * (isEdge ? 1.85 : 0.62);
        let ox =
          Math.sin(t * 4.4 + by * 0.26 + seed * 12.5) * turbAmp * 0.5 +
          pulseStrength * pulseStrength * (1.35 + seed * 1.8);
        let oy =
          Math.cos(t * 3.9 + bx * 0.2 + seed * 9.3) * turbAmp * 0.4 -
          pulseStrength * (seed - 0.45) * 1.2;

        if (ptr.active) {
          const dx = bx - ptr.x;
          const dy = by - ptr.y;
          const d = Math.sqrt(dx * dx + dy * dy);
          if (d < 36 && d > 0.01) {
            const force = Math.pow(1 - d / 36, 2) * 5.8;
            ox += (dx / d) * force + (-dy / d) * force * 0.5;
            oy += (dy / d) * force + (dx / d) * force * 0.5;
          }
        }

        // Elevated luminance: crisp pure diamond white (#FFFFFF) with bright ice-cyan shimmer on wave crests
        const cyanShimmer = pulseStrength * 0.52 + (isEdge ? 0.14 : 0.0) * seed;
        const rC = Math.round(255 - cyanShimmer * 72);
        const gC = Math.round(255 - cyanShimmer * 10);
        const bC = 255;
        const alpha = Math.min(1.0, Math.max(0.88, 1.0 - pulseStrength * (isEdge ? 0.10 : 0.0)));

        // Subtle grain expansion on the traveling light crest so the wave glistens cleanly
        const sizeBoost = 1.84 + pulseStrength * 0.22;
        ctx.fillStyle = `rgba(${rC},${gC},${bC},${alpha.toFixed(2)})`;
        ctx.fillRect(
          bx + ox - rad * (sizeBoost * 0.5),
          by + oy - rad * (sizeBoost * 0.5),
          rad * sizeBoost,
          rad * sizeBoost
        );
      }

      // 4. Render Slightly Brighter Luminous Photon Micro-Stream Detaching Smoothly
      for (let i = 0; i < DRIFT_COUNT; i++) {
        const d = drifters[i];
        d.life += dt;
        if (d.life >= d.maxLife) {
          respawnDrifter(d, false);
        }

        const progress = d.life / d.maxLife;
        const eddyY = Math.sin(t * 3.4 + d.x * 0.08 + i) * 13.0;
        const boost =
          1.0 + Math.max(0, 1 - Math.abs(d.x - pulseCenter) / 72) * 1.28;

        d.x += d.vx * boost * dt;
        d.y += (d.vy + eddyY * 0.42) * dt;

        const alpha = Math.min(0.95, Math.sin(progress * Math.PI) * 0.92);
        ctx.fillStyle = d.isCyan
          ? `rgba(125, 211, 252, ${alpha.toFixed(2)})`
          : `rgba(255, 255, 255, ${alpha.toFixed(2)})`;
        ctx.fillRect(d.x, d.y, d.size * 1.55, d.size * 1.55);
      }
    };

    animId = requestAnimationFrame(render);
    return () => cancelAnimationFrame(animId);
  }, []);

  const handleMouseMove = (e: React.MouseEvent<HTMLButtonElement>) => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const rect = canvas.getBoundingClientRect();
    pointerRef.current = {
      x: ((e.clientX - rect.left) / Math.max(rect.width, 1)) * (ksBrandGrains.w + 52),
      y: ((e.clientY - rect.top) / Math.max(rect.height, 1)) * (ksBrandGrains.h + 16),
      active: true,
    };
  };

  const handleMouseLeave = () => {
    pointerRef.current.active = false;
  };

  return (
    <button
      type="button"
      onClick={onSelectHome}
      onMouseMove={handleMouseMove}
      onMouseLeave={handleMouseLeave}
      aria-label="Keystone Advisors"
      className="block focus:outline-none select-none cursor-pointer"
    >
      {/* +30% Larger Dimensions (was 265x66 / sm:338x85 -> now 345x86 / sm:440x110) + tasteful sapphire bloom */}
      <canvas
        ref={canvasRef}
        className="w-[325px] h-[82px] sm:w-[405px] sm:h-[103px] lg:w-[440px] lg:h-[112px] block drop-shadow-[0_0_12px_rgba(125,211,252,0.28)] drop-shadow-[0_3px_14px_rgba(2,8,23,0.95)]"
      />
    </button>
  );
}
