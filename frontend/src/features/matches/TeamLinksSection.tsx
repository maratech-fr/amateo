import { Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";
import type { TeamLike, TierLike } from "@/shared/lib/teamTiers";

import type { TeamLink, TeamLinkIntensity, TeamLinkType } from "./api";
import { useCreateTeamLink, useDeleteTeamLink, useTeamLinks, useUpdateTeamLink } from "./queries";
import { INTENSITY_LABEL } from "./lib/teamLinkLabel";
import { linkableBTeams } from "./lib/linkableTeams";
import { MATCHES_TEAM_LINKS_TEXTS as TEXTS } from "./texts/teamLinks";

/** La phrase d'exemple parle des équipes du club : celle qui est ouverte et la première autre, sinon les deux premières. */
function linkExample<T extends TeamLike>(teams: T[], filterTeamId: string | undefined): string {
  const opened = teams.find((t) => t.id === filterTeamId);
  const [first, second] = undefined === opened ? teams : [opened, ...teams.filter((t) => t.id !== opened.id)];
  return undefined === first || undefined === second ? TEXTS.exampleGeneric : TEXTS.exampleNamed(first.name, second.name);
}

/**
 * Passerelles entre équipes — la SECTION seule (extraite de `HabitsLinksDialog`, lot P2-45).
 * Le dialog des matchs la RECOMPOSE (comportement `/matchs` inchangé) ; le wizard l'ouvre depuis
 * une équipe via `TeamLinksModal`. Deux paramètres :
 *  - `filterTeamId` : ne montre que les passerelles NOMMANT cette équipe, et pré-remplit
 *    l'équipe A du formulaire (l'écran s'ouvre déjà « côté » de cette équipe).
 *  - `readOnly` : une passerelle est de la structure de SAISON — depuis un écran de période
 *    (reprise/fermeture) on la LIT (avec son intensité) sans jamais la créer/modifier/supprimer.
 *    On dit POURQUOI à l'écran (`TeamLink` se déclare au niveau saison), sinon une section sans
 *    contrôle passe pour une panne. La liste reste en pleine lisibilité (read-only ≠ désactivé).
 *
 * Générique sur `TeamLike` : les deux hôtes (module matchs, wizard) ont un type d'équipe
 * différent mais le même id/nom — la section n'a besoin que de ça, jamais du type complet.
 */
export function TeamLinksSection<T extends TeamLike>({
  teams,
  tiers,
  filterTeamId,
  readOnly = false,
}: {
  teams: T[];
  tiers: TierLike[];
  filterTeamId?: string;
  readOnly?: boolean;
}) {
  const linksQuery = useTeamLinks();
  const links = linksQuery.data ?? [];
  const filteredLinks = undefined === filterTeamId ? links : links.filter((l) => l.teamAId === filterTeamId || l.teamBId === filterTeamId);
  const teamName = (id: string): string => teams.find((t) => t.id === id)?.name ?? TEXTS.teamFallback;

  return (
    <section className="flex flex-col gap-2">
      <h3 className="text-sm font-semibold">{TEXTS.title}</h3>

      {linksQuery.isError ? <p className="text-sm text-destructive">{TEXTS.loadError}</p> : null}

      {readOnly ? (
        <ReadOnlyLinks links={filteredLinks} teamName={teamName} filtered={undefined !== filterTeamId} />
      ) : (
        <EditableLinks teams={teams} tiers={tiers} links={filteredLinks} teamName={teamName} filterTeamId={filterTeamId} />
      )}
    </section>
  );
}

/** Lecture seule (période / vacances) : la liste + la raison EN TEXTE, aucun contrôle d'édition. */
function ReadOnlyLinks({ links, teamName, filtered }: { links: TeamLink[]; teamName: (id: string) => string; filtered: boolean }) {
  return (
    <>
      {/* La raison, en information et non en panne : pleine lisibilité, ton muet, jamais grisé. */}
      <p className="text-xs text-muted-foreground">{TEXTS.seasonOnly}</p>
      {0 === links.length ? (
        <EmptyHint className="text-xs">{filtered ? TEXTS.noneForTeam : TEXTS.none}</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-1">
          {links.map((link) => (
            <li key={link.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-border bg-card px-2 py-1.5 text-sm">
              <span className="flex-1">
                {teamName(link.teamAId)} ↔ {teamName(link.teamBId)} ·{" "}
                <span className="text-muted-foreground">{"NOT_SIMULTANEOUS" === link.linkType ? TEXTS.neverTogether : TEXTS.oneAfterOther}</span>
              </span>
              <span className="text-xs text-muted-foreground">{TEXTS.trainingIntensity(INTENSITY_LABEL[link.trainingIntensity])}</span>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

/** Édition (saison, et le dialog `/matchs`) : liste avec réglage d'intensité + suppression, et formulaire d'ajout. */
function EditableLinks<T extends TeamLike>({
  teams,
  tiers,
  links,
  teamName,
  filterTeamId,
}: {
  teams: T[];
  tiers: TierLike[];
  links: TeamLink[];
  teamName: (id: string) => string;
  filterTeamId?: string;
}) {
  const createLink = useCreateTeamLink();
  const updateLink = useUpdateTeamLink();
  const deleteLink = useDeleteTeamLink();

  // Ancrée (`filterTeamId` fourni) : A est FIGÉE — un FAIT affiché en texte, pas un champ. L'état A
  // n'existe alors plus (`teamAId = filterTeamId`), impossible de créer un lien « côté SM1 » qui ne
  // concerne pas SM1. Sans ancrage (dialog `/matchs`), le sélecteur A reste STRICTEMENT inchangé.
  const anchored = undefined !== filterTeamId;
  const [selectedA, setSelectedA] = useState("");
  const teamAId = filterTeamId ?? selectedA;
  const [linkTeamBId, setLinkTeamBId] = useState("");
  const [linkType, setLinkType] = useState<TeamLinkType>("NOT_SIMULTANEOUS");
  const [linkIntensity, setLinkIntensity] = useState<TeamLinkIntensity>("PREFERRED");
  const [pendingDeleteId, setPendingDeleteId] = useState<string | null>(null);
  const pendingDelete = links.find((l) => l.id === pendingDeleteId) ?? null;

  // B proposables : ancrée → toutes − A − déjà liées à A (garde-fou d'ergonomie, le serveur refuse
  // le doublon en 422 quoi qu'il arrive) ; sans ancrage → toutes (comportement `/matchs` inchangé,
  // décision fondateur 2026-08-23). `links` est déjà filtré à l'équipe ancrée = les liens nommant A.
  const bTeams = anchored ? linkableBTeams(teams, teamAId, links) : teams;
  // « Toutes déjà liées » n'est vrai que s'il EXISTE d'autres équipes, toutes liées à A. S'il n'y a
  // simplement aucune autre équipe (club à une seule équipe), on garde le formulaire d'ajout normal
  // (B vide, bouton inerte) plutôt qu'une phrase fausse — cf. TeamsStep « Liens de … » à une équipe.
  const hasOtherTeams = teams.some((t) => t.id !== teamAId);
  const noLinkableB = anchored && hasOtherTeams && 0 === bTeams.length;

  const addLink = (): void => {
    if ("" === teamAId || "" === linkTeamBId || teamAId === linkTeamBId || createLink.isPending) {
      return;
    }
    createLink.mutate({ teamAId, teamBId: linkTeamBId, linkType, trainingIntensity: linkIntensity });
  };

  return (
    <>
      <p className="text-xs text-muted-foreground">
        {linkExample(teams, filterTeamId)}
        {TEXTS.twoSettingsBefore}
        <strong>{TEXTS.twoSettingsStrong}</strong>
        {TEXTS.twoSettingsAfter}
      </p>
      <ul className="ml-4 list-disc text-xs text-muted-foreground">
        <li>
          <strong>{TEXTS.matchesSide}</strong>
          {TEXTS.matchesSideDetail}
        </li>
        <li>
          <strong>{TEXTS.trainingSide}</strong>
          {TEXTS.trainingSideSeparator}
          <em>{TEXTS.preferred}</em>
          {TEXTS.preferredDetail}
          <em>{TEXTS.mandatory}</em>
          {TEXTS.mandatoryDetail}
        </li>
      </ul>

      <ul className="flex flex-col gap-1">
        {links.map((link) => (
          <li key={link.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-border bg-card px-2 py-1.5 text-sm">
            <span className="flex-1">
              {teamName(link.teamAId)} ↔ {teamName(link.teamBId)} ·{" "}
              <span className="text-muted-foreground">{"NOT_SIMULTANEOUS" === link.linkType ? TEXTS.neverTogether : TEXTS.oneAfterOther}</span>
            </span>
            <label className="flex items-center gap-1 text-xs text-muted-foreground">
              {TEXTS.training}
              <Select
                aria-label={TEXTS.intensityAria(teamName(link.teamAId), teamName(link.teamBId))}
                wrapperClassName="w-32"
                value={link.trainingIntensity}
                disabled={updateLink.isPending}
                onChange={(e) => updateLink.mutate({ link, input: { trainingIntensity: e.target.value as TeamLinkIntensity } })}
              >
                <option value="PREFERRED">{INTENSITY_LABEL.PREFERRED}</option>
                <option value="MANDATORY">{INTENSITY_LABEL.MANDATORY}</option>
              </Select>
            </label>
            <Button
              variant="ghost"
              size="icon"
              className="size-8 text-destructive"
              aria-label={TEXTS.deleteAria(teamName(link.teamAId), teamName(link.teamBId))}
              disabled={deleteLink.isPending}
              onClick={() => setPendingDeleteId(link.id)}
            >
              <Trash2 className="size-4" />
            </Button>
          </li>
        ))}
      </ul>

      {noLinkableB ? (
        // Ancrée, toutes les équipes déjà liées à A : jamais un sélecteur vide muet, une phrase.
        <p className="text-xs text-muted-foreground">{TEXTS.allLinked(teamName(teamAId))}</p>
      ) : (
        // Correctif 4 — les CINQ champs d'ajout sur UNE ligne (grille), chacun avec son libellé
        // AU-DESSUS et aligné en bas (`items-end`) — fini « Équipe A/B » d'un côté, « Match/
        // Entraînement » de l'autre. Repli propre en écran étroit : deux colonnes qui s'empilent.
        <div className="grid grid-cols-2 items-end gap-2 sm:grid-cols-[repeat(4,minmax(0,1fr))_auto]">
          {/* Équipe A — `<div>` + légende décorative (jamais `<label>` : le Listbox se nomme
              via son `aria-label`, un `<label>` doublonnerait l'association). */}
          <div className="flex flex-col gap-1 text-xs text-muted-foreground">
            {TEXTS.teamA}
            {anchored ? (
              // A FIGÉE : un FAIT en texte (« SM1 ↔ … »), pas un champ désactivé (qui se lirait comme une panne).
              <span className="flex h-9 items-center gap-1.5 text-sm">
                <span className="sr-only">{TEXTS.anchoredHint(teamName(teamAId))}</span>
                <span className="font-medium text-foreground">{teamName(teamAId)}</span>
                <span aria-hidden className="text-muted-foreground">↔</span>
              </span>
            ) : (
              <TeamSelect aria-label={TEXTS.firstTeamAria} wrapperClassName="w-full" teams={teams} tiers={tiers} placeholder={TEXTS.teamAPlaceholder} value={selectedA} onValueChange={setSelectedA} />
            )}
          </div>
          <div className="flex flex-col gap-1 text-xs text-muted-foreground">
            {TEXTS.teamB}
            <TeamSelect aria-label={TEXTS.secondTeamAria} wrapperClassName="w-full" teams={bTeams} tiers={tiers} placeholder={TEXTS.teamBPlaceholder} value={linkTeamBId} onValueChange={setLinkTeamBId} />
          </div>
          <label className="flex flex-col gap-1 text-xs text-muted-foreground">
            {TEXTS.matches}
            <Select aria-label={TEXTS.linkTypeAria} className="h-9" wrapperClassName="w-full" value={linkType} onChange={(e) => setLinkType(e.target.value as TeamLinkType)}>
              <option value="NOT_SIMULTANEOUS">{TEXTS.optionNeverTogether}</option>
              <option value="BACK_TO_BACK">{TEXTS.optionOneAfterOther}</option>
            </Select>
          </label>
          <label className="flex flex-col gap-1 text-xs text-muted-foreground">
            {TEXTS.training}
            <Select aria-label={TEXTS.newIntensityAria} className="h-9" wrapperClassName="w-full" value={linkIntensity} onChange={(e) => setLinkIntensity(e.target.value as TeamLinkIntensity)}>
              <option value="PREFERRED">{INTENSITY_LABEL.PREFERRED}</option>
              <option value="MANDATORY">{INTENSITY_LABEL.MANDATORY}</option>
            </Select>
          </label>
          <Button
            size="sm"
            disabled={"" === teamAId || "" === linkTeamBId || teamAId === linkTeamBId || createLink.isPending}
            onClick={addLink}
          >
            <Plus className="size-3.5" />
            {TEXTS.add}
          </Button>
        </div>
      )}

      <ConfirmDialog
        open={null !== pendingDelete}
        title={TEXTS.deleteTitle}
        description={pendingDelete ? TEXTS.deleteDescription(teamName(pendingDelete.teamAId), teamName(pendingDelete.teamBId)) : null}
        confirmLabel={TEXTS.deleteConfirm}
        destructive
        onConfirm={() => {
          if (null !== pendingDelete) {
            deleteLink.mutate(pendingDelete.id);
          }
          setPendingDeleteId(null);
        }}
        onCancel={() => setPendingDeleteId(null)}
      />
    </>
  );
}
