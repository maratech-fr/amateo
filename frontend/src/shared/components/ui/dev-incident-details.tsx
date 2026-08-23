import { type ReactNode, useState } from "react";

import { readRecentIncident } from "@/shared/api/lastIncidentStore";

/**
 * P4-129 — sous le message grand public d'un écran système, un bloc REPLIABLE de détails
 * techniques, VISIBLE EN DEV UNIQUEMENT. Il répond à l'incident déclencheur : un 502 nginx
 * SANS X-Request-Id sur :5173, sur lequel le rail existant n'aurait RIEN affiché.
 *
 * ⚠ `import.meta.env.DEV` est lu AU RENDU (jamais capturé à l'import) : en production le
 * composant rend `null`, et son contenu n'a aucun chemin d'exécution. Prouvé par test sous
 * `vi.stubEnv("DEV", false)`, MÊME avec un incident frais.
 *
 * Deux régimes de données, deux groupes :
 *   - « Cet écran » : statut/URL/code/planning que l'écran appelant CONNAÎT de SON erreur,
 *     passés en props (il ne les invente pas — s'il ne sait rien, le groupe n'apparaît pas).
 *   - « Dernier incident serveur » : lu du store par le composant lui-même (fonction PURE,
 *     sûre hors providers, comme `ServerErrorScreen`) — il peut être SANS lien avec l'écran
 *     courant, l'intitulé le dit.
 *
 * A11y (passe de design ui-ux-pro-max, 2026-08-23) : `<details>/<summary>` NATIF, replié par
 * défaut (aucun `open`) ; le composant ne pose AUCUNE live region et ne vole JAMAIS le focus.
 * Le seul texte toujours visible est le summary — d'où « Détails techniques (dev) », sans
 * identifiant interne. Pas de bouton Copier (il exigerait un état « Copié ✓ » — machinerie
 * dans une primitive volontairement inerte) : `select-all` suffit à copier une valeur d'un geste.
 */
export interface DevIncidentDetailsProps {
  /** Statut HTTP que l'écran connaît de SON erreur (groupe « Cet écran »). */
  screenStatus?: number;
  /** URL appelée que l'écran connaît de SON erreur. */
  screenUrl?: string;
  /** Code d'erreur machine que l'écran connaît de SON erreur. */
  screenCode?: string;
  /** Le run/planning en cause, si l'écran en a un (ex. `GenerationServiceDown`). */
  scheduleId?: string | null;
}

const SUMMARY_CLASSES =
  "inline-flex min-h-11 cursor-pointer select-none items-center rounded-md text-xs text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background";
const DT_CLASSES = "text-muted-foreground";
const DD_CLASSES = "select-all break-all font-mono text-xs text-foreground";

function Row({ label, value }: { label: string; value: ReactNode }): ReactNode {
  return (
    <div className="flex flex-col gap-0.5 sm:flex-row sm:gap-2">
      <dt className={DT_CLASSES}>{label}</dt>
      <dd className={DD_CLASSES}>{value}</dd>
    </div>
  );
}

/** « il y a 4 min · 14:32 » — relatif ET absolu, calculé une fois (pas de ticker). */
function formatIncidentTime(at: number, now: number): string {
  const minutes = Math.floor((now - at) / 60_000);
  const relative = minutes < 1 ? "à l'instant" : `il y a ${minutes} min`;
  const absolute = new Date(at).toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
  return `${relative} · ${absolute}`;
}

export function DevIncidentDetails(props: DevIncidentDetailsProps): ReactNode {
  // Lu AU RENDU : en prod ce chemin ne s'exécute jamais (bloc absent du bundle). La garde
  // vit dans un composant SANS hook, et le corps (qui, lui, en a un) n'est monté qu'en dev —
  // les hooks restent donc inconditionnels dans le composant qui les porte.
  if (!import.meta.env.DEV) {
    return null;
  }
  return <DevIncidentDetailsBody {...props} />;
}

function DevIncidentDetailsBody({ screenStatus, screenUrl, screenCode, scheduleId }: DevIncidentDetailsProps): ReactNode {
  // Horodatage relatif figé au MONTAGE (pas de ticker) : `Date.now()` est impur, on le
  // capture dans l'initialiseur paresseux de useState — lu une fois, jamais au fil des rendus.
  const [mountNow] = useState(() => Date.now());
  const incident = readRecentIncident();
  const hasScreenInfo = undefined !== screenStatus || undefined !== screenUrl || undefined !== screenCode || (null !== scheduleId && undefined !== scheduleId);

  // Rien à montrer : ni ce que l'écran sait, ni un incident serveur frais.
  if (!hasScreenInfo && null === incident) {
    return null;
  }

  return (
    <details className="mt-2 w-full max-w-md text-left">
      <summary className={SUMMARY_CLASSES}>Détails techniques (dev)</summary>

      <div className="mt-2 flex flex-col gap-3">
        {hasScreenInfo ? (
          <section>
            <p className="mb-1 text-xs font-semibold text-muted-foreground">Cet écran</p>
            <dl className="flex flex-col gap-1">
              {undefined !== screenStatus ? <Row label="Statut" value={<span className="tabular-nums">{screenStatus}</span>} /> : null}
              {undefined !== screenUrl ? <Row label="URL" value={screenUrl} /> : null}
              {undefined !== screenCode ? <Row label="Code" value={screenCode} /> : null}
              {null !== scheduleId && undefined !== scheduleId ? <Row label="Planning" value={scheduleId} /> : null}
            </dl>
          </section>
        ) : null}

        {null !== incident ? (
          <section>
            <p className="mb-1 text-xs font-semibold text-muted-foreground">Dernier incident serveur (peut être sans lien avec cet écran)</p>
            <dl className="flex flex-col gap-1">
              <Row label="Statut" value={<span className="tabular-nums">{incident.status}</span>} />
              <Row label="URL" value={incident.url} />
              {undefined !== incident.code ? <Row label="Code" value={incident.code} /> : null}
              {undefined !== incident.requestId ? <Row label="X-Request-Id" value={incident.requestId} /> : null}
              <Row label="Horodatage" value={formatIncidentTime(incident.at, mountNow)} />
            </dl>
          </section>
        ) : null}
      </div>
    </details>
  );
}
