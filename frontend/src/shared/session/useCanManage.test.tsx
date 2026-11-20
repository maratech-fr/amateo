import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, renderHook } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, describe, expect, it } from "vitest";

import { useAuthStore } from "@/shared/stores/authStore";

import type { MeResponse } from "./api";
import { useCanManage } from "./queries";

/**
 * Promesse (chantier « un Membre lit tout, ne modifie rien », PR 2) : `useCanManage()` est la
 * SEULE question d'affichage « ce compte peut-il modifier ? » — vrai pour une adhésion ACTIVE de
 * gestion (`admin`, miroir `shared/lib/roles.ts`), faux pour un Membre, une adhésion non
 * active ou une session pas encore chargée (on ne propose jamais un geste voué au 403).
 *
 * Le hook vit à côté de `useMe` (même module) : on le prouve sur le VRAI `useMe`, cache `["me"]`
 * pré-rempli (staleTime 60 s → aucun fetch), pas sur un double.
 */
function renderWithMe(me: Partial<MeResponse> | undefined) {
  useAuthStore.setState({ isAuthenticated: true });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  if (undefined !== me) {
    client.setQueryData(["me"], me);
  }
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  return renderHook(() => useCanManage(), { wrapper });
}

describe("useCanManage — la question unique « peut-il modifier ? »", () => {
  afterEach(() => {
    // Démonter AVANT de muter le store : sinon le hook encore monté se re-rend hors act().
    cleanup();
    useAuthStore.setState({ isAuthenticated: false });
  });

  it("un gestionnaire actif (admin) peut modifier", () => {
    expect(renderWithMe({ membershipStatus: "active", role: "admin" }).result.current).toBe(true);
  });

  // Deux rôles seulement : une ancienne valeur `owner` (convertie en base) ne rouvre pas la main.
  // Jumeau au niveau de la lib : `shared/lib/coachName.test.ts` (« rôles de gestion »).
  it("une ancienne valeur owner, même active, ne peut pas modifier", () => {
    expect(renderWithMe({ membershipStatus: "active", role: "owner" }).result.current).toBe(false);
  });

  it("un Membre actif ne peut pas modifier", () => {
    expect(renderWithMe({ membershipStatus: "active", role: "member" }).result.current).toBe(false);
  });

  it("une adhésion non active, même de gestion, ne peut pas modifier", () => {
    expect(renderWithMe({ membershipStatus: "pending", role: "admin" }).result.current).toBe(false);
  });

  it("tant que la session n'est pas chargée, aucun geste n'est proposé", () => {
    useAuthStore.setState({ isAuthenticated: false });
    const client = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
    expect(renderHook(() => useCanManage(), { wrapper }).result.current).toBe(false);
  });
});
