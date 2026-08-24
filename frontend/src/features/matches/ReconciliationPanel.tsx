import { AlertTriangle } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import type { Deviation, DeviationDecision, DeviationField } from "./api";
import { DEPOSITED_WARNING, FIELD_LABEL, fieldConsequence, isDeposited } from "./lib/deviationConsequence";
import { FIXTURE_STATUS_LABEL } from "./lib/fixtureStatusLabel";

interface ReconciliationPanelProps {
  deviations: Deviation[];
  /** Controlled: the decisions posed so far (flat, one per field — the API shape). */
  decisions: DeviationDecision[];
  onDecisionsChange: (decisions: DeviationDecision[]) => void;
  teamName: (teamId: string) => string;
}

/** Every (fixture, field) pair that must be decided — the mass-gesture universe. */
function allFieldKeys(deviations: Deviation[]): { fixtureId: string; field: DeviationField }[] {
  return deviations.flatMap((d) =>
    (Object.keys(d.fields) as DeviationField[]).map((field) => ({ fixtureId: d.fixtureId, field })),
  );
}

/**
 * RMM-4 — l'écran « état app VS état fichier », UNE carte par écart, AGNOSTIQUE du
 * canal (le dialogue d'import puis PR-3 le rebrancheront ; le panneau ne connaît ni
 * l'un ni l'autre). Contrôlé : il rend depuis `decisions` et émet la liste COMPLÈTE
 * des décisions posées à chaque geste (par champ, ou en masse). Aucune valeur par
 * défaut — un champ non tranché reste non tranché (l'import est sûr par défaut).
 *
 * Conception (passe `ui-ux-pro-max`, verdict 2026-08-24) : colonnes app/fichier
 * côte à côte, divergence en teinte warning (jamais rouge — le rouge est réservé à
 * la CONSÉQUENCE), toggle segmenté sans défaut, conséquence TOUJOURS visible sous
 * le champ, bande destructive `role="alert"` quand le match est saisi/validé FBI,
 * badge « Écart persistant » le plus lourd.
 */
export function ReconciliationPanel({ deviations, decisions, onDecisionsChange, teamName }: ReconciliationPanelProps) {
  const choiceFor = (fixtureId: string, field: DeviationField): "keep_app" | "take_file" | undefined =>
    decisions.find((d) => d.fixtureId === fixtureId && d.field === field)?.choice;

  const setChoice = (fixtureId: string, field: DeviationField, choice: "keep_app" | "take_file"): void => {
    const without = decisions.filter((d) => !(d.fixtureId === fixtureId && d.field === field));
    onDecisionsChange([...without, { fixtureId, field, choice }]);
  };

  const setAll = (choice: "keep_app" | "take_file"): void =>
    onDecisionsChange(allFieldKeys(deviations).map((k) => ({ ...k, choice })));

  const total = allFieldKeys(deviations).length;
  const decided = decisions.length;

  return (
    <div className="flex flex-col gap-4">
      {/* Barre sticky : gestes de masse (pré-remplissage, pas d'écriture) + avancement. */}
      <div className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-2 rounded-md border border-border bg-card/95 px-3 py-2 backdrop-blur">
        <span className="text-sm text-muted-foreground" role="status">
          {decided}/{total} écart{total > 1 ? "s" : ""} tranché{decided > 1 ? "s" : ""}
        </span>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" onClick={() => setAll("take_file")}>
            Tout prendre du fichier
          </Button>
          <Button variant="outline" size="sm" onClick={() => setAll("keep_app")}>
            Tout garder
          </Button>
        </div>
      </div>

      {deviations.map((deviation) => {
        const deposited = isDeposited(deviation.status);
        return (
          <article key={deviation.fixtureId} aria-label={`Écart — ${teamName(deviation.teamId)} · ${deviation.division}`} className="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
            <div className="flex flex-col gap-3 p-4">
                {/* En-tête : équipe · division · n° · statut FR · badge persistant. */}
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span className="font-medium">{teamName(deviation.teamId)}</span>
                  <span className="text-muted-foreground">· {deviation.division}</span>
                  <span className="text-xs text-muted-foreground">· Rencontre n° {deviation.externalRef}</span>
                  <span className="ml-auto inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                    {FIXTURE_STATUS_LABEL[deviation.status]}
                  </span>
                  {deviation.persisting ? (
                    <span className="inline-flex items-center gap-1 rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive">
                      <AlertTriangle className="size-3" aria-hidden="true" />
                      Écart persistant
                    </span>
                  ) : null}
                </div>

                {/* Signalement renforcé : le match est déjà déposé à la fédération. */}
                {deposited ? (
                  <div role="alert" className="flex items-start gap-2 rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-foreground">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden="true" />
                    <span>{DEPOSITED_WARNING}</span>
                  </div>
                ) : null}

                {/* Un bloc par champ divergent : deux colonnes + choix + conséquence. */}
                {(Object.keys(deviation.fields) as DeviationField[]).map((field) => {
                  const values = deviation.fields[field];
                  if (undefined === values) {
                    return null;
                  }
                  const choice = choiceFor(deviation.fixtureId, field);
                  const consequence = fieldConsequence(field);
                  const who = teamName(deviation.teamId);
                  return (
                    <div key={field} className="rounded-md border border-border p-3">
                      <p className="mb-2 text-sm font-medium">{FIELD_LABEL[field]}</p>
                      <div className="grid grid-cols-2 gap-2 text-sm">
                        <div className="rounded border border-border bg-muted/30 px-2 py-1">
                          <span className="block text-xs text-muted-foreground">Dans l'app</span>
                          <span>{values.app ?? "—"}</span>
                        </div>
                        <div className="rounded border border-warning/60 bg-warning/10 px-2 py-1">
                          <span className="block text-xs text-muted-foreground">Dans le fichier</span>
                          <span className="font-medium">{values.file ?? "—"}</span>
                        </div>
                      </div>

                      {/* Toggle segmenté SANS défaut — aria-pressed porte l'état. */}
                      <div role="group" aria-label={`Choix pour ${FIELD_LABEL[field]} · ${who}`} className="mt-2 inline-flex overflow-hidden rounded-md border border-border">
                        <button
                          type="button"
                          aria-label={`Garder l'app — ${FIELD_LABEL[field]} · ${who}`}
                          aria-pressed={"keep_app" === choice}
                          onClick={() => setChoice(deviation.fixtureId, field, "keep_app")}
                          className={cn("px-3 py-1.5 text-sm transition-colors", "keep_app" === choice ? "bg-accent text-accent-foreground" : "text-muted-foreground hover:bg-muted")}
                        >
                          Garder l'app
                        </button>
                        <button
                          type="button"
                          aria-label={`Prendre le fichier — ${FIELD_LABEL[field]} · ${who}`}
                          aria-pressed={"take_file" === choice}
                          onClick={() => setChoice(deviation.fixtureId, field, "take_file")}
                          className={cn("border-l border-border px-3 py-1.5 text-sm transition-colors", "take_file" === choice ? "bg-accent text-accent-foreground" : "text-muted-foreground hover:bg-muted")}
                        >
                          Prendre le fichier
                        </button>
                      </div>

                      {/* Conséquence TOUJOURS visible (avant le choix). */}
                      <p className={cn("mt-2 text-xs", consequence.releasesSlot ? "text-warning-foreground" : "text-muted-foreground")}>{consequence.takeFile}</p>
                      {"keep_app" === choice ? <p className="mt-1 text-xs text-muted-foreground">{consequence.keepApp}</p> : null}
                    </div>
                  );
                })}
            </div>
          </article>
        );
      })}
    </div>
  );
}
