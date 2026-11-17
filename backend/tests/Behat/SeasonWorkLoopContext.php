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
 * `engine/tests/semantic/test_validate_assignments_semantics.py`). Remplace, sur un décor léger (palier 60 s) :
 *   - `une-contrainte-saisie-est-honoree.feature:9` (une contrainte d'équipe honorée) ;
 *   - `un-verrou-est-souverain.feature:9` (une séance verrouillée reste à sa case après régénération) ;
 *   - `un-verrou-est-souverain.feature:31` (un créneau libre réservé retire la place au solveur).
 * (Les régénérations de PLAN DE PÉRIODE — :47/:57/:69 — et les edges :21/:40 restent dans
 * `un-verrou`, promesses distinctes du cycle de vie des plans de période, sans filet plus bas.)
 */
final class SeasonWorkLoopContext extends BaseContext
{
    use SmallClubFixtureTrait;

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

    #[Then('la régénération aboutit : le verrou tient sa case, la case réservée reste vide, et SM1 n\'a aucune séance avant 20h30')]
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
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
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

    private function encoded(mixed $value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
