<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueResource;
use App\Dto\VenueInput;
use App\Entity\Fixture;
use App\Entity\Place;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Entity\VenueTravelTime;
use App\Enum\TravelComputeScope;
use App\Message\ComputeTravelTimesMessage;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\TravelComputeLock;
use App\Structure\PlaceDirectory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractStateProcessor<Venue, VenueInput, VenueResource>
 */
class VenueStateProcessor extends AbstractStateProcessor
{
    private ?MessageBusInterface $messageBus = null;

    private ?TravelComputeLock $travelComputeLock = null;

    /**
     * Le lieu dont les matrices de trajet doivent être RE-CALCULÉES après le flush, posé par
     * {@see updateEntityFromInput} quand la position d'un gymnase a réellement changé (lot G) : la
     * position vit sur le lieu, elle vaut pour chaque saison où ce lieu a un gymnase. Consommé et
     * remis à null par {@see afterPersist}. Jamais posé à la création (POST).
     *
     * @var array{0: string, 1: string}|null [club, lieu]
     */
    private ?array $pendingTravelRecompute = null;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly PlaceDirectory $placeDirectory,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    #[Required]
    public function setTravelRecomputeDeps(MessageBusInterface $messageBus, TravelComputeLock $travelComputeLock): void
    {
        $this->messageBus = $messageBus;
        $this->travelComputeLock = $travelComputeLock;
    }

    protected function getEntityClass(): string
    {
        return Venue::class;
    }

    protected function cascadeBeforeDelete(object $entity): void
    {
        if ($entity instanceof Venue) {
            // PR4 « fermetures à la source » (décision fondateur 2026-10-22) — un match à domicile
            // sans gymnase est un état IMPOSSIBLE : supprimer un gymnase qui porte au moins une
            // rencontre est REFUSÉ (422), AVANT toute purge, zéro écriture. Le filtre tenant scope
            // déjà le comptage sur le club de ce gymnase (un gymnase d'un autre club est invisible,
            // 404 avant d'arriver ici). La VALIDATION d'un planning reste la seule gâchette qui
            // dépointe un match (`FixtureVenueLossMarker`).
            if ($this->entityManager->getRepository(Fixture::class)->count(['venueId' => $entity->getId()]) > 0) {
                $this->refuse('Ce gymnase accueille des matchs : le supprimer les laisserait sans salle. Retirez-les d\'abord, puis supprimez le gymnase.');
            }
            $this->cascadeDeleter?->purgeChildrenOfVenue($entity);
        }
    }

    /**
     * @param VenueInput $input
     */
    protected function createEntityFromInput(object $input): Venue
    {
        // Tout gymnase créé reçoit un lieu neuf au club de la requête (le gymnase reçoit le même club
        // dans processPost), avec l'adresse et la position fournies ; le lieu est persisté avant le
        // gymnase qui le pointe.
        $place = new Place;
        $clubId = $this->requestStack->getCurrentRequest()?->attributes->get('_club_id');
        if (\is_string($clubId)) {
            $place->setClubId($clubId);
        }
        $this->writePosition($place, $input);
        $this->entityManager->persist($place);

        $entity = new Venue($place);
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->color) {
            $entity->setColor($input->color);
        }
        if (null !== $input->source) {
            $entity->setSource($input->source);
        }
        if (null !== $input->externalRef) {
            $entity->setExternalRef($input->externalRef);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->canSplit) {
            $entity->setCanSplit($input->canSplit);
        }

        return $entity;
    }

    /**
     * @param Venue      $entity
     * @param VenueInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // Lu AVANT toute mutation : c'est l'état d'origine du gymnase qui dit si on est sur une
        // transition « divisible » true→false (celle qui peut rendre des créneaux incohérents).
        $wasSplit = $entity->getCanSplit();
        // Adresse et position vivent sur le lieu du gymnase au club : les écrire les corrige pour
        // toutes les saisons où ce lieu a un gymnase.
        $place = $this->placeDirectory->of($entity);
        // Lot G — la POSITION d'origine, pour détecter un vrai changement de coordonnées après coup.
        $before = [$place->getAddress(), $place->getLatitude(), $place->getLongitude()];
        // Dissocier de la salle FFBB (`externalRef` vide) ramène le gymnase à l'état d'un gymnase qu'on
        // vient de créer : seul le nom reste. Il passe sur un lieu NEUF, sans adresse : le lieu qu'il
        // quitte, et les gymnases des autres saisons qui le pointent, ne bougent pas (décision 2 du
        // lot 3, A). Une valeur fournie dans le même PUT l'emporte, juste après.
        if ('' === $input->externalRef) {
            $place = (new Place)->setClubId($entity->getClubId());
            $this->entityManager->persist($place);
            $entity->moveToPlace($place);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->color) {
            $entity->setColor($input->color);
        }
        $this->writePosition($place, $input);
        // La ligne du gymnase est touchée quand son lieu change, pour que sa version change avec lui
        // (If-Match de l'écran).
        if ($before !== [$place->getAddress(), $place->getLatitude(), $place->getLongitude()]) {
            $entity->setUpdatedAt(new DateTimeImmutable);
        }

        // Lot G — la position a-t-elle RÉELLEMENT changé ? (Nom/couleur/autre champ seul ⇒ non.) On
        // ne re-calcule que vers une position complète (les deux coordonnées posées). Le club et le
        // lieu sont mémorisés ; le dispatch (sous garde matrice + verrou) part APRÈS le flush, dans
        // `afterPersist`.
        $newLatitude = $place->getLatitude();
        $newLongitude = $place->getLongitude();
        $coordsChanged = $before[1] !== $newLatitude || $before[2] !== $newLongitude;
        if ($coordsChanged && null !== $newLatitude && null !== $newLongitude) {
            $this->pendingTravelRecompute = [$entity->getClubId(), $place->getId()];
        }
        // Une dissociation qui laisse le gymnase sans position efface ses temps de trajet, saisies à la
        // main comprises. Une position fournie dans le même PUT suit le chemin ci-dessus.
        if ('' === $input->externalRef && (null === $newLatitude || null === $newLongitude)) {
            $this->blankTravelTimesOf($entity);
        }
        if (null !== $input->source) {
            $entity->setSource($input->source);
        }
        if (null !== $input->externalRef) {
            $entity->setExternalRef('' === $input->externalRef ? null : $input->externalRef);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        // canSplit was silently dropped on update → toggling "terrain divisible"
        // never persisted, so the per-slot capacity control never appeared and the
        // solver never split the court. A false value must persist (uncheck), so
        // guard on `null !==`, not truthiness.
        if (null !== $input->canSplit) {
            if ($wasSplit && false === $input->canSplit) {
                // La transition « divisible → non divisible » ne peut pas laisser derrière elle
                // des créneaux qui accueillent encore 2 équipes ou plus (incohérent). Refus (422)
                // sans confirmation, cascade avec.
                $this->guardSplitTransition($entity, $input->confirmSplitCascade);
            }
            $entity->setCanSplit($input->canSplit);
        }
    }

    /**
     * @param Venue $entity
     */
    protected function mapEntityToOutput(object $entity): VenueResource
    {
        return VenueResource::fromEntity($entity, $this->placeDirectory->of($entity));
    }

    /**
     * Lot G — après le flush d'un PUT qui a DÉPLACÉ un gymnase (coordonnées changées, posé par
     * {@see updateEntityFromInput}), on relance le calcul ASYNCHRONE de la matrice de trajet de chaque
     * saison où son lieu a un gymnase — mais SEULEMENT si le club a DÉJÀ au moins une ligne de matrice
     * pour cette saison (opt-in respecté :
     * jamais faire naître une matrice sur un simple déplacement). Les AUTO du couple impliquant ce
     * gymnase sont recalculés (nouvelles coordonnées ⇒ MISS du cache — indexé par coordonnées —, donc
     * appel IGN), les MANUAL préservés (garanti par l'autofill), les autres couples retombant sur le
     * cache. Un calcul déjà en cours ⇒ pas de redispatch (le handler échouerait sur le verrou tenu,
     * patron du contrôleur d'autofill). Aucune matrice / autre club / coordonnées inchangées ⇒ rien.
     */
    protected function afterPersist(object $entity): void
    {
        $pending = $this->pendingTravelRecompute;
        $this->pendingTravelRecompute = null;
        if (null === $pending || !$this->messageBus instanceof MessageBusInterface || !$this->travelComputeLock instanceof TravelComputeLock) {
            return;
        }
        [$clubId, $placeId] = $pending;
        if ($this->travelComputeLock->isHeld($clubId)) {
            return;
        }
        /** @var list<string> $seasonIds */
        $seasonIds = $this->entityManager->createQuery('SELECT DISTINCT v.seasonId FROM App\Entity\Venue v WHERE v.clubId = :c AND v.placeId = :p ORDER BY v.seasonId')
            ->setParameter('c', $clubId)
            ->setParameter('p', $placeId)
            ->getSingleColumnResult();
        foreach ($seasonIds as $seasonId) {
            if (0 === $this->entityManager->getRepository(VenueTravelTime::class)->count(['clubId' => $clubId, 'seasonId' => $seasonId])) {
                continue; // opt-in : pas de matrice ⇒ on n'en crée pas une sur un déplacement.
            }
            $this->messageBus->dispatch(new ComputeTravelTimesMessage($clubId, $seasonId, TravelComputeScope::VENUE_MATRIX));
        }
    }

    /**
     * Adresse et position d'un PUT ou d'un POST, posées sur le lieu (null = inchangé). Une adresse vide
     * efface l'adresse (saisie manuelle des coordonnées, lot E : « Position saisie à la main » sans
     * adresse) ; sinon on pose l'adresse fournie.
     */
    private function writePosition(Place $place, VenueInput $input): void
    {
        if (null !== $input->latitude) {
            $place->setLatitude($input->latitude);
        }
        if (null !== $input->longitude) {
            $place->setLongitude($input->longitude);
        }
        if (null !== $input->address) {
            $place->setAddress('' === trim($input->address) ? null : $input->address);
        }
    }

    /**
     * Vide les lignes de matrice du gymnase sans les supprimer : {@see afterPersist} ne recalcule que si
     * le club a au moins une ligne (opt-in), et « Annuler » ou une nouvelle adresse doivent recalculer
     * même quand ce gymnase portait les seules lignes du club. L'autofill remplit une ligne vidée comme
     * une colonne jamais renseignée (`VenueTravelTimeAutofillService::autofill`).
     */
    private function blankTravelTimesOf(Venue $venue): void
    {
        $repository = $this->entityManager->getRepository(VenueTravelTime::class);
        foreach ([...$repository->findBy(['venueAId' => $venue->getId()]), ...$repository->findBy(['venueBId' => $venue->getId()])] as $row) {
            $row->setDrivingMinutes(null)->setDrivingSource(null)->setWalkingMinutes(null)->setWalkingSource(null);
        }
    }

    /**
     * v2 cohérence canSplit — la garde INVERSE (poser capacité ≥ 2 sur un gymnase non divisible)
     * existe déjà côté créneau (VenueTrainingSlotStateProcessor::validateCapacityForVenue). Ici on
     * ferme le sens retour : rendre le gymnase indivisible alors que des créneaux y portent encore
     * une capacité ≥ 2. Toutes couches confondues (saison ET période : `findBy` par venueId ne
     * filtre pas sur `schedulePlanId`).
     *   - sans confirmation → 422, en NOMMANT les créneaux visés (jour + heure, aucun identifiant
     *     interne — cf. `PublicTextIsFreeOfInternalIdentifiersTest`) ;
     *   - avec confirmation (`VenueInput::confirmSplitCascade`) → cascade atomique déléguée à
     *     `EntityCascadeDeleter` : chaque créneau retombe à 1, perd son libellé, et voit ses
     *     réservations (+ verrous HARD matérialisés) vidées. Le planning se dira à régénérer : le
     *     seul write du gymnase déplace l'empreinte de structure (club+saison).
     */
    private function guardSplitTransition(Venue $venue, bool $confirmed): void
    {
        $overCapacity = array_values(array_filter(
            $this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['venueId' => $venue->getId()]),
            static fn (VenueTrainingSlot $slot): bool => $slot->getCapacity() >= 2,
        ));

        if ([] === $overCapacity) {
            return; // aucun créneau incohérent : décocher « divisible » passe librement
        }

        if (!$confirmed) {
            // `refuse()` construit une VRAIE liste de violations (le normaliseur d'ApiPlatform
            // reconstruit `detail`/`violations` DEPUIS elle — un message-chaîne nu y ressortirait
            // vide, et le toast du front, qui lit `detail`, n'afficherait rien).
            $this->refuse($this->splitCascadeMessage($venue, $overCapacity));
        }

        $this->cascadeDeleter?->clampSplitSlotsAndClearPins($overCapacity);
    }

    /**
     * @param list<VenueTrainingSlot> $overCapacity
     */
    private function splitCascadeMessage(Venue $venue, array $overCapacity): string
    {
        $days = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
        $creneaux = implode(', ', array_map(
            static fn (VenueTrainingSlot $slot): string => ($days[$slot->getDayOfWeek()] ?? 'jour ' . $slot->getDayOfWeek()) . ' ' . $slot->getStartTime()->format('H:i'),
            $overCapacity,
        ));

        return \sprintf(
            'Le gymnase « %s » ne peut pas devenir indivisible : des créneaux y accueillent encore 2 équipes ou plus (%s). Confirmez pour les ramener à 1 équipe et vider leurs réservations, ou laissez « terrain divisible » coché.',
            $venue->getName(),
            $creneaux,
        );
    }
}
