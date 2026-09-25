import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { snapshotFile } from "@/shared/lib/fileSnapshot";
import { errorMessage } from "@/shared/lib/errorMessage";

import type { TeamImportAnalysis, TeamImportResult } from "../api";
import { useAnalyzeTeamsImport, useImportTeams } from "../queries";

interface TeamsImportModalProps {
  clubId: string;
  seasonId: string;
  onClose: () => void;
}

/**
 * Import FBI des équipes (P3-7, décision fondateur) — modale à deux temps de l'ONBOARDING,
 * utilisée une ou deux fois : on dépose l'export FBI, on VOIT les équipes qu'il contient
 * (et lesquelles sont déjà présentes, pour ne pas faire de doublon), on ne coche que
 * celles à importer, on complète ensuite manuellement. Pas de lien FFBB, saison SEULE.
 *
 * Le contrat (analyse dry-run puis import des lignes cochées) et TOUTES les règles métier
 * vivent côté serveur (`FfbbExcelImporter` + `TeamImportGate`) : le front AFFICHE, il
 * n'invente aucune règle et relaie les refus tels quels (`errorMessage`).
 *
 * Décisions d'interaction (passe `ui-ux-pro-max`, corpus a11y/feedback) :
 *  - CLÔTURE : jamais bloquée (rien n'est écrit avant l'import, l'analyse est un dry-run) —
 *    seule la double-soumission est empêchée (input/bouton désarmés pendant un appel).
 *    « Fermer » + Esc + overlay restent toujours ouverts (escape-route).
 *  - FOCUS : le focus initial et le piège sont ceux de la primitive `Modal` (`useModalA11y`) ;
 *    aucun vol de focus aux changements d'étape — l'écran nouveau s'ANNONCE (aria-live).
 *  - ANNONCES : `role="status"` pour l'analyse en cours et le rapport (polite) ;
 *    `role="alert"` pour l'encart d'erreur HTTP (assertive) ; « déjà présente » est du
 *    TEXTE dans le libellé de la case (jamais couleur seule).
 *  - BOUTON : « Importer N équipe(s) », compte vivant, désarmé à 0 ou pendant un appel.
 */
export function TeamsImportModal({ clubId, seasonId, onClose }: TeamsImportModalProps) {
  const analyze = useAnalyzeTeamsImport();
  const importMut = useImportTeams();
  // Le SNAPSHOT mémoire du fichier (figé à la sélection) : le même objet sert l'analyse
  // ET l'import, immunisé contre un fichier ré-écrit sur disque entre les deux.
  const [file, setFile] = useState<File | null>(null);
  const [analysis, setAnalysis] = useState<TeamImportAnalysis | null>(null);
  const [result, setResult] = useState<TeamImportResult | null>(null);
  // Numéros de ligne Excel cochés. Les doublons (`alreadyPresent`) naissent DÉCOCHÉS.
  const [checked, setChecked] = useState<Set<number>>(new Set());
  const [httpError, setHttpError] = useState<string | null>(null);

  const analyzing = analyze.isPending;
  const importing = importMut.isPending;

  const onFileChange = async (next: File | null): Promise<void> => {
    setHttpError(null);
    if (null === next) {
      return;
    }
    let snapshot: File;
    try {
      snapshot = await snapshotFile(next);
    } catch {
      setHttpError("Le fichier n'a pas pu être lu — est-il ouvert dans un autre logiciel ?");
      return;
    }
    setFile(snapshot);
    analyze.mutate(
      { clubId, seasonId, file: snapshot },
      {
        onSuccess: (data) => {
          setAnalysis(data);
          // Défaut : tout coché SAUF les déjà-présentes (anti-doublon), cochables à la main.
          setChecked(new Set(data.rows.filter((r) => !r.alreadyPresent).map((r) => r.row)));
        },
        onError: (error) => void errorMessage(error).then(setHttpError),
      },
    );
  };

  const toggle = (row: number): void =>
    setChecked((prev) => {
      const next = new Set(prev);
      if (next.has(row)) {
        next.delete(row);
      } else {
        next.add(row);
      }
      return next;
    });

  const rows = analysis?.rows ?? [];
  const allChecked = rows.length > 0 && rows.every((r) => checked.has(r.row));
  const toggleAll = (): void => setChecked(allChecked ? new Set() : new Set(rows.map((r) => r.row)));

  const doImport = (): void => {
    if (null === file) {
      return;
    }
    setHttpError(null);
    importMut.mutate(
      { clubId, seasonId, file, rows: [...checked].sort((a, b) => a - b) },
      {
        onSuccess: setResult,
        onError: (error) => void errorMessage(error).then(setHttpError),
      },
    );
  };

  const count = checked.size;
  const showSelection = null !== analysis && null === result;

  return (
    <Modal
      label="Importer vos équipes (export FBI)"
      title="Importer vos équipes (export FBI)"
      size="md"
      onClose={onClose}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>
            Fermer
          </Button>
          {showSelection ? (
            <Button size="sm" disabled={0 === count || importing} onClick={doImport}>
              {importing ? <Spinner className="size-4" /> : null}
              Importer {count} équipe{count > 1 ? "s" : ""}
            </Button>
          ) : null}
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {/* Temps 1 — dépôt. Tant qu'aucune analyse n'a abouti, on montre le champ de fichier. */}
        {null === analysis && null === result ? (
          <>
            <p className="text-sm text-muted-foreground">
              Déposez l'export FBI de la liste de vos équipes (.xlsx, colonnes Nom, Catégorie, Numéro, Organisme). Un
              autre fichier Excel ne sera pas reconnu.
            </p>
            <label className="flex flex-col gap-1 text-sm">
              <span className="text-muted-foreground">Fichier FBI (.xlsx)</span>
              <input
                aria-label="Fichier FBI (.xlsx)"
                type="file"
                accept=".xlsx"
                className="text-sm"
                disabled={analyzing}
                onChange={(e) => void onFileChange(e.target.files?.[0] ?? null)}
              />
            </label>
            {analyzing ? (
              <p role="status" className="flex items-center gap-2 text-sm text-muted-foreground">
                <Spinner className="size-4" />
                Analyse du fichier…
              </p>
            ) : null}
          </>
        ) : null}

        {/* Temps 2 — sélection. Une ligne par équipe, doublons décochés par défaut. */}
        {showSelection ? (
          <>
            <div className="flex items-center justify-between gap-2">
              <p className="text-sm text-muted-foreground">
                Cochez les équipes à importer. Celles déjà présentes sont décochées pour éviter les doublons.
              </p>
              {rows.length > 0 ? (
                <Button variant="ghost" size="sm" onClick={toggleAll}>
                  {allChecked ? "Tout décocher" : "Tout cocher"}
                </Button>
              ) : null}
            </div>
            <ul className="flex flex-col gap-1">
              {rows.map((r) => (
                <li key={r.row}>
                  <label className="flex items-center gap-2 rounded px-1 py-1 text-sm hover:bg-muted">
                    <input type="checkbox" className="size-4 shrink-0" checked={checked.has(r.row)} onChange={() => toggle(r.row)} />
                    <span className="min-w-0 flex-1">
                      {r.name} · {r.category}
                      {r.alreadyPresent ? <span className="text-muted-foreground"> — déjà présente</span> : null}
                    </span>
                  </label>
                </li>
              ))}
            </ul>
            {analysis.errors.length > 0 ? (
              <ul className="list-inside list-disc text-xs text-destructive">
                {analysis.errors.map((error, i) => (
                  <li key={i}>{error}</li>
                ))}
              </ul>
            ) : null}
          </>
        ) : null}

        {/* Temps 3 — rapport. La modale reste ouverte pour le lire. */}
        {null !== result ? (
          <div role="status" className="flex flex-col gap-2 text-sm">
            <p className="font-medium">
              {result.created} équipe{result.created > 1 ? "s" : ""} créée{result.created > 1 ? "s" : ""} · {result.skipped}{" "}
              ignorée{result.skipped > 1 ? "s" : ""} (déjà présentes sous le même nom)
            </p>
            {result.errors.length > 0 ? (
              <ul className="list-inside list-disc text-xs text-destructive">
                {result.errors.map((error, i) => (
                  <li key={i}>{error}</li>
                ))}
              </ul>
            ) : null}
            <p className="text-muted-foreground">
              Complétez maintenant chaque équipe : genre, niveau de jeu, rang et séances.
            </p>
            <p className="text-xs text-muted-foreground">
              La fédération ne déclare pas toutes vos équipes (loisirs, école de basket…) : celles qui manquent
              s'ajoutent à la main, ce n'est pas un oubli de l'import.
            </p>
          </div>
        ) : null}

        {/* Encart d'erreur HTTP — dans la modale (le message porte l'action), jamais un toast. */}
        {null !== httpError ? (
          <p role="alert" className="text-sm text-destructive">
            {httpError}
          </p>
        ) : null}
      </div>
    </Modal>
  );
}
