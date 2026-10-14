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
 * et le verrou (la séance ne bouge pas). Remplace, sur un décor léger (palier 60 s) :
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
}
