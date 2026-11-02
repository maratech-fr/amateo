<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\CoachWishCampaignTokenSync;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : UNE règle dit qui répond pour quoi, et l'écrit sur le jeton. Un destinataire a un jeton
 * qui liste ses équipes ; un adjoint n'en a jamais ; une équipe GAGNÉE par un destinataire déjà servi
 * le repasse « à envoyer » ; une équipe perdue ne remet rien à zéro ; une campagne à date passée n'est
 * jamais touchée ; rejouer la règle ne change rien. Les chemins qui l'appellent (lien, coach, sélecteur,
 * « Charger ») sont prouvés côté API : CoachWishCampaignApiTest, CoachWishCampaignActionsTest, StructureRestorerTest.
 */
#[Group('phase1')]
#[Group('integration')]
final class CoachWishCampaignTokenSyncTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private CoachWishCampaignTokenSync $sync;

    private Club $club;

    private Season $season;

    private CalendarEntry $entry;

    private Team $u11;

    private Team $u13;

    public function testARecipientGetsOneTokenListingHisTeamsAndAnAssistantNone(): void
    {
        $maxime = $this->coach('Maxime');
        $benoit = $this->coach('Benoît');
        $this->link($this->u11, $maxime, TeamCoachRole::MAIN, '2026-01-01');
        $this->link($this->u13, $maxime, TeamCoachRole::MAIN, '2026-01-01');
        $this->link($this->u11, $benoit, TeamCoachRole::ASSISTANT, '2026-01-01');
        $campaign = $this->campaign('2027-06-30');

        $this->sync->sync($campaign);

        self::assertEqualsCanonicalizing([$this->u11->getId(), $this->u13->getId()], $this->token($campaign, $maxime)?->getTeamIds());
        self::assertNull($this->token($campaign, $benoit), 'un adjoint n\'a ni jeton ni mail');
    }

    public function testASecondMainGetsNoTokenWhileHeIsNotTheRecipient(): void
    {
        $maxime = $this->coach('Maxime');
        $julie = $this->coach('Julie');
        $this->link($this->u11, $maxime, TeamCoachRole::MAIN, '2026-01-01');
        $this->link($this->u11, $julie, TeamCoachRole::MAIN, '2026-01-02');
        $campaign = $this->campaign('2027-06-30', [$this->u11->getId()]);

        $this->sync->sync($campaign);

        self::assertInstanceOf(CoachWishToken::class, $this->token($campaign, $maxime));
        self::assertNull($this->token($campaign, $julie));
    }

    public function testGainingATeamPutsAnAlreadyServedRecipientBackToSend(): void
    {
        // F8 — Julie (U13, a répondu) est désignée pour U11M1 : même jeton, « à envoyer ».
        $julie = $this->coach('Julie');
        $this->link($this->u13, $julie, TeamCoachRole::MAIN, '2026-01-01');
        $this->link($this->u11, $julie, TeamCoachRole::MAIN, '2026-01-01');
        $campaign = $this->campaign('2027-06-30');
        $token = $this->seedToken($campaign, $julie, [$this->u13->getId()], sent: true, responded: true);
        $secret = $token->getToken();

        $this->sync->sync($campaign);

        $fresh = $this->token($campaign, $julie);
        self::assertSame($secret, $fresh?->getToken(), 'le même lien : il ne change jamais');
        self::assertNull($fresh->getSentAt(), 'équipe gagnée : il repasse « à envoyer »');
        self::assertNull($fresh->getRespondedAt());
        self::assertEqualsCanonicalizing([$this->u11->getId(), $this->u13->getId()], $fresh->getTeamIds());
    }

    public function testGainingATeamResetsARecipientWhoAnsweredByACopiedLinkExceptOnAClosedCampaign(): void
    {
        // Règle fondateur : il a répondu sans jamais recevoir le mail (`sentAt` nul), mais il manque
        // des données pour l'équipe gagnée : il repasse « à envoyer ». Collecte fermée : rien ne bouge.
        $this->club->setIsDemo(true)->setSimulatedToday(new DateTimeImmutable('2030-05-10 00:00:00'));
        $this->em->flush();
        $julie = $this->coach('Julie');
        $this->link($this->u13, $julie, TeamCoachRole::MAIN, '2026-01-01');
        $this->link($this->u11, $julie, TeamCoachRole::MAIN, '2026-01-01');
        $open = $this->campaign('2030-05-10');
        $closed = $this->campaign('2030-05-09', null, $this->otherEntry());
        $this->seedToken($open, $julie, [$this->u13->getId()], responded: true);
        $this->seedToken($closed, $julie, [$this->u13->getId()], responded: true);

        $this->sync->syncSeason($this->club->getId(), $this->season->getId());

        $fresh = $this->token($open, $julie);
        self::assertNull($fresh?->getRespondedAt(), 'équipe gagnée : la réponse par lien copié ne suffit plus');
        self::assertNull($fresh->getSentAt());
        self::assertEqualsCanonicalizing([$this->u11->getId(), $this->u13->getId()], $fresh->getTeamIds());

        $untouched = $this->token($closed, $julie);
        self::assertNotNull($untouched?->getRespondedAt(), 'collecte fermée : la réponse reste');
        self::assertSame([$this->u13->getId()], $untouched->getTeamIds());
    }

    public function testLosingATeamShrinksTheTokenWithoutAnyReset(): void
    {
        $maxime = $this->coach('Maxime');
        $this->link($this->u11, $maxime, TeamCoachRole::MAIN, '2026-01-01');
        $campaign = $this->campaign('2027-06-30');
        $this->seedToken($campaign, $maxime, [$this->u11->getId(), $this->u13->getId()], sent: true, responded: true);

        $this->sync->sync($campaign);

        $fresh = $this->token($campaign, $maxime);
        self::assertSame([$this->u11->getId()], $fresh?->getTeamIds());
        self::assertNotNull($fresh->getSentAt(), 'perdre une équipe ne remet rien à zéro');
        self::assertNotNull($fresh->getRespondedAt());
    }

    public function testAFormerRecipientKeepsHisTokenWithNoTeam(): void
    {
        // Un lien déjà collé dans WhatsApp ne devient jamais un 404 : « Aucune équipe concernée ».
        $former = $this->coach('Maxime');
        $campaign = $this->campaign('2027-06-30', [$this->u11->getId()]);
        $this->seedToken($campaign, $former, [$this->u11->getId()]);

        $this->sync->sync($campaign);

        self::assertSame([], $this->token($campaign, $former)?->getTeamIds());
    }

    public function testReplayingTheRuleChangesNothing(): void
    {
        $maxime = $this->coach('Maxime');
        $this->link($this->u11, $maxime, TeamCoachRole::MAIN, '2026-01-01');
        $campaign = $this->campaign('2027-06-30', [$this->u11->getId()]);
        $this->sync->sync($campaign);
        $token = $this->token($campaign, $maxime);
        self::assertInstanceOf(CoachWishToken::class, $token);
        $token->markSent(new DateTimeImmutable('2026-02-01 10:00:00'));
        $this->em->flush();

        $this->sync->sync($campaign);
        $this->sync->sync($campaign);

        $fresh = $this->token($campaign, $maxime);
        self::assertNotNull($fresh?->getSentAt(), 'aucune équipe gagnée : le jeton reste servi');
        self::assertCount(1, $this->em->getRepository(CoachWishToken::class)->findBy(['campaignId' => $campaign->getId()]));
    }

    public function testSyncSeasonTouchesOpenCampaignsOnlyDeadlineDayIncludedOnTheClubClock(): void
    {
        // Horloge du club : on est le 10/05/2030. Date limite ce jour-là = ouverte ; la veille = fermée.
        $this->club->setIsDemo(true)->setSimulatedToday(new DateTimeImmutable('2030-05-10 00:00:00'));
        $this->em->flush();
        $maxime = $this->coach('Maxime');
        $this->link($this->u11, $maxime, TeamCoachRole::MAIN, '2026-01-01');
        $open = $this->campaign('2030-05-10', [$this->u11->getId()]);
        $closed = $this->campaign('2030-05-09', [$this->u11->getId()], $this->otherEntry());
        $stranger = $this->coach('Ancien');
        $this->seedToken($closed, $stranger, [$this->u11->getId()], sent: true);

        $this->sync->syncSeason($this->club->getId(), $this->season->getId());

        self::assertSame([$this->u11->getId()], $this->token($open, $maxime)?->getTeamIds(), 'le jour de la date limite, la collecte est encore ouverte');
        self::assertNull($this->token($closed, $maxime), 'collecte fermée : aucun jeton créé');
        self::assertSame([$this->u11->getId()], $this->token($closed, $stranger)?->getTeamIds(), 'collecte fermée : son affichage est gardé');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->sync = self::getContainer()->get(CoachWishCampaignTokenSync::class);
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Règle')->setSlug('regle-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->entry = $this->holiday('2026-02-16', '2026-03-01');
        $this->u11 = $this->team('U11M1');
        $this->u13 = $this->team('U13');
    }

    private function holiday(string $start, string $end): CalendarEntry
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function otherEntry(): CalendarEntry
    {
        return $this->holiday('2026-04-06', '2026-04-19');
    }

    /** @param list<string>|null $teamIds */
    private function campaign(string $deadline, ?array $teamIds = null, ?CalendarEntry $entry = null): CoachWishCampaign
    {
        $campaign = (new CoachWishCampaign)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId(($entry ?? $this->entry)->getId())->setDeadline(new DateTimeImmutable($deadline))
            ->setWeeks(['2026-02-16'])->setTeamIds($teamIds ?? [$this->u11->getId(), $this->u13->getId()]);
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function team(string $name): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName($name);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function coach(string $firstName): Coach
    {
        $coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setFirstName($firstName)->setLastName('Test');
        $this->em->persist($coach);
        $this->em->flush();

        return $coach;
    }

    private function link(Team $team, Coach $coach, TeamCoachRole $role, string $createdAt): void
    {
        $this->em->persist((new TeamCoach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole($role)->setCreatedAt(new DateTimeImmutable($createdAt)));
        $this->em->flush();
    }

    /** @param list<string> $teamIds */
    private function seedToken(CoachWishCampaign $campaign, Coach $coach, array $teamIds, bool $sent = false, bool $responded = false): CoachWishToken
    {
        $token = (new CoachWishToken)->setCampaignId($campaign->getId())->setCoachId($coach->getId())->setClubId($this->club->getId())->setTeamIds($teamIds);
        if ($sent) {
            $token->markSent(new DateTimeImmutable('2026-02-01 10:00:00'));
        }
        if ($responded) {
            $token->markResponded(new DateTimeImmutable('2026-02-02 10:00:00'));
        }
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    private function token(CoachWishCampaign $campaign, Coach $coach): ?CoachWishToken
    {
        return $this->em->getRepository(CoachWishToken::class)->findOneBy(['campaignId' => $campaign->getId(), 'coachId' => $coach->getId()]);
    }
}
