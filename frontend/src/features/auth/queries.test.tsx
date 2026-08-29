import { QueryClient, QueryClientProvider, useQuery } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { AssignableRole } from "@/shared/lib/roles";
import { useAuthStore } from "@/shared/stores/authStore";

import * as authApi from "./api";
import type { RegisterPayload } from "./api";
import {
  useApproveMember,
  useConfirmEmailChange,
  useDevDemoRegister,
  useLogin,
  useLogout,
  useMembers,
  usePendingMembers,
  useRegister,
  useRegisterConfig,
  useRejectMember,
  useRenamePlanning,
  useVerifyEmail,
} from "./queries";

// FRT-20 (4ᵉ et dernière tranche — axe §7.1 « auth & memberships ») — on n'exerce QUE `queries.ts` :
// le module voisin `./api` est le SEUL double (patron vivant du dépôt, cf. matches/queries.test.tsx &
// planning/queries.test.tsx). Les VRAIS hooks sont montés sur un vrai QueryClient. Pour la classe de
// bug qu'on chasse — une invalidation qui n'ATTEINT PAS le lecteur réel (clé fantôme, mauvais
// préfixe, invalidation MANQUANTE), ou pire ici une FUITE de données entre sessions — un espion sur
// `invalidateQueries`/`clear` ne suffit pas : il prouve l'appel, jamais l'EFFET, et ne voit JAMAIS un
// appel absent. Donc chaque cas monte le lecteur ET la mutation sur le MÊME client, déclenche, et
// asserte que la DONNÉE a bougé (le lecteur REFETCHE, ou le cache est vraiment VIDE).
//
// ⚠ TanStack invalide PAR PRÉFIXE : invalider `['memberships']` atteint `['memberships','pending']`
// ET `['memberships','list']` (préfixe plus court → query plus longue), JAMAIS l'inverse. C'est ce
// sens-là qu'on PROUVE en montant les deux vrais lecteurs de la famille.
//
// `['me']` et `['calendar-entries']` n'ont AUCUN lecteur natif dans ce module → on monte un lecteur
// LOCAL sur la même clé (une vraie query sur le même client). C'est un vrai lecteur, pas un espion :
// s'il ne refetche pas, l'invalidation n'a pas mordu.
vi.mock("./api", () => ({
  login: vi.fn().mockResolvedValue(undefined),
  logout: vi.fn().mockResolvedValue(undefined),
  register: vi.fn().mockResolvedValue({ status: "accepted" }),
  verifyEmail: vi.fn().mockResolvedValue({ membershipStatus: "active", user: { id: "u", email: "e@x" } }),
  devDemoRegister: vi.fn().mockResolvedValue({ membershipStatus: "active", clubId: "c" }),
  confirmEmailChange: vi.fn().mockResolvedValue({ status: "email_confirmed", email: "new@x" }),
  renamePlanning: vi.fn().mockResolvedValue({}),
  registerConfig: vi.fn().mockResolvedValue({ turnstileSiteKey: null, demoShortcut: false, demoEmail: null }),
  getPendingMembers: vi.fn().mockResolvedValue({ members: [] }),
  getMembers: vi.fn().mockResolvedValue({ members: [], deactivated: [] }),
  approveMember: vi.fn().mockResolvedValue({}),
  rejectMember: vi.fn().mockResolvedValue(undefined),
  changeMemberRole: vi.fn().mockResolvedValue({}),
  deactivateMember: vi.fn().mockResolvedValue(undefined),
  reactivateMember: vi.fn().mockResolvedValue(undefined),
  forgotPassword: vi.fn().mockResolvedValue({}),
  resetPassword: vi.fn().mockResolvedValue({}),
}));

// Lecteurs LOCAUX pour les clés sans hook natif dans ce module. Compteurs remis à zéro par
// clearAllMocks (les mockResolvedValue survivent au clear, comme pour le double `./api`).
const meSpy = vi.fn().mockResolvedValue({ id: "me" });
const calSpy = vi.fn().mockResolvedValue([]);
function useMe() {
  return useQuery({ queryKey: ["me"], queryFn: meSpy });
}
function useCalendarEntries() {
  return useQuery({ queryKey: ["calendar-entries"], queryFn: calSpy });
}

function makeClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
}

function wrapperFor(client: QueryClient) {
  return ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

const REGISTER_PAYLOAD: RegisterPayload = {
  email: "a@b.c",
  password: "S3cret!Passw0rd",
  firstName: "A",
  lastName: "B",
  ara: "ARA0001",
  club_name: "Club",
  consent: true,
};

beforeEach(() => {
  vi.clearAllMocks();
  // Le store d'auth est un singleton persisté (localStorage) : on repart d'une session fermée.
  useAuthStore.setState({ isAuthenticated: false });
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. LE CŒUR DE LA TRANCHE — useLogout : ce n'est PAS de la fraîcheur, c'est l'isolation entre
//    utilisateurs. `clear()` + `queryClient.clear()` sont dans un `finally` DÉLIBÉRÉ : un échec
//    réseau ne doit pas laisser les données du club précédent survivre en cache. On le prouve dans
//    les DEUX sens, sur la DONNÉE (le cache réellement vide), pas sur l'appel à `clear`.
// ─────────────────────────────────────────────────────────────────────────────

describe("auth queries — useLogout vide le cache ET l'état d'auth (isolation entre sessions)", () => {
  it("déconnexion nominale → tout le cache du club courant disparaît + la session se ferme", async () => {
    useAuthStore.setState({ isAuthenticated: true });
    const client = makeClient();
    // De la donnée du club A en cache (les deux surfaces : /me et la liste des membres).
    client.setQueryData(["me"], { id: "u-club-A", clubId: "A" });
    client.setQueryData(["memberships", "list"], { members: [{ email: "coach@club-A" }] });
    const { result } = renderHook(() => useLogout(), { wrapper: wrapperFor(client) });
    expect(client.getQueryData(["me"])).toBeDefined();

    await result.current();

    // Preuve d'EFFET, pas d'appel : la donnée du club A n'existe PLUS nulle part.
    expect(client.getQueryData(["me"])).toBeUndefined();
    expect(client.getQueryData(["memberships", "list"])).toBeUndefined();
    expect(client.getQueryCache().getAll()).toHaveLength(0);
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(authApi.logout).toHaveBeenCalledTimes(1);
  });

  it("authApi.logout() REJETTE → le cache est vidé QUAND MÊME (finally) : aucune fuite vers la session suivante", async () => {
    // Si ce `finally` sautait un jour (refactor onSuccess/onError), les données du club précédent
    // survivraient en cache pour l'utilisateur suivant. C'est une régression de SÉCURITÉ, pas d'UI.
    vi.mocked(authApi.logout).mockRejectedValueOnce(new Error("réseau KO"));
    useAuthStore.setState({ isAuthenticated: true });
    const client = makeClient();
    client.setQueryData(["me"], { id: "u-club-A", clubId: "A" });
    client.setQueryData(["memberships", "list"], { members: [{ email: "coach@club-A" }] });
    const { result } = renderHook(() => useLogout(), { wrapper: wrapperFor(client) });

    // L'appel serveur échoue mais le hook AVALE l'erreur (catch) : la promesse résout quand même.
    await expect(result.current()).resolves.toBeUndefined();

    expect(client.getQueryData(["me"])).toBeUndefined();
    expect(client.getQueryData(["memberships", "list"])).toBeUndefined();
    expect(client.getQueryCache().getAll()).toHaveLength(0);
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. EFFET RÉEL — la famille `['memberships']` : le foyer commun `useMembershipMutation` PROMET
//    (docblock) que les DEUX vues se rafraîchissent — celle EN ATTENTE et celle des ACTIFS — pas
//    seulement celle d'où part le geste. On monte les DEUX vrais lecteurs et on prouve que
//    l'invalidation `['memberships']` (préfixe) atteint les deux.
// ─────────────────────────────────────────────────────────────────────────────

describe("auth queries — la promesse du foyer ['memberships'] : les deux vues se rafraîchissent", () => {
  it("useApproveMember → la vue EN ATTENTE ['memberships','pending'] ET la vue ACTIFS ['memberships','list'] refetchent (préfixe)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ pending: usePendingMembers(true), list: useMembers(true), approve: useApproveMember() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.pending.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.list.isSuccess).toBe(true));
    expect(authApi.getPendingMembers).toHaveBeenCalledTimes(1);
    expect(authApi.getMembers).toHaveBeenCalledTimes(1);

    const role: AssignableRole = "admin";
    result.current.approve.mutate({ id: "m1", role });

    await waitFor(() => expect(result.current.approve.isSuccess).toBe(true));
    // Le geste part de la vue EN ATTENTE, mais l'adhésion approuvée bascule vers les ACTIFS :
    // les DEUX lecteurs DOIVENT refetcher. Si l'une des deux clés n'était pas préfixée par
    // ['memberships'], elle resterait bloquée à 1 — le défaut exact de forme de la 2ᵉ tranche.
    await waitFor(() => expect(authApi.getPendingMembers).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(authApi.getMembers).toHaveBeenCalledTimes(2));
    expect(authApi.approveMember).toHaveBeenCalledWith("m1", role);
  });

  it("useRejectMember → même foyer : les deux vues refetchent aussi (le geste ne rafraîchit pas que la sienne)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ pending: usePendingMembers(true), list: useMembers(true), reject: useRejectMember() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.pending.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.list.isSuccess).toBe(true));

    result.current.reject.mutate("m2");

    await waitFor(() => expect(result.current.reject.isSuccess).toBe(true));
    expect(authApi.rejectMember).toHaveBeenCalledWith("m2");
    await waitFor(() => expect(authApi.getPendingMembers).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(authApi.getMembers).toHaveBeenCalledTimes(2));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. EFFET RÉEL — les entrées de session invalident `['me']`. On monte un lecteur LOCAL sur ['me']
//    et on prouve qu'il refetche, ET que le drapeau d'UI passe à « session ouverte ». On distingue
//    ce qui est touché de ce qui ne l'est pas (useLogin NE touche PAS ['calendar-entries'] — vérifié
//    au code l.47-50 ; seul useRenamePlanning le fait, cas plus bas).
// ─────────────────────────────────────────────────────────────────────────────

describe("auth queries — les entrées de session marquent la session ET rafraîchissent ['me']", () => {
  it("useLogin → ['me'] refetche et la session s'ouvre ; ['calendar-entries'] NE bouge PAS", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ me: useMe(), cal: useCalendarEntries(), login: useLogin() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.me.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.cal.isSuccess).toBe(true));
    expect(meSpy).toHaveBeenCalledTimes(1);
    expect(calSpy).toHaveBeenCalledTimes(1);
    expect(useAuthStore.getState().isAuthenticated).toBe(false);

    result.current.login.mutate({ email: "a@b.c", password: "x" });

    await waitFor(() => expect(result.current.login.isSuccess).toBe(true));
    // SEC-16 : indice d'UI « une session est ouverte » + relecture de /me (le /me pré-login a pu
    // être un 401 mis en cache).
    await waitFor(() => expect(useAuthStore.getState().isAuthenticated).toBe(true));
    await waitFor(() => expect(meSpy).toHaveBeenCalledTimes(2));
    // useLogin n'invalide QUE ['me'] : le calendrier ne refetche pas ici (contraste avec rename).
    await new Promise((r) => setTimeout(r, 40));
    expect(calSpy).toHaveBeenCalledTimes(1);
  });

  it("useVerifyEmail → le lien e-mail ouvre la session (drapeau + ['me'] refetché), et transmet le token", async () => {
    const client = makeClient();
    const { result } = renderHook(() => ({ me: useMe(), verify: useVerifyEmail() }), { wrapper: wrapperFor(client) });

    await waitFor(() => expect(result.current.me.isSuccess).toBe(true));

    result.current.verify.mutate("tok-verify");

    await waitFor(() => expect(result.current.verify.isSuccess).toBe(true));
    // Ce hook passe `authApi.verifyEmail` DIRECTEMENT comme mutationFn : react-query lui ajoute
    // son contexte de mutation en 2ᵉ argument (ignoré par l'API) — d'où le `expect.anything()`.
    expect(authApi.verifyEmail).toHaveBeenCalledWith("tok-verify", expect.anything());
    await waitFor(() => expect(useAuthStore.getState().isAuthenticated).toBe(true));
    await waitFor(() => expect(meSpy).toHaveBeenCalledTimes(2));
  });

  it("useDevDemoRegister → le club démo vient de naître : session ouverte + ['me'] refetché, le corps est transmis", async () => {
    const client = makeClient();
    const { result } = renderHook(() => ({ me: useMe(), demo: useDevDemoRegister() }), { wrapper: wrapperFor(client) });

    await waitFor(() => expect(result.current.me.isSuccess).toBe(true));

    const body = { email: "d@x.y", password: "S3cret!Passw0rd", ara: "ARA9", clubName: "Démo" };
    result.current.demo.mutate(body);

    await waitFor(() => expect(result.current.demo.isSuccess).toBe(true));
    expect(authApi.devDemoRegister).toHaveBeenCalledWith(body, expect.anything());
    await waitFor(() => expect(useAuthStore.getState().isAuthenticated).toBe(true));
    await waitFor(() => expect(meSpy).toHaveBeenCalledTimes(2));
  });

  it("useConfirmEmailChange → le cookie change d'identité : ['me'] (ancienne adresse) refetché + session marquée", async () => {
    // Le serveur repose un cookie frais pour la NOUVELLE adresse : l'ancien ['me'] est faux, il DOIT
    // être relu. C'est le cas le plus subtil — l'identité bascule sans re-login.
    const client = makeClient();
    const { result } = renderHook(() => ({ me: useMe(), confirm: useConfirmEmailChange() }), { wrapper: wrapperFor(client) });

    await waitFor(() => expect(result.current.me.isSuccess).toBe(true));

    result.current.confirm.mutate("tok-email");

    await waitFor(() => expect(result.current.confirm.isSuccess).toBe(true));
    expect(authApi.confirmEmailChange).toHaveBeenCalledWith("tok-email", expect.anything());
    await waitFor(() => expect(useAuthStore.getState().isAuthenticated).toBe(true));
    await waitFor(() => expect(meSpy).toHaveBeenCalledTimes(2));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. EFFET RÉEL (négatif) — useRegister n'AUTHENTIFIE PAS (A3 : « vérifiez vos e-mails »). Il ne
//    marque pas la session et ne relit pas ['me'] — le jeton n'est émis qu'à la vérification du lien.
//    Preuve d'un NON-effet : le lecteur ['me'] RESTE à 1, le drapeau RESTE faux.
// ─────────────────────────────────────────────────────────────────────────────

describe("auth queries — useRegister n'ouvre AUCUNE session (l'auth attend le lien e-mail)", () => {
  it("register réussi → ['me'] NE refetche PAS et la session RESTE fermée", async () => {
    const client = makeClient();
    const { result } = renderHook(() => ({ me: useMe(), register: useRegister() }), { wrapper: wrapperFor(client) });

    await waitFor(() => expect(result.current.me.isSuccess).toBe(true));
    expect(meSpy).toHaveBeenCalledTimes(1);

    result.current.register.mutate(REGISTER_PAYLOAD);

    await waitFor(() => expect(result.current.register.isSuccess).toBe(true));
    expect(authApi.register).toHaveBeenCalledWith(REGISTER_PAYLOAD, expect.anything());
    // Aucune invalidation, aucun setAuthenticated : on laisse une temporisation pour qu'un effet
    // fautif se manifeste, puis on affirme l'immobilité.
    await new Promise((r) => setTimeout(r, 40));
    expect(meSpy).toHaveBeenCalledTimes(1);
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. EFFET RÉEL — useRenamePlanning invalide DEUX caches (ADR-0002 inv. 12) : ['me'] (porte le nom
//    du plan de SAISON) ET ['calendar-entries'] (les plans de PÉRIODE). Contraste direct avec
//    useLogin : ici les DEUX lecteurs locaux refetchent. N'invalider que ['me'] laissait un plan de
//    période renommé afficher son ancien nom pendant le staleTime.
// ─────────────────────────────────────────────────────────────────────────────

describe("auth queries — useRenamePlanning rafraîchit ['me'] ET ['calendar-entries']", () => {
  it("renommer un plan → les DEUX lecteurs refetchent, et (planId, name) est transmis", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ me: useMe(), cal: useCalendarEntries(), rename: useRenamePlanning() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.me.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.cal.isSuccess).toBe(true));

    result.current.rename.mutate({ planId: "p1", name: "Automne" });

    await waitFor(() => expect(result.current.rename.isSuccess).toBe(true));
    expect(authApi.renamePlanning).toHaveBeenCalledWith("p1", "Automne");
    await waitFor(() => expect(meSpy).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(calSpy).toHaveBeenCalledTimes(2));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. useRegisterConfig — config publique quasi-statique : staleTime Infini. Deux montages sur le
//    même client → UN SEUL fetch (la 2ᵉ lecture sert le cache, pas le réseau). Publique = toujours
//    activée (pas de gate d'auth).
// ─────────────────────────────────────────────────────────────────────────────

describe("auth queries — useRegisterConfig : quasi-statique, un seul fetch (staleTime Infini)", () => {
  it("deux lecteurs de la config → une seule requête réseau ; la donnée est servie du cache", async () => {
    const client = makeClient();
    const wrapper = wrapperFor(client);

    const first = renderHook(() => useRegisterConfig(), { wrapper });
    await waitFor(() => expect(first.result.current.isSuccess).toBe(true));
    expect(authApi.registerConfig).toHaveBeenCalledTimes(1);
    expect(first.result.current.data).toEqual({ turnstileSiteKey: null, demoShortcut: false, demoEmail: null });

    // Un second lecteur sur le même client : staleTime Infini → aucun re-fetch, donnée du cache.
    const second = renderHook(() => useRegisterConfig(), { wrapper });
    await waitFor(() => expect(second.result.current.isSuccess).toBe(true));
    expect(second.result.current.data?.turnstileSiteKey).toBeNull();
    await new Promise((r) => setTimeout(r, 40));
    expect(authApi.registerConfig).toHaveBeenCalledTimes(1);
  });
});
