import type { Metadata } from 'next';
import './globals.css';
export const metadata: Metadata = { title: 'Comanda Online', description: 'Gestão de restaurantes, bares e espetarias' };
export default function RootLayout({children}:{children:React.ReactNode}) { return <html lang="pt-BR"><body>{children}</body></html>; }