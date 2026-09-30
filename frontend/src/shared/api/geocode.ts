import { api } from "@/shared/api/client";

/** Un candidat d'adresse rendu par le proxy `GET /api/geocode` (BAN). */
export interface GeocodeCandidate {
  label: string;
  latitude: number;
  longitude: number;
  /** Score de pertinence BAN, 0..1. */
  score: number;
}

/**
 * GET /api/geocode?q= (management) — jamais un appel direct à la BAN (frontière §2).
 * 422 q<3/>200, 502 BAN down. Maison PARTAGÉE (le géocodage d'une adresse sert le gymnase
 * ET le siège du club). Le hook `useGeocode` vit à part (`shared/hooks/useGeocode.ts`) pour
 * qu'un test puisse mocker CE `geocodeAddress` (une liaison intra-module ne se mocke pas).
 */
export const geocodeAddress = (q: string): Promise<GeocodeCandidate[]> =>
  api
    .get("geocode", { searchParams: { q } })
    .json<{ candidates: GeocodeCandidate[] }>()
    .then((r) => r.candidates);

/**
 * GET /api/geocode/reverse?lat=&lon= (management) — le libellé d'adresse le plus proche de
 * coordonnées, pour AFFICHER de quoi vérifier un « Localisé » sans adresse saisie. Best-effort
 * côté serveur : `label` peut être null (la BAN n'a rien renvoyé). Jamais un appel tiers direct.
 */
export const reverseGeocode = (latitude: number, longitude: number): Promise<string | null> =>
  api
    .get("geocode/reverse", { searchParams: { lat: latitude, lon: longitude } })
    .json<{ label: string | null }>()
    .then((r) => r.label);
