/**
 * WCAG 2.2 AA guardrail (structural). Renders shared primitives + the Modal and
 * asserts axe-core finds no violations. Runs on every `vitest run`, so any
 * frontend change that regresses accessibility fails here. Component-specific
 * a11y (MonthCalendar emoji alternatives, WeekGrid venue-as-text) is asserted in
 * those components' own co-located tests, where the render fixtures already live.
 *
 * jsdom has no layout engine → axe skips colour-contrast (WCAG 1.4.3); that axis
 * is enforced by the Playwright/axe pass (follow-up) alongside the contrast fixes.
 */
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { ForbiddenPage } from "@/app/ForbiddenPage";
import { NotFoundPage } from "@/app/NotFoundPage";
import { OfflineScreen } from "@/app/OfflineScreen";
import { ServerErrorScreen } from "@/app/ServerErrorScreen";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";
import { SystemScreen } from "@/shared/components/ui/system-screen";
import { WishRecap } from "@/features/coach-wishes/WishRecap";
import { WishTeamStep } from "@/features/coach-wishes/WishTeamStep";
import { sectionKey, type SectionState } from "@/features/coach-wishes/wishSections";

import { expectNoA11yViolations } from "./utils";

// Fixture: la page publique de doléances est le cas MOBILE (coach sans login).
const wishSections = (over: Partial<SectionState> = {}): Map<string, SectionState> => new Map([[sectionKey("t1", "2026-02-16"), { slotsWanted: 2, days: new Set([3]), wishedDays: new Set([2]), comment: "note", ...over }]]);

describe("a11y — shared UI primitives", () => {
  it("Button has no violations", async () => {
    await expectNoA11yViolations(<Button>Enregistrer</Button>);
  });

  it("labelled Input has no violations", async () => {
    await expectNoA11yViolations(
      <>
        <label htmlFor="team-name">Nom de l'équipe</label>
        <Input id="team-name" />
      </>,
    );
  });

  it("labelled Select has no violations", async () => {
    await expectNoA11yViolations(
      <Select aria-label="Gymnase">
        <option value="a">Armand</option>
        <option value="b">Mateo</option>
      </Select>,
    );
  });

  it("ConfirmDialog (open) has no violations", async () => {
    await expectNoA11yViolations(
      <ConfirmDialog open title="Supprimer cette contrainte ?" description="Action définitive." onConfirm={() => {}} onCancel={() => {}} />,
    );
  });

  it("ConfirmDialog focuses the panel (not the destructive button) and Escape cancels", async () => {
    const onConfirm = vi.fn();
    const onCancel = vi.fn();
    render(<ConfirmDialog open title="Supprimer ?" onConfirm={onConfirm} onCancel={onCancel} />);

    // Initial focus is the neutral panel, so a stray Enter never fires the
    // destructive action (the removed autoFocus was on the confirm button).
    expect(screen.getByRole("dialog")).toHaveFocus();
    await userEvent.keyboard("{Enter}");
    expect(onConfirm).not.toHaveBeenCalled();

    await userEvent.keyboard("{Escape}");
    expect(onCancel).toHaveBeenCalledOnce();
  });
});

describe("a11y — étapes de doléances (cas public mobile)", () => {
  it("WishTeamStep (une équipe × ses semaines) has no violations", async () => {
    await expectNoA11yViolations(
      <WishTeamStep
        team={{ id: "t1", name: "SM1" }}
        weeks={["2026-02-16"]}
        sections={wishSections()}
        partnerTeams={[{ id: "t2", name: "SF1" }]}
        teamLinks={[]}
        mutualization={{ partnerTeamIds: new Set(), sharedSlots: 1 }}
        onPatch={() => {}}
        onToggleDay={() => {}}
        onToggleWishedDay={() => {}}
        onTogglePartner={() => {}}
        onSharedSlots={() => {}}
      />,
    );
  });

  it("WishRecap (récapitulatif avant envoi) has no violations", async () => {
    await expectNoA11yViolations(
      <WishRecap
        teams={[
          { id: "t1", name: "SM1" },
          { id: "t2", name: "U13" },
        ]}
        weeks={["2026-02-16"]}
        sections={wishSections({ slotsWanted: 4 })}
        initial={wishSections()}
        mutualizations={new Map()}
        partnerName={new Map()}
        onEditTeam={() => {}}
      />,
    );
  });
});

describe("a11y — Modal focus management (WCAG 2.1.2 / 2.4.3)", () => {
  it("has no violations, moves focus into the panel, and closes on Escape", async () => {
    const onClose = vi.fn();
    render(
      <Modal label="Boîte de test" title="Titre" onClose={onClose}>
        <button type="button">Action</button>
      </Modal>,
    );

    const dialog = screen.getByRole("dialog");
    // Focus is moved into the dialog on open (neutral panel entry point).
    expect(dialog).toHaveFocus();
    // Modal portals to document.body, so scan the whole document.
    expect(await axe(document.body)).toHaveNoViolations();

    await userEvent.keyboard("{Escape}");
    expect(onClose).toHaveBeenCalledOnce();
  });

  it("Escape on a nested ConfirmDialog does not also close the surrounding Modal (review C1)", async () => {
    const onModalClose = vi.fn();
    const onConfirmCancel = vi.fn();
    render(
      <Modal label="Extérieur" title="Extérieur" onClose={onModalClose}>
        <button type="button">Contenu</button>
        <ConfirmDialog open title="Confirmer ?" onConfirm={() => {}} onCancel={onConfirmCancel} />
      </Modal>,
    );

    // Put focus on the inner confirm (its real state when stacked on the modal).
    // Escape must dismiss only it (panel-scoped listener), leaving the outer modal
    // open — a document-level listener fired both.
    screen.getByRole("dialog", { name: "Confirmer ?" }).focus();
    await userEvent.keyboard("{Escape}");
    expect(onConfirmCancel).toHaveBeenCalledOnce();
    expect(onModalClose).not.toHaveBeenCalled();
  });

  it("restores focus to the trigger element on close (WCAG 2.4.3)", () => {
    // The element focused when the modal opens is the one focus returns to on close.
    const trigger = document.createElement("button");
    trigger.textContent = "Trigger";
    document.body.appendChild(trigger);
    trigger.focus();
    expect(trigger).toHaveFocus();

    const { unmount } = render(
      <Modal label="Boîte" title="Titre" onClose={() => {}}>
        <button type="button">Action</button>
      </Modal>,
    );
    expect(screen.getByRole("dialog")).toHaveFocus();

    unmount();
    expect(trigger).toHaveFocus();
    trigger.remove();
  });
});

describe("a11y — écrans système (P5-14)", () => {
  it("SystemScreen (primitive, avec code incident) has no violations", async () => {
    await expectNoA11yViolations(
      <SystemScreen
        title="Arrêt de jeu imprévu."
        primaryAction={{ label: "Réessayer", onClick: () => {} }}
        secondaryAction={{ label: "Retour à l'accueil", onClick: () => {} }}
        incidentId="11111111-2222-4333-8444-555566667777"
      >
        Une erreur inattendue s'est produite de notre côté.
      </SystemScreen>,
    );
  });

  it("NotFoundPage (404) has no violations", async () => {
    await expectNoA11yViolations(<NotFoundPage />);
  });

  it("ForbiddenPage (403) has no violations", async () => {
    await expectNoA11yViolations(<ForbiddenPage />);
  });

  it("OfflineScreen (hors-ligne pleine page) has no violations", async () => {
    await expectNoA11yViolations(<OfflineScreen onRetry={() => {}} />);
  });

  it("ServerErrorScreen (500) has no violations", async () => {
    await expectNoA11yViolations(<ServerErrorScreen onRetry={() => {}} />);
  });
});
