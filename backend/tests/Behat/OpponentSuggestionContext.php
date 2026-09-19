<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * P2-54 « adversaire multi-gymnases » PR-2 — les SUGGESTIONS partagées de gymnases par
 * club adverse, de bout en bout sur la stack qui tourne. Deux vrais tenants sous RLS
 * (le club de démonstration seedé + un second club semé) engagés à l'extérieur contre
 * le MÊME adversaire : ce qu'un club épingle devient une suggestion « choisie par N
 * clubs » pour l'autre, sans révéler LEQUEL, le choix de l'un ne touche pas le trajet
 * de l'autre, et retirer un choix fait redescendre le compte sans effacer la ligne.
 *
 * Le second club et les rencontres AWAY (une par club, même code adverse) sont SEMÉS en
 * base (connexion admin, RLS traversée) — deux tenants suffisent à la promesse, et un
 * décor semé se RETIRE proprement en fin de scénario (suggestions, trajets, rencontres,
 * second club).
 */
final class OpponentSuggestionContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    // ⚠ Numéros de salle FÉDÉRAUX RÉELS (sondés le 2026-09-15) : la suggestion partagée
    // n'est alimentée qu'après re-résolution serveur contre l'index FFBB (revue sécurité
    // 2026-09-15) — un numéro inventé ne partagerait rien. Ce scénario dépend donc de la
    // disponibilité de l'API FFBB (comme les features de réconciliation FFBB).
    // GYMNASE JEANNE DESPARMET-RUELLO (Villeurbanne) et GYMNASE MATEO.
    private const string GYM_A_REF = '6926616';

    private const float GYM_A_LAT = 45.76499;

    private const float GYM_A_LON = 4.9051;

    private const string GYM_B_REF = '166926608';

    private const float GYM_B_LAT = 45.77999;

    private const float GYM_B_LON = 4.88473;

    private string $tokenA = '';

    private string $clubIdA = '';

    private string $seasonIdA = '';

    private string $tokenB = '';

    private string $clubIdB = '';

    private string $seasonIdB = '';

    private string $userIdB = '';

    private string $opponentCode = '';

    private ?string $travelLabelABefore = null;

    #[Given('deux clubs engagés à l\'extérieur contre le même adversaire')]
    public function deuxClubsContreLeMemeAdversaire(): void
    {
        $this->opponentCode = 'ARA0069' . substr((string) time(), -3) . random_int(10, 99);

        $this->tokenA = $this->mintToken(self::USER_EMAIL);
        $me = $this->apiGet('me', $this->tokenA);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubIdA = $clubId;
        $this->seasonIdA = $this->currentSeasonOf($this->clubIdA);

        $this->seedSecondClub();

        // Une rencontre AWAY chez chaque club contre le MÊME code organisme adverse.
        $this->seedAwayFixture($this->clubIdA, $this->seasonIdA);
        $this->seedAwayFixture($this->clubIdB, $this->seasonIdB);
    }

    #[When('le premier club épingle un gymnase pour cet adversaire')]
    public function lePremierClubEpingleUnGymnase(): void
    {
        $this->pinGym($this->tokenA, self::GYM_A_REF, 'Gymnase choisi par A', self::GYM_A_LAT, self::GYM_A_LON);
        $this->travelLabelABefore = $this->travelOverrideLabelOf($this->tokenA);
    }

    #[Then('le second club voit ce gymnase suggéré, choisi par 1 club')]
    public function leSecondClubVoitLaSuggestion(): void
    {
        $suggestion = $this->findSuggestion($this->tokenB, self::GYM_A_REF);
        if (null === $suggestion) {
            throw new RuntimeException('le second club ne voit pas le gymnase épinglé par le premier');
        }
        if (1 !== ($suggestion['chosenByCount'] ?? null)) {
            throw new RuntimeException(\sprintf('la suggestion devrait être « choisie par 1 club », vue « %s »', json_encode($suggestion['chosenByCount'] ?? null)));
        }
    }

    #[Then('la suggestion ne révèle jamais quel club l\'a choisi')]
    public function laSuggestionNeReveleJamaisLeClub(): void
    {
        $raw = json_encode($this->apiGet(\sprintf('opponents/%s/venue-suggestions', $this->opponentCode), $this->tokenB)['json'], \JSON_THROW_ON_ERROR);
        foreach ([$this->clubIdA, self::USER_EMAIL] as $identity) {
            if (str_contains($raw, $identity)) {
                throw new RuntimeException('la suggestion partagée révèle une identité de club/auteur — fuite tenant');
            }
        }
        // Un compte, jamais un qui : les clés servies ne portent aucune identité.
        $suggestion = $this->findSuggestion($this->tokenB, self::GYM_A_REF) ?? [];
        foreach (['clubId', 'userId', 'authorId', 'chosenBy', 'sourceClubId'] as $forbidden) {
            if (\array_key_exists($forbidden, $suggestion)) {
                throw new RuntimeException(\sprintf('la suggestion porte une clé identifiante interdite : %s', $forbidden));
            }
        }
    }

    #[When('le second club épingle un autre gymnase pour cet adversaire')]
    public function leSecondClubEpingleUnAutreGymnase(): void
    {
        $this->pinGym($this->tokenB, self::GYM_B_REF, 'Gymnase choisi par B', self::GYM_B_LAT, self::GYM_B_LON);
    }

    #[Then('le trajet du premier club n\'a pas changé')]
    public function leTrajetDuPremierClubNaPasChange(): void
    {
        $after = $this->travelOverrideLabelOf($this->tokenA);
        if ($after !== $this->travelLabelABefore) {
            throw new RuntimeException(\sprintf('le trajet du premier club a changé sous le choix du second (« %s » → « %s »)', (string) $this->travelLabelABefore, (string) $after));
        }
        if ('Gymnase choisi par A' !== $after) {
            throw new RuntimeException(\sprintf('le premier club ne pointe plus son propre gymnase (vu « %s »)', (string) $after));
        }
    }

    #[When('le premier club retire son choix')]
    public function lePremierClubRetireSonChoix(): void
    {
        $result = $this->apiPost('opponents/travel/auto', ['opponentOrganismeCode' => $this->opponentCode], $this->tokenA);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('le retour à l\'automatique a échoué (HTTP %d)', $result['status']));
        }
    }

    #[Then('la suggestion reste, choisie par 0 club')]
    public function laSuggestionResteAZero(): void
    {
        $suggestion = $this->findSuggestion($this->tokenA, self::GYM_A_REF);
        if (null === $suggestion) {
            throw new RuntimeException('la suggestion a disparu — elle devait rester (compte 0)');
        }
        if (0 !== ($suggestion['chosenByCount'] ?? null)) {
            throw new RuntimeException(\sprintf('après retrait, le compte devrait être 0, vu « %s »', json_encode($suggestion['chosenByCount'] ?? null)));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' !== $this->opponentCode) {
            $this->dbalExec(\sprintf('DELETE FROM opponent_venue_suggestion WHERE ffbb_organisme_code=\'%s\'', $this->opponentCode), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM opponent_venue_link WHERE opponent_organisme_code=\'%s\'', $this->opponentCode), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM fixture WHERE opponent_organisme_code=\'%s\'', $this->opponentCode), admin: true);
        }
        if ('' !== $this->clubIdB) {
            $this->dbalExec(\sprintf('DELETE FROM club_user WHERE club_id=\'%s\'', $this->clubIdB), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM season WHERE club_id=\'%s\'', $this->clubIdB), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM club WHERE id=\'%s\'', $this->clubIdB), admin: true);
        }
        if ('' !== $this->userIdB) {
            $this->dbalExec(\sprintf('DELETE FROM app_user WHERE id=\'%s\'', $this->userIdB), admin: true);
        }
    }

    private function pinGym(string $token, string $ref, string $label, float $lat, float $lon): void
    {
        $result = $this->apiPost('opponents/travel/manual', [
            'opponentOrganismeCode' => $this->opponentCode,
            'venueLabel' => $label,
            'venueExternalRef' => $ref,
            'latitude' => $lat,
            'longitude' => $lon,
        ], $token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('l\'épinglage du gymnase a échoué (HTTP %d) : %s', $result['status'], json_encode($result['json'])));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSuggestion(string $token, string $ref): ?array
    {
        $result = $this->apiGet(\sprintf('opponents/%s/venue-suggestions', $this->opponentCode), $token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('lecture des suggestions refusée (HTTP %d)', $result['status']));
        }
        $suggestions = $result['json']['suggestions'] ?? [];
        foreach (\is_array($suggestions) ? $suggestions : [] as $suggestion) {
            if (\is_array($suggestion) && ($suggestion['externalRef'] ?? null) === $ref) {
                return $suggestion;
            }
        }

        return null;
    }

    private function travelOverrideLabelOf(string $token): ?string
    {
        $result = $this->apiGet('opponents/travel', $token);
        $opponents = $result['json']['opponents'] ?? [];
        foreach (\is_array($opponents) ? $opponents : [] as $opponent) {
            if (\is_array($opponent) && ($opponent['opponentOrganismeCode'] ?? null) === $this->opponentCode) {
                $label = $opponent['overrideVenueLabel'] ?? null;

                return \is_string($label) ? $label : null;
            }
        }

        return null;
    }

    private function currentSeasonOf(string $clubId): string
    {
        // ⚠ dbalScalar rend la 1re ligne non-« behatval » : sans l'alias, l'EN-TÊTE de
        // colonne (« id ») serait rendu à la place de l'uuid (patron guardSandbox).
        $seasonId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM season WHERE club_id=\'%s\' AND start_date <= now() ORDER BY start_date DESC LIMIT 1', $clubId),
            admin: true,
        );
        if ('' === $seasonId) {
            throw new RuntimeException('aucune saison courante pour le club de démonstration');
        }

        return $seasonId;
    }

    private function seedAwayFixture(string $clubId, string $seasonId): void
    {
        $this->dbalExec(
            \sprintf(
                'INSERT INTO fixture (id, version, created_at, updated_at, club_id, season_id, team_id, match_date, home_away, opponent_label, opponent_organisme_code)'
                . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', \'%s\', \'2026-10-04\', \'AWAY\', \'Adversaire partagé (fonctionnel)\', \'%s\')',
                $this->uuid(),
                $clubId,
                $seasonId,
                $this->uuid(),
                $this->opponentCode,
            ),
            admin: true,
        );
    }

    private function seedSecondClub(): void
    {
        $this->clubIdB = $this->uuid();
        $this->seasonIdB = $this->uuid();
        $this->userIdB = $this->uuid();
        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $slug = 'autre-club-suggestion-' . $suffix;
        $email = 'autre-club-sugg-' . $suffix . '@fonctionnel.test';

        $this->dbalExec(
            \sprintf(
                'INSERT INTO club (id, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed)'
                . ' VALUES (\'%s\', now(), now(), \'Autre club (suggestions)\', \'%s\', 0, \'Europe/Paris\', \'fr\', true)',
                $this->clubIdB,
                $slug,
            ),
            admin: true,
        );
        $seasonStart = date('Y-m-d', (int) strtotime('-40 days'));
        $seasonEnd = date('Y-m-d', (int) strtotime('+300 days'));
        $this->dbalExec(
            \sprintf(
                'INSERT INTO season (id, created_at, updated_at, club_id, name, start_date, end_date, status, transition_data)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'Saison fonctionnelle\', \'%s\', \'%s\', \'active\', \'{}\')',
                $this->seasonIdB,
                $this->clubIdB,
                $seasonStart,
                $seasonEnd,
            ),
            admin: true,
        );
        $this->dbalExec(
            \sprintf(
                'INSERT INTO app_user (id, created_at, updated_at, email, password_hash, first_name, last_name)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'$2y$13$abcdefghijklmnopqrstuv\', \'Fonc\', \'Tionnel\')',
                $this->userIdB,
                $email,
            ),
            admin: true,
        );
        $this->dbalExec(
            \sprintf(
                'INSERT INTO club_user (id, created_at, updated_at, club_id, user_id, role, joined_at, is_active)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'%s\', \'admin\', now(), true)',
                $this->uuid(),
                $this->clubIdB,
                $this->userIdB,
            ),
            admin: true,
        );
        $this->tokenB = $this->mintToken($email);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
