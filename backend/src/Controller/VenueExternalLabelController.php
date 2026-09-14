<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Venue;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Service\Basketball\VenueLabelInventory;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonResolver;
use App\Service\WriteTargetSeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Rattacher » / « Retirer » un libellé de salle FBI/FFBB à un gymnase (P4-187a
 * D4/D5). Un domicile importé porte un libellé fédéral libre (`fbiVenueLabel`) mais
 * pas de `venueId` : rattacher le libellé au gymnase le rend visible de la collision
 * de gymnase et de la fermeture, sans jamais placer la rencontre.
 *
 *  - GET    /api/venues/fbi-labels                    → inventaire agrégé (lecture
 *    ouverte à tout membre) : par libellé NORMALISÉ de la saison courante, le
 *    gymnase confirmé, une suggestion tirée des placements réels et les compteurs.
 *  - POST   /api/venues/{id}/external-labels          → ajoute l'alias (normalisé,
 *    idempotent) PUIS backfille les domiciles du club encore sans salle dont le
 *    libellé égale l'alias. Un libellé déjà porté par un AUTRE gymnase → 422 ; un
 *    libellé vide après normalisation → 422. Avec `reassign: true`, l'alias change
 *    de porteur et les domiciles NON PLACÉS au même libellé sont re-pointés (les
 *    placés gardent leur salle).
 *  - DELETE /api/venues/{id}/external-labels/{label}  → retire l'alias (idempotent),
 *    NE touche aucune rencontre déjà rattachée.
 *
 * Patron {@see VenuePeriodGridActionController} : management (SEC-07) + gymnase
 * résolu par le repo tenant-filtré (un gymnase étranger est invisible → 404) +
 * {@see SeasonScopedWriteInterface} (409 si la saison du gymnase est archivée).
 */
#[AsController]
final class VenueExternalLabelController extends AbstractController implements SeasonScopedWriteInterface
{
    /** Un libellé de salle fédéral tient en quelques mots ; au-delà, c'est un corps forgé (revue sécurité). */
    private const MAX_LABEL_LENGTH = 120;

    /** Un gymnase n'a qu'une poignée de graphies FBI/FFBB ; au-delà, le json ne doit pas grossir sans fin. */
    private const MAX_LABELS_PER_VENUE = 30;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly VenueLabelNormalizer $labelNormalizer,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly VenueLabelInventory $labelInventory,
    ) {}

    /**
     * SEC-13 — la cible (le gymnase) vit dans l'URL : on dérive sa saison hors
     * filtres pour que le garde « saison archivée » morde comme sur une écriture
     * API Platform.
     */
    public function writeTargetSeasonId(Request $request): ?string
    {
        $venueId = $request->attributes->get('id');

        return \is_string($venueId) && '' !== $venueId ? $this->writeTargetSeasonResolver->ofVenue($venueId) : null;
    }

    /**
     * Inventaire agrégé « libellé de salle FBI/FFBB → gymnase » de la saison
     * courante du club : lecture ouverte à tout membre authentifié (pas
     * {@see ManagementAccessGuard} — c'est un état, pas un geste), le club et la
     * saison viennent du contexte serveur. Alimente l'écran de ré-affectation
     * (E2). `priority: 10` : cette route statique doit gagner sur la route item
     * `/api/venues/{id}` d'API Platform, qui avalerait sinon « fbi-labels » comme
     * un uuid (→ 404), même idiome que {@see FixtureConflictsController}.
     */
    #[Route('/api/venues/fbi-labels', name: 'api_venue_fbi_labels', methods: ['GET'], priority: 10)]
    public function inventory(): JsonResponse
    {
        $clubId = $this->currentClubId();
        if (null === $clubId) {
            return $this->json(['labels' => []]);
        }
        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        if (!$season instanceof Season) {
            return $this->json(['labels' => []]);
        }

        return $this->json(['labels' => $this->labelInventory->forSeason($season->getId())]);
    }

    #[Route('/api/venues/{id}/external-labels', name: 'api_venue_external_labels_attach', methods: ['POST'])]
    public function attach(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $venue = $this->ownedVenue($id);
        if (!$venue instanceof Venue) {
            // Un gymnase d'un autre club est invisible → 404 byte-identique.
            return $this->json(['error' => 'Gymnase introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $decoded = json_decode((string) $request->getContent(), true);
        $rawLabel = \is_array($decoded) ? ($decoded['label'] ?? null) : null;
        // Ré-affectation explicite (E1) : sans le drapeau, comportement byte-identique
        // (422 d'unicité, backfill des seuls domiciles sans salle) ; avec, l'alias change
        // de porteur et les domiciles NON PLACÉS sont re-pointés quel que soit leur gymnase.
        $reassign = \is_array($decoded) && true === ($decoded['reassign'] ?? null);
        $label = $this->labelNormalizer->normalize(\is_string($rawLabel) ? $rawLabel : '');
        if ('' === $label) {
            return $this->json(['error' => 'Le libellé est vide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            return $this->json(['error' => \sprintf('Le libellé dépasse %d caractères.', self::MAX_LABEL_LENGTH)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!\in_array($label, $venue->getExternalLabels(), true) && \count($venue->getExternalLabels()) >= self::MAX_LABELS_PER_VENUE) {
            return $this->json(['error' => \sprintf('Ce gymnase porte déjà %d libellés — retirez-en un avant d\'en ajouter.', self::MAX_LABELS_PER_VENUE)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Un libellé ne désigne qu'UN gymnase. Un autre gymnase le porte déjà ?
        //  - sans `reassign` : on refuse en le nommant (« retirez-le d'abord »)
        //    plutôt que de créer une ambiguïté qui empêcherait toute résolution ;
        //  - avec `reassign` : on le lui RETIRE (l'unicité reste tenue) et on note
        //    `previousVenueId` pour dire d'où l'alias vient.
        $owner = $this->venueCarryingLabel($label, $venue->getId(), $venue->getSeasonId());
        $previousVenueId = null;
        if ($owner instanceof Venue) {
            if (!$reassign) {
                return $this->json(
                    ['error' => \sprintf('Ce libellé est déjà rattaché au gymnase « %s ». Retirez-le d\'abord.', $owner->getName())],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $previousVenueId = $owner->getId();
            $owner->removeExternalLabel($label);
        }

        $venue->addExternalLabel($label);

        if ($reassign) {
            // Ré-affectation : tous les domiciles NON PLACÉS de la saison au même
            // libellé pointent désormais ce gymnase, quel que soit leur gymnase actuel
            // (`attached` = nombre effectivement re-pointé → 0 si déjà bons, idempotent).
            // Les PLACÉS/SOUMIS/VALIDÉS gardent leur salle (statut jamais touché) et sont
            // comptés `kept` — le geste ne défait jamais un placement déjà décidé.
            [$attached, $kept] = $this->reassignHomeFixtures($venue, $label);
            $this->entityManager->flush();

            return $this->json([
                'label' => $label,
                'venueId' => $venue->getId(),
                'attached' => $attached,
                'kept' => $kept,
                'previousVenueId' => $previousVenueId,
            ], Response::HTTP_OK);
        }

        // Backfill : les domiciles du club encore sans salle dont le libellé FBI/FFBB
        // égale l'alias reçoivent ce gymnase (statut inchangé — jamais un placement).
        // `attached` ne compte que les nouveaux rattachés : un re-POST ne recompte
        // rien (les rencontres déjà rattachées ne sont plus « sans salle »).
        // Borne SAISON explicite (défense en profondeur sous le filtre saison) : les
        // alias sont copiés au changement de saison, un domicile de N-1 ne doit
        // jamais recevoir le gymnase de N.
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

        $this->entityManager->flush();

        return $this->json(['venueId' => $venue->getId(), 'label' => $label, 'attached' => $attached], Response::HTTP_OK);
    }

    #[Route('/api/venues/{id}/external-labels/{label}', name: 'api_venue_external_labels_detach', methods: ['DELETE'])]
    public function detach(string $id, string $label): Response
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $venue = $this->ownedVenue($id);
        if (!$venue instanceof Venue) {
            return $this->json(['error' => 'Gymnase introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Retirer un alias ne touche AUCUNE rencontre : un domicile déjà rattaché
        // garde son gymnase (le lien est posé, plus l'alias qui l'a produit).
        $venue->removeExternalLabel($this->labelNormalizer->normalize($label));
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Re-pointe vers $venue tous les domiciles NON PLACÉS de la saison dont le
     * libellé normalisé égale $label (quel que soit leur gymnase actuel), sans
     * jamais toucher un domicile PLACÉ/SOUMIS/VALIDÉ ni aucun statut.
     *
     * @return array{0: int, 1: int} [re-pointés (venue changé), placés conservés]
     */
    private function reassignHomeFixtures(Venue $venue, string $label): array
    {
        $attached = 0;
        $kept = 0;
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy([
            'homeAway' => FixtureHomeAway::HOME,
            'seasonId' => $venue->getSeasonId(),
        ]);
        foreach ($fixtures as $fixture) {
            $fixtureLabel = $fixture->getFbiVenueLabel();
            if (null === $fixtureLabel || $this->labelNormalizer->normalize($fixtureLabel) !== $label) {
                continue;
            }
            if (FixtureStatus::UNPLACED !== $fixture->getStatus()) {
                ++$kept;

                continue;
            }
            if ($fixture->getVenueId() !== $venue->getId()) {
                $fixture->setVenueId($venue->getId());
                ++$attached;
            }
        }

        return [$attached, $kept];
    }

    /**
     * Le gymnase par id, mais seulement s'il appartient au club courant : le club
     * vient du contexte (jamais du corps), et un gymnase d'un AUTRE club rend `null`
     * → 404 byte-identique. Défense explicite (idiome {@see VenuePeriodGridActionController}),
     * en plus de la RLS : jamais une écriture cross-tenant, même si l'entité est déjà
     * managée en mémoire.
     */
    private function ownedVenue(string $id): ?Venue
    {
        $venue = $this->entityManager->getRepository(Venue::class)->find($id);
        if (!$venue instanceof Venue) {
            return null;
        }
        $currentClubId = $this->currentClubId();

        return null === $currentClubId || $venue->getClubId() === $currentClubId ? $venue : null;
    }

    private function currentClubId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }
        $clubId = $request?->headers->get('X-Club-Id');

        return \is_string($clubId) && '' !== $clubId ? $clubId : null;
    }

    /**
     * Le gymnase du club, DE LA MÊME SAISON (les alias sont copiés en N+1 : la copie
     * porte légitimement le même libellé), autre que $exceptVenueId, portant déjà cet
     * alias — ou null.
     */
    private function venueCarryingLabel(string $label, string $exceptVenueId, string $seasonId): ?Venue
    {
        foreach ($this->entityManager->getRepository(Venue::class)->findBy(['seasonId' => $seasonId]) as $venue) {
            if ($venue->getId() !== $exceptVenueId && \in_array($label, $venue->getExternalLabels(), true)) {
                return $venue;
            }
        }

        return null;
    }
}
