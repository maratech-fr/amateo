<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\PeriodWindowUniquenessGuard;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR BLOQUANT — P2-38 (prévention) « les fenêtres déjà planifiées, servies » (axe §7.1 : planning
 * lifecycle — ADR-0002 inv. 4).
 *
 * POURQUOI ce test existe : la modale « Quelles semaines ajuster ? » offrait des semaines dont la
 * création de plan serait refusée en 409 `window_already_planned`. Le fondateur a tranché — le
 * BACKEND sert le verdict, le front restitue sans redériver. `GET /api/planned-windows` et la garde
 * d'écriture {@see PeriodWindowUniquenessGuard} partagent LE MÊME prédicat par
 * construction (un seul texte SQL, `governingWindows`). Sans ce test, la route de lecture et la garde
 * d'écriture pourraient DIVERGER — l'écran promettrait une disponibilité que le serveur refuse (ou
 * masquerait une semaine libre). On le prouve par le COMPORTEMENT, falsifié dans les DEUX sens :
 *   (a) toute plage SERVIE par la route → le POST d'une période qui la chevauche reçoit bien 409 ;
 *   (b) toute fenêtre NON servie → le POST passe la garde (201).
 *
 * Plus : la FAMILLE (mère↔enfant, semaines sœurs) n'est ni servie ni refusée · une saison étrangère
 * est invisible · un autre club → 404 (jamais un oracle d'existence) · le chevauchement PARTIEL aux
 * bornes est vu (début ≤ fin ET fin ≥ début) · le chemin « mère pas encore créée » (`seasonId` sans
 * `entryId`) · la lecture est OUVERTE au Membre (aucune garde management).
 */
#[Group('phase1')]
#[Group('security')]
final class PlannedWindowsParityTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * LE cœur — parité served ⇄ refused, falsifiée dans les deux sens, sur le chemin « pending »
     * (`seasonId` sans entrée) qui est exactement celui de la modale d'ouverture.
     */
    public function testServedWindowsAreExactlyWhatTheWriteGuardRefuses(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();

        // Deux périodes qui GOUVERNENT leur fenêtre (adaptées), séparées par un trou libre.
        $octobre = $this->postPeriodDated($user, 'closure', 'Gymnase indisponible', '2026-10-19', '2026-10-25');
        $this->adaptPeriod($user, $octobre);
        $novembre = $this->postPeriodDated($user, 'holiday', 'Vacances de Toussaint', '2026-11-02', '2026-11-08');
        $this->adaptPeriod($user, $novembre);
        // Trou libre : 2026-10-26 → 2026-11-01.

        // La route (chemin pending : la mère candidate n'existe pas encore).
        $windows = $this->plannedWindows($user, ['seasonId' => $season->getId(), 'start' => '2026-10-19', 'end' => '2026-11-08']);

        // Servies = EXACTEMENT les deux gouvernantes, triées par date, avec leur label composé serveur.
        self::assertCount(2, $windows, 'exactement les deux fenêtres gouvernées');
        self::assertSame($octobre, $windows[0]['entryId'], 'triées par date de début');
        self::assertSame($novembre, $windows[1]['entryId']);
        self::assertSame('Gymnase indisponible', $windows[0]['title']);
        self::assertSame('2026-10-19', $windows[0]['startDate']);
        self::assertSame('2026-10-25', $windows[0]['endDate']);
        self::assertNotSame('', $windows[0]['label'], 'la fenêtre est nommée côté serveur');
        self::assertNotSame('', $windows[1]['label']);

        // La PHRASE est servie prête à afficher — l'écran n'en compose aucune. Et elle NOMME la
        // période exactement comme le refus 409 le fera : deux noms pour un seul objet feraient
        // croire au gestionnaire qu'il existe deux plannings.
        foreach ($windows as $window) {
            self::assertArrayHasKey('reason', $window, 'la route sert la phrase, elle ne laisse pas le front la composer');
            self::assertSame(
                PeriodWindowUniquenessGuard::nameConflict((string) $window['title'], (string) $window['label']),
                $window['reason'],
                'le nommage servi doit être CELUI du refus 409 — foyer unique',
            );
            self::assertStringNotContainsString('découper', (string) $window['reason'], 'l\'écran de découpe ne renvoie pas vers lui-même');
        }

        // (a) SERVIE → refusée : une nouvelle période qui MORD sur octobre est bien refusée en 409.
        $incident = $this->postPeriodDated($user, 'closure', 'Autre incident', '2026-10-20', '2026-10-26');
        $this->adaptPeriodExpecting(409, $user, $incident);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('window_already_planned', $payload['code'] ?? null);
        self::assertSame($octobre, $payload['entryId'] ?? null, 'le refus pointe la fenêtre servie');

        // (b) NON servie → passe la garde : une période dans le trou libre s'adapte (201).
        $libre = $this->postPeriodDated($user, 'holiday', 'Semaine libre', '2026-10-26', '2026-11-01');
        $this->adaptPeriod($user, $libre);
        unset($club);
    }

    /**
     * La FAMILLE n'est ni servie ni refusée : depuis une semaine, ni sa mère ni ses sœurs
     * n'apparaissent (même ancêtre racine, exclu) — alors qu'elles GOUVERNENT bien (visibles par
     * le chemin `seasonId`, sans exclusion). C'est l'exclusion de famille du prédicat, prouvée
     * dans les deux directions (mère↔enfant ET sœur↔sœur).
     */
    public function testTheFamilyIsNeitherServedNorRefused(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();

        $mere = $this->postPeriodDated($user, 'holiday', 'Vacances découpées', '2026-10-19', '2026-11-01');
        $w1 = $this->postWeekChild($user, $mere, '2026-10-19', '2026-10-25');
        $w2 = $this->postWeekChild($user, $mere, '2026-10-26', '2026-11-01');

        // Depuis W1 : ni W2 (sœur) ni la mère ne sont servies — même racine, exclue.
        $fromW1 = $this->plannedWindows($user, ['entryId' => $w1, 'start' => '2026-10-19', 'end' => '2026-11-01']);
        self::assertSame([], $this->entryIds($fromW1), 'la famille (mère + sœurs) n\'est jamais servie à l\'un des siens');

        // Mais elles gouvernent bien : le chemin saison (sans exclusion) les voit toutes les deux.
        $fromSeason = $this->plannedWindows($user, ['seasonId' => $season->getId(), 'start' => '2026-10-19', 'end' => '2026-11-01']);
        $ids = $this->entryIds($fromSeason);
        self::assertContains($w1, $ids, 'W1 gouverne sa semaine');
        self::assertContains($w2, $ids, 'W2 gouverne sa semaine');
        unset($club);
    }

    /**
     * Le chevauchement PARTIEL aux bornes est vu : une fenêtre qui touche la plage par un seul jour
     * (par sa fin, puis par son début) est servie ; une fenêtre entièrement disjointe ne l'est pas.
     */
    public function testPartialOverlapAtTheBordersIsServed(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $p = $this->postPeriodDated($user, 'closure', 'Fenêtre bornée', '2026-10-19', '2026-10-25');
        $this->adaptPeriod($user, $p);

        // Recouvrement par la borne HAUTE de la période (elle finit le premier jour de la plage).
        $touchEnd = $this->plannedWindows($user, ['seasonId' => $season->getId(), 'start' => '2026-10-25', 'end' => '2026-10-31']);
        self::assertContains($p, $this->entryIds($touchEnd), 'fin ≥ début de la plage → servie');

        // Recouvrement par la borne BASSE de la période (elle commence le dernier jour de la plage).
        $touchStart = $this->plannedWindows($user, ['seasonId' => $season->getId(), 'start' => '2026-10-13', 'end' => '2026-10-19']);
        self::assertContains($p, $this->entryIds($touchStart), 'début ≤ fin de la plage → servie');

        // Entièrement AVANT : disjointe → jamais servie.
        $before = $this->plannedWindows($user, ['seasonId' => $season->getId(), 'start' => '2026-10-05', 'end' => '2026-10-12']);
        self::assertNotContains($p, $this->entryIds($before), 'une fenêtre disjointe n\'est pas servie');
        unset($club);
    }

    /** Une saison ÉTRANGÈRE est invisible : le prédicat filtre par la saison interrogée. */
    public function testAForeignSeasonIsInvisible(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $p = $this->postPeriodDated($user, 'closure', 'Gymnase indisponible', '2026-10-19', '2026-10-25');
        $this->adaptPeriod($user, $p);

        $other = $this->addSeason($club);

        // La période vit dans la saison active ; interrogée sur l'AUTRE saison du même club → rien.
        $foreign = $this->plannedWindows($user, ['seasonId' => $other->getId(), 'start' => '2026-10-19', 'end' => '2026-10-25']);
        self::assertSame([], $this->entryIds($foreign), 'une fenêtre d\'une autre saison ne fuit pas');

        // Témoin : la saison propre, elle, la voit.
        $own = $this->plannedWindows($user, ['seasonId' => $season->getId(), 'start' => '2026-10-19', 'end' => '2026-10-25']);
        self::assertContains($p, $this->entryIds($own));
    }

    /** Un autre club : sa fenêtre ne fuit jamais, et ses identifiants répondent 404 (pas un oracle). */
    public function testAnotherClubIsNotServedAndItsIdsReturn404(): void
    {
        [$owner, $ownerClub] = $this->createClubWithSeason();
        $foreignEntry = $this->postPeriodDated($owner, 'closure', 'Gymnase du voisin', '2026-10-19', '2026-10-25');
        $this->adaptPeriod($owner, $foreignEntry);

        [$stranger, $strangerClub, $strangerSeason] = $this->createClubWithSeason();

        // La fenêtre du club voisin ne fuit pas dans les résultats de l'étranger.
        $windows = $this->plannedWindows($stranger, ['seasonId' => $strangerSeason->getId(), 'start' => '2026-10-19', 'end' => '2026-10-25']);
        self::assertSame([], $this->entryIds($windows));

        // Interroger avec un entryId / seasonId d'un AUTRE club → 404 byte-identique.
        self::assertSame(404, $this->rawStatus($stranger, ['entryId' => $foreignEntry, 'start' => '2026-10-19', 'end' => '2026-10-25']));
        self::assertSame(404, $this->rawStatus($stranger, ['seasonId' => $this->seasonIdOfClub($ownerClub), 'start' => '2026-10-19', 'end' => '2026-10-25']));
    }

    /** La LECTURE est ouverte au Membre : aucune garde management sur une route de lecture. */
    public function testReadIsOpenToMember(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $p = $this->postPeriodDated($user, 'closure', 'Gymnase indisponible', '2026-10-19', '2026-10-25');
        $this->adaptPeriod($user, $p);

        $member = $this->addMember($club, 'coach'); // rôle non-management
        $windows = $this->plannedWindows($member, ['seasonId' => $season->getId(), 'start' => '2026-10-19', 'end' => '2026-10-25']);
        self::assertContains($p, $this->entryIds($windows), 'un membre lit le verdict comme un gestionnaire');
    }

    /** Refus nommés atteignables : 422 dates absentes, 422 ni entryId ni seasonId, 404 id inconnu. */
    public function testNamedRefusals(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();

        // Dates absentes → 422.
        self::assertSame(422, $this->rawStatus($user, ['seasonId' => $season->getId()]));
        // Dates malformées → 422.
        self::assertSame(422, $this->rawStatus($user, ['seasonId' => $season->getId(), 'start' => '2026-13-40', 'end' => '2026-10-25']));
        // Ni entryId ni seasonId → 422.
        self::assertSame(422, $this->rawStatus($user, ['start' => '2026-10-19', 'end' => '2026-10-25']));
        // entryId inconnu → 404.
        self::assertSame(404, $this->rawStatus($user, ['entryId' => '00000000-0000-0000-0000-000000000000', 'start' => '2026-10-19', 'end' => '2026-10-25']));
        // seasonId inconnu → 404.
        self::assertSame(404, $this->rawStatus($user, ['seasonId' => '00000000-0000-0000-0000-000000000000', 'start' => '2026-10-19', 'end' => '2026-10-25']));
        unset($club);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<array{entryId: string, title: string, startDate: string, endDate: string, label: string}>
     */
    private function plannedWindows(User $user, array $query): array
    {
        $this->client->request('GET', '/api/planned-windows?' . http_build_query($query), [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['windows']);

        /* @var list<array{entryId: string, title: string, startDate: string, endDate: string, label: string}> */
        return $payload['windows'];
    }

    /** @param array<string, string> $query */
    private function rawStatus(User $user, array $query): int
    {
        $this->client->request('GET', '/api/planned-windows?' . http_build_query($query), [], [], $this->authHeaders($user));

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @param list<array{entryId: string, title: string, startDate: string, endDate: string, label: string}> $windows
     *
     * @return list<string>
     */
    private function entryIds(array $windows): array
    {
        return array_map(static fn (array $w): string => $w['entryId'], $windows);
    }

    private function adaptPeriod(User $user, string $entryId): void
    {
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    private function adaptPeriodExpecting(int $status, User $user, string $entryId): void
    {
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($status);
    }

    private function postPeriodDated(User $user, string $periodType, string $title, string $start, string $end): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => $periodType,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    /** POST d'une entrée-SEMAINE (elle naît AVEC son plan) — rend son id. */
    private function postWeekChild(User $user, string $motherId, string $start, string $end): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => 'Semaine du ' . $start,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => 'holiday',
            'parentEntryId' => $motherId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    private function addMember(Club $club, string $role): User
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $member = new User;
        $member->setEmail('member' . $uid . '@test.com');
        $member->setFirstName('Non');
        $member->setLastName('Manager');
        $member->setPasswordHash($hasher->hashPassword($member, 'pass'));
        $this->em->persist($member);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($member->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $member;
    }

    /** Une seconde saison du MÊME club (DRAFT) — pour prouver l'isolation par saison. */
    private function addSeason(Club $club): Season
    {
        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('Autre saison');
        $season->setStartDate(new DateTimeImmutable('2027-08-01'));
        $season->setEndDate(new DateTimeImmutable('2028-07-15'));
        $season->setStatus(SeasonStatus::DRAFT);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function seasonIdOfClub(Club $club): string
    {
        $this->scopeGucToClub($club->getId());

        return (string) $this->em->getConnection()->fetchOne(
            'SELECT id FROM season WHERE club_id = :cid ORDER BY start_date DESC LIMIT 1',
            ['cid' => $club->getId()],
        );
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubWithSeason(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club fenêtres');
        $club->setSlug('club-fenetres-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('fenetres' . $uid . '@test.com');
        $user->setFirstName('Fe');
        $user->setLastName('Netres');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$user, $club, $season];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
