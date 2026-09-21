<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Controller\OpponentTravelController;
use App\Entity\OpponentVenueLink;
use App\Enum\OpponentVenueLinkSource;
use App\Repository\OpponentVenueLinkRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\OpponentPairingKey;
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
    /**
     * Borne le nombre de gymnases appariés par (club, adversaire) : l'ajout avant toute
     * rencontre reste possible (cas playoff), mais on empêche l'inflation d'un compteur
     * communautaire par des libellés forgés (revue sécurité 2026-09-20).
     */
    public const int MAX_VENUES_PER_OPPONENT = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OpponentVenueLinkRepository $linkRepository,
        private readonly OpponentTravelResolver $travelResolver,
        private readonly VenueLabelNormalizer $labelNormalizer,
        private readonly OpponentPairingKey $pairingKey,
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

        $this->writeGym($clubId, $link, $venueLabel, $venueRef, $lat, $lon, $previousRef);

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

        $this->writeGym($clubId, $link, $venueLabel, $venueRef, $lat, $lon, $previousRef);

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
        $ref = $link->getVenueExternalRef();
        // Décrément SYMÉTRIQUE : un ref n'est persisté que s'il a été crédité (résolu fédéralement,
        // cf. writeGym), et on ne décrémente qu'au retrait du DERNIER lien du club sur ce gymnase.
        if (OpponentVenueLinkSource::MANUAL === $link->getSource() && null !== $ref
            && 0 === $this->linkRepository->countManualByRef($clubId, $link->getOpponentOrganismeCode(), $ref, $link->getId())) {
            $this->travelResolver->debitSharedVenue($link->getOpponentOrganismeCode(), $ref);
        }
        $this->entityManager->remove($link);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Écrit le gymnase sur le lien (MANUAL) et tient la comptabilité du partagé IDEMPOTENTE et
     * SYMÉTRIQUE. Le nouveau ref n'est PERSISTÉ que s'il résout fédéralement (b) — un ref présent
     * implique donc toujours un crédit passé. On ne crédite le nouveau gymnase que si le club ne
     * le portait pas déjà (a), et on ne débite l'ancien que si plus aucun autre lien du club ne le
     * porte (a). Chauffe enfin le trajet du nouveau gymnase (cache-first).
     */
    private function writeGym(string $clubId, OpponentVenueLink $link, string $venueLabel, ?string $newRef, float $lat, float $lon, ?string $previousRef): void
    {
        // 🔴 SÉCURITÉ (revue 2026-09-21) — un adversaire SANS code fédéral (clé SENTINELLE) n'entre
        // JAMAIS dans le catalogue partagé `opponent_venue_suggestion` (keyé sur le code organisme
        // fédéral PUBLIC ; table hors-tenant, sans GRANT DELETE). On neutralise la référence ICI,
        // AVANT toute résolution fédérale et pour TOUS les appelants (addOrUpdate, repoint) — foyer
        // unique de la garde, plutôt qu'une rustine par contrôleur. Sans ref, `resolveFederalVenue`
        // n'est pas appelé et rien n'est crédité : l'appariement reste LOCAL (coordonnées seules).
        if ($this->pairingKey->isSentinel($link->getOpponentOrganismeCode())) {
            $newRef = null;
        }
        $federal = null === $newRef ? null : $this->travelResolver->resolveFederalVenue($newRef, $lat, $lon);
        $storedRef = null !== $newRef && null !== $federal ? mb_substr($newRef, 0, 64) : null;
        $code = $link->getOpponentOrganismeCode();

        $link->setVenueLabel(mb_substr($venueLabel, 0, 180))
            ->setVenueExternalRef($storedRef)
            ->setLatitude($lat)
            ->setLongitude($lon)
            ->setSource(OpponentVenueLinkSource::MANUAL);
        $this->entityManager->persist($link);
        $this->entityManager->flush();

        if ($previousRef !== $storedRef) {
            // Débit de l'ancien ref s'il n'est plus porté par aucun AUTRE lien du club.
            if (null !== $previousRef && 0 === $this->linkRepository->countManualByRef($clubId, $code, $previousRef, $link->getId())) {
                $this->travelResolver->debitSharedVenue($code, $previousRef);
            }
            // Crédit du nouveau ref si le club ne le portait pas déjà. Un $storedRef non nul
            // implique un $federal non nul (il n'est posé que lorsque la résolution a abouti,
            // ce que PHPStan sait narrower — d'où l'absence de test « null !== $federal »).
            if (null !== $storedRef && 0 === $this->linkRepository->countManualByRef($clubId, $code, $storedRef, $link->getId())) {
                $this->travelResolver->creditSharedVenue($code, $storedRef, $federal);
            }
        }

        $this->travelResolver->warmTravel($clubId, $lat, $lon);
    }
}
