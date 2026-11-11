import { describe, expect, it } from "vitest";

import * as deviationConsequence from "./deviationConsequence";
import { FIELD_LABEL, fieldConsequence } from "./deviationConsequence";

/**
 * La CONSÉQUENCE d'un choix est de la PRÉSENTATION (le back décide vraiment). On épingle
 * ici, mot pour mot, ce que l'écran promet AVANT que le gestionnaire ne tranche : prendre
 * le fichier écrit la valeur de FBI et garde le statut — seul « terrain neutre » fait
 * quitter le créneau. Plus aucun avertissement « repassera à Placé ».
 */
describe("deviationConsequence (présentation pure)", () => {
  it("prendre la date du fichier change la date et garde gymnase, heure et statut", () => {
    expect(fieldConsequence("date").takeFile).toBe("Prendre la date du fichier change la date du match ; il garde son gymnase, son heure et son statut.");
  });

  it("prendre la salle du fichier met le match dans le gymnase choisi ; seul « terrain neutre » le fait quitter son créneau", () => {
    expect(fieldConsequence("venue").takeFile).toBe(
      "Prendre la salle du fichier met le match dans le gymnase choisi ; il garde sa date, son heure et son statut. Avec « terrain neutre », il quitte son créneau.",
    );
  });

  it("prendre l'heure du fichier garde le placement (inchangé)", () => {
    expect(fieldConsequence("kickoff").takeFile).toBe("Prendre l'heure du fichier garde le placement (le créneau reste).");
  });

  it("garder l'app n'écrit rien et la question revient au prochain dépôt (inchangé)", () => {
    const keepApp = "Rien n'est écrit — la rencontre est traitée avec la valeur d'Amateo ; si le prochain dépôt diverge encore, l'écart reviendra ici.";
    expect(fieldConsequence("date").keepApp).toBe(keepApp);
    expect(fieldConsequence("kickoff").keepApp).toBe(keepApp);
    expect(fieldConsequence("venue").keepApp).toBe(keepApp);
  });

  it("une conséquence ne porte que ses deux textes : plus de « libère le créneau »", () => {
    expect(Object.keys(fieldConsequence("date")).sort()).toEqual(["keepApp", "takeFile"]);
  });

  it("l'avertissement « saisi dans FBI » n'existe plus", () => {
    expect(Object.keys(deviationConsequence).sort()).toEqual(["FIELD_LABEL", "fieldConsequence"]);
  });

  it("FIELD_LABEL est une TABLE FR (jamais un code montré)", () => {
    expect(FIELD_LABEL).toEqual({ date: "Date", kickoff: "Heure", venue: "Salle" });
  });
});
