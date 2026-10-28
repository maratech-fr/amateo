<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Clock\DevClockStore;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SchoolHolidayPeriod;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Integration\Api\SchedulePlanStructureHashTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR BLOQUANT — « prolonger une fermeture à travers les vacances, avec reprise du planning »
 * (décision fondateur 2026-10-27, axe §7.1 *planning lifecycle*). Amende ADR-0002 inv. 2
 * (« jamais une copie » de D3 v2 est désormais borné : l'après-vacances EST une copie validée).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * CONTRAT D'API ATTENDU (pour NEO — rail NEUF, ancré au PLAN de fermeture, DayDialog/D3 v2 intacts)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  GET  /api/schedule_plans/{id}  (SchedulePlanResource) sert, pour un plan CLOSURE, EN PLUS :
 *      - windowEditable     : bool    — la fenêtre de l'incident est modifiable ;
 *      - windowLockedReason : ?string — pourquoi non (ex. génération en vol), null quand éditable ;
 *      - minEndDate         : string (Y-m-d) — PLANCHER « le passé ne bouge pas », calculé SERVEUR
 *                             via l'horloge (jamais recalculé par le front) ;
 *      - closureTitle       : string — identité de la FERMETURE (titre de la RACINE), partagée par
 *                             TOUS les plans-segments d'un même incident (ils ouvrent la même fenêtre).
 *
 *  POST /api/schedule_plans/{id}/window-preview   body {startDate, endDate}  (LECTURE, zéro effet)
 *      → 200 {
 *          token              : string,
 *          summary            : string,   // phrase FR courte servie (« 4 plannings, 1 pause… »)
 *          futurePlansWarning : bool,     // des plans à startDate > today seraient balayés par
 *                                         // valider/rouvrir le socle (inv. 14)
 *          effects            : list<{kind, startDate, endDate, label}>  // ORDRE CHRONOLOGIQUE
 *        }
 *      kinds : unchanged | convert | birth_copy | birth_empty | shift | vanish | holiday_pause |
 *              holiday_takes_over  (NEO peut renommer : le test nomme ceux qu'il assère).
 *
 *  POST /api/schedule_plans/{id}/window            body {startDate, endDate, previewToken}  (APPLY)
 *      → 200 ; jeton RECALCULÉ sous `lockClubWindows` puis `lockPlanScope` → 409 si périmé/forgé ;
 *      jeton ABSENT → 422 « demandez l'aperçu ».
 *      L'APERÇU == L'APPLIQUÉ (mêmes fenêtres, mêmes verdicts) — invariant central.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * PROMESSES VERROUILLÉES (chaque garde falsifiée dans les deux sens)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  • cas « BCCL » de bout en bout : une racine-bloc CLOSURE validée + une mère HOLIDAY à 2 reprises
 *    NON validées au milieu → prolonger par-delà les vacances donne 4 plans de fermeture POINTÉS :
 *      - le MILIEU ré-ancré = MÊME version que l'origine (convert), slots intacts ;
 *      - la fin entamée AVANT vacances = copie VALIDÉE ;
 *      - le run d'APRÈS-vacances = copie VALIDÉE ;
 *      - la fin entamée FINALE = copie VALIDÉE ;
 *    slots des copies == version en vigueur du milieu source ; verrous HARD à ORIGINE VRAIE ;
 *    contrainte datée `venue_closed` étendue à TOUTE la fenêtre (début figé = plancher) ;
 *    reprises de vacances non validées qui SURVIVENT et restent travaillables (« à régénérer » =
 *    jamais validées, le gestionnaire les régénère) ; aperçu == appliqué.
 *  • règle VENDREDI→SAMEDI (fondateur 2026-10-27) : le feed scolaire démarre le VENDREDI de fin des
 *    cours, mais un jour lun→ven d'une semaine qui n'est PAS DE vacances ({@see HolidayWorkweekRule})
 *    reste à la FERMETURE → la pause ne commence que le SAMEDI, et une semaine dont chaque jour est
 *    fermeture OU vacances compte PLEINE (plus de « fin » isolée qui trouait avec la fenêtre brute) ;
 *  • raccourcir : même geste, verdicts annoncés == appliqués, le passé ne bouge pas ;
 *  • plancher : une fenêtre déjà commencée a son début FIGÉ (minEndDate servi, apply sous le plancher refusé) ;
 *  • copie figée : modifier la SOURCE après coup ne redescend pas dans les copies ;
 *  • inv. 14 : valider/rouvrir le socle balaie les segments FUTURS nés du geste, l'entrée SURVIT ;
 *  • abus : A1 club B → 404 byte-identique à un plan inconnu ; A2 apply sans jeton 422 / jeton périmé 409 /
 *    jeton forgé 409 ; A3 non-gestionnaire 403 ; A4 saison archivée 409.
 *
 * ⚠ FENÊTRES TOUJOURS RELATIVES À L'HORLOGE : l'horloge du test est PINNÉE (DevClockStore) sur un
 *    LUNDI connu ; toute date de fenêtre se dérive en semaines depuis ce « today » (`mon(n)`), jamais
 *    de date calendaire en dur. Les vacances sont posées PAR LE TEST lui-même.
 *
 * ⚠ Une reprise de vacances n'est PAS signalée « à régénérer » par divergence d'empreinte : une période
 * HOLIDAY droppe par défaut les contraintes FACILITY ({@see PeriodConstraintSelector}), donc la datée
 * `venue_closed` étendue n'entre jamais dans son payload et son empreinte ne bouge pas. Le signal
 * opposable est « reste NON validée » (miroir du Behat {@see ClosureStraddleContext}). La mécanique
 * d'empreinte « à régénérer » (empreinte servie ⇄ snapshot figé) vit dans {@see SchedulePlanStructureHashTest}.
 */
#[Group('phase1')]
#[Group('integration')]
final class ClosureWindowExtendTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    /** Un vrai gymnase pour les séances copiées et la fermeture datée. */
    private const string VENUE_ID = '77777777-7777-4777-8777-777777777777';

    private const string TEAM_ID = '88888888-8888-4888-8888-888888888888';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private DateTimeImmutable $today;

    /**
     * ① Bout en bout : racine-bloc validée + vacances à 2 reprises non validées → prolonger par-delà
     * les vacances = 4 plans pointés (1 convert + 3 copies validées), datée étendue, reprises
     * divergentes, aperçu == appliqué.
     */
    public function testExtendingAClosureAcrossHolidaysBirthsFourPointedPlans(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();

        // Racine-bloc CLOSURE alignée (lun→dim, 4 semaines pleines = un seul segment adaptable « d'un
        // bloc ») déjà COMMENCÉE : [mon(-2), sun(+1)], today = mon(0) à l'intérieur.
        $rootId = $this->postPeriod($user, 'Barros en travaux', $this->mon(-2), $this->sun(1));
        $srcPlanId = $this->adaptPeriod($user, $rootId);
        $srcVersionId = $this->seedPointedVersionWithLockedSlots($club, $season, $srcPlanId);
        $srcSlotCount = $this->slotCount($club, $srcVersionId);
        // La datée `venue_closed` née du même geste (config == fenêtre de la racine).
        $datedId = $this->seedDatedClosure($club, $season, $rootId, $this->mon(-2), $this->sun(1));

        // Mère HOLIDAY au MILIEU (disjointe : fri(+2)→sun(+4)) + 2 reprises hebdo NON validées (chacune
        // porte une version COMPLETED mais AUCUN pointeur — « non validée »).
        $vacId = $this->postHoliday($user, 'Toussaint', $this->fri(2), $this->sun(4));
        $r1 = $this->postWeekChild($user, $vacId, 'holiday', $this->mon(3), $this->sun(3));
        $r2 = $this->postWeekChild($user, $vacId, 'holiday', $this->mon(4), $this->sun(4));
        $r1Plan = $this->planOf($club, $r1);
        $r2Plan = $this->planOf($club, $r2);
        $this->seedUnchosenCompletedVersion($club, $season, $r1Plan);
        $this->seedUnchosenCompletedVersion($club, $season, $r2Plan);

        // ── APERÇU : prolonger la fin jusqu'au vendredi fri(+6) (le début reste figé par le plancher).
        $preview = $this->preview($user, $srcPlanId, $this->mon(-2), $this->fri(6));
        self::assertNotSame('', $preview['summary'], 'un résumé FR est servi');
        self::assertTrue($preview['futurePlansWarning'], 'les 3 segments futurs seraient balayés par valider/rouvrir le socle');

        $byKind = $this->effectsByKind($preview['effects']);
        self::assertCount(1, $byKind['convert'] ?? [], 'le milieu est RÉ-ANCRÉ (convert), pas recopié');
        self::assertCount(3, $byKind['birth_copy'] ?? [], 'trois segments naissent en COPIE validée (fin avant, après-vacances, fin finale)');
        self::assertNotEmpty($byKind['holiday_pause'] ?? $byKind['holiday_takes_over'] ?? [], 'les vacances font une pause dans la fermeture');

        // Les fenêtres annoncées, pour l'invariant aperçu == appliqué (ordre chronologique).
        $convertWindow = $byKind['convert'][0];
        self::assertSame([$this->mon(-2), $this->sun(1)], [$convertWindow['startDate'], $convertWindow['endDate']], 'le convert garde la fenêtre du milieu');

        // ── APPLY avec le jeton de l'aperçu.
        $this->applyWindow($user, $srcPlanId, $this->mon(-2), $this->fri(6), $preview['token']);

        // (1) MILIEU : le plan SOURCE survit, ré-ancré sur l'enfant milieu, MÊME version, slots intacts.
        $milieuChild = $this->childWithWindow($club, $rootId, $this->mon(-2), $this->sun(1));
        self::assertNotNull($milieuChild, 'un enfant milieu existe sur la fenêtre d’origine');
        self::assertSame($srcPlanId, $this->planOf($club, $milieuChild), 'le plan validé est RÉ-ANCRÉ, jamais recréé');
        self::assertSame($srcVersionId, $this->chosenScheduleId($club, $srcPlanId), 'le milieu pointe la MÊME version qu’avant');
        self::assertSame($srcSlotCount, $this->slotCount($club, $srcVersionId), 'les slots du milieu sont intacts');

        // (2)(3)(4) COPIES : fin avant vacances, après-vacances, fin finale — chacune POINTÉE sur une
        // version COMPLETED, slots == source, verrous à origine VRAIE (jamais UNKNOWN).
        //
        // ⚠ RÈGLE vendredi→samedi (fondateur 2026-10-27) : les vacances commencent le VENDREDI fri(2)
        // (entrée + feed), mais la semaine mon(2)→dim n'est PAS une semaine DE vacances au sens de
        // {@see HolidayWorkweekRule::covers} (lun→jeu hors vacances) → ses jours lun→ven restent à la
        // FERMETURE et la pause ne commence que le SAMEDI. La première copie court donc jusqu'au
        // VENDREDI fri(2) (et non jeu. thu(2), l'ancien découpage qui troue avec la fenêtre brute).
        foreach ([[$this->mon(2), $this->fri(2)], [$this->mon(5), $this->sun(5)], [$this->mon(6), $this->fri(6)]] as $win) {
            $child = $this->childWithWindow($club, $rootId, $win[0], $win[1]);
            self::assertNotNull($child, \sprintf('un segment-copie existe sur [%s, %s]', $win[0], $win[1]));
            $planId = $this->planOf($club, $child);
            self::assertNotNull($planId);
            $chosen = $this->chosenScheduleId($club, $planId);
            self::assertNotNull($chosen, 'la copie est VALIDÉE d’office (pointée)');
            self::assertSame(ScheduleStatus::COMPLETED->value, $this->scheduleStatus($club, $chosen), 'la copie est COMPLETED sans solve');
            self::assertSame($srcSlotCount, $this->slotCount($club, $chosen), 'la copie reprend les slots de la version source');
            self::assertSame([LockOrigin::MANUAL->value], $this->distinctLockOrigins($club, $chosen), 'les verrous HARD gardent leur ORIGINE vraie');
        }

        // (5) La datée `venue_closed` est ÉTENDUE à toute la fenêtre — début FIGÉ (plancher).
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $dated = $this->em->getRepository(Constraint::class)->find($datedId);
        self::assertInstanceOf(Constraint::class, $dated);
        self::assertSame($this->mon(-2), $dated->getConfig()['startDate'], 'le début de la fermeture datée ne bouge pas (le passé est figé)');
        self::assertSame($this->fri(6), $dated->getConfig()['endDate'], 'la fermeture datée court jusqu’au nouveau bout de fenêtre');

        // (6) REPRISES de vacances : la fermeture PAUSE pendant les vacances (les segments-copies
        // sautent la pause), donc les reprises SURVIVENT et restent NON pointées — « à régénérer » au
        // sens du gestionnaire, qui ne les a jamais validées et les (re)générera. C'est le signal
        // opposable, miroir exact du Behat {@see ClosureStraddleContext} (chosen null).
        //
        // ⚠ PAS de divergence d'EMPREINTE ici : une période HOLIDAY DROPPE par défaut les contraintes
        // FACILITY ({@see PeriodConstraintSelector}, « les FACILITY étaient déjà droppées hors
        // fermeture »), donc la datée `venue_closed` étendue n'entre JAMAIS dans le payload d'une
        // reprise de vacances — son empreinte ne peut pas bouger. La mécanique d'empreinte « à
        // régénérer » vit, elle, dans {@see SchedulePlanStructureHashTest} (empreinte servie ⇄ snapshot).
        self::assertNull($this->chosenScheduleId($club, $r1Plan), 'la reprise 1 reste non validée (à régénérer)');
        self::assertNull($this->chosenScheduleId($club, $r2Plan), 'la reprise 2 reste non validée (à régénérer)');
        // Les reprises SURVIVENT et restent travaillables : le plan sert encore son empreinte courante
        // (non nulle). Cette assertion s'appuie sur la bonne clé `currentStructureHash` — l'ancien
        // helper lisait `structureHash`/`hash` et rendait toujours null (vacuité).
        self::assertNotNull($this->servedStructureHash($user, $r1Plan), 'la reprise 1 survit et reste travaillable (empreinte courante servie)');
        self::assertNotNull($this->servedStructureHash($user, $r2Plan), 'la reprise 2 survit et reste travaillable (empreinte courante servie)');
    }

    /**
     * ⑥ PAUSE DÉDUPLIQUÉE ET NOMMÉE — correctif captures réelles (fondateur 2026-10-27).
     *
     * Une même Toussaint est décrite par TROIS sources que {@see ClosureSegmentation::holidayWindowsForSeason}
     * rend toutes : l'entrée HOLIDAY matérialisée, le feed scolaire de la zone (mêmes dates) et les
     * deux semaines filles. Le défaut constaté : une ligne `holiday_pause` PAR fenêtre → la Toussaint
     * listée 4 fois, bornes anonymes. La promesse protégée :
     *  1. EXACTEMENT UNE ligne de pause par période de vacances (fenêtres recoupées/jointives fusionnées) ;
     *  2. le libellé NOMME les vacances et ses bornes sont clampées à la fenêtre (union [fri(2), sun(4)]) ;
     *  3. des plans de vacances recoupent la pause (les deux reprises) → « vos plannings de vacances
     *     restent tels quels. », jamais l'ancienne promesse « votre planning de reprise reste tel quel » ;
     *  4. la fermeture ne COUVRE jamais les vacances — aucun segment convert/copie ne chevauche la pause.
     *
     * NB : le RENDU EXACT des bornes dans le libellé (format compact « (16 → 31 oct.) ») est laissé à
     * l'implémentation — il dépend de dates calendaires, alors que l'horloge du test est pinnée en
     * janvier. On FIGE ici les parts invariantes du format fondateur (« Pause — », le NOM des vacances,
     * la phrase finale) et les bornes via les champs STRUCTURÉS `startDate`/`endDate`.
     */
    public function testHolidayPauseIsOneNamedLineEvenFromEntryFeedAndChildren(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $this->setClubSchoolZone($club, 'A');
        // Le feed scolaire NOMME la vacance ; l'entrée HOLIDAY + ses semaines filles couvrent LA MÊME
        // Toussaint → 4 fenêtres pour une seule période de vacances (le défaut : 4 lignes de pause).
        $this->seedSchoolHoliday('A', 'Vacances de la Toussaint', $this->fri(2), $this->sun(4));

        $rootId = $this->postPeriod($user, 'Barros en travaux', $this->mon(-2), $this->sun(1));
        $srcPlanId = $this->adaptPeriod($user, $rootId);
        $this->seedPointedVersionWithLockedSlots($club, $season, $srcPlanId);
        $this->seedDatedClosure($club, $season, $rootId, $this->mon(-2), $this->sun(1));

        // Entrée HOLIDAY matérialisée + deux reprises de vacances (plans de vacances) recoupant la pause.
        $vacId = $this->postHoliday($user, 'Toussaint', $this->fri(2), $this->sun(4));
        $r1 = $this->postWeekChild($user, $vacId, 'holiday', $this->mon(3), $this->sun(3));
        $r2 = $this->postWeekChild($user, $vacId, 'holiday', $this->mon(4), $this->sun(4));
        $this->seedUnchosenCompletedVersion($club, $season, $this->planOf($club, $r1));
        $this->seedUnchosenCompletedVersion($club, $season, $this->planOf($club, $r2));

        $preview = $this->preview($user, $srcPlanId, $this->mon(-2), $this->fri(6));
        $byKind = $this->effectsByKind($preview['effects']);
        $pauses = $byKind['holiday_pause'] ?? $byKind['holiday_takes_over'] ?? [];

        // (1) UNE seule ligne, malgré entrée HOLIDAY + feed scolaire + deux semaines filles.
        self::assertCount(1, $pauses, 'une période de vacances recoupée = exactement une ligne de pause');
        $pause = $pauses[0];

        // (2) Nommée + bornes fusionnées et clampées à la fenêtre. La vacance DÉMARRE le VENDREDI
        // fri(2) (feed + entrée), mais la semaine mon(2)→dim n'est pas une semaine DE vacances
        // ({@see HolidayWorkweekRule}) : ses jours lun→ven restent à la FERMETURE, donc la PAUSE ne
        // commence que le SAMEDI sat(2) (règle vendredi→samedi, fondateur 2026-10-27).
        self::assertStringStartsWith('Pause — Vacances de la Toussaint', $pause['label'], 'la pause nomme les vacances');
        self::assertSame([$this->sat(2), $this->sun(4)], [$pause['startDate'], $pause['endDate']], 'la pause commence le samedi (lun→ven restent à la fermeture) et se clampe à la fenêtre');

        // (3) Des plans de vacances recoupent la pause → on ne touche pas à leurs plannings.
        self::assertStringContainsString('vos plannings de vacances restent tels quels.', $pause['label']);
        self::assertStringNotContainsString('votre planning de reprise reste tel quel', $pause['label'], 'jamais la promesse d’un planning de reprise qui n’est pas garanti');

        // (4) La fermeture ne couvre JAMAIS les vacances : aucun segment ne chevauche la pause.
        foreach (array_merge($byKind['convert'] ?? [], $byKind['birth_copy'] ?? [], $byKind['birth_empty'] ?? []) as $run) {
            self::assertFalse(
                $run['startDate'] <= $pause['endDate'] && $run['endDate'] >= $pause['startDate'],
                \sprintf('un planning de fermeture [%s, %s] chevauche les vacances', $run['startDate'], $run['endDate']),
            );
        }
    }

    /**
     * ⑦ PAUSE SANS PLAN DE VACANCES — jamais la promesse d'un planning qui n'existe pas (fondateur 2026-10-27).
     *
     * Une vacance présente UNIQUEMENT dans le feed scolaire (aucune entrée HOLIDAY, aucun plan de
     * vacances ne la recoupe). Promesse : la pause est nommée par le feed et annonce « les vacances
     * gardent leur propre fonctionnement. » — jamais « restent tels quels » (il n'y a pas de planning)
     * ni l'ancien « votre planning de reprise reste tel quel » (faux dès qu'aucune reprise ne couvre).
     */
    public function testHolidayPauseWithoutAnyVacationPlanMakesNoPromiseOfAPlanning(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $this->setClubSchoolZone($club, 'B');
        // Une vacance du feed scolaire seulement : aucun plan de vacances ne la recoupe.
        $this->seedSchoolHoliday('B', 'Vacances de printemps', $this->fri(2), $this->sun(4));

        $rootId = $this->postPeriod($user, 'Barros en travaux', $this->mon(-2), $this->sun(1));
        $planId = $this->adaptPeriod($user, $rootId);
        $this->seedPointedVersionWithLockedSlots($club, $season, $planId);
        $this->seedDatedClosure($club, $season, $rootId, $this->mon(-2), $this->sun(1));

        $preview = $this->preview($user, $planId, $this->mon(-2), $this->fri(6));
        $byKind = $this->effectsByKind($preview['effects']);
        $pauses = $byKind['holiday_pause'] ?? $byKind['holiday_takes_over'] ?? [];

        self::assertCount(1, $pauses, 'une vacance du feed recoupée = exactement une ligne de pause');
        $pause = $pauses[0];

        self::assertStringStartsWith('Pause — Vacances de printemps', $pause['label'], 'la pause nomme les vacances du feed');
        // Feed démarrant le VENDREDI fri(2) → semaine mon(2) non DE vacances ({@see HolidayWorkweekRule})
        // → lun→ven restent à la fermeture, la pause commence le SAMEDI sat(2) (fondateur 2026-10-27).
        self::assertSame([$this->sat(2), $this->sun(4)], [$pause['startDate'], $pause['endDate']], 'la pause du feed commence le samedi, clampée à la fenêtre');

        // Aucun plan de vacances ne recoupe la pause → on ne promet AUCUN planning.
        self::assertStringContainsString('les vacances gardent leur propre fonctionnement.', $pause['label']);
        self::assertStringNotContainsString('restent tels quels', $pause['label'], 'sans plan de vacances, aucune promesse de planning conservé');
        self::assertStringNotContainsString('votre planning de reprise reste tel quel', $pause['label'], 'sans reprise, jamais « votre planning de reprise reste tel quel »');
    }

    /** ② Raccourcir : même geste, le passé ne bouge pas, verdicts annoncés == appliqués. */
    public function testShrinkingKeepsThePastAndAppliesTheAnnouncedVerdicts(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        // [mon(-2), sun(+3)] = 6 semaines pleines, un seul segment, déjà commencé.
        $rootId = $this->postPeriod($user, 'Barros en travaux', $this->mon(-2), $this->sun(3));
        $planId = $this->adaptPeriod($user, $rootId);
        $versionId = $this->seedPointedVersionWithLockedSlots($club, $season, $planId);
        $this->seedDatedClosure($club, $season, $rootId, $this->mon(-2), $this->sun(3));

        // Rétrécir la fin de sun(+3) à sun(+1).
        $preview = $this->preview($user, $planId, $this->mon(-2), $this->sun(1));
        $kinds = array_keys($this->effectsByKind($preview['effects']));
        self::assertTrue(\in_array('shift', $kinds, true) || \in_array('vanish', $kinds, true), 'raccourcir annonce un glissement/une disparition');

        $this->applyWindow($user, $planId, $this->mon(-2), $this->sun(1), $preview['token']);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        // Le passé est intact : la version du socle-de-période survit, la fenêtre du plan rétrécit,
        // son début reste figé.
        self::assertTrue($this->scheduleExists($club, $versionId), 'la version (passé) survit au raccourcissement');
        [$start, $end] = $this->planWindow($club, $planId);
        self::assertSame($this->mon(-2), $start, 'le début figé ne bouge pas');
        self::assertSame($this->sun(1), $end, 'la fin appliquée == la fin annoncée');
    }

    /** ③ Plancher : une fenêtre déjà commencée a son début FIGÉ — minEndDate servi, apply sous le plancher refusé. */
    public function testStartedWindowFloorIsServedAndEnforced(): void
    {
        [$user] = $this->createClubWithSeason();
        $rootId = $this->postPeriod($user, 'Barros en travaux', $this->mon(-2), $this->sun(1));
        $planId = $this->adaptPeriod($user, $rootId);

        // Servi : la fenêtre est éditable et le plancher est au plus tôt « today » (jamais dans le passé).
        $plan = $this->getPlan($user, $planId);
        self::assertTrue($plan['windowEditable'], 'une fermeture éditable l’annonce');
        self::assertNull($plan['windowLockedReason']);
        self::assertSame('Barros en travaux', $plan['closureTitle'], 'tous les segments d’un incident ouvrent la même fenêtre (titre de la racine)');
        self::assertGreaterThanOrEqual($this->today->format('Y-m-d'), $plan['minEndDate'], 'le plancher ne descend jamais avant aujourd’hui');

        // Apply qui tenterait d'effacer le passé (fin AVANT le plancher) → 422, rien n'est touché.
        $preview = $this->preview($user, $planId, $this->mon(-2), $this->mon(-1));
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startDate' => $this->mon(-2), 'endDate' => $this->mon(-1), 'previewToken' => $preview['token']], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'on ne raccourcit jamais une fenêtre sous le plancher du passé');
    }

    /** ④ Copie figée : modifier la SOURCE (le milieu) après coup ne redescend PAS dans les copies. */
    public function testCopiesAreSnapshotsDecoupledFromTheSource(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $rootId = $this->postPeriod($user, 'Barros en travaux', $this->mon(-2), $this->sun(1));
        $srcPlanId = $this->adaptPeriod($user, $rootId);
        $srcVersionId = $this->seedPointedVersionWithLockedSlots($club, $season, $srcPlanId);
        $this->seedDatedClosure($club, $season, $rootId, $this->mon(-2), $this->sun(1));
        $vacId = $this->postHoliday($user, 'Toussaint', $this->fri(2), $this->sun(4));
        $this->postWeekChild($user, $vacId, 'holiday', $this->mon(3), $this->sun(3));

        $preview = $this->preview($user, $srcPlanId, $this->mon(-2), $this->fri(6));
        $this->applyWindow($user, $srcPlanId, $this->mon(-2), $this->fri(6), $preview['token']);

        $copyChild = $this->childWithWindow($club, $rootId, $this->mon(6), $this->fri(6));
        self::assertNotNull($copyChild);
        $copyChosen = $this->chosenScheduleId($club, $this->planOf($club, $copyChild) ?? '');
        self::assertNotNull($copyChosen);
        $copyCountBefore = $this->slotCount($club, $copyChosen);

        // Muter la SOURCE : ajouter un slot à la version du milieu.
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $extra = (new ScheduleSlotTemplate)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setScheduleId($srcVersionId)
            ->setTeamId(self::TEAM_ID)
            ->setVenueId(self::VENUE_ID)
            ->setDayOfWeek(4)
            ->setStartTime(new DateTimeImmutable('20:30'))
            ->setDurationMinutes(90)
            ->setLockLevel(LockLevel::NONE);
        $this->em->persist($extra);
        $this->em->flush();

        self::assertSame($copyCountBefore, $this->slotCount($club, $copyChosen), 'la copie est FIGÉE : muter la source ne la touche pas');
    }

    /** ⑤ inv. 14 : rouvrir le socle balaie les segments FUTURS nés du geste, l'entrée racine SURVIT. */
    public function testReopeningSocleSweepsFutureSegmentsButKeepsStartedAndEntry(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $socle = $this->settleSeasonPlan($season); // le socle est en vigueur (pointé).

        $rootId = $this->postPeriod($user, 'Barros en travaux', $this->mon(-2), $this->sun(1));
        $srcPlanId = $this->adaptPeriod($user, $rootId);
        $this->seedPointedVersionWithLockedSlots($club, $season, $srcPlanId);
        $this->seedDatedClosure($club, $season, $rootId, $this->mon(-2), $this->sun(1));
        $vacId = $this->postHoliday($user, 'Toussaint', $this->fri(2), $this->sun(4));
        $this->postWeekChild($user, $vacId, 'holiday', $this->mon(3), $this->sun(3));

        $preview = $this->preview($user, $srcPlanId, $this->mon(-2), $this->fri(6));
        $this->applyWindow($user, $srcPlanId, $this->mon(-2), $this->fri(6), $preview['token']);

        $futureChild = $this->childWithWindow($club, $rootId, $this->mon(6), $this->fri(6));
        $futurePlan = $this->planOf($club, $futureChild ?? '');
        $milieuChild = $this->childWithWindow($club, $rootId, $this->mon(-2), $this->sun(1));
        self::assertNotNull($futurePlan);
        self::assertNotNull($milieuChild);

        // Rouvrir le socle (dépointer) — avec confirmation : les plans FUTURS sont balayés.
        $this->client->request('POST', '/api/schedules/' . $socle->getId() . '/reopen', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['confirmDeleteOverlays' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        self::assertFalse($this->planExists($club, $futurePlan), 'le segment FUTUR né du geste est balayé par la réouverture du socle');
        self::assertSame($srcPlanId, $this->planOf($club, $milieuChild), 'le milieu DÉJÀ commencé survit (le passé reste joué)');
        self::assertNotNull($this->rowWindow($club, $rootId), 'l’entrée de calendrier de l’incident SURVIT (à refaire)');
    }

    /** A1 — un club B visant le plan de A : 404 BYTE-IDENTIQUE à un plan inconnu (aucun oracle d’existence). */
    public function testCrossTenantPreviewIs404ByteIdenticalToUnknownPlan(): void
    {
        [$userA] = $this->createClubWithSeason();
        $rootId = $this->postPeriod($userA, 'Barros en travaux', $this->mon(-2), $this->sun(1));
        $planA = $this->adaptPeriod($userA, $rootId);

        [$userB] = $this->createClubWithSeason();

        $this->client->request('POST', '/api/schedule_plans/' . $planA . '/window-preview', [], [], $this->authHeaders($userB) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startDate' => $this->mon(-2), 'endDate' => $this->fri(6)], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
        $foreign = (string) $this->client->getResponse()->getContent();

        $unknown = '99999999-9999-4999-8999-999999999999';
        $this->client->request('POST', '/api/schedule_plans/' . $unknown . '/window-preview', [], [], $this->authHeaders($userB) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startDate' => $this->mon(-2), 'endDate' => $this->fri(6)], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
        $unknownBody = (string) $this->client->getResponse()->getContent();

        self::assertSame($unknownBody, $foreign, 'le plan d’un autre club est indistinguable d’un plan inconnu (aucun oracle d’existence)');
    }

    /** A2 — apply sans jeton → 422 ; jeton périmé (décor changé) → 409 ; jeton forgé → 409. */
    public function testApplyWithoutFreshTokenIsRefused(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $rootId = $this->postPeriod($user, 'Barros en travaux', $this->mon(-2), $this->sun(1));
        $planId = $this->adaptPeriod($user, $rootId);
        $this->seedPointedVersionWithLockedSlots($club, $season, $planId);
        $this->seedDatedClosure($club, $season, $rootId, $this->mon(-2), $this->sun(1));
        $vacId = $this->postHoliday($user, 'Toussaint', $this->fri(2), $this->sun(4));
        $this->postWeekChild($user, $vacId, 'holiday', $this->mon(3), $this->sun(3));

        // Sans jeton → 422.
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startDate' => $this->mon(-2), 'endDate' => $this->fri(6)], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'sans jeton d’aperçu, l’apply est refusé');

        // Jeton forgé → 409.
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startDate' => $this->mon(-2), 'endDate' => $this->fri(6), 'previewToken' => 'forged-token'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409, 'un jeton forgé est refusé');

        // Jeton PÉRIMÉ : on prend l'aperçu, puis le décor change (une reprise de plus), puis apply.
        $preview = $this->preview($user, $planId, $this->mon(-2), $this->fri(6));
        $this->postWeekChild($user, $vacId, 'holiday', $this->mon(4), $this->sun(4));
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startDate' => $this->mon(-2), 'endDate' => $this->fri(6), 'previewToken' => $preview['token']], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409, 'un jeton recalculé sous verrous devient périmé si le décor change');
    }

    /** A3 — le geste est une écriture cockpit : un non-gestionnaire est refusé (403). */
    public function testApplyRequiresManagementRole(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $rootId = $this->postPeriod($user, 'Barros en travaux', $this->mon(-2), $this->sun(1));
        $planId = $this->adaptPeriod($user, $rootId);
        $this->seedPointedVersionWithLockedSlots($club, $season, $planId);

        $coach = $this->addMember($club, 'coach');
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window-preview', [], [], $this->authHeaders($coach) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startDate' => $this->mon(-2), 'endDate' => $this->fri(6)], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403, 'un non-gestionnaire ne prolonge pas une fermeture');
    }

    /**
     * A4 — saison archivée : l'apply est refusé 409 par le mécanisme GLOBAL.
     *
     * « Archivée » se dérive de l'ANNÉE, jamais du statut : {@see SeasonResolver} fait foi (une
     * saison d'année N-1 est en lecture seule même avec `status = ACTIVE`). On pose donc une VRAIE
     * saison passée (patron {@see SeasonReadonlyTest}) et on y ancre la fermeture ciblée ; sans
     * header `X-Season-Id`, la saison SÉLECTIONNÉE est la courante (writable), mais la RESSOURCE
     * visée vit dans la saison archivée → {@see SeasonReadonlyGuardListener} dérive la saison de la
     * cible (SEC-13) et 409 — AVANT même que le contrôleur ne soit invoqué.
     *
     * ⚠ Le levier est le STATUT, pas le libellé : la page d'erreur HTML du harnais de test recopie
     * la SOURCE du test (l'assertion incluse), donc chercher le message du garde dans le corps est
     * VACUE (il s'y trouve toujours). On envoie donc un jeton d'aperçu VIDE : le seul 409 atteignable
     * est celui du garde saison (kernel.controller) ; sans lui, le contrôleur rend 422 « demandez
     * l'aperçu » (le garde retiré → ROUGE sur le statut). Un jeton bidon rendrait un 409 de
     * jeton-périmé qui MASQUERAIT la disparition du garde.
     */
    public function testApplyOnAnArchivedSeasonIsRefused(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        // Une seconde saison, d'année N-1 : le club devient multi-saison et la passée est en
        // lecture seule par l'année (status ACTIVE à dessein — ce n'est PAS le statut qui décide).
        $past = $this->createPastSeason($club);
        $planId = $this->seedClosurePlanInSeason($club, $past);

        // Pas de header X-Season-Id (saison sélectionnée = courante, writable) ni de jeton d'aperçu :
        // seule la dérivation « saison de la ressource » peut refuser → prouve le garde GLOBAL.
        $pastYear = (int) $past->getStartDate()->format('Y');
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startDate' => $pastYear . '-11-10', 'endDate' => $pastYear . '-11-23'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409, 'une saison passée (archivée par l’année) est refusée par le garde saison GLOBAL avant le contrôleur');
    }

    /**
     * ⑧ TITRE DE LA MÈRE RÉÉCRIT QUELLE QUE SOIT LA GRAPHIE (retour fondateur 2026-10-28).
     *
     * À la création, l'app pose un suffixe de dates en TOUTES LETTRES ({@see SchedulePlanProvisioner::
     * windowLabel} → « du 22 décembre 2025 au 11 janvier 2026 »). Après prolongation, la racine doit
     * être renommée avec la NOUVELLE fenêtre — y compris quand l'ancien titre porte une graphie
     * ABRÉGÉE (« 16 oct. 2026 », forme du seed Matéo) : aujourd'hui {@see ClosureWindowApplier::
     * recaleTitleSuffix} compare par `str_ends_with($title, windowLabel(old))` en toutes lettres, donc
     * un suffixe abrégé n'est JAMAIS reconnu ni réécrit (le cas ROUGE). Trois graphies :
     *  (A) suffixe en toutes lettres (ce que l'app pose)  → réécrit (déjà VERT, témoin de non-régression) ;
     *  (B) suffixe abrégé (graphie du seed BCCL)          → réécrit lui aussi (le cas ROUGE) ;
     *  (C) titre SANS suffixe de dates                    → inchangé (inv. 12 : le titre reste souverain).
     *
     * Le format PRODUIT reste celui de la création ({@see SchedulePlanProvisioner::windowLabel},
     * calculé ici par le vrai service, jamais une date en dur). L'extension [mon(-2), sun(2)] est
     * alignée lun→dim et sans vacances → un seul segment → chemin `simpleRedate` qui appelle le recale.
     */
    public function testMotherTitleIsRecaledWhateverTheDateGraphie(): void
    {
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $oldLong = $provisioner->windowLabel(new DateTimeImmutable($this->mon(-2)), new DateTimeImmutable($this->sun(1)));
        $newLong = $provisioner->windowLabel(new DateTimeImmutable($this->mon(-2)), new DateTimeImmutable($this->sun(2)));
        // L'app pose la forme « du … au … » à la création (pas « Semaine du … » : la fenêtre > 1 semaine).
        self::assertStringStartsWith('du ', $oldLong, 'la fenêtre multi-semaines produit un suffixe « du … au … »');
        self::assertStringStartsWith('du ', $newLong);
        self::assertNotSame($oldLong, $newLong, 'la nouvelle fenêtre produit un suffixe différent de l’ancien');

        // (A) suffixe en TOUTES LETTRES (ce que l'app pose) → réécrit avec la nouvelle fenêtre.
        self::assertSame(
            'Matéo en travaux — ' . $newLong,
            $this->recaleRootTitleAfterExtend('Matéo en travaux — ' . $oldLong),
            'un suffixe en toutes lettres est réécrit avec la nouvelle fenêtre',
        );

        // (B) suffixe ABRÉGÉ (graphie du seed BCCL « 16 oct. ») → réécrit MALGRÉ la graphie (cas ROUGE).
        $abbrev = $this->abbreviateFrenchMonths($oldLong);
        self::assertNotSame($oldLong, $abbrev, 'la forme abrégée diffère bien de la forme en toutes lettres');
        self::assertSame(
            'Matéo en travaux — ' . $newLong,
            $this->recaleRootTitleAfterExtend('Matéo en travaux — ' . $abbrev),
            'un suffixe abrégé est réécrit lui aussi (reconnaissance insensible à la graphie)',
        );

        // (C) titre SANS suffixe de dates → inchangé (inv. 12 : le titre reste souverain).
        self::assertSame(
            'Barros en travaux',
            $this->recaleRootTitleAfterExtend('Barros en travaux'),
            'un titre sans suffixe de dates ne bouge pas',
        );
    }

    /**
     * Crée une fermeture (titre DONNÉ) sur [mon(-2), sun(1)], la prolonge à [mon(-2), sun(2)] (aligné,
     * sans vacances → `simpleRedate`), et rend le titre de la racine APRÈS apply — l'EFFET persisté,
     * jamais le site d'appel. Un club jetable par titre évite tout chevauchement de fenêtre entre cas.
     */
    private function recaleRootTitleAfterExtend(string $title): string
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $rootId = $this->postPeriod($user, $title, $this->mon(-2), $this->sun(1));
        $planId = $this->adaptPeriod($user, $rootId);
        $this->seedPointedVersionWithLockedSlots($club, $season, $planId);
        $this->seedDatedClosure($club, $season, $rootId, $this->mon(-2), $this->sun(1));

        $preview = $this->preview($user, $planId, $this->mon(-2), $this->sun(2));
        $this->applyWindow($user, $planId, $this->mon(-2), $this->sun(2), $preview['token']);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $persisted = $this->em->getConnection()->fetchOne('SELECT title FROM calendar_entry WHERE id = :id', ['id' => $rootId]);
        self::assertNotFalse($persisted, 'la racine survit à la prolongation');

        return (string) $persisted;
    }

    /** Abrège les mois en toutes lettres (« décembre » → « déc. ») — fabrique la graphie du seed BCCL. */
    private function abbreviateFrenchMonths(string $label): string
    {
        return strtr($label, [
            'janvier' => 'janv.', 'février' => 'févr.', 'mars' => 'mars', 'avril' => 'avr.',
            'mai' => 'mai', 'juin' => 'juin', 'juillet' => 'juil.', 'août' => 'août',
            'septembre' => 'sept.', 'octobre' => 'oct.', 'novembre' => 'nov.', 'décembre' => 'déc.',
        ]);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // L'HORLOGE DU TEST : pinnée sur un LUNDI connu (2026-01-05 est un lundi) — toute fenêtre se
        // dérive en semaines depuis ce « today », jamais une date calendaire en dur.
        $this->today = new DateTimeImmutable('2026-01-05T12:00:00+00:00');
        self::getContainer()->get(DevClockStore::class)->set($this->today);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    /** Une VRAIE saison passée (année N-1 relative à l’horloge), ACTIVE : archivée par l’ANNÉE seule. */
    private function createPastSeason(Club $club): Season
    {
        $pastYear = SeasonResolver::seasonYear($this->today) - 1;
        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName($pastYear . '-' . ($pastYear + 1));
        $season->setStartDate(new DateTimeImmutable($pastYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($pastYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    /**
     * Une fermeture ancrée dans la saison donnée : l’entrée de calendrier `closure` + son plan
     * CLOSURE pointé par calendarEntryId — tout ce que le garde doit voir pour dériver la saison
     * de la cible. Le geste réel (Adapter) vit dans la saison COURANTE ; ici la cible est passée,
     * donc on l’ancre directement (le garde refuse AVANT que le contrôleur ne lise le corps).
     */
    private function seedClosurePlanInSeason(Club $club, Season $season): string
    {
        $this->scopeGucToClub($club->getId());
        $year = (int) $season->getStartDate()->format('Y');
        $entry = (new CalendarEntry)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)
            ->setTitle('Barros en travaux (saison passée)')
            ->setStartDate(new DateTimeImmutable($year . '-11-10'))
            ->setEndDate(new DateTimeImmutable($year . '-11-23'))
            ->setIsDisruptive(true)
            ->setPeriodType(CalendarEntryPeriodType::CLOSURE)
            ->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);

        $plan = (new SchedulePlan)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setType(SchedulePlanType::CLOSURE)
            ->setName('Barros en travaux (saison passée)')
            ->setStartDate(new DateTimeImmutable($year . '-11-10'))
            ->setEndDate(new DateTimeImmutable($year . '-11-23'))
            ->setCalendarEntryId($entry->getId());
        $this->em->persist($plan);
        $this->em->flush();

        return $plan->getId();
    }

    /** Lundi n semaines depuis « today » (n négatif = passé), au format Y-m-d. */
    private function mon(int $n): string
    {
        return $this->today->modify(\sprintf('%+d days', 7 * $n))->format('Y-m-d');
    }

    /** Dimanche de la semaine +n (= lundi+n + 6 j). */
    private function sun(int $n): string
    {
        return $this->today->modify(\sprintf('%+d days', 7 * $n + 6))->format('Y-m-d');
    }

    /** Jeudi de la semaine +n. */
    private function thu(int $n): string
    {
        return $this->today->modify(\sprintf('%+d days', 7 * $n + 3))->format('Y-m-d');
    }

    /** Vendredi de la semaine +n. */
    private function fri(int $n): string
    {
        return $this->today->modify(\sprintf('%+d days', 7 * $n + 4))->format('Y-m-d');
    }

    /** Samedi de la semaine +n (premier jour du WEEK-END — début d'une pause de vacances). */
    private function sat(int $n): string
    {
        return $this->today->modify(\sprintf('%+d days', 7 * $n + 5))->format('Y-m-d');
    }

    /**
     * @param list<array{kind: string, startDate: string, endDate: string, label: string}> $effects
     *
     * @return array<string, list<array{kind: string, startDate: string, endDate: string, label: string}>>
     */
    private function effectsByKind(array $effects): array
    {
        $byKind = [];
        foreach ($effects as $effect) {
            $byKind[$effect['kind']][] = $effect;
        }

        return $byKind;
    }

    /**
     * L'aperçu : POST /window-preview → {token, summary, futurePlansWarning, effects}.
     *
     * @return array{token: string, summary: string, futurePlansWarning: bool, effects: list<array{kind: string, startDate: string, endDate: string, label: string}>}
     */
    private function preview(User $user, string $planId, string $start, string $end): array
    {
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window-preview', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startDate' => $start, 'endDate' => $end], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['token']);
        self::assertIsString($payload['summary']);
        self::assertIsBool($payload['futurePlansWarning']);
        self::assertIsArray($payload['effects']);

        $effects = [];
        foreach ($payload['effects'] as $effect) {
            self::assertIsArray($effect);
            $effects[] = [
                'kind' => (string) $effect['kind'],
                'startDate' => (string) $effect['startDate'],
                'endDate' => (string) $effect['endDate'],
                'label' => (string) $effect['label'],
            ];
        }

        return [
            'token' => $payload['token'],
            'summary' => $payload['summary'],
            'futurePlansWarning' => $payload['futurePlansWarning'],
            'effects' => $effects,
        ];
    }

    private function applyWindow(User $user, string $planId, string $start, string $end, string $token): void
    {
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startDate' => $start, 'endDate' => $end, 'previewToken' => $token], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> le corps JSON de GET /api/schedule_plans/{id} */
    private function getPlan(User $user, string $planId): array
    {
        $this->client->request('GET', '/api/schedule_plans/' . $planId, [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function servedStructureHash(User $user, ?string $planId): ?string
    {
        self::assertIsString($planId);
        $this->client->request('GET', '/api/schedule_plans/' . $planId . '/structure-hash', [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        // La route /structure-hash sert la clé `currentStructureHash` (SchedulePlanStructureHashController) —
        // lire `structureHash`/`hash` rendait TOUJOURS null (l'assertion de survie passait alors pour rien).
        $hash = $payload['currentStructureHash'] ?? null;

        return null === $hash ? null : (string) $hash;
    }

    /** Le geste « Adapter » : POST /api/schedule_plans — rend l'id du plan (201). */
    private function adaptPeriod(User $user, string $entryId): string
    {
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    /**
     * Une version POINTÉE (COMPLETED) du plan, avec deux séances HARD/MANUAL — le milieu « source ».
     *
     * @return string l'id de la version pointée
     */
    private function seedPointedVersionWithLockedSlots(Club $club, Season $season, string $planId): string
    {
        $this->scopeGucToClub($club->getId());
        $version = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setName('V1')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($version);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($version);

        foreach ([[1, '18:00'], [3, '19:00']] as $slot) {
            $tpl = (new ScheduleSlotTemplate)
                ->setClubId($club->getId())
                ->setSeasonId($season->getId())
                ->setScheduleId($version->getId())
                ->setTeamId(self::TEAM_ID)
                ->setVenueId(self::VENUE_ID)
                ->setDayOfWeek($slot[0])
                ->setStartTime(new DateTimeImmutable($slot[1]))
                ->setDurationMinutes(90)
                ->setLockLevel(LockLevel::HARD)
                ->setLockOrigin(LockOrigin::MANUAL);
            $this->em->persist($tpl);
        }
        $this->em->flush();
        $this->choosePlanVersion($version);

        return $version->getId();
    }

    /** Une reprise : une version COMPLETED NON pointée (non validée), que le gestionnaire régénérera. */
    private function seedUnchosenCompletedVersion(Club $club, Season $season, ?string $planId): void
    {
        self::assertIsString($planId);
        $this->scopeGucToClub($club->getId());
        $version = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setName('V1')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($version);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($version);
        $this->em->flush();
    }

    /** Donne au club une zone scolaire — sans quoi le feed des vacances scolaires reste muet. */
    private function setClubSchoolZone(Club $club, string $zone): void
    {
        $this->scopeGucToClub($club->getId());
        $club->setSchoolZone($zone);
        $this->em->flush();
    }

    /**
     * Pose une période de vacances SCOLAIRES (donnée GLOBALE, hors RLS) nommée par le feed de la zone
     * donnée — la source du NOM d'une pause. L'horloge du test est pinnée : les bornes sont dérivées
     * en semaines (mon/fri/sun), jamais des dates calendaires en dur.
     */
    private function seedSchoolHoliday(string $zone, string $label, string $start, string $end): void
    {
        $holiday = (new SchoolHolidayPeriod)
            ->setZone($zone)
            ->setLabel($label)
            ->setHolidayType('cwx-' . substr(md5($zone . $label), 0, 12))
            ->setStartDate(new DateTimeImmutable($start))
            ->setEndDate(new DateTimeImmutable($end))
            ->setSchoolYear('2025-2026');
        $this->em->persist($holiday);
        $this->em->flush();
    }

    /** @return string l'id de la contrainte datée `venue_closed` accrochée à la racine */
    private function seedDatedClosure(Club $club, Season $season, string $entryId, string $start, string $end): string
    {
        $this->scopeGucToClub($club->getId());
        $constraint = (new Constraint)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setName('Barros fermé')
            ->setScope(ConstraintScope::FACILITY)
            ->setFamily(ConstraintFamily::FACILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setScopeTargetId(self::VENUE_ID)
            ->setConfig(['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end]);
        $constraint->setCalendarEntryId($entryId);
        $this->em->persist($constraint);
        $this->em->flush();

        return $constraint->getId();
    }

    private function postPeriod(User $user, string $title, string $start, string $end): string
    {
        return $this->postEntry($user, ['kind' => 'period', 'title' => $title, 'startDate' => $start, 'endDate' => $end, 'periodType' => 'closure']);
    }

    private function postHoliday(User $user, string $title, string $start, string $end): string
    {
        return $this->postEntry($user, ['kind' => 'period', 'title' => $title, 'startDate' => $start, 'endDate' => $end, 'periodType' => 'holiday']);
    }

    private function postWeekChild(User $user, string $parentId, string $periodType, string $start, string $end): string
    {
        return $this->postEntry($user, ['kind' => 'period', 'title' => 'Segment', 'startDate' => $start, 'endDate' => $end, 'periodType' => $periodType, 'parentEntryId' => $parentId]);
    }

    /** @param array<string, mixed> $body */
    private function postEntry(User $user, array $body): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    private function planOf(Club $club, string $entryId): ?string
    {
        if ('' === $entryId) {
            return null;
        }
        $this->scopeGucToClub($club->getId());
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM schedule_plan WHERE calendar_entry_id = :eid', ['eid' => $entryId]);

        return false === $id ? null : (string) $id;
    }

    private function planExists(Club $club, ?string $planId): bool
    {
        if (null === $planId) {
            return false;
        }
        $this->scopeGucToClub($club->getId());

        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM schedule_plan WHERE id = :pid', ['pid' => $planId]);
    }

    private function chosenScheduleId(Club $club, ?string $planId): ?string
    {
        if (null === $planId || '' === $planId) {
            return null;
        }
        $this->scopeGucToClub($club->getId());
        $id = $this->em->getConnection()->fetchOne('SELECT chosen_schedule_id FROM schedule_plan WHERE id = :pid', ['pid' => $planId]);

        return false === $id || null === $id ? null : (string) $id;
    }

    private function scheduleStatus(Club $club, string $scheduleId): string
    {
        $this->scopeGucToClub($club->getId());

        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM schedule WHERE id = :sid', ['sid' => $scheduleId]);
    }

    private function scheduleExists(Club $club, string $scheduleId): bool
    {
        $this->scopeGucToClub($club->getId());

        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM schedule WHERE id = :sid', ['sid' => $scheduleId]);
    }

    private function slotCount(Club $club, string $scheduleId): int
    {
        $this->scopeGucToClub($club->getId());

        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule_slot_template WHERE schedule_id = :sid', ['sid' => $scheduleId]);
    }

    /** @return list<string> les origines de verrou DISTINCTES des slots d'une version */
    private function distinctLockOrigins(Club $club, string $scheduleId): array
    {
        $this->scopeGucToClub($club->getId());
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT DISTINCT lock_origin FROM schedule_slot_template WHERE schedule_id = :sid AND lock_origin IS NOT NULL', ['sid' => $scheduleId]);
        $origins = array_map(static fn (mixed $v): string => (string) $v, $rows);
        sort($origins);

        return $origins;
    }

    /** @return array{0: string, 1: string} [start, end] Y-m-d du schedule_plan */
    private function planWindow(Club $club, string $planId): array
    {
        $this->scopeGucToClub($club->getId());
        $row = $this->em->getConnection()->fetchAssociative('SELECT start_date, end_date FROM schedule_plan WHERE id = :pid', ['pid' => $planId]);
        self::assertIsArray($row);

        return [mb_substr((string) $row['start_date'], 0, 10), mb_substr((string) $row['end_date'], 0, 10)];
    }

    /** @return array{0: string, 1: string}|null [start, end] Y-m-d d'une entrée, ou null si disparue */
    private function rowWindow(Club $club, string $entryId): ?array
    {
        $this->scopeGucToClub($club->getId());
        $row = $this->em->getConnection()->fetchAssociative('SELECT start_date, end_date FROM calendar_entry WHERE id = :id', ['id' => $entryId]);
        if (false === $row) {
            return null;
        }

        return [mb_substr((string) $row['start_date'], 0, 10), mb_substr((string) $row['end_date'], 0, 10)];
    }

    private function childWithWindow(Club $club, string $motherId, string $start, string $end): ?string
    {
        $this->scopeGucToClub($club->getId());
        $id = $this->em->getConnection()->fetchOne(
            'SELECT id FROM calendar_entry WHERE parent_entry_id = :pid AND start_date = :start AND end_date = :end',
            ['pid' => $motherId, 'start' => $start, 'end' => $end],
        );

        return false === $id ? null : (string) $id;
    }

    private function addMember(Club $club, string $role): User
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $member = new User;
        $member->setEmail('cwx-member-' . $uid . '@test.com');
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
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubWithSeason(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club fermeture enjambe vacances');
        $club->setSlug('club-cwx-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('CWX' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('cwx' . $uid . '@test.com');
        $user->setFirstName('Cl');
        $user->setLastName('Wx');
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

        // Saison GÉNÉREUSE autour de « today » (pinné) : elle contient les fenêtres mon(-2)…fri(+6).
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-08-01'));
        $season->setEndDate(new DateTimeImmutable('2026-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        $this->provisionSeasonPlanFor($season);

        return [$user, $club, $season];
    }

    private function provisionSeasonPlanFor(Season $season): void
    {
        self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($season);
        $this->em->flush();
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
