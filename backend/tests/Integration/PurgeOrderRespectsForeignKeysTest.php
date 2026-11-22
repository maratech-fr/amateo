<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\Season;
use App\Enum\SeasonStatus;
use App\Service\ErasedClubPurger;
use App\Structure\FamilyCatalog;
use App\Tests\CapturesDeletedTablesTrait;
use App\Tests\RealForeignKeysTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axe tenant isolation (purges de saison et effacement RGPD) : « une purge efface
 * chaque enfant avant le parent dont il meurt », lu dans l'ordre RÉELLEMENT exécuté par
 * l'effacement d'un club (qui purge chaque saison puis le club), contre les clés étrangères réelles.
 *
 * Pourquoi l'ordre et pas « aucune erreur » : en base, presque toute clé étrangère est `ON DELETE
 * CASCADE` ou `SET NULL` (seule `RESTRICT` : `fk_coach_person_club`, du coach vers sa fiche de
 * personne). Un parent effacé avant son enfant ne lève donc en général rien : la cascade emporte
 * l'enfant en silence, hors de la purge et de son compte rendu. Le test
 * exige, pour chaque clé qui fait mourir l'enfant ou qui bloquerait (CASCADE, RESTRICT, NO ACTION)
 * entre deux tables que la purge vise, que le `DELETE` de l'enfant précède celui du parent. Une clé
 * `SET NULL` n'impose aucun ordre (c'est elle qui rompt le cycle plan ↔ version).
 *
 * Second verrou : les familles purgées par leur propriétaire (sans `club_id` + `season_id` qui les
 * borneraient seules) partent avant ce propriétaire — sinon la sous-requête qui les désigne ne
 * trouve plus rien et elles survivent, orphelines.
 *
 * Angles jumeaux, cités : `Security/FamilyDeclarationSweepTest::testTheSeasonPurgeRemovesEachChildBeforeTheParentItDiesWith`
 * (l'ordre que le catalogue RANGE, contre les déclarations) ; ici l'ordre que la purge EXÉCUTE,
 * contre la base.
 */
#[Group('phase1')]
#[Group('integration')]
final class PurgeOrderRespectsForeignKeysTest extends KernelTestCase
{
    use CapturesDeletedTablesTrait;
    use RealForeignKeysTrait;
    use TenantGucTrait;

    /** Règles qui font mourir l'enfant avec son parent, ou qui refuseraient un parent effacé d'abord. */
    private const array ORDERING_RULES = ['c', 'r', 'a'];

    private EntityManagerInterface $em;

    public function testEveryChildIsDeletedBeforeTheParentItsForeignKeyTiesItTo(): void
    {
        $executed = $this->erasureOrder();

        $checked = [];
        $inverted = [];
        foreach ($this->realForeignKeys() as $key) {
            if (!\in_array($key['onDelete'], self::ORDERING_RULES, true) || $key['child'] === $key['parent']) {
                continue;
            }
            $child = array_search($key['child'], $executed, true);
            $parent = array_search($key['parent'], $executed, true);
            if (false === $child || false === $parent) {
                continue;
            }
            $checked[] = $key['name'];
            if ($child > $parent) {
                $inverted[] = \sprintf('%s.%s → %s (%s) : l\'enfant est effacé APRÈS son parent', $key['child'], $key['column'], $key['parent'], $key['name']);
            }
        }

        // Témoin positif : sans au moins une clé entre deux tables purgées, l'assertion serait vide.
        self::assertNotSame([], $checked, 'Aucune clé étrangère entre deux tables purgées : le test ne prouverait rien.');
        self::assertSame([], $inverted, \sprintf(
            "La purge efface un parent avant l'enfant que sa clé étrangère fait mourir avec lui :\n  - %s\n"
            . "La cascade l'emporte en silence, hors du compte rendu de purge. Ordre exécuté :\n  %s",
            implode("\n  - ", $inverted),
            implode(', ', $executed),
        ));
    }

    public function testFamiliesPurgedThroughTheirOwnerGoBeforeTheOwner(): void
    {
        $executed = $this->erasureOrder();
        $metadata = $this->em->getMetadataFactory();

        $owners = self::getContainer()->get(FamilyCatalog::class)->purgedViaOwner();
        self::assertNotSame([], $owners, 'Aucune famille purgée par son propriétaire : le test ne prouverait rien.');

        $late = [];
        foreach ($owners as $family => [$field, $owner]) {
            $familyTable = $metadata->getMetadataFor($family)->getTableName();
            $ownerTable = $metadata->getMetadataFor($owner)->getTableName();
            $familyAt = array_search($familyTable, $executed, true);
            $ownerAt = array_search($ownerTable, $executed, true);
            self::assertIsInt($familyAt, $familyTable . ' n\'est jamais effacée par la purge.');
            self::assertIsInt($ownerAt, $ownerTable . ' n\'est jamais effacée par la purge.');
            if ($familyAt > $ownerAt) {
                $late[] = \sprintf('%s (par %s) après %s', $familyTable, $field, $ownerTable);
            }
        }

        self::assertSame([], $late, \sprintf(
            "Une famille purgée par son propriétaire passe après lui — sa sous-requête ne la trouve plus :\n  - %s",
            implode("\n  - ", $late),
        ));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * L'ordre des `DELETE` de l'effacement d'un club à une saison : la purge de saison (ligne de
     * saison comprise) puis la purge par club.
     *
     * @return list<string>
     */
    private function erasureOrder(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Ordre ' . $uid)->setSlug('ordre-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('ORD' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $this->em->persist((new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE));
        $this->em->flush();

        $purger = self::getContainer()->get(ErasedClubPurger::class);

        return $this->deletedTablesDuring(static fn (): int => $purger->purge($club));
    }
}
