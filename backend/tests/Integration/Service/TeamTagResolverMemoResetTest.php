<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\SeasonStatus;
use App\Plans\PlanComposition;
use App\Service\TeamTagResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Promesse : le mémo « nom → équipes » de {@see TeamTagResolver} ne sert jamais les groupes d'avant
 * une édition. Deux portes : la remise à zéro entre deux messages du worker (`kernel.reset`,
 * ce que `ResetServicesListener` appelle) et l'écriture d'une équipe ou d'une catégorie dans le
 * même processus (`TeamTagMemoResetListener`).
 */
#[Group('integration')]
final class TeamTagResolverMemoResetTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private TeamTagResolver $resolver;

    private Club $club;

    private Season $season;

    private SportCategory $category;

    private Team $team;

    /**
     * Deux messages successifs : la catégorie change entre eux SANS événement Doctrine (SQL brut,
     * comme une édition faite par un autre processus). Le second message voit le nouveau groupe.
     */
    public function testTheNextWorkerMessageSeesTheNewGroups(): void
    {
        self::assertSame([$this->team->getId()], $this->u('U11'), 'premier message : l\'équipe est U11');

        $this->em->getConnection()->executeStatement(
            'UPDATE sport_category SET name = \'U13\', age_min = 12, age_max = 13 WHERE id = :id',
            ['id' => $this->category->getId()],
        );
        $this->em->clear();
        self::assertSame([$this->team->getId()], $this->u('U11'), 'témoin : dans le même message, le mémo tient encore');

        // Fin du message : ce que le worker appelle entre deux messages.
        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->scopeGucToClub($this->club->getId());

        self::assertSame([$this->team->getId()], $this->u('U13'), 'second message : l\'équipe est U13');
        self::assertSame([], $this->u('U11'), 'et n\'est plus U11');
    }

    /** Une écriture de catégorie dans le même processus vide le mémo, sans remise à zéro explicite. */
    public function testWritingACategoryEmptiesTheMemo(): void
    {
        self::assertSame([$this->team->getId()], $this->u('U11'));

        $this->category->setName('U13');
        $this->category->setAgeMin(12);
        $this->category->setAgeMax(13);
        $this->em->flush();

        self::assertSame([$this->team->getId()], $this->u('U13'));
        self::assertSame([], $this->u('U11'));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(TeamTagResolver::class);
        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Club mémo étiquettes');
        $this->club->setSlug('tag-memo-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('bball-memo-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        if (!$this->em->getRepository(PriorityTier::class)->find(1) instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $this->category = new SportCategory;
        $this->category->setClubId($this->club->getId());
        $this->category->setSportId($sport->getId());
        $this->category->setName('U11');
        $this->category->setAgeMin(10);
        $this->category->setAgeMax(11);
        $this->category->setIsCustom(false);
        $this->category->setSortOrder(0);
        $this->em->persist($this->category);
        $this->em->flush();

        $this->team = new Team;
        $this->team->setClubId($this->club->getId());
        $this->team->setSeasonId($this->season->getId());
        $this->team->setSportCategoryId($this->category->getId());
        $this->team->setPriorityTierId(1);
        $this->team->setName('U11 1');
        $this->team->setIsActive(true);
        $this->em->persist($this->team);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($this->team->getSeasonId(), [$this->team->getId() => ['sessionsPerWeek' => 1]]);
        $this->em->flush();
        $this->resolver->reset();
    }

    /** @return list<string> */
    private function u(string $name): array
    {
        return $this->resolver->tagTeamIds($name, $this->season->getId(), $this->club->getId());
    }
}
