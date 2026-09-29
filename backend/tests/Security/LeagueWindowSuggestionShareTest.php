<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR BLOQUANT — P4-272 ② « suggestion des plages de match » : le SEUL flux du module
 * qui LIT à travers la frontière tenant (l'agrégat des saisies de l'instance fédérale),
 * via la fonction SQL `league_window_suggestions` (SECURITY DEFINER). Comme le partage
 * d'échéances (`EntryDeadlineShareTest`), il ne doit JAMAIS rendre de donnée
 * club-identifiante — un compte, jamais un « qui ».
 *
 * Volets, chacun falsifiable en DÉSACTIVANT la garde (rouge prouvé) :
 *  (a) réponse BYTE-IDENTIQUE pour deux lecteurs d'une même instance à copie identique ;
 *  (b) liste blanche EXACTE des clés servies (aucune club-identifiante) ;
 *  (c) seuil 3 (2 clubs → rien, 3 → servi) ;
 *  (d) majorité stricte (3/6 → rien, 4/6 → servi) ;
 *  (e) groupement : un autre comité ne compte pas en DEPARTEMENTAL, une autre ligue ne
 *      compte pas en REGIONAL, NATIONAL compte TOUS ;
 *  (f) demandeur exclu de son propre compte ;
 *  (g) saisons non actives exclues ;
 *  (h) 403 non-gestionnaire (GET et apply) ;
 *  (i) code illisible → réponse neutre (instance null, zéro item) ;
 *  (j) pg_proc : SECURITY DEFINER, search_path figé, EXECUTE au seul rôle applicatif ;
 *  (k) repli fédéral servi pour ARA (→ AURA), RIEN pour GUY (ligue non cataloguée) ;
 *  (l) apply : recalcul serveur (jamais les plages du client), transactionnel, 403 non-gestionnaire.
 */
#[Group('phase1')]
#[Group('security')]
final class LeagueWindowSuggestionShareTest extends WebTestCase
{
    use TenantGucTrait;

    private static int $seq = 0;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    // ── (a) byte-identique pour deux lecteurs d'une même instance ─────────────

    public function testResponseIsByteIdenticalForTwoReadersOfTheSameInstance(): void
    {
        $ligue = $this->uniqueLigue();
        $comite = '0069';
        // Trois pairs saisissent le MÊME ensemble → tendance servie (compte 3).
        foreach (['p1', 'p2', 'p3'] as $tag) {
            [, $season] = $this->makeClub($this->code($ligue, $comite));
            $this->addCopy($season, 'U13', 'DEPARTEMENTAL', null, 6, [['13:00', '18:00']]);
        }

        // Deux lecteurs de la MÊME instance, copie VIDE tous les deux.
        [, , $readerA] = $this->makeClub($this->code($ligue, $comite));
        [, , $readerB] = $this->makeClub($this->code($ligue, $comite));

        $bodyA = $this->rawBody($readerA);
        $bodyB = $this->rawBody($readerB);

        self::assertNotSame('{"instance":null,"items":[]}', $bodyA, 'la tendance est bien servie (sinon le test ne prouve rien)');
        self::assertSame($bodyA, $bodyB, 'deux lecteurs d\'une même instance à copie identique reçoivent une réponse byte-identique (zéro oracle « qui »)');
    }

    // ── (b) liste blanche exacte des clés servies ─────────────────────────────

    public function testServedKeysAreAWhitelistWithoutAnyClubIdentifyingField(): void
    {
        $ligue = $this->uniqueLigue();
        $comite = '0069';
        foreach (['p1', 'p2', 'p3'] as $tag) {
            [, $season] = $this->makeClub($this->code($ligue, $comite));
            $this->addCopy($season, 'U15', 'DEPARTEMENTAL', null, 6, [['14:00', '18:00']]);
        }
        [, , $reader] = $this->makeClub($this->code($ligue, $comite));

        $data = $this->suggestions($reader);
        self::assertSame(['ligue', 'comite'], array_keys($data['instance']), 'instance ne porte QUE ligue+comité');
        self::assertNotSame([], $data['items']);
        foreach ($data['items'] as $item) {
            self::assertSame(
                ['category', 'level', 'gender', 'dayOfWeek', 'windows', 'clubCount', 'source', 'scope'],
                array_keys($item),
                'liste blanche EXACTE des clés d\'un item',
            );
            // Aucune clé club-identifiante ne s'est glissée.
            foreach (['clubId', 'clubIds', 'clubs', 'userId', 'seasonId', 'ffbbClubCode', 'name'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $item, \sprintf('« %s » ne doit jamais être servie', $forbidden));
            }
            self::assertIsInt($item['clubCount'], 'un compte, jamais un « qui »');
        }
    }

    // ── (c) seuil 3 : 2 → rien, 3 → servi ─────────────────────────────────────

    public function testThresholdOfThree(): void
    {
        $ligue = $this->uniqueLigue();
        $comite = '0069';
        [, $s1] = $this->makeClub($this->code($ligue, $comite));
        [, $s2] = $this->makeClub($this->code($ligue, $comite));
        $this->addCopy($s1, 'U11', 'DEPARTEMENTAL', null, 6, [['09:30', '17:00']]);
        $this->addCopy($s2, 'U11', 'DEPARTEMENTAL', null, 6, [['09:30', '17:00']]);
        [, , $reader] = $this->makeClub($this->code($ligue, $comite));

        // 2 pairs → sous le seuil, rien.
        self::assertNull($this->itemFor($this->suggestions($reader), 'U11', 6), '2 clubs → sous le seuil, rien');

        // Un 3ᵉ pair identique → servi, compte 3.
        [, $s3] = $this->makeClub($this->code($ligue, $comite));
        $this->addCopy($s3, 'U11', 'DEPARTEMENTAL', null, 6, [['09:30', '17:00']]);
        $item = $this->itemFor($this->suggestions($reader), 'U11', 6);
        self::assertNotNull($item, '3 clubs identiques → servi');
        self::assertSame(3, $item['clubCount']);
        self::assertSame('clubs', $item['source']);
        self::assertSame('comite', $item['scope']);
        self::assertSame([['kickoffMin' => '09:30', 'kickoffMax' => '17:00']], $item['windows']);
    }

    // ── (d) majorité stricte : 3/6 → rien, 4/6 → servi ────────────────────────

    public function testStrictMajority(): void
    {
        $ligue = $this->uniqueLigue();
        $comite = '0069';
        // 3 clubs avec X, 3 clubs avec Y (même combinaison) → 3/6, aucune majorité.
        foreach (['a', 'b', 'c'] as $tag) {
            [, $s] = $this->makeClub($this->code($ligue, $comite));
            $this->addCopy($s, 'SENIOR', 'DEPARTEMENTAL', null, 6, [['17:00', '21:00']]); // X
        }
        foreach (['d', 'e', 'f'] as $tag) {
            [, $s] = $this->makeClub($this->code($ligue, $comite));
            $this->addCopy($s, 'SENIOR', 'DEPARTEMENTAL', null, 6, [['18:00', '21:00']]); // Y
        }
        [, , $reader] = $this->makeClub($this->code($ligue, $comite));

        self::assertNull($this->itemFor($this->suggestions($reader), 'SENIOR', 6), '3/6 → pas de majorité, rien');

        // Un 7ᵉ club bascule X à 4/7 (> la moitié des 7 saisies) → servi.
        [, $s7] = $this->makeClub($this->code($ligue, $comite));
        $this->addCopy($s7, 'SENIOR', 'DEPARTEMENTAL', null, 6, [['17:00', '21:00']]); // X → 4
        $item = $this->itemFor($this->suggestions($reader), 'SENIOR', 6);
        self::assertNotNull($item, '4/7 → majorité, servi');
        self::assertSame(4, $item['clubCount']);
        self::assertSame([['kickoffMin' => '17:00', 'kickoffMax' => '21:00']], $item['windows']);
    }

    // ── (e) groupement : comité / ligue / NATIONAL ────────────────────────────

    public function testDepartementalIgnoresOtherComite(): void
    {
        $ligue = $this->uniqueLigue();
        // 2 pairs dans MON comité + 3 pairs dans un AUTRE comité (même ligue), tous identiques.
        [, $m1] = $this->makeClub($this->code($ligue, '0069'));
        [, $m2] = $this->makeClub($this->code($ligue, '0069'));
        $this->addCopy($m1, 'U13', 'DEPARTEMENTAL', null, 6, [['13:00', '18:00']]);
        $this->addCopy($m2, 'U13', 'DEPARTEMENTAL', null, 6, [['13:00', '18:00']]);
        foreach (['x', 'y', 'z'] as $tag) {
            [, $s] = $this->makeClub($this->code($ligue, '0038'));
            $this->addCopy($s, 'U13', 'DEPARTEMENTAL', null, 6, [['13:00', '18:00']]);
        }
        [, , $reader] = $this->makeClub($this->code($ligue, '0069'));

        // Seuls les 2 de MON comité comptent en DEPARTEMENTAL → sous le seuil, rien.
        self::assertNull($this->itemFor($this->suggestions($reader), 'U13', 6), 'un autre comité ne compte pas en DEPARTEMENTAL');
    }

    public function testRegionalIgnoresOtherLigue(): void
    {
        $ligue = $this->uniqueLigue();
        $other = $this->uniqueLigue();
        // 2 pairs dans MA ligue + 3 dans une AUTRE ligue, tous en REGIONAL identiques.
        [, $m1] = $this->makeClub($this->code($ligue, '0069'));
        [, $m2] = $this->makeClub($this->code($ligue, '0038'));
        $this->addCopy($m1, 'U18', 'REGIONAL', 'M', 7, [['10:00', '17:30']]);
        $this->addCopy($m2, 'U18', 'REGIONAL', 'M', 7, [['10:00', '17:30']]);
        foreach (['x', 'y', 'z'] as $tag) {
            [, $s] = $this->makeClub($this->code($other, '0075'));
            $this->addCopy($s, 'U18', 'REGIONAL', 'M', 7, [['10:00', '17:30']]);
        }
        [, , $reader] = $this->makeClub($this->code($ligue, '0069'));

        // En REGIONAL, tout MA ligue compte (2, comités confondus) mais pas l'autre ligue → sous le seuil.
        self::assertNull($this->itemFor($this->suggestions($reader), 'U18', 7), 'une autre ligue ne compte pas en REGIONAL');
    }

    public function testNationalCountsEveryClub(): void
    {
        $ligue = $this->uniqueLigue();
        // 3 pairs de LIGUES DIFFÉRENTES, saisie NATIONAL identique → NATIONAL compte tous.
        foreach ([$this->uniqueLigue(), $this->uniqueLigue(), $this->uniqueLigue()] as $peerLigue) {
            [, $s] = $this->makeClub($this->code($peerLigue, '0069'));
            $this->addCopy($s, 'SENIOR', 'NATIONAL', null, 6, [['20:00', '20:30']]);
        }
        [, , $reader] = $this->makeClub($this->code($ligue, '0069'));

        $item = $this->itemFor($this->suggestions($reader), 'SENIOR', 6);
        self::assertNotNull($item, 'NATIONAL compte tous les clubs, ligues confondues');
        self::assertSame(3, $item['clubCount']);
        self::assertSame('federation', $item['scope']);
    }

    // ── (f) demandeur exclu de son propre compte ──────────────────────────────

    public function testRequestingClubIsExcludedFromItsOwnCount(): void
    {
        // Prouvé AU NIVEAU de la fonction SQL — le masquage service (« déjà dans ma
        // copie ») ombragerait sinon l'exclusion dans la vue HTTP (garde doublée, cf.
        // .claude/rules/frontend.md). Cinq porteurs de X : P1..P4 + Q. Vu par Q,
        // l'agrégat doit compter 4 (Q exclu) ; vu par un outsider O, 5.
        $ligue = $this->uniqueLigue();
        $comite = '0069';
        $holders = [];
        foreach (['p1', 'p2', 'p3', 'p4'] as $tag) {
            [$club, $season] = $this->makeClub($this->code($ligue, $comite));
            $this->addCopy($season, 'U11', 'DEPARTEMENTAL', null, 6, [['09:30', '17:00']]);
            $holders[] = $club->getId();
        }
        [$qClub, $qSeason] = $this->makeClub($this->code($ligue, $comite));
        $this->addCopy($qSeason, 'U11', 'DEPARTEMENTAL', null, 6, [['09:30', '17:00']]);
        [$oClub] = $this->makeClub($this->code($ligue, $comite)); // outsider, copie vide

        self::assertSame(4, $this->functionCount($qClub->getId(), 'U11', 6), 'Q est exclu de son propre agrégat (4 autres porteurs)');
        self::assertSame(5, $this->functionCount($oClub->getId(), 'U11', 6), 'l\'outsider voit les 5 porteurs');
    }

    // ── masquage serveur : une combinaison identique à ma copie n'est pas suggérée ─

    public function testCombinationsIdenticalToMyCopyAreMaskedServerSide(): void
    {
        $ligue = $this->uniqueLigue();
        $comite = '0069';
        foreach (['p1', 'p2', 'p3'] as $tag) {
            [, $season] = $this->makeClub($this->code($ligue, $comite));
            $this->addCopy($season, 'U13', 'DEPARTEMENTAL', null, 6, [['13:00', '18:00']]);
        }
        // Un outsider à copie VIDE voit la tendance.
        [, , $outsider] = $this->makeClub($this->code($ligue, $comite));
        self::assertNotNull($this->itemFor($this->suggestions($outsider), 'U13', 6));

        // Un lecteur dont la copie EST DÉJÀ cet ensemble ne se la voit PAS proposer.
        [, $mineSeason, $mine] = $this->makeClub($this->code($ligue, $comite));
        $this->addCopy($mineSeason, 'U13', 'DEPARTEMENTAL', null, 6, [['13:00', '18:00']]);
        self::assertNull($this->itemFor($this->suggestions($mine), 'U13', 6), 'une combinaison identique à ma copie est masquée côté serveur');
    }

    // ── (g) saisons non actives exclues ───────────────────────────────────────

    public function testNonActiveSeasonsAreExcluded(): void
    {
        $ligue = $this->uniqueLigue();
        $comite = '0069';
        // 2 pairs saisissent X sur leur saison ACTIVE.
        [, $a1] = $this->makeClub($this->code($ligue, $comite));
        [, $a2] = $this->makeClub($this->code($ligue, $comite));
        $this->addCopy($a1, 'U15', 'DEPARTEMENTAL', null, 6, [['13:00', '18:00']]);
        $this->addCopy($a2, 'U15', 'DEPARTEMENTAL', null, 6, [['13:00', '18:00']]);
        // Un 3ᵉ pair saisit X mais sur une saison DRAFT (non active) → ne doit pas compter.
        [$draftClub] = $this->makeClub($this->code($ligue, $comite));
        $draftSeason = $this->addSeason($draftClub->getId(), SeasonStatus::DRAFT, +1);
        $this->addCopy($draftSeason, 'U15', 'DEPARTEMENTAL', null, 6, [['13:00', '18:00']]);
        [, , $reader] = $this->makeClub($this->code($ligue, $comite));

        // Si la saison DRAFT comptait, ce serait 3 (servi) ; elle ne compte pas → 2, rien.
        self::assertNull($this->itemFor($this->suggestions($reader), 'U15', 6), 'une copie sur saison non active ne compte pas');
    }

    // ── (h) 403 non-gestionnaire ──────────────────────────────────────────────

    public function testNonManagementMemberIsForbiddenOnBothEndpoints(): void
    {
        [$club] = $this->makeClub($this->code($this->uniqueLigue(), '0069'));
        $member = $this->addMember($club, 'coach');

        $this->client->request('GET', '/api/league-window-suggestions', [], [], $this->authHeaders($member));
        self::assertResponseStatusCodeSame(403, 'la lecture est réservée au gestionnaire');

        $this->client->request('POST', '/api/league-window-suggestions/apply', [], [], $this->authHeaders($member) + ['CONTENT_TYPE' => 'application/json'], json_encode(['combinations' => []], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403, 'l\'application est réservée au gestionnaire');
    }

    // ── (i) code illisible → réponse neutre ───────────────────────────────────

    public function testUnreadableFfbbCodeYieldsNeutralResponse(): void
    {
        [, , $reader] = $this->makeClub('A11Y1234567'); // pas de préfixe 3 lettres → illisible
        $data = $this->suggestions($reader);
        self::assertNull($data['instance'], 'code illisible → instance null');
        self::assertSame([], $data['items'], 'code illisible → aucun item, sans message alarmant');
    }

    // ── (j) pg_proc : SECURITY DEFINER, search_path figé, EXECUTE au rôle app seul ─

    public function testFunctionIsSecurityDefinerWithFrozenSearchPathAndAppOnlyExecute(): void
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT prosecdef, proconfig FROM pg_proc WHERE proname = \'league_window_suggestions\'',
        );
        self::assertIsArray($row, 'la fonction existe');
        self::assertTrue((bool) $row['prosecdef'], 'la fonction est SECURITY DEFINER');
        $proconfig = (string) $row['proconfig'];
        self::assertStringContainsString('search_path=pg_catalog, public', $proconfig, 'search_path figé');
        // Durcissement revue sécurité : pg_temp présent ET EN DERNIER (reco PostgreSQL pour
        // SECURITY DEFINER — sinon une table temporaire pourrait ombrer club/season/…).
        self::assertStringContainsString('pg_temp', $proconfig, 'pg_temp est dans le search_path');
        self::assertStringEndsWith('pg_temp"}', $proconfig, 'pg_temp est le DERNIER schéma du search_path');

        self::assertTrue((bool) $this->conn()->fetchOne(
            'SELECT has_function_privilege(\'amateo_app\', \'league_window_suggestions(uuid)\', \'EXECUTE\')',
        ), 'le rôle applicatif peut exécuter');
        self::assertFalse((bool) $this->conn()->fetchOne(
            'SELECT has_function_privilege(\'public\', \'league_window_suggestions(uuid)\', \'EXECUTE\')',
        ), 'PUBLIC ne peut PAS exécuter (surface d\'appel close)');
    }

    // ── (k) repli fédéral : ARA servi, GUY rien ───────────────────────────────

    public function testFederationFallbackIsServedForAraButNotForGuy(): void
    {
        // ARA → AURA catalogué (21 fenêtres). Copie VIDE → le repli fédéral diffère de
        // la copie → il est servi, étiqueté « fédération ».
        [, , $ara] = $this->makeClub('ARA0069123');
        $data = $this->suggestions($ara);
        self::assertNotNull($data['instance']);
        self::assertNotSame([], $data['items'], 'ARA : repli fédéral servi (copie vide)');
        foreach ($data['items'] as $item) {
            self::assertSame('federation', $item['source'], 'sans tendance, tout item vient du catalogue fédéral');
            self::assertNull($item['clubCount'], 'un repli fédéral ne porte pas de compte de clubs');
            self::assertSame('federation', $item['scope']);
        }

        // GUY : ligue non cataloguée (absente de PREFIX_LEAGUE) → JAMAIS AURA → rien.
        [, , $guy] = $this->makeClub('GUY0973017');
        $guyData = $this->suggestions($guy);
        self::assertNotNull($guyData['instance'], 'GUY est une instance lisible (outre-mer)');
        self::assertSame([], $guyData['items'], 'GUY : aucune tendance, aucun repli fédéral (jamais AURA pour une autre ligue)');
    }

    // ── (l) apply : recalcul serveur, transactionnel, 403 ─────────────────────

    public function testApplyRecomputesServerSideAndReplacesTheCopy(): void
    {
        $ligue = $this->uniqueLigue();
        $comite = '0069';
        foreach (['p1', 'p2', 'p3'] as $tag) {
            [, $s] = $this->makeClub($this->code($ligue, $comite));
            $this->addCopy($s, 'U13', 'DEPARTEMENTAL', null, 6, [['13:00', '18:00']]);
        }
        [$readerClub, $readerSeason, $reader] = $this->makeClub($this->code($ligue, $comite));

        // Le client envoie des plages BIDON dans la combinaison — elles doivent être
        // IGNORÉES (recalcul serveur) ; une combinaison sans suggestion est ignorée.
        $applied = $this->apply($reader, [
            ['category' => 'U13', 'level' => 'DEPARTEMENTAL', 'gender' => null, 'dayOfWeek' => 6, 'windows' => [['kickoffMin' => '00:00', 'kickoffMax' => '23:59']]],
            ['category' => 'INEXISTANTE', 'level' => 'DEPARTEMENTAL', 'gender' => null, 'dayOfWeek' => 6],
        ]);
        self::assertSame(1, $applied['applied'], 'une seule combinaison appliquée (l\'inexistante est ignorée)');

        // La copie du lecteur porte DÉSORMAIS l'ensemble RECALCULÉ (13:00-18:00), pas le bidon.
        $this->scopeGucToClub($readerClub->getId());
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT to_char(kickoff_min, \'HH24:MI\') AS mn, to_char(kickoff_max, \'HH24:MI\') AS mx FROM club_league_window WHERE club_id = :c AND season_id = :s AND category = \'U13\'',
            ['c' => $readerClub->getId(), 's' => $readerSeason->getId()],
        );
        self::assertSame([['mn' => '13:00', 'mx' => '18:00']], $rows, 'la copie porte l\'ensemble recalculé serveur, jamais les plages du client');
    }

    public function testApplyIsForbiddenToNonManagement(): void
    {
        [$club] = $this->makeClub($this->code($this->uniqueLigue(), '0069'));
        $member = $this->addMember($club, 'coach');

        $this->client->request('POST', '/api/league-window-suggestions/apply', [], [], $this->authHeaders($member) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'combinations' => [['category' => 'U13', 'level' => 'DEPARTEMENTAL', 'gender' => null, 'dayOfWeek' => 6]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }

    // ── Infrastructure ────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function uniqueLigue(): string
    {
        // Un préfixe de 3 lettres jamais catalogué (hors PREFIX_LEAGUE) et unique par run.
        $n = self::$seq++;

        return 'Q' . \chr(65 + intdiv($n, 26) % 26) . \chr(65 + $n % 26);
    }

    private function code(string $ligue, string $comite): string
    {
        // ligue(3) + comité(4) + numéro club unique (chiffres) — le résolveur ne lit que les 7 premiers.
        return $ligue . $comite . str_pad((string) (100 + self::$seq++), 4, '0', \STR_PAD_LEFT);
    }

    /** @return array{0: Club, 1: Season, 2: User} */
    private function makeClub(string $ffbbCode): array
    {
        $uid = uniqid('lws', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club LWS ' . $uid);
        $club->setSlug('club-lws-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($ffbbCode);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('lws' . $uid . '@test.com');
        $user->setFirstName('Le');
        $user->setLastName('Ws');
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

        $season = $this->addSeason($club->getId(), SeasonStatus::ACTIVE, 0);

        return [$club, $season, $user];
    }

    private function addSeason(string $clubId, SeasonStatus $status, int $yearOffset): Season
    {
        $this->scopeGucToClub($clubId);
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today')) + $yearOffset;
        $season = new Season;
        $season->setClubId($clubId);
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus($status);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    /**
     * @param list<array{0: string, 1: string}> $windows [ [min, max], … ]
     */
    private function addCopy(Season $season, string $category, string $level, ?string $gender, int $dayOfWeek, array $windows): void
    {
        $this->scopeGucToClub($season->getClubId());
        foreach ($windows as [$min, $max]) {
            $row = new ClubLeagueWindow;
            $row->setClubId($season->getClubId());
            $row->setSeasonId($season->getId());
            $row->setLeague('AURA');
            $row->setCategory($category);
            $row->setLevel($level);
            $row->setGender($gender);
            $row->setDayOfWeek($dayOfWeek);
            $row->setKickoffMin(new DateTimeImmutable($min));
            $row->setKickoffMax(new DateTimeImmutable($max));
            $this->em->persist($row);
        }
        $this->em->flush();
    }

    private function addMember(Club $club, string $role): User
    {
        $uid = uniqid('m', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $member = new User;
        $member->setEmail('member' . $uid . '@test.com');
        $member->setFirstName('Me');
        $member->setLastName('Mber');
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

    /**
     * @return array{instance: array{ligue: string, comite: string}|null, items: list<array<string, mixed>>}
     */
    private function suggestions(User $user): array
    {
        $this->client->request('GET', '/api/league-window-suggestions', [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        /** @var array{instance: array{ligue: string, comite: string}|null, items: list<array<string, mixed>>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function rawBody(User $user): string
    {
        $this->client->request('GET', '/api/league-window-suggestions', [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @param array{instance: mixed, items: list<array<string, mixed>>} $data
     *
     * @return array<string, mixed>|null
     */
    private function itemFor(array $data, string $category, int $dayOfWeek): ?array
    {
        foreach ($data['items'] as $item) {
            if ($item['category'] === $category && $item['dayOfWeek'] === $dayOfWeek) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $combinations
     *
     * @return array{applied: int}
     */
    private function apply(User $user, array $combinations): array
    {
        $this->client->request('POST', '/api/league-window-suggestions/apply', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['combinations' => $combinations], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        /** @var array{applied: int} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /** Reads the SQL function's own count for a combination (bypasses the service masking). */
    private function functionCount(string $clubId, string $category, int $dayOfWeek): ?int
    {
        $value = $this->conn()->fetchOne(
            'SELECT club_count FROM league_window_suggestions(:club) WHERE category = :cat AND day_of_week = :day',
            ['club' => $clubId, 'cat' => $category, 'day' => $dayOfWeek],
        );

        return false === $value ? null : (int) $value;
    }

    private function conn(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
