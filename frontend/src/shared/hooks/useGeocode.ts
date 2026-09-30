import { useMutation, useQuery } from "@tanstack/react-query";

import { geocodeAddress, reverseGeocode } from "@/shared/api/geocode";

/**
 * Géocodage à la demande (le clic « Localiser »). Une mutation, pas une requête permanente.
 * Le hook vit à part de `geocodeAddress` (import inter-modules) pour qu'un test puisse mocker
 * `@/shared/api/geocode` et intercepter l'appel réel.
 */
export function useGeocode() {
  return useMutation({
    mutationFn: (q: string) => geocodeAddress(q),
  });
}

/**
 * Reverse-geocoding en LECTURE (cache long) : le libellé d'adresse de coordonnées posées, pour
 * afficher de quoi vérifier un « Localisé » sans adresse saisie. `enabled` gouverne le tir (le
 * hook est toujours appelé, jamais conditionnellement). Une adresse retrouvée ne changeant pas,
 * on la garde indéfiniment.
 */
export function useReverseGeocode(latitude: number | null | undefined, longitude: number | null | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ["geocode-reverse", latitude ?? null, longitude ?? null],
    queryFn: () => reverseGeocode(latitude as number, longitude as number),
    enabled: enabled && null != latitude && null != longitude,
    staleTime: Infinity,
    gcTime: Infinity,
    retry: false,
  });
}
