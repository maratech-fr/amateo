<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\ScheduleConstraintBuilder;
use App\Service\TeamTagResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class ScheduleConstraintBuilderTest extends TestCase
{
    private ScheduleConstraintBuilder $builder;

    private EntityManagerInterface&MockObject $entityManager;

    /** @var EntityRepository<TeamTag>&MockObject */
    private EntityRepository&MockObject $teamTagRepository;

    /** @var EntityRepository<TeamTagAssignment>&MockObject */
    private EntityRepository&MockObject $teamTagAssignmentRepository;

    private LoggerInterface&MockObject $logger;

    public function testResolveTagFiltersByClubId(): void
    {
        $seasonId = 'season-1';
        $club1Id = 'club-1';
        $tag = (new TeamTag)
            ->setId('tag-jeune-club-1')
            ->setClubId($club1Id)
            ->setName('JEUNE');

        $this->teamTagRepository->expects(self::once())
            ->method('findOneBy')
            ->with(self::callback(static fn (array $criteria): bool => 'JEUNE' === ($criteria['name'] ?? null)
                    && $club1Id === ($criteria['clubId'] ?? null)))
            ->willReturn($tag);

        $this->teamTagAssignmentRepository->method('findBy')->willReturnCallback(
            static function (array $criteria): array {
                if ('tag-jeune-club-1' !== ($criteria['tagId'] ?? null)) {
                    return [];
                }

                $first = (new TeamTagAssignment)
                    ->setTeamId('team-a')
                    ->setTagId('tag-jeune-club-1')
                    ->setSeasonId('season-1');
                $second = (new TeamTagAssignment)
                    ->setTeamId('team-b')
                    ->setTagId('tag-jeune-club-1')
                    ->setSeasonId('season-1');

                return [$first, $second];
            },
        );

        $club1TeamIds = $this->invokeResolveTagToTeamIds('JEUNE', $seasonId, $club1Id);
        self::assertSame(['team-a', 'team-b'], $club1TeamIds);
    }

    public function testResolveTagLogsWarningWhenNotFound(): void
    {
        $seasonId = 'season-1';
        $clubId = 'club-1';

        $this->teamTagRepository->method('findOneBy')->willReturn(null);
        // PSR-3 (revue #340 round 2) : le MESSAGE porte des placeholders stables — un
        // message unique par tag/club casserait le regroupement des agrégateurs de logs.
        // Les VALEURS vivent dans le contexte : c'est lui qu'on vérifie.
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                self::callback(static fn (string $message): bool => str_contains($message, '{targetTag}') && str_contains($message, '{clubId}')),
                self::callback(static fn (array $context): bool => 'JEUNE' === ($context['targetTag'] ?? null)
                        && $clubId === ($context['clubId'] ?? null)
                        && $seasonId === ($context['seasonId'] ?? null)),
            );

        $result = $this->invokeResolveTagToTeamIds('JEUNE', $seasonId, $clubId);

        self::assertSame([], $result);
    }

    public function testIndivisibleVenueForcesSlotCapacityToOne(): void
    {
        $slot = (new VenueTrainingSlot)
            ->setDayOfWeek(1)
            ->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)
            ->setCapacity(2);

        $method = new ReflectionMethod($this->builder, 'buildTrainingSlots');
        $method->setAccessible(true);

        /** @var array<int, array{capacity: int}> $indivisible */
        $indivisible = $method->invoke($this->builder, [$slot], false);
        self::assertSame(1, $indivisible[0]['capacity'], 'indivisible venue caps at 1');

        /** @var array<int, array{capacity: int}> $splittable */
        $splittable = $method->invoke($this->builder, [$slot], true);
        self::assertSame(2, $splittable[0]['capacity'], 'splittable venue keeps slot capacity');
    }

    public function testPriorityTierConstraintDoesNotSendOrToolsWeight(): void
    {
        $tier = (new PriorityTier)
            ->setId(1)
            ->setLabel('S')
            ->setOrToolsWeight(10000)
            ->setDefaultMinSessions(2);

        $method = new ReflectionMethod($this->builder, 'serializePriorityTierConstraints');
        $method->setAccessible(true);

        /** @var array<int, array{value: mixed, metadata: array<string, mixed>}> $result */
        $result = $method->invoke($this->builder, [$tier]);

        self::assertNull($result[0]['value']);
        self::assertArrayNotHasKey('orToolsWeight', $result[0]['metadata']);
        self::assertSame(2, $result[0]['metadata']['defaultMinSessions']);
    }

    public function testClubWideConstraintExpandsToEveryTeam(): void
    {
        // Audit P0.1 (dead "Toutes les équipes" cell): a CLUB-scope TIME/DAY/
        // FACILITY rule must reach the engine as one TEAM constraint per team —
        // the engine only applies these families to a team target.
        $constraint = (new Constraint)
            ->setId('c-club')
            ->setName('Toutes les équipes · pas mercredi')
            ->setScope(ConstraintScope::CLUB)
            ->setFamily(ConstraintFamily::DAY)
            ->setRuleType(ConstraintRuleType::PREFERRED)
            ->setConfig(['forbiddenDays' => [3]])
            ->setSortOrder(0)
            ->setIsActive(true);
        $teams = [$this->team('team-a'), $this->team('team-b')];

        $serialized = $this->invokeSerializeUnified([$constraint], 'season-1', 'club-1', $teams);

        self::assertCount(2, $serialized);
        self::assertSame(['team-a', 'team-b'], array_column($serialized, 'scopeTargetId'));
        foreach ($serialized as $row) {
            self::assertSame('TEAM', $row['scope']);
            self::assertSame('DAY', $row['family']);
            self::assertSame('PREFERRED', $row['ruleType']);
            self::assertSame(['forbiddenDays' => [3]], $row['config']);
        }
    }

    public function testEmptyTagResolutionIsANoOpNeverAClubWideBan(): void
    {
        // Review NR: a HARD "prefer venue" on a tag resolving to ZERO teams used
        // to skip the positive constraints but still run the "forbidden outside
        // the tag" loop — banning the venue for EVERY team of the club.
        $this->teamTagRepository->method('findOneBy')->willReturn(null);

        $constraint = (new Constraint)
            ->setId('c-tag')
            ->setName('Groupe fantôme · préfère Gymnase A')
            ->setScope(ConstraintScope::CLUB)
            ->setFamily(ConstraintFamily::FACILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['targetTag' => 'FANTOME', 'preferredVenueId' => 'v-1'])
            ->setSortOrder(0)
            ->setIsActive(true);

        $serialized = $this->invokeSerializeUnified([$constraint], 'season-1', 'club-1', [$this->team('team-a'), $this->team('team-b')]);

        self::assertSame([], $serialized, 'an unresolvable tag must emit NOTHING (no club-wide forbiddenVenueId)');
    }

    public function testForcedVenueOnTagDoesNotReserveItForTeamsOutsideTheTag(): void
    {
        // D1 (décision fondateur, lecture 1) : « impose Gymnase A au groupe FEMININE »
        // FORCE le groupe sur le gymnase et NE RÉSERVE RIEN aux autres équipes — plus
        // aucune ligne `forbiddenVenueId` implicite « interdit hors tag ». Une exclusivité,
        // si elle est voulue, se saisit ; elle ne se déduit pas d'un « impose ».
        $tag = (new TeamTag)->setId('tag-fem')->setClubId('club-1')->setName('FEMININE');
        $this->teamTagRepository->method('findOneBy')->willReturn($tag);
        $this->teamTagAssignmentRepository->method('findBy')->willReturn([
            (new TeamTagAssignment)->setTeamId('team-a')->setTagId('tag-fem')->setSeasonId('season-1'),
        ]);

        $constraint = (new Constraint)
            ->setId('c-impose')
            ->setName('Groupe FEMININE · impose Gymnase A')
            ->setScope(ConstraintScope::CLUB)
            ->setFamily(ConstraintFamily::FACILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['targetTag' => 'FEMININE', 'forcedVenueId' => 'v-1'])
            ->setSortOrder(0)
            ->setIsActive(true);

        $serialized = $this->invokeSerializeUnified([$constraint], 'season-1', 'club-1', [$this->team('team-a'), $this->team('team-b')]);

        $forced = array_values(array_filter($serialized, static fn (array $r): bool => 'v-1' === ($r['config']['forcedVenueId'] ?? null)));
        $forbidden = array_values(array_filter($serialized, static fn (array $r): bool => 'v-1' === ($r['config']['forbiddenVenueId'] ?? null)));
        self::assertCount(1, $forced, 'the tagged team is forced onto the venue');
        self::assertSame('team-a', $forced[0]['scopeTargetId']);
        self::assertSame([], $forbidden, 'aucune équipe hors tag ne se voit interdire le gymnase — « impose » ne réserve rien (D1)');
    }

    /**
     * P2-29 — cibler PLUSIEURS tags = INTERSECTION : seules les équipes portant TOUS les
     * tags reçoivent la contrainte. « ADULTE et COMPETITION » ne doit PAS toucher une équipe
     * adulte de loisir, ni une équipe jeune en compétition.
     */
    public function testMultiTargetConstraintExpandsToTheTagIntersection(): void
    {
        $this->builder = $this->builderWithTagSets([
            'ADULTE' => ['sm1', 'sf1', 'loisir-adulte'],
            'COMPETITION' => ['sm1', 'sf1', 'u15-compet'],
        ]);

        $constraint = (new Constraint)
            ->setId('c-inter')
            ->setName('Adultes en compétition · pas le mercredi')
            ->setScope(ConstraintScope::CLUB)
            ->setFamily(ConstraintFamily::DAY)
            ->setRuleType(ConstraintRuleType::PREFERRED)
            ->setConfig(['targetTags' => ['ADULTE', 'COMPETITION'], 'forbiddenDays' => [3]])
            ->setSortOrder(0)
            ->setIsActive(true);

        $serialized = $this->invokeSerializeUnified([$constraint], 'season-1', 'club-1', []);

        self::assertSame(['sf1', 'sm1'], array_column($serialized, 'scopeTargetId'), 'seules les équipes des DEUX tags — triées');
        foreach ($serialized as $row) {
            self::assertSame(['forbiddenDays' => [3]], $row['config'], 'aucune clé de tag ne part au moteur (contrat inchangé)');
        }
    }

    /**
     * P2-29 D7/D8 — « ADULTE sauf LOISIR_ADULTE » : l'exclusion RETIRE ses équipes de la
     * cible. Le cas réel du terrain (les adultes en compétition, PAS le Basket Santé loisir).
     */
    public function testExcludeTagsAreSubtractedFromTheTarget(): void
    {
        $this->builder = $this->builderWithTagSets([
            'ADULTE' => ['sm1', 'sf1', 'u21', 'basket-sante'],
            'LOISIR_ADULTE' => ['basket-sante'],
        ]);

        $constraint = (new Constraint)
            ->setId('c-excl')
            ->setName('Adultes hors loisir · finissent avant 22h')
            ->setScope(ConstraintScope::CLUB)
            ->setFamily(ConstraintFamily::TIME)
            ->setRuleType(ConstraintRuleType::PREFERRED)
            ->setConfig(['targetTags' => ['ADULTE'], 'excludeTags' => ['LOISIR_ADULTE'], 'maxStartTime' => '20:00'])
            ->setSortOrder(0)
            ->setIsActive(true);

        $serialized = $this->invokeSerializeUnified([$constraint], 'season-1', 'club-1', []);

        self::assertSame(['sf1', 'sm1', 'u21'], array_column($serialized, 'scopeTargetId'), 'le loisir adulte est retiré, le reste demeure');
    }

    /**
     * P2-29 D8 — `excludeTags` SEUL (aucune cible) : base = TOUTES les équipes de la saison,
     * moins les exclues. La liste d'équipes passée au builder EST cette base.
     */
    public function testExcludeOnlyStartsFromEveryTeamOfTheSeason(): void
    {
        $this->builder = $this->builderWithTagSets([
            'BABY' => ['baby1'],
        ]);

        $constraint = (new Constraint)
            ->setId('c-excl-only')
            ->setName('Tout le monde sauf les bébés · pas le dimanche')
            ->setScope(ConstraintScope::CLUB)
            ->setFamily(ConstraintFamily::DAY)
            ->setRuleType(ConstraintRuleType::PREFERRED)
            ->setConfig(['excludeTags' => ['BABY'], 'forbiddenDays' => [7]])
            ->setSortOrder(0)
            ->setIsActive(true);

        $serialized = $this->invokeSerializeUnified(
            [$constraint],
            'season-1',
            'club-1',
            [$this->team('baby1'), $this->team('sm1'), $this->team('u13')],
        );

        self::assertSame(['sm1', 'u13'], array_column($serialized, 'scopeTargetId'), 'toutes les équipes SAUF le bébé exclu');
    }

    /**
     * P2-29 D11 — LE contrat ne bouge pas : quelle que soit la forme du ciblage, AUCUNE
     * ligne du payload ne porte une clé de tag (`targetTag`/`targetTags`/`excludeTags`). Le
     * moteur ne voit jamais un tag ; l'expansion est purement backend.
     */
    public function testNoTagKeyEverLeaksIntoTheSerializedPayload(): void
    {
        $this->builder = $this->builderWithTagSets([
            'ADULTE' => ['sm1', 'sf1', 'basket-sante'],
            'COMPETITION' => ['sm1', 'sf1'],
            'LOISIR_ADULTE' => ['basket-sante'],
        ]);

        $constraint = (new Constraint)
            ->setId('c-contract')
            ->setName('Adultes en compétition hors loisir')
            ->setScope(ConstraintScope::CLUB)
            ->setFamily(ConstraintFamily::FACILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig([
                'targetTags' => ['ADULTE', 'COMPETITION'],
                'excludeTags' => ['LOISIR_ADULTE'],
                'forcedVenueId' => 'v-1',
            ])
            ->setSortOrder(0)
            ->setIsActive(true);

        $serialized = $this->invokeSerializeUnified([$constraint], 'season-1', 'club-1', [$this->team('sm1'), $this->team('sf1'), $this->team('basket-sante')]);

        self::assertNotSame([], $serialized, 'la contrainte produit bien des lignes');
        foreach ($serialized as $row) {
            $config = $row['config'];
            self::assertIsArray($config);
            foreach (TeamTagResolver::TAG_CONFIG_KEYS as $tagKey) {
                self::assertArrayNotHasKey($tagKey, $config, \sprintf('« %s » ne doit JAMAIS partir au moteur', $tagKey));
            }
        }
    }

    public function testCoachAvailabilityIsNeverClubExpanded(): void
    {
        $constraint = (new Constraint)
            ->setId('c-coach')
            ->setName('coach indispo')
            ->setScope(ConstraintScope::COACH)
            ->setFamily(ConstraintFamily::COACH_AVAILABILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['coachId' => 'co-1', 'unavailableDays' => [1]])
            ->setSortOrder(0)
            ->setIsActive(true);

        $serialized = $this->invokeSerializeUnified([$constraint], 'season-1', 'club-1', [$this->team('team-a')]);

        self::assertCount(1, $serialized);
        self::assertSame('COACH', $serialized[0]['scope']);
    }

    public function testReservationsAreSerializedIntoSlotTemplatesAsHardPins(): void
    {
        $reservation = (new Reservation)
            ->setClubId('club-1')
            ->setSeasonId('season-1')
            ->setSchedulePlanId(null) // réservation de BASE
            ->setTeamId('team-sm1')
            ->setVenueId('venue-mateo')
            ->setDayOfWeek(2)
            ->setStartTime(new DateTimeImmutable('20:30'))
            ->setDurationMinutes(120);

        $payload = $this->builder->buildPayload(
            clubId: 'club-1',
            seasonId: 'season-1',
            reservations: [$reservation],
        );

        self::assertCount(1, $payload['slotTemplates']);
        $slot = $payload['slotTemplates'][0];
        self::assertSame('team-sm1', $slot['teamId']);
        self::assertSame('venue-mateo', $slot['venueId']);
        self::assertSame(2, $slot['dayOfWeek']);
        self::assertSame('20:30:00', $slot['startTime']);
        self::assertSame('HARD', $slot['lockLevel'], 'a reservation is a HARD pin the engine must honor');
    }

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->teamTagRepository = $this->createMock(EntityRepository::class);
        $this->teamTagAssignmentRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->entityManager->method('getRepository')->willReturnMap([
            [TeamTag::class, $this->teamTagRepository],
            [TeamTagAssignment::class, $this->teamTagAssignmentRepository],
        ]);

        // P2-14 : la résolution de tag vit dans TeamTagResolver (source unique) — le
        // builder ne fait que déléguer. On lui passe un résolveur bâti sur le MÊME EM
        // mocké : les attentes de repository ci-dessus restent le contrat testé.
        $this->builder = new ScheduleConstraintBuilder($this->logger, $this->entityManager, null, null, new TeamTagResolver($this->entityManager, $this->logger));
    }

    private function team(string $id): Team
    {
        $team = new Team;
        $team->setId($id);
        $team->setName($id);

        return $team;
    }

    /**
     * @param list<Constraint> $constraints
     * @param list<Team>       $teams
     *
     * @return array<array<string, mixed>>
     */
    private function invokeSerializeUnified(array $constraints, string $seasonId, string $clubId, array $teams): array
    {
        $method = new ReflectionMethod($this->builder, 'serializeUnifiedConstraints');
        $method->setAccessible(true);

        /** @var array<array<string, mixed>> $result */
        $result = $method->invoke($this->builder, $constraints, $seasonId, $clubId, $teams);

        return $result;
    }

    /** @return list<string> */
    private function invokeResolveTagToTeamIds(string $targetTag, string $seasonId, string $clubId): array
    {
        $method = new ReflectionMethod($this->builder, 'resolveTagToTeamIds');
        $method->setAccessible(true);

        // P2-29 : la résolution prend désormais le CONFIG entier (targetTag legacy inclus)
        // et la liste d'équipes (base D8 pour l'exclusion sans cible — inutile ici).
        /** @var list<string> $result */
        $result = $method->invoke($this->builder, ['targetTag' => $targetTag], $seasonId, $clubId, []);

        return $result;
    }

    /**
     * P2-29 — un résolveur bâti sur un EM mocké dont chaque tag NOMMÉ renvoie un jeu
     * d'équipes distinct, pour éprouver l'intersection et l'exclusion au niveau du builder.
     *
     * @param array<string, list<string>> $teamsByTag nom de tag => teamIds
     */
    private function builderWithTagSets(array $teamsByTag): ScheduleConstraintBuilder
    {
        $tagIdByName = [];
        foreach (array_keys($teamsByTag) as $name) {
            $tagIdByName[$name] = 'tag-' . $name;
        }

        $this->teamTagRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($tagIdByName): ?TeamTag {
                $name = $criteria['name'] ?? null;
                if (!\is_string($name) || !isset($tagIdByName[$name])) {
                    return null;
                }

                return (new TeamTag)->setId($tagIdByName[$name])->setClubId('club-1')->setName($name);
            },
        );
        $this->teamTagAssignmentRepository->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($teamsByTag, $tagIdByName): array {
                $tagId = $criteria['tagId'] ?? null;
                $name = array_search($tagId, $tagIdByName, true);
                if (false === $name) {
                    return [];
                }

                return array_map(
                    static fn (string $teamId): TeamTagAssignment => (new TeamTagAssignment)->setTeamId($teamId)->setTagId((string) $tagId)->setSeasonId('season-1'),
                    $teamsByTag[$name],
                );
            },
        );

        return new ScheduleConstraintBuilder($this->logger, $this->entityManager, null, null, new TeamTagResolver($this->entityManager, $this->logger));
    }
}
