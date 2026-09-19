<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Controller\OpponentTravelController;
use App\Entity\OpponentVenueLink;
use App\Enum\OpponentVenueLinkSource;
use App\Repository\OpponentVenueLinkRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-54 (amendement 2026-09-20) — les gestes MANUELS d'appariement « libellé FBI →
 * gymnase » ({@see OpponentVenueLink}) : ajouter, ré-apparier/fusionner, retirer. Foyer
 * unique de l'écriture tenant, appelé par {@see OpponentTravelController}.
 *
 * Chaque geste tient trois choses ENSEMBLE : (1) l'écriture du lien tenant ; (2) la
 * comptabilité du compteur PARTAGÉ ({@see OpponentTravelResolver::accountManualChoice},
 * « un compte, jamais un qui » — un lien AUTO remplacé ne décrémente jamais) ; (3) le
 * chauffage synchrone du cache de trajet du nouveau gymnase
 * ({@see OpponentTravelResolver::warmTravel}, un seul itinéraire IGN, cache-first) pour que
 * l'écran montre le trajet tout de suite.
 */
final class OpponentVenueLinkManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OpponentVenueLinkRepository $linkRepository,
        private readonly OpponentTravelResolver $travelResolver,
        private readonly VenueLabelNormalizer $labelNormalizer,
    ) {}

    /**
     * Crée ou actualise le lien MANUAL `(club, code, libellé FBI normalisé)` vers le
     * gymnase choisi (ref fédérale ou coordonnées). Décrémente/incrémente le partagé et
     * chauffe le trajet. Retourne le lien (sa clé de libellé est stable une fois posée).
     */
    public function addOrUpdate(string $clubId, string $code, string $fbiLabel, string $venueLabel, ?string $venueRef, float $lat, float $lon): OpponentVenueLink
    {
        $norm = $this->labelNormalizer->normalize(trim($fbiLabel));
        $existing = $this->linkRepository->findOneByKey($clubId, $code, $norm);
        // Le ref que le lien portait AVANT ce choix — pour le décompte partagé — n'est
        // transmis QUE si l'ancien lien était MANUAL (un lien AUTO n'a jamais compté).
        $previousRef = $existing instanceof OpponentVenueLink && OpponentVenueLinkSource::MANUAL === $existing->getSource()
            ? $existing->getVenueExternalRef()
            : null;

        $link = $existing ?? (new OpponentVenueLink)
            ->setClubId($clubId)
            ->setOpponentOrganismeCode(mb_substr($code, 0, 64))
            ->setFbiLabel(mb_substr(trim($fbiLabel), 0, 180))
            ->setFbiLabelNorm(mb_substr($norm, 0, 180));
        $link->setVenueLabel(mb_substr($venueLabel, 0, 180))
            ->setVenueExternalRef(null === $venueRef ? null : mb_substr($venueRef, 0, 64))
            ->setLatitude($lat)
            ->setLongitude($lon)
            ->setSource(OpponentVenueLinkSource::MANUAL);
        if (!$existing instanceof OpponentVenueLink) {
            $this->entityManager->persist($link);
        }
        $this->entityManager->flush();

        $this->travelResolver->accountManualChoice($code, $previousRef, $venueRef, $lat, $lon);
        $this->travelResolver->warmTravel($clubId, $lat, $lon);

        return $link;
    }

    /**
     * Ré-apparie/fusionne un lien EXISTANT vers un AUTRE gymnase (la clé de libellé est
     * conservée — le libellé du fichier reste reconnu à l'import). Null si le lien
     * n'appartient pas au club (404 byte-identique côté contrôleur). Le lien devient MANUAL.
     */
    public function repoint(string $clubId, string $linkId, string $venueLabel, ?string $venueRef, float $lat, float $lon): ?OpponentVenueLink
    {
        $link = $this->linkRepository->find($linkId);
        if (!$link instanceof OpponentVenueLink || $link->getClubId() !== $clubId) {
            return null;
        }
        $previousRef = OpponentVenueLinkSource::MANUAL === $link->getSource() ? $link->getVenueExternalRef() : null;
        $code = $link->getOpponentOrganismeCode();
        $link->setVenueLabel(mb_substr($venueLabel, 0, 180))
            ->setVenueExternalRef(null === $venueRef ? null : mb_substr($venueRef, 0, 64))
            ->setLatitude($lat)
            ->setLongitude($lon)
            ->setSource(OpponentVenueLinkSource::MANUAL);
        $this->entityManager->flush();

        $this->travelResolver->accountManualChoice($code, $previousRef, $venueRef, $lat, $lon);
        $this->travelResolver->warmTravel($clubId, $lat, $lon);

        return $link;
    }

    /**
     * Retire l'appariement LOCAL (jamais le catalogue fédéral). Décrémente le partagé si le
     * lien retiré était MANUAL et portait une ref fédérale. Faux si le lien n'appartient pas
     * au club (404 côté contrôleur).
     */
    public function delete(string $clubId, string $linkId): bool
    {
        $link = $this->linkRepository->find($linkId);
        if (!$link instanceof OpponentVenueLink || $link->getClubId() !== $clubId) {
            return false;
        }
        if (OpponentVenueLinkSource::MANUAL === $link->getSource() && null !== $link->getVenueExternalRef()) {
            // accountManualChoice(previous, null) = −1 sur l'ancien ref, aucun incrément.
            $this->travelResolver->accountManualChoice($link->getOpponentOrganismeCode(), $link->getVenueExternalRef(), null, 0.0, 0.0);
        }
        $this->entityManager->remove($link);
        $this->entityManager->flush();

        return true;
    }
}
