import { useMutation } from "@tanstack/react-query";

import { geocodeAddress } from "@/shared/api/geocode";

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
