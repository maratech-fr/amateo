import { beforeEach, describe, expect, it } from "vitest";

import { useAuthStore } from "./authStore";

describe("authStore — SEC-16 (audit) : plus aucun jeton côté client", () => {
  beforeEach(() => {
    useAuthStore.getState().clear();
  });

  it("ne porte qu'un drapeau de session, jamais un jeton", () => {
    useAuthStore.getState().setAuthenticated(true);
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
    // La forme du store EST la garde : aucun champ ne peut contenir un JWT.
    expect(Object.keys(useAuthStore.getState())).not.toContain("token");
    useAuthStore.getState().clear();
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
  });

  it("la migration EFFACE un jeton déjà persisté (le correctif doit atteindre les sessions ouvertes)", () => {
    // Sans ça, le JWT d'un utilisateur connecté avant le déploiement resterait
    // en localStorage — la faille survivrait à sa propre correction.
    const options = useAuthStore.persist.getOptions();
    const migrated = options.migrate?.({ token: "jwt-legacy" }, 1) as unknown as Record<string, unknown>;

    expect(migrated.token).toBeUndefined();
    expect(migrated.isAuthenticated).toBe(true); // la session ouverte le reste : le cookie, lui, vaut ce qu'il vaut
  });

  it("migrate returns a safe default on null persisted state (Zustand 5 null-check)", () => {
    const options = useAuthStore.persist.getOptions();
    const migrated = options.migrate?.(null, 1) as { isAuthenticated: boolean };
    expect(migrated.isAuthenticated).toBe(false);
  });
});
