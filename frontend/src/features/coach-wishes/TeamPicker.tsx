import { useId, useState } from "react";

import type { PriorityTier, Team } from "@/features/wizard/api";
import { CtcMark } from "@/shared/components/ui/ctc-mark";
import { compareTeamsByRank, groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";

/**
 * P3-15 — CHOISIR PARMI 49 ÉQUIPES SANS DÉROULER 49 LIGNES.
 *
 * Le retour du fondateur ne visait pas la modale mais son contenu : « elle affiche TOUTES
 * les équipes et elle est donc illisible ». Une ligne par équipe, c'est 49 lignes sur un
 * club réel, et aucun geste de masse — alors que le cas courant est « je sollicite tout le
 * monde ».
 *
 * Trois leviers, dans cet ordre d'importance :
 *  1. AU REPOS, une seule ligne : « Toutes les équipes (49) · Modifier ». Le cas courant ne
 *     demande plus aucun geste, puisque tout est coché par défaut (voir `CampaignDialog`).
 *  2. DÉPLIÉ, des puces qui s'enroulent au lieu de lignes empilées : 49 équipes tiennent
 *     en quelques lignes. Même idiome que le sélecteur de jours des contraintes.
 *  3. Des raccourcis « tout / aucune », globaux ET par rang.
 *
 * ⚠ CE QUI EST SÉLECTIONNÉ EST TOUJOURS MONTRÉ (revue #346). Le premier jet ne rendait que
 * les équipes ÉLIGIBLES (actives, avec un coach) alors qu'une campagne existante peut
 * porter une équipe qui a perdu son coach depuis : « tout décocher » la laissait en place,
 * le résumé annonçait « 0 équipe » et l'enregistrement la postait quand même. Un état
 * invisible est un état faux — même correctif que pour les semaines retenues (#344).
 */
export function TeamPicker({
  teams,
  ineligibleIds,
  tiers,
  selected,
  onChange,
}: {
  /** Les équipes RENDUES : les éligibles, plus celles que la sélection porte encore. */
  teams: Team[];
  /** Parmi elles, celles qui ne peuvent plus être sollicitées (plus de coach, désactivée,
   *  supprimée) — marquées. Le motif exact n'est pas connu ici : on ne l'invente pas. */
  ineligibleIds: ReadonlySet<string>;
  tiers: PriorityTier[];
  selected: ReadonlySet<string>;
  onChange: (next: Set<string>) => void;
}) {
  const [open, setOpen] = useState(false);
  const panelId = useId();
  // ⚠ Tant que les rangs ne sont pas lus, `groupTeamsByTier` verse TOUT dans le seau
  // « Autres » puis re-groupe à leur arrivée : la rangée de puces se réordonnait sous le
  // pointeur (revue #346). `compareTeamsByRank` donne le MÊME ordre sans avoir besoin des
  // rangs — un seul groupe sans étiquette, qui devient étiqueté quand ils arrivent.
  const groups = 0 === tiers.length ? [{ tier: null, teams: [...teams].sort(compareTeamsByRank) }] : groupTeamsByTier(teams, tiers);

  const setMany = (ids: string[], picked: boolean) => {
    const next = new Set(selected);
    for (const id of ids) {
      if (picked) {
        next.add(id);
      } else {
        next.delete(id);
      }
    }
    onChange(next);
  };
  const allIds = teams.map((t) => t.id);
  const toggle = (id: string) => setMany([id], !selected.has(id));

  // ⚠ Le compte porte sur ce qui produira RÉELLEMENT un lien (revue #346 round 2) :
  // compter les inéligibles au numérateur ET au dénominateur annonçait « Toutes les
  // équipes (3) » quand deux seulement seraient sollicitées — et le sélecteur replié étant
  // la seule ligne lue, rien n'expliquait l'écart. Les inéligibles se disent À PART.
  const eligible = teams.filter((t) => !ineligibleIds.has(t.id));
  const chosen = eligible.filter((t) => selected.has(t.id)).length;
  const strayChosen = teams.filter((t) => ineligibleIds.has(t.id) && selected.has(t.id)).length;
  const base =
    0 === eligible.length ? "Aucune équipe avec un coach rattaché" : chosen === eligible.length ? `Toutes les équipes (${eligible.length})` : `${chosen} équipe${chosen > 1 ? "s" : ""} sur ${eligible.length}`;
  const summary = 0 === strayChosen ? base : `${base} · ${strayChosen} sans coach, à retirer`;

  return (
    <fieldset className="mt-4">
      {/* Le `fieldset`/`legend` du premier jet avait disparu au passage aux puces : 49
          boutons à plat, sans nom de groupe, quand les Semaines gardaient le leur dans le
          même panneau (revue #346). */}
      <legend className="text-sm font-medium">Équipes</legend>
      <div className="mt-1 flex flex-wrap items-center gap-2">
        {/* Région live : sans elle, « tout décocher » ne s'annonçait par rien et son effet
            ne se découvrait qu'en trouvant le bouton d'enregistrement désactivé. */}
        <span aria-live="polite" className="text-sm text-muted-foreground">
          {summary}
        </span>
        {teams.length > 0 ? (
          <button type="button" aria-expanded={open} aria-controls={panelId} className="text-sm text-accent underline underline-offset-2" onClick={() => setOpen((prev) => !prev)}>
            {open ? "Replier la liste des équipes" : "Modifier les équipes"}
          </button>
        ) : null}
      </div>

      {open && teams.length > 0 ? (
        <div id={panelId} className="mt-2 space-y-2 rounded-md border border-border p-2">
          <div className="flex items-center gap-2 text-xs">
            <span className="text-muted-foreground">Toutes :</span>
            <BulkButton label="tout cocher" onClick={() => setMany(allIds, true)} />
            <BulkButton label="tout décocher" onClick={() => setMany(allIds, false)} />
          </div>

          {groups.map((group) => {
            const ids = group.teams.map((t) => t.id);
            const allPicked = ids.every((id) => selected.has(id));
            const label = null === group.tier && 0 === tiers.length ? "Toutes les équipes" : tierGroupLabel(group.tier);
            return (
              <div key={group.tier?.id ?? "orphan"} role="group" aria-label={label}>
                <div className="flex items-center gap-2">
                  <span className="text-xs font-medium text-muted-foreground">{label}</span>
                  {/* Un seul bouton par groupe, dont le LIBELLÉ dit ce qu'il va faire —
                      deux boutons « tout / aucune » par rang doubleraient la hauteur qu'on
                      vient de gagner. */}
                  <BulkButton label={allPicked ? `décocher ${label}` : `cocher ${label}`} onClick={() => setMany(ids, !allPicked)} />
                </div>
                <div className="mt-1 flex flex-wrap gap-1">
                  {group.teams.map((t) => {
                    const picked = selected.has(t.id);
                    const ineligible = ineligibleIds.has(t.id);
                    return (
                      <button
                        key={t.id}
                        type="button"
                        aria-pressed={picked}
                        aria-label={ineligible ? `${t.name} (ne peut plus être sollicitée)` : t.name}
                        onClick={() => toggle(t.id)}
                        className={cn(
                          "rounded-md border px-2 py-1 text-xs",
                          picked ? "border-accent bg-accent text-accent-foreground" : "border-border text-muted-foreground",
                          ineligible && "italic",
                        )}
                      >
                        {t.isCtc ? <CtcMark partner={t.ctcPartner} /> : null}
                        {t.name}
                        {ineligible ? " ⚠" : ""}
                      </button>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
      ) : null}
    </fieldset>
  );
}

function BulkButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className="rounded border border-border px-2 py-0.5 text-xs text-muted-foreground hover:text-foreground">
      {label}
    </button>
  );
}
