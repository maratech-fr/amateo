<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Parcours d'accueil de bout en bout, sur la stack qui tourne.
 *
 * Reproduit ce que faisait le smoke « onboarding » : on inscrit un club NEUF
 * via /register (vérification différée par e-mail), on récupère le lien de
 * vérification dans le webmail Mailpit, on matérialise le club (verify → cookie
 * BEARER), on l'approuve par le relais de dev, on saisit le minimum, puis on
 * génère et on attend le statut terminal. Rien n'est affaibli : en dev Turnstile
 * est éteint (secret vide) et le rate-limit d'inscription est relâché
 * (rate_limiter.yaml when@dev), exactement le contexte où tournait le smoke.
 */
final class OnboardingContext extends BaseContext
{
    private const int POLL_INTERVAL_SECONDS = 5;

    private const int TIMEOUT_SECONDS = 300;

    private string $token = '';

    private bool $onboardingCompleted = false;

    private string $finalStatus = '';

    private string $mailpitBase;

    public function __construct()
    {
        parent::__construct();
        $this->mailpitBase = rtrim((string) (getenv('BEHAT_MAILPIT_BASE') ?: 'http://mailpit:8025'), '/');
    }

    #[Given('un club neuf dont le gestionnaire vient d\'inscrire son compte et de valider son e-mail')]
    public function unClubNeufInscritEtVerifie(): void
    {
        $ara = 'ONB' . time() . random_int(100, 999);
        $email = 'onb-' . $ara . '@smoke.fr';

        // /register défère tout à la vérification e-mail : 202 neutre, aucun jeton,
        // aucun club encore. Aucun turnstileToken envoyé (Turnstile éteint en dev).
        $registered = $this->publicPost('register', [
            'email' => $email,
            'password' => 'Password123!',
            'firstName' => 'On',
            'lastName' => 'Board',
            'ara' => $ara,
            'club_name' => 'Onb ' . $ara,
            'consent' => true,
        ]);
        if (202 !== $registered['status']) {
            throw new RuntimeException(\sprintf('l\'inscription a répondu %d (202 attendu)', $registered['status']));
        }

        $rawToken = $this->pullVerificationToken($email);

        // verify → matérialise la demande de club + pose le JWT en cookie httpOnly
        // BEARER. Ce n'est pas un navigateur : on lit le cookie dans Set-Cookie et
        // on continue en Bearer (chemin resté ouvert pour les scripts d'ops).
        $verified = $this->publicPost('register/verify', ['token' => $rawToken]);
        $this->token = $this->extractBearerCookie($verified['headers']);

        // La vérification ne matérialise plus le club : la demande attend une
        // approbation. On approuve via le relais de dev (le vrai service, 404 en
        // prod) pour éprouver l'APRÈS-création.
        $approved = $this->apiPost('dev/approve-club-request', [], $this->token);
        if (200 !== $approved['status']) {
            throw new RuntimeException(\sprintf('l\'approbation du club a répondu %d (200 attendu)', $approved['status']));
        }
    }

    #[Given('son nouveau club est vide au départ')]
    public function sonNouveauClubEstVide(): void
    {
        $teams = $this->apiGet('teams', $this->token);
        $count = \count($this->members($teams['json']));
        if (0 !== $count) {
            throw new RuntimeException(\sprintf('un club neuf devrait être vide, il porte déjà %d équipe(s) (fuite d\'isolement)', $count));
        }
    }

    #[When('il saisit le minimum : une équipe, un gymnase avec un créneau, un entraîneur')]
    public function ilSaisitLeMinimum(): void
    {
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive disponible pour créer une équipe');
        }

        $this->expectCreated($this->apiPost('teams', ['name' => 'SM1', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe');

        $venue = $this->apiPost('venues', ['name' => 'Gym A', 'source' => 'manual'], $this->token);
        $this->expectCreated($venue, 'gymnase');
        $venueId = $this->stringField($venue['json'], 'id');

        $this->expectCreated($this->apiPost('venue_training_slots', [
            'venueId' => $venueId,
            'dayOfWeek' => 1,
            'startTime' => '18:00',
            'durationMinutes' => 90,
            'capacity' => 1,
        ], $this->token), 'créneau');

        $this->expectCreated($this->apiPost('coaches', ['firstName' => 'Jean'], $this->token), 'entraîneur');
    }

    #[When('il lance la génération de son premier planning')]
    public function ilLanceLaGeneration(): void
    {
        $created = $this->apiPost('schedules', ['name' => 'Mon planning', 'status' => 'DRAFT'], $this->token);
        $this->expectCreated($created, 'planning');
        $scheduleId = $this->stringField($created['json'], 'id');

        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $scheduleId), [], $this->token);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('le déclenchement de la génération a répondu %d (202 attendu)', $launched['status']));
        }

        // L'accueil est marqué terminé au LANCEMENT — on le lit tout de suite,
        // avant même l'aboutissement (comme le smoke).
        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $this->onboardingCompleted = \is_array($club) && true === ($club['onboardingCompleted'] ?? null);

        $this->finalStatus = $this->pollUntilTerminal($scheduleId);
    }

    #[Then('son parcours d\'accueil est marqué comme terminé')]
    public function sonParcoursDAccueilEstTermine(): void
    {
        if (!$this->onboardingCompleted) {
            throw new RuntimeException('le parcours d\'accueil n\'a pas été marqué comme terminé au lancement de la génération');
        }
    }

    #[Then('/^la génération aboutit avec le statut « (?P<statut>[A-Z]+) »$/')]
    public function laGenerationAboutitAvecLeStatut(string $statut): void
    {
        if ($statut !== $this->finalStatus) {
            throw new RuntimeException(\sprintf('statut attendu « %s », obtenu « %s »', $statut, $this->finalStatus));
        }
    }

    /**
     * Extrait le lien de vérification déposé dans Mailpit, comme le smoke.
     */
    private function pullVerificationToken(string $email): string
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $search = $this->httpGet($this->mailpitBase . '/api/v1/search?query=' . rawurlencode('to:' . $email));
            $messages = $search['json']['messages'] ?? [];
            $messageId = \is_array($messages) && isset($messages[0]['ID']) && \is_string($messages[0]['ID']) ? $messages[0]['ID'] : '';

            if ('' !== $messageId) {
                $message = $this->httpGet($this->mailpitBase . '/api/v1/message/' . $messageId);
                $text = $message['json']['Text'] ?? '';
                if (\is_string($text) && 1 === preg_match('#verify-email/([a-f0-9]{64})#', $text, $m)) {
                    return $m[1];
                }
            }

            sleep(1);
        }

        throw new RuntimeException('aucun e-mail de vérification trouvé dans le webmail — la file d\'envoi est-elle consommée ?');
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function extractBearerCookie(array $headers): string
    {
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            if (1 === preg_match('/^BEARER=([^;]+)/', $cookie, $m)) {
                return $m[1];
            }
        }

        throw new RuntimeException('la vérification n\'a pas posé le cookie de session — impossible de continuer');
    }

    private function pollUntilTerminal(string $scheduleId): string
    {
        $deadline = time() + self::TIMEOUT_SECONDS;
        $status = '';

        do {
            $response = $this->apiGet(\sprintf('schedules/%s', $scheduleId), $this->token);
            if (200 !== $response['status']) {
                throw new RuntimeException(\sprintf('lecture du planning en échec (HTTP %d)', $response['status']));
            }

            $status = $this->stringField($response['json'], 'status');
            if (\in_array($status, ['COMPLETED', 'FAILED'], true)) {
                return $status;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        } while (time() < $deadline);

        throw new RuntimeException(\sprintf('la génération n\'a pas abouti dans le délai imparti (dernier statut « %s »)', $status));
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     */
    private function expectCreated(array $response, string $what): void
    {
        if (!\in_array($response['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création %s refusée (HTTP %d)', $what, $response['status']));
        }
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

    /**
     * @param array<mixed> $json
     */
    private function stringField(array $json, string $field): string
    {
        $value = $json[$field] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new RuntimeException(\sprintf('champ « %s » absent de la réponse de l\'API', $field));
        }

        return $value;
    }
}
