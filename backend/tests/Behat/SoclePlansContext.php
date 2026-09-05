<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Le planning de saison commande les plannings de période (ADR-0002), sur la stack qui tourne.
 *
 * Prouve la souveraineté du socle : le valider ou le rouvrir efface les plannings de période
 * ENTIÈREMENT à venir (critère `findWithPlanNotStarted` : date de début > aujourd'hui) et épargne
 * ceux déjà commencés ; et qu'aucun planning de période ne peut naître sans socle en vigueur
 * (`SocleGuard::assertSeasonPlanChosen`).
 *
 * Décor : club de démonstration. Chaque scénario forge SES deux plannings de période — un à venir
 * (par le vrai geste « Adapter », POST /schedule_plans) et un déjà commencé (semé en base : l'API
 * refuse une fenêtre passée) — puis restaure le pointeur du socle et retire ce qu'il a créé, quoi
 * qu'il arrive. Le geste « valider » ne détruit que lorsqu'il DÉPLACE la base : on dépointe le socle
 * en silence avant de re-valider la même version, exactement le cas « pointeur NULL » que la garde
 * destructive n'exempte pas.
 */
final class SoclePlansContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private string $token = '';

    private string $clubId = '';

    private string $seasonId = '';

    /** Version que le socle pointait à l'entrée du scénario (à restaurer). */
    private string $chosenAtEntry = '';

    /** Planning de période à venir, né du vrai geste « Adapter » (destiné à disparaître). */
    private string $futureEntryId = '';

    private string $futurePlanId = '';

    /** Planning de période déjà commencé, semé en base (destiné à subsister). */
    private string $startedEntryId = '';

    private string $startedPlanId = '';

    /** Entrée de la période dont on tente l'ouverture sans socle (scénario refus). */
    private string $refusedEntryId = '';

    private int $refusedStatus = 0;

    private string $refusedError = '';

    #[Given('le club de démonstration, connecté, dont le planning de saison est en vigueur')]
    public function leClubConnecteAvecSocleEnVigueur(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        $this->seasonId = $this->dbalScalar(
            \sprintf('SELECT season_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $this->seasonId) {
            throw new RuntimeException('aucun planning de saison — la base est-elle seedée ?');
        }

        // Ouvrir un planning de période exige le socle en vigueur : s'il ne pointe rien, on pose la
        // version COMPLETED la plus fraîche le temps du scénario (restaurée en fin).
        $this->chosenAtEntry = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $this->chosenAtEntry) {
            $this->chosenAtEntry = $this->freshestCompletedSeasonVersion();
            if ('' === $this->chosenAtEntry) {
                throw new RuntimeException('aucun planning de saison COMPLETED — la base est-elle seedée ?');
            }
            $this->pointSocleTo($this->chosenAtEntry);
        }
    }

    #[Given('un planning de période à venir et un planning de période déjà commencé')]
    public function unPlanAVenirEtUnPlanCommence(): void
    {
        // À VENIR : le vrai geste « Adapter » sur une fermeture d'une semaine pleine (lun→ven = un
        // seul segment, accepté « d'un bloc »), bien au-delà des fenêtres seedées.
        $start = date('Y-m-d', (int) strtotime('next monday +105 days'));
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));
        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'closure',
            'title' => 'Fermeture à venir (fonctionnel socle)',
            'startDate' => $start,
            'endDate' => $end,
        ], $this->token);
        $this->futureEntryId = $this->idOf($entry, 'période à venir');

        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->futureEntryId], $this->token);
        $this->futurePlanId = $this->idOf($plan, 'planning de période à venir');

        // DÉJÀ COMMENCÉ : semé en base (l'API refuse une fenêtre passée). Fenêtre à cheval sur
        // aujourd'hui → date de début ≤ aujourd'hui, donc hors du critère « à venir ».
        $this->startedEntryId = $this->uuid();
        $this->startedPlanId = $this->uuid();
        $startedStart = date('Y-m-d', (int) strtotime('-3 days'));
        $startedEnd = date('Y-m-d', (int) strtotime('+3 days'));
        $this->dbalExec(
            \sprintf(
                'INSERT INTO calendar_entry (id, created_at, updated_at, club_id, season_id, kind, title, start_date, end_date, is_disruptive, period_type, status)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'%s\', \'period\', \'Fermeture déjà commencée (fonctionnel socle)\', \'%s\', \'%s\', false, \'closure\', \'active\')',
                $this->startedEntryId,
                $this->clubId,
                $this->seasonId,
                $startedStart,
                $startedEnd,
            ),
            admin: true,
        );
        $this->dbalExec(
            \sprintf(
                'INSERT INTO schedule_plan (id, created_at, updated_at, club_id, season_id, type, name, start_date, end_date, calendar_entry_id)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'%s\', \'CLOSURE\', \'Fermeture déjà commencée (fonctionnel socle)\', \'%s\', \'%s\', \'%s\')',
                $this->startedPlanId,
                $this->clubId,
                $this->seasonId,
                $startedStart,
                $startedEnd,
                $this->startedEntryId,
            ),
            admin: true,
        );
    }

    #[When('je valide le planning de saison')]
    public function jeValideLePlanningDeSaison(): void
    {
        // Valider ne détruit que lorsqu'il DÉPLACE la base — c.-à-d. lorsqu'il choisit une version
        // qui n'est pas celle déjà pointée. On dépointe donc en silence (sans le geste « Rouvrir »,
        // qui détruirait déjà), puis on re-valide la même version : la garde voit un pointeur NULL,
        // qu'elle n'exempte pas, et efface les plannings de période à venir.
        $this->pointSocleTo('');

        $validated = $this->apiPost(
            \sprintf('schedules/%s/validate', $this->chosenAtEntry),
            ['confirmDeleteOverlays' => true],
            $this->token,
        );
        if (200 !== $validated['status']) {
            throw new RuntimeException(\sprintf('la validation du planning de saison a répondu %d (200 attendu)', $validated['status']));
        }
    }

    #[When('je rouvre le planning de saison')]
    public function jeRouvreLePlanningDeSaison(): void
    {
        $reopened = $this->apiPost(
            \sprintf('schedules/%s/reopen', $this->chosenAtEntry),
            ['confirmDeleteOverlays' => true],
            $this->token,
        );
        if (200 !== $reopened['status']) {
            throw new RuntimeException(\sprintf('la réouverture du planning de saison a répondu %d (200 attendu)', $reopened['status']));
        }
    }

    #[Then('le planning de période à venir a disparu et celui déjà commencé subsiste')]
    public function lePlanAVenirADisparuLautreSubsiste(): void
    {
        $future = $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE calendar_entry_id=\'%s\'', $this->futureEntryId),
            admin: true,
        );
        if ('0' !== $future) {
            throw new RuntimeException('le planning de période à venir aurait dû disparaître avec la bascule du socle');
        }

        $started = $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE id=\'%s\'', $this->startedPlanId),
            admin: true,
        );
        if ('1' !== $started) {
            throw new RuntimeException('le planning de période déjà commencé aurait dû subsister — jamais détruit au milieu');
        }
    }

    #[Given('le planning de saison n\'est plus en vigueur')]
    public function leSocleNestPlusEnVigueur(): void
    {
        // Dépointage silencieux (pas le geste « Rouvrir » : on veut isoler la garde de création).
        $this->pointSocleTo('');
    }

    #[When('je tente d\'ouvrir le planning d\'une période à venir')]
    public function jeTenteDouvrirUnPlanSansSocle(): void
    {
        // Le geste « Adapter » (naissance du plan) ne dépend PAS du socle ; c'est GÉNÉRER une
        // version de période — bâtie SUR le calendrier de base — qui l'exige. On adapte donc la
        // période, puis on tente d'en ouvrir une version : c'est CE geste qui est refusé sans socle.
        $start = date('Y-m-d', (int) strtotime('next monday +112 days'));
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));
        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'closure',
            'title' => 'Fermeture sans socle (fonctionnel)',
            'startDate' => $start,
            'endDate' => $end,
        ], $this->token);
        $this->refusedEntryId = $this->idOf($entry, 'période à venir');

        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->refusedEntryId], $this->token);
        $planId = $this->idOf($plan, 'plan de période');

        $refused = $this->apiPost('schedules', ['schedulePlanId' => $planId, 'status' => 'DRAFT'], $this->token);
        $this->refusedStatus = $refused['status'];
        $this->refusedError = \is_string($refused['json']['error'] ?? null) ? $refused['json']['error'] : (\is_string($refused['json']['detail'] ?? null) ? $refused['json']['detail'] : '');
    }

    #[Then('l\'ouverture est refusée faute de planning de saison en vigueur')]
    public function louvertureEstRefusee(): void
    {
        if (409 !== $this->refusedStatus) {
            throw new RuntimeException(\sprintf('l\'ouverture aurait dû être refusée (409), obtenu %d', $this->refusedStatus));
        }
        if (!str_contains($this->refusedError, 'planning principal')) {
            throw new RuntimeException(\sprintf('le refus aurait dû renvoyer au planning principal, message obtenu « %s »', $this->refusedError));
        }
    }

    /**
     * Restaure le pointeur du socle (comme SeasonGenerationContext) et retire tout ce que le
     * scénario a créé — quoi qu'il arrive (succès, échec, exception).
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        // Restaure « socle en vigueur » : la version d'entrée si elle existe encore, sinon la plus
        // fraîche COMPLETED. Ce qui compte pour les autres scénarios, c'est l'état « en vigueur ».
        if ('' !== $this->clubId) {
            $target = '';
            if ('' !== $this->chosenAtEntry
                && '1' === $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule WHERE id=\'%s\'', $this->chosenAtEntry), admin: true)) {
                $target = $this->chosenAtEntry;
            }
            if ('' === $target) {
                $target = $this->freshestCompletedSeasonVersion();
            }
            if ('' !== $target) {
                $this->pointSocleTo($target);
            }
        }

        // Le planning de période à venir : son entrée survit à la bascule (l'entrée n'est jamais
        // détruite), on la retire ; DELETE tolérant si elle a déjà disparu.
        foreach ([$this->futureEntryId, $this->refusedEntryId] as $entryId) {
            if ('' !== $entryId) {
                $this->apiDelete(\sprintf('calendar_entries/%s', $entryId), $this->token);
            }
        }

        // Le planning déjà commencé, semé en base : on le retire en base (plan puis entrée).
        if ('' !== $this->startedPlanId) {
            $this->dbalExec(\sprintf('DELETE FROM schedule_plan WHERE id=\'%s\'', $this->startedPlanId), admin: true);
        }
        if ('' !== $this->startedEntryId) {
            $this->dbalExec(\sprintf('DELETE FROM calendar_entry WHERE id=\'%s\'', $this->startedEntryId), admin: true);
        }
    }

    private function freshestCompletedSeasonVersion(): string
    {
        $id = $this->dbalScalar(
            \sprintf('SELECT s.id AS behatval FROM schedule s JOIN schedule_plan p ON p.id=s.schedule_plan_id WHERE s.club_id=\'%s\' AND p.type=\'SEASON\' AND s.status=\'COMPLETED\' ORDER BY s.created_at DESC LIMIT 1', $this->clubId),
            admin: true,
        );

        // dbal:run-sql rend une ligne « [OK] … empty result set. » quand rien ne sort : ce n'est pas
        // un identifiant. On ne garde qu'une valeur en forme d'UUID.
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) ? $id : '';
    }

    private function pointSocleTo(string $scheduleId): void
    {
        $value = '' === $scheduleId ? 'NULL' : \sprintf('\'%s\'', $scheduleId);
        $this->dbalExec(
            \sprintf('UPDATE schedule_plan SET chosen_schedule_id=%s WHERE club_id=\'%s\' AND type=\'SEASON\'', $value, $this->clubId),
            admin: true,
        );
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     */
    private function idOf(array $response, string $what): string
    {
        $id = $response['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création %s sans identifiant en retour (HTTP %d)', $what, $response['status']));
        }

        return $id;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
