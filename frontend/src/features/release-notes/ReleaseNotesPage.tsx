import { Spinner } from "@/shared/components/ui/spinner";
import { FichePage } from "@/shared/components/ui/fiche-page";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { PageHeader } from "@/shared/components/ui/page-header";

import { useReleaseNotes } from "./queries";
import { ReleaseNoteHeading } from "./ReleaseNoteHeading";
import { RELEASE_NOTES_TEXTS } from "./texts";

/**
 * P5-12 — la page « Nouveautés » (/nouveautes) : le journal complet des notes
 * publiées, les plus récentes d'abord. Corps en TEXTE BRUT (`whitespace-pre-line`),
 * aucun rendu markdown/html.
 *
 * `prose={false}` : le corps d'une note occupe toute la largeur de sa carte — la borne de
 * lisibilité de `FichePage` laissait un vide à droite de chaque carte.
 */
export function ReleaseNotesPage() {
  const { data, isPending, isError, refetch } = useReleaseNotes();

  return (
    <FichePage className="space-y-6" prose={false}>
      <PageHeader title={RELEASE_NOTES_TEXTS.pageTitle} screen="/nouveautes" subtitle={RELEASE_NOTES_TEXTS.pageSubtitle} />

      {isPending ? (
        <div className="flex items-center gap-2 text-sm text-muted-foreground" role="status">
          <Spinner className="size-4" /> {RELEASE_NOTES_TEXTS.loading}
        </div>
      ) : isError ? (
        <LoadErrorHint onRetry={() => void refetch()}>{RELEASE_NOTES_TEXTS.loadError}</LoadErrorHint>
      ) : 0 === data.items.length ? (
        <EmptyHint>{RELEASE_NOTES_TEXTS.empty}</EmptyHint>
      ) : (
        <ul className="space-y-6">
          {data.items.map((item) => (
            <li key={item.id} className="rounded-lg border border-border bg-card p-4">
              <ReleaseNoteHeading date={item.date} version={item.version} />
              <h2 className="mt-0.5 text-base font-semibold">{item.title}</h2>
              <p className="mt-3 whitespace-pre-line text-sm text-foreground">{item.body}</p>
            </li>
          ))}
        </ul>
      )}
    </FichePage>
  );
}
