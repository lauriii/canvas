import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import { DraftIndicator } from "@/components/draft-indicator";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Canvas Headless example app",
  description:
    "Example frontend app embedded in the Drupal Canvas editor, rendering draft content via user-bound preview tokens.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="min-h-full flex flex-col">
        <DraftIndicator />
        {children}
      </body>
    </html>
  );
}
