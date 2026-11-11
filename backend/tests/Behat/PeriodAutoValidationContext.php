<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * P4-503 — un planning de période qu'aucun gestionnaire n'a validé 7 jours après le début de sa
 * période se valide seul (axe planning lifecycle, ADR-0002), de bout en bout sur le PETIT CLUB
 * réaliste ({@see SmallClubFixtureTrait}, palier 60 s) : socle généré et validé, fermeture adaptée
 * par l'API (deux versions réelles : la transcription du socle puis une génération, la première
 * « chargée »), puis la tâche quotidienne réelle (`app:period-plans:auto-validate`, console).
 *
 * Le décor ne sait pas fabriquer une période COMMENCÉE par l'API (une fenêtre datée dans le passé
 * n'est pas un geste de gestionnaire) : la fermeture est déclarée dans le futur, adaptée, générée,
 * PUIS reculée en SQL — `calendar_entry` ET `schedule_plan` ensemble, sinon les deux dates
 * divergent (pointage, seul usage du SQL admis par la charte).
 *
 * En local, le `cron-runner` balaie aussi toutes les minutes : il peut valider avant la commande
 * du scénario. Les assertions portent donc sur l'ÉTAT FINAL, jamais sur « c'est la commande qui
 * l'a fait ». En CI, `functional-tests` ne lance pas le `cron-runner`.
 *
 * Les règles fines (seuil J+7 et fuseaux, éligibilité, gardes sous verrou, isolation) sont
 * prouvées plus bas, dans le NR bloquant `Security/PeriodPlanAutoValidationTest`.
 */
final class PeriodAutoValidationContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $periodPlanId = '';

    private string $loadedVersionId = '';

    private string $otherVersionId = '';

    #[Given('un petit club réaliste dont le planning de saison est en vigueur, avec une fermeture adaptée portant deux versions terminées, la première chargée')]
    public function unPetitClubAvecUneFermetureADeuxVersions(): void
    {
        $this->buildSmallClub();
        $this->generateAndValidateSmallClubSocle();

        $start = $this->smallClubFutureMonday(49);
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));
        $entryId = $this->createSmallClubClosureEntry($start, $end, 'Fermeture — validation automatique (petit club)');
        $this->periodPlanId = $this->openSmallClubPeriodPlan($entryId);

        $this->loadedVersionId = $this->transcribeSmallClubSocleToPlan($this->periodPlanId);
        $generated = $this->generateSmallClubPeriodVersion($this->periodPlanId);
        if ('COMPLETED' !== $generated['status']) {
            throw new RuntimeException(\sprintf('la seconde version de la fermeture aurait dû aboutir (COMPLETED), obtenu « %s »', $generated['status']));
        }
        $this->otherVersionId = $generated['id'];

        $loaded = $this->apiPost(\sprintf('schedules/%s/load-locks', $this->loadedVersionId), [], $this->smallClubToken);
        if (200 !== $loaded['status']) {
            throw new RuntimeException(\sprintf('« Charger » la première version a répondu %d (200 attendu)', $loaded['status']));
        }
    }

    #[Given('cette fermeture a commencé il y a huit jours sans que personne ne valide son planning')]
    public function laFermetureACommenceIlYaHuitJours(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
        $start = $today->modify('-8 days')->format('Y-m-d');
        $end = $today->modify('+3 days')->format('Y-m-d');

        $this->dbalExec(\sprintf(
            'UPDATE calendar_entry SET start_date=\'%s\', end_date=\'%s\' WHERE id=(SELECT calendar_entry_id FROM schedule_plan WHERE id=\'%s\')',
            $start,
            $end,
            $this->periodPlanId,
        ), admin: true);
        $this->dbalExec(\sprintf(
            'UPDATE schedule_plan SET start_date=\'%s 00:00:00+00\', end_date=\'%s 00:00:00+00\' WHERE id=\'%s\'',
            $start,
            $end,
            $this->periodPlanId,
        ), admin: true);

        if (null !== $this->chosenVersion()) {
            throw new RuntimeException('décor : le planning de la fermeture ne devait pas encore être validé');
        }
    }

    #[Given('la validation automatique des plannings de période est passée pour ce club')]
    #[When('la validation automatique des plannings de période passe pour ce club')]
    #[When('la validation automatique des plannings de période repasse pour ce club')]
    public function laValidationAutomatiquePasse(): void
    {
        $this->runConsole(['php', 'bin/console', 'app:period-plans:auto-validate', '--club=' . $this->smallClubId]);
    }

    #[When('je rouvre le planning de cette fermeture')]
    public function jeRouvreLePlanning(): void
    {
        $chosen = $this->chosenVersion();
        if (null === $chosen) {
            throw new RuntimeException('décor : le planning de la fermeture aurait dû être validé avant d\'être rouvert');
        }
        $reopened = $this->apiPost(\sprintf('schedules/%s/reopen', $chosen), [], $this->smallClubToken);
        if (200 !== $reopened['status']) {
            throw new RuntimeException(\sprintf('« Rouvrir » a répondu %d (200 attendu)', $reopened['status']));
        }
    }

    #[Then('le planning de la fermeture est en vigueur sur la version chargée, l\'autre version est gardée, et il est marqué validé automatiquement')]
    public function lePlanningEstEnVigueurSurLaVersionChargee(): void
    {
        if ($this->loadedVersionId !== $this->chosenVersion()) {
            throw new RuntimeException(\sprintf('le planning de la fermeture aurait dû pointer la version chargée (%s), il pointe « %s »', $this->loadedVersionId, $this->chosenVersion() ?? 'rien'));
        }
        $this->assertVersionKept($this->otherVersionId);

        $plan = $this->apiGet(\sprintf('schedule_plans/%s', $this->periodPlanId), $this->smallClubToken);
        if (200 !== $plan['status'] || true !== ($plan['json']['autoValidated'] ?? null)) {
            throw new RuntimeException(\sprintf('« Tous les plannings » aurait dû lire ce planning « validé automatiquement » (HTTP %d, autoValidated=%s)', $plan['status'], json_encode($plan['json']['autoValidated'] ?? null)));
        }
    }

    #[Then('le planning de la fermeture reste ouvert et ses deux versions sont gardées')]
    public function lePlanningResteOuvert(): void
    {
        if (null !== $this->chosenVersion()) {
            throw new RuntimeException('un planning rouvert après une validation automatique ne doit JAMAIS être revalidé automatiquement');
        }
        $this->assertVersionKept($this->loadedVersionId);
        $this->assertVersionKept($this->otherVersionId);
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    private function chosenVersion(): ?string
    {
        $chosen = $this->dbalScalar(\sprintf(
            'SELECT COALESCE(chosen_schedule_id::text, \'\') AS behatval FROM schedule_plan WHERE id=\'%s\'',
            $this->periodPlanId,
        ), admin: true);

        return '' === $chosen ? null : $chosen;
    }

    private function assertVersionKept(string $scheduleId): void
    {
        $count = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule WHERE id=\'%s\'', $scheduleId), admin: true);
        if ('1' !== $count) {
            throw new RuntimeException(\sprintf('la version %s aurait dû être gardée', $scheduleId));
        }
    }
}
