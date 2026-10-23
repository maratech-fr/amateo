<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\Fixture;
use App\Entity\Venue;
use App\Enum\FixtureHomeAway;
use App\Exception\ImportRejectedException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * MAISON UNIQUE du rattachement « libellé de salle FBI/FFBB → gymnase » (attach +
 * backfill) — extraite de {@see App\Controller\VenueExternalLabelController::attach}
 * (chantier A PR 2). Les gardes sont CONSERVÉES verbatim :
 *  - libellé vide (après normalisation) → 422 ;
 *  - libellé > 120 caractères → 422 ;
 *  - au-delà de 30 alias sur un gymnase → 422 ;
 *  - libellé déjà porté par un AUTRE gymnase de la même saison → 422.
 *
 * Partagée par le contrôleur (chemin NON ré-affecté — il relaie l'exception en JSON,
 * comportement byte-identique) et par l'import FBI
 * ({@see App\Service\FbiFixtureImporter}), qui APPARIE chaque salle à domicile AVANT
 * la vérification de couverture et l'auto-validation. Ne flush JAMAIS : l'appelant
 * décide quand écrire.
 */
final class VenueExternalLabelAttacher
{
    /** Un libellé de salle fédéral tient en quelques mots ; au-delà, c'est un corps forgé (revue sécurité). */
    public const int MAX_LABEL_LENGTH = 120;

    /** Un gymnase n'a qu'une poignée de graphies FBI/FFBB ; au-delà, le json ne doit pas grossir sans fin. */
    public const int MAX_LABELS_PER_VENUE = 30;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VenueLabelNormalizer $labelNormalizer,
    ) {}

    /**
     * Normalise le libellé et applique les gardes vide / longueur / nombre d'alias sur ce
     * gymnase. Rend le libellé normalisé, lève {@see ImportRejectedException} (422) sinon.
     * Partagé par les deux branches du contrôleur (rattacher ET ré-affecter).
     */
    public function normalizeOrRefuse(Venue $venue, string $rawLabel): string
    {
        $label = $this->labelNormalizer->normalize($rawLabel);
        if ('' === $label) {
            throw ImportRejectedException::unprocessable('Le libellé est vide.');
        }
        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw ImportRejectedException::unprocessable(\sprintf('Le libellé dépasse %d caractères.', self::MAX_LABEL_LENGTH));
        }
        if (!\in_array($label, $venue->getExternalLabels(), true) && \count($venue->getExternalLabels()) >= self::MAX_LABELS_PER_VENUE) {
            throw ImportRejectedException::unprocessable(\sprintf('Ce gymnase porte déjà %d libellés — retirez-en un avant d\'en ajouter.', self::MAX_LABELS_PER_VENUE));
        }

        return $label;
    }

    /**
     * Rattache le libellé au gymnase (chemin NON ré-affecté) : gardes vide/longueur/nombre,
     * unicité (un autre gymnase le porte déjà → 422), puis pose l'alias normalisé et
     * backfille les domiciles du club encore sans salle (même saison) dont le libellé égale
     * l'alias (statut inchangé — jamais un placement). `attached` ne compte que les nouveaux
     * rattachés. Ne flush JAMAIS.
     *
     * @return array{venueId: string, label: string, attached: int}
     */
    public function attach(Venue $venue, string $rawLabel): array
    {
        $label = $this->normalizeOrRefuse($venue, $rawLabel);

        // Un libellé ne désigne qu'UN gymnase : un autre le porte déjà → on refuse en le
        // nommant plutôt que de créer une ambiguïté qui empêcherait toute résolution.
        $owner = $this->venueCarryingLabel($label, $venue->getId(), $venue->getSeasonId());
        if ($owner instanceof Venue) {
            throw ImportRejectedException::unprocessable(\sprintf('Ce libellé est déjà rattaché au gymnase « %s ». Retirez-le d\'abord.', $owner->getName()));
        }

        $venue->addExternalLabel($label);

        // Backfill : les domiciles du club encore sans salle dont le libellé FBI/FFBB égale
        // l'alias reçoivent ce gymnase (statut inchangé — jamais un placement). Borne SAISON
        // explicite (les alias sont copiés au changement de saison, défense en profondeur).
        $attached = 0;
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy([
            'homeAway' => FixtureHomeAway::HOME,
            'venueId' => null,
            'seasonId' => $venue->getSeasonId(),
        ]);
        foreach ($fixtures as $fixture) {
            $fixtureLabel = $fixture->getFbiVenueLabel();
            if (null !== $fixtureLabel && $this->labelNormalizer->normalize($fixtureLabel) === $label) {
                $fixture->setVenueId($venue->getId());
                ++$attached;
            }
        }

        return ['venueId' => $venue->getId(), 'label' => $label, 'attached' => $attached];
    }

    /**
     * Le gymnase du club, DE LA MÊME SAISON (les alias sont copiés en N+1 : la copie porte
     * légitimement le même libellé), autre que $exceptVenueId, portant déjà cet alias — ou
     * null. Public : la branche de ré-affectation du contrôleur le partage.
     */
    public function venueCarryingLabel(string $label, string $exceptVenueId, string $seasonId): ?Venue
    {
        foreach ($this->entityManager->getRepository(Venue::class)->findBy(['seasonId' => $seasonId]) as $venue) {
            if ($venue->getId() !== $exceptVenueId && \in_array($label, $venue->getExternalLabels(), true)) {
                return $venue;
            }
        }

        return null;
    }
}
