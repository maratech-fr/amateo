import { useEffect, useMemo, useRef, useState } from "react";
import { HTTPError } from "ky";
import { Check, Copy, Send } from "lucide-react";

import type { CalendarEntry } from "@/features/cockpit/api";
import type { Team } from "@/features/wizard/api";
import { addDays, isActionableWeek, periodAdjustWeeks, todayISO } from "@/features/cockpit/lib/date";
import { usePriorityTiers, useUpdateCoach, useWizardTeamCoaches, useWizardTeams } from "@/features/wizard/queries";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { TabPanel, Tabs } from "@/shared/components/ui/tabs";
import { compareTeamsByRank } from "@/shared/lib/teamTiers";
import { Spinner } from "@/shared/components/ui/spinner";
import { copyToClipboard } from "@/shared/lib/clipboard";

import { doleancesLink, type CampaignCoach, type CoachWishCampaign } from "./campaignApi";
import { TeamPicker } from "./TeamPicker";
import { useCreateCoachWishCampaign, useRemindCampaignSilent, useSendCampaignLinks, useUpdateCoachWishCampaign } from "./campaignQueries";

interface CampaignDialogProps {
  /** Entrée MÈRE des vacances à laquelle la campagne s'ancre. */
  entry: CalendarEntry;
  /** Fenêtre de la saison — borne les semaines proposées. */
  season: { startDate: string; endDate: string } | null;
  /** Campagne déjà créée pour cette période, ou null (création). */
  existing: CoachWishCampaign | null;
  onClose: () => void;
}

const frDate = (iso: string): string => {
  const [y, m, d] = iso.split("-");
  return `${d}/${m}/${y}`;
};

/**
 * « Solliciter les coachs » (feature #10, lot C2) : crée/modifie la campagne de collecte
 * d'une période de vacances (semaines, équipes, deadline), puis liste les coachs avec leur
 * lien personnel à copier (WhatsApp — pas d'email en C2). L'email est éditable ici en
 * préparation de C3 (envoi) : aucun effet aujourd'hui.
 */
export function CampaignDialog({ entry, season, existing, onClose }: CampaignDialogProps) {
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

  const toggle = (set: Set<string>, value: string, apply: (next: Set<string>) => void) => {
    const next = new Set(set);
    if (next.has(value)) {
      next.delete(value);
    } else {
      next.add(value);
    }
    apply(next);
  };

  // Le focus suit la bascule programmatique : une ref + un effet (interdits pendant le
  // rendu, autorisés ici) plutôt qu'un état, qu'aucun rendu ne devrait porter.
  const focusTabAfterSwitch = useRef(false);

  const canSave = weeks.size > 0 && teamIds.size > 0 && "" !== deadline;
  const saving = createCampaign.isPending || updateCampaign.isPending;

  const save = () => {
    const body = { calendarEntryId: entry.id, deadline, weeks: [...weeks].sort(), teamIds: [...teamIds] };
    if (null !== campaign) {
      updateCampaign.mutate({ id: campaign.id, body }, { onSuccess: setCampaign });
    } else {
      // Après création, on BASCULE sur les liens : c'est ce que le gestionnaire est venu
      // chercher. Sans ça (revue #346), le bouton changeait de libellé, un onglet
      // apparaissait discrètement, et rien d'autre ne bougeait — on croyait l'échec.
      createCampaign.mutate(body, {
        onSuccess: (created) => {
          setCampaign(created);
          // ⚠ On EMPORTE le focus avec l'onglet (revue #346) : le bouton qui vient d'être
          // pressé se retrouve dans un panneau `hidden`, le navigateur rend alors le focus
          // à `<body>` — et le piège à focus comme Échap, qui écoutent sur le panneau de la
          // modale, cessent d'agir pendant qu'elle reste ouverte.
          setActiveTab("coachs");
          focusTabAfterSwitch.current = true;
        },
      });
    }
  };

  const failed = createCampaign.isError || updateCampaign.isError;

  // Une campagne déjà lancée s'ouvre sur « Coachs » — suivre et envoyer est le geste
  // fréquent. ⚠ SAUF si ses réglages demandent une correction : #344 avait imposé qu'une
  // semaine retenue mais révolue, ou que la période n'émet plus, reste SOUS LES YEUX du
  // gestionnaire. La reléguer derrière un onglet qu'il n'ouvre jamais annulait cette
  // garantie en silence (revue #346) — et ses tests continuaient de passer, `getByLabelText`
  // ne filtrant pas le contenu caché.
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
  const [activeTab, setActiveTab] = useState(null !== existing && !weeksNeedAttention ? "coachs" : "reglages");
  useEffect(() => {
    if (focusTabAfterSwitch.current) {
      focusTabAfterSwitch.current = false;
      document.getElementById(`campaign-tab-${activeTab}`)?.focus();
    }
  });
  const tabs = null === campaign ? [{ id: "reglages", label: "Réglages" }] : [{ id: "reglages", label: "Réglages" }, { id: "coachs", label: `Coachs (${campaign.coaches.length})` }];

  return (
    <Modal
      label="Solliciter les coachs"
      title="Solliciter les coachs"
      onClose={onClose}
      // Pied épinglé pour l'onglet RÉGLAGES seulement — c'est lui qui porte le geste
      // « Créer / Enregistrer ». L'onglet Coachs (suivi des réponses) gère ses propres
      // actions dans son contenu, il n'a pas de pied de modale.
      footer={
        "reglages" === activeTab ? (
          <>
            <Button variant="ghost" size="sm" onClick={onClose}>
              Fermer
            </Button>
            <Button size="sm" disabled={!canSave || saving} onClick={save}>
              {saving ? <Spinner className="size-4" /> : null}
              {null === campaign ? "Créer la collecte" : "Enregistrer"}
            </Button>
          </>
        ) : undefined
      }
    >
      {/* P3-15 — DEUX MOMENTS, DEUX ONGLETS. On règle une fois (semaines, équipes, date
          limite), puis on revient suivre les réponses et envoyer. Tout empiler faisait une
          modale « BEAUCOUP TROP longue, c'est pas jouable » (fondateur).
          L'onglet Coachs n'EXISTE pas tant que rien n'est enregistré — il n'aurait rien à
          montrer — et c'est lui qui s'ouvre à la ré-ouverture d'une campagne : suivre est
          alors le geste fréquent. */}
      <Tabs tabs={tabs} activeTab={activeTab} onTabChange={setActiveTab} ariaLabel="Sections de la collecte" idPrefix="campaign" />

      <TabPanel tabId="reglages" idPrefix="campaign" active={"reglages" === activeTab} className="pt-3">
        <fieldset>
          <legend className="text-sm font-medium">Semaines</legend>
          <div className="mt-1 space-y-1">
            {0 === availableWeeks.length ? (
              <EmptyHint>Aucune semaine disponible sur cette période.</EmptyHint>
            ) : (
              availableWeeks.map((w) => (
                <label key={w.monday} className="flex items-center gap-2 text-sm">
                  {/* Le marqueur vit DANS le nom accessible : `aria-label` écrase le
                      contenu du label, donc un marqueur posé à côté n'est jamais annoncé et
                      un lecteur d'écran entend des semaines indistinctes (revue #344). */}
                  <input
                    type="checkbox"
                    className="size-4 accent-[var(--accent)]"
                    checked={weeks.has(w.monday)}
                    onChange={() => toggle(weeks, w.monday, setWeeks)}
                    aria-label={`Semaine du ${frDate(w.startDate)}${isActionableWeek(w, today) ? "" : " (révolue)"}`}
                  />
                  Semaine du {frDate(w.startDate)} au {frDate(w.endDate)}
                  {isActionableWeek(w, today) ? null : <span className="text-xs italic text-muted-foreground">révolue</span>}
                </label>
              ))
            )}
          </div>
        </fieldset>

        <TeamPicker teams={pickerTeams} ineligibleIds={ineligibleIds} tiers={tiers} selected={teamIds} onChange={setTeamIds} />

        <label className="mt-4 flex items-center gap-2 text-sm font-medium">
          À renvoyer avant le
          <Input type="date" min={today} className="h-8 w-40" value={deadline} onChange={(e) => setDeadline(e.target.value)} aria-label="Date limite" />
        </label>

        {failed ? <p className="mt-3 text-sm text-destructive">Enregistrement impossible. Vérifiez les semaines et équipes choisies.</p> : null}
      </TabPanel>

      {null !== campaign ? (
        <TabPanel tabId="coachs" idPrefix="campaign" active={"coachs" === activeTab} className="pt-3">
          <CoachLinks
            campaign={campaign}
            onEmailSaved={(coachId, email) => setCampaign((c) => (null === c ? c : { ...c, coaches: c.coaches.map((k) => (k.coachId === coachId ? { ...k, email } : k)) }))}
            onCampaignRefreshed={setCampaign}
          />
        </TabPanel>
      ) : null}
    </Modal>
  );
}

/** Jour calendaire Europe/Paris d'une date (YYYY-MM-DD) — le back throttle sur CE fuseau. */
const parisDay = (d: Date): string => new Intl.DateTimeFormat("fr-CA", { timeZone: "Europe/Paris", year: "numeric", month: "2-digit", day: "2-digit" }).format(d);

/** lastReminderAt (ISO) est-il le MÊME jour Europe/Paris que maintenant ? (parité back, D3). */
const isSameParisDay = (iso: string | null): boolean => null !== iso && parisDay(new Date(iso)) === parisDay(new Date());

/** Message d'erreur d'une action d'envoi : 409 = saison close (jamais réessayable), sinon défaut. */
const errorMessage = (error: unknown, fallback: string): string => (error instanceof HTTPError && 409 === error.response.status ? "Cette saison est archivée — la collecte est close." : fallback);

type CoachStatus = "responded" | "pending" | "no-email";

/**
 * Statut mutuellement exclusif d'un coach (D2). « Répondu » PRIME : un coach répond souvent
 * via WhatsApp SANS email (respondedAt posé, email null) — il reste « répondu », jamais rangé
 * en « pas d'email » (sinon le filtre le cacherait alors que le badge le compte répondant).
 */
const coachStatus = (c: CampaignCoach): CoachStatus => (null !== c.respondedAt ? "responded" : null === c.email || "" === c.email ? "no-email" : "pending");

const STATUS_LABELS: { key: CoachStatus; label: string }[] = [
  { key: "responded", label: "Répondu" },
  { key: "pending", label: "En attente" },
  { key: "no-email", label: "Pas d'email" },
];

function CoachLinks({ campaign, onEmailSaved, onCampaignRefreshed }: { campaign: CoachWishCampaign; onEmailSaved: (coachId: string, email: string) => void; onCampaignRefreshed: (c: CoachWishCampaign) => void }) {
  const sendLinks = useSendCampaignLinks();
  const remind = useRemindCampaignSilent();
  const teamsQuery = useWizardTeams();
  const teamCoachesQuery = useWizardTeamCoaches();

  // Filtres (D1/D2/D3) : par ÉQUIPE (celles de la campagne) et par STATUT, additifs dans
  // chaque axe, combinés en ET entre axes. N'affectent QUE la liste, jamais les boutons (D4).
  const [teamFilter, setTeamFilter] = useState<Set<string>>(new Set());
  const [statusFilter, setStatusFilter] = useState<Set<CoachStatus>>(new Set());

  // Équipes de la campagne (id + nom) pour les boutons de filtre.
  const campaignTeamIds = new Set(campaign.teamIds);
  // P3-15 (b) — triées par RANG, comme partout où une équipe se lit. Elles sortaient dans
  // l'ordre brut de l'API : même helper, pas une seconde règle de tri.
  // Tri par RANG, mais avec `compareTeamsByRank` et non `groupTeamsByTier` : ce dernier a
  // besoin des rangs CHARGÉS, et tant qu'ils ne le sont pas il verse tout dans « Autres »
  // puis re-trie à leur arrivée — la rangée se réordonnait sous le pointeur, et un clic
  // filtrait sur une autre équipe que celle visée (revue #346). Le comparateur donne le
  // même ordre dès le premier rendu.
  const campaignTeams = [...(teamsQuery.data ?? []).filter((t) => campaignTeamIds.has(t.id))].sort(compareTeamsByRank);
  // coachId → équipes de la campagne qu'il coache (D1 : TeamCoach ∩ teamIds campagne).
  const coachTeams = new Map<string, Set<string>>();
  for (const tc of teamCoachesQuery.data ?? []) {
    if (campaignTeamIds.has(tc.teamId)) {
      coachTeams.set(tc.coachId, (coachTeams.get(tc.coachId) ?? new Set()).add(tc.teamId));
    }
  }

  const toggle = <T,>(set: Set<T>, value: T, apply: (n: Set<T>) => void) => {
    const next = new Set(set);
    if (next.has(value)) {
      next.delete(value);
    } else {
      next.add(value);
    }
    apply(next);
  };

  const visibleCoaches = campaign.coaches.filter((c) => {
    const okTeam = 0 === teamFilter.size || [...(coachTeams.get(c.coachId) ?? new Set<string>())].some((t) => teamFilter.has(t));
    const okStatus = 0 === statusFilter.size || statusFilter.has(coachStatus(c));
    return okTeam && okStatus;
  });

  // Envoi global : les coachs à email PAS ENCORE servis (le bouton n'est pas un renvoi — D2).
  const unsentWithEmail = campaign.coaches.filter((c) => null !== c.email && "" !== c.email && null === c.sentAt);
  // Relance : silencieux à email — et une seule fois par jour (D3).
  const silentWithEmail = campaign.coaches.filter((c) => null !== c.email && "" !== c.email && null === c.respondedAt);
  const remindedToday = isSameParisDay(campaign.lastReminderAt);

  return (
    <div className="mt-5 border-t border-border pt-4">
      <p className="text-sm font-medium">
        Liens des coachs · {campaign.respondedCoachCount}/{campaign.totalCoachCount} ont répondu
      </p>
      <div className="mt-2 flex flex-wrap gap-2">
        <Button
          variant="outline"
          size="sm"
          disabled={0 === unsentWithEmail.length || sendLinks.isPending}
          title={0 === unsentWithEmail.length ? "Tous les coachs avec un email ont déjà reçu leur lien" : undefined}
          onClick={() => sendLinks.mutate({ id: campaign.id }, { onSuccess: (r) => onCampaignRefreshed(r.campaign) })}
        >
          {sendLinks.isPending ? <Spinner className="size-4" /> : <Send className="size-4" />}
          Envoyer les liens par email
        </Button>
        <Button
          variant="ghost"
          size="sm"
          disabled={remindedToday || 0 === silentWithEmail.length || remind.isPending}
          title={remindedToday ? "Déjà relancés aujourd'hui — pas deux fois le même jour" : 0 === silentWithEmail.length ? "Aucun coach silencieux avec un email" : undefined}
          onClick={() => remind.mutate(campaign.id, { onSuccess: (r) => onCampaignRefreshed(r.campaign) })}
        >
          {remind.isPending ? <Spinner className="size-4" /> : null}
          Relancer les silencieux
        </Button>
      </div>
      {/* Sans ceci un 422/409 resterait muet ; on distingue « saison close » (409) de « déjà
          relancé aujourd'hui » (422 — cache périmé, autre onglet) pour ne pas mal expliquer. */}
      {sendLinks.isError ? <p className="mt-2 text-sm text-destructive">{errorMessage(sendLinks.error, "Envoi impossible pour le moment. Réessayez.")}</p> : null}
      {remind.isError ? <p className="mt-2 text-sm text-destructive">{errorMessage(remind.error, "Relance impossible — les coachs ont peut-être déjà été relancés aujourd'hui.")}</p> : null}

      {/* Filtres — n'affectent QUE la liste ci-dessous (les boutons gardent leur périmètre, D4). */}
      {campaign.coaches.length > 0 ? (
        <div className="mt-3 space-y-1.5">
          {campaignTeams.length > 1 ? (
            <div className="flex flex-wrap items-center gap-1.5">
              <span className="text-xs text-muted-foreground">Équipe&nbsp;:</span>
              {campaignTeams.map((t) => (
                <button
                  key={t.id}
                  type="button"
                  aria-pressed={teamFilter.has(t.id)}
                  className={`rounded-full border px-2 py-0.5 text-xs ${teamFilter.has(t.id) ? "border-accent bg-accent/15 text-foreground" : "border-border text-muted-foreground"}`}
                  onClick={() => toggle(teamFilter, t.id, setTeamFilter)}
                >
                  {t.name}
                </button>
              ))}
            </div>
          ) : null}
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="text-xs text-muted-foreground">Statut&nbsp;:</span>
            {STATUS_LABELS.map((s) => (
              <button
                key={s.key}
                type="button"
                aria-pressed={statusFilter.has(s.key)}
                className={`rounded-full border px-2 py-0.5 text-xs ${statusFilter.has(s.key) ? "border-accent bg-accent/15 text-foreground" : "border-border text-muted-foreground"}`}
                onClick={() => toggle(statusFilter, s.key, setStatusFilter)}
              >
                {s.label}
              </button>
            ))}
          </div>
        </div>
      ) : null}

      {0 === campaign.coaches.length ? (
        <EmptyHint className="mt-1">Aucun coach sur le périmètre choisi.</EmptyHint>
      ) : 0 === visibleCoaches.length ? (
        <EmptyHint className="mt-2">Aucun coach pour ce filtre.</EmptyHint>
      ) : (
        <ul className="mt-2 space-y-2">
          {visibleCoaches.map((coach) => (
            <CoachRow key={coach.coachId} coach={coach} campaignId={campaign.id} onEmailSaved={onEmailSaved} onCampaignRefreshed={onCampaignRefreshed} />
          ))}
        </ul>
      )}
    </div>
  );
}

function CoachRow({ coach, campaignId, onEmailSaved, onCampaignRefreshed }: { coach: CampaignCoach; campaignId: string; onEmailSaved: (coachId: string, email: string) => void; onCampaignRefreshed: (c: CoachWishCampaign) => void }) {
  const [copied, setCopied] = useState(false);
  const [email, setEmail] = useState(coach.email ?? "");
  const updateCoach = useUpdateCoach();
  const sendLinks = useSendCampaignLinks();

  const copy = async () => {
    if (await copyToClipboard(doleancesLink(coach.token))) {
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    }
  };

  const saveEmail = () => {
    const trimmed = email.trim();
    if ("" === trimmed || trimmed === (coach.email ?? "")) {
      return;
    }
    updateCoach.mutate({ id: coach.coachId, body: { firstName: coach.firstName, lastName: coach.lastName, email: trimmed } }, { onSuccess: () => onEmailSaved(coach.coachId, trimmed) });
  };

  const hasEmail = null !== coach.email && "" !== coach.email;

  return (
    <li className="rounded-md border border-border p-2">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium">
          {coach.firstName} {coach.lastName}
        </span>
        {null !== coach.respondedAt ? (
          <StatusPill variant="accent" icon={<Check className="size-3 text-accent" aria-hidden="true" />}>
            répondu le {frDate(coach.respondedAt.slice(0, 10))}
          </StatusPill>
        ) : null}
        {hasEmail ? null : <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">pas d'email</span>}
        {null !== coach.sentAt ? <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">envoyé le {frDate(coach.sentAt.slice(0, 10))}</span> : null}
        {hasEmail ? (
          // Envoi CIBLÉ (ajout tardif d'un email, ou renvoi volontaire à CE coach — D1).
          <Button variant="ghost" size="sm" disabled={sendLinks.isPending} onClick={() => sendLinks.mutate({ id: campaignId, coachIds: [coach.coachId] }, { onSuccess: (r) => onCampaignRefreshed(r.campaign) })}>
            <Send className="size-4" />
            {null === coach.sentAt ? "Envoyer" : "Renvoyer"}
          </Button>
        ) : null}
        <Button variant="outline" size="sm" className="ml-auto" onClick={copy}>
          {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
          {copied ? "Copié" : "Copier le lien"}
        </Button>
      </div>
      <div className="mt-1.5 flex items-center gap-2">
        <Input type="email" placeholder="email (pour l'envoi du lien)" className="h-8 flex-1 text-xs" value={email} onChange={(e) => setEmail(e.target.value)} onBlur={saveEmail} aria-label={`Email de ${coach.firstName} ${coach.lastName}`} />
      </div>
    </li>
  );
}
