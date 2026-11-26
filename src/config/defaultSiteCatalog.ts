import type { SpatialThemeId } from "./spatialThemes";
import { DEFAULT_SPATIAL_THEME } from "./spatialThemes";

export interface ServiceCardOffer {
  id: string;
  tag: string;
  title: string;
  description: string;
  priceHighlight: string;
  priceNote?: string;
  ctaLabel: string;
  opensBecScanner?: boolean;
  isFeatured?: boolean;
  featuredBadge?: string;
}

export interface PillarCatalog {
  chapterIndex: number;
  label: string;
  hookConcept?: string;
  headline?: string;
  clickHint?: string;
  accentTextClass?: string;
  accentBorderClass?: string;
  accentBtnHoverClass?: string;
  cards?: ServiceCardOffer[];
}

export interface PartnerMarqueeItem {
  name: string;
  role: string;
  icon: "cloud" | "shield" | "award" | "layers" | "cpu" | "globe" | "lock";
  href?: string;
}

export interface PublicSiteConfig {
  defaultTheme: SpatialThemeId;
  allowPublicThemeSwitcher: boolean;
  contactEmail: string;
  whatsappNumber: string;
  whatsappDisplay: string;
  chapters: PillarCatalog[];
  partnersMarquee?: PartnerMarqueeItem[];
  updatedAt?: string;
}

export const DEFAULT_CHAPTERS: PillarCatalog[] = [
  {
    chapterIndex: 0,
    label: "Inicio",
  },
  {
    chapterIndex: 1,
    label: "Digital Core",
    headline: "Arquitectura Salesforce, Core Financiero e IA en semanas",
    clickHint: "Haz clic sobre CRM para descubrir soluciones",
    accentTextClass: "text-sky-300",
    accentBorderClass: "border-sky-400/35",
    accentBtnHoverClass:
      "hover:bg-sky-300 hover:text-[#0A0E15] hover:border-sky-300",
    cards: [
      {
        id: "dc-starter",
        tag: "Optimiza tu CRM",
        title: "Salesforce Starter Suite",
        description:
          "Activa tu motor comercial de prospectos y ventas certificado en solo 2 semanas.",
        priceHighlight: "Desde USD $2.490",
        priceNote: "Pago único",
        ctaLabel: "Cotiza tu implementación",
      },
      {
        id: "dc-quick",
        tag: "Impulsa tus ventas",
        title: "Salesforce Quick Starter",
        description:
          "Sales o Service Cloud automatizado con KPIs ejecutivos en vivo listo en 4 semanas.",
        priceHighlight: "Desde USD $8.000",
        priceNote: "Pago único",
        ctaLabel: "Hablemos de tu proyecto",
      },
      {
        id: "dc-squads",
        tag: "Velocidad IA-Gobernada",
        title: "Squads Híbridos Senior + IA",
        description:
          "Arquitectos senior y escuadrones de agentes propios operando con precisión sobre Salesforce, SAP y APIs.",
        priceHighlight: "Desde USD $2.750 / mes",
        ctaLabel: "Reserva una asesoría",
      },
      {
        id: "dc-fsc",
        tag: "Ecosistemas Regulados",
        title: "Banca FSC & Agentforce",
        description:
          "Integración de Financial Services Cloud y arquitecturas de IA empresarial sin exposición de datos.",
        priceHighlight: "Arquitectura a Medida",
        ctaLabel: "Agenda tu diagnóstico",
      },
    ],
  },
  {
    chapterIndex: 2,
    label: "DeepShield",
    headline: "Protege tu operación frente al fraude con IA",
    clickHint: "Haz clic sobre DSX para descubrir planes de blindaje",
    accentTextClass: "text-emerald-300",
    accentBorderClass: "border-emerald-400/35",
    accentBtnHoverClass:
      "hover:bg-emerald-300 hover:text-[#0A0E15] hover:border-emerald-300",
    cards: [
      {
        id: "ds-bec",
        tag: "Descubre tu exposición",
        title: "Diagnóstico Dominio BEC",
        description:
          "Verifica en 30 segundos si terceros pueden suplantar el correo de tu empresa.",
        priceHighlight: "Gratuito en línea",
        priceNote: "100% pasivo",
        ctaLabel: "Escanear mi dominio ahora",
        opensBecScanner: true,
      },
      {
        id: "ds-assessment",
        tag: "Anticípate al riesgo",
        title: "Cyber Risk Assessment 72h",
        description:
          "Radiografía ejecutiva de vulnerabilidades y cumplimiento Ley N° 21.459.",
        priceHighlight: "Desde USD $950",
        priceNote: "Entrega en 72h",
        ctaLabel: "Agenda tu diagnóstico",
      },
      {
        id: "ds-redteam",
        tag: "Potencia tu defensa",
        title: "Red Teaming con IA",
        description:
          "Entrena a tu equipo ante clonación de voz (Vishing) y señuelos avanzados.",
        priceHighlight: "Desde USD $650 / mes",
        ctaLabel: "Solicita una auditoría",
      },
      {
        id: "ds-payments",
        tag: "Blinda tu tesorería",
        title: "DeepShield Pagos & Enterprise",
        description:
          "Validación biométrica contra desvío de transferencias y custodia Zero Trust.",
        priceHighlight: "Desde USD $490 / mes",
        ctaLabel: "Cotiza tu implementación",
      },
    ],
  },
  {
    chapterIndex: 3,
    label: "KeyHub",
    headline: "Gobierno de ingeniería, trazabilidad forense y agentes IA",
    clickHint: "Haz clic sobre KHS para descubrir licencias KeyHub",
    accentTextClass: "text-violet-300",
    accentBorderClass: "border-violet-400/35",
    accentBtnHoverClass:
      "hover:bg-violet-300 hover:text-[#0A0E15] hover:border-violet-300",
    cards: [
      {
        id: "kh-pro",
        tag: "Potencia tu día a día",
        title: "KeyHub Professional",
        description:
          "Proyectos Kanban, control de horas, contratos digitales y nómina USD/CLP.",
        priceHighlight: "USD $8 / usuario / mes",
        priceNote: "Mín. 20 usuarios",
        ctaLabel: "Reserva una asesoría",
      },
      {
        id: "kh-ent",
        tag: "Escala tu empresa",
        title: "KeyHub Enterprise",
        description:
          "Cloud privada, conector bidireccional Salesforce y cadena de custodia GitFlow.",
        priceHighlight: "USD $14 / usuario / mes",
        priceNote: "SLA 99.9%",
        ctaLabel: "Hablemos de tu proyecto",
      },
      {
        id: "kh-forensic",
        tag: "Lleva tu control al siguiente nivel",
        title: "Auditoría Forense de Horas",
        description:
          "Cuadratura matemática anti-fraude de tiempos para auditorías bancarias.",
        priceHighlight: "+ USD $4 / usuario / mes",
        priceNote: "Add-on",
        ctaLabel: "Agenda tu diagnóstico",
      },
      {
        id: "kh-agents",
        tag: "Automatiza tu desarrollo",
        title: "Plataforma Agentes IA & Code Graph",
        description:
          "14 agentes especializados y grafo AST que ahorra hasta 97% en consumo de tokens.",
        priceHighlight: "+ USD $2 / usuario / mes",
        priceNote: "Add-on",
        ctaLabel: "Activa tus agentes",
        isFeatured: true,
        featuredBadge: "Destacado · Ahorra 97% Tokens",
      },
    ],
  },
];

export const DEFAULT_PARTNERS_MARQUEE: PartnerMarqueeItem[] = [
  {
    name: "SALESFORCE PARTNER",
    role: "Socio Certificado Oficial",
    icon: "cloud",
  },
  {
    name: "AGENTFORCE & FSC",
    role: "Financial Services Cloud",
    icon: "cpu",
  },
  {
    name: "ISO/IEC 27001",
    role: "Seguridad de la Información",
    icon: "shield",
  },
  {
    name: "AWS · AZURE · GOOGLE CLOUD",
    role: "Arquitectura MultiCloud",
    icon: "layers",
  },
  {
    name: "TRUSTZERO VERIFIED",
    role: "DMARC & Anti-Fraude BEC",
    icon: "lock",
    href: "/trust/",
  },
  {
    name: "INMOTION",
    role: "Alianza Caribe & Rep. Dominicana",
    icon: "globe",
    href: "https://inmotion.com.do/",
  },
  {
    name: "SURGES ACADEMY",
    role: "Alianza Formación & SENCE",
    icon: "award",
    href: "https://surgescapacitaciones.cl/",
  },
  {
    name: "SAP INTEGRATION",
    role: "Modernización Core ERP",
    icon: "layers",
  },
];

export const DEFAULT_SITE_CONFIG: PublicSiteConfig = {
  defaultTheme: DEFAULT_SPATIAL_THEME,
  allowPublicThemeSwitcher: true,
  contactEmail: "contacto@keystoneadvisors.cl",
  whatsappNumber: "56930640431",
  whatsappDisplay: "+56 9 3064 0431",
  chapters: DEFAULT_CHAPTERS,
  partnersMarquee: DEFAULT_PARTNERS_MARQUEE,
};
