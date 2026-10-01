<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR des axes §7.1 « périmètre engagé » + « constraint semantics » sur
 * « le niveau d'une équipe JEUNE suit son engagement FFBB, par clic » (décision
 * fondateur 2026-10-01). Le confirm d'appariement
 * (`POST /api/ffbb/engagements/confirm`) peut désormais, SUR DEMANDE EXPLICITE
 * (`alignLevel`), écrire le niveau qu'implique un engagement jeune — mais c'est le
 * SEUL chemin autorisé, et toujours avec la valeur SERVEUR re-déduite.
 *
 * L'invariant engagé a deux faces, prouvées ensemble :
 *  - la garde générique reste SOUVERAINE — le PUT /api/teams refuse toujours (409,
 *    mot pour mot) un changement de niveau sur une équipe qui joue ;
 *  - le confirm n'écrit le niveau que si `alignLevel` est demandé ET l'engagement
 *    est éligible (U9–U18, championnat/brassage) ; sinon rien ne bouge. La valeur
 *    est déduite côté serveur (un `level` client est ignoré), jamais sur une équipe
 *    d'un autre club (422, rien écrit), et le tag NIVEAU en découle.
 *
 * Sans ce gate, affaiblir la garde générique, ou faire écrire un niveau client /
 * un niveau sur une ligne inéligible, passerait invisible aux autres checks.
 * Backend FFBB = le stub déterministe (services_test.yaml).
 */
#[Group('phase1')]
#[Group('integration')]
final class FfbbLevelAlignmentTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testTheGenericPutStillRefusesALevelChangeOnAnEngagedTeamWordForWord(): void
    {
        [$token, , $clubId] = $this->register('FLAA');
        $team = $this->createTeam($clubId);
        $this->setTeamLevel($team, TeamLevel::REGIONAL);
        $this->engage($team); // au moins un match → équipe engagée

        $this->client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => $team->getName(),
            'sportCategoryId' => $team->getSportCategoryId(),
            'priorityTierId' => $team->getPriorityTierId(),
            'level' => 'DEPARTEMENTAL',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409, 'la garde générique du périmètre engagé reste souveraine');
        self::assertStringContainsString(
            'Cette équipe joue en compétition : elle est inscrite sous son niveau actuel aupr',
            (string) $this->client->getResponse()->getContent(),
            'le message de refus est celui du périmètre engagé, mot pour mot',
        );
        $this->scopeGucToClub($clubId);
        $this->em->clear();
        self::assertSame(TeamLevel::REGIONAL, $this->em->getRepository(Team::class)->find($team->getId())?->getLevel());
    }

    public function testConfirmWithoutAlignLevelLeavesTheLevelUnchanged(): void
    {
        [$token, , $clubId] = $this->register('FLAB');
        $this->useStubClubCode($clubId);
        $team = $this->createTeam($clubId); // niveau null

        $this->confirm($token, [['ffbbCompetitionId' => FfbbHttpClientStub::YOUNG_D13_COMPETITION_ID, 'teamId' => $team->getId()]]);
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(Team::class)->find($team->getId())?->getLevel(),
            'sans alignLevel, un niveau non renseigné le reste — jamais comblé en douce',
        );
    }

    public function testConfirmWithAlignLevelWritesTheDeducedLevelIgnoringTheClientValueAndTagsIt(): void
    {
        [$token, , $clubId] = $this->register('FLAC');
        $this->useStubClubCode($clubId);
        $team = $this->createTeam($clubId); // niveau null
        $this->engage($team); // l'équipe joue déjà — le confirm écrit quand même (chemin FFBB contrôlé)
        $tier = $team->getPriorityTierId();

        $this->confirm($token, [[
            'ffbbCompetitionId' => FfbbHttpClientStub::YOUNG_D13_COMPETITION_ID,
            'teamId' => $team->getId(),
            'alignLevel' => true,
            'level' => 'ELITE', // valeur client hostile — doit être IGNORÉE
        ]]);
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $reloaded = $this->em->getRepository(Team::class)->find($team->getId());
        self::assertInstanceOf(Team::class, $reloaded);
        self::assertSame(TeamLevel::DEPARTEMENTAL, $reloaded->getLevel(), 'le niveau DÉDUIT (U13 départemental), pas la valeur client ELITE');
        self::assertSame($tier, $reloaded->getPriorityTierId(), 'le rang/tier n\'est jamais touché par l\'alignement');
        self::assertContains('DEPARTEMENTAL', $this->tagNamesOf($team->getId()), 'le tag NIVEAU déduit est présent');
    }

    public function testAlignLevelIsANoOpOnIneligibleEngagements(): void
    {
        [$token, , $clubId] = $this->register('FLAD');
        $this->useStubClubCode($clubId);

        $ineligible = [
            'seniors' => FfbbHttpClientStub::COMPETITION_ID,       // Pré test masculine (seniors)
            'coupe' => FfbbHttpClientStub::COMPETITION_ID_CUP,     // Coupe du Rhône U18 (type CUP)
            'pré-régional jeune' => FfbbHttpClientStub::YOUNG_PR17_COMPETITION_ID, // U17 PR → aucun TeamLevel
        ];

        foreach ($ineligible as $label => $ffbbCompetitionId) {
            $team = $this->createTeam($clubId);
            $this->confirm($token, [[
                'ffbbCompetitionId' => $ffbbCompetitionId,
                'teamId' => $team->getId(),
                'alignLevel' => true,
            ]]);
            self::assertResponseStatusCodeSame(200, $label);

            $this->scopeGucToClub($clubId);
            $this->em->clear();
            self::assertNull(
                $this->em->getRepository(Team::class)->find($team->getId())?->getLevel(),
                \sprintf('alignLevel sur une ligne inéligible (%s) est un no-op silencieux', $label),
            );
        }
    }

    public function testAlignLevelOnAYoungLineDoesNotWriteOnASeniorTeam(): void
    {
        // Revue sécurité 2026-10-02 — apparier une ligne JEUNE (U13) à une équipe SENIORS avec
        // alignLevel ne doit JAMAIS écrire son niveau : l'éligibilité regarde aussi l'ÉQUIPE,
        // pas seulement la ligne, sinon on contourne le verrou du périmètre engagé.
        [$token, , $clubId] = $this->register('FLAG');
        $this->useStubClubCode($clubId);
        $team = $this->createTeam($clubId, 'Seniors'); // équipe NON jeune, niveau null

        $this->confirm($token, [[
            'ffbbCompetitionId' => FfbbHttpClientStub::YOUNG_D13_COMPETITION_ID,
            'teamId' => $team->getId(),
            'alignLevel' => true,
        ]]);
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(Team::class)->find($team->getId())?->getLevel(),
            'une équipe senior ne reçoit jamais le niveau déduit d\'une ligne jeune',
        );
    }

    public function testConfirmCannotAlignTheLevelOfAForeignTeam(): void
    {
        [$tokenA, , $clubA] = $this->register('FLAE');
        $this->useStubClubCode($clubA);
        $this->createTeam($clubA); // fixe le socle de A — la garde passe, l'appariement doit échouer
        [, , $clubB] = $this->register('FLAF');
        $teamB = $this->createTeam($clubB);

        $this->confirm($tokenA, [[
            'ffbbCompetitionId' => FfbbHttpClientStub::YOUNG_D13_COMPETITION_ID,
            'teamId' => $teamB->getId(),
            'alignLevel' => true,
        ]]);
        self::assertResponseStatusCodeSame(422, 'une équipe d\'un autre club est invisible à travers les filtres');

        $this->scopeGucToClub($clubB);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Team::class)->find($teamB->getId())?->getLevel(), 'rien écrit sur l\'équipe étrangère');
        self::assertCount(0, $this->em->getRepository(Competition::class)->findBy(['teamId' => $teamB->getId()]));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param list<array<string, mixed>> $pairings
     */
    private function confirm(string $token, array $pairings): void
    {
        $this->client->request('POST', '/api/ffbb/engagements/confirm', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['pairings' => $pairings], \JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function tagNamesOf(string $teamId): array
    {
        $this->scopeGucToClub($this->em->getRepository(Team::class)->find($teamId)?->getClubId() ?? '');
        $assignments = $this->em->getRepository(TeamTagAssignment::class)->findBy(['teamId' => $teamId]);
        $names = [];
        foreach ($assignments as $assignment) {
            $tag = $this->em->getRepository(TeamTag::class)->find($assignment->getTagId());
            if ($tag instanceof TeamTag) {
                $names[] = $tag->getName();
            }
        }

        return $names;
    }

    private function setTeamLevel(Team $team, TeamLevel $level): void
    {
        $this->scopeGucToClub($team->getClubId());
        $managed = $this->em->getRepository(Team::class)->find($team->getId());
        \assert($managed instanceof Team);
        $managed->setLevel($level);
        $this->em->flush();
    }

    private function engage(Team $team): void
    {
        $this->scopeGucToClub($team->getClubId());
        $competition = new Competition;
        $competition->setClubId($team->getClubId());
        $competition->setSeasonId($team->getSeasonId());
        $competition->setTeamId($team->getId());
        $competition->setName('ENGAGE');
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);

        $fixture = new Fixture;
        $fixture->setClubId($team->getClubId());
        $fixture->setSeasonId($team->getSeasonId());
        $fixture->setTeamId($team->getId());
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable('+7 days'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('AS TEST NORD');
        $this->em->persist($fixture);
        $this->em->flush();
    }

    private function useStubClubCode(string $clubId): void
    {
        $club = $this->em->getRepository(Club::class)->find($clubId);
        \assert($club instanceof Club);
        $club->setFfbbClubCode(FfbbHttpClientStub::CLUB_CODE);
        $this->em->flush();
    }

    private function createSeason(string $clubId, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($clubId);
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->settleSeasonPlan($season);

        return $season;
    }

    private function createTeam(string $clubId, string $categoryName = 'U13'): Team
    {
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId])
            ?? $this->createSeason($clubId, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        if (null === $this->chosenPlanVersion($season)) {
            $this->settleSeasonPlan($season);
        }

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        // Le NOM de catégorie porte la tranche d'âge (« U13 » → jeune, « Seniors » → non) :
        // TeamTagService::isYouthTeam le lit.
        $category = new SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName($categoryName . '-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName($categoryName . '-Test');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /** @return array{0: string, 1: string, 2: string} [token, userId, clubId] */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Alignment', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
