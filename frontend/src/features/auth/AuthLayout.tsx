import { Moon, Sun } from "lucide-react";
import type { ReactNode } from "react";

import { BrandMark } from "@/shared/components/ui/brand-mark";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { useThemeStore } from "@/shared/stores/themeStore";

interface AuthLayoutProps {
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
}

/** Centered card shell for all unauthenticated screens. */
export function AuthLayout({ title, description, children, footer }: AuthLayoutProps) {
  const mode = useThemeStore((state) => state.mode);
  const toggleMode = useThemeStore((state) => state.toggleMode);

  return (
    <main className="flex min-h-screen items-center justify-center bg-background p-4 text-foreground">
      <div className="w-full max-w-md">
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
