<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * P3 — la boucle de travail du gestionnaire, de bout en bout sur le petit club réaliste
 * ({@see SmallClubFixtureTrait}) : « je contrains, je réserve, je verrouille, je régénère :
 * tout tient ». Deux solves réels (le socle, puis la régénération).
 *
 * UNE promesse composée, à l'image du vrai geste : après avoir posé une contrainte d'équipe,
 * réservé un créneau libre et verrouillé une séance, la régénération HONORE les trois à la
 * fois — la contrainte saisie (SM1 jamais avant 20h30), la réservation (la case reste vide)
 * et le verrou (la séance ne bouge pas). En chemin, le plafond de séances par jour d'une équipe
 * est lu de la même façon par la garde des gestes et par le verdict du moteur : à 1, déplacer une
 * séance de SF1 vers un jour où elle s'entraîne déjà est refusé ; à 2, accepté avec la concession
 * d'écart nommée ; le geste inverse est accepté (cas nombreux : `OneSessionPerDayGesturesTest`,
 * `engine/tests/semantic/test_validate_assignments_semantics.py`). Une règle du club réglée sur
 * Ignorée est annoncée par le Récap, puis ni appliquée ni signalée par la régénération ; remise sur
 * Obligatoire, l'annonce disparaît ; la présence d'un salarié n'est jamais obligatoire (cas
 * nombreux : `ImplicitRuleSettingApiTest`, `ValidateConstraintsTest`, moteur
 * `test_implicit_rules_adjustable.py`). SF1 se réserve la case d'une de ses séances : la régénération l'y verrouille, et
 * la déplacer depuis le planning est refusé (`source_reserved`), rien ne bouge (cas nombreux — saison, période, échange,
 * groupe, verrou à la main, réservation ailleurs : `OneSessionPerDayGesturesTest`). Remplace, sur un décor léger (palier 60 s) :
 *   - `une-contrainte-saisie-est-honoree.feature:9` (une contrainte d'équipe honorée) ;
 *   - `un-verrou-est-souverain.feature:9` (une séance verrouillée reste à sa case après régénération) ;
 *   - `un-verrou-est-souverain.feature:31` (un créneau libre réservé retire la place au solveur).
 * (Les régénérations de PLAN DE PÉRIODE — :47/:57/:69 — et les edges :21/:40 restent dans
 * `un-verrou`, promesses distinctes du cycle de vie des plans de période, sans filet plus bas.)
 *
 * Second scénario, sans solve de plus que le socle : une séance verrouillée compte comme une séance de l'équipe, une
 * fois seulement quand elle est posée sur sa propre réservation (cas nombreux, saison, période, groupe, comblement :
 * `GenerationRefusesARecapBlockerTest`).
 */
final class SeasonWorkLoopContext extends BaseContext
{
    use SmallClubFixtureTrait;

    /** Équipe à deux séances sans coach, dont un verrou compte comme une séance. */
    private const string COUNTED_TEAM = 'U20M1';

    /** ISO-8601 : 1 = lundi. */
    private const array DAY_NAMES = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    private string $socleVersionId = '';

    private string $regeneratedId = '';

    private string $finalStatus = '';

    /** Séance verrouillée (à retrouver à la même case). */
    private string $lockVenueId = '';

    private int $lockDay = 0;

    private string $lockStartTime = '';

    private string $lockTeamId = '';

    /** Créneau libre réservé (sa case doit rester vide). */
    private string $reservedVenueId = '';

    private int $reservedDay = 0;

    private string $reservedStart = '';

    /** Séance de SF1 déplacée vers le jour de son autre séance, puis remise en place. */
    private string $sf1SlotId = '';

    private string $sf1OriginVenueId = '';

    private int $sf1OriginDay = 0;

    private string $sf1OriginStart = '';

    private string $sf1TargetVenueId = '';

    private int $sf1TargetDay = 0;

    private string $sf1TargetStart = '';

    private int $moveStatus = 0;

    /** @var array<mixed> */
    private array $moveJson = [];

    /** La case de la séance de SF1 que SF1 se réserve (hors case du créneau libre, hors séance verrouillée). */
    private string $sf1HeldVenueId = '';

    private int $sf1HeldDay = 0;

    private string $sf1HeldStart = '';

    /** La séance de SF1 tenue par sa réservation dans la version régénérée (celle qu'on essaie de déplacer). */
    private string $sf1HeldSlotId = '';

    /** Réponse au réglage d'une règle du club refusé (salarié sur Obligatoire). */
    private int $ruleStatus = 0;

    /** @var array<mixed> */
    private array $ruleJson = [];

    /** La contrainte « SM1 pas avant 20h30 », renommée puis rétablie. */
    private string $sm1ConstraintId = '';

    /** @var list<array{id: string, venueId: string, day: int, start: string}> les deux séances de l'équipe comptée, la première réservée */
    private array $countedSessions = [];

    /** @var list<string> */
    private array $countedReservationIds = [];

    /** Le motif du Récap qui nomme la séance verrouillée hors réservation. */
    private string $countedBlocker = '';

    private int $schedulesBefore = 0;

    private int $regenerateStatus = 0;

    /** @var array<mixed> */
    private array $regenerateJson = [];

    #[Given('un petit club réaliste dont le planning de saison est en vigueur, puis rouvert pour y retravailler')]
    public function unPetitClubSocleEnVigueurPuisRouvert(): void
    {
        $this->buildSmallClub();
        $this->socleVersionId = $this->generateAndValidateSmallClubSocle();
        // Le socle est en vigueur ; on le rouvre pour y retravailler (geste réel du gestionnaire).
        $this->reopenSmallClubSocle();
    }

    #[Given('j\'interdis à SM1 de s\'entraîner avant 20h30')]
    public function jInterdisSm1Avant2030(): void
    {
        $created = $this->apiPost('constraints', [
            'name' => 'SM1 pas avant 20h30 (boucle de travail)',
            'scope' => 'TEAM',
            'scopeTargetId' => $this->smallClubTeamIds['SM1'],
            'family' => 'TIME',
            'ruleType' => 'HARD',
            'config' => ['minStartTime' => '20:30'],
            'isActive' => true,
        ], $this->smallClubToken);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création de la contrainte SM1 refusée (HTTP %d)', $created['status']));
        }
        $this->sm1ConstraintId = $this->smallClubStringField($created['json'], 'id');
    }

    #[Given('je réserve un créneau libre sur une case occupée du socle')]
    public function jeReserveUnCreneauLibre(): void
    {
        // Même sélection déterministe que `un-verrou:31` (LockContext) : une case occupée du socle,
        // servie par un créneau de base NON divisible, capacité 1, finissant avant minuit, sans
        // occupation de groupe ni réservation — miroir des gardes de ReservationStateProcessor.
        $row = $this->dbalScalar(
            \sprintf(
                'SELECT sst.venue_id || \'|\' || sst.day_of_week || \'|\' || to_char(sst.start_time, \'HH24:MI\') AS behatval'
                . ' FROM schedule_slot_template sst'
                . ' JOIN venue v ON v.id = sst.venue_id AND v.can_split = false'
                . ' JOIN venue_training_slot vts ON vts.venue_id = sst.venue_id AND vts.day_of_week = sst.day_of_week'
                . ' AND to_char(vts.start_time, \'HH24:MI\') = to_char(sst.start_time, \'HH24:MI\')'
                . ' AND vts.schedule_plan_id IS NULL AND vts.club_id = \'%1$s\' AND vts.capacity = 1'
                . ' WHERE sst.schedule_id = \'%2$s\''
                . ' AND (extract(hour from sst.start_time)::int * 60 + extract(minute from sst.start_time)::int + 90) <= 1440'
                . ' AND NOT EXISTS ('
                . ' SELECT 1 FROM schedule_slot_template g WHERE g.schedule_id = sst.schedule_id'
                . ' AND g.venue_id = sst.venue_id AND g.day_of_week = sst.day_of_week'
                . ' AND to_char(g.start_time, \'HH24:MI\') = to_char(sst.start_time, \'HH24:MI\')'
                . ' AND g.shared_training_block_id IS NOT NULL)'
                . ' AND NOT EXISTS ('
                . ' SELECT 1 FROM reservation res WHERE res.club_id = \'%1$s\' AND res.schedule_plan_id IS NULL'
                . ' AND res.venue_id = sst.venue_id AND res.day_of_week = sst.day_of_week'
                . ' AND to_char(res.start_time, \'HH24:MI\') = to_char(sst.start_time, \'HH24:MI\'))'
                . ' ORDER BY sst.venue_id, sst.day_of_week, sst.start_time LIMIT 1',
                $this->smallClubId,
                $this->socleVersionId,
            ),
            admin: true,
        );
        $parts = '' === $row ? [] : explode('|', $row);
        if (3 !== \count($parts)) {
            throw new RuntimeException('aucune case occupée éligible à un créneau libre sur le petit club');
        }
        [$this->reservedVenueId, $day, $this->reservedStart] = $parts;
        $this->reservedDay = (int) $day;

        $created = $this->apiPost('reservations', [
            'label' => 'Loto du club (boucle de travail)',
            'venueId' => $this->reservedVenueId,
            'dayOfWeek' => $this->reservedDay,
            'startTime' => $this->reservedStart,
            'durationMinutes' => 90,
            'schedulePlanId' => null,
        ], $this->smallClubToken);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la pose du créneau libre a répondu %d (201 attendu) — corps : %s', $created['status'], json_encode($created['json'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
        }
    }

    #[Given('je verrouille une autre séance du socle')]
    public function jeVerrouilleUneAutreSeance(): void
    {
        // Une séance LIBRE d'une équipe AUTRE que SM1 (le verrou est souverain : verrouiller SM1 avant
        // 20h30 masquerait la contrainte) et HORS de la case réservée (sans quoi réservation et verrou
        // se disputeraient la même case). Déterministe (tri par id).
        $row = $this->dbalScalar(
            \sprintf(
                'SELECT id || \'|\' || team_id || \'|\' || venue_id || \'|\' || day_of_week || \'|\' || to_char(start_time, \'HH24:MI\') AS behatval'
                . ' FROM schedule_slot_template'
                . ' WHERE schedule_id=\'%1$s\' AND lock_level=\'NONE\' AND team_id <> \'%2$s\''
                . ' AND NOT (venue_id=\'%3$s\' AND day_of_week=%4$d AND to_char(start_time, \'HH24:MI\')=\'%5$s\')'
                . ' ORDER BY id LIMIT 1',
                $this->socleVersionId,
                $this->smallClubTeamIds['SM1'],
                $this->reservedVenueId,
                $this->reservedDay,
                $this->reservedStart,
            ),
            admin: true,
        );
        $parts = '' === $row ? [] : explode('|', $row);
        if (5 !== \count($parts)) {
            throw new RuntimeException('aucune séance libre (hors SM1, hors case réservée) à verrouiller sur le petit club');
        }
        [$slotId, $this->lockTeamId, $this->lockVenueId, $day, $this->lockStartTime] = $parts;
        $this->lockDay = (int) $day;

        $locked = $this->apiPost(
            \sprintf('schedule-slots/%s/manual-edit/lock', $slotId),
            ['lockLevel' => 'HARD'],
            $this->smallClubToken,
        );
        if (200 !== $locked['status']) {
            throw new RuntimeException(\sprintf('le verrouillage de la séance a répondu %d (200 attendu)', $locked['status']));
        }
    }

    /**
     * Le geste « je réserve ce qui est bien placé » : SF1 se réserve la case d'une de ses séances du socle. Ni la case du
     * créneau libre (capacité 1 : SF1 devrait la quitter), ni celle de la séance verrouillée (elle peut être de SF1).
     * Déterministe (jour, heure, gymnase).
     */
    #[Given('je réserve à SF1 la case de sa séance')]
    public function jeReserveASf1LaCaseDeSaSeance(): void
    {
        $row = $this->dbalScalar(
            \sprintf(
                'SELECT venue_id || \'|\' || day_of_week || \'|\' || to_char(start_time, \'HH24:MI\') || \'|\' || duration_minutes AS behatval'
                . ' FROM schedule_slot_template'
                . ' WHERE schedule_id=\'%1$s\' AND team_id=\'%2$s\' AND shared_training_block_id IS NULL'
                . ' AND NOT (venue_id=\'%3$s\' AND day_of_week=%4$d AND to_char(start_time, \'HH24:MI\')=\'%5$s\')'
                . ' AND NOT (venue_id=\'%6$s\' AND day_of_week=%7$d AND to_char(start_time, \'HH24:MI\')=\'%8$s\')'
                . ' ORDER BY day_of_week, start_time, venue_id LIMIT 1',
                $this->socleVersionId,
                $this->smallClubTeamIds['SF1'],
                $this->reservedVenueId,
                $this->reservedDay,
                $this->reservedStart,
                $this->lockVenueId,
                $this->lockDay,
                $this->lockStartTime,
            ),
            admin: true,
        );
        $parts = '' === $row ? [] : explode('|', $row);
        if (4 !== \count($parts)) {
            throw new RuntimeException('décor : aucune séance de SF1 hors de la case du créneau libre et de la séance verrouillée');
        }
        [$this->sf1HeldVenueId, $day, $this->sf1HeldStart, $duration] = $parts;
        $this->sf1HeldDay = (int) $day;

        $created = $this->apiPost('reservations', [
            'teamId' => $this->smallClubTeamIds['SF1'],
            'venueId' => $this->sf1HeldVenueId,
            'dayOfWeek' => $this->sf1HeldDay,
            'startTime' => $this->sf1HeldStart,
            'durationMinutes' => (int) $duration,
            'schedulePlanId' => null,
        ], $this->smallClubToken);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la réservation de SF1 à la case de sa séance a répondu %d (201 attendu) : %s', $created['status'], $this->encoded($created['json'])));
        }
    }

    /**
     * Dans la version régénérée (la nouvelle version chargée), la séance de SF1 que tient sa réservation ; vers une case
     * vide de la grille de saison, un jour où SF1 ne s'entraîne pas, sans réservation, où l'ordre des âges tient (le geste
     * ne doit pouvoir tomber que sur la réservation).
     */
    #[When('j\'essaie de déplacer la séance réservée de SF1 vers une case libre')]
    public function jEssaieDeDeplacerLaSeanceReserveeDeSf1(): void
    {
        $this->sf1HeldSlotId = $this->dbalScalar(
            \sprintf(
                'SELECT id AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\''
                . ' AND venue_id=\'%s\' AND day_of_week=%d AND to_char(start_time, \'HH24:MI\')=\'%s\' AND lock_level=\'HARD\'',
                $this->regeneratedId,
                $this->smallClubTeamIds['SF1'],
                $this->sf1HeldVenueId,
                $this->sf1HeldDay,
                $this->sf1HeldStart,
            ),
            admin: true,
        );
        if ('' === $this->sf1HeldSlotId) {
            throw new RuntimeException('décor : après la régénération, SF1 devrait être verrouillée à la case de sa réservation');
        }

        $row = $this->dbalScalar(
            \sprintf(
                'SELECT vts.venue_id || \'|\' || vts.day_of_week || \'|\' || to_char(vts.start_time, \'HH24:MI\') AS behatval'
                . ' FROM venue_training_slot vts'
                . ' WHERE vts.club_id=\'%3$s\' AND vts.schedule_plan_id IS NULL'
                . ' AND NOT EXISTS (SELECT 1 FROM schedule_slot_template s WHERE s.schedule_id=\'%1$s\''
                . ' AND s.venue_id=vts.venue_id AND s.day_of_week=vts.day_of_week AND s.start_time=vts.start_time)'
                . ' AND NOT EXISTS (SELECT 1 FROM schedule_slot_template s WHERE s.schedule_id=\'%1$s\' AND s.team_id=\'%2$s\' AND s.day_of_week=vts.day_of_week)'
                . ' AND NOT EXISTS (SELECT 1 FROM reservation r WHERE r.club_id=\'%3$s\' AND r.schedule_plan_id IS NULL'
                . ' AND r.venue_id=vts.venue_id AND r.day_of_week=vts.day_of_week AND r.start_time=vts.start_time)'
                . ' AND NOT EXISTS (SELECT 1 FROM schedule_slot_template s'
                . ' JOIN team t ON t.id = s.team_id JOIN sport_category c ON c.id = t.sport_category_id'
                . ' JOIN team me ON me.id = \'%2$s\' JOIN sport_category mc ON mc.id = me.sport_category_id'
                . ' WHERE s.schedule_id=\'%1$s\' AND s.team_id <> \'%2$s\''
                . ' AND s.venue_id=vts.venue_id AND s.day_of_week=vts.day_of_week'
                . ' AND c.age_min IS NOT NULL AND mc.age_min IS NOT NULL'
                . ' AND ((c.age_min < mc.age_min AND s.start_time > vts.start_time) OR (c.age_min > mc.age_min AND s.start_time < vts.start_time)))'
                . ' ORDER BY vts.start_time DESC, vts.day_of_week, vts.venue_id LIMIT 1',
                $this->regeneratedId,
                $this->smallClubTeamIds['SF1'],
                $this->smallClubId,
            ),
            admin: true,
        );
        $parts = '' === $row ? [] : explode('|', $row);
        if (3 !== \count($parts)) {
            throw new RuntimeException('décor : aucune case vide un jour où SF1 ne s\'entraîne pas dans la version régénérée');
        }

        $response = $this->apiPost(\sprintf('schedule-slots/%s/move', $this->sf1HeldSlotId), [
            'venueId' => $parts[0],
            'dayOfWeek' => (int) $parts[1],
            'startTime' => $parts[2],
        ], $this->smallClubToken);
        $this->moveStatus = $response['status'];
        $this->moveJson = $response['json'];
    }

    #[Then('c\'est refusé : « SF1 tient ce créneau par une réservation de gymnase », et la séance n\'a pas bougé')]
    public function cEstRefuseLaSeanceEstTenueParSaReservation(): void
    {
        if (422 !== $this->moveStatus || 'source_reserved' !== ($this->moveJson['code'] ?? null)) {
            throw new RuntimeException(\sprintf('déplacer la séance que tient la réservation de SF1 doit être refusé (422 source_reserved) — reçu %d : %s', $this->moveStatus, $this->encoded($this->moveJson)));
        }
        $error = (string) ($this->moveJson['error'] ?? '');
        if (!str_starts_with($error, 'SF1 tient ce créneau par une réservation de gymnase')) {
            throw new RuntimeException(\sprintf('le refus doit nommer SF1 et la réservation — obtenu : « %s »', $error));
        }
        $inPlace = (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE id=\'%s\' AND venue_id=\'%s\' AND day_of_week=%d AND to_char(start_time, \'HH24:MI\')=\'%s\' AND lock_level=\'HARD\'',
                $this->sf1HeldSlotId,
                $this->sf1HeldVenueId,
                $this->sf1HeldDay,
                $this->sf1HeldStart,
            ),
            admin: true,
        );
        if (1 !== $inPlace) {
            throw new RuntimeException('la séance réservée de SF1 a bougé alors que le déplacement est refusé');
        }
    }

    #[When('j\'essaie de déplacer une séance de SF1 vers un jour où elle s\'entraîne déjà')]
    public function jEssaieDeDeplacerSf1VersSonAutreJour(): void
    {
        $this->pickSf1MoveTowardsItsOtherDay();
        $this->moveSf1To($this->sf1TargetVenueId, $this->sf1TargetDay, $this->sf1TargetStart);
    }

    #[Then('c\'est refusé : une seule séance par jour')]
    public function cEstRefuseUneSeanceParJour(): void
    {
        if (422 !== $this->moveStatus || 'team_already_trains_that_day' !== ($this->moveJson['code'] ?? null)) {
            throw new RuntimeException(\sprintf('à 1 séance par jour, le déplacement vers un jour où SF1 s\'entraîne déjà doit être refusé (422 team_already_trains_that_day) — reçu %d : %s', $this->moveStatus, $this->encoded($this->moveJson)));
        }
        $error = (string) ($this->moveJson['error'] ?? '');
        if (!str_contains($error, 'une seule séance par jour')) {
            throw new RuntimeException(\sprintf('le refus doit dire « une seule séance par jour » — obtenu : « %s »', $error));
        }
    }

    #[When('j\'autorise 2 séances par jour pour SF1 et je refais ce déplacement vers un créneau qui ne chevauche pas l\'autre séance')]
    public function jAutoriseDeuxSeancesPourSf1EtJeRefais(): void
    {
        $teamId = $this->smallClubTeamIds['SF1'];
        $current = $this->apiGet(\sprintf('teams/%s', $teamId), $this->smallClubToken)['json'];
        $written = $this->apiPut(\sprintf('teams/%s', $teamId), [
            'name' => $current['name'] ?? 'SF1',
            'sportCategoryId' => $current['sportCategoryId'] ?? null,
            'priorityTierId' => $current['priorityTierId'] ?? null,
            'sessionsPerWeek' => $current['sessionsPerWeek'] ?? null,
            'level' => $current['level'] ?? null,
            'gender' => $current['gender'] ?? null,
            'maxSessionsPerDay' => 2,
        ], $this->smallClubToken);
        if (200 !== $written['status']) {
            throw new RuntimeException(\sprintf('autoriser 2 séances par jour à SF1 a répondu %d (200 attendu) — planning rouvert : %s', $written['status'], $this->encoded($written['json'])));
        }

        $this->moveSf1To($this->sf1TargetVenueId, $this->sf1TargetDay, $this->sf1TargetStart);
    }

    #[Then('il est accepté, et le geste nomme la concession : deux séances à moins de 2 h d\'écart')]
    public function ilEstAccepteAvecLaConcessionDEcart(): void
    {
        if (200 !== $this->moveStatus || true !== ($this->moveJson['valid'] ?? null)) {
            throw new RuntimeException(\sprintf('à 2 séances par jour, ce déplacement doit être accepté (200) — reçu %d : %s', $this->moveStatus, $this->encoded($this->moveJson)));
        }
        // La grille du petit club ne porte que 18:00, 19:30 et 21:00 (90 min) : deux séances le même
        // jour y sont toujours à moins de 2 h l'une de l'autre, la concession d'écart est donc due.
        $compromises = \is_array($this->moveJson['compromises'] ?? null) ? $this->moveJson['compromises'] : [];
        $gap = array_filter(
            $compromises,
            static fn (mixed $c): bool => \is_array($c)
                && 'same_day' === ($c['family'] ?? null)
                && 'broken' === ($c['effect'] ?? null)
                && str_contains((string) ($c['message'] ?? ''), 'à moins de 2 h d\'écart'),
        );
        if ([] === $gap) {
            throw new RuntimeException(\sprintf('le geste qui rapproche deux séances de SF1 doit nommer la concession d\'écart (famille same_day, « à moins de 2 h d\'écart ») — compromis : %s', $this->encoded($compromises)));
        }
    }

    #[When('je remets cette séance à sa case d\'origine')]
    public function jeRemetsLaSeanceASaCase(): void
    {
        $this->moveSf1To($this->sf1OriginVenueId, $this->sf1OriginDay, $this->sf1OriginStart);
    }

    #[Then('c\'est accepté, et SF1 n\'a plus qu\'une séance ce jour-là')]
    public function cEstAccepteEtSf1NaPlusQuUneSeance(): void
    {
        if (200 !== $this->moveStatus || true !== ($this->moveJson['valid'] ?? null)) {
            throw new RuntimeException(\sprintf('remettre la séance à sa case d\'origine doit être accepté (200) — reçu %d : %s', $this->moveStatus, $this->encoded($this->moveJson)));
        }
        $onTargetDay = (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\' AND day_of_week=%d',
                $this->socleVersionId,
                $this->smallClubTeamIds['SF1'],
                $this->sf1TargetDay,
            ),
            admin: true,
        );
        if (1 !== $onTargetDay) {
            throw new RuntimeException(\sprintf('après le geste inverse, SF1 devrait n\'avoir qu\'une séance le jour %d — obtenu %d', $this->sf1TargetDay, $onTargetDay));
        }
    }

    #[When('je régénère le planning de saison du petit club')]
    public function jeRegenere(): void
    {
        $launched = $this->apiPost(\sprintf('schedules/%s/regenerate', $this->socleVersionId), [], $this->smallClubToken);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('le déclenchement de la régénération a répondu %d (202 attendu)', $launched['status']));
        }
        $id = $launched['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException('la régénération n\'a pas rendu d\'identifiant de version');
        }
        $this->regeneratedId = $id;
        $this->finalStatus = $this->pollSmallClubUntilTerminal($this->regeneratedId);
    }

    #[Given('je règle « Les jeunes avant les grands » sur Ignorée')]
    public function jeRegleLOrdreDesAgesSurIgnoree(): void
    {
        $this->putAgeAscending('OFF');
    }

    #[Then('le récapitulatif annonce cette règle comme ignorée')]
    public function leRecapAnnonceLaRegleIgnoree(): void
    {
        $ignored = $this->recapIgnoredRules();
        // Exactement l'ordre des âges : « jours d'affilée », éteinte par défaut, n'est pas annoncée.
        if (['ageAscending'] !== $ignored) {
            throw new RuntimeException(\sprintf('le récapitulatif doit annoncer la seule règle « Les jeunes avant les grands » comme ignorée (ignoredRules = ["ageAscending"]) — obtenu : %s', $this->encoded($ignored)));
        }
    }

    #[When('je remets « Les jeunes avant les grands » sur Obligatoire')]
    public function jeRemetsLOrdreDesAgesSurObligatoire(): void
    {
        $this->putAgeAscending('HARD');
    }

    #[Then('le récapitulatif ne l\'annonce plus comme ignorée')]
    public function leRecapNeLAnnoncePlus(): void
    {
        $ignored = $this->recapIgnoredRules();
        if (\in_array('ageAscending', $ignored, true)) {
            throw new RuntimeException(\sprintf('remise sur Obligatoire, la règle ne doit plus être annoncée comme ignorée — ignoredRules : %s', $this->encoded($ignored)));
        }
    }

    #[When('j\'essaie de régler la présence d\'un salarié sur Obligatoire')]
    public function jEssaieDeRendreLeSalarieObligatoire(): void
    {
        $response = $this->apiPut('implicit_rule_settings/salarieDistribution', ['intensity' => 'HARD'], $this->smallClubToken);
        $this->ruleStatus = $response['status'];
        $this->ruleJson = $response['json'];
    }

    #[Then('c\'est refusé : « La présence d\'un salarié ne peut pas être obligatoire : choisissez Souhaitée ou Ignorée. »')]
    public function cEstRefuseLeSalarieNEstJamaisObligatoire(): void
    {
        $expected = 'La présence d\'un salarié ne peut pas être obligatoire : choisissez Souhaitée ou Ignorée.';
        if (422 !== $this->ruleStatus || !str_contains($this->encoded($this->ruleJson), $expected)) {
            throw new RuntimeException(\sprintf('régler la présence d\'un salarié sur Obligatoire doit être refusé (422, « %s ») — reçu %d : %s', $expected, $this->ruleStatus, $this->encoded($this->ruleJson)));
        }
    }

    #[Then('la régénération aboutit : le verrou tient sa case, la case réservée reste vide, SM1 n\'a aucune séance avant 20h30, et aucun signalement ne porte sur l\'ordre des âges')]
    public function toutTient(): void
    {
        if ('COMPLETED' !== $this->finalStatus) {
            throw new RuntimeException(\sprintf('la régénération aurait dû aboutir (COMPLETED), obtenu « %s »', $this->finalStatus));
        }

        // 1. Le verrou tient : la séance verrouillée est à la même case (gymnase, jour, heure).
        $lockedStillThere = (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\' AND venue_id=\'%s\' AND day_of_week=%d AND to_char(start_time, \'HH24:MI\')=\'%s\' AND lock_level=\'HARD\'',
                $this->regeneratedId,
                $this->lockTeamId,
                $this->lockVenueId,
                $this->lockDay,
                $this->lockStartTime,
            ),
            admin: true,
        );
        if ($lockedStillThere < 1) {
            throw new RuntimeException('la séance verrouillée n\'est plus à sa case après régénération — le verrou n\'a pas tenu');
        }

        // 2. La case réservée reste vide : aucune séance n'y est placée (le créneau libre a retiré la place).
        $onReserved = (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND venue_id=\'%s\' AND day_of_week=%d AND to_char(start_time, \'HH24:MI\')=\'%s\'',
                $this->regeneratedId,
                $this->reservedVenueId,
                $this->reservedDay,
                $this->reservedStart,
            ),
            admin: true,
        );
        if (0 !== $onReserved) {
            throw new RuntimeException('une équipe a été placée sur la case réservée — le créneau libre n\'a pas retiré la place');
        }

        // 3. La contrainte est honorée : SM1 a des séances, et aucune avant 20h30.
        $sm1Count = (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\'', $this->regeneratedId, $this->smallClubTeamIds['SM1']),
            admin: true,
        );
        if ($sm1Count < 1) {
            throw new RuntimeException('SM1 n\'a aucune séance : la contrainte serait honorée à vide, la preuve serait creuse');
        }
        $earliest = $this->dbalScalar(
            \sprintf('SELECT MIN(start_time)::text AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\'', $this->regeneratedId, $this->smallClubTeamIds['SM1']),
            admin: true,
        );
        if ($earliest < '20:30:00') {
            throw new RuntimeException(\sprintf('une séance de SM1 est placée à %s, avant l\'heure interdite 20:30', $earliest));
        }

        // 4. La règle ignorée n'est pas signalée. Peut être vrai par hasard (aucun écart d'âge à
        // signaler) : la preuve qu'elle n'est ni posée ni signalée vit au moteur
        // (`engine/tests/semantic/test_implicit_rules_adjustable.py`).
        $ageSignals = (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_diagnostic WHERE schedule_id=\'%s\' AND rule_key=\'ageAscending\'', $this->regeneratedId),
            admin: true,
        );
        if (0 !== $ageSignals) {
            throw new RuntimeException(\sprintf('la règle « Les jeunes avant les grands » est ignorée : aucun signalement ne doit la porter — %d trouvé(s)', $ageSignals));
        }
    }

    /**
     * « Périmé » (bannière « vos données ont changé ») ne s'affiche que pour une donnée qui peut
     * déplacer une séance : la couleur d'un gymnase et le niveau d'une équipe qu'aucune contrainte
     * ne vise ne partent pas au moteur. Témoin d'abord : juste après la régénération, le planning
     * n'est pas périmé — sans quoi la suite ne prouverait rien.
     */
    #[When('je change la couleur d\'un gymnase et le niveau de l\'équipe Loisir')]
    public function jeChangeLaCouleurEtLeNiveau(): void
    {
        if ($this->planIsStale()) {
            throw new RuntimeException('témoin : juste après la régénération, le planning ne devrait pas être périmé');
        }
        $this->putBack('venues', $this->smallClubVenueIds['Gymnase Nord'], ['color' => '#2563eb']);
        $this->putBack('teams', $this->smallClubTeamIds['Loisir'], ['level' => 'REGIONAL']);
    }

    #[Then('le planning n\'est pas signalé périmé')]
    public function lePlanningNEstPasPerime(): void
    {
        if ($this->planIsStale()) {
            throw new RuntimeException('le planning est signalé périmé alors que rien de ce qui a changé ne peut déplacer une séance');
        }
    }

    #[When('j\'ajoute une séance par semaine à l\'équipe Loisir')]
    public function jAjouteUneSeanceALoisir(): void
    {
        $this->putBack('teams', $this->smallClubTeamIds['Loisir'], ['sessionsPerWeek' => $this->smallClubTeamPlan()['Loisir']['sessionsPerWeek'] + 1]);
    }

    #[When('je la retire')]
    public function jeLaRetire(): void
    {
        $this->putBack('teams', $this->smallClubTeamIds['Loisir'], ['sessionsPerWeek' => $this->smallClubTeamPlan()['Loisir']['sessionsPerWeek']]);
    }

    #[When('je renomme l\'équipe Loisir')]
    public function jeRenommeLoisir(): void
    {
        $this->putBack('teams', $this->smallClubTeamIds['Loisir'], ['name' => 'Loisir Adultes']);
    }

    #[When('je lui rends son nom')]
    public function jeLuiRendsSonNom(): void
    {
        $this->putBack('teams', $this->smallClubTeamIds['Loisir'], ['name' => 'Loisir']);
    }

    /**
     * Un nom de contrainte modifié périme le planning, et lui rendre son nom efface le signal.
     * Ne prouve PAS que l'ordre des contraintes lu en base reste hors de l'empreinte (la contrainte
     * renommée reste la dernière en base, son ordre ne bouge pas) : ce filet-là est
     * `PruneUnreadSnapshotFieldsCommandTest::testAVersionWithConstraintsInAnotherOrderGetsTheSortedHash`.
     */
    #[When('je renomme une contrainte puis lui rends son nom')]
    public function jeRenommeUneContraintePuisLuiRendsSonNom(): void
    {
        $original = $this->apiGet(\sprintf('constraints/%s', $this->sm1ConstraintId), $this->smallClubToken);
        $name = $original['json']['name'] ?? null;
        if (!\is_string($name) || '' === $name) {
            throw new RuntimeException('la contrainte SM1 n\'a pas de nom à rétablir');
        }
        $this->putBack('constraints', $this->sm1ConstraintId, ['name' => $name . ' (renommée)']);
        if (!$this->planIsStale()) {
            throw new RuntimeException('témoin : renommer une contrainte devrait périmer le planning (le nom fait partie de l\'envoi)');
        }
        $this->putBack('constraints', $this->sm1ConstraintId, ['name' => $name]);
    }

    #[Then('il est signalé périmé')]
    public function ilEstSignalePerime(): void
    {
        if (!$this->planIsStale()) {
            throw new RuntimeException('le planning devrait être signalé périmé : la donnée changée part au moteur');
        }
    }

    #[Then('il ne l\'est plus')]
    #[Then('il ne l\'est toujours pas')]
    public function ilNeLEstPlus(): void
    {
        if ($this->planIsStale()) {
            throw new RuntimeException('le planning est encore signalé périmé alors que la donnée est revenue à sa valeur');
        }
    }

    /**
     * Les deux séances (libres, l'équipe n'a pas de coach) de U20M1 au socle, triées par jour : la première reçoit aussi
     * une réservation à sa propre case, le geste « je réserve ce qui est bien placé ».
     */
    #[When('je verrouille les deux séances de U20M1 et lui réserve la case de la première')]
    public function jeVerrouilleLesDeuxSeancesEtReserveLaPremiere(): void
    {
        $raw = $this->dbalScalar(
            \sprintf(
                'SELECT string_agg(row, \';\' ORDER BY day_of_week, start_time) AS behatval FROM ('
                . ' SELECT day_of_week, start_time, id || \'|\' || venue_id || \'|\' || day_of_week || \'|\' || to_char(start_time, \'HH24:MI\') AS row'
                . ' FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\' AND lock_level=\'NONE\''
                . ') q',
                $this->socleVersionId,
                $this->smallClubTeamIds[self::COUNTED_TEAM],
            ),
            admin: true,
        );
        $rows = '' === $raw ? [] : explode(';', $raw);
        if (2 !== \count($rows)) {
            throw new RuntimeException(\sprintf('décor : %s devrait avoir deux séances libres au socle (trouvées : %d)', self::COUNTED_TEAM, \count($rows)));
        }
        foreach ($rows as $row) {
            [$id, $venueId, $day, $start] = explode('|', $row);
            $this->countedSessions[] = ['id' => $id, 'venueId' => $venueId, 'day' => (int) $day, 'start' => $start];
            $this->setLock($id, 'HARD');
        }
        $first = $this->countedSessions[0];
        $this->countedReservationIds[] = $this->reserveForCountedTeam($first['venueId'], $first['day'], $first['start']);
    }

    /** Une case de la grille de saison, un jour où U20M1 ne s'entraîne pas, vide au socle et sans réservation. */
    #[When('je lui réserve un second créneau un jour où elle ne s\'entraîne pas')]
    public function jeLuiReserveUnSecondCreneau(): void
    {
        $row = $this->dbalScalar(
            \sprintf(
                'SELECT vts.venue_id || \'|\' || vts.day_of_week || \'|\' || to_char(vts.start_time, \'HH24:MI\') AS behatval'
                . ' FROM venue_training_slot vts'
                . ' WHERE vts.club_id=\'%1$s\' AND vts.schedule_plan_id IS NULL AND vts.day_of_week NOT IN (%3$d, %4$d)'
                . ' AND NOT EXISTS (SELECT 1 FROM schedule_slot_template s WHERE s.schedule_id=\'%2$s\''
                . ' AND s.venue_id=vts.venue_id AND s.day_of_week=vts.day_of_week AND s.start_time=vts.start_time)'
                . ' AND NOT EXISTS (SELECT 1 FROM reservation r WHERE r.club_id=\'%1$s\' AND r.schedule_plan_id IS NULL'
                . ' AND r.venue_id=vts.venue_id AND r.day_of_week=vts.day_of_week AND r.start_time=vts.start_time)'
                . ' ORDER BY vts.day_of_week, vts.start_time, vts.venue_id LIMIT 1',
                $this->smallClubId,
                $this->socleVersionId,
                $this->countedSessions[0]['day'],
                $this->countedSessions[1]['day'],
            ),
            admin: true,
        );
        $parts = '' === $row ? [] : explode('|', $row);
        if (3 !== \count($parts)) {
            throw new RuntimeException(\sprintf('décor : aucune case vide un jour où %s ne s\'entraîne pas', self::COUNTED_TEAM));
        }
        $this->countedReservationIds[] = $this->reserveForCountedTeam($parts[0], (int) $parts[1], $parts[2]);
    }

    #[Then('le récapitulatif ne bloque pas')]
    public function leRecapitulatifNeBloquePas(): void
    {
        $blockers = $this->recapBlockers();
        if ([] !== $blockers) {
            throw new RuntimeException(\sprintf('le récapitulatif ne devrait rien bloquer — blocages : %s', $this->encoded($blockers)));
        }
    }

    /**
     * Le motif nomme la seule séance verrouillée comptée (la seconde : jour, heures, gymnase), jamais celle posée sur la
     * réservation ; les réservations ne sont pas nommées.
     */
    #[Then('le récapitulatif bloque U20M1 et nomme sa seule séance verrouillée hors réservation')]
    public function leRecapBloqueEtNommeLaSeanceVerrouillee(): void
    {
        $this->countedBlocker = $this->countedTeamBlocker();
        $named = $this->countedSessions[1];
        $venueName = (string) array_search($named['venueId'], $this->smallClubVenueIds, true);
        $hours = \sprintf('%s-%s', $named['start'], date('H:i', (int) strtotime($named['start'] . ' +90 minutes')));
        foreach ([self::DAY_NAMES[$named['day']], $hours, $venueName] as $expected) {
            if (!str_contains($this->countedBlocker, $expected)) {
                throw new RuntimeException(\sprintf('le motif doit nommer la séance verrouillée hors réservation (« %s » attendu) — obtenu : « %s »', $expected, $this->countedBlocker));
            }
        }
        $silent = self::DAY_NAMES[$this->countedSessions[0]['day']];
        if (str_contains($this->countedBlocker, $silent)) {
            throw new RuntimeException(\sprintf('la séance verrouillée sur sa propre réservation (%s) ne compte pas : le motif ne doit pas la nommer — obtenu : « %s »', $silent, $this->countedBlocker));
        }
    }

    #[When('j\'essaie de régénérer le planning de saison du petit club')]
    public function jEssaieDeRegenerer(): void
    {
        $this->schedulesBefore = $this->countSmallClubSchedules();
        $response = $this->apiPost(\sprintf('schedules/%s/regenerate', $this->socleVersionId), [], $this->smallClubToken);
        $this->regenerateStatus = $response['status'];
        $this->regenerateJson = $response['json'];
    }

    #[Then('c\'est refusé par le même motif, sans nouvelle version')]
    public function cEstRefuseParLeMemeMotif(): void
    {
        if (422 !== $this->regenerateStatus || true !== ($this->regenerateJson['blocker'] ?? null)) {
            throw new RuntimeException(\sprintf('la régénération devait être refusée comme un blocage (422, blocker) — reçu %d : %s', $this->regenerateStatus, $this->encoded($this->regenerateJson)));
        }
        if ($this->countedBlocker !== ($this->regenerateJson['error'] ?? null)) {
            throw new RuntimeException(\sprintf('le guichet doit dire le motif du Récap mot pour mot — Récap : « %s », guichet : %s', $this->countedBlocker, $this->encoded($this->regenerateJson)));
        }
        $after = $this->countSmallClubSchedules();
        if ($this->schedulesBefore !== $after) {
            throw new RuntimeException(\sprintf('aucune version ne doit naître d\'un refus (%d avant, %d après)', $this->schedulesBefore, $after));
        }
    }

    #[When('j\'ôte le cadenas de la séance posée sur la réservation')]
    public function jOteLeCadenasDeLaSeanceReservee(): void
    {
        $this->setLock($this->countedSessions[0]['id'], 'NONE');
    }

    #[Then('le récapitulatif bloque toujours par le même motif')]
    public function leRecapBloqueToujoursParLeMemeMotif(): void
    {
        $now = $this->countedTeamBlocker();
        if ($this->countedBlocker !== $now) {
            throw new RuntimeException(\sprintf('le cadenas d\'une séance posée sur sa réservation ne compte pas : le motif ne doit pas changer — avant « %s », après « %s »', $this->countedBlocker, $now));
        }
    }

    #[When('j\'ôte le cadenas de la séance nommée')]
    public function jOteLeCadenasDeLaSeanceNommee(): void
    {
        $this->setLock($this->countedSessions[1]['id'], 'NONE');
    }

    #[When('je retire ses deux réservations')]
    public function jeRetireSesDeuxReservations(): void
    {
        foreach ($this->countedReservationIds as $reservationId) {
            $removed = $this->apiDelete(\sprintf('reservations/%s', $reservationId), $this->smallClubToken);
            if (!\in_array($removed['status'], [200, 204], true)) {
                throw new RuntimeException(\sprintf('le retrait d\'une réservation a répondu %d (204 attendu)', $removed['status']));
            }
        }
    }

    #[Then('U20M1 n\'a plus ni réservation ni séance verrouillée')]
    public function plusNiReservationNiVerrou(): void
    {
        $left = (int) $this->dbalScalar(
            \sprintf(
                'SELECT (SELECT COUNT(*) FROM reservation WHERE team_id=\'%1$s\') + (SELECT COUNT(*) FROM schedule_slot_template WHERE schedule_id=\'%2$s\' AND team_id=\'%1$s\' AND lock_level=\'HARD\') AS behatval',
                $this->smallClubTeamIds[self::COUNTED_TEAM],
                $this->socleVersionId,
            ),
            admin: true,
        );
        if (0 !== $left) {
            throw new RuntimeException(\sprintf('%s devrait être revenue à son état d\'arrivée — %d réservation(s) ou verrou(s) restant(s)', self::COUNTED_TEAM, $left));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    private function setLock(string $slotId, string $level): void
    {
        $response = $this->apiPost(\sprintf('schedule-slots/%s/manual-edit/lock', $slotId), ['lockLevel' => $level], $this->smallClubToken);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('le cadenas (%s) a répondu %d (200 attendu) : %s', $level, $response['status'], $this->encoded($response['json'])));
        }
    }

    private function reserveForCountedTeam(string $venueId, int $day, string $start): string
    {
        $created = $this->apiPost('reservations', [
            'teamId' => $this->smallClubTeamIds[self::COUNTED_TEAM],
            'venueId' => $venueId,
            'dayOfWeek' => $day,
            'startTime' => $start,
            'durationMinutes' => 90,
            'schedulePlanId' => null,
        ], $this->smallClubToken);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la réservation de %s a répondu %d (201 attendu) : %s', self::COUNTED_TEAM, $created['status'], $this->encoded($created['json'])));
        }

        return $this->smallClubStringField($created['json'], 'id');
    }

    /**
     * Les blocages du Récap de saison (corps 200 comme 422).
     *
     * @return list<string>
     */
    private function recapBlockers(): array
    {
        $recap = $this->apiPost('constraints/validate', [], $this->smallClubToken);
        if (!\in_array($recap['status'], [200, 422], true) || !\is_array($recap['json']['blockers'] ?? null)) {
            throw new RuntimeException(\sprintf('le récapitulatif doit répondre 200 ou 422 avec la liste blockers — reçu %d : %s', $recap['status'], $this->encoded($recap['json'])));
        }

        return array_values(array_map(strval(...), $recap['json']['blockers']));
    }

    /** Le seul blocage du Récap qui nomme l'équipe comptée. */
    private function countedTeamBlocker(): string
    {
        $blockers = $this->recapBlockers();
        $mine = array_values(array_filter($blockers, static fn (string $blocker): bool => str_contains($blocker, self::COUNTED_TEAM)));
        if (1 !== \count($mine)) {
            throw new RuntimeException(\sprintf('le récapitulatif devrait bloquer %s par un motif, un seul — blocages : %s', self::COUNTED_TEAM, $this->encoded($blockers)));
        }

        return $mine[0];
    }

    private function countSmallClubSchedules(): int
    {
        return (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
    }

    /**
     * Le signal de l'écran Planning : l'empreinte COURANTE du plan (`structure-hash`) diffère du
     * `snapshotHash` de la version régénérée.
     */
    private function planIsStale(): bool
    {
        $version = $this->apiGet(\sprintf('schedules/%s', $this->regeneratedId), $this->smallClubToken);
        $planId = $version['json']['schedulePlanId'] ?? null;
        $snapshotHash = $version['json']['snapshotHash'] ?? null;
        if (!\is_string($planId) || !\is_string($snapshotHash)) {
            throw new RuntimeException(\sprintf('la version régénérée ne porte ni plan ni empreinte (HTTP %d)', $version['status']));
        }
        $current = $this->apiGet(\sprintf('schedule_plans/%s/structure-hash', $planId), $this->smallClubToken);
        $currentHash = $current['json']['currentStructureHash'] ?? null;
        if (200 !== $current['status'] || !\is_string($currentHash)) {
            throw new RuntimeException(\sprintf('l\'empreinte courante du plan est illisible (HTTP %d)', $current['status']));
        }

        return $currentHash !== $snapshotHash;
    }

    /**
     * Le geste de l'écran d'édition : relire la ressource, changer un champ, tout renvoyer (PUT).
     *
     * @param array<string, mixed> $changes
     */
    private function putBack(string $resource, string $id, array $changes): void
    {
        $current = $this->apiGet(\sprintf('%s/%s', $resource, $id), $this->smallClubToken);
        if (200 !== $current['status']) {
            throw new RuntimeException(\sprintf('lecture de %s/%s refusée (HTTP %d)', $resource, $id, $current['status']));
        }
        $updated = $this->apiPut(\sprintf('%s/%s', $resource, $id), [...$current['json'], ...$changes], $this->smallClubToken);
        if (200 !== $updated['status']) {
            throw new RuntimeException(\sprintf('modification de %s/%s refusée (HTTP %d) — corps : %s', $resource, $id, $updated['status'], json_encode($updated['json'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
        }
    }

    /**
     * Choisit, dans le socle, une séance LIBRE de SF1 et une case vide du jour de son AUTRE séance :
     * une autre heure (90 min fixes, donc aucun chevauchement), ni réservée ni occupée, où l'ordre des
     * âges tient (aucune plus jeune après elle, aucune plus âgée avant elle, dans ce gymnase ce jour-là :
     * le geste ne doit pas être refusé pour une autre raison), le gymnase de l'autre séance d'abord (aucun trajet). Déterministe.
     */
    private function pickSf1MoveTowardsItsOtherDay(): void
    {
        $row = $this->dbalScalar(
            \sprintf(
                // Une seule requête SELECT (dbal:run-sql n'exécute pas un WITH comme une lecture).
                'SELECT m.id || \'|\' || m.venue_id || \'|\' || m.day_of_week || \'|\' || to_char(m.start_time, \'HH24:MI\')'
                . ' || \'|\' || vts.venue_id || \'|\' || vts.day_of_week || \'|\' || to_char(vts.start_time, \'HH24:MI\') AS behatval'
                . ' FROM schedule_slot_template m'
                . ' JOIN schedule_slot_template o ON o.schedule_id = m.schedule_id AND o.team_id = m.team_id'
                . ' AND o.id <> m.id AND o.day_of_week <> m.day_of_week'
                . ' JOIN venue_training_slot vts ON vts.club_id=\'%3$s\' AND vts.schedule_plan_id IS NULL'
                . ' AND vts.day_of_week = o.day_of_week AND vts.start_time <> o.start_time'
                . ' WHERE m.schedule_id=\'%1$s\' AND m.team_id=\'%2$s\' AND m.lock_level=\'NONE\''
                . ' AND NOT EXISTS (SELECT 1 FROM schedule_slot_template s WHERE s.schedule_id=\'%1$s\''
                . ' AND s.venue_id=vts.venue_id AND s.day_of_week=vts.day_of_week AND s.start_time=vts.start_time)'
                . ' AND NOT EXISTS (SELECT 1 FROM schedule_slot_template s'
                . ' JOIN team t ON t.id = s.team_id JOIN sport_category c ON c.id = t.sport_category_id'
                . ' JOIN team me ON me.id = \'%2$s\' JOIN sport_category mc ON mc.id = me.sport_category_id'
                . ' WHERE s.schedule_id=\'%1$s\' AND s.team_id <> \'%2$s\''
                . ' AND s.venue_id=vts.venue_id AND s.day_of_week=vts.day_of_week'
                . ' AND c.age_min IS NOT NULL AND mc.age_min IS NOT NULL'
                . ' AND ((c.age_min < mc.age_min AND s.start_time > vts.start_time) OR (c.age_min > mc.age_min AND s.start_time < vts.start_time)))'
                . ' AND NOT EXISTS (SELECT 1 FROM reservation r WHERE r.club_id=\'%3$s\' AND r.schedule_plan_id IS NULL'
                . ' AND r.venue_id=vts.venue_id AND r.day_of_week=vts.day_of_week AND r.start_time=vts.start_time)'
                . ' ORDER BY (vts.venue_id = o.venue_id) DESC, vts.start_time DESC, m.id, vts.venue_id LIMIT 1',
                $this->socleVersionId,
                $this->smallClubTeamIds['SF1'],
                $this->smallClubId,
            ),
            admin: true,
        );
        $parts = '' === $row ? [] : explode('|', $row);
        if (7 !== \count($parts)) {
            $sf1 = $this->dbalScalar(
                \sprintf(
                    'SELECT COALESCE(string_agg(day_of_week || \' \' || to_char(start_time, \'HH24:MI\') || \' \' || lock_level || \' \' || venue_id, \' ; \'), \'\') AS behatval'
                    . ' FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\'',
                    $this->socleVersionId,
                    $this->smallClubTeamIds['SF1'],
                ),
                admin: true,
            );
            throw new RuntimeException(\sprintf('aucune case vide le jour de l\'autre séance de SF1 sur le petit club — décor à revoir (séances de SF1 : %s ; requête : « %s »)', $sf1, $row));
        }
        [$this->sf1SlotId, $this->sf1OriginVenueId, $originDay, $this->sf1OriginStart, $this->sf1TargetVenueId, $targetDay, $this->sf1TargetStart] = $parts;
        $this->sf1OriginDay = (int) $originDay;
        $this->sf1TargetDay = (int) $targetDay;
    }

    private function moveSf1To(string $venueId, int $day, string $start): void
    {
        $response = $this->apiPost(\sprintf('schedule-slots/%s/move', $this->sf1SlotId), [
            'venueId' => $venueId,
            'dayOfWeek' => $day,
            'startTime' => $start,
        ], $this->smallClubToken);
        $this->moveStatus = $response['status'];
        $this->moveJson = $response['json'];
    }

    private function putAgeAscending(string $intensity): void
    {
        $written = $this->apiPut('implicit_rule_settings/ageAscending', ['intensity' => $intensity], $this->smallClubToken);
        if (200 !== $written['status']) {
            throw new RuntimeException(\sprintf('régler « Les jeunes avant les grands » sur %s a répondu %d (200 attendu) : %s', $intensity, $written['status'], $this->encoded($written['json'])));
        }
    }

    /**
     * Les règles que le Récap annonce comme ignorées (`ignoredRules`, corps 200 comme 422).
     *
     * @return list<mixed>
     */
    private function recapIgnoredRules(): array
    {
        $recap = $this->apiPost('constraints/validate', [], $this->smallClubToken);
        if (!\in_array($recap['status'], [200, 422], true) || !\is_array($recap['json']['ignoredRules'] ?? null)) {
            throw new RuntimeException(\sprintf('le récapitulatif doit répondre 200 ou 422 avec la liste ignoredRules — reçu %d : %s', $recap['status'], $this->encoded($recap['json'])));
        }

        return array_values($recap['json']['ignoredRules']);
    }

    private function encoded(mixed $value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
