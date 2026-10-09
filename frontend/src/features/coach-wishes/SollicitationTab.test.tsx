import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride } from "@/shared/lib/clock";

import type { CampaignCoach, CoachWishCampaign } from "./campaignApi";

// Équipes/coachs de la campagne pour le sélecteur d'équipe (ResourceFilter) et l'envoi d'email.
vi.mock("@/features/wizard/queries", () => ({
  useWizardTeams: () => ({
    data: [
      { id: "t1", name: "SM1", isActive: true, priorityTierId: 3, tierOrder: 0 },
      { id: "t2", name: "U13", isActive: true, priorityTierId: 3, tierOrder: 1 },
      { id: "t3", name: "U11", isActive: true, priorityTierId: 1, tierOrder: 0 },
    ],
  }),
  useWizardTeamCoaches: () => ({
    data: [
      { id: "tc1", teamId: "t1", coachId: "c1", role: "MAIN" },
      { id: "tc2", teamId: "t2", coachId: "c2", role: "MAIN" },
    ],
  }),
  usePriorityTiers: () => ({ data: [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 3, label: "B", name: "Moyenne", color: null }] }),
  useUpdateCoach: () => ({ mutate: vi.fn() }),
}));

const sendMut = vi.fn();
const remindMut = vi.fn();
const refetchPreview = vi.fn();
const sendState: { isError: boolean; error: unknown } = { isError: false, error: null };
const remindState: { isError: boolean; error: unknown } = { isError: false, error: null };
const previewState: { data: { subject: string; from: string; html: string } | undefined; isError: boolean } = { data: undefined, isError: false };
vi.mock("./campaignQueries", () => ({
  useSendCampaignLinks: () => ({ mutate: sendMut, isPending: false, isError: sendState.isError, error: sendState.error }),
  useRemindCampaignSilent: () => ({ mutate: remindMut, isPending: false, isError: remindState.isError, error: remindState.error }),
  useCoachWishEmailPreview: () => ({ data: previewState.data, isError: previewState.isError, refetch: refetchPreview }),
}));

const copyMock = vi.fn().mockResolvedValue(true);
vi.mock("@/shared/lib/clipboard", () => ({ copyToClipboard: (t: string) => copyMock(t) }));

import { SollicitationTab } from "./SollicitationTab";

const coach = (over: Partial<CampaignCoach> = {}): CampaignCoach => ({
  coachId: "c1",
  firstName: "Maxime",
  lastName: "Durand",
  email: null,
  token: "a".repeat(64),
  respondedAt: null,
  sentAt: null,
  ...over,
});

const campaign = (over: Partial<CoachWishCampaign> = {}): CoachWishCampaign => ({
  id: "camp1",
  calendarEntryId: "e1",
  deadline: "2027-06-30",
  weeks: ["2026-02-16"],
  teamIds: ["t1"],
  totalCoachCount: 1,
  respondedCoachCount: 0,
  openWishCount: 0,
  lastReminderAt: null,
  coaches: [coach()],
  ...over,
});

const renderTab = (c: CoachWishCampaign) => render(<SollicitationTab campaign={c} onEmailSaved={vi.fn()} onCampaignRefreshed={vi.fn()} />);
const inList = (name: string) => within(screen.getByRole("list", { name: "Coachs sollicités" })).queryByText(name);

describe("SollicitationTab", () => {
  beforeEach(() => {
    setTodayOverride("2026-02-01");
    sendMut.mockReset();
    remindMut.mockReset();
    copyMock.mockClear();
    sendState.isError = false;
    sendState.error = null;
    remindState.isError = false;
    remindState.error = null;
    previewState.data = undefined;
    previewState.isError = false;
    refetchPreview.mockReset();
  });
  afterEach(() => {
    setTodayOverride(null);
    vi.unstubAllGlobals();
  });

  // Décision fondateur 2026-10-09 (7) : le filtre statut démarre sur « En attente ».
  it("démarre avec le filtre statut « En attente »", () => {
    renderTab(
      campaign({
        totalCoachCount: 2,
        teamIds: ["t1", "t2"],
        coaches: [
          coach({ coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr" }), // en attente
          coach({ coachId: "c2", firstName: "Wanda", lastName: "U13", email: null, respondedAt: "2026-02-01T10:00:00Z" }), // répondue
        ],
      }),
    );
    // La puce « En attente » est pressée d'emblée ; la répondante est donc masquée.
    expect(screen.getByRole("button", { name: "En attente" })).toHaveAttribute("aria-pressed", "true");
    expect(inList("Maxime SM1")).toBeInTheDocument();
    expect(inList("Wanda U13")).not.toBeInTheDocument();
  });

  // Bug #1137 (décision fondateur 2026-10-09) : « En attente » = tout coach SANS réponse, qu'il
  // ait un email ou non — « ce n'est pas parce que tu n'as pas d'email que tu n'as pas répondu ».
  it("« En attente » liste tout coach sans réponse, avec OU sans email (#1137)", () => {
    renderTab(
      campaign({
        totalCoachCount: 2,
        teamIds: ["t1", "t2"],
        coaches: [
          coach({ coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr" }), // email, sans réponse
          coach({ coachId: "c2", firstName: "Mara", lastName: "U13", email: null }), // SANS email, sans réponse
        ],
      }),
    );
    // Défaut « En attente » : les deux sont là (aucun n'a répondu), email ou pas.
    expect(inList("Maxime SM1")).toBeInTheDocument();
    expect(inList("Mara U13")).toBeInTheDocument();
    // La marque « pas d'email » reste visible sur la ligne du coach sans email.
    expect(within(screen.getByRole("list", { name: "Coachs sollicités" })).getByText("pas d'email")).toBeInTheDocument();
  });

  // Décision 6 : le filtre par ÉQUIPE utilise `ResourceFilter` (même sélecteur que l'onglet
  // Doléances), restreint aux équipes de la campagne.
  it("filtre la liste par équipe via le sélecteur ResourceFilter", async () => {
    const user = userEvent.setup();
    renderTab(
      campaign({
        totalCoachCount: 2,
        teamIds: ["t1", "t2"],
        coaches: [
          coach({ coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr" }),
          coach({ coachId: "c2", firstName: "Mara", lastName: "U13", email: "mara@test.fr" }),
        ],
      }),
    );
    // Défaut « En attente » : les deux coachs (email, sans réponse) sont là.
    expect(inList("Maxime SM1")).toBeInTheDocument();
    expect(inList("Mara U13")).toBeInTheDocument();

    // Sélecteur d'équipe = ResourceFilter : on ouvre « Équipes » puis on coche « SM1 ».
    await user.click(screen.getByRole("button", { name: /Équipes/ }));
    await user.click(screen.getByRole("button", { name: "SM1" }));
    expect(inList("Maxime SM1")).toBeInTheDocument();
    expect(inList("Mara U13")).not.toBeInTheDocument();
  });

  // P4-178 — repli AA : la pastille « répondu le … » (StatusPill accent) et la puce de statut
  // active gardent leur texte en `text-foreground`, jamais `text-accent`.
  it("pastille « répondu le » et puce active : pas de `text-accent` sur le texte lisible", async () => {
    const user = userEvent.setup();
    renderTab(campaign({ respondedCoachCount: 1, coaches: [coach({ email: "m@x.fr", respondedAt: "2026-02-20T10:00:00+00:00", sentAt: "2026-02-18T09:00:00+00:00" })] }));

    // Défaut « En attente » masque la répondante : on révèle les répondants.
    const statusBtn = screen.getByRole("button", { name: "Répondu" });
    expect(statusBtn).toHaveAttribute("aria-pressed", "false");
    await user.click(statusBtn);
    expect(statusBtn).toHaveAttribute("aria-pressed", "true");
    expect(statusBtn).not.toHaveClass("text-accent");

    const responded = screen.getByText(/répondu le/);
    expect(responded).toBeInTheDocument();
    expect(responded).not.toHaveClass("text-accent");
  });

  // Décision 5 : bouton COMPACT « Lien » (→ « Copié »), nom accessible contextualisé (A11Y-28).
  it("copie le lien personnel via le bouton « Lien »", async () => {
    // Le coach (sans email, sans réponse) est visible d'emblée sous le défaut « En attente » (#1137).
    const user = userEvent.setup();
    renderTab(campaign());

    const btn = screen.getByRole("button", { name: "Copier le lien de Maxime Durand" });
    expect(btn).toHaveTextContent("Lien");
    await user.click(btn);
    expect(copyMock).toHaveBeenCalledWith(`${window.location.origin}/doleances/${"a".repeat(64)}`);
  });

  // D2 — « Voir la page d'un coach » (zone globale, non filtrée) ouvre l'aperçu dans un onglet.
  it("ouvre l'aperçu de la page du coach sélectionné dans un nouvel onglet", async () => {
    const user = userEvent.setup();
    const open = vi.fn();
    vi.stubGlobal("open", open);
    renderTab(campaign());
    await user.click(screen.getByRole("button", { name: /Voir la page d'un coach/ }));
    expect(open).toHaveBeenCalledWith("/doleances/apercu/camp1?coach=c1", "_blank", "noopener");
  });

  it("envoie les liens aux coachs à email pas encore servis (D2)", async () => {
    const user = userEvent.setup();
    renderTab(
      campaign({
        totalCoachCount: 2,
        teamIds: ["t1", "t2"],
        coaches: [
          coach({ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: "max@test.fr" }),
          coach({ coachId: "c2", firstName: "Mara", lastName: "Petit", email: null, token: "b".repeat(64) }),
        ],
      }),
    );

    // Sous le défaut « En attente », le coach sans email (sans réponse) est visible avec son badge (#1137).
    expect(screen.getByText("pas d'email")).toBeInTheDocument();

    // Bouton GLOBAL (périmètre plein, décision 7) : envoie à tous les coachs à email pas servis.
    await user.click(screen.getByRole("button", { name: /Envoyer les liens par email/ }));
    expect(sendMut).toHaveBeenCalledTimes(1);
    expect(sendMut.mock.calls[0][0]).toEqual({ id: "camp1" });
  });

  it("annonce « Aucun coach sur le périmètre choisi. » quand la campagne ne porte aucun coach", () => {
    renderTab(campaign({ totalCoachCount: 0, coaches: [] }));
    expect(screen.getByText("Aucun coach sur le périmètre choisi.")).toBeInTheDocument();
  });

  // #1137 — « Pas d'email » est un critère INDÉPENDANT : il liste les coachs sans email QUELLE
  // QUE soit leur réponse. Un répondant via WhatsApp (sans email) relève des DEUX : « Répondu »
  // (il a répondu) ET « Pas d'email » (il n'a pas d'email).
  it("« Pas d'email » liste un coach sans email MÊME s'il a répondu (critère indépendant, WhatsApp)", async () => {
    const user = userEvent.setup();
    renderTab(
      campaign({
        totalCoachCount: 2,
        teamIds: ["t1", "t2"],
        respondedCoachCount: 1,
        coaches: [
          coach({ coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr" }), // email, sans réponse
          // Répond via WhatsApp, aucun email : « Répondu » ET « Pas d'email » à la fois.
          coach({ coachId: "c2", firstName: "Wanda", lastName: "U13", email: null, respondedAt: "2026-02-01T10:00:00Z" }),
        ],
      }),
    );

    // On montre les répondants : Wanda (réponse WhatsApp) apparaît sous « Répondu ».
    await user.click(screen.getByRole("button", { name: "Répondu" }));
    expect(within(screen.getByRole("list", { name: "Coachs sollicités" })).getByText("Wanda U13")).toBeInTheDocument();

    // Statut « Pas d'email » SEUL : Wanda (sans email) RESTE listée bien qu'elle ait répondu ;
    // Maxime (a un email) en est exclu.
    await user.click(screen.getByRole("button", { name: "Répondu" })); // retire Répondu → {En attente}
    await user.click(screen.getByRole("button", { name: "En attente" })); // retire En attente → {}
    await user.click(screen.getByRole("button", { name: "Pas d'email" }));
    expect(within(screen.getByRole("list", { name: "Coachs sollicités" })).getByText("Wanda U13")).toBeInTheDocument();
    expect(inList("Maxime SM1")).not.toBeInTheDocument();
  });

  it("affiche « saison archivée » sur un 409, pas « déjà relancé »", () => {
    remindState.isError = true;
    remindState.error = new HTTPError(new Response(null, { status: 409 }), new Request("http://x/api/coach_wish_campaigns/camp1/remind"), {} as never);
    renderTab(campaign({ coaches: [coach({ lastName: "SM1", email: "max@test.fr", sentAt: "2026-01-01T08:00:00Z" })] }));
    expect(screen.getByText(/saison est archivée/)).toBeInTheDocument();
  });

  it("affiche une erreur si la relance échoue (feedback, pas muet)", () => {
    remindState.isError = true;
    renderTab(campaign({ coaches: [coach({ lastName: "SM1", email: "max@test.fr", sentAt: "2026-01-01T08:00:00Z" })] }));
    expect(screen.getByText(/Relance impossible/)).toBeInTheDocument();
  });

  // D3 — « pas deux fois le même jour ». Le « aujourd'hui » du garde suit l'horloge de l'app
  // (`todayISO`, horloge simulée comprise), PAS l'heure réelle du navigateur. Parité avec le
  // throttle serveur `CoachWishCampaignActionController::remind`.
  it("bloque la relance si déjà relancé le jour SIMULÉ courant (D3, suit todayISO)", () => {
    renderTab(campaign({ lastReminderAt: "2026-02-01T09:00:00+01:00", coaches: [coach({ email: "max@test.fr", sentAt: "2026-01-01T08:00:00Z" })] }));
    // Désactivé mais DÉCOUVRABLE (A11Y-30, `disabledReason`) : aria-disabled, pas le `disabled` natif.
    expect(screen.getByRole("button", { name: /Relancer les silencieux/ })).toHaveAttribute("aria-disabled", "true");
  });

  it("laisse relancer si la dernière relance est un AUTRE jour que le jour simulé courant", () => {
    renderTab(campaign({ lastReminderAt: "2026-01-31T09:00:00+01:00", coaches: [coach({ email: "max@test.fr", sentAt: "2026-01-01T08:00:00Z" })] }));
    expect(screen.getByRole("button", { name: /Relancer les silencieux/ })).toBeEnabled();
  });

  // ── D1 — aperçu de l'e-mail du lien coach (envoi initial) ──

  it("ouvre l'aperçu de l'e-mail dans une iframe sandboxée, objet et expéditeur au-dessus", async () => {
    const user = userEvent.setup();
    previewState.data = { subject: "Vos disponibilités pour Toussaint", from: "Gérald (BCCL) via Amateo <no-reply@amateo.app>", html: "<p>Bonjour Prénom</p>" };
    renderTab(campaign({ coaches: [coach({ email: "m@x.fr" })] }));

    await user.click(screen.getByRole("button", { name: /Aperçu de l'e-mail/ }));

    expect(screen.getByText("Vos disponibilités pour Toussaint")).toBeInTheDocument();
    expect(screen.getByText(/via Amateo/)).toBeInTheDocument();
    const iframe = screen.getByTitle("Aperçu de l'e-mail");
    expect(iframe).toHaveAttribute("sandbox", "");
    expect(iframe).toHaveAttribute("srcdoc", "<p>Bonjour Prénom</p>");
  });

  it("montre un chargement tant que l'aperçu n'est pas arrivé (jamais une iframe vide)", async () => {
    const user = userEvent.setup();
    previewState.data = undefined;
    renderTab(campaign({ coaches: [coach({ email: "m@x.fr" })] }));

    await user.click(screen.getByRole("button", { name: /Aperçu de l'e-mail/ }));

    expect(screen.getByLabelText("Chargement")).toBeInTheDocument();
    expect(screen.queryByTitle("Aperçu de l'e-mail")).toBeNull();
  });

  it("montre une erreur avec « Réessayer » quand l'aperçu échoue", async () => {
    const user = userEvent.setup();
    previewState.data = undefined;
    previewState.isError = true;
    renderTab(campaign({ coaches: [coach({ email: "m@x.fr" })] }));

    await user.click(screen.getByRole("button", { name: /Aperçu de l'e-mail/ }));

    expect(screen.getByText(/aperçu n'a pas pu être chargé/i)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Réessayer" }));
    expect(refetchPreview).toHaveBeenCalled();
  });
});
