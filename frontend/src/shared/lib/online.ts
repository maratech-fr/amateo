import { onlineManager } from "@tanstack/react-query";
import { useSyncExternalStore } from "react";

/**
 * P5-14 — la SOURCE DE VÉRITÉ UNIQUE de l'état réseau du front.
 *
 * On s'abonne à l'`onlineManager` de react-query — le MÊME objet qui met une mutation en pause hors
 * ligne (`networkMode: "online"`) et qui la reprend au retour du réseau (`resumePausedMutations`).
 * Lire ailleurs `navigator.onLine` ferait diverger « ce que le bandeau affiche » de « ce que la file
 * de mutations décide » : ici, un seul juge.
 *
 * ⚠ Défaut connu de l'onlineManager (`onlineManager.js:4`) : il naît OPTIMISTE (`#online = true`) et
 * ne bascule que sur les événements `online`/`offline` de `window`. Au boot d'un onglet DÉJÀ hors
 * ligne, il prétend « en ligne » alors que `navigator.onLine` dit le contraire. `seedOnlineFromNavigator`
 * comble ce trou — appelé une fois dans `main.tsx` AVANT le render.
 */
export function seedOnlineFromNavigator(): void {
  if ("undefined" !== typeof navigator && false === navigator.onLine) {
    onlineManager.setOnline(false);
  }
}

/**
 * Vrai/faux réactif de l'état réseau. `onlineManager.subscribe` est déjà lié (bind dans le
 * constructeur de `Subscribable`), donc utilisable tel quel. Le snapshot serveur (`() => true`) ne
 * sert qu'à la robustesse SSR : le front est 100 % client.
 */
export function useOnline(): boolean {
  return useSyncExternalStore(
    onlineManager.subscribe,
    () => onlineManager.isOnline(),
    () => true,
  );
}

// essai du Lot Gate : ligne jetable
