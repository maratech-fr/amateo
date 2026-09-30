import { fireEvent, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { HelpButton } from "@/shared/components/ui/help-button";
import { renderWithProviders } from "@/test/utils";

import { MATCHES_HELP_LABEL, MATCHES_HELP_TRIGGER, MATCHES_TAB_HELP, activeMatchesTab } from "./screenHelp";

describe("activeMatchesTab — l'onglet actif dérivé du chemin", () => {
  it("mappe chaque route sur son onglet, la racine /matchs sur le Calendrier", () => {
    expect(activeMatchesTab("/matchs")).toBe("calendrier");
    expect(activeMatchesTab("/matchs/conflits")).toBe("conflits");
    expect(activeMatchesTab("/matchs/importer")).toBe("importer");
    expect(activeMatchesTab("/matchs/configuration")).toBe("configuration");
    expect(activeMatchesTab("/matchs/adversaires")).toBe("adversaires");
    expect(activeMatchesTab("/matchs/semaine-type")).toBe("semaine-type");
    expect(activeMatchesTab("/matchs/contraintes")).toBe("contraintes");
  });
});

describe("MATCHES_TAB_HELP — l'aide (i) de chaque écran Matchs", () => {
  it("porte les 7 onglets", () => {
    expect(Object.keys(MATCHES_TAB_HELP)).toHaveLength(7);
  });

  it("le bouton (i) ouvre l'aide du Calendrier avec « Placer automatiquement »", () => {
    renderWithProviders(
      <HelpButton label={MATCHES_HELP_LABEL} triggerLabel={MATCHES_HELP_TRIGGER}>
        {MATCHES_TAB_HELP.calendrier}
      </HelpButton>,
    );
    expect(screen.queryByText(MATCHES_HELP_LABEL)).toBeNull();
    fireEvent.click(screen.getByLabelText(MATCHES_HELP_TRIGGER));
    expect(screen.getByText(MATCHES_HELP_LABEL)).toBeInTheDocument();
    expect(screen.getByText(/Placer automatiquement/)).toBeInTheDocument();
  });

  it("l'aide d'Importer dit que réimporter n'écrase pas les placements", () => {
    renderWithProviders(
      <HelpButton label={MATCHES_HELP_LABEL} triggerLabel={MATCHES_HELP_TRIGGER}>
        {MATCHES_TAB_HELP.importer}
      </HelpButton>,
    );
    fireEvent.click(screen.getByLabelText(MATCHES_HELP_TRIGGER));
    expect(screen.getByText(/sans écraser ce que vous avez déjà placé/)).toBeInTheDocument();
  });
});
