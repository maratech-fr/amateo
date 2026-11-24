<?php

declare(strict_types=1);

namespace App\Matches;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\ApiResource\FixtureResource;
use App\Command\EngineRequestsRecordCommand;
use App\Controller\FixtureConflictsController;
use App\Entity\ConflictResolution;
use App\Entity\Season;
use App\Repository\ConflictResolutionRepository;
use App\Service\Basketball\VenueAliasResolver;
use App\Service\ConflictFeedAssembler;
use App\Service\EngineRequestRecorder;
use App\Service\TenantConnectionContext;
use App\State\Provider\FixtureStateProvider;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Les vues des matchs d'un club et d'une saison, telles que le gestionnaire les reçoit — le filet
 * d'une migration du lot 7 là où la demande au moteur ne voit rien (l'import, le radar) :
 *   - `fixtures` : `GET /api/fixtures` en `application/json` (même provider, même sérialisation),
 *     toutes les pages, triée par `id` ;
 *   - `conflicts` : `GET /api/fixtures/conflicts['conflicts']` (même assembleur, champ `resolution`
 *     compris), triée par `fingerprint` — un conflit n'a pas d'id. Les lignes `held` sont hors vue.
 *
 * Lecture seule : aucun balayage (l'amical passé n'est pas validé, contrairement à l'accueil des
 * matchs), aucune écriture. Appelé club RELÂCHÉ, comme {@see EngineRequestRecorder::requestsOfClub} :
 * pose lui-même le club (GUC de la RLS, filtres Doctrine club et saison que le radar et le provider
 * lisent par `findBy([])`), puis relâche tout et vide l'EntityManager, pour que rien d'un club ne
 * reste pour le suivant.
 *
 * Écrit sur disque par {@see EngineRequestsRecordCommand}, au format d'empreintes (sans nom en clair).
 */
final readonly class MatchViewRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantConnectionContext $tenantConnectionContext,
        private FixtureStateProvider $fixtureProvider,
        private ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
        private SerializerInterface $serializer,
        private ConflictFeedAssembler $feedAssembler,
        private ConflictResolutionRepository $resolutionRepository,
        private VenueAliasResolver $venueAliasResolver,
    ) {}

    /** @param array<string, mixed> $row */
    private static function stringOf(array $row, string $key): string
    {
        return \is_string($row[$key] ?? null) ? $row[$key] : '';
    }

    /**
     * @return array{fixtures: list<array<string, mixed>>, conflicts: list<array<string, mixed>>}
     */
    public function record(string $clubId, string $seasonId): array
    {
        $this->tenantConnectionContext->setClubId($clubId);
        $filters = $this->entityManager->getFilters();
        $filters->enable('tenant_filter')->setParameter('club_id', $clubId, 'uuid');
        $filters->enable('season_filter')->setParameter('season_id', $seasonId, 'uuid');
        // Mémoïsé par requête : hors requête, les gymnases d'un club resteraient pour le suivant.
        $this->venueAliasResolver->reset();

        try {
            return ['fixtures' => $this->fixtures(), 'conflicts' => $this->conflicts($clubId, $seasonId)];
        } finally {
            try {
                $this->venueAliasResolver->reset();
                foreach (['tenant_filter', 'season_filter'] as $name) {
                    if ($filters->isEnabled($name)) {
                        $filters->disable($name);
                    }
                }
                $this->entityManager->clear();
            } finally {
                $this->tenantConnectionContext->clear();
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function fixtures(): array
    {
        $operation = $this->resourceMetadataFactory->create(FixtureResource::class)->getOperation(null, true, true);
        if (!$operation instanceof CollectionOperationInterface || !$operation instanceof HttpOperation) {
            throw new LogicException('GET /api/fixtures : opération de collection introuvable.');
        }
        // Toutes les pages : la vue est le contenu servi, pas sa première page.
        $operation = $operation->withPaginationEnabled(false);
        $outputs = $this->fixtureProvider->provide($operation);
        $outputs = is_iterable($outputs) ? [...$outputs] : [];

        // Le contexte de sérialisation de `SerializerContextBuilder::createFromRequest` pour cette
        // opération, hors ce qui ne vaut que pour une requête (URI) et n'entre pas en `application/json`.
        $context = ($operation->getNormalizationContext() ?? []) + [
            'operation_name' => $operation->getName(),
            'operation' => $operation,
            'resource_class' => FixtureResource::class,
            'skip_null_values' => true,
            'iri_only' => false,
            'input' => $operation->getInput(),
            'output' => $operation->getOutput(),
        ];

        $rows = $this->decodedRows($this->serializer->serialize($outputs, 'json', $context));
        usort($rows, static fn (array $a, array $b): int => strcmp(self::stringOf($a, 'id'), self::stringOf($b, 'id')));

        return $rows;
    }

    /**
     * Le flux de {@see FixtureConflictsController::conflicts()} : assemblé, décoré du statut de
     * traitement joint par empreinte, sérialisé comme `AbstractController::json()`.
     *
     * @return list<array<string, mixed>>
     */
    private function conflicts(string $clubId, string $seasonId): array
    {
        $season = $this->entityManager->find(Season::class, $seasonId);
        $feed = $this->feedAssembler->assemble($clubId, $season instanceof Season ? $season : null);

        $byFingerprint = $season instanceof Season ? $this->resolutionRepository->mapByFingerprint($season->getId()) : [];
        $conflicts = array_map(
            static function (array $conflict) use ($byFingerprint): array {
                $fingerprint = $conflict['fingerprint'] ?? null;
                $row = \is_string($fingerprint) ? ($byFingerprint[$fingerprint] ?? null) : null;

                // Même vue que `FixtureConflictsController::resolutionView()`.
                return $conflict + ['resolution' => $row instanceof ConflictResolution ? [
                    'status' => $row->getStatus()->value,
                    'note' => $row->getNote(),
                    'updatedAt' => $row->getUpdatedAt()->format(DateTimeInterface::ATOM),
                ] : null];
            },
            $feed['conflicts'],
        );

        $rows = $this->decodedRows($this->serializer->serialize($conflicts, 'json', ['json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS]));
        usort($rows, static fn (array $a, array $b): int => strcmp(self::stringOf($a, 'fingerprint'), self::stringOf($b, 'fingerprint')));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function decodedRows(string $json): array
    {
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $rows = [];
        foreach (\is_array($decoded) ? $decoded : [] as $row) {
            if (\is_array($row)) {
                /* @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
