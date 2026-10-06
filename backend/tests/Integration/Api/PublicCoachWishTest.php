<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachWish;
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
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page publique de collecte (feature #10, lot C2) — axe AUTH & TENANT (§7.1) : le club vient
 * du TOKEN, jamais d'un input ; le périmètre d'écriture est borné ; jamais de 401 ; une
 * doléance ne franchit jamais la frontière club. Même famille d'invariants que
 * RlsIsolationTest — d'où le groupe phase1.
 */
#[Group('phase1')]
final class PublicCoachWishTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private CalendarEntry $mother;

    private Team $team;

    private Coach $coach;

    private CoachWishCampaign $campaign;

    private string $token;

    public function testGetReturnsContextAndPrefillsExistingWishes(): void
    {
        // Une doléance saisie « au nom de » (par le gestionnaire) doit pré-remplir la page.
        $this->seedWish($this->team->getId(), '2026-02-16', 2, [3], 'Note manager', wishedDays: [2]);

        $this->client->request('GET', '/api/coach-wishes/public/' . $this->token);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Maxime', $body['coachFirstName']);
        self::assertSame('2027-06-30', $body['deadline']);
        self::assertSame(['2026-02-16', '2026-02-23'], $body['weeks']);
        self::assertSame([['id' => $this->team->getId(), 'name' => 'SM1']], $body['teams']);
        self::assertCount(1, $body['wishes']);
        self::assertSame(2, $body['wishes'][0]['slotsWanted']);
        self::assertSame([3], $body['wishes'][0]['unavailableDays']);
        self::assertSame([2], $body['wishes'][0]['wishedDays'], 'les jours souhaités sont pré-remplis');
        self::assertArrayNotHasKey('done', $body['wishes'][0], 'le drapeau done n’est jamais exposé');
    }

    public function testUnknownAndMalformedTokensReturnIdentical404(): void
    {
        $this->client->request('GET', '/api/coach-wishes/public/not-a-valid-token');
        self::assertResponseStatusCodeSame(404);
        $malformed = (string) $this->client->getResponse()->getContent();

        $this->client->request('GET', '/api/coach-wishes/public/' . str_repeat('a', 64));
        self::assertResponseStatusCodeSame(404);
        self::assertSame($malformed, (string) $this->client->getResponse()->getContent(), 'inconnu et malformé répondent à l’identique');
    }

    public function testNeverReturns401(): void
    {
        foreach (['not-a-token', str_repeat('a', 64), $this->token] as $t) {
            $this->client->request('GET', '/api/coach-wishes/public/' . $t);
            self::assertNotSame(401, $this->client->getResponse()->getStatusCode());
        }
    }

    public function testExpiredDeadlineReturns410AndExtensionRevivesTheLink(): void
    {
        $this->setDeadline('2020-01-06'); // un lundi bien passé
        $this->client->request('GET', '/api/coach-wishes/public/' . $this->token);
        self::assertResponseStatusCodeSame(410);

        // Le gestionnaire étend la deadline → le MÊME lien répond de nouveau.
        $this->setDeadline(new DateTimeImmutable('+1 year')->format('Y-m-d'));
        $this->client->request('GET', '/api/coach-wishes/public/' . $this->token);
        self::assertResponseIsSuccessful();
    }

    public function testSubmitUpsertsWithCoachClubAndSeasonAndResetsDone(): void
    {
        // Une doléance déjà traitée (done=true) : la re-soumission du coach la remet « à traiter ».
        $this->seedWish($this->team->getId(), '2026-02-16', 1, [], null, true);

        $this->client->request('POST', '/api/coach-wishes/public/' . $this->token, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'submissions' => [['teamId' => $this->team->getId(), 'weekStart' => '2026-02-16', 'slotsWanted' => 3, 'unavailableDays' => [5], 'comment' => 'Réponse du coach']],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        $wish = $this->em->getRepository(CoachWish::class)->findOneBy(['calendarEntryId' => $this->mother->getId(), 'teamId' => $this->team->getId(), 'weekStart' => new DateTimeImmutable('2026-02-16 00:00:00')]);
        self::assertNotNull($wish);
        self::assertSame(3, $wish->getSlotsWanted());
        self::assertSame($this->coach->getId(), $wish->getCoachId());
        self::assertSame($this->club->getId(), $wish->getClubId());
        self::assertSame($this->season->getId(), $wish->getSeasonId());
        self::assertFalse($wish->isDone(), 'la re-soumission remet « à traiter »');

        // Le token porte l'horodatage de réponse.
        $token = $this->em->getRepository(CoachWishToken::class)->findOneByToken($this->token);
        self::assertNotNull($token?->getRespondedAt());
    }

    public function testSubmitPersistsWishedDaysAsInformativeOnly(): void
    {
        // P4-312 — un jour SOUHAITÉ (positif) part et se persiste, en plus des indisponibilités.
        $this->client->request('POST', '/api/coach-wishes/public/' . $this->token, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'submissions' => [['teamId' => $this->team->getId(), 'weekStart' => '2026-02-16', 'slotsWanted' => 2, 'unavailableDays' => [3], 'wishedDays' => [2, 4], 'comment' => null]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        $wish = $this->em->getRepository(CoachWish::class)->findOneBy(['calendarEntryId' => $this->mother->getId(), 'teamId' => $this->team->getId(), 'weekStart' => new DateTimeImmutable('2026-02-16 00:00:00')]);
        self::assertNotNull($wish);
        self::assertSame([2, 4], $wish->getWishedDays());
        self::assertSame([3], $wish->getUnavailableDays());
    }

    public function testSubmitRejectsADayBothWishedAndUnavailableWithoutWriting(): void
    {
        // P4-312 — un jour ne peut pas être à la fois souhaité ET indisponible : 422, rien d'écrit.
        $this->client->request('POST', '/api/coach-wishes/public/' . $this->token, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'submissions' => [['teamId' => $this->team->getId(), 'weekStart' => '2026-02-16', 'slotsWanted' => 2, 'unavailableDays' => [3], 'wishedDays' => [3], 'comment' => null]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Un jour ne peut pas être à la fois souhaité et indisponible.', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertCount(0, $this->em->getRepository(CoachWish::class)->findBy(['teamId' => $this->team->getId()]), 'rien écrit sur un conflit souhaité ∩ indisponible');
    }

    public function testSubmitRejectsATeamOutsideThePerimeterWithoutWriting(): void
    {
        $foreignTeam = $this->newTeam('U13'); // existe, mais hors campagne + pas au coach
        $this->client->request('POST', '/api/coach-wishes/public/' . $this->token, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'submissions' => [['teamId' => $foreignTeam->getId(), 'weekStart' => '2026-02-16', 'slotsWanted' => 2, 'unavailableDays' => [], 'comment' => null]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertCount(0, $this->em->getRepository(CoachWish::class)->findBy(['teamId' => $foreignTeam->getId()]), 'rien écrit hors périmètre');
    }

    public function testSubmitRejectsAWeekOutsideTheCampaign(): void
    {
        $this->client->request('POST', '/api/coach-wishes/public/' . $this->token, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'submissions' => [['teamId' => $this->team->getId(), 'weekStart' => '2026-03-30', 'slotsWanted' => 2, 'unavailableDays' => [], 'comment' => null]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnArchivedSeasonClosesTheLink(): void
    {
        // Revue #10 C2 : le chemin public n'a pas de JWT, `SeasonAccessGuard` ne peut pas
        // intercepter — une saison archivée (lecture seule) doit fermer le lien (410),
        // jamais laisser écrire dans une saison gelée.
        $this->scopeGucToClub($this->club->getId());
        $this->season->setStatus(SeasonStatus::ARCHIVED);
        $this->em->flush();

        $this->client->request('GET', '/api/coach-wishes/public/' . $this->token);
        self::assertResponseStatusCodeSame(410);

        $this->client->request('POST', '/api/coach-wishes/public/' . $this->token, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'submissions' => [['teamId' => $this->team->getId(), 'weekStart' => '2026-02-16', 'slotsWanted' => 2, 'unavailableDays' => [], 'comment' => null]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(410);

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertCount(0, $this->em->getRepository(CoachWish::class)->findBy(['teamId' => $this->team->getId()]), 'rien écrit dans une saison archivée');
    }

    public function testACalendarRolledSeasonClosesTheLinkEvenWhenStatusStaysActive(): void
    {
        // Revue sécu #10 C2 : le read-only d'une saison est DÉRIVÉ DU CALENDRIER (pivot
        // 15-juillet), pas du statut (jamais posé à 'archived' au roulement). La saison de la
        // campagne (2025) est encore 'active' mais, une saison 2026 existant, elle est gelée
        // → le lien public doit fermer (410), comme le chemin authentifié via _season_readonly.
        $this->scopeGucToClub($this->club->getId());
        $next = (new Season)->setClubId($this->club->getId())->setName('2026-2027')
            ->setStartDate(new DateTimeImmutable('2026-09-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($next);
        $this->em->flush();
        self::assertSame(SeasonStatus::ACTIVE, $this->season->getStatus(), 'la saison de la campagne reste active (statut jamais roulé)');

        $this->client->request('GET', '/api/coach-wishes/public/' . $this->token);
        self::assertResponseStatusCodeSame(410);

        $this->client->request('POST', '/api/coach-wishes/public/' . $this->token, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'submissions' => [['teamId' => $this->team->getId(), 'weekStart' => '2026-02-16', 'slotsWanted' => 2, 'unavailableDays' => [], 'comment' => null]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(410);

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertCount(0, $this->em->getRepository(CoachWish::class)->findBy(['teamId' => $this->team->getId()]), 'rien écrit dans une saison gelée par le calendrier');
    }

    public function testAnOversizedSubmissionsArrayIsRejected(): void
    {
        // Revue sécu #10 C2 : borne de cardinalité AVANT itération — un tableau géant sur un
        // endpoint sans login est refusé (abus O(N)).
        $items = array_fill(0, 201, ['teamId' => $this->team->getId(), 'weekStart' => '2026-02-16', 'slotsWanted' => 1, 'unavailableDays' => [], 'comment' => null]);
        $this->client->request('POST', '/api/coach-wishes/public/' . $this->token, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['submissions' => $items], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testDuplicateTeamWeekSubmissionsMergeInsteadOf500(): void
    {
        // Revue #10 C2 : deux lignes du même (équipe, semaine) partageraient la clé naturelle
        // de CoachWish (violation d'unicité → 500). On déduplique, la dernière l'emporte.
        $this->client->request('POST', '/api/coach-wishes/public/' . $this->token, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'submissions' => [
                ['teamId' => $this->team->getId(), 'weekStart' => '2026-02-16', 'slotsWanted' => 2, 'unavailableDays' => [], 'comment' => 'premier'],
                ['teamId' => $this->team->getId(), 'weekStart' => '2026-02-16', 'slotsWanted' => 5, 'unavailableDays' => [], 'comment' => 'dernier'],
            ],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        $rows = $this->em->getRepository(CoachWish::class)->findBy(['calendarEntryId' => $this->mother->getId(), 'teamId' => $this->team->getId(), 'weekStart' => new DateTimeImmutable('2026-02-16 00:00:00')]);
        self::assertCount(1, $rows, 'une seule ligne pour (équipe, semaine)');
        self::assertSame(5, $rows[0]->getSlotsWanted(), 'la dernière soumission l’emporte');
    }

    public function testAWeekNoLongerIntersectingTheShrunkPeriodIsRejected(): void
    {
        // Revue #10 C2 : `campaign.weeks` est un instantané. Si le gestionnaire raccourcit la
        // période après le lancement, une semaine encore listée mais hors de la fenêtre doit
        // être refusée à l'écriture (parité avec le chemin authentifié `assertValidAnchor`).
        $this->scopeGucToClub($this->club->getId());
        $this->mother->setEndDate(new DateTimeImmutable('2026-02-20')); // la 2e semaine (23/02) ne recoupe plus
        $this->em->flush();

        $this->client->request('POST', '/api/coach-wishes/public/' . $this->token, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'submissions' => [['teamId' => $this->team->getId(), 'weekStart' => '2026-02-23', 'slotsWanted' => 2, 'unavailableDays' => [], 'comment' => null]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertCount(0, $this->em->getRepository(CoachWish::class)->findBy(['weekStart' => new DateTimeImmutable('2026-02-23 00:00:00')]), 'rien écrit hors fenêtre');
    }

    public function testATokenOfClubANeverWritesIntoClubB(): void
    {
        // Le token du club courant ne peut jamais faire écrire une doléance dans un AUTRE
        // club : le club vient du token, l'équipe ciblée doit être du périmètre du token.
        [$otherClubId, $otherTeamId] = $this->seedIntruderClub();
        $this->client->request('POST', '/api/coach-wishes/public/' . $this->token, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'submissions' => [['teamId' => $otherTeamId, 'weekStart' => '2026-02-16', 'slotsWanted' => 2, 'unavailableDays' => [], 'comment' => null]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        $this->scopeGucToClub($otherClubId);
        self::assertCount(0, $this->em->getRepository(CoachWish::class)->findBy(['teamId' => $otherTeamId]), 'aucune doléance n’a franchi la frontière club');
    }

    public function testSimulatedClockClosesALinkWhoseDeadlineIsStillInTheRealFuture(): void
    {
        // P4-16 — la page publique (pas de `_club_id` en requête) suit l'horloge DU club via
        // ClubDay. Deadline DANS LE FUTUR réel : à l'heure réelle le lien serait OUVERT, mais
        // l'horloge du club calée APRÈS la deadline le ferme (410). Red si ClubDay ignore
        // simulated_today (il resterait ouvert — comparaison au jour RÉEL).
        $this->setDeadline('2030-05-10');
        $this->setSimulatedToday('2030-05-11'); // lendemain de la deadline, sous l'horloge du club

        $this->client->request('GET', '/api/coach-wishes/public/' . $this->token);
        self::assertResponseStatusCodeSame(410, 'horloge du club après la deadline → lien fermé, bien que la deadline soit dans le futur réel');
    }

    public function testSimulatedClockReopensALinkWhoseDeadlineIsAlreadyInTheRealPast(): void
    {
        // Miroir : deadline DANS LE PASSÉ réel → à l'heure réelle le lien serait FERMÉ, mais
        // l'horloge du club calée LE JOUR de la deadline (incluse) le rouvre (200). Red si
        // ClubDay ignore simulated_today (il resterait fermé — comparaison au jour RÉEL).
        $this->setDeadline('2020-01-06');
        $this->setSimulatedToday('2020-01-06'); // le jour même, deadline incluse

        $this->client->request('GET', '/api/coach-wishes/public/' . $this->token);
        self::assertResponseIsSuccessful();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $hasher = $c->get('security.user_password_hasher');
        $uid = uniqid('', true);

        // SEC-30 — les tests d'échéance sous horloge simulée (setSimulatedToday) exigent
        // un club démo : la date simulée n'est honorée/persistable que pour is_demo = true.
        $this->club = (new Club)->setName('PCW ' . $uid)->setSlug('pcw-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)->setIsDemo(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('pcw' . $uid . '@test.com')->setFirstName('P')->setLastName('W');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $this->team = $this->newTeam('SM1');
        $this->coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setFirstName('Maxime')->setLastName('Durand');
        $this->em->persist($this->coach);
        $this->em->flush();
        $this->em->persist((new TeamCoach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setTeamId($this->team->getId())->setCoachId($this->coach->getId())->setRole(TeamCoachRole::MAIN));

        $this->mother = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Toussaint')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($this->mother);

        $this->campaign = (new CoachWishCampaign)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->mother->getId())->setDeadline(new DateTimeImmutable('2027-06-30'))
            ->setWeeks(['2026-02-16', '2026-02-23'])->setTeamIds([$this->team->getId()]);
        $this->em->persist($this->campaign);

        $tokenEntity = (new CoachWishToken)->setCampaignId($this->campaign->getId())->setCoachId($this->coach->getId())->setClubId($this->club->getId());
        $this->em->persist($tokenEntity);
        $this->em->flush();
        $this->token = $tokenEntity->getToken();
    }

    private function newTeam(string $name): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName($name);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /**
     * @param list<int> $days
     * @param list<int> $wishedDays
     */
    private function seedWish(string $teamId, string $weekStart, int $slots, array $days, ?string $comment, bool $done = false, array $wishedDays = []): void
    {
        $wish = (new CoachWish)->setCalendarEntryId($this->mother->getId())->setTeamId($teamId)
            ->setWeekStart(new DateTimeImmutable($weekStart . ' 00:00:00'))->setCoachId($this->coach->getId())
            ->setSlotsWanted($slots)->setUnavailableDays($days)->setWishedDays($wishedDays)->setComment($comment)->setDone($done);
        $wish->setClubId($this->club->getId());
        $wish->setSeasonId($this->season->getId());
        $this->em->persist($wish);
        $this->em->flush();
    }

    private function setSimulatedToday(?string $ymd): void
    {
        // `club` n'a pas de RLS → UPDATE direct, un seul mutation+requête par test (pas d'état
        // partagé entre requêtes, le client rebâtit le noyau à chaque appel).
        $this->club->setSimulatedToday(null === $ymd ? null : new DateTimeImmutable($ymd . ' 00:00:00'));
        $this->em->flush();
    }

    private function setDeadline(string $ymd): void
    {
        // Une requête publique a pu vider le GUC (finally clear) : le re-poser avant le
        // flush, sinon l'UPDATE de la campagne (RLS) est bloqué.
        $this->scopeGucToClub($this->club->getId());
        $this->campaign->setDeadline(new DateTimeImmutable($ymd . ' 00:00:00'));
        $this->em->flush();
    }

    /**
     * @return array{0: string, 1: string} clubId, teamId de l'intrus
     */
    private function seedIntruderClub(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Intrus ' . $uid)->setSlug('intrus-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSportCategoryId('22222222-2222-4222-8222-222222222222')->setPriorityTierId(1)->setName('Intrus');
        $this->em->persist($team);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());

        return [$club->getId(), $team->getId()];
    }
}
