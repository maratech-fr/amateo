<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FbiCorrection;
use App\Entity\Fixture;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\FbiCorrectionCloseSource;
use App\Enum\FbiCorrectionField;
use App\Repository\FbiCorrectionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * La MAISON UNIQUE du registre « à corriger dans FBI » : le seul endroit qui ouvre,
 * rafraîchit et ferme une entrée {@see FbiCorrection}. Injectée dans les deux foyers
 * d'arbitrage — le moteur de réconciliation partagé ({@see FbiFixtureImporter}, donc
 * les deux canaux xlsx + API) et l'arbitrage hors dépôt
 * ({@see App\Controller\ReviewFixtureDeviationController}).
 *
 * Sémantique : un « garder l'appli » (keep_app) sur un champ divergent dit « FBI est
 * en retard, il faut le mettre à jour à la main » → une entrée OUVERTE. Un dépôt qui
 * constate que FBI reflète désormais l'appli la ferme (`deposit`) ; le gestionnaire
 * peut aussi la cocher « corrigé » (`manual`). Un « prendre le fichier » (take_source)
 * n'ouvre JAMAIS d'entrée (l'appli s'aligne sur FBI, il n'y a rien à reporter).
 */
final class FbiCorrectionLedger
{
    /** L'auteur par défaut si aucun utilisateur en session (jamais en pratique : keep_app vient d'un dialog authentifié). */
    private const SYSTEM_AUTHOR = '00000000-0000-4000-8000-000000000000';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FbiCorrectionRepository $repository,
        private readonly Security $security,
    ) {}

    /**
     * Ouvre (ou rafraîchit) l'entrée « à corriger dans FBI » pour ce (rencontre, champ) :
     * l'appli fait foi (`appValue` = ce qu'il faut taper dans FBI), FBI est en retard
     * (`fbiValue` = ce que FBI affiche encore). Upsert : une entrée OUVERTE existante est
     * re-datée au lieu d'être dupliquée. `lastSeenInFbiAt` est posé « maintenant » — cet
     * écart vient d'être constaté dans FBI.
     */
    public function open(Fixture $fixture, FbiCorrectionField $field, ?string $appValue, ?string $fbiValue, DateTimeImmutable $now): FbiCorrection
    {
        $entry = $this->repository->findOpen($fixture->getId(), $field);
        if (!$entry instanceof FbiCorrection) {
            $entry = (new FbiCorrection)
                ->setClubId((string) $fixture->getClubId())
                ->setSeasonId($fixture->getSeasonId())
                ->setFixtureId($fixture->getId())
                ->setField($field);
            $this->entityManager->persist($entry);
        }

        $entry->setAppValue($appValue);
        $entry->setFbiValue($fbiValue);
        $entry->setVenueFbiLabel(FbiCorrectionField::VENUE === $field ? $this->venueFbiLabel($fixture) : null);
        $entry->setDecidedAt($now);
        $entry->setDecidedBy($this->currentUserId());
        $entry->setLastSeenInFbiAt($now);

        return $entry;
    }

    /** Un dépôt a RE-VU cet écart dans FBI (FBI affiche toujours l'ancienne valeur) : on re-date le témoin. */
    public function refreshSeen(FbiCorrection $entry, DateTimeImmutable $now): void
    {
        $entry->setLastSeenInFbiAt($now);
    }

    /** Un dépôt constate que FBI est corrigé (ou porte une 3ᵉ valeur) : l'entrée se ferme d'elle-même. */
    public function closeBySource(FbiCorrection $entry, DateTimeImmutable $now): void
    {
        $this->close($entry, FbiCorrectionCloseSource::DEPOSIT, $now);
    }

    /** Le gestionnaire coche « Corrigé dans FBI » : fermeture manuelle (réouvrable dans les 24 h). */
    public function closeManually(FbiCorrection $entry, DateTimeImmutable $now): void
    {
        $this->close($entry, FbiCorrectionCloseSource::MANUAL, $now);
    }

    private function close(FbiCorrection $entry, FbiCorrectionCloseSource $source, DateTimeImmutable $now): void
    {
        $entry->setClosedAt($now);
        $entry->setClosedBy($source);
    }

    /**
     * Le libellé FBI du gymnase de l'appli, quand l'inventaire des alias en connaît un —
     * ce que le gestionnaire doit sélectionner dans FBI. Null pour une salle sans alias
     * confirmé (le front affiche alors le nom Amateo).
     */
    private function venueFbiLabel(Fixture $fixture): ?string
    {
        $venueId = $fixture->getVenueId();
        if (null === $venueId) {
            return null;
        }
        $venue = $this->entityManager->getRepository(Venue::class)->find($venueId);
        if (!$venue instanceof Venue) {
            return null;
        }

        return $venue->getExternalLabels()[0] ?? null;
    }

    private function currentUserId(): string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getId() : self::SYSTEM_AUTHOR;
    }
}
