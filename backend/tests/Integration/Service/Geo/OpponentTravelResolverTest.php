<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Geo;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentTravelSource;
use App\Enum\SeasonStatus;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentTravelRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbSalleResolver;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\OpponentTravelResolver;
use App\Service\Geo\TravelTimeCache;
use App\Service\SeasonResolver;
use App\Tests\Double\FrozenClock;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2-54 RMM-9 PR-3 — le calcul AUTO des trajets adverses depuis le siège du club.
 * Best-effort (IGN muet → minutes null) et, LE cœur du patron, une valeur MANUAL
 * n'est JAMAIS écrasée par la passe AUTO.
 */
#[Group('integration')]
final class OpponentTravelResolverTest extends WebTestCase
{
    use TenantGucTrait;

    private const string OPPONENT_CODE = 'ARA0069123';

    private EntityManagerInterface $em;

    public function testAutoComputesTravelFromTheClubSiegeToTheDirectoryLocation(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $this->seedDirectoryEntry(45.76, 4.86);

        $result = $this->resolverWithIgn(1320)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['resolved']);
        self::assertSame([], $result['unresolved']);

        $this->scopeGucToClub($club->getId());
        $row = $this->travelRepository()->findOneByCode($season->getId(), self::OPPONENT_CODE);
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(22, $row->getTravelMinutes(), '1320 s → 22 min (aller simple voiture)');
        self::assertSame(OpponentTravelSource::AUTO, $row->getSource());
        self::assertFalse($row->hasOverride());
    }

    public function testManualRowIsNeverOverwrittenByTheAutoPass(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $this->seedDirectoryEntry(45.76, 4.86);

        $this->scopeGucToClub($club->getId());
        $manual = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode(self::OPPONENT_CODE)
            ->setSource(OpponentTravelSource::MANUAL)
            ->setTravelMinutes(7)
            ->setOverrideVenueLabel('Le vrai gymnase')
            ->setOverrideLatitude(45.5)
            ->setOverrideLongitude(4.5)
            ->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($manual);
        $this->em->flush();

        $result = $this->resolverWithIgn(9999)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['skippedManual']);
        self::assertSame(0, $result['resolved']);

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        $row = $this->travelRepository()->findOneByCode($season->getId(), self::OPPONENT_CODE);
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(7, $row->getTravelMinutes(), 'la valeur MANUAL survit à la passe AUTO');
        self::assertSame(OpponentTravelSource::MANUAL, $row->getSource());
        self::assertSame('Le vrai gymnase', $row->getOverrideVenueLabel());
    }

    /**
     * Une ligne MANUAL restée SANS trajet (IGN muet AU MOMENT du choix) mais dont l'override
     * porte des coordonnées est RE-ROUTÉE par la passe AUTO : SEUL le trajet (et resolvedAt)
     * change, le gymnase épinglé et la source MANUAL restent souverains. Falsifié : avant le
     * correctif la ligne était « sautée » (skippedManual) et son trajet restait null pour
     * toujours ; après, elle est enfin routée sans que son bloc override bouge.
     */
    public function testAManualRowWithoutTravelIsReRoutedWithoutTouchingItsOverride(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        // Pas d'entrée d'annuaire : la SEULE raison d'entrer dans le routage est l'override.

        $this->scopeGucToClub($club->getId());
        $manual = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode(self::OPPONENT_CODE)
            ->setSource(OpponentTravelSource::MANUAL)
            ->setTravelMinutes(null) // IGN muet au moment du choix
            ->setOverrideVenueLabel('Le vrai gymnase')
            ->setOverrideVenueExternalRef('166900101')
            ->setOverrideLatitude(45.5)
            ->setOverrideLongitude(4.5)
            ->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($manual);
        $this->em->flush();

        $result = $this->resolverWithIgn(1320)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['resolved'], 'la ligne MANUAL sans trajet est enfin routée');
        self::assertSame(0, $result['skippedManual'], 'elle n\'est plus sautée : elle est re-routée');

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        $row = $this->travelRepository()->findOneByCode($season->getId(), self::OPPONENT_CODE);
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(22, $row->getTravelMinutes(), '1320 s → 22 min : le trajet est enfin calculé depuis l\'override');
        self::assertSame(OpponentTravelSource::MANUAL, $row->getSource(), 'la source reste MANUAL (le gymnase épinglé est souverain)');
        self::assertSame('Le vrai gymnase', $row->getOverrideVenueLabel(), 'le bloc override n\'est pas touché');
        self::assertSame('166900101', $row->getOverrideVenueExternalRef());
        self::assertSame(45.5, $row->getOverrideLatitude());
        self::assertSame(4.5, $row->getOverrideLongitude());
    }

    /**
     * C5-bis — une ligne ÉQUIPE (`opponentTeamKey` non NULL) AUTO au trajet null MAIS
     * portant des coordonnées d'override (posée par l'auto-localisateur pendant un IGN
     * dégradé) est enfin ROUTÉE : SEUL le trajet (et resolvedAt) change, le gymnase épinglé
     * et la source AUTO restent souverains. Falsifié : avant C5-bis la passe ne regardait
     * QUE les lignes club — une ligne équipe restait à jamais sans trajet.
     */
    public function testATeamAutoRowWithoutTravelIsReRoutedFromItsOverrideKeepingItSovereign(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent(); // fixture code sans annuaire → passe club vide

        $this->scopeGucToClub($club->getId());
        $team = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode('ARA0069TEAM')
            ->setOpponentTeamKey('adverse 1')
            ->setSource(OpponentTravelSource::AUTO)
            ->setTravelMinutes(null) // IGN dégradé au moment de l'auto-localisation
            ->setOverrideVenueLabel('Gymnase de l\'équipe 1')
            ->setOverrideVenueExternalRef('166900199')
            ->setOverrideLatitude(45.5)
            ->setOverrideLongitude(4.5)
            ->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($team);
        $this->em->flush();

        $result = $this->resolverWithIgn(1320)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['resolved'], 'la ligne équipe sans trajet est enfin routée');

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        $row = $this->travelRepository()->findOneByCode($season->getId(), 'ARA0069TEAM', 'adverse 1');
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(22, $row->getTravelMinutes(), '1320 s → 22 min depuis l\'override');
        self::assertSame(OpponentTravelSource::AUTO, $row->getSource(), 'la source reste AUTO');
        self::assertSame('Gymnase de l\'équipe 1', $row->getOverrideVenueLabel(), 'le gymnase épinglé n\'est pas touché');
        self::assertSame('166900199', $row->getOverrideVenueExternalRef());
        self::assertSame(45.5, $row->getOverrideLatitude());
        self::assertSame(4.5, $row->getOverrideLongitude());
    }

    public function testAnOpponentWithNoDirectoryLocationComesBackUnresolved(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        // No directory entry seeded → nothing to route to.

        $result = $this->resolverWithIgn(1320)->resolve($club->getId(), $season->getId());

        self::assertSame(0, $result['resolved']);
        self::assertSame([self::OPPONENT_CODE], $result['unresolved']);

        $this->scopeGucToClub($club->getId());
        self::assertNull($this->travelRepository()->findOneByCode($season->getId(), self::OPPONENT_CODE));
    }

    /**
     * C5 (durcit BCK-22) : un trajet est une CONSTANTE. Une ligne AUTO qui porte DÉJÀ
     * un trajet n'est JAMAIS re-routée — fini le « reroute TOUT » qui, sur un IGN muet,
     * valait `setTravelMinutes(null)` et détruisait la valeur. Résultat : aucun appel
     * réseau, `resolved = 0`, `unresolved = []`, et toutes les valeurs survivent intactes.
     */
    public function testAnExistingAutoTravelIsNeverRecomputed(): void
    {
        $club = new Club;
        $club->setName('Club budget ' . uniqid('', true));
        $club->setSlug('club-budget-opp-' . uniqid('', true));
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setLatitude(45.70);
        $club->setLongitude(4.90);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $season->setStartDate(new DateTimeImmutable('today'));
        $season->setEndDate(new DateTimeImmutable('+300 days'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        // 9 adversaires AWAY géolocalisés, chacun avec une bonne ligne AUTO déjà en base
        // (99 min) : le cas exact où « reroute TOUT » pouvait écraser une valeur.
        for ($i = 0; $i < 9; ++$i) {
            $code = \sprintf('ARA00699%03d', $i);

            $fixture = new Fixture;
            $fixture->setClubId($club->getId());
            $fixture->setSeasonId($season->getId());
            $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
            $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
            $fixture->setHomeAway(FixtureHomeAway::AWAY);
            $fixture->setOpponentLabel('Adverse ' . $i);
            $fixture->setOpponentOrganismeCode($code);
            $this->em->persist($fixture);

            $existing = (new OpponentTravel)
                ->setClubId($club->getId())
                ->setSeasonId($season->getId())
                ->setOpponentOrganismeCode($code)
                ->setSource(OpponentTravelSource::AUTO)
                ->setTravelMinutes(99)
                ->setResolvedAt(new DateTimeImmutable);
            $this->em->persist($existing);
        }
        $this->em->flush();

        // Directory entries are GLOBAL (no club) → seeded without a GUC.
        for ($i = 0; $i < 9; ++$i) {
            $entry = new OpponentDirectoryEntry(\sprintf('ARA00699%03d', $i), 'Adverse ' . $i, OpponentLocationPrecision::CITY);
            $entry->setLatitude(45.76)->setLongitude(4.86)->setCity('Lyon');
            $this->em->persist($entry);
        }
        $this->em->flush();

        $calls = 0;
        $result = $this->cachingResolver($calls)->resolve($club->getId(), $season->getId());

        // Rien à recalculer : toutes les valeurs existent déjà (constantes).
        self::assertSame(0, $result['resolved'], 'aucune valeur n\'est (re)calculée');
        self::assertSame([], $result['unresolved'], 'aucun manque : rien n\'est ciblé');
        self::assertSame(0, $calls, 'aucun appel IGN — une constante ne repart jamais au réseau');

        // Les 9 bonnes valeurs AUTO survivent intactes.
        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        for ($i = 0; $i < 9; ++$i) {
            $row = $this->travelRepository()->findOneByCode($season->getId(), \sprintf('ARA00699%03d', $i));
            self::assertInstanceOf(OpponentTravel::class, $row, "la ligne du code {$i} existe toujours");
            self::assertSame(99, $row->getTravelMinutes(), "la bonne valeur AUTO du code {$i} survit");
            self::assertSame(OpponentTravelSource::AUTO, $row->getSource());
        }
    }

    /**
     * P2-54 « adversaire multi-gymnases » — la structure que LIT le radar
     * ({@see OpponentTravelRepository::travelMinutesBySeason}) : par code, le défaut club
     * et les surcharges par équipe. Le radar projette alors `teams[teamKey] ?? club` :
     * la fixture « - 2 » prend le trajet équipe (20), « - 1 » retombe sur le club (60).
     */
    public function testTravelMinutesBySeasonExposesTeamOverridesWithAClubFallback(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $code = self::OPPONENT_CODE;

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new OpponentTravel)
            ->setClubId($club->getId())->setSeasonId($season->getId())->setOpponentOrganismeCode($code)
            ->setSource(OpponentTravelSource::AUTO)->setTravelMinutes(60)->setResolvedAt(new DateTimeImmutable));
        $this->em->persist((new OpponentTravel)
            ->setClubId($club->getId())->setSeasonId($season->getId())->setOpponentOrganismeCode($code)
            ->setOpponentTeamKey('adverse trajet 2')
            ->setSource(OpponentTravelSource::MANUAL)->setTravelMinutes(20)->setResolvedAt(new DateTimeImmutable));
        $this->em->flush();
        $this->em->clear();

        $this->scopeGucToClub($club->getId());
        $map = $this->travelRepository()->travelMinutesBySeason($season->getId());

        self::assertArrayHasKey($code, $map);
        self::assertSame(60, $map[$code]['club'], 'le défaut club (repli du radar)');
        self::assertSame(['adverse trajet 2' => 20], $map[$code]['teams'], 'la surcharge par équipe, keyée sur le libellé normalisé');
        // The radar's projection: teams[teamKey] ?? club.
        self::assertSame(20, $map[$code]['teams']['adverse trajet 2'] ?? $map[$code]['club'], 'fixture « - 2 » → trajet équipe');
        self::assertSame(60, $map[$code]['teams']['adverse trajet 1'] ?? $map[$code]['club'], 'fixture « - 1 » → repli club');
    }

    /**
     * P2-54 PR-2 — un choix MANUAL référencé alimente la suggestion PARTAGÉE (+1) ;
     * re-choisir le même gymnase est neutre ; changer de gymnase décrémente l'ancien
     * ref et incrémente le nouveau.
     */
    public function testManualChoiceFeedsTheSharedSuggestionAndAChangeMovesTheCount(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $refA = '166900101';
        $refB = '166900102';
        $resolver = $this->resolverWithIgn(1320);

        $this->scopeGucToClub($club->getId());
        $resolver->applyManualOverride($club->getId(), $season->getId(), self::OPPONENT_CODE, null, $refA, 'GYMNASE A', 45.76, 4.86);
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $refA), 'un choix référencé alimente la suggestion (+1)');

        // Re-choisir exactement le même gymnase : neutre.
        $resolver->applyManualOverride($club->getId(), $season->getId(), self::OPPONENT_CODE, null, $refA, 'GYMNASE A', 45.76, 4.86);
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $refA), 're-choisir le même gymnase ne double pas le compte');

        // Changer de gymnase : −1 sur l'ancien, +1 sur le nouveau.
        $resolver->applyManualOverride($club->getId(), $season->getId(), self::OPPONENT_CODE, null, $refB, 'GYMNASE B', 45.60, 4.70);
        self::assertSame(0, $this->suggestionCount(self::OPPONENT_CODE, $refA), 'changer de choix décrémente l\'ancien ref');
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $refB), 'et incrémente le nouveau');
    }

    /** Un choix MANUAL SANS référence de salle reste tenant — il n'alimente PAS le partagé. */
    public function testManualChoiceWithoutRefFeedsNothingToTheSharedTable(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $resolver = $this->resolverWithIgn(1320);

        $this->scopeGucToClub($club->getId());
        $resolver->applyManualOverride($club->getId(), $season->getId(), self::OPPONENT_CODE, null, null, 'GYMNASE LIBRE', 45.76, 4.86);

        $shared = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code',
            ['code' => self::OPPONENT_CODE],
        );
        self::assertSame(0, $shared, 'un gymnase saisi sans référence FFBB ne partage rien (tenant seulement)');
    }

    /** Rétablir l'AUTO sur la ligne CLUB décrémente le ref qu'elle portait (idempotent). */
    public function testRevertToAutoDecrementsTheClubRowRef(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $this->seedDirectoryEntry(45.76, 4.86);
        $ref = '166900201';
        $resolver = $this->resolverWithIgn(1320);

        $this->scopeGucToClub($club->getId());
        $resolver->applyManualOverride($club->getId(), $season->getId(), self::OPPONENT_CODE, null, $ref, 'GYMNASE C', 45.76, 4.86);
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $ref));

        $resolver->revertToAuto($club->getId(), $season->getId(), self::OPPONENT_CODE);
        self::assertSame(0, $this->suggestionCount(self::OPPONENT_CODE, $ref), 'rétablir l\'automatique rend le gymnase → −1 partagé');
    }

    /** Supprimer une surcharge ÉQUIPE (rétablir l'AUTO d'une équipe, A3) décrémente son ref. */
    public function testDeleteTeamOverrideDecrementsTheTeamRowRef(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $ref = '166900301';
        $teamKey = 'adverse trajet 2';
        $resolver = $this->resolverWithIgn(1320);

        $this->scopeGucToClub($club->getId());
        $resolver->applyManualOverride($club->getId(), $season->getId(), self::OPPONENT_CODE, $teamKey, $ref, 'GYMNASE EQUIPE', 45.76, 4.86);
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $ref));

        self::assertTrue($resolver->deleteTeamOverride($season->getId(), self::OPPONENT_CODE, $teamKey));
        self::assertSame(0, $this->suggestionCount(self::OPPONENT_CODE, $ref), 'supprimer la surcharge équipe rend le gymnase → −1 partagé');
    }

    /**
     * PR-2b — garde P4-209(a) : supprimer une ligne équipe **AUTO** (posée par
     * l'auto-localisation depuis le fichier, portant un ref fédéral mais n'ayant JAMAIS
     * compté comme un choix) NE décrémente PAS le partagé — sinon on volerait le compte
     * d'un autre club. Falsifié : le compte d'un autre club reste intact.
     */
    public function testDeletingAnAutoTeamRowDoesNotDecrementTheSharedCount(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $ref = '166900401';
        $teamKey = 'adverse trajet 2';

        // Un AUTRE club a choisi ce gymnase (compte partagé = 1) — indépendant de nous.
        $this->suggestionRepository()->upsertManual(self::OPPONENT_CODE, $ref, 'GYMNASE FEDERAL', null, null, 45.76, 4.86);
        $this->suggestionRepository()->increment(self::OPPONENT_CODE, $ref);
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $ref));

        // Notre ligne équipe est AUTO (auto-localisée) et porte le MÊME ref fédéral.
        $this->scopeGucToClub($club->getId());
        $auto = (new OpponentTravel)
            ->setClubId($club->getId())->setSeasonId($season->getId())->setOpponentOrganismeCode(self::OPPONENT_CODE)
            ->setOpponentTeamKey($teamKey)
            ->setSource(OpponentTravelSource::AUTO)->setTravelMinutes(12)
            ->setOverrideVenueLabel('GYMNASE FEDERAL')->setOverrideVenueExternalRef($ref)
            ->setOverrideLatitude(45.76)->setOverrideLongitude(4.86)->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($auto);
        $this->em->flush();

        self::assertTrue($this->resolverWithIgn(1320)->deleteTeamOverride($season->getId(), self::OPPONENT_CODE, $teamKey));
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $ref), 'supprimer une ligne AUTO ne touche PAS le compte partagé d\'un autre club');
    }

    /**
     * PR-2b — `manual` sur une ligne équipe **AUTO** la remplace par MANUAL : +1 sur le
     * nouveau gymnase, JAMAIS de −1 sur l'ancien ref (l'AUTO n'avait jamais compté). Le
     * compte d'un autre club sur l'ancien ref reste intact.
     */
    public function testManualOverAnAutoTeamRowAddsOneWithoutDecrementingTheOld(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $oldRef = '166900402';
        $newRef = '166900101'; // connu du salleResolver de ce test
        $teamKey = 'adverse trajet 2';

        // Un autre club tient le compte de l'ancien ref (= 1) — indépendant.
        $this->suggestionRepository()->upsertManual(self::OPPONENT_CODE, $oldRef, 'ANCIEN GYM', null, null, 45.76, 4.86);
        $this->suggestionRepository()->increment(self::OPPONENT_CODE, $oldRef);

        // Notre ligne équipe est AUTO et porte l'ancien ref.
        $this->scopeGucToClub($club->getId());
        $auto = (new OpponentTravel)
            ->setClubId($club->getId())->setSeasonId($season->getId())->setOpponentOrganismeCode(self::OPPONENT_CODE)
            ->setOpponentTeamKey($teamKey)
            ->setSource(OpponentTravelSource::AUTO)->setTravelMinutes(12)
            ->setOverrideVenueLabel('ANCIEN GYM')->setOverrideVenueExternalRef($oldRef)
            ->setOverrideLatitude(45.76)->setOverrideLongitude(4.86)->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($auto);
        $this->em->flush();

        $this->resolverWithIgn(1320)->applyManualOverride($club->getId(), $season->getId(), self::OPPONENT_CODE, $teamKey, $newRef, 'GYMNASE B', 45.76, 4.86);

        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $oldRef), 'remplacer un AUTO ne décrémente pas l\'ancien ref (jamais compté)');
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $newRef), 'le nouveau choix MANUAL compte +1');
    }

    /**
     * BCK-32 — cap dur : 61 adversaires AWAY géolocalisés (aucun MANUAL). Sans le cap,
     * les 61 seraient routés ; {@see OpponentTravelResolver::MAX_OPPONENTS} (60) en route
     * 60 et renvoie le 61ᵉ en `unresolved` SANS aucun appel IGN pour l'excès.
     */
    public function testResolveCapsAtSixtyOpponentsAndTheExcessComesBackUnresolvedWithoutNetwork(): void
    {
        [$club, $season] = $this->seedManyGeolocatedAwayOpponents(61);

        $calls = 0;
        $result = $this->countingResolver($calls)->resolve($club->getId(), $season->getId());

        self::assertSame(60, $result['resolved'], 'exactement 60 adversaires routés (cap dur)');
        self::assertCount(1, $result['unresolved'], 'le 61ᵉ revient non résolu');
        self::assertSame(60, $calls, 'aucun appel IGN pour l\'excès — le 61ᵉ n\'est jamais dispatché');
    }

    public function testResolveServesACachedTravelWithoutTouchingTheNetwork(): void
    {
        [$club, $season] = $this->seedManyGeolocatedAwayOpponents(1);
        // Le trajet est DÉJÀ en cache (une constante) : siège 45.70,4.90 → adverse 45.76,4.86.
        self::getContainer()->get(TravelTimeCache::class)->store($club->getId(), IgnRoutingClient::PROFILE_CAR, 45.70, 4.90, 45.76, 4.86, 88);

        $calls = 0;
        $result = $this->cachingResolver($calls)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['resolved'], 'l\'adversaire est résolu');
        self::assertSame(0, $calls, 'un trajet en cache ne déclenche AUCUN appel IGN (jamais recalculé)');
        $row = $this->em->getRepository(OpponentTravel::class)->findOneBy(['opponentOrganismeCode' => 'ARA0069C000']);
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(88, $row->getTravelMinutes(), 'la valeur servie vient du cache');
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * A resolver whose IGN rides a MockHttpClient that COUNTS every itinerary call
     * (still MockClock, budget never bites) — to prove the cap spends no network on
     * the excess.
     */
    private function countingResolver(int &$calls): OpponentTravelResolver
    {
        // FrozenClock : le pacing 1/s ne consomme aucun budget (sleep no-op), donc le
        // budget ne mord jamais — le test isole le CAP DUR (60), pas le budget de mur.
        $ign = new IgnRoutingClient(new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse((string) json_encode(['duration' => 600]));
        }), new FrozenClock);

        return new OpponentTravelResolver(
            $this->em,
            $ign,
            $this->travelRepository(),
            self::getContainer()->get(OpponentDirectoryEntryRepository::class),
            $this->suggestionRepository(),
            $this->salleResolver(),
            self::getContainer()->get(ClubRepository::class),
            self::getContainer()->get(FixtureRepository::class),
            new NullLogger,
        );
    }

    /** The real resolver wired with the container's cache + a counting IGN (FrozenClock : pas de pacing). */
    private function cachingResolver(int &$calls): OpponentTravelResolver
    {
        $ign = new IgnRoutingClient(new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse((string) json_encode(['duration' => 600]));
        }), new FrozenClock);

        return new OpponentTravelResolver(
            $this->em,
            $ign,
            $this->travelRepository(),
            self::getContainer()->get(OpponentDirectoryEntryRepository::class),
            $this->suggestionRepository(),
            $this->salleResolver(),
            self::getContainer()->get(ClubRepository::class),
            self::getContainer()->get(FixtureRepository::class),
            new NullLogger,
            self::getContainer()->get(TravelTimeCache::class),
        );
    }

    /**
     * A club with $count geolocated AWAY opponents (a fixture + a directory entry each,
     * no MANUAL travel row) — the work set of a cap test.
     *
     * @return array{0: Club, 1: Season}
     */
    private function seedManyGeolocatedAwayOpponents(int $count): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club cap ' . $uid);
        $club->setSlug('club-cap-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setLatitude(45.70);
        $club->setLongitude(4.90);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $season->setStartDate(new DateTimeImmutable('today'));
        $season->setEndDate(new DateTimeImmutable('+300 days'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        for ($i = 0; $i < $count; ++$i) {
            $fixture = new Fixture;
            $fixture->setClubId($club->getId());
            $fixture->setSeasonId($season->getId());
            $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
            $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
            $fixture->setHomeAway(FixtureHomeAway::AWAY);
            $fixture->setOpponentLabel('Adverse cap ' . $i);
            $fixture->setOpponentOrganismeCode(\sprintf('ARA0069C%03d', $i));
            $this->em->persist($fixture);
        }
        $this->em->flush();

        // Directory entries are GLOBAL (no club) → seeded without a GUC.
        for ($i = 0; $i < $count; ++$i) {
            $entry = new OpponentDirectoryEntry(\sprintf('ARA0069C%03d', $i), 'Adverse cap ' . $i, OpponentLocationPrecision::CITY);
            $entry->setLatitude(45.76)->setLongitude(4.86)->setCity('Lyon');
            $this->em->persist($entry);
        }
        $this->em->flush();

        return [$club, $season];
    }

    /**
     * The real resolver, but its IGN client rides a MockHttpClient returning a
     * fixed duration (seconds) for every pair. The clock defaults to a still
     * MockClock (budget never bites); a SteppingClock forces the budget to expire.
     */
    private function resolverWithIgn(int $durationSeconds, ?ClockInterface $clock = null): OpponentTravelResolver
    {
        $ign = new IgnRoutingClient(new MockHttpClient(
            static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => $durationSeconds])),
        ), $clock ?? new MockClock);

        return new OpponentTravelResolver(
            $this->em,
            $ign,
            $this->travelRepository(),
            self::getContainer()->get(OpponentDirectoryEntryRepository::class),
            $this->suggestionRepository(),
            $this->salleResolver(),
            self::getContainer()->get(ClubRepository::class),
            self::getContainer()->get(FixtureRepository::class),
            new NullLogger,
        );
    }

    /**
     * A FfbbSalleResolver on a MockHttpClient: any `ffbbserver_salles` geo query returns
     * a fixed set of FEDERAL salles (the test refs), so a manual choice re-resolves its
     * federal label/coords server-side exactly like production — never the caller's text.
     */
    private function salleResolver(): FfbbSalleResolver
    {
        $salles = [];
        foreach (['166900101', '166900102', '166900201', '166900301'] as $numero) {
            $salles[] = [
                'numero' => $numero,
                'libelle' => 'GYMNASE FEDERAL ' . $numero,
                'cartographie' => ['ville' => 'Lyon', 'latitude' => 45.76, 'longitude' => 4.86],
                'commune' => ['codePostal' => '69001'],
            ];
        }
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use ($salles): MockResponse {
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';

            return new MockResponse((string) json_encode(['results' => [['hits' => str_contains($body, 'ffbbserver_salles') ? $salles : []]]]));
        });

        return new FfbbSalleResolver(new FfbbApiClient($mock, 'stub-token'));
    }

    private function suggestionRepository(): OpponentVenueSuggestionRepository
    {
        $repository = self::getContainer()->get(OpponentVenueSuggestionRepository::class);
        self::assertInstanceOf(OpponentVenueSuggestionRepository::class, $repository);

        return $repository;
    }

    private function suggestionCount(string $code, string $ref): ?int
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT chosen_by_count FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code AND venue_external_ref = :ref',
            ['code' => $code, 'ref' => $ref],
        );

        return false === $value ? null : (int) $value;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedClubWithAwayOpponent(): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club trajet ' . $uid);
        $club->setSlug('club-trajet-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setLatitude(45.70);
        $club->setLongitude(4.90);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $season->setStartDate(new DateTimeImmutable('today'));
        $season->setEndDate(new DateTimeImmutable('+300 days'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel('Adverse Trajet');
        $fixture->setOpponentOrganismeCode(self::OPPONENT_CODE);
        $this->em->persist($fixture);
        $this->em->flush();

        return [$club, $season];
    }

    private function seedDirectoryEntry(float $lat, float $lon): void
    {
        $entry = new OpponentDirectoryEntry(self::OPPONENT_CODE, 'Adverse Trajet', OpponentLocationPrecision::CITY);
        $entry->setLatitude($lat)->setLongitude($lon)->setCity('Lyon');
        $this->em->persist($entry);
        $this->em->flush();
    }

    private function travelRepository(): OpponentTravelRepository
    {
        $repository = self::getContainer()->get(OpponentTravelRepository::class);
        self::assertInstanceOf(OpponentTravelRepository::class, $repository);

        return $repository;
    }
}
