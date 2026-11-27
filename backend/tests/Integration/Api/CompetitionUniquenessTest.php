<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse : une équipe n'a jamais deux compétitions de même nom — la résolution d'import en dépend (P4-67 : le
 * résolveur prenait la première). Depuis la PR 3b du lot 7, ce n'est plus la base qui le garantit (aucune unicité sur
 * le nom d'une compétition, plan §11) mais la porte unique, par UNE comparaison normalisée : `POST /api/competitions`
 * refuse 422 une seconde compétition de ce nom pour l'équipe, à la casse et aux accents près. Deux équipes en « DF2 »
 * restent légitimes (deux équipes du club dans une division, Q8).
 *
 * Jumeau : l'import passe par la même porte (`FbiFixtureImporterTest::
 * testAMappedDivisionWhoseNameDiffersOnlyByCaseReusesTheTeamsCompetition`).
 */
#[Group('integration')]
final class CompetitionUniquenessTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private User $user;

    #[TestWith(['DF2', 'DF2'])]
    #[TestWith(['DF2', 'df2'])]
    #[TestWith(['REGIONALE 2', 'Régionale 2'])]
    public function testASecondCompetitionOfTheSameNameForATeamIsRefusedAtTheDoor(string $first, string $twin): void
    {
        $team = $this->persistTeam('U15 A');
        $this->postCompetition($team, $first);
        self::assertResponseStatusCodeSame(201);

        $this->postCompetition($team, $twin);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('L\'équipe U15 A a déjà une compétition « ', (string) ($this->responseData()['violations'][0]['message'] ?? ''));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement WHERE team_id = ?', [$team]), 'aucune seconde compétition pour l\'équipe');
    }

    public function testTwoTeamsMayShareACompetitionName(): void
    {
        $teamA = $this->persistTeam('U11 A');
        $teamB = $this->persistTeam('U11 B');

        $this->postCompetition($teamA, 'DFU11-2');
        self::assertResponseStatusCodeSame(201);
        $this->postCompetition($teamB, 'DFU11-2');
        self::assertResponseStatusCodeSame(201, 'deux équipes du club dans une même division : deux compétitions de même nom, légitimes');

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM competition WHERE club_id = ? AND name = ?', [$this->club->getId(), 'DFU11-2']));

        // Contrat de lecture (PR 3b) : `?teamId=` rend les compétitions où l'équipe est engagée, chacune avec ses
        // engagements ; l'équipe ne se lit plus sur la compétition.
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);
        $this->client->request('GET', '/api/competitions?teamId=' . $teamB, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $members = $this->responseData()['member'] ?? [];
        self::assertCount(1, $members, 'seule la compétition où U11 B est engagée');
        self::assertSame([$teamB], array_column($members[0]['engagements'] ?? [], 'teamId'));
        self::assertArrayNotHasKey('teamId', $members[0], 'l\'équipe vit sur l\'engagement');
    }

    /**
     * Promesse (lot 7, PR 3b, fin de C30) : `POST /api/competitions` refuse 422 l'équipe du club qui n'est pas de la
     * saison résolue — une compétition de cette saison n'engage jamais l'équipe d'une autre saison. Le pendant
     * « autre club » est `MatchTenantIsolationTest::testCreatingACompetitionForAnotherClubsTeamIsRefused`.
     */
    public function testATeamOfAnotherSeasonOfTheClubIsRefused(): void
    {
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1;
        $past = (new Season)->setClubId($this->club->getId())->setName((string) $year)->setStartDate(new DateTimeImmutable($year . '-08-01'))
            ->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))->setStatus(SeasonStatus::ARCHIVED)->setTransitionData([]);
        $this->em->persist($past);
        $this->em->flush();
        $team = $this->persistTeam('U15 passée', $past);

        $this->postCompetition($team, 'Championnat U15');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Équipe inconnue pour ce club et cette saison.', $this->responseData()['violations'][0]['message'] ?? null);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement WHERE team_id = ?', [$team]), 'aucun engagement de l\'équipe d\'une autre saison');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $this->club = (new Club)->setName('Club unicité')->setSlug('club-unicite-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $this->user = (new User)->setEmail('unicite' . $uid . '@test.com')->setFirstName('U')->setLastName('Nicité');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($this->user->getId())->setRole('admin')->setIsActive(true));
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->season = (new Season)->setClubId($this->club->getId())->setName((string) $year)->setStartDate(new DateTimeImmutable($year . '-08-01'))
            ->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();
        $this->settleSeasonPlan($this->season);
    }

    private function postCompetition(string $teamId, string $name): void
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);
        $this->client->request('POST', '/api/competitions', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => $name, 'competitionType' => 'CHAMPIONSHIP', 'teamId' => $teamId,
        ], \JSON_THROW_ON_ERROR));
    }

    private function persistTeam(string $name, ?Season $season = null): string
    {
        $season ??= $this->season;
        $this->scopeGucToClub($this->club->getId());
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = (new SportCategory)->setClubId($this->club->getId())->setSportId($sport->getId())->setName($name . ' ' . uniqid('', true));
        $this->em->persist($category);
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($season->getId())->setSportCategoryId($category->getId())
            ->setPriorityTierId(3)->setName($name)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
