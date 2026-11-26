"use client";

import React, { useRef, useEffect } from "react";
import * as THREE from "three";

export type CyberThemeId = "sapphire_core" | "cobalt_quantum" | "arctic_cyber";

export interface CyberAtmospherePreset {
  id: CyberThemeId;
  label: string;
  swatch: string;
  voidHex: number;
  horizonHex: number;
  primaryBlueHex: number;
  electricCyanHex: number;
  deepAccentHex: number;
  exposure: number;
}

export const CYBER_THEMES_V2: Record<CyberThemeId, CyberAtmospherePreset> = {
  sapphire_core: {
    id: "sapphire_core",
    label: "Sapphire Quantum Tunnel",
    swatch: "#0ea5e9",
    voidHex: 0x020510,
    horizonHex: 0x051436,
    primaryBlueHex: 0x0284c7,
    electricCyanHex: 0x38bdf8,
    deepAccentHex: 0x1d4ed8,
    exposure: 1.08,
  },
  cobalt_quantum: {
    id: "cobalt_quantum",
    label: "Cobalt Deep Horizon",
    swatch: "#3b82f6",
    voidHex: 0x02040d,
    horizonHex: 0x08163b,
    primaryBlueHex: 0x2563eb,
    electricCyanHex: 0x60a5fa,
    deepAccentHex: 0x4338ca,
    exposure: 1.06,
  },
  arctic_cyber: {
    id: "arctic_cyber",
    label: "Arctic Cyber Light",
    swatch: "#22d3ee",
    voidHex: 0x020712,
    horizonHex: 0x061d38,
    primaryBlueHex: 0x0891b2,
    electricCyanHex: 0x67e8f9,
    deepAccentHex: 0x0369a1,
    exposure: 1.08,
  },
};

export interface KeystoneDigitalTunnelEngineProps {
  scrollRatio: number;
  activeChapter: number;
  cyberThemeId?: CyberThemeId;
  isDissolved?: boolean;
  onSculptureClick?: () => void;
  onSculptureHoverChange?: (hovering: boolean) => void;
  onBootProgress?: (bootProg: number, hudReady: boolean) => void;
}

const BOOT_DURATION = 2.6;

function computeContinuousMorph(r: number): number {
  const s = ((r % 1.0) + 1.0) % 1.0;
  if (s < 0.11) return 0.0;
  if (s < 0.24) return THREE.MathUtils.smoothstep(s, 0.11, 0.24);
  if (s < 0.37) return 1.0;
  if (s < 0.50) return 1.0 + THREE.MathUtils.smoothstep(s, 0.37, 0.50);
  if (s < 0.63) return 2.0;
  if (s < 0.76) return 2.0 + THREE.MathUtils.smoothstep(s, 0.63, 0.76);
  if (s < 0.88) return 3.0;
  const backT = THREE.MathUtils.smoothstep(s, 0.88, 0.995);
  return 3.0 * (1.0 - backT);
}

export default function KeystoneDigitalTunnelEngine({
  scrollRatio,
  activeChapter,
  cyberThemeId = "sapphire_core",
  isDissolved = false,
  onSculptureClick,
  onSculptureHoverChange,
  onBootProgress,
}: KeystoneDigitalTunnelEngineProps) {
  const mountRef = useRef<HTMLDivElement>(null);
  const scrollRef = useRef(scrollRatio);
  const chapterRef = useRef(activeChapter);
  const themeRef = useRef<CyberThemeId>(cyberThemeId);
  const dissolvedRef = useRef(isDissolved);
  const clickCbRef = useRef(onSculptureClick);
  const hoverCbRef = useRef(onSculptureHoverChange);
  const bootCbRef = useRef(onBootProgress);

  scrollRef.current = scrollRatio;
  chapterRef.current = activeChapter;
  themeRef.current = cyberThemeId;
  dissolvedRef.current = isDissolved;
  clickCbRef.current = onSculptureClick;
  hoverCbRef.current = onSculptureHoverChange;
  bootCbRef.current = onBootProgress;

  useEffect(() => {
    const container = mountRef.current;
    if (!container) return;

    const initPreset =
      CYBER_THEMES_V2[themeRef.current] || CYBER_THEMES_V2.sapphire_core;

    // =========================================================================
    // 1. SCENE, CAMERA & 4K HIGH-PRECISION RENDERER
    // =========================================================================
    const scene = new THREE.Scene();
    const liveVoidCol = new THREE.Color(initPreset.voidHex);
    scene.background = liveVoidCol;
    const fogExp = new THREE.FogExp2(initPreset.voidHex, 0.0145);
    scene.fog = fogExp;

    const camera = new THREE.PerspectiveCamera(
      44,
      container.clientWidth / Math.max(container.clientHeight, 1),
      0.1,
      260
    );
    camera.position.set(0, 1.35, 11.2);
    camera.lookAt(0, 0.55, -28);

    const renderer = new THREE.WebGLRenderer({
      antialias: true,
      powerPreference: "high-performance",
    });
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2.5));
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = initPreset.exposure;
    container.appendChild(renderer.domElement);

    const maxAniso = renderer.capabilities.getMaxAnisotropy();

    // =========================================================================
    // 2. DEEP SPACE CYBER NEBULA & VOLUMETRIC BLUE HORIZON PORTAL DOME
    // =========================================================================
    const skyUniforms = {
      uTime: { value: 0 },
      uVoidColor: { value: new THREE.Color(initPreset.voidHex) },
      uHorizonColor: { value: new THREE.Color(initPreset.horizonHex) },
      uCyanGlow: { value: new THREE.Color(initPreset.electricCyanHex) },
      uPortalCore: { value: new THREE.Vector3(0.0, 0.03, -1.0).normalize() },
    };

    const skyGeo = new THREE.SphereGeometry(180, 48, 32);
    const skyMat = new THREE.ShaderMaterial({
      uniforms: skyUniforms,
      side: THREE.BackSide,
      depthWrite: false,
      fog: false,
      vertexShader: /* glsl */ `
        varying vec3 vWorldDir;
        void main() {
          vec4 wp = modelMatrix * vec4(position, 1.0);
          vWorldDir = normalize(wp.xyz);
          gl_Position = projectionMatrix * viewMatrix * wp;
        }
      `,
      fragmentShader: /* glsl */ `
        uniform float uTime;
        uniform vec3 uVoidColor;
        uniform vec3 uHorizonColor;
        uniform vec3 uCyanGlow;
        uniform vec3 uPortalCore;
        varying vec3 vWorldDir;

        float hash21(vec2 p) {
          p = fract(p * vec2(127.1, 311.7));
          p += dot(p, p + 45.32);
          return fract(p.x * p.y);
        }

        float noise2(vec2 p) {
          vec2 i = floor(p);
          vec2 f = fract(p);
          f = f * f * (3.0 - 2.0 * f);
          return mix(
            mix(hash21(i), hash21(i + vec2(1.0, 0.0)), f.x),
            mix(hash21(i + vec2(0.0, 1.0)), hash21(i + vec2(1.0, 1.0)), f.x),
            f.y
          );
        }

        float fbm(vec2 p) {
          float v = 0.0;
          float a = 0.5;
          mat2 rot = mat2(0.8, 0.6, -0.6, 0.8);
          for (int i = 0; i < 4; i++) {
            v += a * noise2(p);
            p = rot * p * 2.08;
            a *= 0.5;
          }
          return v;
        }

        void main() {
          vec3 dir = normalize(vWorldDir);
          float horizonBand = exp(-abs(dir.y - 0.01) * 6.5);
          vec3 col = mix(uVoidColor, uHorizonColor, horizonBand * 0.85);

          // Distant futuristic tunnel vanishing point glow (controlled so center stays legible!)
          float portalDot = max(dot(dir, uPortalCore), 0.0);
          float outerAura = pow(portalDot, 7.0) * 0.34;
          float innerCore = pow(portalDot, 42.0) * 0.28;

          // Subtle moving volumetric light aurora in upper sky
          vec2 uvNeb = dir.xz / (abs(dir.y) + 0.32) * 1.4;
          float neb = fbm(uvNeb + vec2(uTime * 0.03, -uTime * 0.04));
          col += uHorizonColor * neb * 0.38 * horizonBand;
          col += uCyanGlow * (outerAura + innerCore);

          gl_FragColor = vec4(col, 1.0);
        }
      `,
    });
    scene.add(new THREE.Mesh(skyGeo, skyMat));

    // =========================================================================
    // 3. IMMERSIVE 3D DIGITAL LANDSCAPE (Dual Mirrored Floor + Upper Canopy Grid)
    //    - Procedural GPU-animated 3D digital valley: flat dark center channel +
    //      sweeping topographic cyber-ridges on left & right flanks with flowing
    //      laser contour lines and traveling blue telemetry pulses!
    // =========================================================================
    const landscapeUniforms = {
      uTime: { value: 0 },
      uSpeedZ: { value: 0 },
      uPrimaryBlue: { value: new THREE.Color(initPreset.primaryBlueHex) },
      uElectricCyan: { value: new THREE.Color(initPreset.electricCyanHex) },
      uDeepVoid: { value: new THREE.Color(initPreset.voidHex) },
      uBootFade: { value: 0 },
    };

    const makeDigitalTerrainMaterial = (isCeiling: boolean) =>
      new THREE.ShaderMaterial({
        uniforms: {
          ...landscapeUniforms,
          uFlipY: { value: isCeiling ? -1.0 : 1.0 },
          uOpacityMul: { value: isCeiling ? 0.42 : 1.0 },
        },
        transparent: true,
        depthWrite: !isCeiling,
        side: THREE.DoubleSide,
        vertexShader: /* glsl */ `
          uniform float uTime;
          uniform float uSpeedZ;
          uniform float uFlipY;
          varying vec3 vWorldPos;
          varying float vElevation;
          varying float vValleyMask;

          float hash21(vec2 p) {
            p = fract(p * vec2(234.34, 435.345));
            p += dot(p, p + 34.23);
            return fract(p.x * p.y);
          }

          float vnoise(vec2 p) {
            vec2 i = floor(p);
            vec2 f = fract(p);
            f = f * f * (3.0 - 2.0 * f);
            return mix(
              mix(hash21(i), hash21(i + vec2(1.0, 0.0)), f.x),
              mix(hash21(i + vec2(0.0, 1.0)), hash21(i + vec2(1.0, 1.0)), f.x),
              f.y
            );
          }

          float digitalRidges(vec2 p) {
            float sum = 0.0;
            float amp = 1.0;
            float freq = 0.085;
            for (int i = 0; i < 5; i++) {
              float n = vnoise(p * freq);
              float ridge = 1.0 - abs(n * 2.0 - 1.0);
              sum += pow(ridge, 2.2) * amp;
              amp *= 0.5;
              freq *= 2.06;
            }
            return sum;
          }

          void main() {
            vec3 pos = position;
            // Infinite forward flight along -Z
            float flowZ = pos.z + uSpeedZ;

            // Central tunnel corridor stays calm & recessed; left & right flanks rise into sculptural 3D digital peaks!
            float absX = abs(pos.x);
            float corridorMask = smoothstep(3.8, 17.5, absX);
            float farBoost = smoothstep(8.0, -95.0, pos.z);

            float waveMacro = sin(pos.x * 0.22 + flowZ * 0.09) * 0.65 + cos(flowZ * 0.06 - pos.x * 0.14) * 0.55;
            float ridgeH = digitalRidges(vec2(pos.x * 1.15, flowZ * 0.92));

            float elev = (ridgeH * (3.6 + farBoost * 3.8) + waveMacro * 0.9) * corridorMask;
            // Subtle data ripples inside the central tunnel runway
            float runwayRipple = sin(flowZ * 0.45) * cos(pos.x * 0.9) * 0.08 * (1.0 - corridorMask);

            pos.y += (elev + runwayRipple) * uFlipY;
            vElevation = elev;
            vValleyMask = corridorMask;

            vec4 wp = modelMatrix * vec4(pos, 1.0);
            vWorldPos = wp.xyz;
            gl_Position = projectionMatrix * viewMatrix * wp;
          }
        `,
        fragmentShader: /* glsl */ `
          uniform float uTime;
          uniform float uSpeedZ;
          uniform vec3 uPrimaryBlue;
          uniform vec3 uElectricCyan;
          uniform vec3 uDeepVoid;
          uniform float uBootFade;
          uniform float uOpacityMul;

          varying vec3 vWorldPos;
          varying float vElevation;
          varying float vValleyMask;

          void main() {
            float flowZ = vWorldPos.z + uSpeedZ;

            // High-precision anti-aliased architectural 3D cyber grid
            vec2 gridCoord = vec2(vWorldPos.x * 1.25, flowZ * 1.25);
            vec2 gridDeriv = fwidth(gridCoord);
            vec2 gridCell = abs(fract(gridCoord - 0.5) - 0.5) / max(gridDeriv, vec2(0.0001));
            float fineGrid = 1.0 - min(min(gridCell.x, gridCell.y), 1.0);

            // Major structural highway lines every 4 units
            vec2 majorCoord = gridCoord * 0.25;
            vec2 majorDeriv = fwidth(majorCoord);
            vec2 majorCell = abs(fract(majorCoord - 0.5) - 0.5) / max(majorDeriv, vec2(0.0001));
            float majorGrid = 1.0 - min(min(majorCell.x, majorCell.y), 1.0);

            // Topographic holographic elevation contour lines on the 3D digital mountains
            float contourCoord = vElevation * 2.4;
            float contourDeriv = max(fwidth(contourCoord), 0.001);
            float contourLine = (1.0 - min(abs(fract(contourCoord - 0.5) - 0.5) / contourDeriv, 1.0)) * vValleyMask;

            // Traveling high-speed blue light pulses shooting along the Z-axis
            float pulseWave1 = pow(max(0.0, sin(flowZ * 0.14 + uTime * 3.2 - abs(vWorldPos.x) * 0.18)), 14.0);
            float pulseWave2 = pow(max(0.0, sin(flowZ * 0.27 - uTime * 2.1 + vWorldPos.x * 0.12)), 18.0);
            float dataStream = (pulseWave1 * 0.85 + pulseWave2 * 0.65) * (majorGrid * 0.8 + fineGrid * 0.35);

            // Solid obsidian-sapphire base facet shading
            float heightGlow = clamp(vElevation / 6.5, 0.0, 1.0);
            vec3 baseSurface = mix(uDeepVoid, uPrimaryBlue * 0.22, heightGlow * 0.65);

            // Crisp luminous grid & topographic contours
            vec3 lineCol =
              uPrimaryBlue * (fineGrid * 0.32 + contourLine * 0.42) +
              uElectricCyan * (majorGrid * 0.58 + dataStream * 1.45 + contourLine * heightGlow * 0.45);

            vec3 finalCol = baseSurface + lineCol;

            // Distance & lateral atmospheric vignette so edges melt into infinite 4K space
            float distFade = exp(-length(vWorldPos.xz - vec2(0.0, -18.0)) * 0.015);
            float nearFade = smoothstep(13.5, 6.5, vWorldPos.z);
            float farFade = smoothstep(-135.0, -55.0, vWorldPos.z);

            // Keep center foreground subtly restrained so UI nav & titles have 100% contrast
            float centerCalm = mix(0.52, 1.0, vValleyMask);

            float alpha = clamp(distFade * farFade * nearFade * uBootFade * uOpacityMul * centerCalm, 0.0, 1.0);
            gl_FragColor = vec4(finalCol, alpha);
          }
        `,
      });

    const floorGeo = new THREE.PlaneGeometry(116, 150, 260, 260);
    floorGeo.rotateX(-Math.PI / 2);
    floorGeo.translate(0, -3.15, -56);
    const floorMat = makeDigitalTerrainMaterial(false);
    const floorMesh = new THREE.Mesh(floorGeo, floorMat);
    scene.add(floorMesh);

    // Upper Inverted Cyber Canopy (completes the 360° Futuristic Digital Tunnel!)
    const ceilingGeo = new THREE.PlaneGeometry(116, 150, 160, 160);
    ceilingGeo.rotateX(Math.PI / 2);
    ceilingGeo.translate(0, 8.6, -58);
    const ceilingMat = makeDigitalTerrainMaterial(true);
    const ceilingMesh = new THREE.Mesh(ceilingGeo, ceilingMat);
    scene.add(ceilingMesh);

    // =========================================================================
    // 4. FUTURISTIC ARCHITECTURAL BLUE-LIGHT TUNNEL RIBS (Instanced 3D Portals)
    //    - Sleek chamfered octagonal/arch light gates receding into infinity
    //    - Continuously streaming toward the camera in 4K precision
    // =========================================================================
    const RING_COUNT = 28;
    const TUNNEL_SPAN = 126.0; // Z from +8 down to -118

    // Create sleek octagonal architectural arch ring geometry
    const buildTunnelPortalGeometry = (): THREE.BufferGeometry => {
      const shape = new THREE.Shape();
      const rx = 10.6;
      const ry = 6.2;
      const bev = 2.4;

      // Outer chamfered futuristic portal frame
      shape.moveTo(-rx + bev, -ry);
      shape.lineTo(rx - bev, -ry);
      shape.lineTo(rx, -ry + bev);
      shape.lineTo(rx, ry - bev);
      shape.lineTo(rx - bev, ry);
      shape.lineTo(-rx + bev, ry);
      shape.lineTo(-rx, ry - bev);
      shape.lineTo(-rx, -ry + bev);
      shape.closePath();

      // Inner cutout (thin architectural laser frame)
      const hole = new THREE.Path();
      const t = 0.085;
      const irx = rx - t;
      const iry = ry - t;
      const ibev = bev - t * 0.45;
      hole.moveTo(-irx + ibev, -iry);
      hole.lineTo(-irx, -iry + ibev);
      hole.lineTo(-irx, iry - ibev);
      hole.lineTo(-irx + ibev, iry);
      hole.lineTo(irx - ibev, iry);
      hole.lineTo(irx, iry - ibev);
      hole.lineTo(irx, -iry + ibev);
      hole.lineTo(irx - ibev, -iry);
      hole.closePath();
      shape.holes.push(hole);

      const extrudeGeo = new THREE.ExtrudeGeometry(shape, {
        depth: 0.14,
        bevelEnabled: false,
      });
      extrudeGeo.translate(0, 2.35, -0.07);
      return extrudeGeo;
    };

    const portalGeo = buildTunnelPortalGeometry();
    const portalUniforms = {
      uTime: { value: 0 },
      uPrimaryBlue: { value: new THREE.Color(initPreset.primaryBlueHex) },
      uElectricCyan: { value: new THREE.Color(initPreset.electricCyanHex) },
      uBootFade: { value: 0 },
    };

    const portalMat = new THREE.ShaderMaterial({
      uniforms: portalUniforms,
      transparent: true,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
      vertexShader: /* glsl */ `
        attribute float aRingIndex;
        varying vec3 vWorldPos;
        varying vec3 vLocalPos;
        varying float vRingIdx;

        void main() {
          vLocalPos = position;
          vRingIdx = aRingIndex;
          vec4 wp = modelMatrix * instanceMatrix * vec4(position, 1.0);
          vWorldPos = wp.xyz;
          gl_Position = projectionMatrix * viewMatrix * wp;
        }
      `,
      fragmentShader: /* glsl */ `
        uniform float uTime;
        uniform vec3 uPrimaryBlue;
        uniform vec3 uElectricCyan;
        uniform float uBootFade;

        varying vec3 vWorldPos;
        varying vec3 vLocalPos;
        varying float vRingIdx;

        void main() {
          // Sequential light wave cascading down the futuristic tunnel ribs
          float wave = sin(vWorldPos.z * 0.14 + uTime * 3.1) * 0.5 + 0.5;
          float pulse = pow(wave, 5.0);

          // Highlight lateral pillars & upper chamfered corners with intense sapphire/cyan light
          float sideHighlight = smoothstep(3.5, 9.8, abs(vLocalPos.x));
          vec3 col = mix(uPrimaryBlue, uElectricCyan, pulse * 0.75 + sideHighlight * 0.35);

          // Smooth depth attenuation: fade gracefully near camera and far in horizon
          float fadeNear = smoothstep(9.5, 1.5, vWorldPos.z);
          float fadeFar = smoothstep(-122.0, -32.0, vWorldPos.z);
          float alpha = (0.14 + pulse * 0.34) * (0.45 + sideHighlight * 0.65) * fadeNear * fadeFar * uBootFade;

          gl_FragColor = vec4(col * (1.1 + pulse * 0.8), alpha);
        }
      `,
    });

    const portalInstanced = new THREE.InstancedMesh(
      portalGeo,
      portalMat,
      RING_COUNT
    );
    portalInstanced.frustumCulled = false;
    const ringIndices = new Float32Array(RING_COUNT);
    for (let i = 0; i < RING_COUNT; i++) ringIndices[i] = i;
    portalGeo.setAttribute(
      "aRingIndex",
      new THREE.InstancedBufferAttribute(ringIndices, 1)
    );
    scene.add(portalInstanced);

    // =========================================================================
    // 5. HIGH-VELOCITY 3D PHOTON LIGHT STREAKS & QUANTUM DATA DUST (Tunnel Flow)
    // =========================================================================
    const STREAK_COUNT = 260;
    const streakGeo = new THREE.BoxGeometry(0.032, 0.032, 2.8);
    const streakMat = new THREE.MeshBasicMaterial({
      color: initPreset.electricCyanHex,
      transparent: true,
      opacity: 0.68,
      blending: THREE.AdditiveBlending,
      depthWrite: false,
    });
    const streakInstanced = new THREE.InstancedMesh(
      streakGeo,
      streakMat,
      STREAK_COUNT
    );
    streakInstanced.frustumCulled = false;
    scene.add(streakInstanced);

    interface StreakData {
      x: number;
      y: number;
      zOffset: number;
      speedMul: number;
      lenScale: number;
    }
    const streaks: StreakData[] = [];
    for (let i = 0; i < STREAK_COUNT; i++) {
      // Arrange photon streaks around the tunnel perimeter (avoiding central reading zone!)
      const angle = Math.random() * Math.PI * 2;
      const radX = 5.2 + Math.random() * 14.5;
      const radY = 2.8 + Math.random() * 6.2;
      streaks.push({
        x: Math.cos(angle) * radX,
        y: 2.1 + Math.sin(angle) * radY,
        zOffset: Math.random() * TUNNEL_SPAN,
        speedMul: 0.75 + Math.random() * 1.35,
        lenScale: 0.6 + Math.random() * 2.2,
      });
    }

    // Floating crystalline quantum nodes in 3D space
    const DUST_COUNT = 900;
    const dustPositions = new Float32Array(DUST_COUNT * 3);
    for (let i = 0; i < DUST_COUNT; i++) {
      dustPositions[i * 3] = (Math.random() - 0.5) * 42;
      dustPositions[i * 3 + 1] = -2.5 + Math.random() * 11.5;
      dustPositions[i * 3 + 2] = 8 - Math.random() * 110;
    }
    const dustGeo = new THREE.BufferGeometry();
    dustGeo.setAttribute(
      "position",
      new THREE.BufferAttribute(dustPositions, 3)
    );
    const dustMat = new THREE.PointsMaterial({
      color: 0x7dd3fc,
      size: 0.055,
      transparent: true,
      opacity: 0.58,
      blending: THREE.AdditiveBlending,
      depthWrite: false,
    });
    const dustPoints = new THREE.Points(dustGeo, dustMat);
    scene.add(dustPoints);

    // =========================================================================
    // 6. CENTER STAGE LUMINOUS HOLOGRAPHIC TYPOGRAPHY ("SUMERGETE" + PILLAR MONOGRAMS)
    //    - Crisp Diamond-White & Sapphire-Cyan 3D Typography with Interactive
    //      Photon-Plasma Dispersion when touched by cursor + dark contrast cushion!
    // =========================================================================
    const buildStageTexture = (
      mode: "hero" | "monogram",
      word: string
    ): THREE.CanvasTexture => {
      const cw = 3072;
      const ch = 1024;
      const off = document.createElement("canvas");
      off.width = cw;
      off.height = ch;
      const octx = off.getContext("2d");
      if (octx) {
        octx.clearRect(0, 0, cw, ch);
        octx.fillStyle = "#ffffff";
        octx.textBaseline = "middle";

        const drawTracked = (
          txt: string,
          fontSpec: string,
          trackingPx: number,
          centerY: number
        ) => {
          octx.font = fontSpec;
          const chars = txt.split("");
          const widths = chars.map((c) => octx.measureText(c).width);
          const totalW =
            widths.reduce((acc, w) => acc + w, 0) +
            trackingPx * Math.max(0, chars.length - 1);
          let cx = (cw - totalW) * 0.5;
          for (let i = 0; i < chars.length; i++) {
            octx.fillText(chars[i], cx, centerY);
            cx += widths[i] + trackingPx;
          }
        };

        if (mode === "hero") {
          drawTracked(
            "SUMERGETE",
            '800 300px "Plus Jakarta Sans", "Avenir Next", -apple-system, sans-serif',
            22,
            430
          );
          drawTracked(
            "en tecnologia Keystone",
            '600 114px "Plus Jakarta Sans", "Avenir Next", -apple-system, sans-serif',
            10.5,
            685
          );
        } else {
          drawTracked(
            word,
            '800 440px "Plus Jakarta Sans", "Avenir Next", -apple-system, sans-serif',
            44,
            530
          );
        }
      }

      const tex = new THREE.CanvasTexture(off);
      tex.minFilter = THREE.LinearMipmapLinearFilter;
      tex.magFilter = THREE.LinearFilter;
      tex.anisotropy = maxAniso;
      tex.generateMipmaps = true;
      tex.needsUpdate = true;
      return tex;
    };

    const stageTextures = [
      buildStageTexture("hero", "SUMERGETE"),
      buildStageTexture("monogram", "CRM"),
      buildStageTexture("monogram", "DSX"),
      buildStageTexture("monogram", "KHS"),
    ];

    const makePlasmaRT = () =>
      new THREE.WebGLRenderTarget(256, 128, {
        minFilter: THREE.LinearFilter,
        magFilter: THREE.LinearFilter,
        type: THREE.HalfFloatType,
        depthBuffer: false,
        stencilBuffer: false,
      });

    let plasmaRTA = makePlasmaRT();
    let plasmaRTB = makePlasmaRT();

    const PLANE_W = 7.65;
    const PLANE_H = 2.55;
    const holoPlaneGeo = new THREE.PlaneGeometry(PLANE_W, PLANE_H, 1, 1);

    const plasmaSimUniforms = {
      uPrevTex: { value: plasmaRTA.texture },
      uPointerUV: { value: new THREE.Vector2(-10, -10) },
      uPrevPointerUV: { value: new THREE.Vector2(-10, -10) },
      uPointerVel: { value: new THREE.Vector2(0, 0) },
      uPointerActive: { value: 0.0 },
      uTime: { value: 0.0 },
      uAspect: { value: PLANE_W / PLANE_H },
    };

    const plasmaSimMat = new THREE.ShaderMaterial({
      uniforms: plasmaSimUniforms,
      depthTest: false,
      depthWrite: false,
      vertexShader: /* glsl */ `
        varying vec2 vUv;
        void main() {
          vUv = uv;
          gl_Position = vec4(position.xy, 0.0, 1.0);
        }
      `,
      fragmentShader: /* glsl */ `
        uniform sampler2D uPrevTex;
        uniform vec2 uPointerUV;
        uniform vec2 uPrevPointerUV;
        uniform vec2 uPointerVel;
        uniform float uPointerActive;
        uniform float uTime;
        uniform float uAspect;
        varying vec2 vUv;

        float distToSegment(vec2 p, vec2 a, vec2 b) {
          vec2 pa = p - a;
          vec2 ba = b - a;
          pa.x *= uAspect;
          ba.x *= uAspect;
          float h = clamp(dot(pa, ba) / max(dot(ba, ba), 0.00001), 0.0, 1.0);
          return length(pa - ba * h);
        }

        void main() {
          vec2 texel = vec2(1.0 / 256.0, 1.0 / 128.0);
          vec4 cur = texture2D(uPrevTex, vUv);

          float curlX = sin(vUv.y * 14.0 + uTime * 2.2) * 0.002;
          vec2 advUv = vUv - vec2(curlX + cur.x * 0.004, 0.0038 + cur.y * 0.003);

          vec4 sC = texture2D(uPrevTex, advUv);
          vec4 sL = texture2D(uPrevTex, advUv - vec2(texel.x, 0.0));
          vec4 sR = texture2D(uPrevTex, advUv + vec2(texel.x, 0.0));
          vec4 sU = texture2D(uPrevTex, advUv + vec2(0.0, texel.y));
          vec4 sD = texture2D(uPrevTex, advUv - vec2(0.0, texel.y));
          vec4 diffused = mix(sC, (sL + sR + sU + sD) * 0.25, 0.35);

          vec2 vel = diffused.xy * 0.935;
          float density = diffused.z * 0.958;

          if (uPointerActive > 0.01) {
            float d = distToSegment(vUv, uPrevPointerUV, uPointerUV);
            float brush = exp(-(d * d) / (0.115 * 0.115));
            vel += uPointerVel * brush * 0.65;
            density = clamp(density + brush * (0.36 + length(uPointerVel) * 4.8) * uPointerActive, 0.0, 1.25);
          }

          gl_FragColor = vec4(vel, density, 1.0);
        }
      `,
    });

    const orthoCam = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);
    const quadGeo = new THREE.PlaneGeometry(2, 2);
    const plasmaSimScene = new THREE.Scene();
    plasmaSimScene.add(new THREE.Mesh(quadGeo, plasmaSimMat));

    const holoUniforms = {
      uTime: { value: 0 },
      uMorph: { value: 0 },
      uOpacity: { value: 0 },
      uDisperseIn: { value: 0 },
      uDissolve: { value: 0 },
      uHoverPulse: { value: 0 },
      uAccentCyan: { value: new THREE.Color(initPreset.electricCyanHex) },
      uTex0: { value: stageTextures[0] },
      uTex1: { value: stageTextures[1] },
      uTex2: { value: stageTextures[2] },
      uTex3: { value: stageTextures[3] },
      uPlasmaTex: { value: plasmaRTA.texture },
    };

    const holoTextMat = new THREE.ShaderMaterial({
      uniforms: holoUniforms,
      transparent: true,
      depthWrite: false,
      side: THREE.DoubleSide,
      vertexShader: /* glsl */ `
        varying vec2 vUv;
        void main() {
          vUv = uv;
          gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
        }
      `,
      fragmentShader: /* glsl */ `
        uniform float uTime;
        uniform float uMorph;
        uniform float uOpacity;
        uniform float uDisperseIn;
        uniform float uDissolve;
        uniform float uHoverPulse;
        uniform vec3 uAccentCyan;
        uniform sampler2D uTex0;
        uniform sampler2D uTex1;
        uniform sampler2D uTex2;
        uniform sampler2D uTex3;
        uniform sampler2D uPlasmaTex;
        varying vec2 vUv;

        float hash21(vec2 p) {
          p = fract(p * vec2(127.1, 311.7));
          p += dot(p, p + 45.32);
          return fract(p.x * p.y);
        }

        float vnoise(vec2 p) {
          vec2 i = floor(p);
          vec2 f = fract(p);
          f = f * f * (3.0 - 2.0 * f);
          return mix(
            mix(hash21(i), hash21(i + vec2(1.0, 0.0)), f.x),
            mix(hash21(i + vec2(0.0, 1.0)), hash21(i + vec2(1.0, 1.0)), f.x),
            f.y
          );
        }

        float fbm(vec2 p) {
          float v = 0.0;
          float amp = 0.5;
          mat2 rot = mat2(0.80, 0.60, -0.60, 0.80);
          for (int i = 0; i < 4; i++) {
            v += amp * vnoise(p);
            p = rot * p * 2.06;
            amp *= 0.5;
          }
          return v;
        }

        float sampleStageAlpha(int idx, vec2 uv) {
          if (uv.x < 0.002 || uv.x > 0.998 || uv.y < 0.002 || uv.y > 0.998) return 0.0;
          if (idx == 0) return texture2D(uTex0, uv).a;
          if (idx == 1) return texture2D(uTex1, uv).a;
          if (idx == 2) return texture2D(uTex2, uv).a;
          return texture2D(uTex3, uv).a;
        }

        float sampleMorphText(vec2 uv, float m) {
          int idxA = 0;
          int idxB = 1;
          float t = m;
          if (m >= 2.0) {
            idxA = 2;
            idxB = 3;
            t = m - 2.0;
          } else if (m >= 1.0) {
            idxA = 1;
            idxB = 2;
            t = m - 1.0;
          }
          float sT = smoothstep(0.06, 0.94, t);
          return mix(sampleStageAlpha(idxA, uv), sampleStageAlpha(idxB, uv), sT);
        }

        void main() {
          if (uOpacity <= 0.01) discard;

          float m = clamp(uMorph, 0.0, 3.0);
          float morphBell = sin(fract(m) * 3.14159265);
          float dEase = smoothstep(0.0, 1.0, uDissolve);

          vec4 plasmaData = texture2D(uPlasmaTex, vUv);
          float localPlasma = clamp(plasmaData.z, 0.0, 1.25);
          float globalPlasma = clamp(morphBell * 0.82 + uDisperseIn * 0.72 + dEase * 1.15, 0.0, 1.25);
          float totalPlasma = clamp(localPlasma + globalPlasma, 0.0, 1.35);

          // 1. Razor-sharp solid luminous letter core at rest
          float crispAlpha = sampleMorphText(vUv, m);

          // 2. Holographic blue-light photon wisps when touched or morphing
          vec2 noiseCoord = vUv * vec2(15.0, 6.0);
          float n1 = fbm(noiseCoord + vec2(-uTime * 0.45, -uTime * 0.85));
          float n2 = fbm(noiseCoord * 2.1 + vec2(n1 * 2.0 + uTime * 0.35, -uTime * 1.2));
          float wispNoise = n1 * 0.55 + n2 * 0.45;

          vec2 curlDisp = vec2(
            (n2 - 0.48) * 0.085 - plasmaData.x * 0.06,
            -(0.025 + n1 * 0.095) - abs(plasmaData.y) * 0.04
          ) * totalPlasma;

          float s0 = sampleMorphText(vUv + curlDisp * 0.5, m);
          float s1 = sampleMorphText(vUv + curlDisp * 1.05 + vec2(0.012, -0.015) * totalPlasma, m);
          float s2 = sampleMorphText(vUv + curlDisp * 1.6 + vec2(-0.014, -0.025) * totalPlasma, m);
          float photonPlume = (s0 * 0.48 + s1 * 0.32 + s2 * 0.20);

          float erodeMask = smoothstep(
            totalPlasma * 0.74 - 0.06,
            totalPlasma * 0.74 + 0.24,
            crispAlpha * (0.60 + wispNoise * 0.70)
          );
          float solidCore = crispAlpha * mix(1.0, erodeMask, smoothstep(0.02, 0.42, totalPlasma));

          float vaporWisps =
            photonPlume *
            smoothstep(0.16, 0.84, wispNoise + 0.24) *
            smoothstep(0.01, 0.32, totalPlasma) *
            clamp(1.12 - totalPlasma * 0.55, 0.0, 1.0) *
            0.92;

          float finalLetterAlpha = clamp(max(solidCore, vaporWisps), 0.0, 1.0);
          finalLetterAlpha *= mix(1.0, 0.0, smoothstep(0.0, 0.96, dEase));

          // 3. Deep Obsidian Contrast Cushion + Subtle Sapphire Rim Halo so letters NEVER blend into background!
          float haloSample =
            sampleMorphText(vUv + vec2(0.010, 0.0), m) +
            sampleMorphText(vUv - vec2(0.010, 0.0), m) +
            sampleMorphText(vUv + vec2(0.0, 0.026), m) +
            sampleMorphText(vUv - vec2(0.0, 0.026), m);
          float shadowHalo = clamp(haloSample * 0.25, 0.0, 1.0) * (1.0 - finalLetterAlpha) * (1.0 - dEase);

          // Radial subtle dark vignette behind the center wordmark for cinema-grade readability
          vec2 cDist = (vUv - vec2(0.5, 0.46)) * vec2(1.0, 1.45);
          float centerShield = exp(-dot(cDist, cDist) * 5.2) * (1.0 - dEase) * 0.48;

          float combinedAlpha = clamp(max(finalLetterAlpha + shadowHalo * 0.72, centerShield), 0.0, 1.0) * uOpacity;
          if (combinedAlpha <= 0.004) discard;

          // Subtle traveling platinum-cyan light sheen across the solid white letters
          float sheenBand = pow(max(0.0, sin(vUv.x * 5.5 - uTime * 1.4 + vUv.y * 1.8)), 8.0);
          vec3 pureWhite = vec3(0.98, 0.99, 1.0) + uAccentCyan * (sheenBand * 0.25 + uHoverPulse * 0.22);
          vec3 photonCyan = mix(uAccentCyan * 1.25, vec3(1.0), 0.35);
          vec3 letterCol = mix(photonCyan, pureWhite, clamp(solidCore, 0.0, 1.0));

          vec3 darkShieldCol = vec3(0.008, 0.018, 0.048);
          float fgWeight = clamp(finalLetterAlpha / max(combinedAlpha, 0.001), 0.0, 1.0);
          vec3 outCol = mix(darkShieldCol, letterCol, fgWeight);

          gl_FragColor = vec4(outCol, combinedAlpha);
        }
      `,
    });

    const sculptureGroup = new THREE.Group();
    sculptureGroup.position.set(0, 0.82, 0.35);
    scene.add(sculptureGroup);

    const holoMesh = new THREE.Mesh(holoPlaneGeo, holoTextMat);
    holoMesh.frustumCulled = false;
    sculptureGroup.add(holoMesh);

    const hitProxyMesh = new THREE.Mesh(
      new THREE.BoxGeometry(5.8, 2.3, 1.0),
      new THREE.MeshBasicMaterial({ visible: false })
    );
    hitProxyMesh.position.set(0, -0.1, 0);
    sculptureGroup.add(hitProxyMesh);

    // =========================================================================
    // 7. 4K CINEMATIC POST-PROCESSING COMPOSITOR (Liquid Cursor Wake + Anamorphic Bloom)
    // =========================================================================
    const initW = Math.max(container.clientWidth, 1);
    const initH = Math.max(container.clientHeight, 1);
    const dpr = Math.min(window.devicePixelRatio, 2.5);

    const sceneRT = new THREE.WebGLRenderTarget(
      Math.round(initW * dpr),
      Math.round(initH * dpr),
      {
        minFilter: THREE.LinearFilter,
        magFilter: THREE.LinearFilter,
        type: THREE.HalfFloatType,
        samples: 4,
      }
    );

    const simScale = 0.35;
    const makeFluidRT = (w: number, h: number) =>
      new THREE.WebGLRenderTarget(
        Math.max(64, Math.round(w * simScale)),
        Math.max(64, Math.round(h * simScale)),
        {
          minFilter: THREE.LinearFilter,
          magFilter: THREE.LinearFilter,
          type: THREE.HalfFloatType,
          depthBuffer: false,
          stencilBuffer: false,
        }
      );

    let fluidRTA = makeFluidRT(initW, initH);
    let fluidRTB = makeFluidRT(initW, initH);

    const fluidSimUniforms = {
      uPrevTex: { value: fluidRTA.texture },
      uTexel: { value: new THREE.Vector2(1 / fluidRTA.width, 1 / fluidRTA.height) },
      uAspect: { value: initW / initH },
      uMouse: { value: new THREE.Vector2(0.5, 0.5) },
      uPrevMouse: { value: new THREE.Vector2(0.5, 0.5) },
      uVelocity: { value: new THREE.Vector2(0, 0) },
      uStrength: { value: 0.0 },
    };

    const fluidSimMat = new THREE.ShaderMaterial({
      uniforms: fluidSimUniforms,
      depthTest: false,
      depthWrite: false,
      vertexShader: /* glsl */ `
        varying vec2 vUv;
        void main() {
          vUv = uv;
          gl_Position = vec4(position.xy, 0.0, 1.0);
        }
      `,
      fragmentShader: /* glsl */ `
        uniform sampler2D uPrevTex;
        uniform vec2 uTexel;
        uniform float uAspect;
        uniform vec2 uMouse;
        uniform vec2 uPrevMouse;
        uniform vec2 uVelocity;
        uniform float uStrength;
        varying vec2 vUv;

        float distSeg(vec2 p, vec2 a, vec2 b) {
          vec2 pa = p - a;
          vec2 ba = b - a;
          pa.x *= uAspect;
          ba.x *= uAspect;
          float h = clamp(dot(pa, ba) / max(dot(ba, ba), 0.00001), 0.0, 1.0);
          return length(pa - ba * h);
        }

        void main() {
          vec4 center = texture2D(uPrevTex, vUv);
          vec2 advUv = vUv - center.xy * 0.005;
          vec4 c = texture2D(uPrevTex, advUv);
          vec4 l = texture2D(uPrevTex, advUv - vec2(uTexel.x, 0.0));
          vec4 r = texture2D(uPrevTex, advUv + vec2(uTexel.x, 0.0));
          vec4 t = texture2D(uPrevTex, advUv + vec2(0.0, uTexel.y));
          vec4 b = texture2D(uPrevTex, advUv - vec2(0.0, uTexel.y));
          vec4 avg = (l + r + t + b) * 0.25;

          vec2 gradMass = vec2(r.z - l.z, t.z - b.z);
          vec2 swirl = vec2(-gradMass.y, gradMass.x) * 0.04;

          vec2 vel = mix(c.xy, avg.xy, 0.30) * 0.94 + swirl * c.z;
          float mass = mix(c.z, avg.z, 0.22) * 0.958;

          if (uStrength > 0.0005) {
            float d = distSeg(vUv, uPrevMouse, uMouse);
            float radius = 0.042 + clamp(uStrength * 0.4, 0.0, 0.028);
            float brush = exp(-(d * d) / (radius * radius));
            vel += uVelocity * brush * 0.85;
            mass = clamp(mass + brush * clamp(uStrength * 8.8, 0.0, 0.8), 0.0, 1.25);
          }

          gl_FragColor = vec4(vel, mass, 1.0);
        }
      `,
    });

    const fluidSimScene = new THREE.Scene();
    fluidSimScene.add(new THREE.Mesh(quadGeo, fluidSimMat));

    const compUniforms = {
      uSceneTex: { value: sceneRT.texture },
      uFluidTex: { value: fluidRTA.texture },
      uTexel: { value: new THREE.Vector2(1 / fluidRTA.width, 1 / fluidRTA.height) },
      uScreenTexel: {
        value: new THREE.Vector2(
          1 / Math.round(initW * dpr),
          1 / Math.round(initH * dpr)
        ),
      },
      uAspect: { value: initW / initH },
      uTime: { value: 0 },
    };

    const compMat = new THREE.ShaderMaterial({
      uniforms: compUniforms,
      depthTest: false,
      depthWrite: false,
      vertexShader: /* glsl */ `
        varying vec2 vUv;
        void main() {
          vUv = uv;
          gl_Position = vec4(position.xy, 0.0, 1.0);
        }
      `,
      fragmentShader: /* glsl */ `
        uniform sampler2D uSceneTex;
        uniform sampler2D uFluidTex;
        uniform vec2 uTexel;
        uniform vec2 uScreenTexel;
        uniform float uAspect;
        uniform float uTime;
        varying vec2 vUv;

        void main() {
          vec4 fluid = texture2D(uFluidTex, vUv);
          float hL = texture2D(uFluidTex, vUv - vec2(uTexel.x * 1.5, 0.0)).z;
          float hR = texture2D(uFluidTex, vUv + vec2(uTexel.x * 1.5, 0.0)).z;
          float hB = texture2D(uFluidTex, vUv - vec2(0.0, uTexel.y * 1.5)).z;
          float hT = texture2D(uFluidTex, vUv + vec2(0.0, uTexel.y * 1.5)).z;

          vec2 grad = vec2((hR - hL) / max(uAspect, 0.5), hT - hB) * 2.4;
          vec2 flowOffset = fluid.xy * 0.016 + grad * 0.014;
          float intensity = clamp(length(grad) * 1.2 + fluid.z * 0.3, 0.0, 1.0);

          // Subtle optical blue-cyan dispersion on cursor fluid ripple
          float ca = 0.004 * intensity + 0.0018 * fluid.z;
          vec2 dir = normalize(flowOffset + vec2(0.0001));
          vec2 sampleUv = clamp(vUv - flowOffset, 0.001, 0.999);

          float rCh = texture2D(uSceneTex, clamp(sampleUv + dir * ca, 0.001, 0.999)).r;
          float gCh = texture2D(uSceneTex, sampleUv).g;
          float bCh = texture2D(uSceneTex, clamp(sampleUv - dir * ca, 0.001, 0.999)).b;
          vec3 col = vec3(rCh, gCh, bCh);

          // Horizontal Anamorphic Cinema Blue-Light Bloom (Sci-Fi 4K Lens Signature!)
          vec2 anamorphicStep = vec2(uScreenTexel.x * 7.5, uScreenTexel.y * 2.0);
          vec3 b1 = texture2D(uSceneTex, clamp(sampleUv + vec2(anamorphicStep.x, 0.0), 0.001, 0.999)).rgb;
          vec3 b2 = texture2D(uSceneTex, clamp(sampleUv - vec2(anamorphicStep.x, 0.0), 0.001, 0.999)).rgb;
          vec3 b3 = texture2D(uSceneTex, clamp(sampleUv + vec2(anamorphicStep.x * 2.0, anamorphicStep.y), 0.001, 0.999)).rgb;
          vec3 b4 = texture2D(uSceneTex, clamp(sampleUv - vec2(anamorphicStep.x * 2.0, anamorphicStep.y), 0.001, 0.999)).rgb;
          vec3 brightAvg = max(vec3(0.0), (b1 + b2 + b3 + b4) * 0.25 - vec3(0.45));
          col += brightAvg * vec3(0.14, 0.24, 0.38);

          // Sapphire specular ripple sheen on cursor trail
          vec3 norm = normalize(vec3(-grad.x * 2.5, -grad.y * 2.5, 1.0));
          float spec = pow(max(dot(norm, normalize(vec3(-0.3, 0.6, 0.7))), 0.0), 20.0);
          col += vec3(0.22, 0.68, 1.0) * spec * 0.45 * smoothstep(0.02, 0.4, fluid.z + length(grad));

          // Executive perimeter readability vignette (frames top nav & bottom marquee cleanly)
          vec2 vigUv = vUv - 0.5;
          float vig = 1.0 - dot(vigUv * vec2(0.95, 1.22), vigUv * vec2(0.95, 1.22)) * 0.48;
          col *= clamp(vig, 0.58, 1.0);

          // Anti-banding 4K film sensor dither
          float dither = fract(sin(dot(vUv + fract(uTime * 0.37), vec2(12.9898, 78.233))) * 43758.5453) - 0.5;
          col += dither * 0.005;

          gl_FragColor = vec4(max(col, vec3(0.0)), 1.0);
          #include <tonemapping_fragment>
          #include <colorspace_fragment>
        }
      `,
    });
    compMat.toneMapped = true;

    const compScene = new THREE.Scene();
    compScene.add(new THREE.Mesh(quadGeo, compMat));

    // =========================================================================
    // 8. INTERACTION & MASTER 60/120FPS ANIMATION LOOP
    // =========================================================================
    let mouseNDCX = 0;
    let mouseNDCY = 0;
    let smoothMouseX = 0;
    let smoothMouseY = 0;
    let isHoveringSculpture = false;

    const currUV = new THREE.Vector2(0.5, 0.5);
    const prevUV = new THREE.Vector2(0.5, 0.5);
    let hasReceivedPointer = false;

    const prevHitUV = new THREE.Vector2(-10, -10);
    const currHitUV = new THREE.Vector2(-10, -10);
    let hasPlasmaHit = false;

    const raycaster = new THREE.Raycaster();
    const pointerVec = new THREE.Vector2();
    const interactionPlane = new THREE.Plane(new THREE.Vector3(0, 0, 1), -0.35);
    const hitPoint = new THREE.Vector3();

    const handlePointerMove = (e: MouseEvent) => {
      const rect = container.getBoundingClientRect();
      const u = (e.clientX - rect.left) / Math.max(rect.width, 1);
      const v = 1.0 - (e.clientY - rect.top) / Math.max(rect.height, 1);
      mouseNDCX = u * 2 - 1;
      mouseNDCY = v * 2 - 1;

      if (!hasReceivedPointer) {
        hasReceivedPointer = true;
        currUV.set(u, v);
        prevUV.set(u, v);
      } else {
        currUV.set(u, v);
      }
    };
    window.addEventListener("mousemove", handlePointerMove);

    const handleCanvasClick = () => {
      if (chapterRef.current >= 1 && !dissolvedRef.current && isHoveringSculpture) {
        clickCbRef.current?.();
      }
    };
    renderer.domElement.addEventListener("click", handleCanvasClick);

    const handleResize = () => {
      if (!container) return;
      const w = Math.max(container.clientWidth, 1);
      const h = Math.max(container.clientHeight, 1);
      const ratio = Math.min(window.devicePixelRatio, 2.5);
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      renderer.setSize(w, h);

      const rtW = Math.round(w * ratio);
      const rtH = Math.round(h * ratio);
      sceneRT.setSize(rtW, rtH);
      const sw = Math.max(64, Math.round(w * simScale));
      const sh = Math.max(64, Math.round(h * simScale));
      fluidRTA.setSize(sw, sh);
      fluidRTB.setSize(sw, sh);
      fluidSimUniforms.uTexel.value.set(1 / sw, 1 / sh);
      fluidSimUniforms.uAspect.value = w / h;
      compUniforms.uTexel.value.set(1 / sw, 1 / sh);
      compUniforms.uScreenTexel.value.set(1 / rtW, 1 / rtH);
      compUniforms.uAspect.value = w / h;
    };
    window.addEventListener("resize", handleResize);

    const dummyMatrix = new THREE.Matrix4();
    const dummyPos = new THREE.Vector3();
    const dummyQuat = new THREE.Quaternion();
    const dummyScale = new THREE.Vector3();
    const dummyEuler = new THREE.Euler();

    const clock = new THREE.Clock();
    let animationId: number;
    let currentScroll = scrollRef.current;
    let currentMorph = computeContinuousMorph(scrollRef.current);
    let hudTriggered = false;
    let travelDistance = 0;

    const targetVoidCol = new THREE.Color();
    const targetHorizonCol = new THREE.Color();
    const targetPrimaryBlue = new THREE.Color();
    const targetElectricCyan = new THREE.Color();

    const chapterAccents = [
      new THREE.Color(0x38bdf8), // 0: Hero Sapphire-Cyan
      new THREE.Color(0x60a5fa), // 1: Digital Core
      new THREE.Color(0x34d399), // 2: DeepShield Cyber Emerald-Cyan
      new THREE.Color(0x818cf8), // 3: KeyHub Indigo-Sapphire
    ];

    let prevElapsed = 0;

    const animate = () => {
      animationId = requestAnimationFrame(animate);
      const elapsed = clock.getElapsedTime();
      const dt = Math.min(0.05, Math.max(0.001, elapsed - prevElapsed));
      prevElapsed = elapsed;

      // Smooth Live Theme Interpolation
      const preset =
        CYBER_THEMES_V2[themeRef.current] || CYBER_THEMES_V2.sapphire_core;
      targetVoidCol.setHex(preset.voidHex);
      targetHorizonCol.setHex(preset.horizonHex);
      targetPrimaryBlue.setHex(preset.primaryBlueHex);
      targetElectricCyan.setHex(preset.electricCyanHex);

      liveVoidCol.lerp(targetVoidCol, 0.06);
      fogExp.color.copy(liveVoidCol);
      skyUniforms.uVoidColor.value.copy(liveVoidCol);
      skyUniforms.uHorizonColor.value.lerp(targetHorizonCol, 0.06);
      skyUniforms.uCyanGlow.value.lerp(targetElectricCyan, 0.06);

      landscapeUniforms.uDeepVoid.value.copy(liveVoidCol);
      landscapeUniforms.uPrimaryBlue.value.lerp(targetPrimaryBlue, 0.06);
      landscapeUniforms.uElectricCyan.value.lerp(targetElectricCyan, 0.06);

      portalUniforms.uPrimaryBlue.value.copy(landscapeUniforms.uPrimaryBlue.value);
      portalUniforms.uElectricCyan.value.copy(landscapeUniforms.uElectricCyan.value);
      streakMat.color.copy(landscapeUniforms.uElectricCyan.value);
      renderer.toneMappingExposure +=
        (preset.exposure - renderer.toneMappingExposure) * 0.06;

      // Boot progression
      const bootT = Math.min(1.0, elapsed / BOOT_DURATION);
      const bootEase = THREE.MathUtils.smoothstep(bootT, 0.0, 1.0);
      landscapeUniforms.uBootFade.value = bootEase;
      portalUniforms.uBootFade.value = THREE.MathUtils.smoothstep(bootT, 0.15, 1.0);

      if (bootCbRef.current) {
        if (bootT >= 0.45 && !hudTriggered) {
          hudTriggered = true;
          bootCbRef.current(1.0, true);
        } else if (!hudTriggered) {
          bootCbRef.current(bootT / 0.45, false);
        }
      }

      // Circular Scroll Inertia & Warp Acceleration between Chapters!
      let deltaScroll = scrollRef.current - currentScroll;
      if (deltaScroll > 0.5) {
        currentScroll += 1.0;
        deltaScroll = scrollRef.current - currentScroll;
      } else if (deltaScroll < -0.5) {
        currentScroll -= 1.0;
        deltaScroll = scrollRef.current - currentScroll;
      }
      const scrollSpeedBoost = Math.min(Math.abs(deltaScroll) * 65.0, 22.0);
      currentScroll = (((currentScroll + deltaScroll * 0.048) % 1.0) + 1.0) % 1.0;

      const continuousTargetMorph = computeContinuousMorph(currentScroll);
      currentMorph += (continuousTargetMorph - currentMorph) * 0.08;
      holoUniforms.uMorph.value = currentMorph;

      const morphFract = currentMorph - Math.floor(currentMorph);
      const flightArc = Math.sin(morphFract * Math.PI);

      // Continuous 4K Cinematic Forward Velocity through the Digital Landscape & Tunnel!
      const forwardSpeed = 6.2 + flightArc * 14.0 + scrollSpeedBoost;
      travelDistance += forwardSpeed * dt;

      skyUniforms.uTime.value = elapsed;
      landscapeUniforms.uTime.value = elapsed;
      landscapeUniforms.uSpeedZ.value = travelDistance;
      portalUniforms.uTime.value = elapsed;
      holoUniforms.uTime.value = elapsed;
      compUniforms.uTime.value = elapsed;

      // Update Instanced Futuristic Tunnel Portal Ribs (Infinite Conveyor with gentle helical twist)
      const spacing = TUNNEL_SPAN / RING_COUNT;
      for (let i = 0; i < RING_COUNT; i++) {
        const rawZ = ((i * spacing + travelDistance * 1.15) % TUNNEL_SPAN);
        const zPos = 8.0 - rawZ; // Flows continuously from -118 toward +8!
        const depthNorm = (8.0 - zPos) / TUNNEL_SPAN;

        // Subtle futuristic tunnel curve & architectural twist in the distance
        const curveX = Math.sin(depthNorm * 3.2 + elapsed * 0.28) * depthNorm * 2.6;
        const curveY = Math.cos(depthNorm * 2.4 + elapsed * 0.22) * depthNorm * 0.85;
        const twistZ = Math.sin(depthNorm * 4.0 - elapsed * 0.35) * 0.08 * depthNorm;

        dummyPos.set(curveX, curveY, zPos);
        dummyEuler.set(0, 0, twistZ);
        dummyQuat.setFromEuler(dummyEuler);
        const s = 1.0 + Math.sin(i * 0.7) * 0.04;
        dummyScale.set(s, s, 1.0);
        dummyMatrix.compose(dummyPos, dummyQuat, dummyScale);
        portalInstanced.setMatrixAt(i, dummyMatrix);
      }
      portalInstanced.instanceMatrix.needsUpdate = true;

      // Update High-Speed 3D Blue Light Streaks flying past camera
      dummyQuat.identity();
      for (let i = 0; i < STREAK_COUNT; i++) {
        const st = streaks[i];
        const zFlow =
          ((st.zOffset + travelDistance * 2.35 * st.speedMul) % TUNNEL_SPAN);
        const zPos = 10.0 - zFlow;
        dummyPos.set(st.x, st.y, zPos);
        dummyScale.set(1.0, 1.0, st.lenScale * (1.0 + flightArc * 1.4));
        dummyMatrix.compose(dummyPos, dummyQuat, dummyScale);
        streakInstanced.setMatrixAt(i, dummyMatrix);
      }
      streakInstanced.instanceMatrix.needsUpdate = true;

      // Subtle Quantum Dust drift
      dustPoints.position.z = (travelDistance * 0.45) % 24.0;
      dustPoints.rotation.z = Math.sin(elapsed * 0.12) * 0.04;

      // Smooth Camera Flight & Mouse Parallax
      smoothMouseX += (mouseNDCX - smoothMouseX) * 0.05;
      smoothMouseY += (mouseNDCY - smoothMouseY) * 0.05;

      const enterServices = THREE.MathUtils.smoothstep(currentScroll, 0.06, 0.24);
      const exitBackToHero = 1.0 - THREE.MathUtils.smoothstep(currentScroll, 0.86, 0.99);
      const transitionT = enterServices * exitBackToHero;

      const camSwayX =
        Math.sin(currentScroll * Math.PI * 2.0) * 0.85 * transitionT +
        smoothMouseX * 0.62;
      const camHeightY =
        THREE.MathUtils.lerp(0.92, 1.08, transitionT) +
        flightArc * 0.28 +
        smoothMouseY * 0.26;
      const camDepthZ =
        THREE.MathUtils.lerp(13.8, 9.85, bootEase) -
        transitionT * 0.85 +
        flightArc * 1.35;

      camera.position.set(camSwayX, camHeightY, camDepthZ);
      camera.lookAt(camSwayX * 0.28, 0.72 + smoothMouseY * 0.08, -18.0);

      // Sculpture / Holographic Center Stage Interaction
      const dissolveTarget =
        dissolvedRef.current && chapterRef.current >= 1 ? 1.0 : 0.0;
      holoUniforms.uDissolve.value +=
        (dissolveTarget - holoUniforms.uDissolve.value) * 0.075;

      pointerVec.set(mouseNDCX, mouseNDCY);
      raycaster.setFromCamera(pointerVec, camera);
      let hasHit = false;
      if (raycaster.ray.intersectPlane(interactionPlane, hitPoint)) {
        hasHit = true;
      }

      const canHoverPillar =
        bootT >= 0.65 &&
        chapterRef.current >= 1 &&
        !dissolvedRef.current &&
        flightArc < 0.35;
      let nextHover = false;
      if (canHoverPillar) {
        const hits = raycaster.intersectObject(hitProxyMesh, false);
        nextHover = hits.length > 0;
      }
      if (nextHover !== isHoveringSculpture) {
        isHoveringSculpture = nextHover;
        renderer.domElement.style.cursor = nextHover ? "pointer" : "default";
        hoverCbRef.current?.(nextHover);
      }
      const hoverTarget = isHoveringSculpture ? 1.0 : 0.0;
      holoUniforms.uHoverPulse.value +=
        (hoverTarget - holoUniforms.uHoverPulse.value) * 0.12;

      const textOpacity = THREE.MathUtils.smoothstep(bootT, 0.18, 0.72);
      holoUniforms.uOpacity.value = textOpacity;
      holoUniforms.uDisperseIn.value = 1.0 - textOpacity;
      sculptureGroup.visible = textOpacity > 0.01;

      if (sculptureGroup.visible) {
        sculptureGroup.position.x = camSwayX * 0.25;
        sculptureGroup.position.z = 0.35 - flightArc * 0.85;
        sculptureGroup.rotation.y =
          Math.sin(elapsed * 0.3) * 0.03 + smoothMouseX * 0.09 + flightArc * 0.18;
        sculptureGroup.rotation.x = smoothMouseY * -0.04;

        let pointerOnPlane = 0.0;
        if (hasHit && hasReceivedPointer && !dissolvedRef.current) {
          const localP = hitPoint.clone();
          sculptureGroup.worldToLocal(localP);
          const uHit = localP.x / PLANE_W + 0.5;
          const vHit = localP.y / PLANE_H + 0.5;
          if (uHit >= -0.08 && uHit <= 1.08 && vHit >= -0.12 && vHit <= 1.12) {
            pointerOnPlane = 1.0;
            currHitUV.set(uHit, vHit);
            if (!hasPlasmaHit) {
              hasPlasmaHit = true;
              prevHitUV.set(uHit, vHit);
            }
          } else {
            hasPlasmaHit = false;
          }
        } else {
          hasPlasmaHit = false;
        }

        plasmaSimUniforms.uPrevTex.value = plasmaRTA.texture;
        plasmaSimUniforms.uPointerUV.value.copy(currHitUV);
        plasmaSimUniforms.uPrevPointerUV.value.copy(prevHitUV);
        plasmaSimUniforms.uPointerVel.value.set(
          (currHitUV.x - prevHitUV.x) * 8.0,
          (currHitUV.y - prevHitUV.y) * 8.0
        );
        plasmaSimUniforms.uPointerActive.value = pointerOnPlane;
        plasmaSimUniforms.uTime.value = elapsed;

        const prevTM = renderer.toneMapping;
        renderer.toneMapping = THREE.NoToneMapping;
        renderer.setRenderTarget(plasmaRTB);
        renderer.render(plasmaSimScene, orthoCam);
        renderer.toneMapping = prevTM;

        const tmpP = plasmaRTA;
        plasmaRTA = plasmaRTB;
        plasmaRTB = tmpP;
        prevHitUV.lerp(currHitUV, 0.72);
        holoUniforms.uPlasmaTex.value = plasmaRTA.texture;

        const idxRound = Math.max(0, Math.min(3, Math.round(currentMorph)));
        holoUniforms.uAccentCyan.value.lerp(chapterAccents[idxRound], 0.06);
      }

      // Update Screen-Space Fluid Wake
      const velX = currUV.x - prevUV.x;
      const velY = currUV.y - prevUV.y;
      const speed = Math.sqrt(velX * velX + velY * velY);

      fluidSimUniforms.uPrevTex.value = fluidRTA.texture;
      fluidSimUniforms.uMouse.value.copy(currUV);
      fluidSimUniforms.uPrevMouse.value.copy(prevUV);
      fluidSimUniforms.uVelocity.value.set(velX * 14.0, velY * 14.0);
      fluidSimUniforms.uStrength.value = Math.min(speed, 0.14);

      const prevToneMapping = renderer.toneMapping;
      renderer.toneMapping = THREE.NoToneMapping;
      renderer.setRenderTarget(fluidRTB);
      renderer.render(fluidSimScene, orthoCam);

      const tmpF = fluidRTA;
      fluidRTA = fluidRTB;
      fluidRTB = tmpF;
      prevUV.lerp(currUV, 0.65);

      // Render 3D World -> HDR SceneRT -> Anamorphic Compositor
      renderer.setRenderTarget(sceneRT);
      renderer.render(scene, camera);

      renderer.toneMapping = prevToneMapping;
      compUniforms.uSceneTex.value = sceneRT.texture;
      compUniforms.uFluidTex.value = fluidRTA.texture;
      renderer.setRenderTarget(null);
      renderer.render(compScene, orthoCam);
    };

    animate();

    return () => {
      window.removeEventListener("mousemove", handlePointerMove);
      window.removeEventListener("resize", handleResize);
      renderer.domElement.removeEventListener("click", handleCanvasClick);
      cancelAnimationFrame(animationId);
      if (container && renderer.domElement) {
        container.removeChild(renderer.domElement);
      }
      skyGeo.dispose();
      skyMat.dispose();
      floorGeo.dispose();
      floorMat.dispose();
      ceilingGeo.dispose();
      ceilingMat.dispose();
      portalGeo.dispose();
      portalMat.dispose();
      streakGeo.dispose();
      streakMat.dispose();
      dustGeo.dispose();
      dustMat.dispose();
      holoPlaneGeo.dispose();
      holoTextMat.dispose();
      hitProxyMesh.geometry.dispose();
      (hitProxyMesh.material as THREE.Material).dispose();
      quadGeo.dispose();
      plasmaSimMat.dispose();
      fluidSimMat.dispose();
      compMat.dispose();
      plasmaRTA.dispose();
      plasmaRTB.dispose();
      fluidRTA.dispose();
      fluidRTB.dispose();
      sceneRT.dispose();
      stageTextures.forEach((tx) => tx.dispose());
      scene.clear();
      renderer.dispose();
    };
  }, []);

  return <div ref={mountRef} className="fixed inset-0 w-full h-full z-0" />;
}
