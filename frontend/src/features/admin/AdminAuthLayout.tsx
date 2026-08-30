import { ShieldCheck } from "lucide-react";
import type { ReactNode } from "react";

import { PRODUCT_NAME } from "@/shared/lib/product";

interface AdminAuthLayoutProps {
  title: string;
  description: string;
  children: ReactNode;
}

export function AdminAuthLayout({ title, description, children }: AdminAuthLayoutProps) {
  return (
    <main className="relative flex min-h-screen items-center justify-center overflow-hidden bg-slate-950 p-6 text-slate-100">
      <div className="pointer-events-none absolute -left-24 -top-24 size-80 rounded-full bg-cyan-400/10 blur-3xl" />
      <div className="pointer-events-none absolute -bottom-32 -right-24 size-96 rounded-full bg-indigo-500/10 blur-3xl" />
      <div className="relative w-full max-w-md">
        <div className="mb-8 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="flex size-10 items-center justify-center rounded-xl border border-cyan-300/20 bg-cyan-300/10 text-cyan-200">
              <ShieldCheck className="size-5" aria-hidden="true" />
            </div>
            <div>
              <p className="text-sm font-semibold tracking-wide text-white">{PRODUCT_NAME}</p>
              <p className="text-xs uppercase tracking-[0.22em] text-slate-400">Console sécurisée</p>
            </div>
          </div>
        </div>
        <section className="rounded-2xl border border-white/10 bg-white/[0.06] p-1 shadow-2xl shadow-black/30 backdrop-blur-xl">
          <div className="rounded-[0.85rem] border border-white/10 bg-slate-950/70 p-7">
            <p className="mb-3 text-xs font-medium uppercase tracking-[0.22em] text-cyan-300">Accès restreint</p>
            <h1 className="text-2xl font-semibold tracking-tight text-white">{title}</h1>
            <p className="mt-2 text-sm leading-6 text-slate-400">{description}</p>
            <div className="mt-7">{children}</div>
          </div>
        </section>
        <p className="mt-5 text-center text-xs text-slate-500">Chaque accès à cette console est journalisé.</p>
      </div>
    </main>
  );
}
