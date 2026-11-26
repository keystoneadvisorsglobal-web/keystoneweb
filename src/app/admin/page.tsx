import type { Metadata } from "next";
import AdminConsoleClient from "./AdminConsoleClient";

export const metadata: Metadata = {
  title: "Keystone Command Console v4.0 | Gobierno Ejecutivo & Telemetría",
  description:
    "Consola ejecutiva de gobierno comercial B2B, radar anti-fraude BEC, TrustZero y configuración espacial en vivo de Keystone Advisors.",
  robots: {
    index: false,
    follow: false,
  },
};

export default function AdminPage() {
  return <AdminConsoleClient />;
}
