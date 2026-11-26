"use client";

import React, { useRef, useEffect } from "react";
import ksBrandGrains from "./ksBrandGrains.json";

interface KeystoneLivingBrandProps {
  onSelectHome?: () => void;
}

interface DriftingSandGrain {
  x: number;
  y: number;
  vx: number;
  vy: number;
  life: number;
  maxLife: number;
  size: number;
  spawnIndex: number;
}

export default function KeystoneLivingBrand({
  onSelectHome,
}: KeystoneLivingBrandProps) {
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
    // Add 44px trailing airspace on the right so wind-blown sand grains can drift away smoothly before fading!
    const W = ksBrandGrains.w + 44; // 354
    const H = ksBrandGrains.h + 12; // 88
    canvas.width = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);

    const grains = ksBrandGrains.grains; // [x, y, shade, rad, isEdge]
    const totalGrains = grains.length;

    // Collect edge indices so wind-blown sand sheds naturally off the letter crests & trailing edges
    const edgeIndices: number[] = [];
    for (let i = 0; i < totalGrains; i++) {
      if (grains[i][4] === 1) edgeIndices.push(i);
    }
    const spawnPool = edgeIndices.length > 0 ? edgeIndices : [0];

    // Pool of 260 active wind-swept sandstorm particles detaching & blowing in gusts
    const DRIFT_COUNT = 260;
    const drifters: DriftingSandGrain[] = [];

    const respawnDrifter = (d: DriftingSandGrain, randomizeAge = false) => {
      const idx = spawnPool[Math.floor(Math.random() * spawnPool.length)];
      const src = grains[idx];
      d.spawnIndex = idx;
      d.x = src[0] + 4;
      d.y = src[1] + 6;
      d.vx = 14 + Math.random() * 28; // Wind blows left-to-right across the mountain ridge
      d.vy = (Math.random() - 0.56) * 11; // Slight upward turbulent lift
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
        spawnIndex: 0,
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

      // Traveling sandstorm gust envelope sweeping across the logo from left to right
      const gustCenter = ((t * 95) % (W + 140)) - 70;
      const ptr = pointerRef.current;

      // 1. Render Core Dark Sandstorm Body of KS + KEY STONE ADVISORS
      for (let i = 0; i < totalGrains; i++) {
        const g = grains[i];
        const bx = g[0] + 4;
        const by = g[1] + 6;
        const seed = g[2];
        const rad = g[3];
        const isEdge = g[4];

        // Distance to traveling wind gust front
        const distGust = Math.abs(bx - gustCenter);
        const gustStrength = Math.max(0, 1 - distGust / 58);

        // High-frequency fine sand shimmer + wind-shear turbulence on edges
        const turbAmp = 0.28 + (isEdge ? 0.55 : 0.18) + gustStrength * (isEdge ? 2.6 : 0.95);
        let ox =
          Math.sin(t * 5.2 + by * 0.28 + seed * 12.5) * turbAmp * 0.55 +
          gustStrength * gustStrength * (1.8 + seed * 2.4);
        let oy =
          Math.cos(t * 4.6 + bx * 0.22 + seed * 9.3) * turbAmp * 0.45 -
          gustStrength * (seed - 0.45) * 1.6;

        // Interactive cursor wind-vortex swirl when user hovers over the logo
        if (ptr.active) {
          const dx = bx - ptr.x;
          const dy = by - ptr.y;
          const d = Math.sqrt(dx * dx + dy * dy);
          if (d < 34 && d > 0.01) {
            const force = Math.pow(1 - d / 34, 2) * 6.5;
            // Tangential swirl + outward sand scatter
            ox += (dx / d) * force + (-dy / d) * force * 0.55;
            oy += (dy / d) * force + (dx / d) * force * 0.55;
          }
        }

        // Rich Dark Volcanic Basalt-Sand tones (#0C1017 to #232C3B) so it stands out sharply!
        const shadeVal = seed * 0.55 + gustStrength * 0.45;
        const rC = Math.round(11 + shadeVal * 23);
        const gC = Math.round(15 + shadeVal * 27);
        const bC = Math.round(22 + shadeVal * 36);
        const alpha = Math.max(0.62, 0.96 - gustStrength * (isEdge ? 0.28 : 0.06));

        ctx.fillStyle = `rgba(${rC},${gC},${bC},${alpha.toFixed(2)})`;
        ctx.fillRect(bx + ox - rad * 0.88, by + oy - rad * 0.88, rad * 1.76, rad * 1.76);
      }

      // 2. Render Wind-Swept Loose Sand Grains Detaching & Blowing in Turbulent Eddies
      for (let i = 0; i < DRIFT_COUNT; i++) {
        const d = drifters[i];
        d.life += dt;
        if (d.life >= d.maxLife) {
          respawnDrifter(d, false);
        }

        const progress = d.life / d.maxLife;
        // Turbulent curl-like wind eddy acceleration
        const eddyY = Math.sin(t * 3.8 + d.x * 0.09 + i) * 16.0;
        const gustBoost = 1.0 + Math.max(0, 1 - Math.abs(d.x - gustCenter) / 70) * 1.4;

        d.x += d.vx * gustBoost * dt;
        d.y += (d.vy + eddyY * 0.45) * dt;

        // Smooth fade-in at detachment and gradual sandstorm dissipation into the mountain air
        const alpha = Math.sin(progress * Math.PI) * 0.72;
        ctx.fillStyle = `rgba(16,21,30,${alpha.toFixed(2)})`;
        ctx.fillRect(d.x, d.y, d.size * 1.5, d.size * 1.5);
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
      x: ((e.clientX - rect.left) / Math.max(rect.width, 1)) * (ksBrandGrains.w + 44),
      y: ((e.clientY - rect.top) / Math.max(rect.height, 1)) * (ksBrandGrains.h + 12),
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
      <canvas
        ref={canvasRef}
        className="w-[265px] h-[66px] sm:w-[335px] sm:h-[83px] block"
      />
    </button>
  );
}
