import { PRODUCT_NAME } from "@/shared/lib/product";

/**
 * Textes du bandeau d'annonce de maintenance (`DeploymentNoticeBanner.tsx`, inscrit à
 * `MIGRATED_FILES`). Le nom du produit vient de `PRODUCT_NAME`, jamais d'un littéral.
 */
export const DEPLOYMENT_TEXTS = {
  /** Retour le même jour calendaire : l'heure seule suffit. */
  noticeSameDay: (start: string, until: string): string =>
    `Maintenance prévue le ${start} : ${PRODUCT_NAME} sera indisponible jusqu'à ${until} environ.`,
  /** Retour un autre jour (la fenêtre enjambe minuit) : date et heure du retour. */
  noticeOtherDay: (start: string, untilDateTime: string): string =>
    `Maintenance prévue le ${start} : ${PRODUCT_NAME} sera indisponible jusqu'au ${untilDateTime} environ.`,
  dismiss: "Masquer l'annonce de maintenance",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
