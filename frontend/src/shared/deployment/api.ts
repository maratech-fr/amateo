import { api } from "@/shared/api/client";

/**
 * Le strict minimum qu'un club connecté a besoin de savoir d'une mise en production programmée :
 * QUAND elle commence et QUAND l'app revient. Jamais la version, ni l'acteur, ni la cuisine de
 * déploiement — le backend (`DeploymentNoticeController`) ne les sert pas.
 */
export interface DeploymentNotice {
  scheduledAt: string;
  expectedReturnAt: string;
}

/**
 * Lit l'annonce courante. Le backend sert un `null` LITTÉRAL (pas `{}`) quand rien n'est
 * programmé — `.json()` le rend tel quel, le bandeau ne rend alors rien.
 */
export function getDeploymentNotice(): Promise<DeploymentNotice | null> {
  return api.get("deployment-notice").json<DeploymentNotice | null>();
}
