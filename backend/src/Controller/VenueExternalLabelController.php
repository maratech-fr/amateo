<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fixture;
use App\Entity\Venue;
use App\Enum\FixtureHomeAway;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\ManagementAccessGuard;
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
 *  - POST   /api/venues/{id}/external-labels          → ajoute l'alias (normalisé,
 *    idempotent) PUIS backfille les domiciles du club encore sans salle dont le
 *    libellé égale l'alias. Un libellé déjà porté par un AUTRE gymnase → 422 ; un
 *    libellé vide après normalisation → 422.
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

        // Un libellé ne désigne qu'UN gymnase : s'il est déjà porté par un autre,
        // on refuse en le nommant (« retirez-le d'abord ») plutôt que de créer une
        // ambiguïté qui empêcherait toute résolution confirmée.
        $owner = $this->venueCarryingLabel($label, $venue->getId(), $venue->getSeasonId());
        if ($owner instanceof Venue) {
            return $this->json(
                ['error' => \sprintf('Ce libellé est déjà rattaché au gymnase « %s ». Retirez-le d\'abord.', $owner->getName())],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $venue->addExternalLabel($label);

        // Backfill : les domiciles du club encore sans salle dont le libellé FBI/FFBB
        // égale l'alias reçoivent ce gymnase (statut inchangé — jamais un placement).
        // `attached` ne compte que les nouveaux rattachés : un re-POST ne recompte
        // rien (les rencontres déjà rattachées ne sont plus « sans salle »).
        $attached = 0;
        // Borne SAISON explicite (défense en profondeur sous le filtre saison) : les
        // alias sont copiés au changement de saison, un domicile de N-1 ne doit
        // jamais recevoir le gymnase de N.
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
