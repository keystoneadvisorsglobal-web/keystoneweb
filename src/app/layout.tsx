import type { Metadata } from "next";
import { Plus_Jakarta_Sans, Inter } from "next/font/google";
import "./globals.css";

const jakarta = Plus_Jakarta_Sans({
  subsets: ["latin"],
  weight: ["500", "600", "700", "800"],
  variable: "--font-jakarta",
  display: "swap",
});

const inter = Inter({
  subsets: ["latin"],
  weight: ["400", "500", "600"],
  variable: "--font-inter",
  display: "swap",
});

export const metadata: Metadata = {
  title: "Keystone Advisors Global | Consultoría Estratégica Salesforce, Cloud & Ciberseguridad",
  description: "Aceleramos el futuro digital de las empresas más exigentes con ingeniería de software de élite, Salesforce FSC, KeyHub SaaS y Blindaje perimetral BEC.",
  icons: {
    icon: [
      { url: "/favicon.png?v=ks44", type: "image/png", sizes: "64x64" },
      { url: "/favicon.ico?v=ks44", sizes: "any" },
    ],
    shortcut: "/favicon.png?v=ks44",
    apple: "/favicon.png?v=ks44",
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="es" className={`${jakarta.variable} ${inter.variable}`}>
      <body className="antialiased selection:bg-slate-900 selection:text-white">
        {children}
      </body>
    </html>
  );
}
