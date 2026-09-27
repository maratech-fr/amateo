import { HTTPError, TimeoutError } from "ky";

interface ApiErrorBody {
  error?: string;
  message?: string;
  detail?: string;
  violations?: { message?: string }[];
}

// P4-263 — le backend francise les messages ATTEIGNABLES par un gestionnaire, mais un 4xx nu
// de Symfony / API Platform (NotFoundHttpException sans message, garde de contrat) peut encore
// porter la reason-phrase HTTP anglaise BRUTE. Ce n'est pas une copie écrite pour l'utilisateur :
// on l'ignore et on tombe sur le repli FR par statut. Liste FERMÉE (les reason-phrases de
// `Symfony\Component\HttpFoundation\Response::$statusTexts`, y compris l'ancienne « Unprocessable
// Entity » qu'API Platform peut encore émettre) — jamais une heuristique « ça a l'air anglais ».
const ENGLISH_STATUS_TEXTS = new Set([
  "bad request",
  "unauthorized",
  "forbidden",
  "not found",
  "method not allowed",
  "conflict",
  "unprocessable entity",
  "unprocessable content",
  "too many requests",
  "internal server error",
]);

function isEnglishStatusText(value: string): boolean {
  return ENGLISH_STATUS_TEXTS.has(value.trim().toLowerCase());
}

/**
 * Best-effort French, user-facing message from an unknown error (FRT-01/02).
 * Prefers a server-provided message, then falls back to a status-based sentence.
 * Async because reading the ky HTTPError response body is async.
 */
export async function errorMessage(error: unknown): Promise<string> {
  if (error instanceof TimeoutError) {
    return "La requête a expiré. Réessayez.";
  }

  if (error instanceof HTTPError) {
    const status = error.response.status;

    // ky 2.x consumes the error-response stream itself and exposes the parsed
    // body as `error.data` — re-reading the response would throw "body stream
    // already read". A non-JSON body leaves data as a string/undefined → the
    // object guard falls through to the status-based sentence.
    const data = (error as { data?: unknown }).data;
    // SEC-08 / P4-5 — un 5xx ne dit RIEN d'actionnable à l'utilisateur, et son
    // corps peut porter des détails internes (message de driver, trace). Les
    // messages du serveur ne sont repris que pour les erreurs CLIENT (4xx), où
    // ils sont des messages métier écrits pour être lus.
    if (status < 500 && null !== data && typeof data === "object") {
      const body = data as ApiErrorBody;
      const direct = body.error ?? body.message ?? body.detail;
      if (typeof direct === "string" && direct.trim() !== "" && !isEnglishStatusText(direct)) {
        return direct;
      }
      if (Array.isArray(body.violations) && body.violations.length > 0) {
        const joined = body.violations
          .map((v) => v.message)
          .filter((m): m is string => typeof m === "string" && m.trim() !== "")
          .join(" · ");
        if (joined !== "") {
          return joined;
        }
      }
    }

    // AUD-FRT-18 — deux codes que le backend émet VRAIMENT manquaient à la table, et
    // tombaient donc dans le repli « Une erreur est survenue (429) ». Un nombre n'est pas
    // un message : il ne dit ni ce qui s'est passé, ni quoi faire.
    //
    //  · 401 — le cookie JWT a expiré (`AuthController:322,453`). Le geste utile est de se
    //    reconnecter, pas de réessayer, et le gestionnaire n'a aucun moyen de le deviner.
    //  · 429 — le throttle par utilisateur (SEC-11, `ApiRateLimitTest`). Ici « réessayez
    //    plus tard » est LA bonne conduite ; « (429) » pousse au contraire à re-cliquer,
    //    ce qui prolonge exactement la fenêtre de blocage.
    if (status === 400) return "Requête invalide.";
    if (status === 401) return "Session expirée. Reconnectez-vous.";
    if (status === 403) return "Accès refusé.";
    if (status === 404) return "Ressource introuvable.";
    if (status === 405) return "Action non autorisée.";
    if (status === 409) return "Conflit : l'action n'a pas pu être effectuée.";
    if (status === 422) return "Données invalides. Vérifiez votre saisie.";
    if (status === 429) return "Trop de requêtes d'affilée. Patientez quelques instants avant de réessayer.";
    if (status >= 500) {
      // P5-11 — joindre une référence d'incident (8 premiers caractères du
      // X-Request-Id ré-émis par le backend) : le gestionnaire la donne au
      // support, qui la retrouve dans les logs corrélés. Les headers restent
      // lisibles (seul le corps a été consommé par ky). Absente si le header
      // manque (rétro-compatible).
      const requestId = error.response.headers.get("X-Request-Id");
      if (requestId !== null && requestId.trim() !== "") {
        return `Erreur serveur. Réessayez plus tard. (réf. incident : ${requestId.slice(0, 8)})`;
      }
      return "Erreur serveur. Réessayez plus tard.";
    }
    return `Une erreur est survenue (${status}).`;
  }

  if (error instanceof Error) {
    return "Problème de connexion. Vérifiez votre réseau.";
  }

  return "Une erreur est survenue.";
}
