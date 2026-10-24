<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Service\ScheduleConstraintBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Chantier D (CTC) PR1 — NR de NEUTRALITÉ MOTEUR, NON-GATANT (assumé).
 *
 * Promesse (décision fondateur 2026-10-24) : une CTC est un simple REPÈRE VISUEL, « le club la
 * traite comme une équipe à part entière — ZÉRO effet moteur/créneaux ». Donc le payload envoyé au
 * solveur doit être STRICTEMENT le même qu'elle soit déclarée en entente ou non : `isCtc` et
 * `ctcPartner` ne doivent JAMAIS franchir `ScheduleConstraintBuilder::serializeTeam` ni aucun autre
 * point du payload.
 *
 * Pourquoi ici et au plus bas : la promesse ne traverse ni le réseau ni le solveur réel — elle se lit
 * sur le PAYLOAD construit en mémoire. Le comparer byte-à-byte (json_encode identique) est le témoin
 * le moins cher et le plus dur : un seul champ qui fuiterait casserait l'égalité.
 *
 * Pourquoi NON-GATANT (et non un step `ci.yml` + `blocking-tests.md`) : l'axe §7.1 « constraint
 * semantics » garde qu'une contrainte SAISIE est HONORÉE par le solveur ; la CTC n'est PAS une
 * contrainte et n'ajoute RIEN au payload. Ce test garde l'INVERSE (que rien n'entre) — il protège un
 * raffinement d'affichage, pas une sémantique moteur. Le plan MORPHEUS (validé fondateur 2026-10-24)
 * le pose explicitement non-gatant ; il vit donc en `backend-tests` ordinaire, cité par
 * `test-coverage-map.md`. Jumeau d'intention : les `*PayloadParityTest` (SharedBlock, TeamLink,
 * VenueTravelTime) qui gardent « un bloc stocké vide ⇒ payload byte-identique ».
 *
 * Signatures attendues de NEO (option A — ce test ERREUR tant qu'elles n'existent pas :
 * « Call to undefined method App\Entity\Team::setIsCtc() ») :
 *   App\Entity\Team::setIsCtc(bool): self · setCtcPartner(?string): self
 * et `serializeTeam` INCHANGÉ (il ne lit ni n'émet ces deux champs).
 */
final class ScheduleConstraintBuilderCtcNeutralityTest extends TestCase
{
    private ScheduleConstraintBuilder $builder;

    private EntityManagerInterface&MockObject $entityManager;

    public function testACtcTeamProducesAByteIdenticalEnginePayload(): void
    {
        $ctc = (new Team)
            ->setId('team-ctc')
            ->setClubId('club-1')
            ->setSeasonId('season-1')
            ->setSportCategoryId('sport-category-u15f')
            ->setPriorityTierId(2)
            ->setName('U15F Entente')
            ->setSessionsPerWeek(2)
            ->setIsCtc(true)
            ->setCtcPartner('AS Voisine');

        $plain = (new Team)
            ->setId('team-ctc')
            ->setClubId('club-1')
            ->setSeasonId('season-1')
            ->setSportCategoryId('sport-category-u15f')
            ->setPriorityTierId(2)
            ->setName('U15F Entente')
            ->setSessionsPerWeek(2)
            ->setIsCtc(false)
            ->setCtcPartner(null);

        $withCtc = $this->builder->build([], [$ctc], []);
        $withoutCtc = $this->builder->build([], [$plain], []);

        // Le payload d'équipe n'a jamais entendu parler de la CTC : aucun champ `isCtc`/`ctcPartner`.
        self::assertArrayNotHasKey('isCtc', $withCtc['teams'][0], 'isCtc ne doit pas franchir serializeTeam');
        self::assertArrayNotHasKey('ctcPartner', $withCtc['teams'][0], 'ctcPartner ne doit pas franchir serializeTeam');

        // Et, de bout en bout, les deux payloads sont RIGOUREUSEMENT identiques : seule la CTC
        // change entre les deux équipes, donc toute différence prouverait une fuite moteur.
        self::assertSame(
            json_encode($withoutCtc, \JSON_THROW_ON_ERROR),
            json_encode($withCtc, \JSON_THROW_ON_ERROR),
            'déclarer une entente ne change RIEN au payload solveur (zéro effet moteur)',
        );
    }

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $sportCategoryRepository = $this->createMock(EntityRepository::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Patron ScheduleConstraintBuilderAgeFieldsTest : serializeTeam lit SportCategory (âges) et
        // deriveMatchDay lit TeamMatchHabit ; les repos de tags doivent répondre (findBy vide) sinon
        // getRepository rend null et le build lève un TypeError.
        $sportCategoryRepository->method('find')->willReturn(null);
        $emptyRepository = $this->createMock(EntityRepository::class);
        $emptyRepository->method('findBy')->willReturn([]);

        $this->entityManager->method('getRepository')->willReturnMap([
            [SportCategory::class, $sportCategoryRepository],
            [TeamTagAssignment::class, $emptyRepository],
            [TeamTag::class, $emptyRepository],
            [TeamMatchHabit::class, $emptyRepository],
        ]);

        $this->builder = new ScheduleConstraintBuilder($logger, $this->entityManager);
    }
}
