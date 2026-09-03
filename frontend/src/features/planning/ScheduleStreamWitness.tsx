import { useScheduleStreamDiagnostics } from "./lib/scheduleStream";

/**
 * P4-168 — TÉMOIN du canal de livraison du planning.
 *
 * Élément SANS rendu visuel (`hidden`) qui expose dans le DOM l'état du flux Mercure, pour
 * qu'un e2e distingue « livré par SSE » de « livré par le repli polling (hub muet) » :
 * - `data-schedule-stream` = `connected` | `disconnected` (état instantané du flux) ;
 * - `data-schedule-stream-events` = nombre CUMULÉ d'événements Mercure reçus dans la session.
 *
 * Le témoin robuste est le COMPTEUR : le flux se relâche dès la fin de génération, donc au
 * moment où le planning s'affiche `connected` est déjà retombé, tandis que le compteur, lui,
 * survit et prouve encore qu'un événement SSE a été délivré (voir `scheduleStream.ts`).
 * État client pur — aucune règle métier (règle 🔴 n° 1 non concernée).
 */
export function ScheduleStreamWitness() {
  const { connected, eventsReceived } = useScheduleStreamDiagnostics();
  return <div hidden data-testid="schedule-stream-witness" data-schedule-stream={connected ? "connected" : "disconnected"} data-schedule-stream-events={eventsReceived} />;
}
