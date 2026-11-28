<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * « Valider ces modifications », sans régénérer (lot conception 5, PR 6), sur la stack qui tourne, moteur réel.
 *
 * Prouve : le geste fait valoir sur le planning en vigueur un changement fait hors du planning (le coach principal),
 * sans déplacer aucune séance ni faire naître de version ; les séances que tenait l'ancien coach principal sont tenues
 * par le nouveau, que les conflits de personne du planning en vigueur comptent ; le froid changé supprime les plannings
 * de période pas commencés, annoncés avant ; rien n'attend plus ; « Retravailler » juste après donne une copie de
 * travail à jour. Avant confirmation, le contrôle d'incohérences dit ce que le changement casse sur le planning en
 * vigueur (règle cassée, séances manquantes) et laisse valider.
 *
 * Décor : `SmallClubFixtureTrait` (11 équipes, 3 gymnases, palier 60 s), plus un coach, Eden, principal des U13F1, et
 * deux réservations au même moment, lundi 18:00 : U13M1 au Gymnase Centre, U13F1 au Gymnase Nord. Le socle est généré
 * et validé ; confier les U13M1 à Eden la met à deux endroits à la fois. Purge canonique en fin de scénario.
 *
 * Les cas nombreux du contrôle (table des séances touchées, découpage par 50, refus et délai du moteur) :
 * `Integration/Plans/InForceCoherenceTest` ; ce que le geste écrit, colonne par colonne :
 * `Security/LoadedVersionIsTheOnlyEditableTest`. « Revenir au planning en vigueur » qui abandonne les modifications :
 * `le-socle-commande-les-plans.feature`, scénario « L'écran sait quand des modifications attendent la validation ».
 */
final class PendingChangesApplyContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $planId = '';

    private string $inForceId = '';

    private string $futureEntryId = '';

    /** Le coach qui tenait les U13M1 dans le planning en vigueur (Camille), et celui qui les reprend. */
    private string $formerCoachId = '';

    private string $successorCoachId = '';

    /** @var array<mixed> la dernière annonce lue (`GET /api/schedule_plans/{id}/pending-impact`) */
    private array $impact = [];

    /** Les séances du planning en vigueur avant le geste : `id|équipe|gymnase|jour|heure|durée|verrou`, triées. */
    private string $sessionsBefore = '';

    private string $versionsBefore = '';

    private string $workCopyId = '';

    #[Given('un petit club dont le planning de saison est en vigueur, Eden coach principal des U13F1')]
    public function unPetitClubAvecEden(): void
    {
        $this->buildSmallClub();
        $eden = $this->apiPost('coaches', ['firstName' => 'Eden'], $this->smallClubToken);
        if (!\in_array($eden['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création du coach Eden refusée (HTTP %d)', $eden['status']));
        }
        $this->smallClubCoachIds['Eden'] = $this->smallClubStringField($eden['json'], 'id');
        $this->assignSmallClubCoach('Eden', 'U13F1');
        foreach ([['U13M1', 'Gymnase Centre'], ['U13F1', 'Gymnase Nord']] as [$team, $venue]) {
            $reserved = $this->apiPost('reservations', [
                'teamId' => $this->smallClubTeamIds[$team], 'venueId' => $this->smallClubVenueIds[$venue],
                'dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'schedulePlanId' => null,
            ], $this->smallClubToken);
            if (!\in_array($reserved['status'], [200, 201], true)) {
                throw new RuntimeException(\sprintf('réservation de %s refusée (HTTP %d) — %s', $team, $reserved['status'], json_encode($reserved['json'], \JSON_UNESCAPED_UNICODE)));
            }
        }
        $this->inForceId = $this->generateAndValidateSmallClubSocle();
        $this->planId = $this->dbalScalar(\sprintf('SELECT id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->smallClubId), admin: true);

        foreach (['U13M1', 'U13F1'] as $team) {
            $atMonday = $this->dbalScalar(
                \sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\' AND day_of_week=1 AND start_time=\'18:00\'', $this->inForceId, $this->smallClubTeamIds[$team]),
                admin: true,
            );
            if ('1' !== $atMonday) {
                throw new RuntimeException(\sprintf('témoin : la séance réservée des %s devrait être au planning en vigueur, lundi 18:00', $team));
            }
        }
    }

    #[Given('un planning de période à venir')]
    public function unPlanningDePeriodeAVenir(): void
    {
        $start = $this->smallClubFutureMonday(105);
        $this->futureEntryId = $this->createSmallClubClosureEntry($start, date('Y-m-d', (int) strtotime($start . ' +4 days')), 'Fermeture à venir (valider ces modifications)');
        $this->openSmallClubPeriodPlan($this->futureEntryId);
    }

    #[Given('/^(?P<modification>le coach principal des U13M1 confié à Eden|le coach principal des U13M1 confié à un coach libre|les U13M1 passés à 3 séances), sans valider$/u')]
    public function uneModificationSansValider(string $modification): void
    {
        if ('les U13M1 passés à 3 séances' === $modification) {
            $this->writeU13m1SessionsPerWeek(3);

            return;
        }

        if ('le coach principal des U13M1 confié à Eden' === $modification) {
            $this->successorCoachId = $this->smallClubCoachIds['Eden'];
        } else {
            $free = $this->apiPost('coaches', ['firstName' => 'Fred'], $this->smallClubToken);
            if (!\in_array($free['status'], [200, 201], true)) {
                throw new RuntimeException(\sprintf('création du coach libre refusée (HTTP %d)', $free['status']));
            }
            $this->successorCoachId = $this->smallClubStringField($free['json'], 'id');
        }
        $this->formerCoachId = $this->smallClubCoachIds['Camille'];
        $link = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM team_coach WHERE team_id=\'%s\' AND coach_id=\'%s\' AND role=\'MAIN\'', $this->smallClubTeamIds['U13M1'], $this->formerCoachId),
            admin: true,
        );
        if ('' === $link) {
            throw new RuntimeException('témoin : Camille devrait être le coach principal des U13M1');
        }
        $removed = $this->apiDelete(\sprintf('team_coaches/%s', $link), $this->smallClubToken);
        if (204 !== $removed['status']) {
            throw new RuntimeException(\sprintf('retirer le coach principal des U13M1 a répondu %d (204 attendu)', $removed['status']));
        }
        $posted = $this->apiPost('team_coaches', ['teamId' => $this->smallClubTeamIds['U13M1'], 'coachId' => $this->successorCoachId, 'role' => 'MAIN'], $this->smallClubToken);
        if (!\in_array($posted['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('confier les U13M1 au nouveau coach principal a répondu %d', $posted['status']));
        }
        if ($this->placedConflictsName($this->successorCoachId)) {
            throw new RuntimeException('témoin : sans validation, les conflits du planning en vigueur ne comptent pas encore le nouveau coach des U13M1');
        }
    }

    #[When('je demande ce que change « Valider ces modifications »')]
    public function jeDemandeCeQueChangeValiderCesModifications(): void
    {
        $impact = $this->apiGet(\sprintf('schedule_plans/%s/pending-impact', $this->planId), $this->smallClubToken);
        if (200 !== $impact['status'] || !\is_string($impact['json']['changesToken'] ?? null)) {
            throw new RuntimeException(\sprintf('l\'annonce de « Valider ces modifications » a répondu %d, sans jeton de liste ? corps : %s', $impact['status'], json_encode($impact['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->impact = $impact['json'];
    }

    #[Then('l\'annonce nomme le planning de période à venir, qui sera supprimé')]
    public function lAnnonceNommeLePlanningDePeriodeAVenir(): void
    {
        $destroyed = array_column(\is_array($this->impact['destroyedPeriodPlans'] ?? null) ? $this->impact['destroyedPeriodPlans'] : [], 'entryId');
        if ([$this->futureEntryId] !== $destroyed) {
            throw new RuntimeException(\sprintf('un coach principal changé est du froid : l\'annonce devrait nommer le planning de période à venir ; lu : %s', json_encode($this->impact['destroyedPeriodPlans'] ?? null, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[When('je valide ces modifications')]
    public function jeValideCesModifications(): void
    {
        $this->sessionsBefore = $this->inForceSessions();
        $this->versionsBefore = $this->versionCount();
        $this->confirmApply();
    }

    #[Then('les séances du planning en vigueur sont à la même place, et aucune version n\'est née')]
    public function lesSeancesSontALaMemePlace(): void
    {
        if ($this->sessionsBefore !== $this->inForceSessions()) {
            throw new RuntimeException('valider ces modifications ne déplace, n\'ajoute, ne retire ni ne verrouille aucune séance du planning en vigueur');
        }
        if ($this->versionsBefore !== $this->versionCount()) {
            throw new RuntimeException(\sprintf('aucune version ne naît du geste : %s avant, %s après', $this->versionsBefore, $this->versionCount()));
        }
        $pointer = $this->dbalScalar(\sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE id=\'%s\'', $this->planId), admin: true);
        if ($pointer !== $this->inForceId) {
            throw new RuntimeException('le planning en vigueur reste la même version');
        }
    }

    #[Then('les séances des U13M1 sont tenues par Eden, que les conflits du planning en vigueur comptent')]
    public function lesSeancesDesU13m1SontTenuesParEden(): void
    {
        $holders = $this->dbalScalar(
            \sprintf('SELECT string_agg(DISTINCT COALESCE(coach_id::text, \'-\'), \',\') AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\'', $this->inForceId, $this->smallClubTeamIds['U13M1']),
            admin: true,
        );
        if ($holders !== $this->successorCoachId) {
            throw new RuntimeException(\sprintf('les séances des U13M1 que tenait Camille devraient nommer Eden ; lu : %s', $holders));
        }
        if (!$this->placedConflictsName($this->successorCoachId)) {
            throw new RuntimeException('Eden tient les U13M1 et les U13F1 lundi à 18:00 dans deux gymnases : les conflits du planning en vigueur devraient la compter');
        }
    }

    #[Then('le planning de période à venir a disparu')]
    public function lePlanningDePeriodeAVenirADisparu(): void
    {
        $count = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE calendar_entry_id=\'%s\'', $this->futureEntryId), admin: true);
        if ('0' !== $count) {
            throw new RuntimeException('du froid a changé : le planning de période à venir, annoncé, devrait être supprimé');
        }
    }

    #[Then('rien n\'attend plus la validation')]
    public function rienNAttendPlusLaValidation(): void
    {
        $pending = $this->apiGet(\sprintf('schedule_plans/%s/pending-changes', $this->planId), $this->smallClubToken);
        if (200 !== $pending['status'] || false !== ($pending['json']['pending'] ?? null)) {
            throw new RuntimeException(\sprintf('après le geste, rien ne devrait attendre la validation ; lu : %s', json_encode($pending['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[When('je retravaille le planning de saison')]
    public function jeRetravailleLePlanningDeSaison(): void
    {
        $reworked = $this->apiPost(\sprintf('schedules/%s/rework', $this->inForceId), [], $this->smallClubToken);
        if (201 !== $reworked['status']) {
            throw new RuntimeException(\sprintf('retravailler a répondu %d (201 attendu) — corps : %s', $reworked['status'], json_encode($reworked['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->workCopyId = $this->smallClubStringField($reworked['json'], 'id');
    }

    #[Then('la copie de travail est à jour')]
    public function laCopieDeTravailEstAJour(): void
    {
        $version = $this->apiGet(\sprintf('schedules/%s', $this->workCopyId), $this->smallClubToken);
        $current = $this->apiGet(\sprintf('schedule_plans/%s/structure-hash', $this->planId), $this->smallClubToken);
        $snapshotHash = $version['json']['snapshotHash'] ?? null;
        $currentHash = $current['json']['currentStructureHash'] ?? null;
        if (!\is_string($snapshotHash) || $snapshotHash !== $currentHash) {
            throw new RuntimeException(\sprintf('retravailler juste après le geste donne une copie à jour (empreinte de la copie = celle du plan) ; lu : %s contre %s', json_encode($snapshotHash), json_encode($currentHash)));
        }
    }

    #[Then('/^le contrôle annonce (?P<annonce>aucune incohérence|une incohérence sur la séance des U13M1 au même moment qu\'Eden, qui nomme sa règle|1 séance manquante pour les U13M1)$/u')]
    public function leControleAnnonce(string $annonce): void
    {
        $incoherences = array_values(array_filter(\is_array($this->impact['incoherences'] ?? null) ? $this->impact['incoherences'] : [], 'is_array'));
        $missing = array_values(array_filter(\is_array($this->impact['missingSessions'] ?? null) ? $this->impact['missingSessions'] : [], 'is_array'));
        $read = json_encode(['incoherences' => $incoherences, 'missingSessions' => $missing, 'checkInconclusive' => $this->impact['checkInconclusive'] ?? null], \JSON_UNESCAPED_UNICODE);
        if (false !== ($this->impact['checkInconclusive'] ?? null)) {
            throw new RuntimeException(\sprintf('le contrôle devrait conclure ; lu : %s', $read));
        }
        $u13m1 = $this->smallClubTeamIds['U13M1'];
        $ok = match ($annonce) {
            'aucune incohérence' => [] === $incoherences && [] === $missing,
            '1 séance manquante pour les U13M1' => [] === $incoherences && 1 === \count($missing) && $u13m1 === ($missing[0]['teamId'] ?? null) && 1 === ($missing[0]['count'] ?? null),
            default => [] !== array_filter($incoherences, fn (array $i): bool => $u13m1 === ($i['teamId'] ?? null) && 1 === ($i['dayOfWeek'] ?? null) && '18:00' === substr((string) ($i['startTime'] ?? ''), 0, 5)
                && \is_string($i['slotId'] ?? null) && \is_string($i['rule'] ?? null) && '' !== $i['rule'])
                && [] === array_filter($incoherences, static fn (array $i): bool => 1 !== ($i['dayOfWeek'] ?? null) || '18:00' !== substr((string) ($i['startTime'] ?? ''), 0, 5)),
        };
        if (!$ok) {
            throw new RuntimeException(\sprintf('le contrôle devrait annoncer « %s » ; lu : %s', $annonce, $read));
        }
    }

    #[Then('la validation reste possible')]
    public function laValidationRestePossible(): void
    {
        $this->confirmApply();
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    private function confirmApply(): void
    {
        $applied = $this->apiPost(\sprintf('schedule_plans/%s/apply-pending-changes', $this->planId), ['confirmChanges' => $this->impact['changesToken'] ?? null], $this->smallClubToken);
        if (200 !== $applied['status'] || ($applied['json']['id'] ?? null) !== $this->inForceId) {
            throw new RuntimeException(\sprintf('valider ces modifications, confirmé sur la liste annoncée, a répondu %d (200 et la version en vigueur attendus) — corps : %s', $applied['status'], json_encode($applied['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    private function writeU13m1SessionsPerWeek(int $sessions): void
    {
        $teamId = $this->smallClubTeamIds['U13M1'];
        $current = $this->apiGet(\sprintf('teams/%s', $teamId), $this->smallClubToken)['json'];
        $written = $this->apiPut(\sprintf('teams/%s', $teamId), [
            'name' => $current['name'] ?? 'U13M1',
            'sportCategoryId' => $current['sportCategoryId'] ?? null,
            'priorityTierId' => $current['priorityTierId'] ?? null,
            'sessionsPerWeek' => $sessions,
            'level' => $current['level'] ?? null,
            'gender' => $current['gender'] ?? null,
            'maxSessionsPerDay' => $current['maxSessionsPerDay'] ?? null,
        ], $this->smallClubToken);
        if (200 !== $written['status']) {
            throw new RuntimeException(\sprintf('passer les U13M1 à %d séances a répondu %d — corps : %s', $sessions, $written['status'], json_encode($written['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    /** Vrai si les conflits de personne du planning en vigueur (`GET /api/training/placed-conflicts`) nomment ce coach. */
    private function placedConflictsName(string $coachId): bool
    {
        $conflicts = $this->apiGet('training/placed-conflicts', $this->smallClubToken);
        if (200 !== $conflicts['status']) {
            throw new RuntimeException(\sprintf('lire les conflits du planning en vigueur a répondu %d', $conflicts['status']));
        }
        foreach (\is_array($conflicts['json']['conflicts'] ?? null) ? $conflicts['json']['conflicts'] : [] as $conflict) {
            if (\is_array($conflict) && $coachId === ($conflict['personId'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function inForceSessions(): string
    {
        return $this->dbalScalar(
            \sprintf(
                'SELECT string_agg(id || \'|\' || team_id || \'|\' || venue_id || \'|\' || day_of_week || \'|\' || to_char(start_time, \'HH24:MI\') || \'|\' || duration_minutes || \'|\' || lock_level, \';\' ORDER BY id) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\'',
                $this->inForceId,
            ),
            admin: true,
        );
    }

    private function versionCount(): string
    {
        return $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule WHERE schedule_plan_id=\'%s\'', $this->planId), admin: true);
    }
}
