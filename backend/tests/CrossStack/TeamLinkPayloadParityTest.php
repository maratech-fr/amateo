<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamLink;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Enum\TeamLinkIntensity;
use App\Enum\TeamLinkType;
use App\Plans\PlanComposition;
use App\Service\ScheduleConstraintBuilder;
use App\State\Processor\TeamLinkStateProcessor;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClassConstant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axes backend↔engine contract + pipeline de génération (§7.1).
 *
 * Lot PASSERELLES PR-1 : ce que le club STOCKE (passerelles {teamAId, teamBId, intensity},
 * STRUCTURE de club+saison — patron Team/Coach, PAS ancré au plan) doit être EXACTEMENT le bloc
 * `teamLinks` que le payload émet au solveur, socle ET période, AVEC son intensité côté
 * entraînement. Le bloc est FILTRÉ au roster du payload : une équipe absente (désactivée pour la
 * période) fait ABANDONNER son lien — jamais un teamId fantôme.
 *
 * Falsifié dans les DEUX sens : une passerelle stockée DOIT apparaître (un builder qui émettrait
 * [] échoue) ET un lien dont une équipe est hors roster NE doit PAS apparaître (un builder aveugle
 * au roster échoue) ; l'égalité exacte fait tomber toute passerelle fantôme.
 */
#[Group('phase1')]
#[Group('integration')]
final class TeamLinkPayloadParityTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    /**
     * Sens 1 — le socle émet EXACTEMENT ses passerelles stockées, avec leur intensité (MANDATORY
     * ici, pas le défaut : l'intensité VOYAGE). Un builder émettant [] échoue.
     */
    /**
     * Le cap de saisie backend est un MIROIR MANUEL de `MAX_TEAM_LINKS` (engine,
     * input_schema.py) — même régime que CONTRACT_VERSION : pas de codegen, l'égalité
     * est une discipline, et une discipline non testée dérive (la version l'a prouvé
     * deux fois). Sans cette égalité, la 51ᵉ passerelle passerait la saisie et ferait
     * 422-FAILED la GÉNÉRATION — une panne loin de sa cause.
     */
    public function testWriteCapMirrorsTheEngineEdgeCap(): void
    {
        $schema = file_get_contents(__DIR__ . '/../../../engine/app/schemas/input_schema.py');
        self::assertIsString($schema);
        self::assertSame(1, preg_match('/^MAX_TEAM_LINKS = (\d+)$/m', $schema, $m), 'MAX_TEAM_LINKS introuvable dans input_schema.py — le motif a changé, ce garde est aveugle.');

        $mirrored = new ReflectionClassConstant(TeamLinkStateProcessor::class, 'MAX_TEAM_LINKS');

        self::assertSame((int) $m[1], $mirrored->getValue(), 'Le cap de saisie backend a dérivé du cap au bord engine — recaler les DEUX littéraux ensemble.');
    }

    public function testClubSeasonPayloadEmitsStoredLinksWithIntensity(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season);
        $t2 = $this->team($club, $season);
        $this->em->flush();

        $link = $this->teamLink($club, $season, $t1, $t2, TeamLinkIntensity::MANDATORY);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame(
            [[
                'id' => $link->getId(),
                'teamAId' => $link->getTeamAId(),
                'teamBId' => $link->getTeamBId(),
                'intensity' => 'MANDATORY',
            ]],
            $payload['teamLinks'],
            'le socle émet EXACTEMENT la passerelle stockée, avec son intensité',
        );
    }

    /**
     * Une passerelle stockée apparaît AUSSI dans le payload de période (structure club+saison,
     * pas de copie de plan) tant que ses deux équipes sont au roster de la période.
     */
    public function testPeriodPayloadEmitsStoredLinkWhenBothTeamsInRoster(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season);
        $t2 = $this->team($club, $season);
        $this->em->flush();

        $link = $this->teamLink($club, $season, $t1, $t2, TeamLinkIntensity::PREFERRED);
        $entry = $this->holidayPeriod($club, $season);
        $planId = $this->planIdOf($entry);
        $this->em->flush();

        $payload = $this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);

        self::assertSame(
            [[
                'id' => $link->getId(),
                'teamAId' => $link->getTeamAId(),
                'teamBId' => $link->getTeamBId(),
                'intensity' => 'PREFERRED',
            ]],
            $payload['teamLinks'],
            'la période émet la même passerelle club+saison quand les deux équipes sont au roster',
        );
    }

    /**
     * Sens 2 — roster de période : une équipe DÉSACTIVÉE pour la période sort du payload, donc
     * toute passerelle la nommant est ABANDONNÉE ; la passerelle dont les deux équipes restent
     * actives, elle, survit. Un builder aveugle au roster émettrait le lien fantôme → échoue.
     */
    public function testPeriodPayloadDropsLinkWhenATeamIsDeactivated(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season);
        $t2 = $this->team($club, $season);
        $t3 = $this->team($club, $season);
        $this->em->flush();

        // Deux passerelles : {t1,t2} (survit) et {t1,t3} (t3 désactivée pour la période → tombe).
        $kept = $this->teamLink($club, $season, $t1, $t2, TeamLinkIntensity::PREFERRED);
        $dropped = $this->teamLink($club, $season, $t1, $t3, TeamLinkIntensity::MANDATORY);

        $entry = $this->holidayPeriod($club, $season);
        $planId = $this->planIdOf($entry);
        $this->deactivateForPeriod($club, $season, $planId, $t3);
        $this->em->flush();

        $payload = $this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);

        // La passerelle {t1,t2} survit, EXACTEMENT ; {t1,t3} est absente.
        self::assertSame(
            [[
                'id' => $kept->getId(),
                'teamAId' => $kept->getTeamAId(),
                'teamBId' => $kept->getTeamBId(),
                'intensity' => 'PREFERRED',
            ]],
            $payload['teamLinks'],
            'le lien nommant une équipe désactivée est abandonné ; l\'autre survit exactement',
        );

        // Aucun teamId de l'équipe désactivée ne fuit dans le bloc émis.
        $emitted = array_merge(
            array_column($payload['teamLinks'], 'teamAId'),
            array_column($payload['teamLinks'], 'teamBId'),
        );
        self::assertNotContains($t3->getId(), $emitted, 'une équipe hors roster ne doit pas fuir');
        self::assertNotContains($dropped->getId(), array_column($payload['teamLinks'], 'id'), 'le lien abandonné ne doit pas apparaître');
    }

    /**
     * Aucune passerelle stockée ⇒ bloc VIDE : chemin byte-identique côté moteur (default_factory=list).
     */
    public function testNoLinkEmitsEmptyBlock(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame([], $payload['teamLinks']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
    }

    private function team(Club $club, Season $season): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->uuid());
        $team->setPriorityTierId(3);
        $team->setName('T' . substr($this->uuid(), 0, 6));
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 2]]);

        return $team;
    }

    private function teamLink(Club $club, Season $season, Team $a, Team $b, TeamLinkIntensity $intensity): TeamLink
    {
        // Normalise le couple (teamAId < teamBId) comme le fait le processor.
        $teamAId = $a->getId();
        $teamBId = $b->getId();
        if (strcasecmp($teamAId, $teamBId) > 0) {
            [$teamAId, $teamBId] = [$teamBId, $teamAId];
        }
        $link = new TeamLink;
        $link->setClubId($club->getId());
        $link->setSeasonId($season->getId());
        $link->setTeamAId($teamAId);
        $link->setTeamBId($teamBId);
        $link->setLinkType(TeamLinkType::NOT_SIMULTANEOUS);
        $link->setTrainingIntensity($intensity);
        $this->em->persist($link);

        return $link;
    }

    private function deactivateForPeriod(Club $club, Season $season, string $planId, Team $team): void
    {
        $override = self::getContainer()->get(PlanComposition::class)->lineOf($planId, $team->getId());
        $override->setParticipating(false);
        $override->setSessionsPerWeek(self::getContainer()->get(PlanComposition::class)->socleLinesOf([$team->getId()])[$team->getId()]->getSessionsPerWeek());
    }

    private function holidayPeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Reprise');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        return $entry;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('TeamLink Parity Club');
        $club->setSlug('teamlink-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('TLP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('teamlink-parity-' . $uid . '@test.com');
        $user->setFirstName('T');
        $user->setLastName('L');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $season->getId());

        return [$club, $season];
    }
}
