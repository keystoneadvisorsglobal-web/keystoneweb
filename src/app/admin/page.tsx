import type { Metadata } from "next";
import AdminConsoleClient from "./AdminConsoleClient";

export const metadata: Metadata = {
  title: "Keystone Command Console v4.4 | Gobierno Ejecutivo & Telemetría",
  description:
    "Consola ejecutiva de gobierno comercial B2B, radar anti-fraude BEC, TrustZero y configuración espacial en vivo de Keystone Advisors.",
  icons: {
    icon: [
      { url: "/favicon.png?v=ks44", type: "image/png", sizes: "64x64" },
      { url: "/favicon.ico?v=ks44", sizes: "any" },
    ],
    shortcut: "/favicon.png?v=ks44",
    apple: "/favicon.png?v=ks44",
  },
  robots: {
    index: false,
    follow: false,
  },
};

export default function AdminPage() {
  return <AdminConsoleClient />;
}
