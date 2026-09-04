<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Sollicitation des vœux d'entraîneurs de bout en bout, sur la stack qui tourne.
 *
 * Reproduit ce que faisait le smoke « coach-wishes » — le SEUL chemin d'écriture
 * /api non authentifié : une période HOLIDAY + une campagne (semaines × équipes ×
 * échéance) forgent un jeton EN CLAIR par entraîneur ; send-links exerce le rail
 * d'e-mail ; la page PUBLIQUE se pré-remplit depuis le jeton nu (aucun JWT) ; une
 * soumission dans le périmètre du jeton persiste un vœu que le gestionnaire relit.
 *
 * ⚠ Les appels publics ne posent JAMAIS de Bearer : le jeton dans l'URL EST
 * l'identité. Autosuffisant : crée son entraîneur/période/campagne, nettoie tout.
 */
final class CoachWishesContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const string COACH_FIRST_NAME = 'Smoke';

    private string $token = '';

    private string $teamId = '';

    private string $coachId = '';

    private string $entryId = '';

    private string $campaignId = '';

    private string $wishToken = '';

    private string $monday = '';

    private int $sent = 0;

    private string $publicFirstName = '';

    private int $submissionStatus = 0;

    #[Given('le club de démonstration et son gestionnaire connecté')]
    public function leClubEtSonGestionnaireConnecte(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $teams = $this->apiGet('teams?itemsPerPage=1', $this->token);
        $teamId = $this->members($teams['json'])[0]['id'] ?? null;
        if (!\is_string($teamId) || '' === $teamId) {
            throw new RuntimeException('le club de démonstration n\'a aucune équipe — la base est-elle seedée ?');
        }
        $this->teamId = $teamId;
    }

    #[Given('un entraîneur rattaché à une équipe')]
    public function unEntraineurRattacheAUneEquipe(): void
    {
        $suffix = (string) time() . random_int(100, 999);
        $coach = $this->apiPost('coaches', [
            'firstName' => self::COACH_FIRST_NAME,
            'lastName' => 'Coach',
            'email' => 'smoke-coach-' . $suffix . '@smoke.fr',
            'isActive' => true,
        ], $this->token);
        $this->coachId = $this->idOf($coach, 'entraîneur');

        $link = $this->apiPost('team_coaches', ['teamId' => $this->teamId, 'coachId' => $this->coachId, 'role' => 'MAIN'], $this->token);
        if (201 !== $link['status']) {
            throw new RuntimeException(\sprintf('le rattachement de l\'entraîneur à l\'équipe a répondu %d (201 attendu)', $link['status']));
        }
    }

    #[Given('une période de vacances à venir')]
    public function unePeriodeDeVacances(): void
    {
        // Une période HOLIDAY (les campagnes sont réservées aux vacances)
        // démarrant un lundi futur.
        $this->monday = date('Y-m-d', (int) strtotime('next monday +28 days'));
        $end = date('Y-m-d', (int) strtotime($this->monday . ' +13 days'));

        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'holiday',
            'title' => 'Vacances vœux fonctionnel',
            'startDate' => $this->monday,
            'endDate' => $end,
        ], $this->token);
        $this->entryId = $this->idOf($entry, 'période de vacances');
    }

    #[When('le gestionnaire ouvre une campagne de vœux et envoie les liens')]
    public function leGestionnaireOuvreLaCampagne(): void
    {
        $deadline = date('Y-m-d', (int) strtotime($this->monday . ' -3 days'));
        $campaign = $this->apiPost('coach_wish_campaigns', [
            'calendarEntryId' => $this->entryId,
            'deadline' => $deadline,
            'weeks' => [$this->monday],
            'teamIds' => [$this->teamId],
        ], $this->token);
        $this->campaignId = $this->idOf($campaign, 'campagne');

        foreach ($this->asArray($campaign['json']['coaches'] ?? []) as $entry) {
            if (\is_array($entry) && ($entry['coachId'] ?? null) === $this->coachId) {
                $token = $entry['token'] ?? null;
                if (\is_string($token) && '' !== $token) {
                    $this->wishToken = $token;
                }

                break;
            }
        }
        if ('' === $this->wishToken) {
            throw new RuntimeException('aucun jeton forgé pour l\'entraîneur sollicité');
        }

        $links = $this->apiPost(\sprintf('coach_wish_campaigns/%s/send-links', $this->campaignId), [], $this->token);
        $sent = $links['json']['sent'] ?? null;
        $this->sent = \is_int($sent) ? $sent : (int) $sent;
    }

    #[Then('au moins un lien de sollicitation est envoyé')]
    public function auMoinsUnLienEnvoye(): void
    {
        if ($this->sent < 1) {
            throw new RuntimeException(\sprintf('aucun lien envoyé (envois : %d)', $this->sent));
        }
    }

    #[Then('la page publique du lien reconnaît l\'entraîneur sans qu\'il se connecte')]
    public function laPagePubliqueReconnaitLEntraineur(): void
    {
        // Appel PUBLIC : aucun Bearer — le jeton dans l'URL EST l'identité.
        $public = $this->publicGet(\sprintf('coach-wishes/public/%s', $this->wishToken));
        if (200 !== $public['status']) {
            throw new RuntimeException(\sprintf('la page publique a répondu %d (200 attendu)', $public['status']));
        }
        $firstName = $public['json']['coachFirstName'] ?? null;
        $this->publicFirstName = \is_string($firstName) ? $firstName : '';

        if (self::COACH_FIRST_NAME !== $this->publicFirstName) {
            throw new RuntimeException(\sprintf('la page publique n\'a pas reconnu l\'entraîneur (prénom obtenu « %s »)', $this->publicFirstName));
        }
    }

    #[Then('l\'entraîneur soumet ses vœux depuis cette page sans se connecter')]
    public function lEntraineurSoumetSesVoeux(): void
    {
        // Soumission PUBLIQUE : 2 séances souhaitées, mercredi indisponible.
        // Toujours sans Bearer.
        $submission = $this->publicPost(\sprintf('coach-wishes/public/%s', $this->wishToken), [
            'submissions' => [[
                'teamId' => $this->teamId,
                'weekStart' => $this->monday,
                'slotsWanted' => 2,
                'unavailableDays' => [3],
                'comment' => 'smoke',
            ]],
        ]);
        $this->submissionStatus = $submission['status'];

        if (200 !== $this->submissionStatus) {
            throw new RuntimeException(\sprintf('la soumission publique a répondu %d (200 attendu)', $this->submissionStatus));
        }
    }

    #[Then('le vœu soumis remonte côté gestionnaire')]
    public function leVoeuRemonteCoteGestionnaire(): void
    {
        $wishes = $this->apiGet(\sprintf('coach_wishes?calendarEntryId=%s', $this->entryId), $this->token);

        foreach ($this->members($wishes['json']) as $wish) {
            $unavailable = $wish['unavailableDays'] ?? [];
            if (($wish['teamId'] ?? null) === $this->teamId
                && 2 === ($wish['slotsWanted'] ?? null)
                && \is_array($unavailable) && \in_array(3, $unavailable, true)) {
                return;
            }
        }

        throw new RuntimeException('le vœu soumis est introuvable côté gestionnaire');
    }

    /**
     * Nettoyage dans l'ordre du smoke (`trap cleanup`) : campagne, période, puis
     * entraîneur. Quoi qu'il arrive (succès, échec, exception).
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        if ('' !== $this->campaignId) {
            $this->apiDelete(\sprintf('coach_wish_campaigns/%s', $this->campaignId), $this->token);
        }
        if ('' !== $this->entryId) {
            $this->apiDelete(\sprintf('calendar_entries/%s', $this->entryId), $this->token);
        }
        if ('' !== $this->coachId) {
            $this->apiDelete(\sprintf('coaches/%s', $this->coachId), $this->token);
        }
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

    /**
     * @param mixed $value
     *
     * @return array<mixed>
     */
    private function asArray($value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<array<string, mixed>>
     */
    private function members(array $json): array
    {
        $members = $json['member'] ?? $json;

        return array_values(array_filter(\is_array($members) ? $members : [], 'is_array'));
    }
}
