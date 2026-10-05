import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Clock } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { errorMessage } from "@/shared/lib/errorMessage";
import { setClubClock } from "@/shared/session/api";
import { useMe } from "@/shared/session/queries";
import { toast } from "@/shared/stores/toastStore";

/**
 * Widget d'horloge d'un compte de DÉMONSTRATION, dans l'en-tête à côté de la pastille « Démo ».
 *
 * Décision fondateur 2026-10-02 : l'horloge simulée ne vit QUE pour un compte démo — le widget
 * ne se montre donc que si `club.isDemo` (lu du serveur, jamais recalculé ; rien pour un vrai
 * club). La pastille-bouton affiche la date simulée (« Aujourd'hui : 15 mai 2027 ») ou
 * « Horloge : aujourd'hui » quand aucune n'est posée ; au clic, un popover offre un champ date,
 * « Appliquer » et « Revenir à aujourd'hui ». Le serveur écrit `simulated_today` ; on invalide
 * `/api/me` → `useApplySimulatedClock` recale `clock.ts` et tous les écrans datés se mettent à
 * jour sans rechargement.
 */
const dateFr = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "long", year: "numeric" });

function formatSimulated(iso: string): string {
  // Midi pour éviter un décalage de jour à la conversion locale près de minuit.
  return dateFr.format(new Date(`${iso}T12:00:00`));
}

export function DemoClockWidget() {
  const { data } = useMe();
  const queryClient = useQueryClient();
  const simulatedToday = data?.club?.simulatedToday ?? null;
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState(simulatedToday ?? "");
  const rootRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);

  // Ouvrir repart de la valeur serveur courante ; fermer ne touche à rien. La saisie se règle
  // dans le GESTIONNAIRE (jamais dans un effet — pas de rendu en cascade).
  const toggle = (): void => {
    if (!open) {
      setDraft(simulatedToday ?? "");
    }
    setOpen((o) => !o);
  };

  const mutation = useMutation({
    // Enrobé : react-query passe un 2ᵉ argument de contexte au mutationFn — l'API ne reçoit
    // que le corps.
    mutationFn: (body: { date: string } | { clear: true }) => setClubClock(body),
    onSuccess: () => {
      // L'horloge effective bouge → recaler la session ; useApplySimulatedClock propage au reste.
      void queryClient.invalidateQueries({ queryKey: ["me"] });
      setOpen(false);
    },
    // FRT-41 — restituer le message du serveur (409/422 écrits pour être lus) plutôt qu'un
    // générique qui l'écrase ; repli FR par statut sinon (via `errorMessage`).
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });

  // A11Y-29 — popover NON MODAL : Échap ferme ET rend le focus au déclencheur (patron BetaBadge) ;
  // clic extérieur ferme sans forcer le focus. Effet inerte tant que fermé.
  useEffect(() => {
    if (!open) {
      return;
    }
    const onKey = (event: KeyboardEvent): void => {
      if ("Escape" === event.key) {
        event.preventDefault();
        setOpen(false);
        triggerRef.current?.focus();
      }
    };
    const onDown = (event: MouseEvent): void => {
      if (null !== rootRef.current && !rootRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("keydown", onKey);
    document.addEventListener("mousedown", onDown);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("mousedown", onDown);
    };
  }, [open]);

  // Réservé aux comptes de démonstration (le serveur refuse un vrai club de toute façon).
  if (true !== data?.club?.isDemo) {
    return null;
  }

  const label = null !== simulatedToday ? `Aujourd’hui : ${formatSimulated(simulatedToday)}` : "Horloge : aujourd’hui";

  return (
    <div ref={rootRef} className="relative shrink-0">
      <Button
        ref={triggerRef}
        type="button"
        size="sm"
        variant="outline"
        aria-haspopup="dialog"
        aria-expanded={open}
        title="Horloge simulée de la démo — cliquer pour modifier"
        onClick={toggle}
      >
        <Clock className="size-3.5" aria-hidden="true" />
        {label}
      </Button>
      {open ? (
        <div role="dialog" aria-modal="false" aria-label="Horloge simulée de la démo" className="absolute left-0 z-40 mt-1 w-64 rounded-lg border border-border bg-card p-3 text-card-foreground shadow-lg">
          <p className="mb-2 text-xs text-muted-foreground">Faire vivre la démo à une autre date (écrans, échéances, rappels).</p>
          <label className="sr-only" htmlFor="demo-clock-date">Date simulée de la démo</label>
          <Input
            id="demo-clock-date"
            type="date"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            className="mb-2"
          />
          <div className="flex flex-wrap justify-end gap-2">
            <Button
              type="button"
              size="sm"
              variant="ghost"
              disabled={null === simulatedToday || mutation.isPending}
              onClick={() => mutation.mutate({ clear: true })}
            >
              Revenir à aujourd’hui
            </Button>
            <Button
              type="button"
              size="sm"
              disabled={"" === draft || mutation.isPending}
              onClick={() => mutation.mutate({ date: draft })}
            >
              Appliquer
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
