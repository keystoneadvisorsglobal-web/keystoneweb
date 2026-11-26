import type { Config } from "tailwindcss";

const config: Config = {
  content: [
    "./src/pages/**/*.{js,ts,jsx,tsx,mdx}",
    "./src/components/**/*.{js,ts,jsx,tsx,mdx}",
    "./src/app/**/*.{js,ts,jsx,tsx,mdx}",
  ],
  theme: {
    extend: {
      colors: {
        background: "#FAFAFC",
        foreground: "#1E2530",
        keystone: {
          dark: "#0F172A",
          navy: "#003366",
          blue: "#0284C7",
          gold: "#D4AF37",
          goldLight: "#F5E7B2",
          titanium: "#1E2530",
          muted: "#4A5568",
          glass: "rgba(255, 255, 255, 0.75)",
        },
      },
      fontFamily: {
        sans: ["Arial", "system-ui", "-apple-system", "sans-serif"],
      },
      boxShadow: {
        "luxury": "0 20px 40px -15px rgba(0, 0, 0, 0.05), 0 0 1px 1px rgba(0, 0, 0, 0.03)",
        "glass": "0 8px 32px 0 rgba(31, 38, 135, 0.07)",
        "gold-glow": "0 0 25px rgba(212, 175, 55, 0.25)",
        "azure-glow": "0 0 25px rgba(2, 132, 199, 0.25)",
      },
      keyframes: {
        float: {
          "0%, 100%": { transform: "translateY(0px)" },
          "50%": { transform: "translateY(-10px)" },
        },
        pulseGlow: {
          "0%, 100%": { opacity: "0.4" },
          "50%": { opacity: "0.8" },
        },
        shimmer: {
          "100%": { transform: "translateX(100%)" },
        }
      },
      animation: {
        float: "float 6s ease-in-out infinite",
        "pulse-glow": "pulseGlow 4s ease-in-out infinite",
      },
    },
  },
  plugins: [],
};
export default config;
