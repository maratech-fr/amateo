import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import * as wizardApi from "@/features/wizard/api";
import { useToastStore } from "@/shared/stores/toastStore";
import { renderWithProviders, visibleTextOccurrences } from "@/test/utils";

import type { Coach, ConflictCoachMailing, ConflictMailingResult, ConflictMailPreview, ConflictMailPreviewRecipient } from "./api";
import * as matchesApi from "./api";
import { CoachMailBar } from "./CoachMailBar";
import { useConflicts } from "./queries";
import { MATCHES_CONFLICTS_MAIL_TEXTS as MAIL } from "./texts/conflictsMail";

/**
 * « Envoyer aux coachs » (onglet Conflits) — la barre et sa modale d'aperçu avant envoi (plan D9,
 * D10). Promesse : AVANT de cliquer « Envoyer », le gestionnaire voit qui recevra quoi — chaque
 * nombre, chaque « recevra / ne recevra rien », chaque motif de grisé vient du SERVEUR (`GET
 * …/mail-coaches/preview`), l'écran n'en recalcule aucun ; l'adresse d'un coach se saisit ou se
 * corrige sur place ; la case « Tout renvoyer » part dans le corps du POST.
 *
 * Les tests de la barre (bouton, grisés, toast, ligne « Envoyé le … ») viennent de
 * `ConflictsPage.test.tsx` (déplacés avec le composant, rendus ici seuls). Les deux filets GLOBAUX
 * (toast unique d'un PUT refusé, zéro toast d'un aperçu en échec) exigent le VRAI `queryClient` :
 * ils vivent dans `CoachMailBar.net.test.tsx` (jamais ici — `renderWithProviders` n'a pas le filet).
 */

vi.mock("./api", () => ({
  getConflicts: vi.fn(() => Promise.resolve({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: [], held: [] })),
  getConflictMailPreview: vi.fn(),
  mailConflictsToCoaches: vi.fn(),
}));

vi.mock("@/features/wizard/api", async (importOriginal) => ({ ...(await importOriginal<typeof import("@/features/wizard/api")>()), updateCoach: vi.fn() }));

const SENT_AT = "2026-11-03T14:32:00+01:00"; // affiché « 03/11/2026 à 14:32 » (heure murale servie)
const COACHES = new Map<string, Coach>([
  ["coach-karim", { id: "coach-karim", firstName: "Karim", lastName: "Benali" } as Coach],
  ["coach-emma", { id: "coach-emma", firstName: "Emma", lastName: "Durand" } as Coach],
]);
const DEFAULT_COACH_MAILING: ConflictCoachMailing = { personConflictCount: 3, pendingRecipientCount: 0, recipientCount: 4, last: null };

// ── Décor de l'aperçu (plan §4.1) : six coachs, six situations, tout servi par le serveur ──
function recipientOf(over: Partial<ConflictMailPreviewRecipient> & Pick<ConflictMailPreviewRecipient, "coachId" | "firstName" | "lastName">): ConflictMailPreviewRecipient {
  return {
    email: `${over.firstName.toLowerCase()}@club.test`,
    hasValidAddress: true,
    kind: "conflicts",
    receiptKnown: true,
    conflictCount: 1,
    newCount: 0,
    changedCount: 0,
    alreadySentCount: 0,
    lastSentAt: SENT_AT,
    sendsByDefault: true,
    sendsOnResendAll: true,
    ...over,
  };
}
const KARIM = recipientOf({ coachId: "coach-karim", firstName: "Karim", lastName: "Benali", conflictCount: 3, newCount: 1, alreadySentCount: 2 });
const EMMA = recipientOf({ coachId: "coach-emma", firstName: "Emma", lastName: "Durand", conflictCount: 2, changedCount: 1, alreadySentCount: 1 });
const OMAR = recipientOf({ coachId: "coach-omar", firstName: "Omar", lastName: "Fares", conflictCount: 2, receiptKnown: false, lastSentAt: null });
const LEA = recipientOf({ coachId: "coach-lea", firstName: "Léa", lastName: "Roux", kind: "cleared", conflictCount: 0 });
const NADIA = recipientOf({ coachId: "coach-nadia", firstName: "Nadia", lastName: "Kader", email: "nadia@", hasValidAddress: false, conflictCount: 2, newCount: 2, sendsByDefault: false, sendsOnResendAll: false });
const SAM = recipientOf({ coachId: "coach-sam", firstName: "Sam", lastName: "Tissot", conflictCount: 1, alreadySentCount: 1, sendsByDefault: false });

function previewOf(over: Partial<ConflictMailPreview> = {}): ConflictMailPreview {
  return { seasonId: "s", personConflictCount: 7, defaultMailCount: 4, resendAllMailCount: 5, blockedByDefault: null, blockedOnResendAll: null, recipients: [KARIM, EMMA, OMAR, LEA, NADIA, SAM], ...over };
}

function mailingResult(over: Partial<ConflictMailingResult> = {}): ConflictMailingResult {
  return { sentAt: SENT_AT, conflictCount: 0, recipients: [], cleared: [], skipped: [], failed: [], ...over };
}
const recipient = (n: number) => ({ coachId: `r-${n}`, firstName: `Coach${n}`, lastName: "Liste", conflictCount: 1 });
const clearedCoach = (n: number) => ({ coachId: `c-${n}`, firstName: `Coach${n}`, lastName: "Plus" });
const P_KARIM = { coachId: "coach-karim", firstName: "Karim", lastName: "Benali" };
const P_EMMA = { coachId: "coach-emma", firstName: "Emma", lastName: "Durand" };
const P_LEA = { coachId: "coach-lea", firstName: "Léa", lastName: "Roux" };

function httpError(status: number, data: unknown): HTTPError {
  const response = new Response(JSON.stringify(data), { status, headers: { "content-type": "application/json" } });
  const error = new HTTPError(response, new Request("http://localhost/api/fixtures/conflicts/mail-coaches", { method: "POST" }), {} as never);
  (error as unknown as { data?: unknown }).data = data;
  return error;
}

// La barre reçoit ses props de la page (inchangées) ; la sonde monte le flux du radar pour prouver
// qu'un envoi le fait relire.
const bar = vi.hoisted(() => ({ mailing: undefined as ConflictCoachMailing | undefined, canManage: true, isDemo: false, seasonReadonly: false }));
function RadarProbe() {
  useConflicts();
  return null;
}
function renderBar() {
  return renderWithProviders(
    <>
      <CoachMailBar mailing={bar.mailing} canManage={bar.canManage} isDemo={bar.isDemo} seasonReadonly={bar.seasonReadonly} seasonName="2026-2027" coaches={COACHES} />
      <RadarProbe />
    </>,
  );
}

const findSendButton = () => screen.findByRole("button", { name: MAIL.sendButton });
const lastToast = () => useToastStore.getState().toasts.at(-1);
/** Grisé : `disabled` natif (motif en clair à côté) ou `aria-disabled` (motif par `disabledReason`) — le motif, lui, est prouvé à part. */
const expectGreyed = (button: HTMLElement): void => {
  expect(button.hasAttribute("disabled") || "true" === button.getAttribute("aria-disabled")).toBe(true);
};
const isGreyed = (button: HTMLElement): boolean => button.hasAttribute("disabled") || "true" === button.getAttribute("aria-disabled");
const statusPrefix = () => MAIL.statusLine({ date: "§", time: "§", sender: null, mailedCount: 1, skippedNames: [] }).split("§")[0];

/** Ouvre la modale et attend que l'aperçu soit rendu (la ligne de Karim visible). */
async function openPreview(user: ReturnType<typeof userEvent.setup>): Promise<HTMLElement> {
  await user.click(await findSendButton());
  const dialog = await screen.findByRole("dialog", { name: MAIL.confirmTitle });
  await waitFor(() => expect(within(dialog).getAllByRole("listitem").length).toBeGreaterThan(0));
  return dialog;
}
/** La ligne `<li>` d'un coach dans la modale (repérée par son prénom). */
function rowOf(dialog: HTMLElement, firstName: string): HTMLElement {
  const row = within(dialog)
    .getAllByRole("listitem")
    .find((li) => (li.textContent ?? "").includes(firstName));
  if (undefined === row) {
    throw new Error(`aucune ligne pour ${firstName} dans l'aperçu`);
  }
  return row;
}
const confirmButton = (dialog: HTMLElement) => within(dialog).getByRole("button", { name: MAIL.confirmAction });
async function confirmEnabled(dialog: HTMLElement): Promise<HTMLElement> {
  const button = confirmButton(dialog);
  await waitFor(() => expect(isGreyed(confirmButton(dialog))).toBe(false));
  return button;
}

beforeEach(() => {
  vi.clearAllMocks();
  bar.mailing = DEFAULT_COACH_MAILING;
  bar.canManage = true;
  bar.isDemo = false;
  bar.seasonReadonly = false;
  vi.mocked(matchesApi.getConflictMailPreview).mockImplementation(() => Promise.resolve(previewOf()));
  vi.mocked(matchesApi.mailConflictsToCoaches).mockResolvedValue(mailingResult({ recipients: [recipient(1)] }));
  useToastStore.setState({ toasts: [] });
});

// ── La barre (déplacés de ConflictsPage.test.tsx avec le composant) ─────────────────────────

describe("CoachMailBar — qui voit le bouton", () => {
  it("un gestionnaire voit « Envoyer aux coachs », actif quand il y a des conflits de personne", async () => {
    renderBar();
    const button = await findSendButton();
    expect(button).not.toHaveAttribute("aria-disabled");
    expect(button).toBeEnabled();
  });

  it("un membre n'a pas le bouton mais lit la ligne du dernier envoi", async () => {
    bar.canManage = false;
    bar.mailing = { ...DEFAULT_COACH_MAILING, last: { sentAt: SENT_AT, sentByFirstName: "Nadia", mailedCount: 7, skippedCoachIds: [] } };
    renderBar();
    expect(await screen.findByText(MAIL.statusLine({ date: "03/11/2026", time: "14:32", sender: "Nadia", mailedCount: 7, skippedNames: [] }))).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: MAIL.sendButton })).not.toBeInTheDocument();
    expect(matchesApi.getConflictMailPreview).not.toHaveBeenCalled();
  });

  it("zéro conflit de personne mais un coach à prévenir (« plus aucun conflit ») : le bouton reste ACTIF", async () => {
    bar.mailing = { personConflictCount: 0, pendingRecipientCount: 1, recipientCount: 1, last: { sentAt: SENT_AT, sentByFirstName: "Nadia", mailedCount: 2, skippedCoachIds: [] } };
    renderBar();
    const button = await findSendButton();
    expect(button).not.toHaveAttribute("aria-disabled");
    expect(button).toBeEnabled();
  });
});

describe("CoachMailBar — désactivé, avec sa raison découvrable", () => {
  it.each([
    ["rien à envoyer ni personne à prévenir", (): void => { bar.mailing = { personConflictCount: 0, pendingRecipientCount: 0, recipientCount: 0, last: null }; }, (): string => MAIL.disabledNothingToSend],
    ["club de démonstration", (): void => { bar.isDemo = true; }, (): string => MAIL.disabledDemo],
    ["saison archivée (saison de travail en lecture seule)", (): void => { bar.seasonReadonly = true; }, (): string => MAIL.disabledArchived],
    ["réponse sans `coachMailing`", (): void => { bar.mailing = undefined; }, (): string => MAIL.disabledUnavailable],
  ])("%s : bouton désactivé, raison annoncée, le clic n'ouvre rien", async (_label, arrange, reason) => {
    arrange();
    const user = userEvent.setup();
    renderBar();
    const button = await findSendButton();
    expectGreyed(button);
    expect(button).toHaveAccessibleDescription(reason());
    await user.click(button);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(matchesApi.mailConflictsToCoaches).not.toHaveBeenCalled();
  });

  it.each([
    ["rien à envoyer ni personne à prévenir", (): void => { bar.mailing = { personConflictCount: 0, pendingRecipientCount: 0, recipientCount: 0, last: null }; }, (): string => MAIL.disabledNothingToSend],
    ["club de démonstration", (): void => { bar.isDemo = true; }, (): string => MAIL.disabledDemo],
    ["saison archivée (saison de travail en lecture seule)", (): void => { bar.seasonReadonly = true; }, (): string => MAIL.disabledArchived],
    ["réponse sans `coachMailing`", (): void => { bar.mailing = undefined; }, (): string => MAIL.disabledUnavailable],
  ])("%s : le motif se LIT à côté du bouton grisé (texte visible, pas un title ni un sr-only)", async (_label, arrange, reason) => {
    arrange();
    renderBar();
    const button = await findSendButton();
    expectGreyed(button);
    const row = button.closest("div");
    const visible = visibleTextOccurrences(reason()).filter((element) => null !== row && row.contains(element) && !button.contains(element));
    expect(visible.length, "le motif doit être un texte visible dans la rangée du bouton").toBeGreaterThan(0);
  });
});

describe("CoachMailBar — confirmation puis envoi", () => {
  it("le clic ouvre une confirmation qui nomme la saison ; « Annuler » n'envoie rien", async () => {
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    expect(within(dialog).getByText(MAIL.confirmBody("2026-2027"))).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: MAIL.confirmCancel }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(matchesApi.mailConflictsToCoaches).not.toHaveBeenCalled();
  });

  it("« Envoyer » poste UNE fois avec `{ resendAll: false }` ; pendant la requête « Envoi… » est désactivé ; au retour le radar est relu", async () => {
    let resolve: (value: ConflictMailingResult) => void = () => undefined;
    vi.mocked(matchesApi.mailConflictsToCoaches).mockReturnValueOnce(
      new Promise<ConflictMailingResult>((r) => {
        resolve = r;
      }),
    );
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    await user.click(await confirmEnabled(dialog));

    const pending = await within(dialog).findByRole("button", { name: MAIL.sending });
    expectGreyed(pending);
    await user.click(pending);
    expect(matchesApi.mailConflictsToCoaches).toHaveBeenCalledTimes(1);
    expect(matchesApi.mailConflictsToCoaches).toHaveBeenCalledWith({ resendAll: false });

    const readsBefore = vi.mocked(matchesApi.getConflicts).mock.calls.length;
    resolve(mailingResult({ recipients: [recipient(1), recipient(2)] }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    await waitFor(() => expect(vi.mocked(matchesApi.getConflicts).mock.calls.length).toBeGreaterThan(readsBefore));
  });

  it("un refus serveur `{detail}` (409 socle rouvert) s'affiche tel quel en toast d'erreur", async () => {
    // Le `detail` que sert vraiment `SocleGuard::SEASON_PLAN_REQUIRED_MESSAGE` (un mock envoie ce que l'API envoie).
    const detail = "Validez le planning de saison avant de créer un match ou un planning de période.";
    vi.mocked(matchesApi.mailConflictsToCoaches).mockRejectedValueOnce(httpError(409, { detail }));
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    await user.click(await confirmEnabled(dialog));
    await waitFor(() => expect(lastToast()?.message).toBe(detail));
    expect(lastToast()?.variant).toBe("error");
  });
});

describe("CoachMailBar — le toast dit ce qui est parti", () => {
  it.each([
    ["un e-mail", mailingResult({ recipients: [recipient(1)] }), { mailedCount: 1, skippedNames: [] as string[], failedNames: [] as string[] }],
    ["listes ET « plus aucun conflit » comptent ensemble", mailingResult({ recipients: [1, 2, 3, 4, 5].map(recipient), cleared: [clearedCoach(6), clearedCoach(7)] }), { mailedCount: 7, skippedNames: [], failedNames: [] }],
    ["un coach sans adresse nommé", mailingResult({ recipients: [1, 2, 3, 4, 5, 6, 7].map(recipient), skipped: [{ ...P_KARIM, kind: "conflicts" }] }), { mailedCount: 7, skippedNames: ["Karim B."], failedNames: [] }],
    [
      "deux e-mails non partis nommés (dont un « plus aucun »)",
      mailingResult({ recipients: [1, 2, 3, 4, 5, 6, 7].map(recipient), failed: [{ ...P_LEA, kind: "conflicts" }, { ...P_EMMA, kind: "cleared" }] }),
      { mailedCount: 7, skippedNames: [], failedNames: ["Léa R.", "Emma D."] },
    ],
    ["rien enfilé, deux sans adresse", mailingResult({ sentAt: null, skipped: [{ ...P_KARIM, kind: "conflicts" }, { ...P_EMMA, kind: "cleared" }] }), { mailedCount: 0, skippedNames: ["Karim B.", "Emma D."], failedNames: [] }],
  ])("%s", async (_label, result, expected) => {
    vi.mocked(matchesApi.mailConflictsToCoaches).mockResolvedValueOnce(result);
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    await user.click(await confirmEnabled(dialog));
    await waitFor(() => expect(lastToast()?.message).toBe(MAIL.toastResult(expected)));
  });
});

describe("CoachMailBar — la ligne « Envoyé le … »", () => {
  it.each([
    ["un e-mail, par Nadia", { sentByFirstName: "Nadia", mailedCount: 1, skippedCoachIds: [] as string[] }, { sender: "Nadia" as string | null, mailedCount: 1, skippedNames: [] as string[] }],
    ["auteur disparu : sans « par »", { sentByFirstName: null, mailedCount: 7, skippedCoachIds: [] }, { sender: null, mailedCount: 7, skippedNames: [] }],
    ["un coach sans adresse à cet envoi", { sentByFirstName: "Nadia", mailedCount: 7, skippedCoachIds: ["coach-karim"] }, { sender: "Nadia", mailedCount: 7, skippedNames: ["Karim B."] }],
    ["deux coachs sans adresse", { sentByFirstName: "Nadia", mailedCount: 7, skippedCoachIds: ["coach-karim", "coach-emma"] }, { sender: "Nadia", mailedCount: 7, skippedNames: ["Karim B.", "Emma D."] }],
    ["un coach supprimé depuis : « un coach retiré »", { sentByFirstName: "Nadia", mailedCount: 7, skippedCoachIds: ["coach-disparu"] }, { sender: "Nadia", mailedCount: 7, skippedNames: [MAIL.coachRemoved] }],
  ])("%s", async (_label, last, expected) => {
    bar.mailing = { ...DEFAULT_COACH_MAILING, last: { sentAt: SENT_AT, ...last } };
    renderBar();
    expect(await screen.findByText(MAIL.statusLine({ date: "03/11/2026", time: "14:32", ...expected }))).toBeInTheDocument();
  });

  it("aucun envoi encore : pas de ligne « Envoyé le »", async () => {
    renderBar();
    await findSendButton();
    expect(screen.queryByText((text) => text.startsWith(statusPrefix()))).not.toBeInTheDocument();
  });
});

// ── L'aperçu avant envoi (plan D6, D9, §3, §4) ─────────────────────────────────────────────

describe("CoachMailBar — l'aperçu dit qui recevra quoi, tel que le serveur le sert", () => {
  it("une ligne par destinataire, dans l'ORDRE servi : adresse, détail, et ce qui partira — rien n'est recompté", async () => {
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    expect(matchesApi.getConflictMailPreview).toHaveBeenCalled();

    const names = within(dialog)
      .getAllByRole("listitem")
      .map((li) => ["Karim", "Emma", "Omar", "Léa", "Nadia", "Sam"].find((first) => (li.textContent ?? "").includes(first)));
    expect(names, "l'écran garde l'ordre servi (le serveur trie)").toEqual(["Karim", "Emma", "Omar", "Léa", "Nadia", "Sam"]);

    const karim = rowOf(dialog, "Karim");
    expect(karim).toHaveTextContent(MAIL.recipientLine({ name: "Karim B.", email: "karim@club.test" }));
    expect(karim).toHaveTextContent(MAIL.recipientDetail({ conflictCount: 3, newCount: 1, changedCount: 0, alreadySentCount: 2, lastSentDate: "03/11", receiptKnown: true }));
    expect(karim).toHaveTextContent(MAIL.willSend);

    const emma = rowOf(dialog, "Emma");
    expect(emma).toHaveTextContent(MAIL.recipientDetail({ conflictCount: 2, newCount: 0, changedCount: 1, alreadySentCount: 1, lastSentDate: "03/11", receiptKnown: true }));
    expect(emma).toHaveTextContent(MAIL.willSend);

    const omar = rowOf(dialog, "Omar");
    expect(omar).toHaveTextContent(MAIL.recipientDetail({ conflictCount: 2, newCount: 0, changedCount: 0, alreadySentCount: 0, lastSentDate: null, receiptKnown: false }));
    expect(omar, "aucun reçu connu : il part une fois, liste complète").toHaveTextContent(MAIL.willSendFull);

    const sam = rowOf(dialog, "Sam");
    expect(sam).toHaveTextContent(MAIL.recipientDetail({ conflictCount: 1, newCount: 0, changedCount: 0, alreadySentCount: 1, lastSentDate: "03/11", receiptKnown: true }));
    expect(sam).toHaveTextContent(MAIL.willNotSendNothingNew);

    const nadia = rowOf(dialog, "Nadia");
    expect(nadia).toHaveTextContent(MAIL.willNotSendNoAddress);

    expect(within(dialog).getByText(MAIL.previewSummary({ mails: 4, noAddress: 1, resendAll: false }), { exact: false })).toBeInTheDocument();
    expect(within(dialog).getByText(MAIL.confirmIgnoresFilters, { exact: false })).toBeInTheDocument();
  });

  it("un coach informé précédemment sans plus aucun conflit (`kind: cleared`) : « plus aucun conflit — une liste lui avait été envoyée »", async () => {
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    const lea = rowOf(dialog, "Léa");
    expect(lea).toHaveTextContent(MAIL.clearedDetail);
    expect(lea).toHaveTextContent(MAIL.willSend);
  });

  it("les nombres viennent du serveur : un bilan servi que la liste ne permet pas de recompter s'affiche tel quel", async () => {
    // 9 e-mails annoncés pour 6 lignes : seul le serveur peut le dire, l'écran ne recompte pas.
    vi.mocked(matchesApi.getConflictMailPreview).mockImplementation(() => Promise.resolve(previewOf({ defaultMailCount: 9 })));
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    expect(within(dialog).getByText(MAIL.previewSummary({ mails: 9, noAddress: 1, resendAll: false }), { exact: false })).toBeInTheDocument();
  });
});

describe("CoachMailBar — l'adresse se saisit ou se corrige sur place", () => {
  it("sans adresse (`email: null`) : pastille « Pas d'adresse » et champ VIDE", async () => {
    vi.mocked(matchesApi.getConflictMailPreview).mockImplementation(() =>
      Promise.resolve(previewOf({ recipients: [KARIM, { ...NADIA, email: null }] })),
    );
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    const nadia = rowOf(dialog, "Nadia");
    expect(within(nadia).getByText(MAIL.noAddressPill)).toBeInTheDocument();
    expect(within(nadia).queryByText(MAIL.invalidAddressPill)).not.toBeInTheDocument();
    expect(within(nadia).getByRole("textbox", { name: MAIL.emailAria("Nadia Kader") })).toHaveValue("");
  });

  it("adresse invalide (`nadia@`) : pastille « Adresse invalide » et champ PRÉREMPLI", async () => {
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    const nadia = rowOf(dialog, "Nadia");
    expect(within(nadia).getByText(MAIL.invalidAddressPill)).toBeInTheDocument();
    expect(within(nadia).queryByText(MAIL.noAddressPill)).not.toBeInTheDocument();
    expect(within(nadia).getByRole("textbox", { name: MAIL.emailAria("Nadia Kader") })).toHaveValue("nadia@");
  });

  it("un coach à adresse valide n'a pas de champ : la modale ne sert qu'à saisir ou corriger", async () => {
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    expect(within(rowOf(dialog, "Karim")).queryByRole("textbox")).not.toBeInTheDocument();
  });

  it("blur : le PUT part avec `{ firstName, lastName, email }`, puis l'aperçu est relu", async () => {
    vi.mocked(wizardApi.updateCoach).mockResolvedValue({} as never);
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    const field = within(rowOf(dialog, "Nadia")).getByRole("textbox", { name: MAIL.emailAria("Nadia Kader") });
    const readsBefore = vi.mocked(matchesApi.getConflictMailPreview).mock.calls.length;

    await user.clear(field);
    await user.type(field, "nadia@club.test");
    await user.tab();

    await waitFor(() => expect(wizardApi.updateCoach).toHaveBeenCalledTimes(1));
    expect(wizardApi.updateCoach).toHaveBeenCalledWith("coach-nadia", { firstName: "Nadia", lastName: "Kader", email: "nadia@club.test" });
    await waitFor(() => expect(vi.mocked(matchesApi.getConflictMailPreview).mock.calls.length).toBeGreaterThan(readsBefore));
  });

  it("blur sur une saisie VIDE ou INCHANGÉE : aucun appel (la modale ne retire jamais une adresse)", async () => {
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    const field = within(rowOf(dialog, "Nadia")).getByRole("textbox", { name: MAIL.emailAria("Nadia Kader") });

    await user.click(field);
    await user.tab(); // inchangée
    await user.clear(field);
    await user.tab(); // vide
    expect(wizardApi.updateCoach).not.toHaveBeenCalled();
  });

  it("pendant l'enregistrement de l'adresse : « Envoyer » grisé, motif « Enregistrement de l'adresse… »", async () => {
    vi.mocked(wizardApi.updateCoach).mockReturnValue(new Promise(() => undefined));
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    await confirmEnabled(dialog);
    const field = within(rowOf(dialog, "Nadia")).getByRole("textbox", { name: MAIL.emailAria("Nadia Kader") });
    await user.clear(field);
    await user.type(field, "nadia@club.test");
    await user.tab();

    await waitFor(() => expect(confirmButton(dialog)).toHaveAccessibleDescription(MAIL.confirmDisabledSaving));
    expectGreyed(confirmButton(dialog));
    await user.click(confirmButton(dialog));
    expect(matchesApi.mailConflictsToCoaches).not.toHaveBeenCalled();
  });
});

describe("CoachMailBar — « Envoyer » grisé avec son motif tant que l'aperçu n'est pas sûr", () => {
  it("pendant la lecture de l'aperçu : « Mise à jour de l'aperçu… », le clic n'envoie rien", async () => {
    vi.mocked(matchesApi.getConflictMailPreview).mockReturnValue(new Promise(() => undefined));
    const user = userEvent.setup();
    renderBar();
    await user.click(await findSendButton());
    const dialog = await screen.findByRole("dialog", { name: MAIL.confirmTitle });
    const button = confirmButton(dialog);
    expectGreyed(button);
    expect(button).toHaveAccessibleDescription(MAIL.confirmDisabledRefreshing);
    await user.click(button);
    expect(matchesApi.mailConflictsToCoaches).not.toHaveBeenCalled();
  });

  it("aperçu en échec : `LoadErrorHint` « L'aperçu n'a pas pu être chargé. » avec « Réessayer », « Envoyer » grisé", async () => {
    vi.mocked(matchesApi.getConflictMailPreview).mockRejectedValue(new Error("network down"));
    const user = userEvent.setup();
    renderBar();
    await user.click(await findSendButton());
    const dialog = await screen.findByRole("dialog", { name: MAIL.confirmTitle });
    const alert = await within(dialog).findByRole("alert");
    expect(alert).toHaveTextContent(MAIL.previewLoadFailed);
    expect(within(alert).getByRole("button", { name: "Réessayer" })).toBeInTheDocument();
    expectGreyed(confirmButton(dialog));
  });

  it.each([
    ["nothing", (): string => MAIL.disabledNothingToSend],
    ["no_address", (): string => MAIL.blockedNoAddress],
    ["nothing_new", (): string => MAIL.blockedNothingNew],
    ["mixed", (): string => MAIL.blockedMixed],
  ] as const)("motif servi `%s` → son libellé, « Envoyer » grisé", async (blocked, label) => {
    vi.mocked(matchesApi.getConflictMailPreview).mockImplementation(() =>
      Promise.resolve(previewOf({ defaultMailCount: 0, blockedByDefault: blocked, recipients: "nothing" === blocked ? [] : [SAM, NADIA] })),
    );
    const user = userEvent.setup();
    renderBar();
    await user.click(await findSendButton());
    const dialog = await screen.findByRole("dialog", { name: MAIL.confirmTitle });
    await waitFor(() => expect(confirmButton(dialog)).toHaveAccessibleDescription(label()));
    expectGreyed(confirmButton(dialog));
    expect(within(dialog).getByText(MAIL.previewSummary({ mails: 0, noAddress: 0, resendAll: false }), { exact: false })).toBeInTheDocument();
  });
});

describe("CoachMailBar — « Tout renvoyer »", () => {
  it("cochée : chaque ligne dit ce que `sendsOnResendAll` sert, le bilan suit, le POST part avec `{ resendAll: true }` — sans relire l'aperçu", async () => {
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    const reads = vi.mocked(matchesApi.getConflictMailPreview).mock.calls.length;

    await user.click(within(dialog).getByRole("checkbox", { name: MAIL.resendAllLabel }));

    expect(rowOf(dialog, "Karim")).toHaveTextContent(MAIL.willSendFull);
    expect(rowOf(dialog, "Sam"), "rien de nouveau, mais « Tout renvoyer » le sert").toHaveTextContent(MAIL.willSendFull);
    expect(rowOf(dialog, "Nadia"), "sans adresse valide, rien ne part même en « Tout renvoyer »").toHaveTextContent(MAIL.willNotSendNoAddress);
    expect(within(dialog).getByText(MAIL.previewSummary({ mails: 5, noAddress: 1, resendAll: true }), { exact: false })).toBeInTheDocument();
    expect(vi.mocked(matchesApi.getConflictMailPreview).mock.calls.length, "la case ne déclenche aucune relecture").toBe(reads);

    await user.click(await confirmEnabled(dialog));
    await waitFor(() => expect(matchesApi.mailConflictsToCoaches).toHaveBeenCalledWith({ resendAll: true }));
  });

  it("rien de nouveau par défaut (`nothing_new`) : cocher « Tout renvoyer » rend « Envoyer » actif", async () => {
    vi.mocked(matchesApi.getConflictMailPreview).mockImplementation(() =>
      Promise.resolve(previewOf({ defaultMailCount: 0, blockedByDefault: "nothing_new", recipients: [{ ...KARIM, newCount: 0, alreadySentCount: 3, sendsByDefault: false }, SAM] })),
    );
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    await waitFor(() => expect(confirmButton(dialog)).toHaveAccessibleDescription(MAIL.blockedNothingNew));
    await user.click(within(dialog).getByRole("checkbox", { name: MAIL.resendAllLabel }));
    await confirmEnabled(dialog);
  });

  it("personne n'a d'adresse (`blockedOnResendAll: no_address`) : cocher ne débloque rien", async () => {
    vi.mocked(matchesApi.getConflictMailPreview).mockImplementation(() =>
      Promise.resolve(previewOf({ defaultMailCount: 0, resendAllMailCount: 0, blockedByDefault: "no_address", blockedOnResendAll: "no_address", recipients: [NADIA] })),
    );
    const user = userEvent.setup();
    renderBar();
    const dialog = await openPreview(user);
    await user.click(within(dialog).getByRole("checkbox", { name: MAIL.resendAllLabel }));
    await waitFor(() => expect(confirmButton(dialog)).toHaveAccessibleDescription(MAIL.blockedNoAddress));
    expectGreyed(confirmButton(dialog));
  });
});
