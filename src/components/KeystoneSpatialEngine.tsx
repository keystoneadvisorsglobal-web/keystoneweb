"use client";

import React, { useRef, useEffect } from "react";
import * as THREE from "three";
import { RoomEnvironment } from "three/examples/jsm/environments/RoomEnvironment.js";
import { createNoise2D } from "simplex-noise";
import {
  SPATIAL_THEMES,
  DEFAULT_SPATIAL_THEME,
  type SpatialThemeId,
} from "@/config/spatialThemes";

export interface KeystoneSpatialEngineProps {
  /** Continuous scroll ratio from 0.0 (Chapter 0: SUMERGETE en tecnología Keystone) to 1.0 (Circular Loop End) */
  scrollRatio: number;
  /** Active chapter index:
   *  0 = Summit Hero ("SUMERGETE / en tecnología Keystone" in 72,000 dark basalt particles)
   *  1 = Service 1: Digital Core (Pure 3D Cloud Sculpture)
   *  2 = Service 2: DeepShield Defense (Complete Pointed Heraldic Shield & Armored Lock)
   *  3 = Service 3: KeyHub Suite (Architectural 3D Master Key)
   */
  activeChapter: number;
  /** Atmosphere & Mountain Color Preset (Admin/Backend customizable, preserves previous preset) */
  themeId?: SpatialThemeId;
  /** True when the user clicked the 3D pillar emblem to dissolve it into ambient mist and reveal service cards */
  isDissolved?: boolean;
  onSculptureClick?: () => void;
  onSculptureHoverChange?: (hovering: boolean) => void;
  replayTrigger?: number;
  onBootProgress?: (bootProg: number, hudReady: boolean) => void;
}

const BOOT_DURATION = 3.85;

/** Maps circular scrollRatio [0..1] into a continuous smooth morph target [0..3] with comfortable plateaus */
function computeContinuousMorph(r: number): number {
  const s = ((r % 1.0) + 1.0) % 1.0;
  // Plateau 0 (Inicio): 0.00..0.11
  if (s < 0.11) return 0.0;
  // Glide 0 -> 1: 0.11..0.24
  if (s < 0.24) return THREE.MathUtils.smoothstep(s, 0.11, 0.24);
  // Plateau 1 (Digital Core): 0.24..0.37
  if (s < 0.37) return 1.0;
  // Glide 1 -> 2: 0.37..0.50
  if (s < 0.50) return 1.0 + THREE.MathUtils.smoothstep(s, 0.37, 0.50);
  // Plateau 2 (DeepShield): 0.50..0.63
  if (s < 0.63) return 2.0;
  // Glide 2 -> 3: 0.63..0.76
  if (s < 0.76) return 2.0 + THREE.MathUtils.smoothstep(s, 0.63, 0.76);
  // Plateau 3 (KeyHub): 0.76..0.88
  if (s < 0.88) return 3.0;
  // Glide 3 -> 0 (Circular return to Hero): 0.88..0.995
  const backT = THREE.MathUtils.smoothstep(s, 0.88, 0.995);
  return 3.0 * (1.0 - backT);
}

export default function KeystoneSpatialEngine({
  scrollRatio,
  activeChapter,
  themeId = DEFAULT_SPATIAL_THEME,
  isDissolved = false,
  onSculptureClick,
  onSculptureHoverChange,
  replayTrigger = 0,
  onBootProgress,
}: KeystoneSpatialEngineProps) {
  const mountRef = useRef<HTMLDivElement>(null);
  const scrollRef = useRef(scrollRatio);
  const chapterRef = useRef(activeChapter);
  const themeRef = useRef<SpatialThemeId>(themeId);
  const dissolvedRef = useRef(isDissolved);
  const clickCbRef = useRef(onSculptureClick);
  const hoverCbRef = useRef(onSculptureHoverChange);
  const bootCbRef = useRef(onBootProgress);
  const bootStartRef = useRef<number>(0);

  scrollRef.current = scrollRatio;
  chapterRef.current = activeChapter;
  themeRef.current = themeId;
  dissolvedRef.current = isDissolved;
  clickCbRef.current = onSculptureClick;
  hoverCbRef.current = onSculptureHoverChange;
  bootCbRef.current = onBootProgress;

  useEffect(() => {
    bootStartRef.current = -1;
  }, [replayTrigger]);

  useEffect(() => {
    const container = mountRef.current;
    if (!container) return;

    const initialTheme = SPATIAL_THEMES[themeRef.current] || SPATIAL_THEMES[DEFAULT_SPATIAL_THEME];

    // --- 1. Scene, Sky Fog & WebGL Renderer ---
    const scene = new THREE.Scene();
    const liveSkyColor = new THREE.Color(initialTheme.skyHex);
    scene.background = liveSkyColor;
    const fogExp = new THREE.FogExp2(initialTheme.skyHex, initialTheme.fogDensity);
    scene.fog = fogExp;

    const camera = new THREE.PerspectiveCamera(
      38,
      container.clientWidth / Math.max(container.clientHeight, 1),
      0.1,
      320
    );
    camera.position.set(4.8, 8.6, 9.2);
    camera.lookAt(0, 0.1, 0);

    const renderer = new THREE.WebGLRenderer({
      antialias: true,
      powerPreference: "high-performance",
    });
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2.5));
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = initialTheme.exposure;
    container.appendChild(renderer.domElement);

    const pmremGenerator = new THREE.PMREMGenerator(renderer);
    pmremGenerator.compileEquirectangularShader();
    const envTex = pmremGenerator.fromScene(new RoomEnvironment(), 0.04).texture;
    scene.environment = envTex;
    scene.environmentIntensity = 0.68;

    // --- 1B. 4K CINEMATIC ATMOSPHERIC SKY DOME + ANAMORPHIC SUN HALO & CIRRUS CLOUDS ---
    const skyDomeUniforms = {
      uTime: { value: 0 },
      uZenithColor: { value: new THREE.Color(0x6e7b94) },
      uHorizonColor: { value: new THREE.Color(initialTheme.skyHex) },
      uSunGlowColor: { value: new THREE.Color(0xfff1dc) },
      uSunDir: { value: new THREE.Vector3(-0.62, 0.24, -0.74).normalize() },
    };

    const skyDomeGeo = new THREE.SphereGeometry(195, 48, 32);
    const skyDomeMat = new THREE.ShaderMaterial({
      uniforms: skyDomeUniforms,
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
        uniform vec3 uZenithColor;
        uniform vec3 uHorizonColor;
        uniform vec3 uSunGlowColor;
        uniform vec3 uSunDir;
        varying vec3 vWorldDir;

        float hash21(vec2 p) {
          p = fract(p * vec2(123.34, 456.21));
          p += dot(p, p + 45.32);
          return fract(p.x * p.y);
        }

        float noise2(vec2 p) {
          vec2 i = floor(p);
          vec2 f = fract(p);
          f = f * f * (3.0 - 2.0 * f);
          float a = hash21(i);
          float b = hash21(i + vec2(1.0, 0.0));
          float c = hash21(i + vec2(0.0, 1.0));
          float d = hash21(i + vec2(1.0, 1.0));
          return mix(mix(a, b, f.x), mix(c, d, f.x), f.y);
        }

        float fbmClouds(vec2 p) {
          float v = 0.0;
          float a = 0.5;
          mat2 rot = mat2(0.8, 0.6, -0.6, 0.8);
          for (int i = 0; i < 5; i++) {
            v += a * noise2(p);
            p = rot * p * 2.07;
            a *= 0.5;
          }
          return v;
        }

        void main() {
          vec3 dir = normalize(vWorldDir);
          float elev = clamp(dir.y, -0.15, 1.0);

          // Rayleigh-like cinematic sky gradient (deep alpine slate zenith -> muted slate-blue horizon, not washed-out white!)
          float horizonCurve = pow(1.0 - clamp(elev, 0.0, 1.0), 2.35);
          vec3 sky = mix(uZenithColor * 0.92, uHorizonColor * 1.04, horizonCurve);

          // Subtle atmospheric Mie solar scattering
          float sunAmt = max(dot(dir, uSunDir), 0.0);
          float mieHalo = pow(sunAmt, 9.0) * 0.22 + pow(sunAmt, 52.0) * 0.34;
          sky += uSunGlowColor * mieHalo;

          // High-altitude procedural 4K cirrus cloud deck drifting slowly across the sky
          if (dir.y > 0.01) {
            vec2 cloudUv = dir.xz / (dir.y + 0.16) * 1.45;
            cloudUv.x += uTime * 0.012;
            cloudUv.y -= uTime * 0.005;
            float c1 = fbmClouds(cloudUv);
            float c2 = fbmClouds(cloudUv * 2.3 - vec2(uTime * 0.018, 0.0));
            float cloudMask = smoothstep(0.50, 0.80, c1 * 0.72 + c2 * 0.28) * smoothstep(0.01, 0.22, dir.y);
            vec3 cloudLit = mix(uHorizonColor * 0.96, vec3(0.88, 0.90, 0.94), 0.35 + pow(sunAmt, 3.0) * 0.45);
            sky = mix(sky, cloudLit, cloudMask * 0.34);
          }

          // Restrained horizon haze band so mountain silhouettes carve sharply against the sky!
          float horizonBand = exp(-abs(dir.y - 0.02) * 14.0);
          sky = mix(sky, uHorizonColor * 1.08 + uSunGlowColor * 0.04, horizonBand * 0.32);

          gl_FragColor = vec4(sky, 1.0);
        }
      `,
    });
    const skyDomeMesh = new THREE.Mesh(skyDomeGeo, skyDomeMat);
    scene.add(skyDomeMesh);

    // --- 2. Load Baked Basalt & Glacial Frost PBR Textures (High-Definition Anisotropy & Tiling) ---
    const texLoader = new THREE.TextureLoader();
    const rockAlbedo = texLoader.load("/terrain/rock_albedo.jpg");
    const rockNormal = texLoader.load("/terrain/rock_normal.jpg");
    const rockRough = texLoader.load("/terrain/rock_roughness.jpg");

    const maxAniso = renderer.capabilities.getMaxAnisotropy();
    rockAlbedo.colorSpace = THREE.SRGBColorSpace;
    [rockAlbedo, rockNormal, rockRough].forEach((t) => {
      t.wrapS = THREE.RepeatWrapping;
      t.wrapT = THREE.RepeatWrapping;
      t.repeat.set(28, 28);
      t.anisotropy = maxAniso;
    });

    // --- 3. Sculptural Grazing Mountain Sun & Rim Lighting (Raking Angle for Sharp Ridge Relief) ---
    const sunLight = new THREE.DirectionalLight(initialTheme.sunColor, initialTheme.sunIntensity);
    sunLight.position.set(-22, 9.2, 7.5);
    sunLight.castShadow = true;
    sunLight.shadow.mapSize.width = 4096;
    sunLight.shadow.mapSize.height = 4096;
    sunLight.shadow.camera.near = 2;
    sunLight.shadow.camera.far = 85;
    const sBound = 28;
    sunLight.shadow.camera.left = -sBound;
    sunLight.shadow.camera.right = sBound;
    sunLight.shadow.camera.top = sBound;
    sunLight.shadow.camera.bottom = -sBound;
    sunLight.shadow.bias = -0.0004;
    scene.add(sunLight);

    const coolBackLight = new THREE.DirectionalLight(initialTheme.rimColor, initialTheme.rimIntensity);
    coolBackLight.position.set(14, 7, -14);
    scene.add(coolBackLight);

    const ambientFill = new THREE.AmbientLight(initialTheme.ambientColor, initialTheme.ambientIntensity);
    scene.add(ambientFill);

    // =========================================================================
    // 4. 4K IMAX ALPINE RIDGED-MULTIFRACTAL CORDILLERA + GLACIAL COULOIRS (480x400 Mesh)
    // =========================================================================
    const noise2D = createNoise2D(() => 0.37);
    const fbmRock = (x: number, z: number) => {
      let val = 0;
      let amp = 1.0;
      let freq = 0.064;
      let weight = 1.0;
      for (let o = 0; o < 8; o++) {
        const n = noise2D(x * freq, z * freq);
        // Razor-sharp knife-edge alpine arête folding + glacial gully erosion!
        let signal = 1.0 - Math.abs(n);
        signal = Math.pow(signal, 2.55) * weight;
        weight = Math.min(1.0, Math.max(0.16, signal * 2.05));
        val += signal * amp;
        amp *= 0.49;
        freq *= 2.14;
      }
      return val;
    };

    const terrainGeo = new THREE.PlaneGeometry(168, 132, 480, 400);
    terrainGeo.rotateX(-Math.PI / 2);
    terrainGeo.translate(0, 0, -38);

    const tPos = terrainGeo.attributes.position;
    const tColors = new Float32Array(tPos.count * 3);
    const vertexMeta = new Float32Array(tPos.count * 3); // [frostFactor, mountainRise, creviceAO]

    for (let i = 0; i < tPos.count; i++) {
      const vx = tPos.getX(i);
      const vz = tPos.getZ(i);

      const distSummit = Math.sqrt(vx * vx * 0.55 + vz * vz);
      const centralSummit = Math.exp(-(distSummit * distSummit) / 30.0) * 1.95;
      const stageSeat = Math.exp(-(vx * vx * 0.38 + vz * vz) / 8.5);

      // Dramatic framing foreground cliff shoulders on left & right (IMAX depth composition)
      const fgLeft = Math.exp(-((vx + 7.4) ** 2 + (vz - 3.2) ** 2) / 13.0) * 1.85;
      const fgRight = Math.exp(-((vx - 7.8) ** 2 + (vz - 2.6) ** 2) / 14.5) * 2.05;

      // Deep glacial valley corridor in center leading the eye toward towering cathedral peaks!
      const valleyCarve = Math.exp(-(vx * vx) / 42.0) * THREE.MathUtils.smoothstep(-vz, 6.0, 38.0) * 2.4;

      const mountainRise = THREE.MathUtils.smoothstep(-vz, 7.0, 68.0);
      const leftPeak =
        Math.exp(-((vx + 18.5) ** 2) / 120.0 - ((vz + 35.0) ** 2) / 165.0) * 22.5;
      const rightPeak =
        Math.exp(-((vx - 21.0) ** 2) / 140.0 - ((vz + 33.5) ** 2) / 175.0) * 24.2;
      const centerCathedralHorn =
        Math.exp(-((vx - 1.6) ** 2) / 165.0 - ((vz + 54.0) ** 2) / 210.0) * 21.5;
      const farLeftMassif =
        Math.exp(-((vx + 42.0) ** 2) / 260.0 - ((vz + 64.0) ** 2) / 280.0) * 26.0;
      const farRightMassif =
        Math.exp(-((vx - 44.0) ** 2) / 280.0 - ((vz + 62.0) ** 2) / 290.0) * 27.5;
      const secondarySpurLeft =
        Math.exp(-((vx + 9.8) ** 2) / 52.0 - ((vz + 21.5) ** 2) / 72.0) * 8.4;
      const secondarySpurRight =
        Math.exp(-((vx - 10.8) ** 2) / 56.0 - ((vz + 23.0) ** 2) / 78.0) * 9.1;
      const horizonWall = mountainRise * 6.8;

      // Domain-warped rock folding so ridges twist organically like real Patagonian / Alpine granite!
      const warpX = noise2D(vx * 0.045, vz * 0.045) * 3.8;
      const warpZ = noise2D(vx * 0.045 + 31.7, vz * 0.045 - 18.2) * 3.8;
      const macroCrags = fbmRock(vx + warpX + 13.7, vz + warpZ - 9.4);
      const fineCouloirs = Math.pow(1.0 - Math.abs(noise2D(vx * 0.34, vz * 0.28)), 3.0) * 0.55;

      const microStrata =
        noise2D(vx * 1.15 + vz * 0.42, vz * 1.35) * 0.22 * (1.0 - stageSeat * 0.75);
      const cragAmp = THREE.MathUtils.lerp(1.55, 6.25, mountainRise) * (1.0 - stageSeat * 0.58);

      const yFinal =
        -3.85 +
        centralSummit * (1.0 - stageSeat * 0.30) +
        fgLeft +
        fgRight -
        valleyCarve +
        horizonWall +
        leftPeak +
        rightPeak +
        centerCathedralHorn +
        farLeftMassif +
        farRightMassif +
        secondarySpurLeft +
        secondarySpurRight +
        macroCrags * cragAmp +
        fineCouloirs * mountainRise * 1.65 +
        microStrata;

      tPos.setY(i, yFinal);

      // Sharp contrast between chiseled slate rock and subtle frost only on knife-edge ridge crests!
      const frostFactor = Math.min(
        1,
        Math.max(0, (macroCrags - 0.64) * 1.85)
      );
      const creviceAO = THREE.MathUtils.clamp(0.32 + macroCrags * 0.72, 0.28, 1.12);

      vertexMeta[i * 3] = frostFactor;
      vertexMeta[i * 3 + 1] = mountainRise;
      vertexMeta[i * 3 + 2] = creviceAO;
    }

    const applyTerrainThemeColors = (tId: SpatialThemeId) => {
      const th = SPATIAL_THEMES[tId] || SPATIAL_THEMES[DEFAULT_SPATIAL_THEME];
      for (let i = 0; i < tPos.count; i++) {
        const frost = vertexMeta[i * 3];
        const mRise = vertexMeta[i * 3 + 1];
        const creviceAO = vertexMeta[i * 3 + 2];
        const shade = (th.terrainBaseShade * creviceAO) + th.terrainFrostBoost * frost;
        const rMul = THREE.MathUtils.lerp(th.nearTint[0], th.farTint[0], mRise);
        const gMul = THREE.MathUtils.lerp(th.nearTint[1], th.farTint[1], mRise);
        const bMul = THREE.MathUtils.lerp(th.nearTint[2], th.farTint[2], mRise);
        tColors[i * 3] = shade * rMul;
        tColors[i * 3 + 1] = shade * gMul;
        tColors[i * 3 + 2] = shade * bMul;
      }
      if (terrainGeo.attributes.color) {
        (terrainGeo.attributes.color as THREE.BufferAttribute).needsUpdate = true;
      }
    };

    applyTerrainThemeColors(themeRef.current);
    terrainGeo.setAttribute("color", new THREE.BufferAttribute(tColors, 3));
    terrainGeo.computeVertexNormals();

    const terrainScanUniforms = {
      uRevealRadius: { value: 0.0 },
      uTime: { value: 0.0 },
      uAerialMistColor: { value: new THREE.Color(0xcbd5e1) },
    };

    const terrainMat = new THREE.MeshStandardMaterial({
      map: rockAlbedo,
      normalMap: rockNormal,
      normalScale: new THREE.Vector2(4.15, 4.15),
      roughnessMap: rockRough,
      roughness: 0.72,
      metalness: 0.14,
      vertexColors: true,
    });

    terrainMat.onBeforeCompile = (shader) => {
      shader.uniforms.uRevealRadius = terrainScanUniforms.uRevealRadius;
      shader.uniforms.uTime = terrainScanUniforms.uTime;
      shader.uniforms.uAerialMistColor = terrainScanUniforms.uAerialMistColor;
      shader.vertexShader = shader.vertexShader
        .replace(
          "#include <common>",
          `#include <common>
           varying vec3 vWorldTerrainPos;
           varying vec3 vWorldTerrainNormal;`
        )
        .replace(
          "#include <worldpos_vertex>",
          `#include <worldpos_vertex>
           vWorldTerrainPos = (modelMatrix * vec4(transformed, 1.0)).xyz;
           vWorldTerrainNormal = normalize(mat3(modelMatrix) * objectNormal);`
        );

      shader.fragmentShader = shader.fragmentShader
        .replace(
          "#include <common>",
          `#include <common>
           uniform float uRevealRadius;
           uniform float uTime;
           uniform vec3 uAerialMistColor;
           varying vec3 vWorldTerrainPos;
           varying vec3 vWorldTerrainNormal;

           // 4K Multi-scale procedural rock micro-detail & snow crystalline sparkle (zero texture tiling!)
           float hashT(vec2 p) {
             p = fract(p * vec2(234.34, 435.345));
             p += dot(p, p + 34.23);
             return fract(p.x * p.y);
           }
           float noiseT(vec2 p) {
             vec2 i = floor(p);
             vec2 f = fract(p);
             f = f * f * (3.0 - 2.0 * f);
             return mix(
               mix(hashT(i), hashT(i + vec2(1.0, 0.0)), f.x),
               mix(hashT(i + vec2(0.0, 1.0)), hashT(i + vec2(1.0, 1.0)), f.x),
               f.y
             );
           }`
        )
        .replace(
          "#include <color_fragment>",
          `#include <color_fragment>
           float slopeUp = clamp(vWorldTerrainNormal.y, 0.0, 1.0);
           float cliffFace = smoothstep(0.92, 0.34, slopeUp);

           // Ultra-crisp 4K geological granite fractures, couloir chutes & chiseled rock fissures!
           float macroNoise = noiseT(vWorldTerrainPos.xz * 0.22);
           float midDetail = noiseT(vWorldTerrainPos.xz * 1.45 + vec2(macroNoise * 1.5));
           float fineRock = noiseT(vWorldTerrainPos.xz * 7.8);
           float microCracks = abs(noiseT(vWorldTerrainPos.xz * 16.5) - 0.5) * 2.0;
           float strata = sin(vWorldTerrainPos.y * 6.8 + vWorldTerrainPos.x * 0.58 + macroNoise * 4.5) * 0.5 + 0.5;

           // Rich, sharply defined Slate-Basalt & Alpine Granite rock body (eliminating washed-out white snow!)
           vec3 deepBasalt = vec3(0.14, 0.16, 0.22) * (0.62 + 0.52 * strata) * (0.76 + 0.38 * fineRock) * (0.85 + 0.22 * microCracks);
           vec3 slateBench = vec3(0.32, 0.36, 0.44) * (0.80 + 0.32 * midDetail) * (0.86 + 0.24 * fineRock);
           vec3 definedRock = mix(slateBench, deepBasalt, cliffFace);
           diffuseColor.rgb = mix(diffuseColor.rgb * 0.78, definedRock, 0.84);

           // Restrained, surgical frost veins ONLY on the highest razor crests & narrow couloir ledges (no white washout!)
           float ridgeCrest = smoothstep(0.84, 0.97, slopeUp + midDetail * 0.14 - 0.06) * smoothstep(2.2, 12.5, vWorldTerrainPos.y + macroNoise * 2.0);
           vec3 coolMineralFrost = vec3(0.76, 0.81, 0.89) * (0.88 + 0.16 * fineRock);
           diffuseColor.rgb = mix(diffuseColor.rgb, coolMineralFrost, ridgeCrest * 0.38);`
        )
        .replace(
          "#include <roughnessmap_fragment>",
          `#include <roughnessmap_fragment>
           // Crisp mineral rock sheen on steep granite facets so ridges catch directional sunlight sharply
           float rSlope = clamp(vWorldTerrainNormal.y, 0.0, 1.0);
           roughnessFactor = mix(0.42, 0.78, smoothstep(0.30, 0.85, rSlope));`
        )
        .replace(
          "#include <dithering_fragment>",
          `#include <dithering_fragment>
           // Subtle deep-canyon blue-slate atmospheric depth (keeps mountain ridges crisp & dark, never milky white!)
           float valleyCloudPlane = -2.45 + sin(vWorldTerrainPos.x * 0.12 + uTime * 0.28) * 0.35 + cos(vWorldTerrainPos.z * 0.10 - uTime * 0.22) * 0.35;
           float lowMist = smoothstep(valleyCloudPlane + 1.8, valleyCloudPlane - 1.4, vWorldTerrainPos.y) * smoothstep(6.0, 34.0, -vWorldTerrainPos.z);
           float distDepth = smoothstep(28.0, 96.0, length(vWorldTerrainPos.xz));
           vec3 mistCol = uAerialMistColor * 0.82;
           gl_FragColor.rgb = mix(gl_FragColor.rgb, mistCol, clamp(lowMist * 0.28 + distDepth * 0.24, 0.0, 0.42));

           if (uRevealRadius < 135.0) {
             float ang = atan(vWorldTerrainPos.z, vWorldTerrainPos.x);
             float jagged = sin(ang * 19.0) * 0.55 + cos(ang * 37.0 + vWorldTerrainPos.x * 1.4) * 0.42;
             float distCenter = length(vWorldTerrainPos.xz) + jagged;
             if (distCenter > uRevealRadius) discard;

             float rimBand = 1.0 - smoothstep(0.0, 2.3, uRevealRadius - distCenter);
             gl_FragColor.rgb = mix(gl_FragColor.rgb, vec3(0.85, 0.90, 0.98), rimBand * 0.85);
           }`
        );
    };

    const terrainMesh = new THREE.Mesh(terrainGeo, terrainMat);
    terrainMesh.receiveShadow = true;
    terrainMesh.castShadow = true;
    scene.add(terrainMesh);

    // --- 4B. VOLUMETRIC DRIFTING VALLEY CLOUD SEA & CINEMATIC GOD-RAYS ---
    const createCloudPuffTexture = (): THREE.CanvasTexture => {
      const sz = 256;
      const c = document.createElement("canvas");
      c.width = sz;
      c.height = sz;
      const ctx = c.getContext("2d");
      if (ctx) {
        ctx.clearRect(0, 0, sz, sz);
        for (let p = 0; p < 7; p++) {
          const ox = sz * (0.36 + (p % 3) * 0.14);
          const oy = sz * (0.42 + Math.floor(p / 3) * 0.11);
          const rad = sz * (0.26 + (p % 2) * 0.09);
          const g = ctx.createRadialGradient(ox, oy, rad * 0.08, ox, oy, rad);
          g.addColorStop(0, "rgba(255, 255, 255, 0.24)");
          g.addColorStop(0.45, "rgba(241, 245, 249, 0.11)");
          g.addColorStop(1, "rgba(241, 245, 249, 0)");
          ctx.fillStyle = g;
          ctx.beginPath();
          ctx.arc(ox, oy, rad, 0, Math.PI * 2);
          ctx.fill();
        }
      }
      const tx = new THREE.CanvasTexture(c);
      tx.needsUpdate = true;
      return tx;
    };

    const cloudPuffTex = createCloudPuffTexture();
    const valleyCloudsGroup = new THREE.Group();
    scene.add(valleyCloudsGroup);

    interface CloudBankItem {
      mesh: THREE.Mesh;
      baseX: number;
      baseY: number;
      baseZ: number;
      speed: number;
      phase: number;
    }
    const cloudBanks: CloudBankItem[] = [];
    const cloudPlaneGeo = new THREE.PlaneGeometry(24, 11);

    for (let i = 0; i < 22; i++) {
      const depthT = i / 21;
      const cz = -7.5 - depthT * 46.0;
      const cx = ((i % 2 === 0 ? -1 : 1) * (4.0 + (i % 5) * 4.8)) + Math.sin(i * 2.1) * 3.5;
      const cy = -1.65 + depthT * 3.2 + (i % 3) * 0.45;
      const cMat = new THREE.MeshBasicMaterial({
        map: cloudPuffTex,
        color: 0xbac4d6,
        transparent: true,
        opacity: 0.09 + (1.0 - depthT) * 0.07,
        depthWrite: false,
        blending: THREE.NormalBlending,
      });
      const cMesh = new THREE.Mesh(cloudPlaneGeo, cMat);
      const scaleW = 0.85 + (i % 4) * 0.35 + depthT * 0.8;
      cMesh.scale.set(scaleW, 0.75 + (i % 3) * 0.22, 1);
      cMesh.position.set(cx, cy, cz);
      valleyCloudsGroup.add(cMesh);
      cloudBanks.push({
        mesh: cMesh,
        baseX: cx,
        baseY: cy,
        baseZ: cz,
        speed: 0.22 + (i % 5) * 0.08,
        phase: i * 1.37,
      });
    }

    // Subtle volumetric cinematic sunbeams (God-rays) slanting from upper-left peak crest
    const godRayUniforms = {
      uTime: { value: 0 },
      uRayColor: { value: new THREE.Color(0xfff4e0) },
    };
    const godRayGeo = new THREE.PlaneGeometry(52, 34);
    const godRayMat = new THREE.ShaderMaterial({
      uniforms: godRayUniforms,
      transparent: true,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
      vertexShader: /* glsl */ `
        varying vec2 vUv;
        void main() {
          vUv = uv;
          gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
        }
      `,
      fragmentShader: /* glsl */ `
        uniform float uTime;
        uniform vec3 uRayColor;
        varying vec2 vUv;
        void main() {
          // Diagonal sunbeam shafts streaming from top-left toward valley floor
          float coord = vUv.x * 0.82 + vUv.y * 0.48;
          float shafts =
            sin(coord * 22.0 - uTime * 0.25) * 0.5 + 0.5;
          float fineShafts =
            sin(coord * 47.0 + uTime * 0.18) * 0.5 + 0.5;
          float beam = pow(shafts * 0.65 + fineShafts * 0.35, 2.6);

          // Soft vignette mask so rays have zero hard edges
          float radialFade =
            smoothstep(0.0, 0.35, vUv.x) *
            smoothstep(1.0, 0.42, vUv.x) *
            smoothstep(0.0, 0.45, vUv.y) *
            smoothstep(1.0, 0.18, vUv.y);

          float alpha = beam * radialFade * 0.045;
          gl_FragColor = vec4(uRayColor, alpha);
        }
      `,
    });
    const godRayMesh = new THREE.Mesh(godRayGeo, godRayMat);
    godRayMesh.position.set(-8.5, 6.2, -22.0);
    godRayMesh.rotation.z = -0.32;
    scene.add(godRayMesh);

    // =========================================================================
    // 5. GENESIS ARCHITECTURAL 3D WIREFRAME MATRIX (Boot Intro)
    // =========================================================================
    const genesisGridGroup = new THREE.Group();
    scene.add(genesisGridGroup);

    const gridSegPoints: THREE.Vector3[] = [];
    const gridNodePositions: number[] = [];
    const G_HALF = 5;
    const G_STEP = 2.05;

    for (let gx = -G_HALF; gx <= G_HALF; gx++) {
      for (let gz = -G_HALF; gz <= G_HALF; gz++) {
        const x0 = gx * G_STEP;
        const z0 = gz * G_STEP - 1.5;
        const y0 =
          -1.8 +
          Math.exp(-(x0 * x0 + z0 * z0) / 28.0) * 1.6 +
          ((Math.abs(gx * 3 + gz * 5) % 3) - 1) * 0.35;

        gridNodePositions.push(x0, y0, z0);

        if (gx < G_HALF) {
          const x1 = (gx + 1) * G_STEP;
          const y1 =
            -1.8 +
            Math.exp(-(x1 * x1 + z0 * z0) / 28.0) * 1.6 +
            ((Math.abs((gx + 1) * 3 + gz * 5) % 3) - 1) * 0.35;
          if ((gx + gz) % 2 === 0) {
            gridSegPoints.push(new THREE.Vector3(x0, y0, z0), new THREE.Vector3(x1, y1, z0));
          }
          if ((gx * 7 + gz) % 4 === 0) {
            gridSegPoints.push(
              new THREE.Vector3(x0, y0, z0),
              new THREE.Vector3(x0, y0 + 1.15, z0)
            );
            gridSegPoints.push(
              new THREE.Vector3(x0, y0 + 1.15, z0),
              new THREE.Vector3(x1, y1, z0)
            );
          }
        }
        if (gz < G_HALF) {
          const z1 = (gz + 1) * G_STEP - 1.5;
          const y2 =
            -1.8 +
            Math.exp(-(x0 * x0 + z1 * z1) / 28.0) * 1.6 +
            ((Math.abs(gx * 3 + (gz + 1) * 5) % 3) - 1) * 0.35;
          if ((gx - gz) % 2 === 0) {
            gridSegPoints.push(new THREE.Vector3(x0, y0, z0), new THREE.Vector3(x0, y2, z1));
          }
        }
      }
    }

    const genesisWireMat = new THREE.LineBasicMaterial({
      color: 0xffffff,
      transparent: true,
      opacity: 0.0,
      depthWrite: false,
    });
    genesisGridGroup.add(
      new THREE.LineSegments(new THREE.BufferGeometry().setFromPoints(gridSegPoints), genesisWireMat)
    );

    const genesisNodesMat = new THREE.PointsMaterial({
      color: 0xffffff,
      size: 0.065,
      transparent: true,
      opacity: 0.0,
      depthWrite: false,
    });
    const genesisNodesGeo = new THREE.BufferGeometry();
    genesisNodesGeo.setAttribute(
      "position",
      new THREE.Float32BufferAttribute(gridNodePositions, 3)
    );
    genesisGridGroup.add(new THREE.Points(genesisNodesGeo, genesisNodesMat));

    // =========================================================================
    // 6. SOLID JET-BLACK TYPOGRAPHY (+30% LARGER) + CINEMATIC BLACK SMOKE DISSIPATION
    //    - 100% Razor-Sharp Solid Black Letters at rest (elegant, ultra-legible, luminous separation halo)
    //    - Interactive Persistent Smoke Simulation: touching/gliding with cursor evaporates letter strokes
    //      into billowing curling black ink-smoke plumes that rise, fade, and smoothly reform!
    // =========================================================================
    const buildSolidStageTexture = (
      mode: "hero" | "monogram",
      word: string
    ): THREE.CanvasTexture => {
      // Extra-wide 3072x1024 canvas with generous airspace above & around so smoke plumes never clip!
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
          // Clean, solid, architectural high-fashion sans-serif (+30% larger in world space!)
          drawTracked(
            "SUMERGETE",
            '800 310px "Plus Jakarta Sans", "Avenir Next", -apple-system, sans-serif',
            22,
            430
          );
          drawTracked(
            "en tecnología Keystone",
            '600 116px "Plus Jakarta Sans", "Avenir Next", -apple-system, sans-serif',
            10.5,
            685
          );
        } else {
          drawTracked(
            word,
            '800 450px "Plus Jakarta Sans", "Avenir Next", -apple-system, sans-serif',
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
      buildSolidStageTexture("hero", "SUMERGETE"),
      buildSolidStageTexture("monogram", "CRM"),
      buildSolidStageTexture("monogram", "DSX"),
      buildSolidStageTexture("monogram", "KHS"),
    ];

    // Persistent Ping-Pong Smoke Trail Buffer on the Text Plane (256x128)
    // Stores swirling smoke excitation where the user's cursor touches the solid black letters!
    const makeTextSmokeRT = () =>
      new THREE.WebGLRenderTarget(256, 128, {
        minFilter: THREE.LinearFilter,
        magFilter: THREE.LinearFilter,
        type: THREE.HalfFloatType,
        depthBuffer: false,
        stencilBuffer: false,
      });

    let smokeRTA = makeTextSmokeRT();
    let smokeRTB = makeTextSmokeRT();

    // Plane +30% larger than previous 4.38m span -> 7.65m wide x 2.55m tall (wordmark spans ~5.72m = +30%!)
    const PLANE_W = 7.65;
    const PLANE_H = 2.55;
    const solidTextGeo = new THREE.PlaneGeometry(PLANE_W, PLANE_H, 1, 1);

    const smokeSimUniforms = {
      uPrevSmoke: { value: smokeRTA.texture },
      uPointerUV: { value: new THREE.Vector2(-10, -10) },
      uPrevPointerUV: { value: new THREE.Vector2(-10, -10) },
      uPointerVel: { value: new THREE.Vector2(0, 0) },
      uPointerActive: { value: 0.0 },
      uTime: { value: 0.0 },
      uAspect: { value: PLANE_W / PLANE_H },
    };

    const smokeSimMat = new THREE.ShaderMaterial({
      uniforms: smokeSimUniforms,
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
        uniform sampler2D uPrevSmoke;
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
          vec4 cur = texture2D(uPrevSmoke, vUv);

          // Buoyant upward smoke rise (higher vUv.y = upward) + turbulent curl drift
          float curlX = sin(vUv.y * 14.0 + uTime * 2.1) * 0.0022;
          vec2 advUv = vUv - vec2(curlX + cur.x * 0.004, 0.0042 + cur.y * 0.003);

          vec4 sC = texture2D(uPrevSmoke, advUv);
          vec4 sL = texture2D(uPrevSmoke, advUv - vec2(texel.x, 0.0));
          vec4 sR = texture2D(uPrevSmoke, advUv + vec2(texel.x, 0.0));
          vec4 sU = texture2D(uPrevSmoke, advUv + vec2(0.0, texel.y));
          vec4 sD = texture2D(uPrevSmoke, advUv - vec2(0.0, texel.y));
          vec4 diffused = mix(sC, (sL + sR + sU + sD) * 0.25, 0.36);

          // Smooth cinematic decay so letters reform cleanly after the smoke clears (~1.6s)
          vec2 vel = diffused.xy * 0.94;
          float density = diffused.z * 0.964;

          if (uPointerActive > 0.01) {
            float d = distToSegment(vUv, uPrevPointerUV, uPointerUV);
            // Generous soft smoke brush around cursor touch
            float brush = exp(-(d * d) / (0.125 * 0.125));
            vel += uPointerVel * brush * 0.65;
            density = clamp(density + brush * (0.38 + length(uPointerVel) * 5.0) * uPointerActive, 0.0, 1.30);
          }

          gl_FragColor = vec4(vel, density, 1.0);
        }
      `,
    });

    const smokeSimScene = new THREE.Scene();
    const smokeQuadGeo = new THREE.PlaneGeometry(2, 2);
    smokeSimScene.add(new THREE.Mesh(smokeQuadGeo, smokeSimMat));

    const uniforms = {
      uTime: { value: 0 },
      uMorph: { value: 0 },
      uOpacity: { value: 0 },
      uDisperseIn: { value: 0 },
      uDissolve: { value: 0 },
      uHoverPulse: { value: 0 },
      uDarkHeroMix: { value: 1 },
      uPointer3D: { value: new THREE.Vector3(0, 0, 10) },
      uAccentColor: { value: new THREE.Color(0x1e293b) },
      uTex0: { value: stageTextures[0] },
      uTex1: { value: stageTextures[1] },
      uTex2: { value: stageTextures[2] },
      uTex3: { value: stageTextures[3] },
      uSmokeTex: { value: smokeRTA.texture },
    };

    const particleMat = new THREE.ShaderMaterial({
      uniforms,
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
        uniform sampler2D uTex0;
        uniform sampler2D uTex1;
        uniform sampler2D uTex2;
        uniform sampler2D uTex3;
        uniform sampler2D uSmokeTex;

        varying vec2 vUv;

        // High-definition procedural FBM Curl-Noise for realistic cinematic billowing ink/smoke!
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

        float fbmSmoke(vec2 p) {
          float v = 0.0;
          float amp = 0.5;
          mat2 rot = mat2(0.80, 0.60, -0.60, 0.80);
          for (int i = 0; i < 5; i++) {
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

          // Read interactive cursor smoke field + global transition/dissolve smoke
          vec4 smokeData = texture2D(uSmokeTex, vUv);
          float localSmoke = clamp(smokeData.z, 0.0, 1.25);
          float globalSmoke = clamp(morphBell * 0.85 + uDisperseIn * 0.75 + dEase * 1.15, 0.0, 1.25);
          float totalSmoke = clamp(localSmoke + globalSmoke, 0.0, 1.35);

          // 1. CRISP, RAZOR-SHARP SOLID BLACK LETTER MASK AT REST (100% clean & legible!)
          float crispAlpha = sampleMorphText(vUv, m);

          // 2. CINEMATIC BILLOWING BLACK SMOKE WHEN TOUCHED OR TRANSITIONING
          vec2 smokeCoord = vUv * vec2(15.0, 6.0);
          float n1 = fbmSmoke(smokeCoord + vec2(-uTime * 0.45, -uTime * 0.85));
          float n2 = fbmSmoke(smokeCoord * 2.15 + vec2(n1 * 2.2 + uTime * 0.35, -uTime * 1.25));
          float wispNoise = n1 * 0.55 + n2 * 0.45;

          // Turbulent curling displacement: sample BELOW current pixel (-Y in UV) so smoke billows UPWARD (+Y)!
          vec2 curlDisp = vec2(
            (n2 - 0.48) * 0.092 - smokeData.x * 0.065,
            -(0.028 + n1 * 0.105) - abs(smokeData.y) * 0.042
          ) * totalSmoke;

          // Multi-sample blurred smoke plume trailing upward & outward from the solid letters
          float s0 = sampleMorphText(vUv + curlDisp * 0.52, m);
          float s1 = sampleMorphText(vUv + curlDisp * 1.05 + vec2(0.014, -0.016) * totalSmoke, m);
          float s2 = sampleMorphText(vUv + curlDisp * 1.65 + vec2(-0.016, -0.028) * totalSmoke, m);
          float smokePlume = (s0 * 0.48 + s1 * 0.32 + s2 * 0.20);

          // Erode/evaporate the solid letter smoothly into wisps where touched by cursor!
          float erodeMask = smoothstep(
            totalSmoke * 0.76 - 0.06,
            totalSmoke * 0.76 + 0.24,
            crispAlpha * (0.58 + wispNoise * 0.72)
          );
          float solidCore = crispAlpha * mix(1.0, erodeMask, smoothstep(0.02, 0.42, totalSmoke));

          // Soft curling black smoke cloud fading smoothly into mountain air
          float vaporWisps =
            smokePlume *
            smoothstep(0.16, 0.84, wispNoise + 0.24) *
            smoothstep(0.01, 0.32, totalSmoke) *
            clamp(1.12 - totalSmoke * 0.55, 0.0, 1.0) *
            0.90;

          float finalLetterAlpha = clamp(max(solidCore, vaporWisps), 0.0, 1.0);
          finalLetterAlpha *= mix(1.0, 0.0, smoothstep(0.0, 0.96, dEase));

          // 3. SUBTLE LUMINOUS ATMOSPHERIC BACK-GLOW SO SOLID BLACK LETTERS NEVER GET LOST AGAINST THE MOUNTAINS!
          float haloA =
            sampleMorphText(vUv + vec2(0.009, 0.0), m) +
            sampleMorphText(vUv - vec2(0.009, 0.0), m) +
            sampleMorphText(vUv + vec2(0.0, 0.024), m) +
            sampleMorphText(vUv - vec2(0.0, 0.024), m) +
            sampleMorphText(vUv + vec2(0.006, 0.016), m) +
            sampleMorphText(vUv - vec2(0.006, 0.016), m);
          float softHalo = clamp(haloA / 6.0, 0.0, 1.0) * (1.0 - finalLetterAlpha) * (1.0 - dEase);

          float combinedAlpha = clamp(finalLetterAlpha + softHalo * 0.46, 0.0, 1.0) * uOpacity;
          if (combinedAlpha <= 0.004) discard;

          // Deep Solid Pure Black (#04060A) for letters & rich black smoke wisps, soft misty separation aura behind
          vec3 solidPitchBlack = vec3(0.0014, 0.0018, 0.0030);
          vec3 blackSmokeTone = vec3(0.0048, 0.0062, 0.0098);
          vec3 letterCol = mix(blackSmokeTone, solidPitchBlack, clamp(solidCore, 0.0, 1.0));

          vec3 mistAuraCol = vec3(0.92, 0.95, 1.0);
          float letterWeight = clamp(finalLetterAlpha / max(combinedAlpha, 0.001), 0.0, 1.0);
          vec3 outCol = mix(mistAuraCol, letterCol, letterWeight);

          gl_FragColor = vec4(outCol, combinedAlpha);
        }
      `,
    });

    const sculptureGroup = new THREE.Group();
    sculptureGroup.position.set(0, 0.72, 0.28);
    scene.add(sculptureGroup);

    const particleSystem = new THREE.Mesh(solidTextGeo, particleMat);
    particleSystem.frustumCulled = false;
    sculptureGroup.add(particleSystem);

    const prevHitUV = new THREE.Vector2(-10, -10);
    const currHitUV = new THREE.Vector2(-10, -10);
    let hasSmokeHit = false;

    // Atmospheric Snow
    const snowCount = 550;
    const snowPos = new Float32Array(snowCount * 3);
    for (let i = 0; i < snowCount * 3; i += 3) {
      snowPos[i] = (Math.random() - 0.5) * 28;
      snowPos[i + 1] = (Math.random() - 0.2) * 14;
      snowPos[i + 2] = (Math.random() - 0.65) * 26;
    }
    const snowGeo = new THREE.BufferGeometry();
    snowGeo.setAttribute("position", new THREE.BufferAttribute(snowPos, 3));
    const snowPoints = new THREE.Points(
      snowGeo,
      new THREE.PointsMaterial({
        color: 0xffffff,
        size: 0.038,
        transparent: true,
        opacity: 0.46,
      })
    );
    scene.add(snowPoints);

    // --- 7. Pointer Interaction, Liquid Iridescent Wake & Resize ---
    let mouseNDCX = 0;
    let mouseNDCY = 0;
    let smoothMouseX = 0;
    let smoothMouseY = 0;
    let isHoveringSculpture = false;

    // -------------------------------------------------------------------------
    // LIQUID PRISMATIC CURSOR WAKE (Ping-Pong FBO Fluid Ripple + Chromatic Refraction)
    // -------------------------------------------------------------------------
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
    const makeSimRT = (w: number, h: number) =>
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

    let simRTA = makeSimRT(initW, initH);
    let simRTB = makeSimRT(initW, initH);

    const orthoCam = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);
    const quadGeo = new THREE.PlaneGeometry(2, 2);

    const simUniforms = {
      uPrevTex: { value: simRTA.texture },
      uTexel: { value: new THREE.Vector2(1 / simRTA.width, 1 / simRTA.height) },
      uAspect: { value: initW / initH },
      uMouse: { value: new THREE.Vector2(0.5, 0.5) },
      uPrevMouse: { value: new THREE.Vector2(0.5, 0.5) },
      uVelocity: { value: new THREE.Vector2(0, 0) },
      uStrength: { value: 0.0 },
      uTime: { value: 0.0 },
    };

    const simMat = new THREE.ShaderMaterial({
      uniforms: simUniforms,
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
        uniform float uTime;
        varying vec2 vUv;

        // Distance from point p to segment a->b with aspect correction
        vec2 distSegment(vec2 p, vec2 a, vec2 b) {
          vec2 pa = p - a;
          vec2 ba = b - a;
          pa.x *= uAspect;
          ba.x *= uAspect;
          float h = clamp(dot(pa, ba) / max(dot(ba, ba), 0.00001), 0.0, 1.0);
          return vec2(length(pa - ba * h), h);
        }

        void main() {
          // Semi-Lagrangian advection + viscous wave diffusion
          vec4 center = texture2D(uPrevTex, vUv);
          vec2 advUv = vUv - center.xy * 0.0055;

          vec4 c = texture2D(uPrevTex, advUv);
          vec4 l = texture2D(uPrevTex, advUv - vec2(uTexel.x, 0.0));
          vec4 r = texture2D(uPrevTex, advUv + vec2(uTexel.x, 0.0));
          vec4 t = texture2D(uPrevTex, advUv + vec2(0.0, uTexel.y));
          vec4 b = texture2D(uPrevTex, advUv - vec2(0.0, uTexel.y));

          vec4 avg = (l + r + t + b) * 0.25;

          // Curl-like organic swirl so the liquid tail parts into silky ribbons like Lusion!
          vec2 gradMass = vec2(r.z - l.z, t.z - b.z);
          vec2 swirl = vec2(-gradMass.y, gradMass.x) * 0.045;

          vec2 vel = mix(c.xy, avg.xy, 0.32) * 0.945 + swirl * c.z;
          float mass = mix(c.z, avg.z, 0.22) * 0.962;

          if (uStrength > 0.0005) {
            vec2 seg = distSegment(vUv, uPrevMouse, uMouse);
            float d = seg.x;
            float radius = 0.048 + clamp(uStrength * 0.45, 0.0, 0.032);
            float brush = exp(-(d * d) / (radius * radius));

            // Dual ribbon wake lobes flanking the cursor trajectory
            vel += uVelocity * brush * 0.88;
            mass = clamp(mass + brush * clamp(uStrength * 9.5, 0.0, 0.85), 0.0, 1.35);
          }

          gl_FragColor = vec4(vel, mass, 1.0);
        }
      `,
    });

    const simScene = new THREE.Scene();
    simScene.add(new THREE.Mesh(quadGeo, simMat));

    const compUniforms = {
      uSceneTex: { value: sceneRT.texture },
      uFluidTex: { value: simRTA.texture },
      uTexel: { value: new THREE.Vector2(1 / simRTA.width, 1 / simRTA.height) },
      uScreenTexel: { value: new THREE.Vector2(1 / Math.round(initW * dpr), 1 / Math.round(initH * dpr)) },
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
          float hL = texture2D(uFluidTex, vUv - vec2(uTexel.x * 1.6, 0.0)).z;
          float hR = texture2D(uFluidTex, vUv + vec2(uTexel.x * 1.6, 0.0)).z;
          float hB = texture2D(uFluidTex, vUv - vec2(0.0, uTexel.y * 1.6)).z;
          float hT = texture2D(uFluidTex, vUv + vec2(0.0, uTexel.y * 1.6)).z;

          // Surface normal of the transparent liquid wake
          vec2 grad = vec2((hR - hL) / max(uAspect, 0.5), hT - hB) * 2.8;
          vec2 flowOffset = fluid.xy * 0.022 + grad * 0.018;
          float intensity = clamp(length(grad) * 1.35 + fluid.z * 0.35, 0.0, 1.0);

          // Chromatic dispersion (RGB prismatic separation along the liquid normal)
          float ca = 0.0055 * intensity + 0.0025 * fluid.z;
          vec2 dir = normalize(flowOffset + vec2(0.0001));
          vec2 sampleUv = clamp(vUv - flowOffset, 0.001, 0.999);

          float rCh = texture2D(uSceneTex, clamp(sampleUv + dir * ca, 0.001, 0.999)).r;
          float gCh = texture2D(uSceneTex, sampleUv).g;
          float bCh = texture2D(uSceneTex, clamp(sampleUv - dir * ca, 0.001, 0.999)).b;
          vec3 col = vec3(rCh, gCh, bCh);

          // 4K Cinema Unsharp-Mask Micro-Contrast Clarifier (crisps mountain ridges, fissures & edges!)
          vec3 nL = texture2D(uSceneTex, clamp(sampleUv - vec2(uScreenTexel.x, 0.0), 0.001, 0.999)).rgb;
          vec3 nR = texture2D(uSceneTex, clamp(sampleUv + vec2(uScreenTexel.x, 0.0), 0.001, 0.999)).rgb;
          vec3 nU = texture2D(uSceneTex, clamp(sampleUv + vec2(0.0, uScreenTexel.y), 0.001, 0.999)).rgb;
          vec3 nD = texture2D(uSceneTex, clamp(sampleUv - vec2(0.0, uScreenTexel.y), 0.001, 0.999)).rgb;
          vec3 blurCross = (nL + nR + nU + nD) * 0.25;
          col += (col - blurCross) * 0.62;

          // Restrained highlight bloom (no milky white haze!)
          vec2 bloomStep = uScreenTexel * 5.0;
          vec3 b1 = texture2D(uSceneTex, clamp(sampleUv + vec2(bloomStep.x, bloomStep.y), 0.001, 0.999)).rgb;
          vec3 b2 = texture2D(uSceneTex, clamp(sampleUv + vec2(-bloomStep.x, bloomStep.y), 0.001, 0.999)).rgb;
          vec3 b3 = texture2D(uSceneTex, clamp(sampleUv + vec2(bloomStep.x, -bloomStep.y), 0.001, 0.999)).rgb;
          vec3 b4 = texture2D(uSceneTex, clamp(sampleUv + vec2(-bloomStep.x, -bloomStep.y), 0.001, 0.999)).rgb;
          vec3 brightAvg = max(vec3(0.0), (b1 + b2 + b3 + b4) * 0.25 - vec3(0.92));
          col += brightAvg * 0.10;

          // Silky pearlescent / iridescent specular sheen on the liquid ridges
          vec3 norm = normalize(vec3(-grad.x * 2.6, -grad.y * 2.6, 1.0));
          vec3 lightDir1 = normalize(vec3(-0.45, 0.65, 0.62));
          vec3 lightDir2 = normalize(vec3(0.55, -0.35, 0.75));
          float spec1 = pow(max(dot(norm, lightDir1), 0.0), 22.0);
          float spec2 = pow(max(dot(norm, lightDir2), 0.0), 16.0);
          float fresnel = pow(1.0 - max(norm.z, 0.0), 2.2);

          vec3 iridA = vec3(0.98, 0.86, 0.94);
          vec3 iridB = vec3(0.80, 0.88, 1.00);
          vec3 sheen = iridA * spec1 * 0.42 + iridB * spec2 * 0.34 + mix(iridB, vec3(1.0), 0.5) * fresnel * 0.28;

          col += sheen * smoothstep(0.02, 0.45, fluid.z + length(grad));

          float trough = clamp(-dot(grad, vec2(0.5, -0.5)), 0.0, 1.0);
          col *= 1.0 - trough * 0.055 * clamp(fluid.z, 0.0, 1.0);

          // Subtle 35mm IMAX anamorphic lens vignette + ultra-fine cinema sensor grain (eliminates 8-bit sky banding!)
          vec2 vigUv = vUv - 0.5;
          float vig = 1.0 - dot(vigUv * vec2(0.95, 1.15), vigUv * vec2(0.95, 1.15)) * 0.34;
          col *= clamp(vig, 0.72, 1.0);

          float filmGrain = fract(sin(dot(vUv + fract(uTime * 0.37), vec2(12.9898, 78.233))) * 43758.5453) - 0.5;
          col += filmGrain * 0.0075;

          gl_FragColor = vec4(max(col, vec3(0.0)), 1.0);
          #include <tonemapping_fragment>
          #include <colorspace_fragment>
        }
      `,
    });
    compMat.toneMapped = true;

    // Convert linear sceneRT output cleanly through Three.js color space if needed
    const compScene = new THREE.Scene();
    compScene.add(new THREE.Mesh(quadGeo, compMat));

    const currUV = new THREE.Vector2(0.5, 0.5);
    const prevUV = new THREE.Vector2(0.5, 0.5);
    let hasReceivedPointer = false;

    const raycaster = new THREE.Raycaster();
    const pointerVec = new THREE.Vector2();
    const interactionPlane = new THREE.Plane(new THREE.Vector3(0, 0, 1), -0.25);
    const hitPoint = new THREE.Vector3();

    const hitProxyMesh = new THREE.Mesh(
      new THREE.BoxGeometry(5.6, 2.2, 1.0),
      new THREE.MeshBasicMaterial({ visible: false })
    );
    hitProxyMesh.position.set(0, -0.14, 0);
    sculptureGroup.add(hitProxyMesh);

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
      simRTA.setSize(sw, sh);
      simRTB.setSize(sw, sh);
      simUniforms.uTexel.value.set(1 / sw, 1 / sh);
      simUniforms.uAspect.value = w / h;
      compUniforms.uTexel.value.set(1 / sw, 1 / sh);
      compUniforms.uScreenTexel.value.set(1 / rtW, 1 / rtH);
      compUniforms.uAspect.value = w / h;
    };
    window.addEventListener("resize", handleResize);

    // --- 8. Master Render Loop (Deep Smooth Camera Flight + Live Theme Transitions) ---
    let animationId: number;
    const clock = new THREE.Clock();
    let currentScroll = scrollRef.current;
    let currentMorph = computeContinuousMorph(scrollRef.current);
    let appliedThemeId: SpatialThemeId = themeRef.current;
    let frameCounter = 0;
    let hudTriggered = false;
    let hasCompletedGenesis = false;

    const targetSkyCol = new THREE.Color(initialTheme.skyHex);
    const targetSunCol = new THREE.Color(initialTheme.sunColor);
    const targetRimCol = new THREE.Color(initialTheme.rimColor);
    const targetAmbCol = new THREE.Color(initialTheme.ambientColor);

    const accentPalette = [
      new THREE.Color(0x1e293b), // 0: Dark Basalt Hero
      new THREE.Color(0x7dd3fc), // 1: Digital Core
      new THREE.Color(0x6ee7b7), // 2: DeepShield
      new THREE.Color(0xc4b5fd), // 3: KeyHub
    ];

    const animate = () => {
      animationId = requestAnimationFrame(animate);
      frameCounter++;
      const elapsed = clock.getElapsedTime();

      // Live Atmosphere Theme Switching (Ready for Admin/Backend customization!)
      if (themeRef.current !== appliedThemeId) {
        appliedThemeId = themeRef.current;
        applyTerrainThemeColors(appliedThemeId);
      }
      const activeTh = SPATIAL_THEMES[appliedThemeId] || SPATIAL_THEMES[DEFAULT_SPATIAL_THEME];
      targetSkyCol.setHex(activeTh.skyHex);
      targetSunCol.setHex(activeTh.sunColor);
      targetRimCol.setHex(activeTh.rimColor);
      targetAmbCol.setHex(activeTh.ambientColor);

      liveSkyColor.lerp(targetSkyCol, 0.06);
      fogExp.color.copy(liveSkyColor);
      fogExp.density += (activeTh.fogDensity - fogExp.density) * 0.06;
      skyDomeUniforms.uHorizonColor.value.copy(liveSkyColor);
      skyDomeUniforms.uZenithColor.value.copy(liveSkyColor).multiplyScalar(0.56);
      terrainScanUniforms.uAerialMistColor.value.copy(liveSkyColor).lerp(new THREE.Color(0xf8fafc), 0.38);
      renderer.toneMappingExposure += (activeTh.exposure - renderer.toneMappingExposure) * 0.06;
      sunLight.color.lerp(targetSunCol, 0.06);
      sunLight.intensity += (activeTh.sunIntensity - sunLight.intensity) * 0.06;
      coolBackLight.color.lerp(targetRimCol, 0.06);
      coolBackLight.intensity += (activeTh.rimIntensity - coolBackLight.intensity) * 0.06;
      ambientFill.color.lerp(targetAmbCol, 0.06);
      ambientFill.intensity += (activeTh.ambientIntensity - ambientFill.intensity) * 0.06;

      if (bootStartRef.current < 0) {
        bootStartRef.current = elapsed;
        hudTriggered = false;
        hasCompletedGenesis = false;
      }

      const bootAge = elapsed - bootStartRef.current;
      const rawBoot = Math.min(1.0, Math.max(0.0, bootAge / BOOT_DURATION));
      if (rawBoot >= 0.999 || scrollRef.current > 0.04) {
        hasCompletedGenesis = true;
      }
      const bootT = hasCompletedGenesis ? 1.0 : rawBoot;

      uniforms.uTime.value = elapsed;
      skyDomeUniforms.uTime.value = elapsed;
      terrainScanUniforms.uTime.value = elapsed;
      godRayUniforms.uTime.value = elapsed;
      compUniforms.uTime.value = elapsed;

      //Genesis Wireframe & Terrain Radial Shockwave
      const wireFadeIn = THREE.MathUtils.smoothstep(bootT, 0.02, 0.18);
      const wireFadeOut = 1.0 - THREE.MathUtils.smoothstep(bootT, 0.58, 0.92);
      const wireAlpha = wireFadeIn * wireFadeOut;

      genesisGridGroup.visible = wireAlpha > 0.01;
      genesisWireMat.opacity = wireAlpha * 0.52;
      genesisNodesMat.opacity = wireAlpha * 0.85;

      if (bootT < 0.999) {
        const waveEase = Math.pow(THREE.MathUtils.smoothstep(bootT, 0.34, 0.96), 1.35);
        terrainScanUniforms.uRevealRadius.value = waveEase * 125.0;
      } else {
        terrainScanUniforms.uRevealRadius.value = 165.0;
      }

      if (bootCbRef.current && (frameCounter % 3 === 0 || bootT >= 0.78)) {
        const shouldShowHud = bootT >= 0.78;
        if (shouldShowHud && !hudTriggered) {
          hudTriggered = true;
          bootCbRef.current(bootT, true);
        } else if (!hudTriggered) {
          bootCbRef.current(bootT, false);
        }
      }

      const craneRaw = THREE.MathUtils.smoothstep(bootT, 0.42, 1.0);
      const craneEase = 1.0 - Math.pow(1.0 - craneRaw, 3.2);

      // Silky Circular Scroll Damping (Lower damping factor = deeper, creamier inertia)
      let deltaScroll = scrollRef.current - currentScroll;
      if (deltaScroll > 0.5) {
        currentScroll += 1.0;
        deltaScroll = scrollRef.current - currentScroll;
      } else if (deltaScroll < -0.5) {
        currentScroll -= 1.0;
        deltaScroll = scrollRef.current - currentScroll;
      }
      currentScroll = ((currentScroll + deltaScroll * 0.042) % 1.0 + 1.0) % 1.0;

      // Continuous Scroll-Driven Morph (No abrupt integer jumps!)
      const continuousTargetMorph = computeContinuousMorph(currentScroll);
      currentMorph += (continuousTargetMorph - currentMorph) * 0.075;
      uniforms.uMorph.value = currentMorph;

      // Calculate transition intensity (how much we are currently between two pillars: 0 = resting on a pillar, 1 = mid-flight)
      const morphFract = currentMorph - Math.floor(currentMorph);
      const flightArc = Math.sin(morphFract * Math.PI); // Smooth 0 -> 1 -> 0 arc between every pillar!

      const enterServices = THREE.MathUtils.smoothstep(currentScroll, 0.06, 0.24);
      const exitBackToHero = 1.0 - THREE.MathUtils.smoothstep(currentScroll, 0.86, 0.99);
      const transitionT = enterServices * exitBackToHero;

      // Smooth Click-to-Dissolve Interpolation
      const dissolveTarget = dissolvedRef.current && chapterRef.current >= 1 ? 1.0 : 0.0;
      uniforms.uDissolve.value += (dissolveTarget - uniforms.uDissolve.value) * 0.065;

      const darkTarget = Math.max(0, 1.0 - currentMorph * 1.35);
      uniforms.uDarkHeroMix.value += (darkTarget - uniforms.uDarkHeroMix.value) * 0.08;

      smoothMouseX += (mouseNDCX - smoothMouseX) * 0.045;
      smoothMouseY += (mouseNDCY - smoothMouseY) * 0.045;

      // Deep 3D Camera Parallax & Mountain Valley Swoop across the 4 stages!
      const scrollAngle = currentScroll * Math.PI * 2.0;
      const valleyPanX = Math.sin(scrollAngle) * 1.35 * transitionT;
      const valleyDepthZ =
        THREE.MathUtils.lerp(10.2, 8.5, transitionT) +
        flightArc * 1.85 - // Pulls back & swoops through depth during every transition!
        Math.cos(scrollAngle * 2.0) * 0.35 * transitionT;
      const valleyHeightY =
        THREE.MathUtils.lerp(0.66, 0.88, transitionT) + flightArc * 0.42;

      // Subtle terrain Z-glide to heighten sensation of flying forward over the mountain ridge
      terrainMesh.position.z = Math.sin(scrollAngle) * 2.4;
      terrainMesh.position.x = -valleyPanX * 0.45;

      camera.position.set(
        THREE.MathUtils.lerp(4.6, valleyPanX, craneEase) + smoothMouseX * 0.52 * craneEase,
        THREE.MathUtils.lerp(8.4, valleyHeightY, craneEase) + smoothMouseY * 0.22 * craneEase,
        THREE.MathUtils.lerp(8.8, valleyDepthZ, craneEase)
      );
      camera.lookAt(
        valleyPanX * 0.28 * craneEase,
        THREE.MathUtils.lerp(0.1, THREE.MathUtils.lerp(0.52, 0.78, transitionT), craneEase),
        0
      );

      pointerVec.set(mouseNDCX, mouseNDCY);
      raycaster.setFromCamera(pointerVec, camera);
      let hasHit = false;
      if (raycaster.ray.intersectPlane(interactionPlane, hitPoint)) {
        hasHit = true;
      }

      const canHoverPillar =
        bootT >= 0.85 && chapterRef.current >= 1 && !dissolvedRef.current && flightArc < 0.35;
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
      uniforms.uHoverPulse.value += (hoverTarget - uniforms.uHoverPulse.value) * 0.12;

      const pOpacity = THREE.MathUtils.smoothstep(bootT, 0.22, 0.72);
      uniforms.uOpacity.value = pOpacity;
      uniforms.uDisperseIn.value = 1.0 - pOpacity;
      sculptureGroup.visible = pOpacity > 0.01;

      if (sculptureGroup.visible) {
        sculptureGroup.position.x = valleyPanX * 0.28;
        sculptureGroup.position.z = 0.28 - flightArc * 0.95;

        const rotAmp = THREE.MathUtils.lerp(0.025, 0.09, transitionT);
        sculptureGroup.rotation.y =
          Math.sin(elapsed * 0.28) * rotAmp +
          smoothMouseX * THREE.MathUtils.lerp(0.08, 0.16, transitionT) +
          flightArc * 0.22;
        sculptureGroup.rotation.x = smoothMouseY * -0.045;

        let pointerOnPlane = 0.0;
        if (hasHit && hasReceivedPointer && !dissolvedRef.current) {
          const localP = hitPoint.clone();
          sculptureGroup.worldToLocal(localP);
          uniforms.uPointer3D.value.copy(localP);

          const uHit = localP.x / PLANE_W + 0.5;
          const vHit = localP.y / PLANE_H + 0.5;
          if (uHit >= -0.08 && uHit <= 1.08 && vHit >= -0.12 && vHit <= 1.12) {
            pointerOnPlane = 1.0;
            currHitUV.set(uHit, vHit);
            if (!hasSmokeHit) {
              hasSmokeHit = true;
              prevHitUV.set(uHit, vHit);
            }
          } else {
            hasSmokeHit = false;
          }
        } else {
          hasSmokeHit = false;
        }

        // Update Persistent Interactive Black Smoke FBO on the Typography Plane!
        smokeSimUniforms.uPrevSmoke.value = smokeRTA.texture;
        smokeSimUniforms.uPointerUV.value.copy(currHitUV);
        smokeSimUniforms.uPrevPointerUV.value.copy(prevHitUV);
        smokeSimUniforms.uPointerVel.value.set(
          (currHitUV.x - prevHitUV.x) * 8.0,
          (currHitUV.y - prevHitUV.y) * 8.0
        );
        smokeSimUniforms.uPointerActive.value = pointerOnPlane;
        smokeSimUniforms.uTime.value = elapsed;

        const prevTM = renderer.toneMapping;
        renderer.toneMapping = THREE.NoToneMapping;
        renderer.setRenderTarget(smokeRTB);
        renderer.render(smokeSimScene, orthoCam);
        renderer.toneMapping = prevTM;

        const tmpSmk = smokeRTA;
        smokeRTA = smokeRTB;
        smokeRTB = tmpSmk;
        prevHitUV.lerp(currHitUV, 0.72);
        uniforms.uSmokeTex.value = smokeRTA.texture;

        const idxRound = Math.max(0, Math.min(3, Math.round(currentMorph)));
        uniforms.uAccentColor.value.lerp(accentPalette[idxRound], 0.06);
      }

      snowPoints.rotation.y = elapsed * 0.012;
      snowPoints.position.x = Math.sin(elapsed * 0.2) * 0.3;

      // Drift volumetric alpine valley clouds gently across the mountain pass
      for (let cIdx = 0; cIdx < cloudBanks.length; cIdx++) {
        const cb = cloudBanks[cIdx];
        const drift = ((elapsed * cb.speed + cb.phase * 4.0) % 52.0) - 26.0;
        cb.mesh.position.x = cb.baseX * 0.55 + drift;
        cb.mesh.position.y = cb.baseY + Math.sin(elapsed * 0.35 + cb.phase) * 0.22;
        cb.mesh.lookAt(camera.position.x, cb.mesh.position.y + 1.2, camera.position.z);
      }

      // --- Step A: Update Ping-Pong Fluid Cursor Wake ---
      const velX = currUV.x - prevUV.x;
      const velY = currUV.y - prevUV.y;
      const speed = Math.sqrt(velX * velX + velY * velY);

      simUniforms.uPrevTex.value = simRTA.texture;
      simUniforms.uMouse.value.copy(currUV);
      simUniforms.uPrevMouse.value.copy(prevUV);
      simUniforms.uVelocity.value.set(velX * 14.0, velY * 14.0);
      simUniforms.uStrength.value = Math.min(speed, 0.14);
      simUniforms.uTime.value = elapsed;

      const prevToneMapping = renderer.toneMapping;
      renderer.toneMapping = THREE.NoToneMapping;
      renderer.setRenderTarget(simRTB);
      renderer.render(simScene, orthoCam);

      // Swap ping-pong buffers
      const tmpRT = simRTA;
      simRTA = simRTB;
      simRTB = tmpRT;
      prevUV.lerp(currUV, 0.65);

      // --- Step B: Render 3D Mountain + 72k Particle World into Linear HDR Target ---
      renderer.setRenderTarget(sceneRT);
      renderer.render(scene, camera);

      // --- Step C: Composite Liquid Prismatic Refraction + Tone Mapping to Screen ---
      renderer.toneMapping = prevToneMapping;
      compUniforms.uSceneTex.value = sceneRT.texture;
      compUniforms.uFluidTex.value = simRTA.texture;
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
      hitProxyMesh.geometry.dispose();
      (hitProxyMesh.material as THREE.Material).dispose();
      quadGeo.dispose();
      simMat.dispose();
      compMat.dispose();
      sceneRT.dispose();
      simRTA.dispose();
      simRTB.dispose();
      terrainGeo.dispose();
      skyDomeGeo.dispose();
      skyDomeMat.dispose();
      cloudPlaneGeo.dispose();
      cloudPuffTex.dispose();
      cloudBanks.forEach((cb) => (cb.mesh.material as THREE.Material).dispose());
      godRayGeo.dispose();
      godRayMat.dispose();
      genesisNodesGeo.dispose();
      solidTextGeo.dispose();
      smokeQuadGeo.dispose();
      smokeSimMat.dispose();
      smokeRTA.dispose();
      smokeRTB.dispose();
      stageTextures.forEach((tx) => tx.dispose());
      particleMat.dispose();
      rockAlbedo.dispose();
      rockNormal.dispose();
      rockRough.dispose();
      envTex.dispose();
      pmremGenerator.dispose();
      scene.clear();
      renderer.dispose();
    };
  }, []);

  return <div ref={mountRef} className="fixed inset-0 w-full h-full z-0" />;
}
