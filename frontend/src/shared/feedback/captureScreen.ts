/**
 * Chantier F PR3 — capture d'écran d'un signalement. Abstraction MINCE au-dessus de la
 * librairie de rendu DOM→image (modern-screenshot, tranché par le spike) : le reste du code
 * ne connaît que `captureScreen(): Promise<Blob | null>`, jamais la librairie.
 *
 * - `document.documentElement` (toute la page), la fenêtre de signalement EXCLUE par
 *   `excludeSelector` (son nœud et tout nœud à l'intérieur sont retirés du clone).
 * - `scale` plafonné à 1 et borné pour qu'un écran très large ne produise pas une image
 *   démesurée (≤ 1920 px de large à l'échelle 1).
 * - WebP, qualité dégressive jusqu'à passer SOUS 2 Mo (même borne que le serveur) ; Safari
 *   ignore parfois le type demandé et rend un PNG — on l'accepte.
 * - `null` si l'image reste trop lourde après le dernier palier, ou à la MOINDRE erreur :
 *   un échec de capture ne doit jamais faire perdre le signalement (l'appelant envoie sans image).
 * - JAMAIS de `workerUrl` : le worker chargerait un script externe, bloqué par la CSP.
 */
const MAX_BYTES = 2_000_000;

const QUALITIES = [0.8, 0.6, 0.4] as const;

const ACCEPTED_TYPES = new Set(["image/webp", "image/png"]);

export async function captureScreen(options?: { excludeSelector?: string }): Promise<Blob | null> {
  try {
    const { domToBlob } = await import("modern-screenshot");
    const root = document.documentElement;
    const scale = Math.min(1, 1920 / (root.scrollWidth || 1920));
    const excludeSelector = options?.excludeSelector;

    // `filter` renvoie false pour EXCLURE : le nœud qui matche le sélecteur, et tout nœud à
    // l'intérieur (`closest` couvre l'élément lui-même et ses ancêtres).
    const filter = (node: Node): boolean => {
      if (undefined === excludeSelector || !(node instanceof Element)) {
        return true;
      }
      return null === node.closest(excludeSelector);
    };

    for (const quality of QUALITIES) {
      const blob = await domToBlob(root, { filter, scale, type: "image/webp", quality });
      if (null !== blob && ACCEPTED_TYPES.has(blob.type) && blob.size <= MAX_BYTES) {
        return blob;
      }
    }

    return null;
  } catch {
    return null;
  }
}
