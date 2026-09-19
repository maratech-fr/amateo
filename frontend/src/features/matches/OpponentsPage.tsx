import { OpponentTravelCard } from "./OpponentTravelCard";

/**
 * C8 — l'onglet « Adversaires » (`/matchs/adversaires`), sorti de la Configuration en page sœur
 * dédiée. Il reprend TOUT `OpponentTravelCard` : « Mettre à jour les adversaires » (avec la
 * progression du calcul asynchrone des trajets, C6), le bandeau « siège non localisé », l'épinglage
 * d'un gymnase, « Rétablir l'automatique », et les adversaires sans code fédéral repliés à part.
 * Enfant de `MatchesLayout` (garde socle héritée).
 */
export function OpponentsPage() {
  return (
    <section className="flex flex-col gap-4">
      <div>
        <h2 className="text-base font-semibold text-foreground">Adversaires</h2>
        <p className="text-sm text-muted-foreground">Où jouent vos adversaires et le trajet depuis le siège du club — localisez, épinglez un gymnase, recalculez.</p>
      </div>
      <OpponentTravelCard />
    </section>
  );
}
