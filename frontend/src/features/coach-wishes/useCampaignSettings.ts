import { type Dispatch, type SetStateAction, useMemo, useState } from "react";

import type { CalendarEntry } from "@/features/cockpit/api";
import type { PriorityTier, Team } from "@/features/wizard/api";
import { addDays, isActionableWeek, periodAdjustWeeks, todayISO } from "@/features/cockpit/lib/date";
import { usePriorityTiers, useWizardTeamCoaches, useWizardTeams } from "@/features/wizard/queries";
import { useCreateCoachWishCampaign, useUpdateCoachWishCampaign } from "./campaignQueries";

import type { CoachWishCampaign } from "./campaignApi";

interface PeriodWeek {
  monday: string;
  startDate: string;
  endDate: string;
}

/**
 * État + logique de l'onglet « Réglages » de la fenêtre doléances (ex-moitié Réglages de
 * `CampaignDialog`) : semaines, équipes, date limite, création/enregistrement de la collecte.
 * Vit au niveau du conteneur (`CoachWishesHub`) pour que le PIED DE MODALE (« Créer / Enregistrer »)
 * reste épinglé au niveau de la fenêtre tout en pilotant l'état porté ici. Déplacement VERBATIM :
 * les invariants #344/#346 (semaines orphelines, graine d'équipes posée une fois, équipes fantômes)
 * suivent leur code ci-dessous, commentaires compris.
 *
 * `onCreated` est appelé APRÈS une création réussie : le hub bascule alors sur l'onglet
 * « Sollicitation » et emporte le focus (patron focus `CampaignDialog` round 2).
 */
export interface CampaignSettings {
  campaign: CoachWishCampaign | null;
  setCampaign: Dispatch<SetStateAction<CoachWishCampaign | null>>;
  availableWeeks: PeriodWeek[];
  weeks: Set<string>;
  toggleWeek: (monday: string) => void;
  today: string;
  pickerTeams: Team[];
  ineligibleIds: Set<string>;
  tiers: PriorityTier[];
  teamIds: Set<string>;
  setTeamIds: (next: Set<string>) => void;
  deadline: string;
  setDeadline: (value: string) => void;
  canSave: boolean;
  saving: boolean;
  failed: boolean;
  save: () => void;
  /** Deux motifs réunis (#344 round 2) : une semaine retenue RÉVOLUE, ou que la période n'ÉMET
   *  plus — les deux doivent ramener le gestionnaire sur les Réglages à l'ouverture. */
  weeksNeedAttention: boolean;
}

export function useCampaignSettings({
  entry,
  season,
  existing,
  onCreated,
}: {
  entry: CalendarEntry;
  season: { startDate: string; endDate: string } | null;
  existing: CoachWishCampaign | null;
  onCreated: () => void;
}): CampaignSettings {
  const teamsQuery = useWizardTeams();
  const teamCoachesQuery = useWizardTeamCoaches();
  const { data: tiers = [] } = usePriorityTiers();
  const createCampaign = useCreateCoachWishCampaign();
  const updateCampaign = useUpdateCoachWishCampaign();

  // Campagne courante (après enregistrement, on garde la réponse pour afficher les liens).
  const [campaign, setCampaign] = useState<CoachWishCampaign | null>(existing);

  // P3-13/P3-15 (c) — on ne sollicite un coach que pour ce qu'il reste à vivre : les
  // semaines RÉVOLUES étaient proposées ET cochées par défaut. Même règle et même foyer
  // que le radar (`isActionableWeek`), pas une seconde implémentation (CLAUDE.md §7.2).
  // ⚠ Une semaine ENTAMÉE reste offerte (revue #344) : une vacance qui démarre un samedi
  // n'aurait plus pu faire l'objet d'aucune collecte dès le lundi suivant, pour des
  // séances pourtant toutes à venir — et rien d'autre dans l'app ne crée une campagne.
  //
  // ⚠ Une campagne EXISTANTE peut porter une semaine devenue révolue : elle reste LISTÉE
  // et marquée, jamais offerte à une nouvelle sélection. La masquer laisserait `weeks`
  // porter un lundi invisible que `save()` renverrait — un état que l'écran ne montre pas
  // (CHOISIR n'offre que l'avenir, NOMMER garde le reste lisible).
  const today = todayISO();
  const availableWeeks = useMemo(() => {
    if (null === season) {
      return [];
    }
    const all = periodAdjustWeeks(entry.startDate, entry.endDate, season, entry.periodType);
    const kept = new Set(existing?.weeks ?? []);
    const offered = all.filter((w) => isActionableWeek(w, today) || kept.has(w.monday));
    // ⚠ Une semaine retenue par la campagne peut ne PLUS être émise du tout — la période
    // a été redimensionnée, ou la fenêtre de saison a bougé. Se contenter de filtrer `all`
    // la laissait invisible tout en la gardant dans l'état, que `save()` renvoyait : le
    // gestionnaire confirme ce qu'il voit et sollicite pour une semaine que l'écran ne lui
    // a jamais montrée (revue #344 round 2 — le défaut même que le round 1 prétendait
    // clore). On la reconstruit depuis son lundi pour qu'elle reste sous les yeux.
    const shown = new Set(offered.map((w) => w.monday));
    const orphans = [...kept].filter((monday) => !shown.has(monday)).map((monday) => ({ monday, startDate: monday, endDate: addDays(monday, 6) }));

    return [...offered, ...orphans].sort((a, b) => a.monday.localeCompare(b.monday));
  }, [entry, season, existing, today]);

  // Équipes ayant AU MOINS un coach — cocher une équipe sans coach ne crée aucun lien.
  const coachCountByTeam = useMemo(() => {
    const map = new Map<string, number>();
    for (const tc of teamCoachesQuery.data ?? []) {
      map.set(tc.teamId, (map.get(tc.teamId) ?? 0) + 1);
    }
    return map;
  }, [teamCoachesQuery.data]);
  const teams = (teamsQuery.data ?? []).filter((t) => t.isActive && (coachCountByTeam.get(t.id) ?? 0) > 0);

  // Défaut : les semaines À VENIR seulement — cocher d'office une semaine révolue partait
  // solliciter les coachs pour du passé.
  const [weeks, setWeeks] = useState<Set<string>>(() => new Set(existing ? existing.weeks : availableWeeks.filter((w) => isActionableWeek(w, today)).map((w) => w.monday)));
  // P3-15 — une nouvelle collecte démarre avec TOUTES les équipes (celles qui ont un
  // coach) : solliciter tout le monde est le cas courant, et il demandait 49 clics. Une
  // campagne existante rouvre évidemment sur SA sélection, jamais sur « toutes ».
  //
  // ⚠ DÉRIVÉ, pas figé dans un état initial : `teams` vient d'une requête et vaut `[]` au
  // premier rendu. Un `useState(() => teams.map(…))` n'est évalué QU'UNE fois — le défaut
  // serait resté vide pour toujours, et la modale aurait affiché « 0 équipe sur 49 » en se
  // croyant d'accord avec elle-même.
  //
  // ⚠ Ni figé au premier rendu, ni dérivé en permanence (revue #346) :
  //  - un `useState(() => teams.map(…))` gèle un défaut VIDE, `teams` valant [] au premier
  //    rendu — la modale aurait affiché « 0 équipe sur 49 » en se croyant d'accord ;
  //  - mais le lire à CHAQUE rendu perd l'INSTANTANÉ : la sélection intacte suivait alors la
  //    requête, et un refetch d'arrière-plan (une saisie d'email invalide les coachs)
  //    élargissait la collecte en silence à des équipes jamais montrées — sélecteur replié,
  //    la seule trace à l'écran était une ligne de résumé.
  // On SEMENCE donc une fois, dès que les équipes sont réellement là : après quoi la
  // sélection n'appartient plus qu'au gestionnaire.
  const [pickedTeamIds, setPickedTeamIds] = useState<Set<string> | null>(null !== existing ? new Set(existing.teamIds) : null);
  // La graine est posée UNE fois, au premier rendu où les équipes existent — ajustement
  // d'état PENDANT le rendu, le motif documenté pour « recalculer quand une donnée arrive »
  // (ni initialiseur paresseux, qui gèlerait le vide, ni effet, que React refuse ici).
  // Après ça, la sélection n'appartient qu'au gestionnaire : aucun refetch ne l'élargit
  // dans son dos.
  // ⚠ On attend que les deux lectures soient POSÉES, pas seulement non vides (revue #346
  // round 2) : react-query sert un cache périmé immédiatement et refetch derrière, si bien
  // qu'un cache de plus de 30 s semait une liste incomplète et s'y verrouillait — « 48
  // équipes sur 49 » annoncé comme « toutes », et le coach de la 49ᵉ sans lien.
  const teamsSettled = undefined !== teamsQuery.data && !teamsQuery.isFetching && undefined !== teamCoachesQuery.data && !teamCoachesQuery.isFetching;
  const [teamsSeeded, setTeamsSeeded] = useState(false);
  // `teams.length > 0` reste requis : une lecture POSÉE mais vide (aucune équipe éligible)
  // ne doit pas verrouiller une graine vide — on laisse la graine ouverte, la sélection
  // reste vide et « Créer » désactivé, ce qui est la vérité.
  if (!teamsSeeded && null === pickedTeamIds && teamsSettled && teams.length > 0) {
    setTeamsSeeded(true);
    setPickedTeamIds(new Set(teams.map((t) => t.id)));
  }
  const teamIds = useMemo(() => pickedTeamIds ?? new Set<string>(), [pickedTeamIds]);
  const setTeamIds = setPickedTeamIds;

  // ⚠ Le sélecteur montre TOUT ce qui est sélectionné, éligible ou non : une campagne
  // existante peut porter une équipe qui a perdu son coach depuis. Ne rendre que les
  // éligibles laissait « tout décocher » sans effet sur elle, le résumé annoncer « 0 » et
  // l'enregistrement la poster quand même (revue #346).
  //
  // ⚠ Y COMPRIS UNE ÉQUIPE SUPPRIMÉE, que plus aucune requête ne connaît (round 2) : sans
  // ça elle restait dans `teamIds`, invisible, indécochable, et partait quand même au POST
  // — le serveur refusait, et l'écran ne disait pas laquelle. On la rend sous un libellé
  // générique : mieux vaut « équipe supprimée » qu'un id fantôme.
  const pickerTeams = useMemo(() => {
    const shown = new Set(teams.map((t) => t.id));
    const strays = (teamsQuery.data ?? []).filter((t) => !shown.has(t.id) && teamIds.has(t.id));
    const known = new Set([...shown, ...strays.map((t) => t.id)]);
    const ghosts = [...teamIds].filter((id) => !known.has(id)).map((id) => ({ id, name: "Équipe supprimée", isActive: false, priorityTierId: Number.MAX_SAFE_INTEGER, tierOrder: 0 }) as Team);

    return 0 === strays.length && 0 === ghosts.length ? teams : [...teams, ...strays, ...ghosts];
  }, [teams, teamsQuery.data, teamIds]);
  // ⚠ On n'ACCUSE que sur une donnée LUE (revue #346 round 2) : `teams` est vide tant que
  // les liens coachs n'ont pas répondu, si bien que TOUTES les équipes d'une campagne
  // existante s'affichaient « n'a plus de coach », en italique et fléchées. Le gestionnaire
  // en concluait que ses affectations avaient sauté — et « nettoyait » une sélection saine.
  const ineligibleIds = useMemo(
    () => (undefined === teamCoachesQuery.data ? new Set<string>() : new Set(pickerTeams.filter((t) => !teams.some((e) => e.id === t.id)).map((t) => t.id))),
    [pickerTeams, teams, teamCoachesQuery.data],
  );
  // La date limite par défaut était `entry.startDate`, donc DANS LE PASSÉ dès que la
  // période a commencé — cas que ce lot vient précisément de rendre légitime. Les liens
  // partaient morts : le serveur répond 410 « deadline dépassée », et le gestionnaire ne
  // l'apprenait qu'en voyant les coachs ne pas répondre (revue #344 round 2).
  const [deadline, setDeadline] = useState<string>(existing?.deadline ?? (entry.startDate > today ? entry.startDate : today));

  const toggleWeek = (monday: string) => {
    const next = new Set(weeks);
    if (next.has(monday)) {
      next.delete(monday);
    } else {
      next.add(monday);
    }
    setWeeks(next);
  };

  const canSave = weeks.size > 0 && teamIds.size > 0 && "" !== deadline;
  const saving = createCampaign.isPending || updateCampaign.isPending;

  const save = () => {
    const body = { calendarEntryId: entry.id, deadline, weeks: [...weeks].sort(), teamIds: [...teamIds] };
    if (null !== campaign) {
      updateCampaign.mutate({ id: campaign.id, body }, { onSuccess: setCampaign });
    } else {
      // Après création, on BASCULE sur les liens (onglet « Sollicitation ») : c'est ce que le
      // gestionnaire est venu chercher. Sans ça (revue #346), le bouton changeait de libellé,
      // un onglet apparaissait discrètement, et rien d'autre ne bougeait — on croyait l'échec.
      createCampaign.mutate(body, {
        onSuccess: (created) => {
          setCampaign(created);
          onCreated();
        },
      });
    }
  };

  const failed = createCampaign.isError || updateCampaign.isError;

  // Deux motifs, pas un (revue #346 round 2) : une semaine retenue peut être RÉVOLUE, ou
  // ne plus être ÉMISE par la période — c'est ce second cas que #344 avait attrapé, et que
  // mon premier jet laissait filer parce qu'une semaine orpheline encore future passe pour
  // actionnable. Les deux doivent ramener le gestionnaire sur les Réglages.
  const emittedMondays = useMemo(
    () => new Set(null === season ? [] : periodAdjustWeeks(entry.startDate, entry.endDate, season, entry.periodType).map((w) => w.monday)),
    [entry, season],
  );
  const weeksNeedAttention =
    null !== existing &&
    (existing.weeks.some((monday) => !emittedMondays.has(monday)) || availableWeeks.some((w) => existing.weeks.includes(w.monday) && !isActionableWeek(w, today)));

  return {
    campaign,
    setCampaign,
    availableWeeks,
    weeks,
    toggleWeek,
    today,
    pickerTeams,
    ineligibleIds,
    tiers,
    teamIds,
    setTeamIds,
    deadline,
    setDeadline,
    canSave,
    saving,
    failed,
    save,
    weeksNeedAttention,
  };
}
