<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Fermetures de gymnase, « Enregistrer vaut geste » — la preuve du VRAI solve (plan fermetures, §H
 * ligne Behat), sur le PETIT CLUB réaliste ({@see SmallClubFixtureTrait}, palier 60 s), par les
 * gestes réels de l'API : un plan de période généré, deux séances verrouillées en dur sur deux JOURS
 * différents ; le gestionnaire déclare la fermeture du gymnase de la première, le jour de cette
 * séance (entrée `closure` + contrainte `venue_closed` attachée, comme `cockpit/queries.ts`) ; le
 * verrou de ce jour-là part, le plan devient périmé ; la régénération aboutit SANS séance à ce
 * gymnase ce jour-là, et le verrou de l'AUTRE jour revient HARD à la même case.
 *
 * Jumeaux PHPUnit (gates) : `Security/ClosureRemovesPinsAtomicallyTest` (le retrait ligne à ligne),
 * `Security/StalePeriodPlanRegenerationTest` (payload sans le gymnase, 409 avant Rouvrir, 202 après).
 * Ce context ne prouve que ce qu'eux ne peuvent pas : le moteur réel honore le retrait.
 *
 * Servi par deux suites : `overlay` (plan VALIDÉ : périmé → Rouvrir → Régénérer) et
 * `verrou-periode` (le verrou d'un autre jour survit à une fermeture).
 */
final class ClosurePinsSmallClubContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $periodPlanId = '';

    private string $versionId = '';

    /** @var array{teamId: string, venueId: string, day: int, start: string, slotId: string} */
    private array $closedCell = ['teamId' => '', 'venueId' => '', 'day' => 0, 'start' => '', 'slotId' => ''];

    /** @var array{teamId: string, venueId: string, day: int, start: string, slotId: string} */
    private array $keptCell = ['teamId' => '', 'venueId' => '', 'day' => 0, 'start' => '', 'slotId' => ''];

    private string $windowStart = '';

    private string $hashBeforeClosure = '';

    private string $regeneratedId = '';

    private string $regeneratedStatus = '';

    #[Given('un petit club réaliste avec un plan de période dont deux séances sont verrouillées en dur sur deux jours différents')]
    public function unPlanDePeriodeAvecDeuxVerrous(): void
    {
        $this->buildPlanWithTwoLocks(validate: false);
    }

    #[Given('un petit club réaliste avec un plan de période VALIDÉ dont deux séances sont verrouillées en dur sur deux jours différents')]
    public function unPlanDePeriodeValideAvecDeuxVerrous(): void
    {
        $this->buildPlanWithTwoLocks(validate: true);
    }

    #[When('je déclare la fermeture du gymnase de la première séance verrouillée, le jour de cette séance')]
    public function jeDeclareLaFermeture(): void
    {
        $this->hashBeforeClosure = $this->currentStructureHash();
        $date = date('Y-m-d', (int) strtotime(\sprintf('%s +%d days', $this->windowStart, $this->closedCell['day'] - 1)));

        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period', 'periodType' => 'closure', 'title' => 'Gymnase fermé (mairie)', 'startDate' => $date, 'endDate' => $date,
        ], $this->smallClubToken);
        if (!\in_array($entry['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('déclaration de l\'entrée de fermeture refusée (HTTP %d)', $entry['status']));
        }
        $constraint = $this->apiPost('constraints', [
            'name' => 'Gymnase fermé (mairie)', 'scope' => 'FACILITY', 'scopeTargetId' => $this->closedCell['venueId'],
            'family' => 'FACILITY', 'ruleType' => 'HARD',
            'config' => ['type' => 'venue_closed', 'startDate' => $date, 'endDate' => $date],
            'calendarEntryId' => $this->smallClubStringField($entry['json'], 'id'),
        ], $this->smallClubToken);
        if (!\in_array($constraint['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la fermeture du gymnase a été refusée (HTTP %d) — %s', $constraint['status'], json_encode($constraint['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('le verrou de ce jour-là est retiré, celui de l\'autre jour est intact, et le plan de période est signalé périmé')]
    public function leVerrouEstRetireEtLePlanPerime(): void
    {
        if ('HARD' === $this->lockLevelOf($this->closedCell['slotId'])) {
            throw new RuntimeException('le verrou au gymnase fermé, ce jour-là, aurait dû être retiré par la déclaration de la fermeture');
        }
        if ('HARD' !== $this->lockLevelOf($this->keptCell['slotId'])) {
            throw new RuntimeException('le verrou de l\'autre jour aurait dû rester HARD');
        }
        if ($this->hashBeforeClosure === $this->currentStructureHash()) {
            throw new RuntimeException('le plan de période aurait dû être signalé périmé (son empreinte de structure aurait dû changer)');
        }
    }

    #[When('je rouvre puis régénère le plan de période')]
    public function jeRouvrePuisRegenere(): void
    {
        $reopened = $this->apiPost(\sprintf('schedules/%s/reopen', $this->versionId), [], $this->smallClubToken);
        if (200 !== $reopened['status']) {
            throw new RuntimeException(\sprintf('« Rouvrir » a répondu %d (200 attendu)', $reopened['status']));
        }
        $this->jeRegenereApresLaFermeture();
    }

    #[When('je régénère le plan de période après la fermeture')]
    public function jeRegenereApresLaFermeture(): void
    {
        $overlay = $this->generateSmallClubPeriodVersion($this->periodPlanId);
        $this->regeneratedId = $overlay['id'];
        $this->regeneratedStatus = $overlay['status'];
    }

    #[Then('la régénération aboutit sans aucune séance à ce gymnase ce jour-là, et la séance verrouillée de l\'autre jour revient verrouillée à la même case')]
    public function laRegenerationAboutitSansLeGymnase(): void
    {
        if ('COMPLETED' !== $this->regeneratedStatus) {
            throw new RuntimeException(\sprintf('la régénération aurait dû aboutir (COMPLETED), obtenu « %s »', $this->regeneratedStatus));
        }
        $atClosed = (int) $this->dbalScalar(\sprintf(
            'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND venue_id=\'%s\' AND day_of_week=%d',
            $this->regeneratedId,
            $this->closedCell['venueId'],
            $this->closedCell['day'],
        ), admin: true);
        if (0 !== $atClosed) {
            throw new RuntimeException(\sprintf('aucune séance ne devrait être placée au gymnase fermé ce jour-là (trouvées : %d)', $atClosed));
        }
        $kept = $this->keptCell;
        if (!$this->smallClubSessionsStillLockedAt($this->regeneratedId, [['teamId' => $kept['teamId'], 'venueId' => $kept['venueId'], 'day' => $kept['day'], 'start' => $kept['start']]])) {
            throw new RuntimeException('la séance verrouillée de l\'autre jour aurait dû revenir VERROUILLÉE (HARD) à la même case');
        }
        // Anti-vide : la version régénérée place bien des séances (sinon « zéro au gymnase fermé » serait gratuit).
        $total = (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\'', $this->regeneratedId), admin: true);
        if ($total < 2) {
            throw new RuntimeException(\sprintf('la version régénérée devrait porter des séances (trouvées : %d)', $total));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    /**
     * Petit club + socle validé + plan de période (fermeture « d'un bloc » lun→ven, sans gymnase) +
     * version overlay générée ; deux séances libres de jours DIFFÉRENTS (lun→ven) verrouillées HARD.
     */
    private function buildPlanWithTwoLocks(bool $validate): void
    {
        $this->buildSmallClub();
        $this->generateAndValidateSmallClubSocle();

        $this->windowStart = $this->smallClubFutureMonday(49);
        $end = date('Y-m-d', (int) strtotime($this->windowStart . ' +4 days'));
        $entryId = $this->createSmallClubClosureEntry($this->windowStart, $end, 'Période de travail — fermetures (petit club)');
        $this->periodPlanId = $this->openSmallClubPeriodPlan($entryId);
        $overlay = $this->generateSmallClubPeriodVersion($this->periodPlanId);
        if ('COMPLETED' !== $overlay['status']) {
            throw new RuntimeException(\sprintf('la version de période aurait dû aboutir (COMPLETED), obtenu « %s »', $overlay['status']));
        }
        $this->versionId = $overlay['id'];

        $this->closedCell = $this->freeSession('');
        $this->keptCell = $this->freeSession((string) $this->closedCell['day']);
        foreach ([$this->closedCell, $this->keptCell] as $cell) {
            $locked = $this->apiPost(\sprintf('schedule-slots/%s/manual-edit/lock', $cell['slotId']), ['lockLevel' => 'HARD'], $this->smallClubToken);
            if (200 !== $locked['status']) {
                throw new RuntimeException(\sprintf('le verrouillage d\'une séance a répondu %d (200 attendu)', $locked['status']));
            }
        }
        if ($validate) {
            $this->validateSmallClubSchedule($this->versionId);
        }
    }

    /**
     * Une séance LIBRE (NONE) de la version, un jour ouvré (1..5, dans la fenêtre lun→ven), d'un
     * jour différent de `$otherThanDay` si donné.
     *
     * @return array{teamId: string, venueId: string, day: int, start: string, slotId: string}
     */
    private function freeSession(string $otherThanDay): array
    {
        $raw = $this->dbalScalar(\sprintf(
            'SELECT id || \'|\' || team_id || \'|\' || venue_id || \'|\' || day_of_week || \'|\' || to_char(start_time, \'HH24:MI\') AS behatval'
            . ' FROM schedule_slot_template WHERE schedule_id=\'%s\' AND lock_level=\'NONE\' AND day_of_week BETWEEN 1 AND 5%s ORDER BY id LIMIT 1',
            $this->versionId,
            '' === $otherThanDay ? '' : ' AND day_of_week <> ' . (int) $otherThanDay,
        ), admin: true);
        $cols = explode('|', $raw);
        if (5 !== \count($cols)) {
            throw new RuntimeException('la version de période devait porter une séance libre un jour ouvré (décor)');
        }

        return ['slotId' => $cols[0], 'teamId' => $cols[1], 'venueId' => $cols[2], 'day' => (int) $cols[3], 'start' => $cols[4]];
    }

    private function lockLevelOf(string $slotId): string
    {
        return $this->dbalScalar(\sprintf('SELECT lock_level AS behatval FROM schedule_slot_template WHERE id=\'%s\'', $slotId), admin: true);
    }

    private function currentStructureHash(): string
    {
        $served = $this->apiGet(\sprintf('schedule_plans/%s/structure-hash', $this->periodPlanId), $this->smallClubToken);
        if (200 !== $served['status']) {
            throw new RuntimeException(\sprintf('l\'empreinte du plan a répondu %d (200 attendu)', $served['status']));
        }
        $hash = $served['json']['currentStructureHash'] ?? null;

        return \is_string($hash) ? $hash : '';
    }
}
