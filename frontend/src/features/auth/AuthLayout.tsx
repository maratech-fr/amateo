import { Moon, Sun } from "lucide-react";
import type { ReactNode } from "react";

import { BrandMark } from "@/shared/components/ui/brand-mark";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { cn } from "@/shared/lib/utils";
import { useThemeStore } from "@/shared/stores/themeStore";

interface AuthLayoutProps {
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
  /**
   * Largeur du conteneur. `md` (448 px) par défaut — login/inscription. SEULE la page publique
   * de doléances passe `2xl` (~672 px) : elle héberge un formulaire de disponibilités dense,
   * semaine par semaine, trop à l'étroit à 448 px (D2 PR A).
   */
  width?: "md" | "2xl";
}

/** Centered card shell for all unauthenticated screens. */
export function AuthLayout({ title, description, children, footer, width = "md" }: AuthLayoutProps) {
  const mode = useThemeStore((state) => state.mode);
  const toggleMode = useThemeStore((state) => state.toggleMode);

  return (
    <main className="flex min-h-screen items-center justify-center p-4 text-foreground">
      <div className={cn("w-full", "2xl" === width ? "max-w-2xl" : "max-w-md")}>
        <div className="mb-6 flex items-center justify-between">
          <BrandMark size="md" />
          <Button variant="ghost" size="icon" aria-label="Basculer le thème" onClick={toggleMode}>
            {mode === "dark" ? <Sun /> : <Moon />}
          </Button>
        </div>
        <Card>
          <CardHeader>
            <CardTitle>{title}</CardTitle>
            {description ? <CardDescription>{description}</CardDescription> : null}
          </CardHeader>
          <CardContent>{children}</CardContent>
        </Card>
        {footer ? <div className="mt-4 text-center text-sm text-muted-foreground">{footer}</div> : null}
      </div>
    </main>
  );
}
