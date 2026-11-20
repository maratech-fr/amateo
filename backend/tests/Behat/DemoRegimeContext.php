<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Gherkin\Node\TableNode;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Promesse : un club de démonstration n'envoie rien à un tiers (conflits aux coachs, invitation,
 * liens de collecte de doléances) et son compte ne se supprime pas ; l'horloge simulée est
 * réservée à la démonstration — refusée au gestionnaire d'un club réel PARCE QUE le club n'est pas
 * en démonstration (le même gestionnaire la règle une fois le club passé en démonstration), pas
 * à cause de son rôle (le refus par rôle, lui, est prouvé dans `un-club-ne-voit-jamais-un-autre-club`).
 *
 * Décor DÉDIÉ et léger ({@see SmallClubFixtureTrait::registerSmallClub}, 2 équipes, 1 gymnase, un
 * coach avec adresse), socle généré et validé (une collecte de vacances exige un plan de période,
 * qui exige le socle en vigueur). Le passage en démonstration est un pointage SQL des deux
 * drapeaux que lit le code — `club.is_demo` (envois) et `app_user.is_demo` (invitation, compte) —,
 * patron de `DemoRetainedContext`. Aucun e-mail : relu dans le webmail de test (Mailpit).
 * Plus bas : `DemoWindowTest`, `DemoClubCaptureTest` (les autres abus de la démo).
 */
final class DemoRegimeContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $campaignId = '';

    private string $coachEmail = '';

    private string $inviteeEmail = '';

    /** @var array{status: int, json: array<mixed>} */
    private array $clock = ['status' => 0, 'json' => []];

    #[Given('un petit club réel dont le planning de saison est en vigueur, un coach joignable par e-mail et une collecte de doléances ouverte pour des vacances')]
    public function unPetitClubReel(): void
    {
        $this->registerSmallClub();
        $categories = $this->apiGet('sport_categories', $this->smallClubToken);
        $category = $categories['json']['member'][0]['id'] ?? null;
        if (!\is_string($category)) {
            throw new RuntimeException('aucune catégorie sportive pour le petit club');
        }
        foreach (['SM1', 'SF1'] as $name) {
            $this->smallClubTeamIds[$name] = $this->smallClubStringField($this->apiPost('teams', [
                'name' => $name, 'sportCategoryId' => $category, 'priorityTierId' => 3, 'sessionsPerWeek' => 1,
            ], $this->smallClubToken)['json'], 'id');
        }
        $venueId = $this->smallClubStringField($this->apiPost('venues', ['name' => 'Gymnase Centre', 'source' => 'manual'], $this->smallClubToken)['json'], 'id');
        foreach ([1, 2] as $day) {
            foreach (['19:00', '20:30'] as $start) {
                $this->smallClubStringField($this->apiPost('venue_training_slots', [
                    'venueId' => $venueId, 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => 90, 'capacity' => 1,
                ], $this->smallClubToken)['json'], 'id');
            }
        }
        $this->inviteeEmail = 'invite-demo-' . strtolower($this->smallClubAra) . '@fonctionnel.test';
        $this->coachEmail = 'coach-demo-' . strtolower($this->smallClubAra) . '@fonctionnel.test';
        $this->smallClubCoachIds['Camille'] = $this->smallClubStringField($this->apiPost('coaches', ['firstName' => 'Camille', 'email' => $this->coachEmail], $this->smallClubToken)['json'], 'id');
        $this->assignSmallClubCoach('Camille', 'SM1');
        $this->generateAndValidateSmallClubSocle();

        $monday = date('Y-m-d', (int) strtotime('next monday +28 days'));
        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period', 'periodType' => 'holiday', 'title' => 'Vacances (démonstration)',
            'startDate' => $monday, 'endDate' => date('Y-m-d', (int) strtotime($monday . ' +13 days')),
        ], $this->smallClubToken);
        $entryId = $this->smallClubStringField($entry['json'], 'id');
        $this->smallClubStringField($this->apiPost('schedule_plans', ['calendarEntryId' => $entryId], $this->smallClubToken)['json'], 'id');
        $campaign = $this->apiPost('coach_wish_campaigns', [
            'calendarEntryId' => $entryId,
            'deadline' => date('Y-m-d', (int) strtotime($monday . ' -3 days')),
            'weeks' => [$monday],
            'teamIds' => [$this->smallClubTeamIds['SM1']],
        ], $this->smallClubToken);
        if (201 !== $campaign['status']) {
            throw new RuntimeException(\sprintf('l\'ouverture de la collecte a répondu %d (201 attendu) : %s', $campaign['status'], json_encode($campaign['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->campaignId = $this->smallClubStringField($campaign['json'], 'id');
    }

    #[When('je règle l\'horloge simulée du club')]
    public function jeRegleLHorloge(): void
    {
        $this->clock = $this->apiPost('club/clock', ['date' => date('Y-m-d', (int) strtotime('next monday'))], $this->smallClubToken);
    }

    #[Then('/^c\'est refusé \(403\) : « (?P<message>.+) »$/u')]
    public function cEstRefuse(string $message): void
    {
        $served = $this->clock['json']['error'] ?? '';
        if (403 !== $this->clock['status'] || $served !== $message) {
            throw new RuntimeException(\sprintf('régler l\'horloge d\'un club réel : %d « %s » (attendu 403 « %s »)', $this->clock['status'], \is_string($served) ? $served : '', $message));
        }
    }

    #[When('le club et son gestionnaire passent en démonstration')]
    public function leClubPasseEnDemonstration(): void
    {
        $this->dbalExec(\sprintf('UPDATE club SET is_demo = true WHERE id=\'%s\'', $this->smallClubId), admin: true);
        $this->dbalExec(\sprintf('UPDATE app_user SET is_demo = true WHERE id=\'%s\'', $this->smallClubUserId), admin: true);
    }

    #[Then('/^chacun de ces envois m\'est refusé \(403\), avec ce message :$/u')]
    public function chacunDeCesEnvoisEstRefuse(TableNode $table): void
    {
        $failures = [];
        foreach ($table->getColumnsHash() as $row) {
            $response = $this->attempt($row['envoi']);
            $served = $response['json']['error'] ?? ($response['json']['detail'] ?? '');
            $served = \is_string($served) ? $served : '';
            if (403 !== $response['status'] || $served !== $row['message']) {
                $failures[] = \sprintf('« %s » : %d « %s » (attendu 403 « %s »)', $row['envoi'], $response['status'], $served, $row['message']);
            }
        }
        if ([] !== $failures) {
            throw new RuntimeException("le club de démonstration n'est pas refusé comme attendu :\n" . implode("\n", $failures));
        }
    }

    #[Then('aucun e-mail n\'est parti vers le coach ni vers la personne invitée')]
    public function aucunEmailNEstParti(): void
    {
        $mailpit = rtrim((string) (getenv('BEHAT_MAILPIT_BASE') ?: 'http://mailpit:8025'), '/');
        foreach ([$this->coachEmail, $this->inviteeEmail] as $address) {
            $search = $this->httpGet($mailpit . '/api/v1/search?query=' . rawurlencode('to:' . $address));
            $total = $search['json']['messages_count'] ?? null;
            if (200 !== $search['status'] || 0 !== $total) {
                throw new RuntimeException(\sprintf('le webmail de test compte %s e-mail(s) vers %s (attendu 0)', json_encode($total), $address));
            }
        }
    }

    #[Then('l\'horloge simulée du club est réglée')]
    public function lHorlogeEstReglee(): void
    {
        if (200 !== $this->clock['status'] || !\is_string($this->clock['json']['simulatedToday'] ?? null)) {
            throw new RuntimeException(\sprintf('régler l\'horloge du club de démonstration a répondu %d : %s', $this->clock['status'], json_encode($this->clock['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    /** @return array{status: int, json: array<mixed>} */
    private function attempt(string $gesture): array
    {
        return match ($gesture) {
            'envoyer les conflits aux coachs' => $this->apiPost('fixtures/conflicts/mail-coaches', [], $this->smallClubToken),
            'inviter quelqu\'un dans le club' => $this->apiPost('invitations', ['email' => $this->inviteeEmail], $this->smallClubToken),
            'envoyer les liens de la collecte de doléances' => $this->apiPost(\sprintf('coach_wish_campaigns/%s/send-links', $this->campaignId), [], $this->smallClubToken),
            'supprimer mon compte' => $this->apiDelete('me', $this->smallClubToken),
            default => throw new RuntimeException(\sprintf('envoi « %s » inconnu du scénario', $gesture)),
        };
    }
}
