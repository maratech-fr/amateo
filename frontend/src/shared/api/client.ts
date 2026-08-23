import * as Sentry from "@sentry/react";
import ky from "ky";

import { recordIncident } from "@/shared/api/lastIncidentStore";
import { markSessionExpired } from "@/shared/lib/sessionExpiredNotice";
import { useAuthStore } from "@/shared/stores/authStore";
import { useSeasonStore } from "@/shared/stores/seasonStore";

/**
 * Configured HTTP client. Relative `/api` prefix only (Vite proxy in dev, Nginx
 * in prod — never hardcode hosts). Clears auth on 401.
 * ky 2.x hooks receive a single `state` object ({ request, response, ... }).
 *
 * SEC-16 (audit) : plus d'en-tête `Authorization` — l'identité voyage dans le
 * cookie httpOnly posé par le serveur, que le JS ne voit pas. `credentials`
 * reste explicite : le défaut `same-origin` suffirait (l'API est servie sous la
 * même origine par les proxys vite/nginx), mais l'écrire évite qu'un futur appel
 * cross-origin parte muet, sans identité et sans erreur parlante.
 */
/** UUID v4 sans dépendre du contexte sécurisé (cf. commentaire du hook P5-11). */
function randomUuid(): string {
  // Pas de narrowing `in` : le type DOM `Crypto` déclare toujours randomUUID, la
  // branche « absent » serait `never` — or à l'exécution elle existe bel et bien.
  const c = crypto as Crypto & { randomUUID?: () => string };
  if (typeof c.randomUUID === "function") {
    return c.randomUUID();
  }
  const b = c.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, "0")).join("");
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

export const api = ky.create({
  prefix: "/api",
  credentials: "include",
  hooks: {
    beforeRequest: [
      (state) => {
        // P5-11 — id de corrélation unique par requête (front→backend→bus→engine).
        // Le backend le VALIDE (forme UUID) et le ré-émet. ⚠ `crypto.randomUUID`
        // n'existe QUE dans un contexte sécurisé (https ou localhost) : sur un
        // accès http hors localhost (e2e dockerisé via frontend-dev, poste du LAN),
        // l'appeler jetait AVANT le fetch — toute l'app rendait « Une erreur est
        // survenue » sans qu'aucune requête ne parte. Repli : un UUID v4 dérivé de
        // `getRandomValues`, disponible dans tous les contextes.
        state.request.headers.set("X-Request-Id", randomUuid());
      },
      (state) => {
        // Season the manager is working in — absent = server-derived current
        // season (mono-season clubs never send it). A request that already
        // carries the header wins: one-shot cross-season calls (transition
        // re-dating) target another season explicitly — the server validates
        // the header either way, it is never trusted client-side.
        const seasonId = useSeasonStore.getState().selectedSeasonId;
        if (seasonId && !state.request.headers.has("X-Season-Id")) {
          state.request.headers.set("X-Season-Id", seasonId);
        }
      },
    ],
    afterResponse: [
      (state) => {
        // P5-11 — sur une erreur serveur, étiqueter la trace Sentry avec le
        // request_id ré-émis : l'incident remonté par l'utilisateur se retrouve
        // dans les logs corrélés. Gardé par VITE_SENTRY_DSN (cohérent avec
        // l'init de main.tsx) — sans DSN le SDK est inerte, on n'appelle rien.
        if (import.meta.env.VITE_SENTRY_DSN && state.response.status >= 500) {
          const requestId = state.response.headers.get("X-Request-Id");
          if (requestId) {
            Sentry.setTag("request_id", requestId);
          }
        }
      },
      async (state) => {
        // P5-6 → P4-129 — retenir le dernier incident serveur (≥ 500), request-id
        // PRÉSENT OU NON. P5-6 le proposait à un signalement contextuel ; P4-129 le
        // sert aussi au bloc « Détails techniques (dev) ». Indépendant de Sentry (le
        // canal de signalement vit sans DSN) ; hook distinct des deux autres pour ne
        // rien changer à leur comportement.
        //
        // ⚠ P4-129 — pourquoi l'on n'exige plus le request-id : le 502 nginx observé
        // sur :5173 arrivait SANS X-Request-Id ; l'ancien garde `if (requestId)`
        // n'enregistrait alors RIEN — l'incident même qui a motivé la fiche. Statut +
        // URL suffisent ; c'est le cas NOMINAL du besoin.
        //
        // Le `code` machine est best-effort : on CLONE la réponse (ky 2.x consomme le
        // corps original, cf. commentaire plus bas) puis on parse en try/catch — le
        // clone a son propre flux, distinct de celui que lira ky. Sur le 502 nginx le
        // corps est du HTML : le parse échoue silencieusement, ce n'est pas une erreur.
        if (state.response.status >= 500) {
          const requestId = state.response.headers.get("X-Request-Id") ?? undefined;
          let code: string | undefined;
          try {
            const body = (await state.response.clone().json()) as { code?: unknown };
            if (typeof body.code === "string") {
              code = body.code;
            }
          } catch {
            // Corps non-JSON (HTML nginx du 502) ou vide : statut + URL restent l'essentiel.
          }
          recordIncident({ status: state.response.status, url: state.request.url, requestId, code });
        }
      },
      (state) => {
        // 401 on an endpoint that AUTHENTICATES a submitted credential is a normal
        // "bad credentials" the CALLER surfaces — NOT a stale session. Two such
        // endpoints: /api/login, and the dev demo-register shortcut (P2-4), which
        // now VERIFIES the demo password instead of overwriting it — a typo returns
        // 401 while NOBODY is logged in (the user is on the register form). Ejecting
        // to /login there would look like the app kicked the founder out mid-demo.
        // Only treat 401 elsewhere as a stale/expired session.
        const callerHandles401 = state.request.url.includes("/api/login")
          || state.request.url.includes("/api/dev/demo-register");
        if (state.response.status === 401 && !callerHandles401) {
          useAuthStore.getState().clear();
          // P5-14 — marquer l'expiration AVANT de rediriger : LoginPage lit ce
          // marqueur one-shot au montage et explique le retour au formulaire
          // (« Fin du temps réglementaire »), au lieu d'une redirection muette.
          markSessionExpired();
          if (typeof window !== "undefined") {
            window.location.assign("/login");
          }
        }
        // Self-healing on a stale persisted season (e.g. purged server-side):
        // the backend 403s EVERY request carrying the dead X-Season-Id,
        // /api/me included — without this reset the app could never recover.
        // Keyed on the X-Season-Rejected marker (NOT any 403) so a legitimate
        // authorization denial still surfaces its error instead of wiping the
        // selection and hard-reloading. Clearing drops the header → no loop.
        if (state.response.status === 403 && state.response.headers.has("X-Season-Rejected")) {
          useSeasonStore.getState().clear();
          if (typeof window !== "undefined") {
            window.location.reload();
          }
        }
      },
    ],
    // No beforeError hook: ky 2.x consumes the error-response body itself and
    // exposes the parsed result as `error.data` BEFORE any consumer runs —
    // re-reading `error.response` throws "body stream already read". Every
    // error-body reader (errorMessage(), structured catches) must read
    // `error.data`, never the response.
  },
});
