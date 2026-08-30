import { Spinner } from "@/shared/components/ui/spinner";
import { FichePage } from "@/shared/components/ui/fiche-page";
import { EmptyHint } from "@/shared/components/ui/empty-hint";

import { useReleaseNotes } from "./queries";

/**
 * P5-12 — la page « Nouveautés » (/nouveautes) : le journal complet des notes
 * publiées, les plus récentes d'abord. Corps en TEXTE BRUT (`whitespace-pre-line`),
 * aucun rendu markdown/html.
 */
export function ReleaseNotesPage() {
  const { data, isPending, isError, refetch } = useReleaseNotes();

  return (
    <FichePage className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Nouveautés</h1>
        <p className="mt-1 text-sm text-muted-foreground">Ce qui a changé récemment dans l'application.</p>
      </div>

      {isPending ? (
        <div className="flex items-center gap-2 text-sm text-muted-foreground" role="status">
          <Spinner className="size-4" /> Chargement…
        </div>
      ) : isError ? (
        <p className="text-sm text-destructive" role="alert">
          Le journal n'a pas pu être chargé.{" "}
          <button type="button" className="underline" onClick={() => void refetch()}>
            Réessayer
          </button>
        </p>
      ) : 0 === data.items.length ? (
        <EmptyHint>Aucune nouveauté pour le moment.</EmptyHint>
      ) : (
        <ul className="space-y-6">
          {data.items.map((item) => (
            <li key={item.id} className="rounded-lg border border-border p-4">
              <p className="text-base font-semibold">{item.title}</p>
              <p className="mt-0.5 text-xs text-muted-foreground">{formatDate(item.date)}</p>
              <p className="mt-3 whitespace-pre-line text-sm text-foreground">{item.body}</p>
            </li>
          ))}
        </ul>
      )}
    </FichePage>
  );
}

const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "long", year: "numeric" });

function formatDate(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}
