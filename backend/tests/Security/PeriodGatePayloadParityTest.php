<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Enum\VenuePeriodMode;
use App\Service\PeriodConstraintSelector;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * P2-14 (axe constraint semantics §7.1) — LA PARITÉ GATE ↔ PAYLOAD : le récap valide
 * exactement le jeu de contraintes que le solveur recevra, ni plus ni moins. Avant P2-14,
 * la sélection vivait en DEUX exemplaires entretenus à la main (« miroir EXACT » assumé
 * en commentaire) — et deux divergences réelles existaient déjà :
 *
 * - une contrainte DATÉE visant une équipe désactivée restait validée par le gate alors
 *   que le payload la filtrait — le récap pouvait donc bloquer (ou avertir) sur une règle
 *   que le solveur ne verrait jamais ;
 * - une CLUB+tag HARD à gymnase dédié dont toutes les équipes taguées sont en pause était
 *   sortie du gate, alors que le payload émet encore ses lignes « interdit hors tag ».
 *
 * Les deux sont épinglées ici, ainsi que l'invariant central : chaque id de contrainte du
 * payload remonte à une entité que le gate a validée, et réciproquement. Si quelqu'un
 * ré-introduit une sélection locale d'un côté, ce test rougit.
 */
#[Group('phase1')]
#[Group('security')]
final class PeriodGatePayloadParityTest extends WebTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testGateAndPayloadAgreeOnTheConstraintSet(): void
    {
        [$user, $club, $season, $entry, $planId, $ids] = $this->seedScenario();

        // 1) LA sélection (source unique) retient exactement : la TEAM active, la CLUB+tag
        //    HARD à gymnase dédié (toutes taguées en pause — divergence n° 2 alignée), et
        //    la datée de l'équipe active. Tout le reste sort, chacun pour sa raison.
        $selection = self::getContainer()->get(PeriodConstraintSelector::class)
            ->selectForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);
        $keptIds = array_map(static fn (Constraint $c): string => $c->getId(), $selection->kept);
        sort($keptIds);
        $expectedKept = [$ids['teamOk'], $ids['tagHardVenue'], $ids['datedOk']];
        sort($expectedKept);
        self::assertSame($expectedKept, $keptIds, 'la sélection retient le jeu attendu');
        $droppedVenueIds = array_map(static fn (array $d): string => $d['constraint']->getId(), $selection->droppedForDisabledVenue);
        sort($droppedVenueIds);
        $expectedDroppedVenue = [$ids['prefDisabledVenue'], $ids['tagAllActive']];
        sort($expectedDroppedVenue);
        self::assertSame($expectedDroppedVenue, $droppedVenueIds, 'les contraintes visant le gymnase désactivé sortent AVEC leur raison — y compris la CLUB+tag qui couvre toutes les actives (zéro ligne possible, revue #340 round 2)');
        // KEEP ne veut pas dire INTACTE : tagHardVenue est gardée pour son exclusivité de
        // gymnase dédié, mais sa clé secondaire désactivée tue ses règles par équipe — annoncé.
        self::assertSame(
            [$ids['tagHardVenue']],
            array_map(static fn (array $d): string => $d['constraint']->getId(), $selection->partiallyAppliedForDisabledVenue),
            'une CLUB+tag gardée pour sa seule exclusivité est signalée PARTIELLE (revue #340 round 2)',
        );

        // 2) PARITÉ : chaque ligne payload remonte à une entité retenue, et chaque entité
        //    retenue produit au moins une ligne. C'est l'invariant que les deux copies
        //    manuelles ne pouvaient que promettre.
        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)
            ->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);
        $payloadRootIds = [];
        foreach ($payload['constraints'] as $row) {
            self::assertIsArray($row);
            $rootId = explode(':', (string) $row['id'])[0];
            // Le builder SYNTHÉTISE aussi des lignes qui ne viennent d'aucune entité
            // (« priority-tier » : les poids de rang) — le gestionnaire ne les a pas
            // saisies, le gate n'a donc rien à en valider. La parité porte sur les
            // contraintes-entités : on ne garde que les racines en UUID.
            if (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $rootId)) {
                $payloadRootIds[$rootId] = true;
            }
        }
        $payloadRootIds = array_keys($payloadRootIds);
        sort($payloadRootIds);
        self::assertSame($expectedKept, $payloadRootIds, 'le payload sérialise EXACTEMENT les entités que le gate a validées');

        // Blindage du filtre UUID (revue #340 round 1) : quel que soit le FORMAT d'id d'une
        // future voie d'expansion, aucune ligne du payload ne doit CONTENIR l'id d'une
        // entité sortie de la sélection — sinon le filtre par racine masquerait la fuite.
        // (`facilityDefault` était une contrainte FACILITY_CAPACITY — famille retirée le 2026-08-08.)
        $excludedIds = [$ids['teamDeactivated'], $ids['prefDisabledVenue'], $ids['tagAllActive'], $ids['datedInertTag'], $ids['datedDeactivated']];
        foreach ($payload['constraints'] as $row) {
            self::assertIsArray($row);
            foreach ($excludedIds as $excludedId) {
                self::assertStringNotContainsString($excludedId, (string) $row['id'], 'une entité sortie de la sélection ne produit AUCUNE ligne, sous aucun format d\'id');
            }
        }

        // 3) Le GATE HTTP consomme la même sélection : l'erreur de la datée sur équipe
        //    désactivée (config invalide À DESSEIN) ne remonte pas — elle ne partira pas au
        //    solveur ; et le gymnase désactivé est annoncé, pas passé sous silence.
        $this->client->request('POST', '/api/constraints/validate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entry->getId()], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue($body['valid'], 'aucune erreur sur le jeu qui part réellement au solveur');
        self::assertArrayNotHasKey($ids['datedDeactivated'], $body['errors'], 'la datée d\'une équipe désactivée ne part pas au solveur : son invalidité ne bloque pas le récap (divergence n° 1 alignée)');
        // Ce test porte sur la PARITÉ gate↔payload, pas sur la capacité : on vérifie que les
        // warnings de SÉLECTION sont là, sans compter ceux des autres volets (revue #341 —
        // un décompte exact le rendait rouge à chaque évolution du volet capacité, pour une
        // raison étrangère à son sujet). Le volet capacité a son propre NR.
        $allWarnings = implode(' | ', array_map(strval(...), $body['warnings']));
        self::assertStringContainsString('Gymnase fermé pour période', $allWarnings, 'le drop pour gymnase désactivé est ANNONCÉ');
        self::assertStringContainsString('ne vise plus aucune équipe active', $allWarnings, 'la DATÉE au tag inerte est ANNONCÉE, jamais évaporée (revue #340)');
        self::assertStringContainsString('ses règles par équipe ne seront pas appliquées', $allWarnings, 'une CLUB+tag gardée pour sa seule exclusivité est annoncée PARTIELLE (revue #340 round 2)');
    }

    /**
     * P2-29 (axe constraint semantics §7.1) — les NOUVELLES formes traversent le MÊME foyer.
     *
     * Cibler PLUSIEURS tags (INTERSECTION) ou en EXCLURE (UNION soustraite) : le gate
     * (sélecteur) et le payload (builder) résolvent désormais via `TeamTagResolver::
     * resolveConstraintTeamIds`, une seule maison. Ce test le PROUVE de bout en bout —
     * `targetTags: [G1,G2] excludeTags: [EXCL]` ne retient QUE l'équipe des deux tags de
     * cible et hors du tag exclu, à l'identique des deux côtés, et sans qu'aucune clé de tag
     * ne parte au moteur (contrat 2.7 inchangé, D11).
     */
    public function testGateAndPayloadAgreeOnMultiTargetAndExclusion(): void
    {
        [$club, $season, $entry, $planId, $constraintId, $teamAId] = $this->seedMultiTargetScenario();

        // 1) Le gate (sélecteur) retient la contrainte : elle vise encore une équipe.
        $selection = self::getContainer()->get(PeriodConstraintSelector::class)
            ->selectForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);
        self::assertSame(
            [$constraintId],
            array_map(static fn (Constraint $c): string => $c->getId(), $selection->kept),
            'la CLUB multi-cible est retenue par le gate',
        );

        // 2) Le payload (builder) sérialise EXACTEMENT l'équipe de l'intersection moins
        //    l'exclusion — et AUCUNE clé de tag ne part au moteur.
        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)
            ->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);
        $teamRows = array_values(array_filter(
            $payload['constraints'],
            static fn (mixed $row): bool => \is_array($row) && str_starts_with((string) $row['id'], $constraintId . ':'),
        ));
        self::assertCount(1, $teamRows, 'une seule équipe : (G1 ∩ G2) − EXCL');
        self::assertSame($teamAId, $teamRows[0]['scopeTargetId'], 'l\'équipe des deux tags de cible, hors du tag exclu');
        foreach (['targetTag', 'targetTags', 'excludeTags'] as $tagKey) {
            self::assertArrayNotHasKey($tagKey, $teamRows[0]['config'], 'aucune clé de tag ne part au moteur (D11)');
        }
    }

    /**
     * Indispo INFORMATIVE (axe constraint semantics §7.1) — la sélection PORTE l'état effectif
     * au grain JOUR (incident × masque manuel), et le PAYLOAD le consomme : gate et payload le
     * partagent PAR CONSTRUCTION (une seule maison, `PlanVenueClosures`, via la sélection).
     *
     * Incident mercredi + masque {samedi: CLOSED} → gate ET payload retirent mer+sam et EUX
     * SEULS. Falsifié : masque {mercredi: OPEN} → les créneaux du mercredi reviennent des deux
     * côtés (l'incident redevient informatif).
     */
    public function testGateAndPayloadAgreeOnEffectiveClosedWeekdays(): void
    {
        // Fenêtre semaine pleine lun 2026-05-04 → dim 2026-05-10 ; mercredi = 05-06 (jour 3),
        // samedi = 05-09 (jour 6). Grille de saison : lundi(1), mercredi(3), samedi(6).
        [$club, $season, $venue, $entry, $planId] = $this->seedDayGrainScenario([6 => 'CLOSED']);
        $this->closure($club, $season, $entry, $venue->getId(), '2026-05-06', '2026-05-06'); // incident mercredi
        $this->em->flush();

        // 1) La SÉLECTION (source unique gate ↔ payload) porte l'état effectif : mer + sam.
        $selection = self::getContainer()->get(PeriodConstraintSelector::class)
            ->selectForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);
        $effective = array_keys($selection->effectiveClosedWeekdaysByVenue[$venue->getId()] ?? []);
        sort($effective);
        self::assertSame([3, 6], $effective, 'la sélection retire mer (incident) + sam (masque) et EUX SEULS');

        // 2) Le PAYLOAD consomme la sélection : le gymnase ne garde que le lundi.
        self::assertSame([1], $this->payloadWeekdaysOf($club, $season, $planId, $entry, $venue->getId()), 'le payload retire les mêmes jours — lundi seul survit');

        // 3) FALSIFICATION : masque {mercredi: OPEN} → les créneaux du mercredi reviennent des
        //    deux côtés ; seul le lundi et le mercredi restent (le samedi n'est plus masqué).
        $override = $this->em->getRepository(VenuePeriodOverride::class)->findOneBy(['schedulePlanId' => $planId, 'venueId' => $venue->getId()]);
        self::assertInstanceOf(VenuePeriodOverride::class, $override);
        $override->setDayOverrides([3 => 'OPEN']);
        $this->em->flush();

        $selection = self::getContainer()->get(PeriodConstraintSelector::class)
            ->selectForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);
        self::assertSame([], array_keys($selection->effectiveClosedWeekdaysByVenue[$venue->getId()] ?? []), 'mercredi rouvert OPEN annule l’incident, plus aucun jour fermé');
        self::assertSame([1, 3, 6], $this->payloadWeekdaysOf($club, $season, $planId, $entry, $venue->getId()), 'les trois créneaux reviennent au payload');
    }

    /**
     * BUG fondateur (2026-08-19, axe constraint semantics §7.1) — le gate == payload sur une
     * INDISPONIBILITÉ datée de gymnase (`venue_closed`). Une telle contrainte croisant un plan
     * d'adaptation faisait rougir le récap « À corriger avant de générer » avec « Une contrainte
     * de gymnase doit désigner un gymnase », BLOQUANT le bouton Générer — faux bloqueur sur
     * CHAQUE fermeture déclarée, alors qu'une fermeture datée ne produit AUCUNE ligne moteur
     * (elle ferme des jours) : le gate ne peut pas bloquer la génération pour elle.
     *
     * Ce test rejoue le cas EXACT via le gate HTTP du plan de période : la fermeture est bien
     * retenue par la sélection (le gymnase n'est pas désactivé), passe sous `validate()`, et NE
     * rend AUCUNE erreur — ni le faux bloqueur historique, ni son propre message de cible.
     */
    public function testDatedVenueClosureDoesNotFalselyBlockThePeriodGate(): void
    {
        [$user, $club, $venue, $entry] = $this->seedClosureGateScenario();

        $this->client->request('POST', '/api/constraints/validate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entry->getId()], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful('la fermeture datée ne fait plus échouer le récap (422)');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $allErrors = implode(' | ', array_merge(...array_values($body['errors'] ?: [[]])));
        self::assertStringNotContainsString('doit désigner un gymnase', $allErrors, 'le faux bloqueur du fondateur a disparu');
        self::assertStringNotContainsString('Une fermeture de gymnase doit désigner', $allErrors, 'le gymnase fermé est bien porté par le scope');
        self::assertTrue($body['valid'], 'aucune erreur ni bloqueur : la génération est débloquée');

        // Le NON-régressé, dans l'autre sens : le gymnase existe toujours pour la génération
        // (la fermeture n'a rien détruit) — la contrainte est bien celle qu'on croit.
        self::assertNotNull($this->em->getRepository(Venue::class)->find($venue->getId()));
    }

    /**
     * P2-59 (axes constraint semantics + planning lifecycle §7.1) — gate ⇄ payload s'accordent
     * sur l'UNION du modèle FAIT/GENÈSE.
     *
     * Une GENÈSE pendue à l'entrée-ENFANT (le plan) ET un FAIT de sa MÈRE composent le jeu de
     * datées d'un plan de reprise. Le sélecteur (gate) retient EXACTEMENT ces deux entités, et le
     * builder (payload) sérialise EXACTEMENT ces deux entités — la parité tient sur l'union, pas
     * sur les seules datées de la mère (ancien modèle).
     *
     * Falsifiable : sous l'ancien modèle, la genèse (sur l'enfant) ne serait pas chargée par le
     * sélecteur — `kept` ne contiendrait que le fait, et l'assertion tombe ROUGE.
     */
    public function testGateAndPayloadAgreeOnTheGenesisMotherUnion(): void
    {
        [$club, $season, $child, $planId, $genesisId, $factId] = $this->seedGenesisUnionScenario();

        $selection = self::getContainer()->get(PeriodConstraintSelector::class)
            ->selectForPeriodPlan($club->getId(), $season->getId(), $planId, $child);
        $keptIds = array_map(static fn (Constraint $c): string => $c->getId(), $selection->kept);
        sort($keptIds);
        $expected = [$genesisId, $factId];
        sort($expected);
        self::assertSame($expected, $keptIds, 'la sélection retient l\'union genèse (enfant) + fait (mère)');

        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)
            ->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $child);
        $payloadRootIds = [];
        foreach ($payload['constraints'] as $row) {
            self::assertIsArray($row);
            $rootId = explode(':', (string) $row['id'])[0];
            if (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $rootId)) {
                $payloadRootIds[$rootId] = true;
            }
        }
        $payloadRootIds = array_keys($payloadRootIds);
        sort($payloadRootIds);
        self::assertSame($expected, $payloadRootIds, 'le payload sérialise EXACTEMENT l\'union que le gate a retenue');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * Un club/saison, une mère (racine holiday) portant un FAIT (CLUB/TIME/HARD), et une
     * semaine-enfant née d'elle portant une GENÈSE (CLUB/TIME/HARD) — chacune avec son plan.
     *
     * @return array{0: Club, 1: Season, 2: CalendarEntry, 3: string, 4: string, 5: string}
     */
    private function seedGenesisUnionScenario(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club genèse ' . $uid);
        $club->setSlug('genese-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('GEN' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        // Une équipe (via sport/catégorie/rang) : une contrainte CLUB TIME s'expanse PAR ÉQUIPE
        // dans le payload — sans équipe, elle ne produirait aucune ligne et la parité serait creuse.
        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('genese-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U11');
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $this->em->flush();

        $this->team($club, $season, $category, 'Équipe A');

        // Mère (racine holiday) : porte le FAIT.
        $mother = new CalendarEntry;
        $mother->setClubId($club->getId());
        $mother->setSeasonId($season->getId());
        $mother->setKind(CalendarEntryKind::PERIOD);
        $mother->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $mother->setTitle('Vacances');
        $mother->setStartDate(new DateTimeImmutable('2026-05-04'));
        $mother->setEndDate(new DateTimeImmutable('2026-05-24'));
        $mother->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($mother);
        $this->em->flush();

        // Semaine-enfant : porte la GENÈSE, née de la mère.
        $child = new CalendarEntry;
        $child->setClubId($club->getId());
        $child->setSeasonId($season->getId());
        $child->setKind(CalendarEntryKind::PERIOD);
        $child->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $child->setTitle('Semaine 1');
        $child->setStartDate(new DateTimeImmutable('2026-05-04'));
        $child->setEndDate(new DateTimeImmutable('2026-05-10'));
        $child->setParentEntryId($mother->getId());
        $child->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($child);
        $this->em->flush();

        $planId = $this->planIdOf($child); // le plan naît du geste

        $genesis = $this->constraint($club, $season, ConstraintScope::CLUB, null, ConstraintFamily::TIME, ['minStartTime' => '20:00'], $child->getId());
        $fact = $this->constraint($club, $season, ConstraintScope::CLUB, null, ConstraintFamily::TIME, ['minStartTime' => '18:00'], $mother->getId());
        $this->em->flush();

        return [$club, $season, $child, $planId, $genesis->getId(), $fact->getId()];
    }

    /**
     * Les jours ISO des créneaux d'un gymnase dans le payload de période, triés.
     *
     * @return list<int>
     */
    private function payloadWeekdaysOf(Club $club, Season $season, string $planId, CalendarEntry $entry, string $venueId): array
    {
        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)
            ->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);
        $days = [];
        foreach ($payload['venues'] as $venue) {
            if ($venue['id'] === $venueId) {
                $days = array_map(static fn (array $slot): int => $slot['dayOfWeek'], $venue['trainingSlots']);
            }
        }
        sort($days);

        return $days;
    }

    /**
     * Un club/saison/gymnase avec une grille de saison lun(1)/mer(3)/sam(6), une période
     * semaine-pleine (son plan copie la grille à sa naissance), et un masque de période.
     *
     * @param array<int, string> $dayOverrides
     *
     * @return array{0: Club, 1: Season, 2: Venue, 3: CalendarEntry, 4: string}
     */
    private function seedDayGrainScenario(array $dayOverrides): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club jour ' . $uid);
        $club->setSlug('jour-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('JOU' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        $venue = $this->venue($club, $season, 'Gymnase jour');
        foreach ([1, 3, 6] as $day) {
            $slot = new VenueTrainingSlot;
            $slot->setClubId($club->getId());
            $slot->setSeasonId($season->getId());
            $slot->setVenueId($venue->getId());
            $slot->setDayOfWeek($day);
            $slot->setStartTime(new DateTimeImmutable('18:00'));
            $slot->setDurationMinutes(90);
            $slot->setCapacity(1);
            $this->em->persist($slot);
        }
        $this->em->flush();

        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Semaine jour');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        $planId = $this->planIdOf($entry); // copie la grille de saison sur le plan

        $override = new VenuePeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setVenueId($venue->getId());
        $override->setDayOverrides($dayOverrides);
        $this->em->persist($override);
        $this->em->flush();

        return [$club, $season, $venue, $entry, $planId];
    }

    /**
     * Le cas fondateur, minimal : un club + son gestionnaire admin, une saison, un gymnase avec
     * grille, une période d'adaptation (donc un plan), et UNE fermeture datée du gymnase qui
     * chevauche la période. Le gymnase n'est PAS désactivé pour la période : la fermeture reste
     * retenue par la sélection et passe donc sous le gate.
     *
     * @return array{0: User, 1: Club, 2: Venue, 3: CalendarEntry}
     */
    private function seedClosureGateScenario(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club fermeture ' . $uid);
        $club->setSlug('fermeture-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('FER' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('fermeture-' . $uid . '@test.com');
        $user->setFirstName('Fer');
        $user->setLastName('Meture');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        $venue = $this->venue($club, $season, 'Gymnase Matéo');
        foreach ([1, 3] as $day) {
            $slot = new VenueTrainingSlot;
            $slot->setClubId($club->getId());
            $slot->setSeasonId($season->getId());
            $slot->setVenueId($venue->getId());
            $slot->setDayOfWeek($day);
            $slot->setStartTime(new DateTimeImmutable('18:00'));
            $slot->setDurationMinutes(90);
            $slot->setCapacity(1);
            $this->em->persist($slot);
        }
        $this->em->flush();

        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Adaptation travaux');
        $entry->setStartDate(new DateTimeImmutable('2026-08-31'));
        $entry->setEndDate(new DateTimeImmutable('2026-09-06'));
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        $this->planIdOf($entry); // le plan naît du geste (copie la grille)

        // « Matéo indisponible (travaux) » 2026-08-18 → 2026-09-30 : chevauche la période.
        $this->closure($club, $season, $entry, $venue->getId(), '2026-08-18', '2026-09-30');
        $this->em->flush();

        return [$user, $club, $venue, $entry];
    }

    private function closure(Club $club, Season $season, CalendarEntry $carrier, string $venueId, string $start, string $end): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($club->getId());
        $constraint->setSeasonId($season->getId());
        $constraint->setName('Gym en travaux');
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId($venueId);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setCalendarEntryId($carrier->getId());
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end]);
        $this->em->persist($constraint);
    }

    /**
     * Une scène minimale à tags MANUELS (noms neutres G1/G2/EXCL) : l'intersection et
     * l'exclusion se prouvent sans dépendre de la dérivation d'âge/niveau. Équipe A porte
     * G1+G2 ; B porte G1 seul ; C porte G1+G2+EXCL.
     *
     * @return array{0: Club, 1: Season, 2: CalendarEntry, 3: string, 4: string, 5: string}
     */
    private function seedMultiTargetScenario(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club multi ' . $uid);
        $club->setSlug('multi-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('MUL' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('multi-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U11');
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $this->em->flush();

        $teamA = $this->team($club, $season, $category, 'Équipe A');
        $teamB = $this->team($club, $season, $category, 'Équipe B');
        $teamC = $this->team($club, $season, $category, 'Équipe C');

        // Tags manuels et leurs assignations. Les tags dérivés du listener existent aussi
        // (U11…) mais on ne les vise pas : la résolution ne touche que G1/G2/EXCL.
        $assign = function (Team $team, TeamTag $tag) use ($club, $season): void {
            $a = new TeamTagAssignment;
            $a->setClubId($club->getId());
            $a->setTeamId($team->getId());
            $a->setTagId($tag->getId());
            $a->setSeasonId($season->getId());
            $this->em->persist($a);
        };
        $tags = [];
        foreach (['G1', 'G2', 'EXCL'] as $name) {
            $tag = new TeamTag;
            $tag->setClubId($club->getId());
            $tag->setName($name);
            $this->em->persist($tag);
            $tags[$name] = $tag;
        }
        $this->em->flush();
        $assign($teamA, $tags['G1']);
        $assign($teamA, $tags['G2']);
        $assign($teamB, $tags['G1']);
        $assign($teamC, $tags['G1']);
        $assign($teamC, $tags['G2']);
        $assign($teamC, $tags['EXCL']);

        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Reprise multi');
        $entry->setStartDate(new DateTimeImmutable('2025-10-20'));
        $entry->setEndDate(new DateTimeImmutable('2025-10-26'));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        $planId = $this->planIdOf($entry);

        // (G1 ∩ G2) − EXCL = A seule (B manque G2, C est exclue).
        $constraint = $this->constraint(
            $club,
            $season,
            ConstraintScope::CLUB,
            null,
            ConstraintFamily::DAY,
            ['targetTags' => ['G1', 'G2'], 'excludeTags' => ['EXCL'], 'forbiddenDays' => [3]],
            null,
            ConstraintRuleType::PREFERRED,
        );
        $this->em->flush();

        return [$club, $season, $entry, $planId, $constraint->getId(), $teamA->getId()];
    }

    /**
     * @return array{0: User, 1: Club, 2: Season, 3: CalendarEntry, 4: string, 5: array<string, string>}
     */
    private function seedScenario(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club parité ' . $uid);
        $club->setSlug('parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('PAR' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('parity-' . $uid . '@test.com');
        $user->setFirstName('Pa');
        $user->setLastName('Rité');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('parity-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U11');
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $this->em->flush();

        $teamActive = $this->team($club, $season, $category, 'Équipe active');
        $teamPaused = $this->team($club, $season, $category, 'Équipe en pause');

        $venueOpen = $this->venue($club, $season, 'Gymnase ouvert');
        $venueDisabled = $this->venue($club, $season, 'Gymnase fermé pour période');

        // Le tag et son assignation : SEULE l'équipe en pause est taguée — le cas exact de
        // la divergence n° 2 (CLUB+tag HARD à gymnase dédié, toutes taguées en pause).
        $tag = new TeamTag;
        $tag->setClubId($club->getId());
        $tag->setName('PARITE');
        $this->em->persist($tag);
        $this->em->flush();
        $assignment = new TeamTagAssignment;
        // BCK-11 : la table est tenant + RLS — une ligne sans club_id est refusée
        // par PostgreSQL, plus seulement invisible.
        $assignment->setClubId($club->getId());
        $assignment->setTeamId($teamPaused->getId());
        $assignment->setTagId($tag->getId());
        $assignment->setSeasonId($season->getId());
        $this->em->persist($assignment);

        // Second tag : il couvre TOUTES les équipes actives (la seule active du club).
        // Le cas revue #340 round 2 : sans équipe active HORS du tag, les lignes
        // « interdit hors tag » n'existent pas — l'entité ne doit PAS être gardée.
        $allTag = new TeamTag;
        $allTag->setClubId($club->getId());
        $allTag->setName('TOUTES');
        $this->em->persist($allTag);
        $this->em->flush();
        $allAssignment = new TeamTagAssignment;
        $allAssignment->setClubId($club->getId());
        $allAssignment->setTeamId($teamActive->getId());
        $allAssignment->setTagId($allTag->getId());
        $allAssignment->setSeasonId($season->getId());
        $this->em->persist($allAssignment);

        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Reprise parité');
        $entry->setStartDate(new DateTimeImmutable('2025-10-20'));
        $entry->setEndDate(new DateTimeImmutable('2025-10-26'));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        $planId = $this->planIdOf($entry);

        // Réglages de période : l'équipe en pause, le gymnase désactivé.
        $teamOverride = new TeamPeriodOverride;
        $teamOverride->setClubId($club->getId());
        $teamOverride->setSeasonId($season->getId());
        $teamOverride->setSchedulePlanId($planId);
        $teamOverride->setTeamId($teamPaused->getId());
        $teamOverride->setIsActive(false);
        $this->em->persist($teamOverride);

        $venueOverride = new VenuePeriodOverride;
        $venueOverride->setClubId($club->getId());
        $venueOverride->setSeasonId($season->getId());
        $venueOverride->setSchedulePlanId($planId);
        $venueOverride->setVenueId($venueDisabled->getId());
        $venueOverride->setMode(VenuePeriodMode::DISABLED);
        $this->em->persist($venueOverride);
        $this->em->flush();

        $ids = [
            // Gardée : TEAM valide sur l'équipe active.
            'teamOk' => $this->constraint($club, $season, ConstraintScope::TEAM, $teamActive->getId(), ConstraintFamily::TIME, ['maxStartTime' => '20:00'], null)->getId(),
            // Sortie SANS bruit : TEAM sur l'équipe en pause.
            'teamDeactivated' => $this->constraint($club, $season, ConstraintScope::TEAM, $teamPaused->getId(), ConstraintFamily::TIME, ['maxStartTime' => '20:00'], null)->getId(),
            // Sortie AVEC warning : elle nomme le gymnase désactivé (clé de config).
            'prefDisabledVenue' => $this->constraint($club, $season, ConstraintScope::TEAM, $teamActive->getId(), ConstraintFamily::FACILITY, ['preferredVenueId' => $venueDisabled->getId()], null)->getId(),
            // Sortie par le DÉFAUT reprise (FACILITY droppée sans override).
            // GARDÉE malgré « toutes taguées en pause » : HARD + gymnase dédié émet encore
            // ses lignes « interdit hors tag » (divergence n° 2 alignée).
            // ⚠ SEC-13 : ces deux-là portaient leurs clés de gymnase sur une famille
            // TIME. Le sélecteur de période les lit sans regarder la famille
            // (`PeriodConstraintSelector:238`), mais le MOTEUR exige
            // `family == "FACILITY"` : le mélange était inerte côté solveur, absent
            // de la vraie donnée (0 ligne), et la liste blanche le refuse désormais.
            // Famille corrigée ; ce que le test garde — la ligne dont la clé
            // secondaire vise un gymnase désactivé — est inchangé.
            // … y compris quand une clé SECONDAIRE vise le gymnase désactivé (revue #340
            // round 1) : les lignes « interdit hors tag » remplacent la config par le seul
            // gymnase DÉDIÉ — un drop entité aveugle les effaçait, le post-filtre par ligne
            // les préservait.
            'tagHardVenue' => $this->constraint($club, $season, ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ['targetTag' => 'PARITE', 'forcedVenueId' => $venueOpen->getId(), 'preferredVenueId' => $venueDisabled->getId()], null)->getId(),
            // Gardée : datée valide de l'équipe active.
            'datedOk' => $this->constraint($club, $season, ConstraintScope::TEAM, $teamActive->getId(), ConstraintFamily::TIME, ['maxStartTime' => '21:00'], $entry->getId())->getId(),
            // Sortie AVEC warning gymnase (revue #340 round 2) : tag couvrant TOUTES les
            // actives (aucune ligne « interdit hors tag » possible) ET clé secondaire sur le
            // gymnase désactivé (les lignes par équipe meurent) → ZÉRO ligne, drop annoncé.
            'tagAllActive' => $this->constraint($club, $season, ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ['targetTag' => 'TOUTES', 'forcedVenueId' => $venueOpen->getId(), 'minAtVenueId' => $venueDisabled->getId()], null)->getId(),
            // Sortie AVEC warning (revue #340 round 1) : DATÉE CLUB+tag dont le tag ne vise
            // plus aucune équipe active, sans gymnase dédié (PREFERRED) — un geste explicite
            // du gestionnaire pour la période ne s'évapore jamais en silence (#8).
            'datedInertTag' => $this->constraint($club, $season, ConstraintScope::CLUB, null, ConstraintFamily::TIME, ['targetTag' => 'PARITE', 'maxStartTime' => '18:00'], $entry->getId(), ConstraintRuleType::PREFERRED)->getId(),
            // Sortie (divergence n° 1 alignée) : datée de l'équipe EN PAUSE, config invalide
            // À DESSEIN (famille TIME sans clé) — si le gate la validait encore, son erreur
            // ferait échouer le récap sur une règle que le solveur ne verra jamais.
            'datedDeactivated' => $this->constraint($club, $season, ConstraintScope::TEAM, $teamPaused->getId(), ConstraintFamily::TIME, [], $entry->getId())->getId(),
        ];
        $this->em->flush();

        return [$user, $club, $season, $entry, $planId, $ids];
    }

    private function team(Club $club, Season $season, SportCategory $category, string $name): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(1);
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function constraint(Club $club, Season $season, ConstraintScope $scope, ?string $target, ConstraintFamily $family, array $config, ?string $calendarEntryId, ConstraintRuleType $ruleType = ConstraintRuleType::HARD): Constraint
    {
        $constraint = new Constraint;
        $constraint->setClubId($club->getId());
        $constraint->setSeasonId($season->getId());
        $constraint->setScope($scope);
        $constraint->setScopeTargetId($target);
        $constraint->setFamily($family);
        $constraint->setRuleType($ruleType);
        $constraint->setName('Parité ' . $family->value . ' ' . uniqid());
        $constraint->setConfig($config);
        $constraint->setIsActive(true);
        $constraint->setSortOrder(0);
        $constraint->setCalendarEntryId($calendarEntryId);
        $this->em->persist($constraint);

        return $constraint;
    }
}
