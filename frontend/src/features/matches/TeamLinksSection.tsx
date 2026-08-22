import { Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";
import type { TeamLike, TierLike } from "@/shared/lib/teamTiers";

import type { TeamLink, TeamLinkIntensity, TeamLinkType } from "./api";
import { useCreateTeamLink, useDeleteTeamLink, useTeamLinks, useUpdateTeamLink } from "./queries";
import { INTENSITY_LABEL } from "./lib/teamLinkLabel";


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
  const teamName = (id: string): string => teams.find((t) => t.id === id)?.name ?? "Équipe ?";

  return (
    <section className="flex flex-col gap-2">
      <h3 className="text-sm font-semibold">Passerelles entre équipes</h3>

      {linksQuery.isError ? <p className="text-sm text-destructive">Les passerelles n’ont pas pu être chargées.</p> : null}

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
      <p className="text-xs text-muted-foreground">Les passerelles se déclarent au niveau de la saison — elles s’appliquent à toutes les périodes.</p>
      {0 === links.length ? (
        <p className="text-xs text-muted-foreground">{filtered ? "Aucune passerelle pour cette équipe." : "Aucune passerelle déclarée."}</p>
      ) : (
        <ul className="flex flex-col gap-1">
          {links.map((link) => (
            <li key={link.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-border px-2 py-1.5 text-sm">
              <span className="flex-1">
                {teamName(link.teamAId)} ↔ {teamName(link.teamBId)} ·{" "}
                <span className="text-muted-foreground">{"NOT_SIMULTANEOUS" === link.linkType ? "jamais en même temps" : "l'un après l'autre"}</span>
              </span>
              <span className="text-xs text-muted-foreground">Entraînement : {INTENSITY_LABEL[link.trainingIntensity]}</span>
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

  const [linkTeamAId, setLinkTeamAId] = useState(filterTeamId ?? "");
  const [linkTeamBId, setLinkTeamBId] = useState("");
  const [linkType, setLinkType] = useState<TeamLinkType>("NOT_SIMULTANEOUS");
  const [linkIntensity, setLinkIntensity] = useState<TeamLinkIntensity>("PREFERRED");

  const addLink = (): void => {
    if ("" === linkTeamAId || "" === linkTeamBId || linkTeamAId === linkTeamBId || createLink.isPending) {
      return;
    }
    createLink.mutate({ teamAId: linkTeamAId, teamBId: linkTeamBId, linkType, trainingIntensity: linkIntensity });
  };

  return (
    <>
      <p className="text-xs text-muted-foreground">
        « SM1 et SM2 partagent des joueurs » → déclarez une passerelle. Deux réglages <strong>indépendants</strong> :
      </p>
      <ul className="ml-4 list-disc text-xs text-muted-foreground">
        <li>
          <strong>Côté matchs</strong> — « jamais en même temps » (le radar alerte) ou « l'un après l'autre » (enchaînement respecté au placement).
        </li>
        <li>
          <strong>Côté entraînement</strong> — <em>Préféré</em> : les séances des deux équipes évitent de se chevaucher. <em>Obligatoire</em> : jamais en
          même temps — peut rendre le planning infaisable si trop contraint.
        </li>
      </ul>

      <ul className="flex flex-col gap-1">
        {links.map((link) => (
          <li key={link.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-border px-2 py-1.5 text-sm">
            <span className="flex-1">
              {teamName(link.teamAId)} ↔ {teamName(link.teamBId)} ·{" "}
              <span className="text-muted-foreground">{"NOT_SIMULTANEOUS" === link.linkType ? "jamais en même temps" : "l'un après l'autre"}</span>
            </span>
            <label className="flex items-center gap-1 text-xs text-muted-foreground">
              Entraînement
              <Select
                aria-label={`Intensité d'entraînement, passerelle ${teamName(link.teamAId)} – ${teamName(link.teamBId)}`}
                className="h-8 w-32"
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
              className="size-7"
              aria-label={`Supprimer la passerelle ${teamName(link.teamAId)} – ${teamName(link.teamBId)}`}
              disabled={deleteLink.isPending}
              onClick={() => deleteLink.mutate(link.id)}
            >
              <Trash2 className="size-4" />
            </Button>
          </li>
        ))}
      </ul>

      <div className="flex flex-wrap items-end gap-2">
        <TeamSelect aria-label="Première équipe du lien" className="w-32" teams={teams} tiers={tiers} placeholder="Équipe A…" value={linkTeamAId} onChange={(e) => setLinkTeamAId(e.target.value)} />
        <TeamSelect aria-label="Seconde équipe du lien" className="w-32" teams={teams} tiers={tiers} placeholder="Équipe B…" value={linkTeamBId} onChange={(e) => setLinkTeamBId(e.target.value)} />
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Matchs
          <Select aria-label="Type de lien côté matchs" className="h-9 w-44" value={linkType} onChange={(e) => setLinkType(e.target.value as TeamLinkType)}>
            <option value="NOT_SIMULTANEOUS">Jamais en même temps</option>
            <option value="BACK_TO_BACK">L'un après l'autre</option>
          </Select>
        </label>
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Entraînement
          <Select aria-label="Intensité d'entraînement du lien" className="h-9 w-32" value={linkIntensity} onChange={(e) => setLinkIntensity(e.target.value as TeamLinkIntensity)}>
            <option value="PREFERRED">{INTENSITY_LABEL.PREFERRED}</option>
            <option value="MANDATORY">{INTENSITY_LABEL.MANDATORY}</option>
          </Select>
        </label>
        <Button
          size="icon"
          className="size-9"
          aria-label="Ajouter la passerelle"
          title="Ajouter la passerelle"
          disabled={"" === linkTeamAId || "" === linkTeamBId || linkTeamAId === linkTeamBId || createLink.isPending}
          onClick={addLink}
        >
          <Plus className="size-4" />
        </Button>
      </div>
    </>
  );
}
