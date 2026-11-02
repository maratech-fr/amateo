import { HTTPError } from "ky";

/**
 * Lance la génération d'une version QU'ON VIENT DE CRÉER. Un refus 4xx ne doit pas la laisser vide dans
 * la liste : on la supprime. L'échec de ce nettoyage est avalé, c'est toujours l'erreur d'origine qui
 * remonte. Jamais à appeler sur une version préexistante.
 */
export async function generateCreatedVersion(scheduleId: string, generate: (id: string) => Promise<unknown>, discard: (id: string) => Promise<void>): Promise<void> {
  try {
    await generate(scheduleId);
  } catch (error) {
    if (error instanceof HTTPError && error.response.status >= 400 && error.response.status < 500) {
      await discard(scheduleId).catch(() => undefined);
    }
    throw error;
  }
}
