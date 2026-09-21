import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Coach, Conflict, ConflictResolution, Team, Venue } from "./api";
import * as matchesApi from "./api";
import { ConflictResolutionControl } from "./ConflictResolutionControl";

vi.mock("./api", () => ({
  putConflictResolution: vi.fn(() => Promise.resolve({ fingerprint: "fp-1", resolution: { status: "DEROGATION_REQUESTED", note: null, updatedAt: "2026-10-03T20:45:00+02:00" } })),
  deleteConflictResolution: vi.fn(() => Promise.resolve(undefined)),
}));

const teams = new Map<string, Team>([
  ["team-1", { id: "team-1", name: "U13", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["team-2", { id: "team-2", name: "Seniors", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
]);
const coaches = new Map<string, Coach>();
const venues = new Map<string, Venue>();

function side(fixtureId: string, teamId: string) {
  return { fixtureId, teamId, homeAway: "HOME" as const, matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" };
}

function conflictWith(resolution: ConflictResolution | null, fingerprint: string | undefined = "fp-1"): Conflict {
  return { type: "VENUE_OVERLAP", severity: 1, fingerprint, resolution, left: side("fx-1", "team-1"), right: side("fx-2", "team-2") };
}

const resolved = (status: ConflictResolution["status"], note: string | null = null): ConflictResolution => ({ status, note, updatedAt: "2026-10-03T20:45:00+02:00" });

function renderControl(conflict: Conflict, canManage = true) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const wrap = (c: Conflict) => (
    <QueryClientProvider client={client}>
      <ul>
        <ConflictResolutionControl conflict={c} teams={teams} coaches={coaches} venues={venues} tone="destructive" isNew={false} canManage={canManage} />
      </ul>
    </QueryClientProvider>
  );
  const result = render(wrap(conflict));
  // Simule le refetch qui suit une écriture : le même arbre re-rendu avec le nouveau conflit.
  return { ...result, rerenderWith: (c: Conflict) => result.rerender(wrap(c)) };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("ConflictResolutionControl — à traiter (gestionnaire)", () => {
  it("montre « Traiter » ; le menu porte les 3 statuts ; choisir écrit (PUT immédiat, note omise)", async () => {
    const user = userEvent.setup();
    renderControl(conflictWith(null));
    await user.click(screen.getByRole("button", { name: "Traiter le conflit" }));
    for (const label of ["Dérogation demandée", "Réglé en interne", "Sans solution pour l'instant"]) {
      expect(screen.getByRole("menuitem", { name: label })).toBeInTheDocument();
    }
    await user.click(screen.getByRole("menuitem", { name: "Réglé en interne" }));
    expect(matchesApi.putConflictResolution).toHaveBeenCalledWith("fp-1", { status: "RESOLVED_INTERNALLY", note: undefined });
  });

  it("« à traiter » : ni « Modifier la note… » ni « Remettre à traiter »", async () => {
    const user = userEvent.setup();
    renderControl(conflictWith(null));
    await user.click(screen.getByRole("button", { name: "Traiter le conflit" }));
    expect(screen.queryByRole("menuitem", { name: "Modifier la note…" })).not.toBeInTheDocument();
    expect(screen.queryByRole("menuitem", { name: "Remettre à traiter" })).not.toBeInTheDocument();
  });
});

describe("ConflictResolutionControl — statuts « joue/coache » (proposés seulement si la personne joue)", () => {
  const sideRole = (fixtureId: string, teamId: string, role: "MAIN" | "ASSISTANT" | "PLAYER") => ({ ...side(fixtureId, teamId), role });

  it("PROPOSE « Coache, ne joue pas » / « Joue, ne coache pas » quand un côté servi porte PLAYER", async () => {
    const user = userEvent.setup();
    const conflict: Conflict = { type: "MATCH_MATCH", severity: 3, fingerprint: "fp-1", resolution: null, left: sideRole("fx-1", "team-1", "MAIN"), right: sideRole("fx-2", "team-2", "PLAYER") };
    renderControl(conflict);
    await user.click(screen.getByRole("button", { name: "Traiter le conflit" }));
    expect(screen.getByRole("menuitem", { name: "Coache, ne joue pas" })).toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "Joue, ne coache pas" })).toBeInTheDocument();
  });

  it("N'AFFICHE PAS ces 2 statuts quand aucun côté ne joue (que des coachs)", async () => {
    const user = userEvent.setup();
    const conflict: Conflict = { type: "MATCH_MATCH", severity: 3, fingerprint: "fp-1", resolution: null, left: sideRole("fx-1", "team-1", "MAIN"), right: sideRole("fx-2", "team-2", "ASSISTANT") };
    renderControl(conflict);
    await user.click(screen.getByRole("button", { name: "Traiter le conflit" }));
    expect(screen.queryByRole("menuitem", { name: "Coache, ne joue pas" })).not.toBeInTheDocument();
    expect(screen.queryByRole("menuitem", { name: "Joue, ne coache pas" })).not.toBeInTheDocument();
  });
});

describe("ConflictResolutionControl — erreur FBI (dialogue avant écriture, lot N)", () => {
  it("une collision de gymnase PROPOSE « Erreur FBI » et « Match à déplacer »", async () => {
    const user = userEvent.setup();
    renderControl(conflictWith(null));
    await user.click(screen.getByRole("button", { name: "Traiter le conflit" }));
    expect(screen.getByRole("menuitem", { name: "Erreur FBI" })).toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "Match à déplacer" })).toBeInTheDocument();
  });

  it("« Erreur FBI » ouvre un dialogue SANS écrire ; confirmer PUT avec le complément (rencontre + champ)", async () => {
    const user = userEvent.setup();
    renderControl(conflictWith(null));
    await user.click(screen.getByRole("button", { name: "Traiter le conflit" }));
    await user.click(screen.getByRole("menuitem", { name: "Erreur FBI" }));
    // Le dialogue s'ouvre ; AUCUN PUT tant qu'on n'a pas confirmé (contrairement aux autres statuts).
    expect(screen.getByRole("dialog", { name: "Déclarer une erreur FBI" })).toBeInTheDocument();
    expect(matchesApi.putConflictResolution).not.toHaveBeenCalled();
    // Confirmer avec les défauts : première rencontre (fx-1), champ « salle » (venue).
    await user.click(screen.getByRole("button", { name: "Déclarer l'erreur FBI" }));
    expect(matchesApi.putConflictResolution).toHaveBeenCalledWith("fp-1", { status: "FBI_ERROR", note: undefined, fbiCorrection: { fixtureId: "fx-1", field: "venue" } });
  });

  it("« Match à déplacer » écrit immédiatement (pas de dialogue)", async () => {
    const user = userEvent.setup();
    renderControl(conflictWith(null));
    await user.click(screen.getByRole("button", { name: "Traiter le conflit" }));
    await user.click(screen.getByRole("menuitem", { name: "Match à déplacer" }));
    expect(screen.queryByRole("dialog", { name: "Déclarer une erreur FBI" })).not.toBeInTheDocument();
    expect(matchesApi.putConflictResolution).toHaveBeenCalledWith("fp-1", { status: "MATCH_TO_MOVE", note: undefined });
  });
});

describe("ConflictResolutionControl — annoté (gestionnaire)", () => {
  it("la pastille EST le déclencheur (nom accessible = statut + date) ; changer de statut resservit la note", async () => {
    const user = userEvent.setup();
    renderControl(conflictWith(resolved("RESOLVED_INTERNALLY", "vu ensemble")));
    const trigger = screen.getByRole("button", { name: /Statut de traitement : Réglé en interne .*modifier/ });
    await user.click(trigger);
    // Le statut courant est désactivé (patron SeasonSelector) ; on choisit un autre.
    await user.click(screen.getByRole("menuitem", { name: "Dérogation demandée" }));
    // La note existante est RESSERVIE (le PUT est un remplacement plein).
    expect(matchesApi.putConflictResolution).toHaveBeenCalledWith("fp-1", { status: "DEROGATION_REQUESTED", note: "vu ensemble" });
  });

  it("« Modifier la note… » ouvre l'éditeur ; enregistrer PUT le statut inchangé + la note", async () => {
    const user = userEvent.setup();
    renderControl(conflictWith(resolved("DEROGATION_REQUESTED", "")));
    await user.click(screen.getByRole("button", { name: /Statut de traitement/ }));
    await user.click(screen.getByRole("menuitem", { name: "Modifier la note…" }));
    const textarea = await screen.findByRole("textbox", { name: /Note/ });
    await user.type(textarea, "relancé la ligue");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(matchesApi.putConflictResolution).toHaveBeenCalledWith("fp-1", { status: "DEROGATION_REQUESTED", note: "relancé la ligue" });
  });

  it("« Remettre à traiter » SANS note ⇒ DELETE direct (pas de modale)", async () => {
    const user = userEvent.setup();
    renderControl(conflictWith(resolved("NO_SOLUTION_YET", null)));
    await user.click(screen.getByRole("button", { name: /Statut de traitement/ }));
    await user.click(screen.getByRole("menuitem", { name: "Remettre à traiter" }));
    expect(matchesApi.deleteConflictResolution).toHaveBeenCalledWith("fp-1");
  });

  it("après « Remettre à traiter », le focus se pose sur le « Traiter » qui remplace la pastille", async () => {
    const user = userEvent.setup();
    const { rerenderWith } = renderControl(conflictWith(resolved("NO_SOLUTION_YET", null)));
    await user.click(screen.getByRole("button", { name: /Statut de traitement/ }));
    await user.click(screen.getByRole("menuitem", { name: "Remettre à traiter" }));
    await waitFor(() => expect(matchesApi.deleteConflictResolution).toHaveBeenCalled());
    // Le refetch ramène le conflit « à traiter » : la pastille devient le bouton « Traiter ».
    rerenderWith(conflictWith(null));
    await waitFor(() => expect(screen.getByRole("button", { name: "Traiter le conflit" })).toHaveFocus());
  });

  it("« Remettre à traiter » AVEC note ⇒ confirmation destructive avant le DELETE", async () => {
    const user = userEvent.setup();
    renderControl(conflictWith(resolved("NO_SOLUTION_YET", "note importante")));
    await user.click(screen.getByRole("button", { name: /Statut de traitement/ }));
    await user.click(screen.getByRole("menuitem", { name: "Remettre à traiter" }));
    // Pas de DELETE tant que non confirmé.
    expect(matchesApi.deleteConflictResolution).not.toHaveBeenCalled();
    expect(screen.getByText(/effacera la note/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Remettre à traiter" }));
    await waitFor(() => expect(matchesApi.deleteConflictResolution).toHaveBeenCalledWith("fp-1"));
  });
});

describe("ConflictResolutionControl — lecture (membre / sans empreinte)", () => {
  it("membre : pastille lisible, aucun menu ni « Traiter »", () => {
    renderControl(conflictWith(resolved("RESOLVED_INTERNALLY")), false);
    expect(screen.getByText(/Réglé en interne/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /modifier/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Traiter le conflit" })).not.toBeInTheDocument();
  });

  it("membre + à traiter : aucune pastille (rien à afficher)", () => {
    renderControl(conflictWith(null), false);
    expect(screen.queryByRole("button", { name: "Traiter le conflit" })).not.toBeInTheDocument();
    expect(screen.queryByText(/Statut de traitement/)).not.toBeInTheDocument();
  });

  it("gestionnaire SANS empreinte : aucune écriture possible → pas d'éditeur (dégradé lecture)", () => {
    // Empreinte ABSENTE (clé d'écriture) → l'éditeur n'est pas rendu, même pour un gestionnaire.
    const noFingerprint: Conflict = { type: "VENUE_OVERLAP", severity: 1, resolution: resolved("DEROGATION_REQUESTED"), left: side("fx-1", "team-1"), right: side("fx-2", "team-2") };
    renderControl(noFingerprint);
    // La pastille reste lisible, mais ce n'est pas un déclencheur de menu.
    expect(screen.getByText(/Dérogation demandée/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Statut de traitement/ })).not.toBeInTheDocument();
  });

  it("une note existante est lisible via « Note » (déplié)", async () => {
    const user = userEvent.setup();
    renderControl(conflictWith(resolved("RESOLVED_INTERNALLY", "réservé un autre créneau")), false);
    await user.click(screen.getByRole("button", { name: "Note" }));
    expect(screen.getByText("réservé un autre créneau")).toBeInTheDocument();
  });
});

describe("ConflictResolutionControl — actions empilées (décision fondateur 2026-09-17)", () => {
  it("empile « Traiter »/pastille AU-DESSUS de « Voir la semaine » dans une même colonne", () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <ul>
          <ConflictResolutionControl
            conflict={conflictWith(resolved("RESOLVED_INTERNALLY"))}
            teams={teams}
            coaches={coaches}
            venues={venues}
            tone="destructive"
            isNew={false}
            canManage
            extraTrailing={
              <button type="button">Voir la semaine</button>
            }
          />
        </ul>
      </QueryClientProvider>,
    );
    const voir = screen.getByRole("button", { name: "Voir la semaine" });
    const traiter = screen.getByRole("button", { name: /Statut de traitement/ });
    const column = voir.parentElement as HTMLElement;
    // Une seule colonne (flex-col) porte les deux actions, « Traiter »/pastille d'abord.
    expect(column).toHaveClass("flex-col");
    expect(column.contains(traiter)).toBe(true);
    // « Traiter »/pastille précède « Voir la semaine » dans l'ordre du document (empilé au-dessus).
    expect(Boolean(voir.compareDocumentPosition(traiter) & Node.DOCUMENT_POSITION_PRECEDING)).toBe(true);
  });
});
