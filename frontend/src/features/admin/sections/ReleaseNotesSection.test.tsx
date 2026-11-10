import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { HTTPError } from "ky";

import { RELEASE_NOTES_TEXTS } from "@/features/release-notes/texts";
import { frDateSlashed } from "@/shared/lib/date";
import { useToastStore } from "@/shared/stores/toastStore";
import { renderWithProviders } from "@/test/utils";

import type { AdminReleaseNote } from "../api";
import { createAdminReleaseNote, getAdminReleaseNotes, publishAdminReleaseNote } from "../api";
import { useAdminStore } from "../store";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";
import { ReleaseNotesSection } from "./ReleaseNotesSection";

vi.mock("../api", async (importOriginal) => {
  const original = await importOriginal<typeof import("../api")>();
  return {
    ...original,
    getAdminReleaseNotes: vi.fn(),
    createAdminReleaseNote: vi.fn(),
    publishAdminReleaseNote: vi.fn(),
    deleteAdminReleaseNote: vi.fn(),
  };
});

const mockList = vi.mocked(getAdminReleaseNotes);
const mockCreate = vi.mocked(createAdminReleaseNote);
const mockPublish = vi.mocked(publishAdminReleaseNote);

// La version « en service » lue par le pré-remplissage : pilotée par test (jsdom n'a pas de
// VITE_APP_VERSION — repli « dev »). Getter = liaison vivante, relue à chaque rendu.
const appVersion = vi.hoisted(() => ({ current: "dev" }));
vi.mock("@/shared/lib/appVersion", () => ({
  get APP_VERSION() {
    return appVersion.current;
  },
}));

/**
 * L'éditeur riche est remplacé ici par un DOUBLE (une zone de texte qui rend un paragraphe) : ce
 * fichier garde le CÂBLAGE de la section (payload `bodyRich`, aperçu, lignes) ; l'éditeur réel est
 * prouvé par `releaseNoteEditor/ReleaseNoteEditor.test.tsx` et `editorOutput.test.ts`.
 */
vi.mock("../releaseNoteEditor/ReleaseNoteEditor", () => ({
  ReleaseNoteEditor: ({ id, onChange }: { id: string; onChange: (doc: unknown) => void }) => (
    <textarea
      id={id}
      onChange={(event) =>
        onChange({ type: "doc", content: [event.target.value === "" ? { type: "paragraph" } : { type: "paragraph", content: [{ type: "text", text: event.target.value }] }] })
      }
    />
  ),
}));

/** Le document que le double d'éditeur rend pour un texte tapé. */
function docOf(text: string) {
  return { type: "doc", content: [{ type: "paragraph", content: [{ type: "text", text }] }] };
}

const DRAFT: AdminReleaseNote = { id: "d1", title: "Refonte du planning", body: "…", date: "2026-08-10", publishedAt: null, createdAt: "2026-08-10T10:00:00+00:00", version: "1.3.5", bodyRich: null };
const PUBLISHED: AdminReleaseNote = { id: "p1", title: "Export PDF amélioré", body: "…", date: "2026-08-12", publishedAt: "2026-08-12T09:00:00+00:00", createdAt: "2026-08-12T08:00:00+00:00", version: null, bodyRich: null };

/** Une erreur HTTP telle que ky la lève (patron `shared/lib/errorMessage.test.ts`). */
function httpError(status: number, body: unknown): HTTPError {
  const response = new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });
  const error = new HTTPError(response, new Request("http://localhost/api/admin/release-notes"), {} as never);
  (error as unknown as { data?: unknown }).data = body;
  return error;
}

async function fillAndSubmit(user: ReturnType<typeof userEvent.setup>): Promise<void> {
  await user.type(screen.getByLabelText(ADMIN_SECTIONS_TEXTS.releaseNotesTitleLabel), "Ma note");
  fireEvent.change(screen.getByLabelText(ADMIN_SECTIONS_TEXTS.releaseNotesDateLabel), { target: { value: "2026-08-13" } });
  await user.type(screen.getByLabelText(ADMIN_SECTIONS_TEXTS.releaseNotesBodyLabel), "Le corps");
  await user.click(screen.getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesSubmit }));
}

describe("ReleaseNotesSection", () => {
  beforeEach(() => {
    appVersion.current = "dev";
    mockList.mockReset();
    mockCreate.mockReset();
    mockPublish.mockReset();
    useAdminStore.getState().setSession({ id: "sa", email: "sa@x" }, "csrf-token");
  });

  it("liste les brouillons ET les publiées", async () => {
    mockList.mockResolvedValue({ items: [DRAFT, PUBLISHED] });
    renderWithProviders(<ReleaseNotesSection />);

    expect(await screen.findByText("Refonte du planning")).toBeInTheDocument();
    expect(screen.getByText("Export PDF amélioré")).toBeInTheDocument();
  });

  it("enregistre une nouvelle note depuis le formulaire titre/date/corps", async () => {
    const user = userEvent.setup();
    mockList.mockResolvedValue({ items: [] });
    mockCreate.mockResolvedValue({ ...DRAFT, id: "new" });
    renderWithProviders(<ReleaseNotesSection />);

    await screen.findByRole("button", { name: /enregistrer/i });
    await user.type(screen.getByLabelText(/titre/i), "Ma note");
    // <input type="date"> ne se remplit pas caractère par caractère en jsdom : valeur directe.
    fireEvent.change(screen.getByLabelText(/date/i), { target: { value: "2026-08-13" } });
    await user.type(screen.getByLabelText(/contenu/i), "Le corps");
    await user.click(screen.getByRole("button", { name: /enregistrer/i }));

    await waitFor(() => expect(mockCreate).toHaveBeenCalledTimes(1));
    const [payload, csrf] = mockCreate.mock.calls[0] ?? [];
    expect(csrf).toBe("csrf-token");
    expect(payload).toMatchObject({ title: "Ma note", noteDate: "2026-08-13", bodyRich: docOf("Le corps") });
    expect((payload as { version?: unknown }).version).toBeUndefined();
  });

  // P4-149 (option 1) — le vide « Aucune note » passe par EmptyHint variant="console"
  // (skin partagé `text-console-muted`), comme ses 14 voisins, jamais un <p> console fait main.
  it("rend l'état vide via le skin console partagé (EmptyHint)", async () => {
    mockList.mockResolvedValue({ items: [] });
    renderWithProviders(<ReleaseNotesSection />);

    const hint = await screen.findByText(ADMIN_SECTIONS_TEXTS.releaseNotesEmpty);
    expect(hint.className).toContain("text-console-muted");
  });

  it("publie un brouillon", async () => {
    const user = userEvent.setup();
    mockList.mockResolvedValue({ items: [DRAFT] });
    mockPublish.mockResolvedValue({ ...DRAFT, publishedAt: "2026-08-13T09:00:00+00:00" });
    renderWithProviders(<ReleaseNotesSection />);

    const row = (await screen.findByText("Refonte du planning")).closest("li");
    expect(row).not.toBeNull();
    await user.click(within(row as HTMLElement).getByRole("button", { name: /publier/i }));

    await waitFor(() => expect(mockPublish).toHaveBeenCalledWith("d1", "csrf-token"));
  });
});

/**
 * Promesse (A3) : **la ligne console annonce sa note par le même en-tête « date - version » que le
 * membre voit, rendu dans la peau CONSOLE** (`text-console-text-dim`) — jamais un jeton adaptatif de
 * l'app (`text-muted-foreground`), jamais `text-console-muted` (sous 4,5:1 pour du texte).
 * Composant unitaire : `release-notes/ReleaseNoteHeading.test.tsx`.
 */
describe("ReleaseNotesSection — en-tête de ligne en peau console", () => {
  beforeEach(() => {
    mockList.mockReset();
    useAdminStore.getState().setSession({ id: "sa", email: "sa@x" }, "csrf-token");
  });

  it("chaque ligne porte l'en-tête date-version (ou date seule), en text-console-text-dim", async () => {
    mockList.mockResolvedValue({ items: [DRAFT, PUBLISHED] });
    renderWithProviders(<ReleaseNotesSection />);

    const draftRow = (await screen.findByText("Refonte du planning")).closest("li") as HTMLElement;
    const draftHeading = within(draftRow).getByText(RELEASE_NOTES_TEXTS.heading(frDateSlashed(DRAFT.date), "1.3.5"));
    expect(draftHeading.className).toContain("text-console-text-dim");
    expect(draftHeading.className).not.toContain("text-console-muted");
    expect(draftRow.querySelector(".text-muted-foreground")).toBeNull();

    const publishedRow = screen.getByText("Export PDF amélioré").closest("li") as HTMLElement;
    expect(within(publishedRow).getByText(RELEASE_NOTES_TEXTS.heading(frDateSlashed(PUBLISHED.date), null))).toBeInTheDocument();
  });
});

/**
 * Promesse (A5) : **la SA n'a pas à retaper la version en service** — le champ « Version » est
 * pré-rempli depuis la version du bundle quand elle a la forme d'un numéro (« v1.3.5 » → « 1.3.5 »),
 * laissé VIDE sinon (« dev », un sha) ; ce qui part au serveur est ce que le champ montre. La
 * normalisation et le refus côté serveur sont prouvés par `backend/tests/Integration/Api/ReleaseNotesTest.php`.
 */
describe("ReleaseNotesSection — champ Version", () => {
  beforeEach(() => {
    mockList.mockReset();
    mockCreate.mockReset();
    useToastStore.setState({ toasts: [] });
    useAdminStore.getState().setSession({ id: "sa", email: "sa@x" }, "csrf-token");
  });

  it("pré-remplit « 1.3.5 » depuis un bundle taggé « v1.3.5 », l'aide est affichée, et l'envoie", async () => {
    const user = userEvent.setup();
    appVersion.current = "v1.3.5";
    mockList.mockResolvedValue({ items: [] });
    mockCreate.mockResolvedValue({ ...DRAFT, id: "new" });
    renderWithProviders(<ReleaseNotesSection />);

    const field = await screen.findByLabelText(ADMIN_SECTIONS_TEXTS.releaseNotesVersionLabel);
    expect(field).toHaveValue("1.3.5");
    expect(screen.getByText(ADMIN_SECTIONS_TEXTS.releaseNotesVersionHint)).toBeInTheDocument();

    await fillAndSubmit(user);

    await waitFor(() =>
      expect(mockCreate).toHaveBeenCalledWith(expect.objectContaining({ title: "Ma note", bodyRich: docOf("Le corps"), noteDate: "2026-08-13", version: "1.3.5" }), "csrf-token"),
    );
  });

  it("laisse le champ vide pour un bundle non taggé (« dev ») et envoie une note sans version", async () => {
    const user = userEvent.setup();
    appVersion.current = "dev";
    mockList.mockResolvedValue({ items: [] });
    mockCreate.mockResolvedValue({ ...DRAFT, id: "new" });
    renderWithProviders(<ReleaseNotesSection />);

    expect(await screen.findByLabelText(ADMIN_SECTIONS_TEXTS.releaseNotesVersionLabel)).toHaveValue("");

    await fillAndSubmit(user);

    await waitFor(() => expect(mockCreate).toHaveBeenCalledTimes(1));
    const payload = mockCreate.mock.calls[0]?.[0];
    expect(payload).toMatchObject({ title: "Ma note", bodyRich: docOf("Le corps"), noteDate: "2026-08-13" });
    // Sans version = clé absente OU null (le serveur normalise les deux) ; jamais une chaîne.
    expect(payload?.version ?? null).toBeNull();
  });

  it("laisse le champ vide pour un sha de commit", async () => {
    appVersion.current = "3f2a9c1";
    mockList.mockResolvedValue({ items: [] });
    renderWithProviders(<ReleaseNotesSection />);

    expect(await screen.findByLabelText(ADMIN_SECTIONS_TEXTS.releaseNotesVersionLabel)).toHaveValue("");
  });

  it("envoie la version retapée par la SA", async () => {
    const user = userEvent.setup();
    mockList.mockResolvedValue({ items: [] });
    mockCreate.mockResolvedValue({ ...DRAFT, id: "new" });
    renderWithProviders(<ReleaseNotesSection />);

    await user.type(await screen.findByLabelText(ADMIN_SECTIONS_TEXTS.releaseNotesVersionLabel), "1.4");
    await fillAndSubmit(user);

    await waitFor(() =>
      expect(mockCreate).toHaveBeenCalledWith(expect.objectContaining({ title: "Ma note", bodyRich: docOf("Le corps"), noteDate: "2026-08-13", version: "1.4" }), "csrf-token"),
    );
  });

  /**
   * ⚑ DÉPEND DE LA CONSOLE PR 1 (D7, `toastWithReason` dans `admin/queries.ts`) — fichier que la
   * PR A ne touche pas. Rouge sur `origin/main` tant que la console n'est pas mergée : le hook
   * toaste un message FIXE qui avale la raison du serveur.
   *
   * Promesse : une version refusée par le serveur se lit EN CLAIR dans le toast de la SA.
   */
  it("un 400 du serveur remonte sa raison dans le toast", async () => {
    const user = userEvent.setup();
    const reason = "La version est invalide (format attendu : 1.3.5).";
    mockList.mockResolvedValue({ items: [] });
    mockCreate.mockRejectedValue(httpError(400, { error: reason }));
    renderWithProviders(<ReleaseNotesSection />);

    await user.type(await screen.findByLabelText(ADMIN_SECTIONS_TEXTS.releaseNotesVersionLabel), "abc");
    await fillAndSubmit(user);

    await waitFor(() => expect(useToastStore.getState().toasts.map((toast) => toast.message).join(" | ")).toContain(reason));
  });
});

/**
 * Promesse (PR B, B3/B4) : **dans la console, une note riche se relit dans la peau CONSOLE — sur
 * chaque ligne ET dans l'aperçu de saisie** (jetons `text-console-*`, jamais un jeton adaptatif de
 * l'app, illisible sur le fond sombre). Un refus du serveur (profondeur, forme) remonte par le même
 * `toastWithReason` que le test « 400 » ci-dessus. Rendu des deux peaux : `rich-text.test.tsx`.
 */
describe("ReleaseNotesSection — corps riche en peau console", () => {
  const BLUE_DOC = {
    type: "doc",
    content: [{ type: "paragraph", content: [{ type: "text", text: "Export PDF", marks: [{ type: "bold" }, { type: "noteColor", attrs: { name: "bleu" } }] }] }],
  };

  beforeEach(() => {
    mockList.mockReset();
    mockCreate.mockReset();
    useToastStore.setState({ toasts: [] });
    useAdminStore.getState().setSession({ id: "sa", email: "sa@x" }, "csrf-token");
  });

  it("une ligne de note riche rend son gras bleu en text-console-info, sans jeton de l'app", async () => {
    mockList.mockResolvedValue({ items: [{ ...PUBLISHED, body: "Export PDF", bodyRich: BLUE_DOC } as AdminReleaseNote] });
    renderWithProviders(<ReleaseNotesSection />);

    const row = (await screen.findByText(PUBLISHED.title)).closest("li") as HTMLElement;
    const word = within(row).getByText("Export PDF");
    expect(word.closest("strong")).not.toBeNull();
    expect(word.closest(".text-console-info")).not.toBeNull();
    expect(row.querySelector(".text-info")).toBeNull();
  });

  it("l'aperçu rend ce que l'éditeur produit, en peau console", async () => {
    const user = userEvent.setup();
    mockList.mockResolvedValue({ items: [] });
    renderWithProviders(<ReleaseNotesSection />);

    await user.type(await screen.findByLabelText(ADMIN_SECTIONS_TEXTS.releaseNotesBodyLabel), "Texte de l'aperçu");

    const preview = screen.getByRole("region", { name: ADMIN_SECTIONS_TEXTS.releaseNotesPreview });
    expect(within(preview).getByText("Texte de l'aperçu")).toBeInTheDocument();
    expect(preview.querySelector(".text-foreground, .text-muted-foreground")).toBeNull();
  });
});
