<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Tests\ComposesPlanTeamsTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse (lot 4, PR 3a, ligne 23 du plan) : un réglage de période se publie comme une mise à jour. Chaque équipe a
 * déjà sa ligne dans le plan ; publier deux fois le réglage d'une équipe pour un plan la met à jour, avec le même code
 * de réponse, sans erreur — l'écran ne sait pas si une ligne « existe », il publie ce que le gestionnaire a réglé.
 * La liste rend une seule ligne pour le couple.
 *
 * Les cas « gymnase » (PR 3b) et « règle » (PR 4) rejoindront ce fichier.
 */
#[Group('integration')]
final class PeriodSettingUpsertTest extends WebTestCase
{
    use ComposesPlanTeamsTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $user;

    private Season $season;

    private string $holiday;

    private string $teamId;

    public function testPublishingTheSameTeamSettingTwiceUpdatesIt(): void
    {
        // Une ligne égale au socle, sans origine, n'est pas un réglage : la liste ne la rend pas (§5 du plan).
        $this->client->request('GET', '/api/team_period_overrides?schedulePlanId=' . $this->holiday, [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? null, 'avant toute publication, aucun réglage listé');

        $first = $this->publish(['schedulePlanId' => $this->holiday, 'teamId' => $this->teamId, 'isActive' => true, 'sessionsPerWeek' => 1]);
        $second = $this->publish(['schedulePlanId' => $this->holiday, 'teamId' => $this->teamId, 'isActive' => true, 'sessionsPerWeek' => 1, 'maxSessionsPerDay' => 2]);

        self::assertSame($first, $second, 'la seconde publication répond comme la première, sans erreur');
        self::assertSame(['participates' => true, 'sessionsPerWeek' => 1, 'maxSessionsPerDay' => 2, 'source' => null], $this->lineRow($this->holiday, $this->teamId), 'la ligne porte le dernier réglage publié');

        $this->client->request('GET', '/api/team_period_overrides?schedulePlanId=' . $this->holiday, [], [], $this->headers());
        self::assertResponseIsSuccessful();
        $members = json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? [];
        self::assertIsArray($members);
        $mine = array_values(array_filter($members, fn (mixed $m): bool => \is_array($m) && $this->teamId === ($m['teamId'] ?? null)));
        self::assertCount(1, $mine, 'une seule ligne pour le couple (plan, équipe)');
        self::assertSame([1, 2], [$mine[0]['sessionsPerWeek'] ?? null, $mine[0]['maxSessionsPerDay'] ?? null]);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $club = (new Club)->setName('Publication ' . $uid)->setSlug('publication-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->user = (new User)->setEmail('publication' . $uid . '@test.com')->setFirstName('P')->setLastName('Ublication');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($this->user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $this->season->getId());
        $team = (new Team)->setClubId($club->getId())->setSeasonId($this->season->getId())->setSportCategoryId('33333333-3333-3333-3333-333333333333')
            ->setPriorityTierId(1)->setName('SM1')->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        $this->teamId = $team->getId();
        $this->setSocleTeam($this->season->getId(), $this->teamId, 2);
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Noël')
            ->setStartDate(new DateTimeImmutable('2025-12-22'))->setEndDate(new DateTimeImmutable('2025-12-28'));
        $this->em->persist($entry);
        $this->holiday = $this->planIdOf($entry);
        $this->layTeamLines($this->season->getId());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return int le code de réponse
     */
    private function publish(array $body): int
    {
        $this->client->request('POST', '/api/team_period_overrides', [], [], $this->headers(), json_encode($body, \JSON_THROW_ON_ERROR));
        $code = $this->client->getResponse()->getStatusCode();
        self::assertContains($code, [200, 201], 'la publication réussit : ' . $this->client->getResponse()->getContent());

        return $code;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
    }
}
