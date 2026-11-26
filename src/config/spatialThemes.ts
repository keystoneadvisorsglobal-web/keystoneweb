export type SpatialThemeId = "glaciar_prev" | "amethyst_slate" | "graphite_mist";

export interface SpatialAtmosphereTheme {
  id: SpatialThemeId;
  label: string;
  description: string;
  /** Hex color for sky background & CSS loading backdrop */
  skyHex: number;
  skyCss: string;
  /** Exponential fog density */
  fogDensity: number;
  /** Tone mapping exposure */
  exposure: number;
  /** Directional sunlight color & intensity */
  sunColor: number;
  sunIntensity: number;
  /** Cool/violet rim backlight color & intensity */
  rimColor: number;
  rimIntensity: number;
  /** Ambient fill color & intensity */
  ambientColor: number;
  ambientIntensity: number;
  /** Terrain vertex shading RGB multipliers [r, g, b] for near summit vs far mountain ridges */
  nearTint: [number, number, number];
  farTint: [number, number, number];
  /** Base brightness & frost boost on terrain */
  terrainBaseShade: number;
  terrainFrostBoost: number;
}

/**
 * Presets de atmósfera 3D listos para ser administrados dinámicamente desde Backend/KeyHub Admin.
 * Incluye intacto el preset anterior ("glaciar_prev") según lo solicitado.
 */
export const SPATIAL_THEMES: Record<SpatialThemeId, SpatialAtmosphereTheme> = {
  glaciar_prev: {
    id: "glaciar_prev",
    label: "Glaciar Claro (Anterior)",
    description: "Preset luminoso nórdico guardado como respaldo.",
    skyHex: 0xb5bec9,
    skyCss: "#b5bec9",
    fogDensity: 0.018,
    exposure: 1.24,
    sunColor: 0xffffff,
    sunIntensity: 3.8,
    rimColor: 0xe2e8f0,
    rimIntensity: 2.0,
    ambientColor: 0xf1f5f9,
    ambientIntensity: 1.45,
    nearTint: [0.98, 1.0, 1.04],
    farTint: [0.88, 0.91, 0.96],
    terrainBaseShade: 0.84,
    terrainFrostBoost: 0.34,
  },
  amethyst_slate: {
    id: "amethyst_slate",
    label: "Amethyst Slate",
    description:
      "Cordillera de roca pizarra y basalto de alta definición con vetas minerales nítidas y escarcha sutil solo en las aristas.",
    skyHex: 0x878d9f,
    skyCss: "#878d9f",
    fogDensity: 0.0068,
    exposure: 1.05,
    sunColor: 0xfff6eb,
    sunIntensity: 3.95,
    rimColor: 0xa5b4fc,
    rimIntensity: 2.10,
    ambientColor: 0xc7d2fe,
    ambientIntensity: 0.52,
    nearTint: [0.84, 0.86, 0.94],
    farTint: [0.58, 0.62, 0.76],
    terrainBaseShade: 0.48,
    terrainFrostBoost: 0.22,
  },
  graphite_mist: {
    id: "graphite_mist",
    label: "Graphite Mist",
    description:
      "Cordillera alpina de roca grafito y granito cincelado con alto relieve y mínima nieve.",
    skyHex: 0x828b99,
    skyCss: "#828b99",
    fogDensity: 0.0065,
    exposure: 1.04,
    sunColor: 0xfff8f0,
    sunIntensity: 3.85,
    rimColor: 0x94a3b8,
    rimIntensity: 2.05,
    ambientColor: 0xcbd5e1,
    ambientIntensity: 0.50,
    nearTint: [0.84, 0.87, 0.92],
    farTint: [0.56, 0.62, 0.70],
    terrainBaseShade: 0.49,
    terrainFrostBoost: 0.20,
  },
};

export const DEFAULT_SPATIAL_THEME: SpatialThemeId = "amethyst_slate";

