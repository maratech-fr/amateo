import { AddressGeocodeField } from "@/shared/components/ui/address-geocode-field";

import type { Venue } from "../api";

/**
 * P2-53 RMM-8 — le geste géo d'une fiche de gymnase : un WRAPPER MINCE autour de la maison
 * partagée {@see AddressGeocodeField}. Il ne fait que traduire un candidat FÉDÉRAL choisi en
 * `{ address, latitude, longitude }` (chaînes) posé sur le gymnase (`onLocate`). Toute
 * l'interaction (Localiser, candidats, « Recommandé », repliée « Localisé »/« Modifier
 * l'adresse ») vit dans le partagé.
 */
export function VenueGeocodeField({
  venue,
  onLocate,
}: {
  venue: Pick<Venue, "id" | "address" | "latitude" | "longitude">;
  /** Pose address + lat/long (chaînes) sur le gymnase. `address` vide = coordonnées saisies à la
   *  main (le backend efface l'adresse). Appelé au clic d'un candidat ou d'une saisie, jamais avant. */
  onLocate: (geo: { address: string; latitude: string; longitude: string }) => void;
}) {
  return (
    <AddressGeocodeField
      address={venue.address}
      located={null != venue.latitude && null != venue.longitude}
      latitude={null != venue.latitude ? Number(venue.latitude) : null}
      longitude={null != venue.longitude ? Number(venue.longitude) : null}
      placeholder="Adresse du gymnase"
      label="Adresse"
      statusWord="Localisé"
      onPick={(candidate) => onLocate({ address: candidate.label, latitude: String(candidate.latitude), longitude: String(candidate.longitude) })}
      // Coordonnées saisies à la main : même écriture (mêmes effets de bord — recalcul des trajets),
      // sans adresse (address vide ⇒ le backend l'efface).
      onManualCoords={({ latitude, longitude }) => onLocate({ address: "", latitude: String(latitude), longitude: String(longitude) })}
    />
  );
}
