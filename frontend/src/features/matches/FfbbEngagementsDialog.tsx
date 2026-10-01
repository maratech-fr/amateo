import { Check, Sparkles, TriangleAlert } from "lucide-react";
import { useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Spinner } from "@/shared/components/ui/spinner";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { LEVEL_LABEL } from "@/shared/lib/teamIdentity";

import type { FfbbEngagement, FfbbPairing, PriorityTier, Team } from "./api";
import { useConfirmFfbbPairings, useFfbbEngagements } from "./queries";

interface FfbbEngagementsDialogProps {
  teams: Team[];
  tiers: PriorityTier[];
  onClose: () => void;
}

/**
 * FFBB pairing (P1-4 PR F, appariement §3): the club's engagements of the
 * season, each to be attached to an app team — confirmed IN BLOCK. Re-paired
 * at each phase (« 1 clic contre un calendrier fiable ») : next phases come
 * pre-filled from the previous pairing. An empty row = not paired — nothing
 * else to model (the absence of a link IS the state).
 */
export function FfbbEngagementsDialog({ teams, tiers, onClose }: FfbbEngagementsDialogProps) {
  const engagements = useFfbbEngagements(true);
  const confirm = useConfirmFfbbPairings();
  // ffbbCompetitionId → teamId chosen ("" = not paired).
  const [choices, setChoices] = useState<Record<string, string>>({});
  // ffbbCompetitionId → le gestionnaire a accepté d'aligner le niveau sur le déduit.
  const [alignChoices, setAlignChoices] = useState<Record<string, boolean>>({});

  const rows = engagements.data?.engagements ?? [];
  const chosenOf = (id: string, suggested: string | null): string => choices[id] ?? suggested ?? "";
  // La proposition d'alignement ne tient que SUR l'équipe suggérée (D5) : un écart calculé
  // par le serveur ne vaut que si l'équipe choisie est encore celle qu'il a mesurée. Changer
  // d'équipe fait tomber la proposition (et son intention) jusqu'à réouverture.
  const canAlign = (row: FfbbEngagement, teamId: string): boolean =>
    null !== row.alignment && null !== row.deducedLevel && "" !== teamId && teamId === row.suggestedTeamId;
  const pairings: FfbbPairing[] = rows
    .map((row): FfbbPairing => {
      const teamId = chosenOf(row.ffbbCompetitionId, row.suggestedTeamId);
      const base: FfbbPairing = { ffbbCompetitionId: row.ffbbCompetitionId, teamId };
      // Quand l'équipe choisie EST l'équipe suggérée (peu importe la source), la réf FFBB se pose
      // SUR la compétition xlsx déjà appariée (C1) : on transmet son `suggestedCompetitionId` pour
      // réutiliser la compétition côté serveur plutôt que d'en dupliquer une. Si le gestionnaire a
      // changé d'équipe, la suggestion ne tient plus — pas de `competitionId`.
      if ("" !== teamId && teamId === row.suggestedTeamId && null !== row.suggestedCompetitionId) {
        base.competitionId = row.suggestedCompetitionId;
      }
      // Intention d'alignement du niveau : seulement si la proposition tient encore ET a été acceptée.
      if (canAlign(row, teamId) && true === alignChoices[row.ffbbCompetitionId]) {
        base.alignLevel = true;
      }
      return base;
    })
    .filter((pairing) => "" !== pairing.teamId);

  const teamName = (id: string): string => teams.find((team) => team.id === id)?.name ?? "";
  const teamLevelLabel = (id: string): string | null => {
    const level = teams.find((team) => team.id === id)?.level ?? null;
    return null === level ? null : LEVEL_LABEL[level];
  };
  const onTeamChange = (id: string, value: string): void => {
    setChoices({ ...choices, [id]: value });
    // L'équipe change → la proposition d'alignement tombe : on oublie l'acceptation.
    setAlignChoices((prev) => {
      const next = { ...prev };
      delete next[id];
      return next;
    });
  };

  // Compteur d'en-tête (patron du compteur d'ImportFbi) : M = lignes, N = lignes rattachées
  // (équipe choisie OU suggestion conservée) = `pairings.length`.
  const attachedCount = pairings.length;
  // La phrase d'origine FBI ne s'affiche qu'une fois, et SEULEMENT s'il existe au moins une
  // suggestion issue d'un import FBI (une chip par ligne le rappelle ensuite).
  const hasFbiSuggestion = rows.some((row) => "fbi" === row.suggestionSource && null !== row.suggestedTeamId);

  return (
    <Modal
      label="Engagements FFBB"
      title="Engagements FFBB"
      onClose={onClose}
      size="xl"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>
            Fermer
          </Button>
          <Button
            size="sm"
            disabled={0 === pairings.length || confirm.isPending}
            onClick={() => confirm.mutate(pairings, { onSuccess: onClose })}
          >
            {confirm.isPending ? "Enregistrement…" : `Confirmer ${pairings.length} appariement${pairings.length > 1 ? "s" : ""}`}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-xs text-muted-foreground">
          Les équipes engagées telles que la ligue les connaît — rattachez chacune à votre équipe puis
          confirmez en bloc. À chaque nouvelle phase, ré-ouvrez : tout est pré-rempli.{" "}
          {hasFbiSuggestion ? "Les rattachements marqués proviennent de votre dernier import FBI. " : null}
          <strong>Données de la ligue — un écart se corrige auprès d'elle.</strong>
        </p>

        {engagements.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner />
          </div>
        ) : engagements.isError ? (
          <NoticeBanner tone="destructive" message="FFBB indisponible — réessayez plus tard." />
        ) : 0 === rows.length ? (
          <p className="text-sm text-muted-foreground">
            Aucun engagement trouvé pour cette saison (les poules sortent généralement après le 20 juillet).
          </p>
        ) : (
          <>
            <p className="text-xs font-medium text-foreground">
              {`${attachedCount} rattachée${attachedCount > 1 ? "s" : ""} sur ${rows.length}`}
            </p>
            <ul className="flex flex-col gap-2">
            {rows.map((row) => {
              // Le chiffre discriminant (« …Division 2 » vs « …Division 3 ») est en QUEUE de
              // chaîne : on LAISSE le libellé s'enrouler (jamais tronqué → toujours visible, y
              // compris au doigt) et on double d'un `title` de secours (§6bis B1/B2).
              const subLabel = `${row.pouleName} · ${row.pouleSize} clubs${null !== row.category ? ` · ${row.category}` : ""}${null !== row.level ? ` · ${row.level}` : ""}${null !== row.gender ? ` · ${row.gender}` : ""}`;
              const chosen = chosenOf(row.ffbbCompetitionId, row.suggestedTeamId);
              // La chip ne s'affiche que pour une HYPOTHÈSE issue d'un import FBI, tant que le
              // gestionnaire n'a pas retouché la ligne (choix explicite = `choices[id]` défini →
              // la chip tombe). Un `pairing`/`canonical` est un appariement confirmé/reconduit :
              // pas d'hypothèse, pas de chip.
              const showFbiChip = "fbi" === row.suggestionSource && null !== row.suggestedTeamId && undefined === choices[row.ffbbCompetitionId];
              return (
                <li key={row.ffbbCompetitionId} className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
                  <span className="min-w-0 text-sm">
                    <span className="block font-medium" title={row.competitionName}>
                      {row.competitionName}
                    </span>
                    <span className="block text-xs text-muted-foreground" title={subLabel}>
                      {subLabel}
                    </span>
                  </span>
                  <span className="flex shrink-0 flex-col items-end gap-1">
                    <TeamSelect
                      aria-label={`Équipe pour ${row.competitionName}`}
                      // La valeur sélectionnée se lit sans ouvrir le select (§6bis B4) : élargi,
                      // et un `title` en secours pour le nom d'équipe qui déborderait encore.
                      title={"" !== chosen ? teamName(chosen) : "Non rattachée"}
                      wrapperClassName="w-52 shrink-0"
                      teams={teams}
                      tiers={tiers}
                      placeholder="Non rattachée"
                      value={chosen}
                      onValueChange={(v) => onTeamChange(row.ffbbCompetitionId, v)}
                    />
                    {showFbiChip ? (
                      <StatusPill variant="neutral" icon={<Sparkles className="size-3.5 shrink-0" aria-hidden="true" />}>
                        suggéré depuis l'import FBI
                      </StatusPill>
                    ) : null}
                    {/* Niveau jeune déduit de l'engagement (D1/D5) : proposé, jamais pré-coché ni comblé
                        en douce. La pastille dit l'ÉTAT (fiche), le bouton l'ACTION ; après acceptation,
                        une pastille neutre + « Annuler » (texte, N1). Visible seulement sur l'équipe suggérée. */}
                    {canAlign(row, chosen) && null !== row.deducedLevel ? (
                      true === alignChoices[row.ffbbCompetitionId] ? (
                        <span className="flex items-center gap-1.5">
                          <StatusPill variant="neutral" icon={<Check className="size-3.5 shrink-0" aria-hidden="true" />}>
                            Sera aligné à la confirmation
                          </StatusPill>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setAlignChoices({ ...alignChoices, [row.ffbbCompetitionId]: false })}
                          >
                            Annuler
                          </Button>
                        </span>
                      ) : (
                        <span className="flex items-center gap-1.5">
                          <StatusPill variant="warning" icon={<TriangleAlert className="size-3.5 shrink-0" aria-hidden="true" />}>
                            {"MISSING" === row.alignment ? "Niveau non renseigné" : `Fiche : ${teamLevelLabel(chosen) ?? "—"}`}
                          </StatusPill>
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setAlignChoices({ ...alignChoices, [row.ffbbCompetitionId]: true })}
                          >
                            {"MISSING" === row.alignment
                              ? `Renseigner : ${LEVEL_LABEL[row.deducedLevel]}`
                              : `Aligner sur ${LEVEL_LABEL[row.deducedLevel]}`}
                          </Button>
                        </span>
                      )
                    ) : null}
                  </span>
                </li>
              );
            })}
            </ul>
          </>
        )}

      </div>
    </Modal>
  );
}
