import { Loader2 } from "lucide-react";

import { cn } from "@/shared/lib/utils";

export function Spinner({ className }: { className?: string }) {
  return <Loader2 className={cn("size-5 animate-spin text-muted-foreground motion-reduce:animate-none", className)} aria-label="Chargement" />;
}

export function FullPageSpinner() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background">
      <Spinner className="size-8" />
    </div>
  );
}
