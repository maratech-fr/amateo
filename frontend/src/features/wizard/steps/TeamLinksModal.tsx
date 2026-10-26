import { useCallback, useEffect, useRef, useState } from "react";

import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { Tabs, TabPanel } from "@/shared/components/ui/tabs";
import { TeamLinksSection } from "@/features/matches/TeamLinksSection";

import type { PriorityTier, Team } from "../api";
import { payload } from "../lib/teamPayload";
import { useUpdateTeam } from "../queries";
import { TEAMS_TEXTS } from "../texts/teams";
import { SharedTrainingBlockPanel } from "./SharedTrainingBlockPanel";

/**
 * Liens d'une équipe (P2-45) — l'écran unique par équipe : passerelles, mutualisation ET entente.
 * Ouvert depuis l'étape Équipes (saison via `TeamsEditor`, période via `PeriodTeamsPanel`).
 *
 * #10 (améliorations légères, retour Rillieux 2026-10-21) — les deux liens vivaient dans le MÊME
 * corps de modale, trop chargé : ils sont SÉPARÉS en onglets (primitive `Tabs`), « Passerelles »
 * actif par défaut (l'ordre historique haut → bas). Tous les panneaux restent montés (l'inactif est
 * `hidden`), chacun garde son propre état.
 *
 * Trois onglets :
 *  - les PASSERELLES sont de la structure de SAISON — éditables en saison, LECTURE SEULE en
 *    période/vacances (`readOnlyLinks`), filtrées à l'équipe d'ouverture ;
 *  - la MUTUALISATION (rendue par `SharedTrainingBlockPanel` — « bloc » est l'image interne côté
 *    backend) est ancrée au PLAN (`schedulePlanId` : `null` = socle, un UUID = période), donc
 *    éditable partout. L'équipe d'ouverture y est pré-cochée (`initialTeamId`) ;
 *  - l'ENTENTE (CTC) (D4, décision fondateur 2026-10-25) est un REPÈRE de club valable toute la
 *    saison, sans effet moteur : elle se règle ici (case + partenaire) par un PUT d'équipe complet,
 *    sur l'équipe d'ouverture SEULE, et n'hérite PAS de `readOnlyLinks` (se règle même en période).
 */
export function TeamLinksModal({
  team,
  teams,
  tiers,
  schedulePlanId,
  pausedTeamIds,
  readOnlyLinks,
  onClose,
}: {
  team: Pick<Team, "id" | "name">;
  teams: Team[];
  tiers: PriorityTier[];
  /** `null` = socle (saison) ; un UUID = plan de période. Gouverne l'écriture de la mutualisation. */
  schedulePlanId: string | null;
  pausedTeamIds?: ReadonlySet<string>;
  /** Passerelles en lecture seule (période/vacances) : la saison seule les déclare. */
  readOnlyLinks: boolean;
  onClose: () => void;
}) {
  const [activeTab, setActiveTab] = useState<"passerelles" | "mutualisation" | "entente">("passerelles");

  return (
    <Modal label={TEAMS_TEXTS.linksModalTitle(team.name)} title={TEAMS_TEXTS.linksModalTitle(team.name)} onClose={onClose} size="lg">
      <div className="flex flex-col gap-4">
        <Tabs
          tabs={[
            { id: "passerelles", label: TEAMS_TEXTS.passerellesTab },
            { id: "mutualisation", label: TEAMS_TEXTS.mutualisationTab },
            { id: "entente", label: TEAMS_TEXTS.ctcTabLabel },
          ]}
          activeTab={activeTab}
          onTabChange={(id) => setActiveTab(id as "passerelles" | "mutualisation" | "entente")}
          ariaLabel={TEAMS_TEXTS.linkTypeAria}
          idPrefix="team-links"
        />
        <TabPanel tabId="passerelles" idPrefix="team-links" active={"passerelles" === activeTab}>
          <TeamLinksSection teams={teams} tiers={tiers} filterTeamId={team.id} readOnly={readOnlyLinks} />
        </TabPanel>
        <TabPanel tabId="mutualisation" idPrefix="team-links" active={"mutualisation" === activeTab}>
          <SharedTrainingBlockPanel teams={teams} tiers={tiers} pausedTeamIds={pausedTeamIds} schedulePlanId={schedulePlanId} initialTeamId={team.id} />
        </TabPanel>
        <TabPanel tabId="entente" idPrefix="team-links" active={"entente" === activeTab}>
          <TeamCtcPanel teamId={team.id} teams={teams} />
        </TabPanel>
      </div>
    </Modal>
  );
}

/**
 * Onglet « Entente (CTC) » (D4). Règle l'entente de l'équipe d'OUVERTURE par un PUT d'équipe complet
 * (`useUpdateTeam`), lu de l'équipe FRAÎCHE (`teams`) et non du snapshot `team` (un `Pick`). La case
 * s'enregistre au clic ; le champ partenaire (masqué tant que décoché) au blur, à « Entrée » et au
 * DÉMONTAGE (anti-perte de saisie : « Échap » ferme sans blur). Décocher efface le partenaire.
 * L'enregistrement est silencieux (le filet global `MutationCache.onError` toaste une erreur).
 */
function TeamCtcPanel({ teamId, teams }: { teamId: string; teams: Team[] }) {
  const fresh = teams.find((t) => t.id === teamId) ?? null;
  const update = useUpdateTeam();

  const [checked, setChecked] = useState(() => true === fresh?.isCtc);
  const [partner, setPartner] = useState(() => fresh?.ctcPartner ?? "");

  // Refs pour un flush STABLE au démontage (patron `flushSort` de `TeamsStep`) : la cleanup ne doit
  // tirer QUE sur unmount, jamais sur un refetch de `teams`. `committed` = le partenaire déjà
  // persisté (état serveur au montage, puis la dernière écriture) — pour ne commiter qu'un changement.
  const freshRef = useRef(fresh);
  const updateRef = useRef(update);
  const partnerRef = useRef(partner);
  const checkedRef = useRef(checked);
  const committedRef = useRef((fresh?.ctcPartner ?? "").trim());
  // Resync des refs APRÈS le rendu (jamais pendant — `react-hooks/refs`), patron `flushSort` de
  // `TeamsStep` : la cleanup de flush lit alors les dernières valeurs sans dépendre d'elles.
  useEffect(() => {
    freshRef.current = fresh;
    updateRef.current = update;
    partnerRef.current = partner;
    checkedRef.current = checked;
  });

  const commitPartner = useCallback(() => {
    const f = freshRef.current;
    if (null === f || !checkedRef.current) {
      return;
    }
    const next = partnerRef.current.trim();
    if (next === committedRef.current) {
      return;
    }
    committedRef.current = next;
    updateRef.current.mutate({ id: f.id, body: payload(f, { isCtc: true, ctcPartner: next || null }) });
  }, []);

  // Flush au démontage (« Échap » ferme la modale sans blur préalable).
  useEffect(() => () => commitPartner(), [commitPartner]);

  const toggle = (value: boolean) => {
    if (null === fresh) {
      return;
    }
    setChecked(value);
    if (value) {
      committedRef.current = partner.trim();
      update.mutate({ id: fresh.id, body: payload(fresh, { isCtc: true }) });
    } else {
      // Décocher efface le partenaire côté serveur ; le buffer est vidé pour ne jamais le re-proposer.
      setPartner("");
      committedRef.current = "";
      update.mutate({ id: fresh.id, body: payload(fresh, { isCtc: false, ctcPartner: null }) });
    }
  };

  return (
    <div className="flex flex-col gap-3">
      <p id="team-ctc-help" className="text-xs text-muted-foreground">
        {TEAMS_TEXTS.ctcHelp}
      </p>
      <label className="flex items-center gap-2 text-sm text-foreground">
        <input type="checkbox" className="size-4 shrink-0" checked={checked} aria-describedby="team-ctc-help" onChange={(e) => toggle(e.target.checked)} />
        {TEAMS_TEXTS.ctcCheckboxLabel}
      </label>
      {checked ? (
        <label className="flex max-w-sm flex-col gap-1 text-sm text-foreground">
          {TEAMS_TEXTS.ctcPartnerLabel}
          <Input
            maxLength={120}
            placeholder={TEAMS_TEXTS.ctcPartnerPlaceholder}
            value={partner}
            onChange={(e) => setPartner(e.target.value)}
            onBlur={commitPartner}
            onKeyDown={(e) => {
              if ("Enter" === e.key) {
                e.preventDefault();
                commitPartner();
              }
            }}
          />
        </label>
      ) : null}
    </div>
  );
}
