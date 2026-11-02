<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CoachWishDigestCommand;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Repository\ClubUserRepository;
use App\Repository\CoachWishTokenRepository;
use App\Service\ClubDay;
use App\Service\CoachWishMailBuilder;
use App\Service\CoachWishPerimeter;
use App\Service\TenantConnectionContext;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Digest quotidien des doléances (feature #10, lot C3) : email aux gestionnaires SEULEMENT
 * s'il y a de NOUVELLES réponses depuis le dernier digest (silence = rien), récap final
 * UNE fois après la deadline quel que soit l'état (D5). Patron PeriodReminderCommandTest.
 */
#[Group('phase1')]
#[Group('integration')]
final class CoachWishDigestCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private const TODAY = '2026-02-01';

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $sentTo = [];

    /** @var list<string> */
    private array $sentSubjects = [];

    public function testDigestGoesToManagersOnlyWhenANewResponseArrived(): void
    {
        [$club, $season, $adminEmail] = $this->seedClub('DIG');
        $campaign = $this->campaign($club, $season, deadline: '2026-02-20');
        $this->token($campaign, $club, 'Maxime', respondedAt: new DateTimeImmutable('2026-01-31 18:00:00'));
        $this->token($campaign, $club, 'Mara', respondedAt: null);
        $this->em->flush();

        $this->runCommand();

        self::assertContains($adminEmail, $this->sentTo, 'une nouvelle réponse déclenche le digest');
        self::assertStringContainsString('Maxime', $this->sentSubjects[0] ?? '');
    }

    public function testATokenWithoutTeamsTriggersNoDigest(): void
    {
        // Ex-destinataire ou adjoint : sa réponse ancienne ne compte plus, même lié à l'équipe.
        [$club, $season] = $this->seedClub('NOTEAM');
        $campaign = $this->campaign($club, $season, deadline: '2026-02-20');
        $this->token($campaign, $club, 'Maxime', respondedAt: new DateTimeImmutable('2026-01-31 18:00:00'))->setTeamIds([]);
        $this->em->flush();

        $this->runCommand();

        self::assertSame([], $this->sentTo);
    }

    public function testSilenceSinceLastDigestSendsNothing(): void
    {
        // Une réponse ANTÉRIEURE au dernier digest : rien de neuf → aucun email (fondateur).
        [$club, $season] = $this->seedClub('SIL');
        $campaign = $this->campaign($club, $season, deadline: '2026-02-20');
        $campaign->markDigestSent(new DateTimeImmutable('2026-01-31 07:00:00'));
        $this->token($campaign, $club, 'Maxime', respondedAt: new DateTimeImmutable('2026-01-30 18:00:00'));
        $this->em->flush();

        $this->runCommand();

        self::assertSame([], $this->sentTo, 'silence total → pas de nouvel email');
    }

    public function testSecondRunWithoutNewResponsesStaysSilent(): void
    {
        [$club, $season, $adminEmail] = $this->seedClub('TWICE');
        $campaign = $this->campaign($club, $season, deadline: '2026-02-20');
        $this->token($campaign, $club, 'Maxime', respondedAt: new DateTimeImmutable('2026-01-31 18:00:00'));
        $this->em->flush();

        $this->runCommand();
        self::assertContains($adminEmail, $this->sentTo);

        // Second run, aucune réponse nouvelle depuis le stamp → silence.
        $this->sentTo = [];
        $this->runCommand();
        self::assertSame([], $this->sentTo, 'le même état ne se re-signale pas');
    }

    public function testFinalRecapIsSentOnceAfterDeadlineEvenWithZeroResponses(): void
    {
        // Deadline dépassée, AUCUNE réponse : le récap part quand même (D5), UNE seule fois.
        [$club, $season, $adminEmail] = $this->seedClub('RECAP');
        $campaign = $this->campaign($club, $season, deadline: '2026-01-30');
        $this->token($campaign, $club, 'Maxime', respondedAt: null);
        $this->em->flush();

        $this->runCommand();
        self::assertContains($adminEmail, $this->sentTo, 'le récap part même à zéro réponse (D5)');
        self::assertStringContainsString('0/1', $this->sentSubjects[0] ?? '');

        $this->sentTo = [];
        $this->runCommand();
        self::assertSame([], $this->sentTo, 'le récap ne part qu\'une fois');
    }

    public function testDeadlineDayItselfStillCountsAsOpen(): void
    {
        // Deadline INCLUSE (D6) : le jour même, pas de récap — le digest normal s'applique.
        [$club, $season, $adminEmail] = $this->seedClub('EDGE');
        $campaign = $this->campaign($club, $season, deadline: self::TODAY);
        $this->token($campaign, $club, 'Maxime', respondedAt: new DateTimeImmutable('2026-01-31 23:00:00'));
        $this->em->flush();

        $this->runCommand();

        self::assertContains($adminEmail, $this->sentTo);
        self::assertStringNotContainsString('close', $this->sentSubjects[0] ?? '', 'le jour de la deadline est encore un digest, pas le récap');
    }

    public function testDigestStampNotAdvancedWhenEverySendFails(): void
    {
        // SMTP mort : le digest d'une nouvelle réponse ne doit PAS être perdu — lastDigestAt
        // reste inchangé pour être retenté le lendemain (fix code-review #1).
        [$club, $season] = $this->seedClub('FAIL');
        $campaign = $this->campaign($club, $season, deadline: '2026-02-20');
        $this->token($campaign, $club, 'Maxime', respondedAt: new DateTimeImmutable('2026-01-31 18:00:00'));
        $this->em->flush();

        $this->runCommand(failing: true);
        self::assertSame([], $this->sentTo, 'aucun email parti (SMTP mort)');

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        $reloaded = $this->em->getRepository(CoachWishCampaign::class)->find($campaign->getId());
        self::assertNull($reloaded?->getLastDigestAt(), 'le stamp n’avance pas sur échec total → digest retentable');

        // Le lendemain, mailer OK → le digest part enfin.
        $this->runCommand();
        self::assertNotSame([], $this->sentTo, 'le digest est bien retenté et délivré');
    }

    public function testFinalRecapStampNotAdvancedWhenSendFails(): void
    {
        [$club, $season] = $this->seedClub('RFAIL');
        $campaign = $this->campaign($club, $season, deadline: '2026-01-30');
        $this->token($campaign, $club, 'Maxime', respondedAt: null);
        $this->em->flush();

        $this->runCommand(failing: true);
        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        $reloaded = $this->em->getRepository(CoachWishCampaign::class)->find($campaign->getId());
        self::assertNull($reloaded?->getFinalRecapSentAt(), 'le récap n’est pas grillé par un échec d’envoi');
    }

    public function testACoachOutsideTheCurrentPerimeterIsNotCounted(): void
    {
        // Fix #6 : un coach dont l'équipe n'est plus dans teamIds ne doit pas figurer au digest.
        [$club, $season, $adminEmail] = $this->seedClub('PERIM');
        $campaign = $this->campaign($club, $season, deadline: '2026-02-20');
        $this->token($campaign, $club, 'Maxime', respondedAt: new DateTimeImmutable('2026-01-31 18:00:00'));

        // Un coach avec token mais SANS TeamCoach dans le périmètre → hors périmètre.
        $ghost = (new Coach)->setClubId($club->getId())->setSeasonId($season->getId())->setFirstName('Fantome')->setLastName('X');
        $this->em->persist($ghost);
        $this->em->flush();
        $this->em->persist((new CoachWishToken)->setCampaignId($campaign->getId())->setCoachId($ghost->getId())->setClubId($club->getId())->markResponded(new DateTimeImmutable('2026-01-31 19:00:00')));
        $this->em->flush();

        $this->runCommand();
        self::assertContains($adminEmail, $this->sentTo);
        self::assertStringNotContainsString('Fantome', implode(' ', $this->sentSubjects), 'un coach hors périmètre ne remonte pas');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->sentTo = [];
        $this->sentSubjects = [];
    }

    /**
     * @return array{0: Club, 1: Season, 2: string}
     */
    private function seedClub(string $tag): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('CWD ' . $tag . $uid)->setSlug('cwd-' . strtolower($tag) . '-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $adminEmail = 'cwd' . strtolower($tag) . $uid . '@test.com';
        $user = (new User)->setEmail($adminEmail)->setFirstName('G')->setLastName('M');
        $user->setPasswordHash('x');
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season, $adminEmail];
    }

    private function campaign(Club $club, Season $season, string $deadline): CoachWishCampaign
    {
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($entry);
        $this->em->flush();

        // Une équipe réelle : le digest ne compte que le PÉRIMÈTRE (TeamCoach ∩ teamIds).
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName('SM1');
        $this->em->persist($team);
        $this->em->flush();

        $campaign = (new CoachWishCampaign)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setCalendarEntryId($entry->getId())->setDeadline(new DateTimeImmutable($deadline))
            ->setWeeks(['2026-02-16'])->setTeamIds([$team->getId()]);
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function token(CoachWishCampaign $campaign, Club $club, string $firstName, ?DateTimeImmutable $respondedAt): CoachWishToken
    {
        $coach = (new Coach)->setClubId($club->getId())->setSeasonId($campaign->getSeasonId())
            ->setFirstName($firstName)->setLastName('Durand');
        $this->em->persist($coach);
        $this->em->flush();

        // Rattache le coach à l'équipe de la campagne — sinon il est hors périmètre (D6/fix #6).
        $teamId = $campaign->getTeamIds()[0];
        $this->em->persist((new TeamCoach)->setClubId($club->getId())->setSeasonId($campaign->getSeasonId())
            ->setTeamId($teamId)->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));

        $token = (new CoachWishToken)->setCampaignId($campaign->getId())->setCoachId($coach->getId())->setClubId($club->getId())->setTeamIds([$teamId]);
        if ($respondedAt instanceof DateTimeImmutable) {
            $token->markResponded($respondedAt);
        }
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    private function runCommand(bool $failing = false): CommandTester
    {
        $container = self::getContainer();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message) use ($failing): void {
            if ($failing) {
                throw new RuntimeException('smtp down');
            }
            if ($message instanceof Email) {
                $this->sentTo[] = $message->getTo()[0]->getAddress();
                $this->sentSubjects[] = (string) $message->getSubject();
            }
        });

        $command = new CoachWishDigestCommand(
            $this->em,
            $container->get(TenantConnectionContext::class),
            $mailer,
            $container->get(ClubUserRepository::class),
            $container->get(CoachWishTokenRepository::class),
            new CoachWishMailBuilder('http://localhost:5173'),
            $container->get(CoachWishPerimeter::class),
            $container->get(ClockInterface::class),
            $container->get(ClubDay::class),
        );

        $tester = new CommandTester($command);
        $tester->execute(['--date' => self::TODAY]);
        if (!$failing) {
            $tester->assertCommandIsSuccessful();
        }

        return $tester;
    }
}
