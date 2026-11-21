<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\Gender;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Service\TeamTagService;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Promesse : avant le merge de la PR 3 du lot conception 1, la copie de production n° 1 dit si
 * les étiquettes ENREGISTRÉES de chaque club sont celles que le calcul à la lecture rend — sortie 0
 * sur une base saine, toutes saisons comprises ; sortie 1 en désignant, par identifiant seulement,
 * chaque écart qui changerait le groupe d'une équipe réelle (plan du lot, §5 et §11).
 *
 * Commande jetable : elle part avec les deux tables (première PR du lot 3), ce test avec elle.
 * Le décor « tel que la production l'a enregistré » se pose avec la règle elle-même
 * (`TeamTagService::catalog`, `tagNamesOf`), puis chaque cas y introduit UN écart.
 */
#[Group('integration')]
final class VerifyStoredTeamTagsCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private const string ARCHIVED_TEAM_NAME = 'Équipe archivée nommée';

    private const string ACTIVE_TEAM_NAME = 'Équipe active nommée';

    private EntityManagerInterface $em;

    private Club $club;

    private Season $archived;

    private Team $archivedTeam;

    private Team $activeTeam;

    /** @var array<string, TeamTag> nom → ligne enregistrée */
    private array $storedTags = [];

    /**
     * Un écart par cas ; la commande doit le désigner par l'identifiant nommé.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function differences(): iterable
    {
        yield 'rattachement en trop, saison archivée' => ['extra-assignment'];
        yield 'rattachement manquant, saison archivée' => ['missing-assignment'];
        yield 'étiquette hors catalogue' => ['tag-outside-catalog'];
        yield 'étiquette du catalogue enregistrée non système' => ['tag-not-system'];
        yield 'couleur différente du catalogue' => ['tag-other-color'];
        yield 'axe différent du catalogue' => ['tag-other-axis'];
        yield 'rattachement à une étiquette hors catalogue' => ['assignment-outside-catalog'];
        yield 'règle qui vise un nom hors catalogue' => ['rule-outside-catalog'];
        yield 'équipe dont la catégorie n\'existe plus' => ['category-gone'];
    }

    public function testASaneBaseExitsZeroAcrossEverySeason(): void
    {
        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode(), 'base saine, saison archivée comprise : ' . $tester->getDisplay());
    }

    #[DataProvider('differences')]
    public function testADifferenceExitsOneAndNamesItByIdentifierOnly(string $difference): void
    {
        $this->scopeGucToClub($this->club->getId());
        $expectedId = match ($difference) {
            'extra-assignment' => $this->assign($this->archivedTeam, 'U15'),
            'missing-assignment' => $this->unassign($this->archivedTeam, 'JEUNE'),
            'tag-outside-catalog' => $this->storeTag('MAISON')->getId(),
            'tag-not-system' => $this->alterTag('U13', 'is_system = false'),
            'tag-other-color' => $this->alterTag('U13', 'color = \'#000000\''),
            'tag-other-axis' => $this->alterTag('U13', 'axis = \'NIVEAU\''),
            'assignment-outside-catalog' => $this->assignToNewTag($this->activeTeam, 'MAISON'),
            'rule-outside-catalog' => $this->ruleTargeting('MAISON'),
            'category-gone' => $this->dropCategoryOf($this->archivedTeam),
        };

        $tester = $this->runCommand();
        $display = $tester->getDisplay();

        self::assertSame(1, $tester->getStatusCode(), 'un écart sort 1 : ' . $display);
        self::assertStringContainsString($expectedId, $display, 'l\'écart est désigné par son identifiant');
        self::assertStringNotContainsString(self::ARCHIVED_TEAM_NAME, $display, 'identifiants seulement : jamais un nom d\'équipe');
        self::assertStringNotContainsString(self::ACTIVE_TEAM_NAME, $display, 'identifiants seulement : jamais un nom d\'équipe');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Club contrôle étiquettes');
        $this->club->setSlug('verify-tags-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('bball-verify-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->archived = $this->season('2024-2025', '2024-09-01', '2025-06-30', SeasonStatus::ARCHIVED);
        $active = $this->season('2025-2026', '2025-09-01', '2026-06-30', SeasonStatus::ACTIVE);
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
        $u13 = $this->category($sport, 'U13', 12, 13);
        $senior = $this->category($sport, 'Senior', 22, 99);
        $this->em->flush();

        $this->archivedTeam = $this->team($this->archived, $u13, self::ARCHIVED_TEAM_NAME, Gender::F, TeamLevel::DEPARTEMENTAL);
        $this->activeTeam = $this->team($active, $senior, self::ACTIVE_TEAM_NAME, Gender::M, TeamLevel::REGIONAL);
        $this->em->flush();

        $this->storeAsProductionHasIt();
    }

    /**
     * Les deux tables telles que la production les tient : le catalogue entier, système, et pour
     * chaque équipe exactement ce que le calcul rend. Ce qu'une écriture d'équipe aurait pu y
     * poser avant est effacé d'abord.
     */
    private function storeAsProductionHasIt(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM team_tag_assignment WHERE club_id = :club', ['club' => $this->club->getId()]);
        $connection->executeStatement('DELETE FROM team_tag WHERE club_id = :club', ['club' => $this->club->getId()]);
        $this->em->clear();

        $service = self::getContainer()->get(TeamTagService::class);
        foreach ($service->catalog() as $entry) {
            $tag = $this->storeTag($entry['name']);
            $tag->setColor($entry['color']);
            $tag->setAxis($entry['axis']);
        }
        $this->em->flush();
        foreach ([$this->archivedTeam, $this->activeTeam] as $team) {
            foreach ($service->tagNamesOf($team) as $name) {
                $this->assign($team, $name);
            }
        }
    }

    private function runCommand(): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel)->find('app:team-tags:verify-stored'));
        $tester->execute(['--club' => [$this->club->getId()]]);

        return $tester;
    }

    private function storeTag(string $name): TeamTag
    {
        $tag = new TeamTag;
        $tag->setClubId($this->club->getId());
        $tag->setName($name);
        $tag->setIsSystem(true);
        $this->em->persist($tag);
        $this->em->flush();
        $this->storedTags[$name] = $tag;

        return $tag;
    }

    /** @return string l'identifiant de l'équipe rattachée */
    private function assign(Team $team, string $tagName): string
    {
        $assignment = new TeamTagAssignment;
        $assignment->setClubId($this->club->getId());
        $assignment->setTeamId($team->getId());
        $assignment->setTagId($this->storedTags[$tagName]->getId());
        $assignment->setSeasonId($team->getSeasonId());
        $this->em->persist($assignment);
        $this->em->flush();

        return $team->getId();
    }

    /** @return string l'identifiant de l'équipe privée de son rattachement */
    private function unassign(Team $team, string $tagName): string
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM team_tag_assignment WHERE team_id = :team AND tag_id = :tag',
            ['team' => $team->getId(), 'tag' => $this->storedTags[$tagName]->getId()],
        );

        return $team->getId();
    }

    /** @return string l'identifiant de l'étiquette modifiée */
    private function alterTag(string $name, string $set): string
    {
        $this->em->getConnection()->executeStatement(
            \sprintf('UPDATE team_tag SET %s WHERE id = :id', $set),
            ['id' => $this->storedTags[$name]->getId()],
        );

        return $this->storedTags[$name]->getId();
    }

    /** @return string l'identifiant de l'étiquette hors catalogue */
    private function assignToNewTag(Team $team, string $tagName): string
    {
        $this->storeTag($tagName);
        $this->assign($team, $tagName);

        return $this->storedTags[$tagName]->getId();
    }

    /** @return string l'identifiant de la règle */
    private function ruleTargeting(string $tagName): string
    {
        $rule = new Constraint;
        $rule->setClubId($this->club->getId());
        $rule->setSeasonId($this->activeTeam->getSeasonId());
        $rule->setName('Groupe maison');
        $rule->setScope(ConstraintScope::CLUB);
        $rule->setFamily(ConstraintFamily::TIME);
        $rule->setRuleType(ConstraintRuleType::PREFERRED);
        $rule->setConfig(['targetTags' => [$tagName], 'minStartTime' => '19:00']);
        $this->em->persist($rule);
        $this->em->flush();

        return $rule->getId();
    }

    /** @return string l'identifiant de l'équipe dont la catégorie a disparu */
    private function dropCategoryOf(Team $team): string
    {
        $this->em->getConnection()->executeStatement('DELETE FROM sport_category WHERE id = :id', ['id' => $team->getSportCategoryId()]);
        $this->em->clear();

        return $team->getId();
    }

    private function season(string $name, string $start, string $end, SeasonStatus $status): Season
    {
        $season = new Season;
        $season->setClubId($this->club->getId());
        $season->setName($name);
        $season->setStartDate(new DateTimeImmutable($start));
        $season->setEndDate(new DateTimeImmutable($end));
        $season->setStatus($status);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function category(Sport $sport, string $name, int $ageMin, int $ageMax): SportCategory
    {
        $category = new SportCategory;
        $category->setClubId($this->club->getId());
        $category->setSportId($sport->getId());
        $category->setName($name);
        $category->setAgeMin($ageMin);
        $category->setAgeMax($ageMax);
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        return $category;
    }

    private function team(Season $season, SportCategory $category, string $name, Gender $gender, TeamLevel $level): Team
    {
        $team = new Team;
        $team->setClubId($this->club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(1);
        $team->setName($name);
        $team->setGender($gender);
        $team->setLevel($level);
        $team->setSessionsPerWeek(1);
        $team->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }
}
