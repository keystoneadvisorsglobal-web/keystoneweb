import type { PillarCatalog, ServiceCardOffer } from "./defaultSiteCatalog";

export interface V2CardColorTheme {
  /** Top luminous neon-jewel accent bar & icon glow */
  topBarGradient: string;
  /** Subtle radial ambient glow inside the top of the card */
  headerAura: string;
  /** Border color at rest and on hover */
  borderColor: string;
  hoverBorderColor: string;
  cardShadow: string;
  /** Tag / Eyebrow styling */
  tagBg: string;
  tagBorder: string;
  tagText: string;
  /** Value / Price showcase gradient & box accent */
  priceGradient: string;
  priceBoxBg: string;
  priceBoxBorder: string;
  /** ROI / Benefit pill colors */
  roiDotColor: string;
  roiTextColor: string;
  /** CTA Button gradient & glow */
  ctaGradient: string;
  ctaShadow: string;
  /** Optional VIP Featured Ribbon */
  ribbonGradient?: string;
}

export interface V2CardCommercialSpec {
  tierNumber: string;
  tag: string;
  title: string;
  hookHeadline: string;
  description: string;
  roiBullet: string;
  pricePrefix?: string;
  priceMain: string;
  priceSuffix?: string;
  priceSubnote: string;
  ctaLabel: string;
  isVipFeatured?: boolean;
  vipBadge?: string;
  colors: V2CardColorTheme;
}

/**
 * 4 Bespoke Executive Jewel Palettes so the 4 cards across the grid play with harmonious,
 * vanguard luxury colors (Electric Cyan · Royal Sapphire-Gold · Neon Violet · Champagne Platinum)
 * instead of 4 monotonous dark gray boxes!
 */
const PALETTE_CYAN_VELOCITY: V2CardColorTheme = {
  topBarGradient: "linear-gradient(90deg, #06b6d4 0%, #38bdf8 50%, #bae6fd 100%)",
  headerAura:
    "radial-gradient(ellipse at 50% 0%, rgba(56, 189, 248, 0.22) 0%, rgba(14, 165, 233, 0.04) 65%, transparent 100%)",
  borderColor: "rgba(56, 189, 248, 0.28)",
  hoverBorderColor: "rgba(125, 211, 252, 0.75)",
  cardShadow: "0 20px 50px rgba(2, 8, 24, 0.82)",
  tagBg: "rgba(14, 165, 233, 0.16)",
  tagBorder: "rgba(56, 189, 248, 0.42)",
  tagText: "#7dd3fc",
  priceGradient: "linear-gradient(95deg, #FFFFFF 0%, #BAE6FD 52%, #38BDF8 100%)",
  priceBoxBg: "linear-gradient(135deg, rgba(14, 165, 233, 0.14) 0%, rgba(2, 8, 23, 0.65) 100%)",
  priceBoxBorder: "rgba(56, 189, 248, 0.32)",
  roiDotColor: "#38bdf8",
  roiTextColor: "#bae6fd",
  ctaGradient: "linear-gradient(135deg, rgba(14, 165, 233, 0.24) 0%, rgba(37, 99, 235, 0.28) 100%)",
  ctaShadow: "0 8px 24px rgba(14, 165, 233, 0.28)",
};

const PALETTE_SAPPHIRE_GOLD_VIP: V2CardColorTheme = {
  topBarGradient: "linear-gradient(90deg, #fbbf24 0%, #fde68a 45%, #38bdf8 100%)",
  headerAura:
    "radial-gradient(ellipse at 50% 0%, rgba(251, 191, 36, 0.20) 0%, rgba(37, 99, 235, 0.14) 55%, transparent 100%)",
  borderColor: "rgba(251, 191, 36, 0.55)",
  hoverBorderColor: "rgba(253, 230, 138, 0.92)",
  cardShadow: "0 24px 60px rgba(245, 158, 11, 0.18), 0 18px 45px rgba(2, 8, 24, 0.9)",
  tagBg: "rgba(245, 158, 11, 0.18)",
  tagBorder: "rgba(251, 191, 36, 0.50)",
  tagText: "#fde68a",
  priceGradient: "linear-gradient(95deg, #FFFFFF 0%, #FEF08A 45%, #F59E0B 100%)",
  priceBoxBg: "linear-gradient(135deg, rgba(245, 158, 11, 0.18) 0%, rgba(15, 23, 42, 0.78) 100%)",
  priceBoxBorder: "rgba(251, 191, 36, 0.45)",
  roiDotColor: "#fbbf24",
  roiTextColor: "#fef08a",
  ctaGradient: "linear-gradient(135deg, #F59E0B 0%, #D97706 45%, #0284C7 100%)",
  ctaShadow: "0 10px 28px rgba(245, 158, 11, 0.38)",
  ribbonGradient: "linear-gradient(90deg, #F59E0B 0%, #FBBF24 50%, #38BDF8 100%)",
};

const PALETTE_VIOLET_QUANTUM: V2CardColorTheme = {
  topBarGradient: "linear-gradient(90deg, #8b5cf6 0%, #c084fc 52%, #e9d5ff 100%)",
  headerAura:
    "radial-gradient(ellipse at 50% 0%, rgba(168, 85, 247, 0.24) 0%, rgba(99, 102, 241, 0.06) 65%, transparent 100%)",
  borderColor: "rgba(167, 139, 250, 0.32)",
  hoverBorderColor: "rgba(216, 180, 254, 0.80)",
  cardShadow: "0 20px 50px rgba(2, 8, 24, 0.82)",
  tagBg: "rgba(139, 92, 246, 0.20)",
  tagBorder: "rgba(167, 139, 250, 0.45)",
  tagText: "#ddd6fe",
  priceGradient: "linear-gradient(95deg, #FFFFFF 0%, #E9D5FF 48%, #A855F7 100%)",
  priceBoxBg: "linear-gradient(135deg, rgba(139, 92, 246, 0.16) 0%, rgba(2, 8, 23, 0.65) 100%)",
  priceBoxBorder: "rgba(167, 139, 250, 0.35)",
  roiDotColor: "#c084fc",
  roiTextColor: "#e9d5ff",
  ctaGradient: "linear-gradient(135deg, rgba(139, 92, 246, 0.30) 0%, rgba(99, 102, 241, 0.32) 100%)",
  ctaShadow: "0 8px 24px rgba(139, 92, 246, 0.30)",
};

const PALETTE_EMERALD_SHIELD: V2CardColorTheme = {
  topBarGradient: "linear-gradient(90deg, #10b981 0%, #34d399 52%, #a7f3d0 100%)",
  headerAura:
    "radial-gradient(ellipse at 50% 0%, rgba(16, 185, 129, 0.24) 0%, rgba(6, 182, 212, 0.06) 65%, transparent 100%)",
  borderColor: "rgba(52, 211, 153, 0.34)",
  hoverBorderColor: "rgba(110, 231, 183, 0.85)",
  cardShadow: "0 20px 50px rgba(2, 8, 24, 0.82)",
  tagBg: "rgba(16, 185, 129, 0.18)",
  tagBorder: "rgba(52, 211, 153, 0.45)",
  tagText: "#a7f3d0",
  priceGradient: "linear-gradient(95deg, #FFFFFF 0%, #A7F3D0 48%, #34D399 100%)",
  priceBoxBg: "linear-gradient(135deg, rgba(16, 185, 129, 0.16) 0%, rgba(2, 8, 23, 0.65) 100%)",
  priceBoxBorder: "rgba(52, 211, 153, 0.36)",
  roiDotColor: "#34d399",
  roiTextColor: "#a7f3d0",
  ctaGradient: "linear-gradient(135deg, #10B981 0%, #059669 55%, #0284C7 100%)",
  ctaShadow: "0 10px 28px rgba(16, 185, 129, 0.36)",
  ribbonGradient: "linear-gradient(90deg, #10B981 0%, #34D399 55%, #38BDF8 100%)",
};

const PALETTE_PLATINUM_EXECUTIVE: V2CardColorTheme = {
  topBarGradient: "linear-gradient(90deg, #94a3b8 0%, #f8fafc 50%, #fcd34d 100%)",
  headerAura:
    "radial-gradient(ellipse at 50% 0%, rgba(226, 232, 240, 0.18) 0%, rgba(251, 191, 36, 0.06) 60%, transparent 100%)",
  borderColor: "rgba(226, 232, 240, 0.28)",
  hoverBorderColor: "rgba(253, 230, 138, 0.72)",
  cardShadow: "0 20px 50px rgba(2, 8, 24, 0.82)",
  tagBg: "rgba(248, 250, 252, 0.10)",
  tagBorder: "rgba(226, 232, 240, 0.35)",
  tagText: "#f1f5f9",
  priceGradient: "linear-gradient(95deg, #FFFFFF 0%, #FDE68A 65%, #F59E0B 100%)",
  priceBoxBg: "linear-gradient(135deg, rgba(255, 255, 255, 0.08) 0%, rgba(2, 8, 23, 0.68) 100%)",
  priceBoxBorder: "rgba(253, 230, 138, 0.28)",
  roiDotColor: "#fcd34d",
  roiTextColor: "#fde68a",
  ctaGradient: "linear-gradient(135deg, rgba(255,255,255,0.16) 0%, rgba(148,163,184,0.22) 100%)",
  ctaShadow: "0 8px 24px rgba(255, 255, 255, 0.14)",
};

export const V2_CARD_SPECS: Record<string, V2CardCommercialSpec> = {
  // =========================================================================
  // PILLAR 1: DIGITAL CORE (4 Harmonious Vanguard Colors across the 4 cards!)
  // =========================================================================
  "dc-starter": {
    tierNumber: "01",
    tag: "DESPEGUE COMERCIAL EN 14 DÍAS",
    title: "Salesforce Starter Suite",
    hookHeadline: "Pasa de planillas dispersas a una máquina de ventas certificada",
    description:
      "Deja de perder oportunidades comerciales: centraliza clientes, cotizaciones y seguimiento automático listo para vender desde la semana 2.",
    roiBullet: "Operativo en 2 semanas · Cero fricción técnica",
    pricePrefix: "Desde",
    priceMain: "USD $2.490",
    priceSubnote: "Inversión única llave en mano · Sin costos ocultos",
    ctaLabel: "Activar mi CRM en 14 días",
    colors: PALETTE_CYAN_VELOCITY,
  },
  "dc-quick": {
    tierNumber: "02",
    tag: "ACELERADOR DE INGRESOS PRO",
    title: "Salesforce Quick Starter",
    hookHeadline: "Multiplica tu tasa de cierre con automatización y KPIs en vivo",
    description:
      "Sales o Service Cloud afinado para directorios exigentes: embudos predictivos, alertas inteligentes y visibilidad total de tu equipo en 4 semanas.",
    roiBullet: "Retorno directo desde el primer trimestre",
    pricePrefix: "Desde",
    priceMain: "USD $8.000",
    priceSubnote: "Pago único cerrado · Llave en mano en 4 semanas",
    ctaLabel: "Multiplicar mis ventas ahora",
    isVipFeatured: true,
    vipBadge: "★ Más Elegido por Empresas",
    colors: PALETTE_SAPPHIRE_GOLD_VIP,
  },
  "dc-squads": {
    tierNumber: "03",
    tag: "POTENCIA HÍBRIDA HUMANA + IA",
    title: "Squads Senior + Agentes IA",
    hookHeadline: "Avanza 3 veces más rápido que una consultora tradicional",
    description:
      "Tu propia célula de arquitectos senior potenciados por agentes autónomos integrando Salesforce, SAP y APIs críticas sin cuellos de botella.",
    roiBullet: "3x velocidad de entrega · Escalabilidad mensual",
    pricePrefix: "Desde",
    priceMain: "USD $2.750",
    priceSuffix: "/ mes",
    priceSubnote: "Suscripción flexible · Talento senior garantizado",
    ctaLabel: "Reservar mi Squad dedicado",
    colors: PALETTE_VIOLET_QUANTUM,
  },
  "dc-fsc": {
    tierNumber: "04",
    tag: "BANCA, SEGUROS & CORPORATIVOS",
    title: "Banca FSC & Agentforce",
    hookHeadline: "Arquitectura de grado bancario e IA sin exponer tus datos",
    description:
      "Transformación mayor sobre Financial Services Cloud y agentes autónomos gobernados bajo estrictos estándares regulatorios e ISO 27001.",
    roiBullet: "Seguridad bancaria · Privacidad Zero-Data-Leak",
    priceMain: "Diseño a Medida",
    priceSubnote: "Evaluación ejecutiva sin costo con Arquitecto Principal",
    ctaLabel: "Agendar sesión estratégica",
    colors: PALETTE_PLATINUM_EXECUTIVE,
  },

  // =========================================================================
  // PILLAR 2: DEEPSHIELD (Ciberdefensa Ejecutiva Anti-Fraude)
  // =========================================================================
  "ds-bec": {
    tierNumber: "01",
    tag: "PRUEBA EN VIVO · 30 SEGUNDOS",
    title: "Escáner Dominio Anti-BEC",
    hookHeadline: "¿Podrían falsificar hoy un correo de tu Gerencia General?",
    description:
      "Compruébalo al instante: auditamos tus registros DMARC, SPF y TLS para revelar si terceros pueden suplantar la identidad de tu empresa.",
    roiBullet: "Diagnóstico inmediato · 100% pasivo y confidencial",
    priceMain: "GRATUITO",
    priceSuffix: "en línea",
    priceSubnote: "Resultado en tiempo real + informe ejecutivo opcional",
    ctaLabel: "Escanear mi dominio gratis",
    isVipFeatured: true,
    vipBadge: "★ Diagnóstico Instantáneo Sin Costo",
    colors: PALETTE_EMERALD_SHIELD,
  },
  "ds-assessment": {
    tierNumber: "02",
    tag: "RADIOGRAFÍA EJECUTIVA EN 72H",
    title: "Cyber Risk Assessment",
    hookHeadline: "Descubre tus brechas críticas antes que los ciberdelincuentes",
    description:
      "Auditoría rápida de exposición externa, credenciales filtradas y mapa de cumplimiento Ley Delitos Informáticos N° 21.459 para Directorio.",
    roiBullet: "Entregable ejecutivo accionable en 72 horas",
    pricePrefix: "Desde",
    priceMain: "USD $950",
    priceSubnote: "Evaluación integral única · Entrega garantizada 72h",
    ctaLabel: "Blindar mi empresa en 72h",
    colors: PALETTE_CYAN_VELOCITY,
  },
  "ds-redteam": {
    tierNumber: "03",
    tag: "ENTRENAMIENTO CONTRA IA",
    title: "Red Teaming & Anti-Vishing",
    hookHeadline: "Inmuniza a tu equipo frente a clonación de voz y engaños IA",
    description:
      "Simulaciones éticas realistas de llamadas con voz clonada (Vishing) y phishing ejecutivo para convertir a tus colaboradores en tu mejor escudo.",
    roiBullet: "Reduce hasta 90% el riesgo de caída por engaño",
    pricePrefix: "Desde",
    priceMain: "USD $650",
    priceSuffix: "/ mes",
    priceSubnote: "Programa continuo de blindaje humano y métrica de riesgo",
    ctaLabel: "Solicitar simulación controlada",
    colors: PALETTE_VIOLET_QUANTUM,
  },
  "ds-payments": {
    tierNumber: "04",
    tag: "BLINDAJE DE TESORERÍA Y PAGOS",
    title: "DeepShield Custodia Pagos",
    hookHeadline: "Cero transferencias desviadas ni facturas adulteradas",
    description:
      "Doble verificación criptográfica fuera de banda (OOB) y padrón inmutable de cuentas proveedoras antes de liberar cualquier pago relevante.",
    roiBullet: "Protección directa del flujo de caja corporativo",
    pricePrefix: "Desde",
    priceMain: "USD $490",
    priceSuffix: "/ mes",
    priceSubnote: "Custodia activa Zero-Trust para finanzas y tesorería",
    ctaLabel: "Proteger mi tesorería hoy",
    colors: PALETTE_SAPPHIRE_GOLD_VIP,
  },

  // =========================================================================
  // PILLAR 3: KEYHUB (SaaS Ingeniería, Control Forense & Agentes IA)
  // =========================================================================
  "kh-pro": {
    tierNumber: "01",
    tag: "OPERACIÓN ÁGIL Y RENTABLE",
    title: "KeyHub Professional",
    hookHeadline: "Proyectos, horas, contratos y facturación bajo control total",
    description:
      "Unifica tableros Kanban, registro exacto de tiempos, firma digital y liquidación multimoneda USD/CLP en una sola plataforma intuitiva.",
    roiBullet: "Ahorra +18 horas semanales de gestión operativa",
    priceMain: "USD $8",
    priceSuffix: "/ usuario",
    priceSubnote: "Facturación mensual · Desde 20 usuarios activos",
    ctaLabel: "Ver demo de KeyHub Pro",
    colors: PALETTE_CYAN_VELOCITY,
  },
  "kh-ent": {
    tierNumber: "02",
    tag: "ESCALA CORPORATIVA PRIVADA",
    title: "KeyHub Enterprise Cloud",
    hookHeadline: "Gobierno total conectado a Salesforce y repositorios Git",
    description:
      "Nube privada aislada, sincronización nativa bidireccional con Salesforce y cadena de custodia completa desde cada ticket hasta producción.",
    roiBullet: "Disponibilidad SLA 99.9% · Aislamiento dedicado",
    priceMain: "USD $14",
    priceSuffix: "/ usuario",
    priceSubnote: "Valor mensual · Soporte prioritario corporativo",
    ctaLabel: "Cotizar nube Enterprise",
    colors: PALETTE_PLATINUM_EXECUTIVE,
  },
  "kh-forensic": {
    tierNumber: "03",
    tag: "PRECISIÓN Y AHORRO EN TI",
    title: "Auditoría Forense de Horas",
    hookHeadline: "Paga solo por trabajo real verificado matemáticamente",
    description:
      "Cruza actividad de código, entregables y jornadas para eliminar sobrefacturación de contratistas y respaldar auditorías internas o bancarias.",
    roiBullet: "Ahorro típico de 15% a 28% en presupuesto externo",
    pricePrefix: "+",
    priceMain: "USD $4",
    priceSuffix: "/ usuario",
    priceSubnote: "Módulo Add-on mensual sobre tu plan KeyHub",
    ctaLabel: "Activar auditoría exacta",
    colors: PALETTE_EMERALD_SHIELD,
  },
  "kh-agents": {
    tierNumber: "04",
    tag: "VANGUARDIA ABSOLUTA EN IA",
    title: "14 Agentes IA + Code Graph",
    hookHeadline: "Multiplica la velocidad de código gastando 97% menos tokens",
    description:
      "Despliega 14 agentes especialistas de software guiados por nuestro grafo semántico AST que entrega contexto quirúrgico sin desperdiciar IA.",
    roiBullet: "Hasta 97% de ahorro real en consumo de tokens",
    pricePrefix: "+",
    priceMain: "USD $2",
    priceSuffix: "/ usuario",
    priceSubnote: "Add-on disruptivo mensual · Retorno inmediato",
    ctaLabel: "Activar mis 14 Agentes IA",
    isVipFeatured: true,
    vipBadge: "★ Disruptivo · Ahorra 97% Tokens",
    colors: PALETTE_SAPPHIRE_GOLD_VIP,
  },
};

export interface V2PillarHeaderSpec {
  kicker: string;
  headline: string;
  subheadline: string;
  ctaHint: string;
  titleGradient: string;
  kickerColor: string;
  kickerBorder: string;
  kickerBg: string;
}

export const V2_PILLAR_HEADERS: Record<number, V2PillarHeaderSpec> = {
  1: {
    kicker: "ECOSISTEMA DIGITAL CORE · SALESFORCE, CLOUD & IA",
    headline: "Convierte tu Tecnología en Crecimiento Real en Semanas",
    subheadline:
      "Implementaciones rápidas a precio claro, retorno medible y talento senior potenciado con Inteligencia Artificial.",
    ctaHint: "Explorar Soluciones y Valores Digital Core",
    titleGradient: "linear-gradient(135deg, #FFFFFF 0%, #E0F2FE 50%, #7DD3FC 100%)",
    kickerColor: "#7dd3fc",
    kickerBorder: "rgba(56, 189, 248, 0.45)",
    kickerBg: "rgba(14, 165, 233, 0.16)",
  },
  2: {
    kicker: "CIBERDEFENSA EJECUTIVA DEEPSHIELD · ZERO-TRUST",
    headline: "Protege la Reputación y la Caja de tu Empresa Frente al Fraude IA",
    subheadline:
      "Anticípate a la suplantación de correos, clonación de voz y desvíos de pagos con defensa activa certificada.",
    ctaHint: "Probar Diagnóstico Gratis & Ver Planes DeepShield",
    titleGradient: "linear-gradient(135deg, #FFFFFF 0%, #D1FAE5 50%, #6EE7B7 100%)",
    kickerColor: "#6ee7b7",
    kickerBorder: "rgba(52, 211, 153, 0.45)",
    kickerBg: "rgba(16, 185, 129, 0.16)",
  },
  3: {
    kicker: "PLATAFORMA KEYHUB SAAS · GOBIERNO & AGENTES DE INGENIERÍA",
    headline: "Domina cada Proyecto, Hora Invertida y Línea de Código con IA",
    subheadline:
      "Transparencia matemática para tu operación tecnológica y 14 agentes IA que reducen hasta un 97% el costo en tokens.",
    ctaHint: "Descubrir Licencias desde USD $8 & Agentes IA",
    titleGradient: "linear-gradient(135deg, #FFFFFF 0%, #EDE9FE 50%, #C4B5FD 100%)",
    kickerColor: "#d8b4fe",
    kickerBorder: "rgba(167, 139, 250, 0.45)",
    kickerBg: "rgba(139, 92, 246, 0.18)",
  },
};

export function enrichPillarForV2(pillar: PillarCatalog): PillarCatalog {
  const hdr = V2_PILLAR_HEADERS[pillar.chapterIndex];
  const enrichedCards: ServiceCardOffer[] | undefined = pillar.cards?.map(
    (card) => {
      const spec = V2_CARD_SPECS[card.id];
      if (!spec) return card;
      return {
        ...card,
        tag: spec.tag,
        title: spec.title,
        description: spec.description,
        priceHighlight: `${spec.pricePrefix ? spec.pricePrefix + " " : ""}${spec.priceMain}${
          spec.priceSuffix ? " " + spec.priceSuffix : ""
        }`,
        priceNote: spec.priceSubnote,
        ctaLabel: spec.ctaLabel,
        isFeatured: spec.isVipFeatured ?? card.isFeatured,
        featuredBadge: spec.vipBadge ?? card.featuredBadge,
      };
    }
  );

  return {
    ...pillar,
    headline: hdr?.headline || pillar.headline,
    clickHint: hdr?.ctaHint || pillar.clickHint,
    cards: enrichedCards,
  };
}
