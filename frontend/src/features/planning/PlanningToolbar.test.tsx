import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Schedule, ScheduleCapabilities } from "./api";
import { PlanningToolbar } from "./PlanningToolbar";

const noop = () => {};

// P2-8 : les gestes de la toolbar (Supprimer / Valider / Charger) sont désormais pilotés
// par le bloc `capabilities` SERVEUR, plus par un calcul client. Défaut = une version de
// travail terminée « ordinaire » ; chaque test surcharge le champ qui porte son scénario.
// `capabilities: null` force l'absence (réponse d'écriture / cache périmé → fail-closed).
const DEFAULT_CAPS: ScheduleCapabilities = { canDelete: false, canValidate: true, canRegenerateFrom: true, versionsDeletedOnValidate: 0, overlaysDroppedOnValidate: 0 };
type ScheduleOver = Partial<Omit<Schedule, "capabilities">> & { capabilities?: Partial<ScheduleCapabilities> | null };

const schedule = (status: Schedule["status"], over: ScheduleOver = {}): Schedule => {
  const { capabilities: capsOver, ...rest } = over;
  return { id: "s1", name: "Plan A", status, score: 100, createdAt: "2026-01-01", updatedAt: "2026-01-01", planType: "SEASON", schedulePlanId: "season-plan", generatedTeamCount: 12, hasStructurePhoto: true, isLiveContext: true, capabilities: null === capsOver ? null : { ...DEFAULT_CAPS, ...capsOver }, ...rest };
};

function renderToolbar(
  schedules: Schedule | Schedule[],
  {
    embedded = true,
    selectedScheduleId = "s1",
    disableRegenerate = false,
    slots = false,
    outputCredits = null,
  }: {
    embedded?: boolean;
    selectedScheduleId?: string;
    disableRegenerate?: boolean;
    slots?: boolean;
    outputCredits?: { count: number; blocked: boolean } | null;
  } = {},
) {
  return render(
    <PlanningToolbar
      filterSlot={slots ? <span>FILTRE</span> : undefined}
      rightSlot={slots ? <span>EXPORT</span> : undefined}
      schedules={Array.isArray(schedules) ? schedules : [schedules]}
      selectedScheduleId={selectedScheduleId}
      onSelectSchedule={noop}
      viewMode="gymnase"
      onViewMode={noop}
      onRegenerate={noop}
      onValidate={noop}
      onReopen={noop}
      onDelete={noop}
      onRegenerateFrom={noop}
      disableRegenerate={disableRegenerate}
      outputCredits={outputCredits}
      isGenerating={false}
      actionBusy={false}
      embedded={embedded}
    />,
  );
}

describe("PlanningToolbar — où vivent le filtre et l'export (P4-43)", () => {
  it("place le filtre AVEC le sélecteur de vue (ligne 1), et l'export avec les actions (ligne 2)", () => {
    // Le filtre vivait en ligne 2 derrière l'export, alors que son libellé SUIT le mode de
    // vue courant (« Par gymnase » → « Gymnases : … ») : deux contrôles sur les mêmes trois
    // ressources, à deux endroits éloignés, dont le second passait inaperçu. L'ordre du DOM
    // porte la règle — pas les classes, qu'un ajustement de style ferait rougir pour rien.
    renderToolbar(schedule("COMPLETED"), { slots: true });

    const view = screen.getByRole("button", { name: "Par gymnase" });
    const filter = screen.getByText("FILTRE");
    const exported = screen.getByText("EXPORT");
    const regenerate = screen.getByRole("button", { name: "Régénérer" });

    // Vue → filtre → actions : le filtre est monté avant la ligne d'actions.
    expect(view.compareDocumentPosition(filter) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(filter.compareDocumentPosition(regenerate) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    // L'export, lui, reste après les actions.
    expect(regenerate.compareDocumentPosition(exported) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });
});

describe("PlanningToolbar — 4e vue « Par jour » (P2-33)", () => {
  it("offre un 4e bouton « Par jour » à côté des trois vues existantes", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("button", { name: "Par gymnase" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Par coach" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Par équipe" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Par jour" })).toBeInTheDocument();
  });

  it("le clic sur « Par jour » bascule la vue vers jour", async () => {
    const user = userEvent.setup();
    const onViewMode = vi.fn();
    render(
      <PlanningToolbar
        schedules={[schedule("COMPLETED")]}
        selectedScheduleId="s1"
        onSelectSchedule={noop}
        viewMode="gymnase"
        onViewMode={onViewMode}
        onRegenerate={noop}
        onValidate={noop}
        onReopen={noop}
        onDelete={noop}
        onRegenerateFrom={noop}
        isGenerating={false}
        actionBusy={false}
        embedded
      />,
    );
    await user.click(screen.getByRole("button", { name: "Par jour" }));
    expect(onViewMode).toHaveBeenCalledWith("jour");
  });
});

describe("PlanningToolbar — coût en crédits sur « Régénérer » (P1-3 §4bis)", () => {
  it("affiche le solde sur « Régénérer » en Découverte bridée", () => {
    renderToolbar(schedule("COMPLETED"), { outputCredits: { count: 8, blocked: false } });
    expect(screen.getByRole("button", { name: "Régénérer (8)" })).toBeEnabled();
  });

  it("désactive « Régénérer (0) » quand le serveur ne laisse plus sortir", () => {
    renderToolbar(schedule("COMPLETED"), { outputCredits: { count: 0, blocked: true } });
    expect(screen.getByRole("button", { name: "Régénérer (0)" })).toBeDisabled();
  });

  it("aucun suffixe ni blocage hors Découverte bridée (outputCredits null)", () => {
    renderToolbar(schedule("COMPLETED"), { outputCredits: null });
    expect(screen.getByRole("button", { name: "Régénérer" })).toBeEnabled();
  });
});

describe("PlanningToolbar — survie des verrous à la régénération", () => {
  it("énonce près de « Régénérer » que les créneaux verrouillés sont conservés", () => {
    renderToolbar(schedule("COMPLETED"));
    // La garantie ne vivait qu'en commentaire de code ; elle est désormais dite à l'écran,
    // exactement là où la régénération se déclenche.
    expect(screen.getByText(/verrouillés sont conservés/i)).toBeInTheDocument();
  });

  it("n'affiche pas la phrase quand « Régénérer » est masqué (version en vigueur, lecture seule)", () => {
    renderToolbar(schedule("COMPLETED", { isChosen: true }));
    expect(screen.queryByText(/verrouillés sont conservés/i)).not.toBeInTheDocument();
  });
});

describe("PlanningToolbar — compteur de verrous manuels (PR 3, déplacé)", () => {
  it("le compteur ne vit PLUS dans la toolbar (retour fondateur : à côté de Diagnostics, dans la page)", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.queryByRole("button", { name: /verrous manuels/i })).not.toBeInTheDocument();
  });
});

describe("PlanningToolbar — schedule lifecycle (N3)", () => {
  it("offers Valider + a Régénérer button on a completed schedule", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("button", { name: /valider/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Régénérer" })).toBeInTheDocument();
    expect(screen.getByText("Terminé")).toBeInTheDocument();
  });

  // D4 (lot 2) — « Valider » passe en accent PLEIN (variant default) : c'est l'action de sortie
  // de l'espace de travail, elle doit se lire comme le bouton principal, même place, même taille.
  it("rend « Valider » en accent plein (variant default)", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("button", { name: /valider/i })).toHaveClass("bg-accent");
  });

  it("hides Rouvrir (it lives on standalone /planning now) and hides Régénérer on the version in force (read-only)", () => {
    // « En vigueur » is the plan's pointer (isChosen), not a status. Nouveau contrat
    // (2026-08-20) : Rouvrir est le geste de l'écran /planning (la version en vigueur),
    // plus celui de la toolbar embarquée du wizard — voir le test standalone plus bas.
    renderToolbar(schedule("COMPLETED", { isChosen: true }));
    expect(screen.queryByRole("button", { name: /rouvrir/i })).not.toBeInTheDocument();
    // The plain "Régénérer" (current structure) is hidden on a read-only version;
    // "Charger cette version" (restore this version's structure) may still show.
    expect(screen.queryByRole("button", { name: "Régénérer" })).not.toBeInTheDocument();
  });

  it("never offers a « Définir principal » action (the plan's pointer is moved by validating, not by a toggle)", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.queryByRole("button", { name: /principal/i })).not.toBeInTheDocument();
  });

  it("stars the loaded-context (isLiveContext) version in the selector", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("option", { name: /★/ })).toBeInTheDocument();
  });

  it("stars exactly ONE version — falls back to the latest when no pointer is set", () => {
    // Neither carries the server pointer → fallback to the latest visible (s2).
    renderToolbar([schedule("COMPLETED", { id: "s1", createdAt: "2026-01-01", isLiveContext: false }), schedule("COMPLETED", { id: "s2", createdAt: "2026-02-01", isLiveContext: false })]);
    expect(screen.getAllByRole("option", { name: /★/ })).toHaveLength(1);
  });

  it("labels the version « V1 — … » and offers no rename control (versions are not renamable)", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("option", { name: /^V1 — / })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /renommer/i })).not.toBeInTheDocument();
  });

  it("offers Supprimer on a plain work version, but not on the one in force", () => {
    // Deux versions : celle qu'on regarde n'est pas la dernière, donc la saison reste
    // ancrée — le serveur la déclare supprimable (canDelete), c'est bien « une version
    // de travail ordinaire » qu'on teste.
    renderToolbar([schedule("COMPLETED", { id: "s1", capabilities: { canDelete: true } }), schedule("COMPLETED", { id: "s2", createdAt: "2026-02-01" })]);
    expect(screen.getByRole("button", { name: /supprimer cette version/i })).toBeInTheDocument();
  });

  it("hides Supprimer on the season's LAST finished version — it anchors the season (server refuses it)", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
  });

  it("hides Valider while a sibling is still solving — the server refuses the whole validation (canValidate:false)", () => {
    renderToolbar([schedule("COMPLETED", { id: "s1", capabilities: { canValidate: false } }), schedule("GENERATING", { id: "s2", createdAt: "2026-02-01" })]);
    expect(screen.queryByRole("button", { name: /valider/i })).not.toBeInTheDocument();
  });

  it("greys « Charger cette version » on the live-context (★) version — reloading it is a no-op", () => {
    // A single schedule is necessarily the live context (fallback to latest).
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("button", { name: /charger cette version/i })).toBeDisabled();
  });

  it("enables « Charger cette version » on a non-live version", () => {
    // s1 (selected) is NOT the loaded context — s2 carries the ★.
    renderToolbar([schedule("COMPLETED", { id: "s1", createdAt: "2026-01-01", isLiveContext: false }), schedule("COMPLETED", { id: "s2", createdAt: "2026-02-01", isLiveContext: true })]);
    expect(screen.getByRole("button", { name: /charger cette version/i })).toBeEnabled();
  });

  it("disables « Régénérer » during a « Charger » restore (actionBusy) without showing « Génération… »", () => {
    render(
      <PlanningToolbar
        schedules={[schedule("COMPLETED")]}
        selectedScheduleId="s1"
        onSelectSchedule={noop}
        viewMode="gymnase"
        onViewMode={noop}
        onRegenerate={noop}
        onValidate={noop}
        onReopen={noop}
        onDelete={noop}
        onRegenerateFrom={noop}
        isGenerating={false}
        actionBusy
        embedded
      />,
    );
    const regen = screen.getByRole("button", { name: "Régénérer" });
    expect(regen).toBeDisabled();
    // The busy label keys on isGenerating (false here), not actionBusy → no false spinner text.
    expect(screen.queryByRole("button", { name: /génération…/i })).not.toBeInTheDocument();
  });

  it("greys « Régénérer » when the selected season version already matches the current structure", () => {
    renderToolbar(schedule("COMPLETED"), { disableRegenerate: true });
    expect(screen.getByRole("button", { name: "Régénérer" })).toBeDisabled();
  });

  it("hides « Charger cette version » on a pre-D2 version with no structure photo (server: canRegenerateFrom:false)", () => {
    renderToolbar(schedule("COMPLETED", { hasStructurePhoto: false, capabilities: { canRegenerateFrom: false } }));
    expect(screen.queryByRole("button", { name: /charger cette version/i })).not.toBeInTheDocument();
  });

  it("hides Supprimer on the version in force — whether the SEASON plan points at it or its own plan does", () => {
    // The season's calendar: /api/me carries that pointer.
    renderToolbar(schedule("COMPLETED", { isChosen: true }));
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
    // A version its OWN plan points at (e.g. a period's overlay in force): the
    // season pointer is elsewhere, so only the per-version isChosen catches it.
    renderToolbar(schedule("COMPLETED", { isChosen: true }));
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
  });

  it("fail-closed : capabilities absent (réponse d'écriture / cache périmé) → aucun geste destructif offert", () => {
    // P2-8 : un geste ne s'offre que sur `capabilities.* === true`. Sans le bloc (POST/PUT
    // rend `null`, ou cache périmé), on n'OFFRE PAS — jamais de défaut permissif : offrir
    // « Supprimer » puis se faire refuser en 409 est pire que ne pas l'offrir.
    renderToolbar(schedule("COMPLETED", { capabilities: null }));
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /valider/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /charger cette version/i })).not.toBeInTheDocument();
  });

  it("standalone /planning (not embedded) hides the version selector but SHOWS the status badge", () => {
    // Nouveau contrat (2026-08-20) : /planning est l'écran de la version EN VIGUEUR. Il
    // porte le badge de statut (et Rouvrir, cf. plus bas) ; seuls les gestes de TRAVAIL —
    // le sélecteur de versions, Valider, Régénérer, Supprimer — restent au wizard. Le
    // sélecteur reste donc masqué, mais le badge, lui, apparaît désormais en standalone.
    renderToolbar(schedule("COMPLETED"), { embedded: false });
    expect(screen.queryByRole("combobox", { name: /version du planning/i })).not.toBeInTheDocument();
    expect(screen.getByText("Terminé")).toBeInTheDocument();
    // View modes remain (consultation still switches gym/coach/team views).
    expect(screen.getByRole("button", { name: "Par coach" })).toBeInTheDocument();
  });

  // NR (axe planning lifecycle) — nouveau contrat (2026-08-20) : /planning autonome est
  // l'écran de consultation de la version en vigueur (badge + Rouvrir), mais les gestes de
  // TRAVAIL — sélecteur, Valider, Régénérer, Supprimer — vivent au wizard (étape
  // Génération). Falsifiable dans les deux sens : ces mêmes gestes RESTENT offerts en
  // embedded (tests ci-dessus), et Rouvrir bascule d'embedded vers standalone.
  it("standalone /planning (not embedded) n'offre NI Valider NI Régénérer sur une version terminée non validée", () => {
    renderToolbar(schedule("COMPLETED"), { embedded: false });
    expect(screen.queryByRole("button", { name: /valider/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Régénérer" })).not.toBeInTheDocument();
  });

  it("standalone /planning (not embedded) OFFRE Rouvrir sur la version en vigueur", () => {
    // La sortie symétrique de Valider : /planning porte Rouvrir, qui mène au wizard étape
    // Génération. Falsification : en embedded, ce même isChosen ne montre PLUS Rouvrir.
    renderToolbar(schedule("COMPLETED", { isChosen: true }), { embedded: false });
    expect(screen.getByRole("button", { name: /rouvrir/i })).toBeInTheDocument();
  });

  it("n'affiche le score du solveur NULLE PART, embedded compris (P4-39)", () => {
    // Décision fondateur : « ça ne sert à rien pour le gestionnaire ». Le mode embedded est
    // celui qui l'affichait — c'est donc LUI qu'il faut interroger : le vérifier en
    // standalone ne prouverait rien, il n'y était déjà pas.
    renderToolbar(schedule("COMPLETED", { score: 9051 }), { embedded: true });

    expect(screen.queryByText(/score/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/9051/)).not.toBeInTheDocument();
    // …et le badge de statut, lui, reste : on retire le score, pas la ligne.
    expect(screen.getByText("Terminé")).toBeInTheDocument();
  });
});
