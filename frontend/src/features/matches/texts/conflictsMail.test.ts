import { describe, expect, it } from "vitest";

import { MATCHES_CONFLICTS_MAIL_TEXTS as MAIL } from "./conflictsMail";

/**
 * Les phrases de l'envoi aux coachs, mot pour mot telles que validées par le fondateur (plan
 * « Envoyer les conflits aux coachs », §4) : ce fichier est la SEULE preuve de leur libellé et de
 * leurs accords (singulier/pluriel par segment). Les tests d'écran (`ConflictsPage.test.tsx`)
 * n'assertent que les clés — ils restent verts quand une valeur bouge ; celui-ci rougit.
 */
describe("MATCHES_CONFLICTS_MAIL_TEXTS — la ligne « Envoyé le … »", () => {
  const base = { date: "03/11/2026", time: "14:32" };

  it.each([
    [{ sender: "Nadia", mailedCount: 1, skippedNames: [] }, "Envoyé le 03/11/2026 à 14:32 par Nadia — 1 e-mail"],
    [{ sender: "Nadia", mailedCount: 7, skippedNames: [] }, "Envoyé le 03/11/2026 à 14:32 par Nadia — 7 e-mails"],
    [{ sender: null, mailedCount: 7, skippedNames: [] }, "Envoyé le 03/11/2026 à 14:32 — 7 e-mails"],
    [{ sender: "Nadia", mailedCount: 7, skippedNames: ["Karim B."] }, "Envoyé le 03/11/2026 à 14:32 par Nadia — 7 e-mails · 1 coach sans adresse à cet envoi : Karim B."],
    [{ sender: "Nadia", mailedCount: 7, skippedNames: ["Karim B.", "Emma D."] }, "Envoyé le 03/11/2026 à 14:32 par Nadia — 7 e-mails · 2 coachs sans adresse à cet envoi : Karim B., Emma D."],
  ])("%o", (args, expected) => {
    expect(MAIL.statusLine({ ...base, ...args })).toBe(expected);
  });

  it("un coach supprimé depuis est dit « un coach retiré »", () => {
    expect(MAIL.coachRemoved).toBe("un coach retiré");
  });
});

describe("MATCHES_CONFLICTS_MAIL_TEXTS — le toast du résultat (accord par segment)", () => {
  it.each([
    [{ mailedCount: 1, skippedNames: [], failedNames: [] }, "1 e-mail envoyé."],
    [{ mailedCount: 7, skippedNames: [], failedNames: [] }, "7 e-mails envoyés."],
    [{ mailedCount: 7, skippedNames: ["Karim B."], failedNames: [] }, "7 e-mails envoyés · 1 coach sans adresse : Karim B."],
    [{ mailedCount: 7, skippedNames: ["Karim B.", "Emma D."], failedNames: [] }, "7 e-mails envoyés · 2 coachs sans adresse : Karim B., Emma D."],
    [{ mailedCount: 7, skippedNames: [], failedNames: ["Léa R."] }, "7 e-mails envoyés · 1 e-mail non parti : Léa R."],
    [{ mailedCount: 7, skippedNames: [], failedNames: ["Léa R.", "Emma D."] }, "7 e-mails envoyés · 2 e-mails non partis : Léa R., Emma D."],
    [{ mailedCount: 0, skippedNames: ["Karim B.", "Emma D."], failedNames: [] }, "Aucun e-mail envoyé · 2 coachs sans adresse : Karim B., Emma D."],
    [{ mailedCount: 0, skippedNames: [], failedNames: ["Léa R."] }, "Aucun e-mail envoyé · 1 e-mail non parti : Léa R."],
  ])("%o", (args, expected) => {
    expect(MAIL.toastResult(args)).toBe(expected);
  });
});

describe("MATCHES_CONFLICTS_MAIL_TEXTS — bouton, raisons, confirmation", () => {
  it("porte les libellés du plan", () => {
    expect(MAIL.sendButton).toBe("Envoyer aux coachs");
    expect(MAIL.sending).toBe("Envoi…");
    expect(MAIL.disabledNothingToSend).toBe("Aucun conflit à envoyer et aucun coach à prévenir.");
    expect(MAIL.disabledDemo).toBe("Envoi d'e-mails indisponible en démonstration.");
    expect(MAIL.disabledArchived).toBe("Cette saison est archivée — elle est en lecture seule.");
    expect(MAIL.disabledUnavailable).toBe("Envoi indisponible pour le moment.");
    expect(MAIL.confirmTitle).toBe("Envoyer aux coachs ?");
    expect(MAIL.confirmAction).toBe("Envoyer");
    expect(MAIL.confirmCancel).toBe("Annuler");
  });

  // Libellés PROPOSÉS par TANK (retour SWITCH, absents du plan §4) — à valider par le fondateur ;
  // NEO pose ces valeurs, un changement de mot ne touche que ce fichier.
  it.each([
    [{ coachCount: 1, conflictCount: 1 }, "1 coach recevra un e-mail · 1 conflit de personne."],
    [{ coachCount: 4, conflictCount: 7 }, "4 coachs recevront un e-mail · 7 conflits de personnes."],
    [{ coachCount: 2, conflictCount: 0 }, "2 coachs recevront un e-mail · 0 conflit de personne."],
  ])("la confirmation compte coachs et conflits, accord par segment : %o", (args, expected) => {
    expect(MAIL.confirmCounts(args)).toBe(expected);
  });

  it("la confirmation dit que tous les conflits de personnes partent, quels que soient les filtres", () => {
    expect(MAIL.confirmIgnoresFilters).toBe("Les filtres de l'écran ne comptent pas : la liste ci-dessous est ce qui partira.");
  });

  it("le corps de la confirmation nomme la saison", () => {
    expect(MAIL.confirmBody("2026-2027")).toBe(
      "Saison 2026-2027. Chaque coach concerné recevra par e-mail, à titre indicatif, les conflits de personnes sur un match, datés d'aujourd'hui ou à venir, qui le touchent. Par défaut, seuls les coachs qui ont un conflit nouveau ou modifié depuis le dernier envoi reçoivent leur liste complète, nouveautés marquées ; un coach informé précédemment et qui n'a plus de conflit reçoit un e-mail le disant. Les autres familles de conflits ne sont pas envoyées.",
    );
  });
});
