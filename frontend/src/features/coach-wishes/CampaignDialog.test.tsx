import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry } from "@/features/cockpit/api";
import { setTodayOverride } from "@/shared/lib/clock";

import type { CoachWishCampaign } from "./campaignApi";

const teamsLate = { value: false };
const teamsFetching = { value: false };
const teamCoachesUnread = { value: false };
vi.mock("@/features/wizard/queries", () => ({
  // Rangs posés : t3/U11 en fanion (S) mais SANS coach — elle ne doit apparaître nulle
  // part ; t1/SM1 et t2/U13 en rang B, dans cet ordre de `tierOrder`.
  // `isFetching` compte : la graine attend une lecture POSÉE, pas un cache périmé servi
  // pendant que le refetch tourne (revue #346 round 2).
  useWizardTeams: () => ({
    isFetching: teamsFetching.value,
    data: teamsLate.value
      ? []
      : [
          { id: "t1", name: "SM1", isActive: true, priorityTierId: 3, tierOrder: 0 },
          { id: "t2", name: "U13", isActive: true, priorityTierId: 3, tierOrder: 1 },
          { id: "t3", name: "U11", isActive: true, priorityTierId: 1, tierOrder: 0 },
        ],
  }),
  usePriorityTiers: () => ({ data: [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 3, label: "B", name: "Moyenne", color: null }] }),
  useWizardTeamCoaches: () => ({ data: teamCoachesUnread.value ? undefined : [{ id: "tc1", teamId: "t1", coachId: "c1", role: "MAIN" }, { id: "tc2", teamId: "t2", coachId: "c2", role: "MAIN" }] }),
  useUpdateCoach: () => ({ mutate: vi.fn() }),
}));

const createMut = vi.fn();
const updateMut = vi.fn();
const sendMut = vi.fn();
const remindMut = vi.fn();
const sendState: { isError: boolean; error: unknown } = { isError: false, error: null };
const remindState: { isError: boolean; error: unknown } = { isError: false, error: null };
vi.mock("./campaignQueries", () => ({
  useCreateCoachWishCampaign: () => ({ mutate: createMut, isPending: false, isError: false }),
  useUpdateCoachWishCampaign: () => ({ mutate: updateMut, isPending: false, isError: false }),
  useSendCampaignLinks: () => ({ mutate: sendMut, isPending: false, isError: sendState.isError, error: sendState.error }),
  useRemindCampaignSilent: () => ({ mutate: remindMut, isPending: false, isError: remindState.isError, error: remindState.error }),
}));

const copyMock = vi.fn().mockResolvedValue(true);
vi.mock("@/shared/lib/clipboard", () => ({ copyToClipboard: (t: string) => copyMock(t) }));

import { CampaignDialog } from "./CampaignDialog";

const entry: CalendarEntry = {
  id: "e1",
  kind: "period",
  title: "Vacances de février",
  startDate: "2026-02-16",
  endDate: "2026-03-01",
  isDisruptive: false,
  periodType: "holiday",
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
  redatable: false, redateNeedsPreview: false,
};
const season = { startDate: "2025-09-01", endDate: "2026-06-30" };

describe("CampaignDialog", () => {
  // P3-13/P3-15 (c) — la campagne ne propose que les semaines À VENIR. Ces fixtures sont
  // datées (fév. 2026) : sans horloge pilotable, elles deviennent du passé au fil du temps
  // et le test se met à échouer tout seul — il l'était déjà devenu. On ancre « aujourd'hui »
  // deux semaines avant la période plutôt que de repasser les dates en relatif.
  beforeEach(() => {
    setTodayOverride("2026-02-01");
    teamsLate.value = false;
    teamsFetching.value = false;
    teamCoachesUnread.value = false;
    createMut.mockReset();
    updateMut.mockReset();
    sendMut.mockReset();
    remindMut.mockReset();
    copyMock.mockClear();
    sendState.isError = false;
    sendState.error = null;
    remindState.isError = false;
    remindState.error = null;
  });
  afterEach(() => setTodayOverride(null));

  it("crée une campagne avec les semaines et équipes choisies", async () => {
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));

    expect(createMut).toHaveBeenCalledTimes(1);
    const body = createMut.mock.calls[0][0];
    expect(body.calendarEntryId).toBe("e1");
    expect(body.weeks.length).toBeGreaterThan(0);
    expect(body.deadline).toBe("2026-02-16");
  });

  // ── P3-15 (a)(b) : une modale qu'on peut lire (retour terrain 2026-07-31) ──

  // ⚠ L'assertion porte sur le CONTENU envoyé, pas sur `canSave` : une sélection vide
  // laisserait `canSave` faux, mais un test qui ne regarderait que le bouton passerait
  // aussi bien avec un défaut cassé.
  it("démarre une nouvelle collecte avec TOUTES les équipes ayant un coach", async () => {
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    // Le résumé le dit d'une ligne, sans rien déplier.
    expect(screen.getByText(/Toutes les équipes \(2\)/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));
    // t3/U11 n'a pas de coach : elle n'est ni comptée ni envoyée.
    expect(createMut.mock.calls[0][0].teamIds.sort()).toEqual(["t1", "t2"]);
  });

  // Le cœur du besoin : 49 équipes ne s'empilent plus, elles se replient derrière une ligne.
  it("garde le sélecteur d'équipes replié, et le déplie à la demande", async () => {
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    const toggle = screen.getByRole("button", { name: /Modifier les équipes/ });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByRole("button", { name: "SM1" })).toBeNull();

    await userEvent.click(toggle);
    expect(screen.getByRole("button", { name: "SM1" })).toHaveAttribute("aria-pressed", "true");
  });

  // Agir en masse : c'est ce qui manquait le plus avec une ligne par équipe.
  it("permet de tout décocher puis de tout recocher d'un geste", async () => {
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("button", { name: /Modifier les équipes/ }));
    await userEvent.click(screen.getByRole("button", { name: "tout décocher" }));
    expect(screen.getByText(/0 équipe sur 2/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Créer la collecte/ })).toBeDisabled();

    await userEvent.click(screen.getByRole("button", { name: "tout cocher" }));
    expect(screen.getByText(/Toutes les équipes \(2\)/)).toBeInTheDocument();
  });

  // (b) Les équipes sont groupées par RANG, comme partout où une équipe se choisit.
  it("groupe les équipes du sélecteur par rang", async () => {
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("button", { name: /Modifier les équipes/ }));
    expect(screen.getByText("B · Moyenne")).toBeInTheDocument();
    // U11 (rang S) n'a pas de coach : son groupe n'existe pas non plus.
    expect(screen.queryByText("S · Fanion")).toBeNull();
  });

  // Une campagne EXISTANTE rouvre sur SA sélection — jamais sur « toutes », ce qui
  // élargirait la collecte en silence à des équipes que le gestionnaire avait écartées.
  it("rouvre une campagne existante sur sa propre sélection, pas sur « toutes »", () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    expect(screen.getByText(/1 équipe sur 2/)).toBeInTheDocument();
  });

  // Deux moments, deux onglets — et rien à montrer tant que rien n'est enregistré.
  it("n'offre l'onglet Coachs qu'une fois la collecte créée, et s'y ouvre à la ré-ouverture", () => {
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);
    expect(screen.queryByRole("tab", { name: /Coachs/ })).toBeNull();

    cleanup();
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: null, token: "a".repeat(64), respondedAt: null, sentAt: null }],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);
    expect(screen.getByRole("tab", { name: /Coachs/ })).toHaveAttribute("aria-selected", "true");
  });

  // P4-178 — repli AA : la pastille « répondu le … » (StatusPill accent) et le filtre de statut
  // actif gardent leur texte en `text-foreground`, jamais `text-accent` (sous 4,5:1 sur fond teinté).
  it("pastille « répondu le » et filtre actif : pas de `text-accent` sur le texte lisible", async () => {
    const user = userEvent.setup();
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 1,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: "m@x.fr", token: "a".repeat(64), respondedAt: "2026-02-20T10:00:00+00:00", sentAt: "2026-02-18T09:00:00+00:00" }],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    const responded = screen.getByText(/répondu le/);
    expect(responded).toBeInTheDocument();
    expect(responded).not.toHaveClass("text-accent");

    // Le bouton de filtre statut, une fois ACTIF, garde son texte lisible (text-foreground).
    const statusBtn = screen.getByRole("button", { name: "Répondu" });
    expect(statusBtn).toHaveAttribute("aria-pressed", "false");
    await user.click(statusBtn);
    expect(statusBtn).toHaveAttribute("aria-pressed", "true");
    expect(statusBtn).not.toHaveClass("text-accent");
  });

  // ── P3-15 (c) : on ne sollicite un coach que pour ce qu'il RESTE (retour 2026-07-31) ──

  // « Les semaines passées et la semaine en cours sont proposées ET cochées par défaut » :
  // le gestionnaire envoyait à ses coachs un lien pour dire leurs souhaits sur du révolu.
  // ⚠ RÉVOLU, pas « entamé » (revue #344) : une vacance qui démarre un samedi n'aurait
  // plus pu faire l'objet d'aucune collecte dès le lundi suivant, pour des séances
  // pourtant toutes à venir — et rien d'autre dans l'app ne crée une campagne.
  it("n'offre ni ne coche une semaine révolue, garde celle qui est entamée", () => {
    // Période du 16/02 au 01/03 = deux semaines (16 et 23). « Aujourd'hui » = lundi 23 :
    // la semaine du 16 est finie, celle du 23 court encore.
    setTodayOverride("2026-02-23");
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    expect(screen.queryByLabelText(/Semaine du 16/)).toBeNull();
    const current = screen.getByLabelText(/Semaine du 23/);
    expect(current).toBeInTheDocument();
    expect(current).toBeChecked();
  });

  // Le cas qui rendait la collecte IMPOSSIBLE avec le premier critère : la période
  // n'a plus qu'une semaine, entamée, mais ses jours utiles sont devant.
  it("laisse créer une collecte sur une semaine entamée aux jours encore à venir", () => {
    setTodayOverride("2026-02-25"); // mercredi de la dernière semaine, qui finit le 01/03
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    expect(screen.queryByText(/Aucune semaine disponible/)).toBeNull();
    expect(screen.getByLabelText(/Semaine du 23/)).toBeChecked();
  });

  // ATTEINDRE ≠ CHOISIR : une campagne EXISTANTE peut porter une semaine devenue révolue.
  // La masquer laisserait l'état porter un lundi que l'écran ne montre pas et que
  // l'enregistrement renverrait — un état invisible est un état faux. Le marqueur vit
  // DANS le nom accessible, sinon un lecteur d'écran ne l'entend jamais (revue #344).
  it("garde visible et marquée une semaine déjà retenue devenue révolue", () => {
    setTodayOverride("2026-02-25");
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2026-03-01",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    const past = screen.getByLabelText("Semaine du 16/02/2026 (révolue)");
    expect(past).toBeInTheDocument();
    expect(past).toBeChecked();
  });

  // ── Revue #344 round 2 ──

  // La date limite par défaut valait `entry.startDate`, donc DANS LE PASSÉ dès que la
  // période a commencé — le cas que ce lot vient de rendre légitime. Les liens partaient
  // morts (410 « deadline dépassée ») et rien ne le disait au gestionnaire.
  it("ne propose jamais une date limite déjà passée", () => {
    setTodayOverride("2026-02-25"); // la période a commencé le 16
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    const deadline = screen.getByLabelText("Date limite") as HTMLInputElement;
    expect(deadline.value).toBe("2026-02-25");
    expect(deadline.min).toBe("2026-02-25");
  });

  // Une semaine retenue par la campagne peut ne PLUS être émise (période redimensionnée,
  // saison déplacée). La filtrer la laissait invisible tout en la gardant dans l'état, que
  // l'enregistrement renvoyait : on sollicite pour une semaine jamais montrée.
  it("montre une semaine retenue que la période n'émet plus", () => {
    setTodayOverride("2026-02-01");
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2026-03-01",
      weeks: ["2026-02-02"], // hors de la période 16/02 → 01/03 : plus émise du tout
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    const orphan = screen.getByLabelText(/Semaine du 02\/02\/2026/);
    expect(orphan).toBeInTheDocument();
    expect(orphan).toBeChecked();
  });

  // ── Revue #346 : ce que mes propres choix avaient défait ──

  // Une campagne existante peut porter une équipe qui a perdu son coach. Ne rendre que les
  // ÉLIGIBLES la laissait invisible : « tout décocher » restait sans effet sur elle, le
  // résumé annonçait « 0 » et l'enregistrement la postait quand même.
  it("montre, marque et décoche une équipe sélectionnée qui n'a plus de coach", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-23"],
      teamIds: ["t1", "t3"], // t3/U11 n'a AUCUN coach : inéligible, mais retenue
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("tab", { name: /Réglages/ }));
    await userEvent.click(screen.getByRole("button", { name: /Modifier les équipes/ }));
    expect(screen.getByRole("button", { name: /U11 \(ne peut plus être sollicitée\)/ })).toHaveAttribute("aria-pressed", "true");

    await userEvent.click(screen.getByRole("button", { name: "tout décocher" }));
    // Le résumé et l'enregistrement disent la MÊME chose : plus rien n'est sélectionné.
    expect(screen.getByRole("button", { name: /Enregistrer/ })).toBeDisabled();
  });

  // Après création, le gestionnaire vient chercher les liens : les laisser dans un panneau
  // caché faisait croire à un échec (le bouton changeait de libellé, rien d'autre ne bougeait).
  it("bascule sur l'onglet Coachs après la création", async () => {
    createMut.mockImplementation((_body: unknown, opts: { onSuccess: (c: CoachWishCampaign) => void }) =>
      opts.onSuccess({
        id: "camp1",
        calendarEntryId: "e1",
        deadline: "2026-03-01",
        weeks: ["2026-02-23"],
        teamIds: ["t1"],
        totalCoachCount: 1,
        respondedCoachCount: 0,
        openWishCount: 0,
        lastReminderAt: null,
        coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: null, token: "a".repeat(64), respondedAt: null, sentAt: null }],
      }),
    );
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));
    expect(screen.getByRole("tab", { name: /Coachs/ })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByRole("button", { name: /Copier le lien/ })).toBeVisible();
  });

  // #344 exigeait qu'une semaine retenue mais révolue reste SOUS LES YEUX. Mon onglet par
  // défaut la reléguait derrière un clic que le chemin fréquent ne déclenche jamais.
  it("ouvre sur Réglages quand une semaine retenue demande une correction", () => {
    setTodayOverride("2026-02-25"); // la semaine du 23 court, celle du 16 est révolue
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2026-03-01",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    expect(screen.getByRole("tab", { name: /Réglages/ })).toHaveAttribute("aria-selected", "true");
    // VISIBLE, pas seulement présent : `getByLabelText` ne filtre pas le contenu caché, ce
    // qui laissait les deux gardes de #344 passer dans un panneau `hidden`.
    expect(screen.getByLabelText(/Semaine du 16\/02\/2026 \(révolue\)/)).toBeVisible();
  });

  // Le défaut « toutes les équipes » n'était gardé par RIEN : les mocks rendent la donnée
  // dès le premier rendu, donc la condition même pour laquelle il existe — des équipes qui
  // arrivent APRÈS — n'était jamais simulée (revue #346, prouvé par falsification).
  it("coche toutes les équipes même quand elles arrivent après le premier rendu", async () => {
    teamsLate.value = true;
    const { rerender } = render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);
    expect(screen.getByText(/Aucune équipe avec un coach rattaché/)).toBeInTheDocument();

    teamsLate.value = false;
    teamsFetching.value = false;
    teamCoachesUnread.value = false;
    rerender(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);
    expect(screen.getByText(/Toutes les équipes \(2\)/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));
    expect(createMut.mock.calls[0][0].teamIds.sort()).toEqual(["t1", "t2"]);
  });

  // ── Revue #346 round 2 : ce que mes correctifs du round 1 avaient laissé passer ──

  // Un cache chaud mais PÉRIMÉ est servi immédiatement pendant que le refetch tourne :
  // semer là-dessus verrouillait une liste incomplète, annoncée comme « toutes ».
  it("attend que la lecture soit POSÉE avant de semer, pas seulement non vide", async () => {
    teamsFetching.value = true; // cache périmé servi, refetch en cours
    const { rerender } = render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);
    // Rien n'est semé tant que la lecture n'est pas posée : le résumé dit la vérité
    // (« 0 sur 2 ») plutôt que d'annoncer « toutes » sur une liste peut-être incomplète.
    expect(screen.getByText(/0 équipe sur 2/)).toBeInTheDocument();

    teamsFetching.value = false; // le refetch a répondu : liste complète
    rerender(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);
    expect(screen.getByText(/Toutes les équipes \(2\)/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));
    expect(createMut.mock.calls[0][0].teamIds.sort()).toEqual(["t1", "t2"]);
  });

  // On n'ACCUSE que sur une donnée lue : tant que les liens coachs n'ont pas répondu,
  // toutes les équipes s'affichaient « ne peut plus être sollicitée ».
  it("n'accuse aucune équipe tant que les liens coachs ne sont pas lus", async () => {
    teamCoachesUnread.value = true;
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-23"],
      teamIds: ["t1", "t2"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("tab", { name: /Réglages/ }));
    await userEvent.click(screen.getByRole("button", { name: /Modifier les équipes/ }));
    expect(screen.queryByRole("button", { name: /ne peut plus être sollicitée/ })).toBeNull();
  });

  // Une équipe SUPPRIMÉE n'est dans aucune requête : sans un libellé de repli, elle restait
  // dans la sélection, invisible, indécochable, et partait quand même au POST.
  it("rend atteignable une équipe supprimée encore portée par la campagne", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-23"],
      teamIds: ["t1", "tSupprimee"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("tab", { name: /Réglages/ }));
    await userEvent.click(screen.getByRole("button", { name: /Modifier les équipes/ }));
    expect(screen.getByRole("button", { name: /Équipe supprimée/ })).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "tout décocher" }));
    // Le bouton et la sélection disent la MÊME chose : plus rien ne partira.
    expect(screen.getByRole("button", { name: /Enregistrer/ })).toBeDisabled();
  });

  // Le résumé compte ce qui produira un lien : compter les inéligibles des deux côtés
  // annonçait « Toutes les équipes (3) » quand deux seulement seraient sollicitées.
  it("ne compte dans le résumé que les équipes qui produiront un lien", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-23"],
      teamIds: ["t1", "t2", "t3"], // t3/U11 n'a pas de coach
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("tab", { name: /Réglages/ }));
    expect(screen.getByText(/Toutes les équipes \(2\) · 1 sans coach, à retirer/)).toBeInTheDocument();
  });

  // #344 visait la semaine que la période n'ÉMET PLUS ; mon premier jet ne rattrapait que
  // la semaine révolue, et une orpheline encore future ouvrait donc sur l'onglet Coachs.
  it("ouvre sur Réglages quand une semaine retenue n'est plus émise par la période", () => {
    setTodayOverride("2026-02-01");
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2026-03-01",
      weeks: ["2026-03-09"], // hors de la période 16/02 → 01/03, et encore future
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    expect(screen.getByRole("tab", { name: /Réglages/ })).toHaveAttribute("aria-selected", "true");
  });

  // Le focus suit la bascule : sans ça il retombe sur `<body>`, et le piège à focus comme
  // Échap — qui écoutent sur le panneau de la modale — cessent d'agir.
  it("emporte le focus avec l'onglet après la création", async () => {
    createMut.mockImplementation((_body: unknown, opts: { onSuccess: (c: CoachWishCampaign) => void }) =>
      opts.onSuccess({
        id: "camp1",
        calendarEntryId: "e1",
        deadline: "2026-03-01",
        weeks: ["2026-02-23"],
        teamIds: ["t1"],
        totalCoachCount: 1,
        respondedCoachCount: 0,
        openWishCount: 0,
        lastReminderAt: null,
        coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: null, token: "a".repeat(64), respondedAt: null, sentAt: null }],
      }),
    );
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));
    expect(document.activeElement).toBe(screen.getByRole("tab", { name: /Coachs/ }));
  });

  it("copie le lien personnel d'un coach", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: null, token: "a".repeat(64), respondedAt: null, sentAt: null }],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("button", { name: /Copier le lien/ }));
    expect(copyMock).toHaveBeenCalledWith(`${window.location.origin}/doleances/${"a".repeat(64)}`);
  });

  it("envoie les liens aux coachs à email pas encore servis (D2)", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 2,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [
        { coachId: "c1", firstName: "Maxime", lastName: "Durand", email: "max@test.fr", token: "a".repeat(64), respondedAt: null, sentAt: null },
        { coachId: "c2", firstName: "Mara", lastName: "Petit", email: null, token: "b".repeat(64), respondedAt: null, sentAt: null },
      ],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    // Le coach sans email porte le badge et pas de bouton d'envoi individuel.
    expect(screen.getByText("pas d'email")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Envoyer les liens par email/ }));
    expect(sendMut).toHaveBeenCalledTimes(1);
    expect(sendMut.mock.calls[0][0]).toEqual({ id: "camp1" });
  });

  // P4-150 — une campagne rouverte s'ouvre sur l'onglet Coachs ; sans aucun coach au
  // périmètre, la copie d'écran de l'état vide est assertée.
  it("annonce « Aucun coach sur le périmètre choisi. » quand la campagne ne porte aucun coach", () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 0,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);
    expect(screen.getByText("Aucun coach sur le périmètre choisi.")).toBeInTheDocument();
  });

  it("filtre la liste des coachs par équipe et par statut (D1-D5)", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1", "t2"],
      totalCoachCount: 2,
      respondedCoachCount: 1,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [
        { coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr", token: "a".repeat(64), respondedAt: "2026-02-01T10:00:00Z", sentAt: null },
        { coachId: "c2", firstName: "Mara", lastName: "U13", email: null, token: "b".repeat(64), respondedAt: null, sentAt: null },
      ],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    // Sans filtre : les deux coachs.
    expect(screen.getByText("Maxime SM1")).toBeInTheDocument();
    expect(screen.getByText("Mara U13")).toBeInTheDocument();

    // Filtre équipe SM1 → Mara (U13) disparaît.
    await userEvent.click(screen.getByRole("button", { name: "SM1", pressed: false }));
    expect(screen.getByText("Maxime SM1")).toBeInTheDocument();
    expect(screen.queryByText("Mara U13")).not.toBeInTheDocument();

    // On enlève le filtre équipe, on filtre par statut « pas d'email » → seul Mara.
    await userEvent.click(screen.getByRole("button", { name: "SM1", pressed: true }));
    await userEvent.click(screen.getByRole("button", { name: "Pas d'email" }));
    expect(screen.getByText("Mara U13")).toBeInTheDocument();
    expect(screen.queryByText("Maxime SM1")).not.toBeInTheDocument();

    // P4-150 — équipe SM1 (Maxime, qui A un email) + statut « Pas d'email » → aucun coach
    // visible : la copie d'écran de l'état vide filtré est assertée.
    await userEvent.click(screen.getByRole("button", { name: "SM1", pressed: false }));
    expect(screen.getByText("Aucun coach pour ce filtre.")).toBeInTheDocument();
  });

  it("classe un répondant SANS email en « Répondu », pas « Pas d'email » (WhatsApp)", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1", "t2"],
      totalCoachCount: 2,
      respondedCoachCount: 1,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [
        { coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr", token: "a".repeat(64), respondedAt: null, sentAt: null },
        // Répond via WhatsApp, aucun email en fiche : doit rester « Répondu ».
        { coachId: "c2", firstName: "Wanda", lastName: "U13", email: null, token: "b".repeat(64), respondedAt: "2026-02-01T10:00:00Z", sentAt: null },
      ],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("button", { name: "Répondu" }));
    expect(screen.getByText("Wanda U13")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Répondu", pressed: true }));
    await userEvent.click(screen.getByRole("button", { name: "Pas d'email" }));
    expect(screen.queryByText("Wanda U13")).not.toBeInTheDocument();
  });

  it("affiche « saison archivée » sur un 409, pas « déjà relancé »", () => {
    remindState.isError = true;
    remindState.error = new HTTPError(new Response(null, { status: 409 }), new Request("http://x/api/coach_wish_campaigns/camp1/remind"), {} as never);
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr", token: "a".repeat(64), respondedAt: null, sentAt: "2026-01-01T08:00:00Z" }],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);
    expect(screen.getByText(/saison est archivée/)).toBeInTheDocument();
  });

  it("affiche une erreur si la relance échoue (feedback, pas muet)", () => {
    remindState.isError = true;
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr", token: "a".repeat(64), respondedAt: null, sentAt: "2026-01-01T08:00:00Z" }],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    expect(screen.getByText(/Relance impossible/)).toBeInTheDocument();
  });

  it("bloque la relance si déjà relancé aujourd'hui (D3)", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: new Date().toISOString(),
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: "max@test.fr", token: "a".repeat(64), respondedAt: null, sentAt: "2026-01-01T08:00:00Z" }],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    expect(screen.getByRole("button", { name: /Relancer les silencieux/ })).toBeDisabled();
  });
});
