<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * P2-54 PR-2b — le gymnase de salle ÉCRIT dans le fichier FBI localise l'adversaire
 * TOUT SEUL, de bout en bout sur la stack qui tourne. Un club (semé, RLS traversée)
 * a une rencontre AWAY dont le fichier porte le nom d'un gymnase RÉEL, et un adversaire
 * dont l'annuaire porte le code postal : après « Mettre à jour les adversaires »
 * (`POST /api/opponents/refresh`), l'équipe adverse est localisée sur ce gymnase FÉDÉRAL,
 * source AUTO. Un nom inventé ne localise rien ; revenir au défaut efface la ligne AUTO.
 *
 * ⚠ Comme la feature « suggestions », ce scénario DÉPEND de l'API FFBB réelle : la
 * localisation n'aboutit qu'après une recherche `searchSalles(CP)` contre l'index
 * fédéral et une égalité STRICTE du libellé. Le gymnase choisi est un gymnase RÉEL de
 * Villeurbanne (69100), à vérifier par une sonde lecture seule si la fédé le renomme.
 *
 * Le club, sa saison, son gestionnaire et les rencontres sont SEMÉS (connexion admin) et
 * RETIRÉS proprement en fin de scénario — un seul tenant suffit à la promesse.
 */
final class OpponentAutoLocateContext extends BaseContext
{
    // Gymnase RÉEL de Villeurbanne (69100), sondé le 2026-09-16 sur l'index fédéral.
    private const string REAL_GYM_LABEL = 'GYMNASE JEANNE DESPARMET-RUELLO';

    private const string INVENTED_GYM_LABEL = 'GYMNASE IMAGINAIRE INEXISTANT 9999';

    private const string POSTAL = '69100';

    private const float LAT = 45.765;

    private const float LON = 4.905;

    private string $token = '';

    private string $clubId = '';

    private string $seasonId = '';

    private string $userId = '';

    private string $realCode = '';

    private string $realTeamKey = '';

    private string $fakeCode = '';

    #[Given('une rencontre à l\'extérieur dont le fichier porte un gymnase réel et un adversaire à code connu')]
    public function uneRencontreAvecGymnaseReel(): void
    {
        $this->seedClub();

        $this->realCode = 'ARA0069' . substr((string) time(), -3) . random_int(10, 99);
        $this->seedDirectory($this->realCode);
        $this->seedAwayFixture($this->realCode, 'Adversaire fichier réel - 1', self::REAL_GYM_LABEL);
    }

    #[When('le club met à jour ses adversaires')]
    public function leClubMetAJourSesAdversaires(): void
    {
        $result = $this->apiPost('opponents/refresh', [], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('la mise à jour des adversaires a échoué (HTTP %d) : %s', $result['status'], json_encode($result['json'])));
        }
    }

    #[Then('l\'équipe adverse est localisée sur ce gymnase, source automatique')]
    public function lEquipeEstLocaliseeSourceAuto(): void
    {
        $entry = $this->travelEntryOf($this->realCode);
        if (null === $entry) {
            throw new RuntimeException('aucune entrée de trajet pour l\'adversaire du fichier réel');
        }
        $override = $entry['overrideVenueLabel'] ?? null;
        if (!\is_string($override) || '' === $override) {
            throw new RuntimeException('l\'équipe adverse n\'a pas été localisée depuis le fichier (aucune surcharge de gymnase)');
        }
        if ('AUTO' !== ($entry['source'] ?? null)) {
            throw new RuntimeException(\sprintf('la localisation depuis le fichier doit être AUTO, vue « %s »', json_encode($entry['source'] ?? null)));
        }
        if (true !== ($entry['located'] ?? null)) {
            throw new RuntimeException('l\'adversaire localisé n\'est pas marqué « located »');
        }
        $teamKey = $entry['opponentTeamKey'] ?? null;
        if (!\is_string($teamKey) || '' === $teamKey) {
            throw new RuntimeException('l\'entrée localisée n\'expose pas son grain équipe (opponentTeamKey)');
        }
        $this->realTeamKey = $teamKey;
    }

    #[Given('une autre rencontre dont le fichier porte un gymnase inventé')]
    public function uneAutreRencontreGymnaseInvente(): void
    {
        $this->fakeCode = 'ARA0069' . substr((string) time(), -3) . random_int(10, 99);
        while ($this->fakeCode === $this->realCode) {
            $this->fakeCode = 'ARA0069' . substr((string) time(), -3) . random_int(10, 99);
        }
        $this->seedDirectory($this->fakeCode);
        $this->seedAwayFixture($this->fakeCode, 'Adversaire fichier faux - 1', self::INVENTED_GYM_LABEL);
    }

    #[Then('cette équipe-là n\'est pas localisée depuis le fichier')]
    public function cetteEquipeNestPasLocaliseeDepuisLeFichier(): void
    {
        $entry = $this->travelEntryOf($this->fakeCode);
        if (null === $entry) {
            throw new RuntimeException('aucune entrée de trajet pour l\'adversaire du gymnase inventé');
        }
        // Un gymnase inventé ne pose AUCUNE surcharge depuis le fichier (le trajet AUTO du
        // club peut exister, mais sans gymnase épinglé et jamais au grain ÉQUIPE).
        if (null !== ($entry['overrideVenueLabel'] ?? null)) {
            throw new RuntimeException('un gymnase inventé n\'aurait pas dû poser de surcharge de gymnase');
        }
        if ('TEAM' === ($entry['scope'] ?? null)) {
            throw new RuntimeException('un gymnase inventé n\'aurait pas dû créer de localisation au grain équipe');
        }
    }

    #[When('le club revient au défaut du club pour l\'équipe localisée')]
    public function leClubRevientAuDefaut(): void
    {
        $result = $this->apiPost('opponents/travel/auto', [
            'opponentOrganismeCode' => $this->realCode,
            'opponentTeamKey' => $this->realTeamKey,
        ], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('le retour au défaut du club a échoué (HTTP %d)', $result['status']));
        }
    }

    #[Then('la localisation automatique de cette équipe a disparu')]
    public function laLocalisationAutoADisparu(): void
    {
        $entry = $this->travelEntryOf($this->realCode);
        if (null !== $entry && null !== ($entry['overrideVenueLabel'] ?? null) && 'TEAM' === ($entry['scope'] ?? null)) {
            throw new RuntimeException('la localisation automatique de l\'équipe aurait dû disparaître après le retour au défaut');
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        foreach ([$this->realCode, $this->fakeCode] as $code) {
            if ('' === $code) {
                continue;
            }
            $this->dbalExec(\sprintf('DELETE FROM opponent_travel WHERE opponent_organisme_code=\'%s\'', $code), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM opponent_venue_suggestion WHERE ffbb_organisme_code=\'%s\'', $code), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM fixture WHERE opponent_organisme_code=\'%s\'', $code), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM opponent_directory WHERE ffbb_organisme_code=\'%s\'', $code), admin: true);
        }
        if ('' !== $this->clubId) {
            $this->dbalExec(\sprintf('DELETE FROM club_user WHERE club_id=\'%s\'', $this->clubId), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM season WHERE club_id=\'%s\'', $this->clubId), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM club WHERE id=\'%s\'', $this->clubId), admin: true);
        }
        if ('' !== $this->userId) {
            $this->dbalExec(\sprintf('DELETE FROM app_user WHERE id=\'%s\'', $this->userId), admin: true);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function travelEntryOf(string $code): ?array
    {
        $result = $this->apiGet('opponents/travel', $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('lecture du trajet adverse refusée (HTTP %d)', $result['status']));
        }
        $opponents = $result['json']['opponents'] ?? [];
        foreach (\is_array($opponents) ? $opponents : [] as $opponent) {
            if (\is_array($opponent) && ($opponent['opponentOrganismeCode'] ?? null) === $code) {
                return $opponent;
            }
        }

        return null;
    }

    private function seedDirectory(string $code): void
    {
        $this->dbalExec(
            \sprintf(
                'INSERT INTO opponent_directory (id, ffbb_organisme_code, name, city, postal_code, latitude, longitude, precision, venue_label, resolved_at)'
                . ' VALUES (\'%s\', \'%s\', \'Adversaire fichier\', \'Villeurbanne\', \'%s\', %F, %F, \'CITY\', NULL, now())',
                $this->uuid(),
                $code,
                self::POSTAL,
                self::LAT,
                self::LON,
            ),
            admin: true,
        );
    }

    private function seedAwayFixture(string $code, string $opponentLabel, string $fbiVenueLabel): void
    {
        $this->dbalExec(
            \sprintf(
                'INSERT INTO fixture (id, version, created_at, updated_at, club_id, season_id, team_id, match_date, home_away, opponent_label, opponent_organisme_code, fbi_venue_label)'
                . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', \'%s\', \'2026-10-04\', \'AWAY\', \'%s\', \'%s\', \'%s\')',
                $this->uuid(),
                $this->clubId,
                $this->seasonId,
                $this->uuid(),
                $opponentLabel,
                $code,
                str_replace('\'', '\'\'', $fbiVenueLabel),
            ),
            admin: true,
        );
    }

    private function seedClub(): void
    {
        $this->clubId = $this->uuid();
        $this->seasonId = $this->uuid();
        $this->userId = $this->uuid();
        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $slug = 'club-auto-locate-' . $suffix;
        $email = 'auto-locate-' . $suffix . '@fonctionnel.test';

        $this->dbalExec(
            \sprintf(
                'INSERT INTO club (id, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed, latitude, longitude)'
                . ' VALUES (\'%s\', now(), now(), \'Club auto-localisation\', \'%s\', 0, \'Europe/Paris\', \'fr\', true, %F, %F)',
                $this->clubId,
                $slug,
                self::LAT,
                self::LON,
            ),
            admin: true,
        );
        $seasonStart = date('Y-m-d', (int) strtotime('-40 days'));
        $seasonEnd = date('Y-m-d', (int) strtotime('+300 days'));
        $this->dbalExec(
            \sprintf(
                'INSERT INTO season (id, created_at, updated_at, club_id, name, start_date, end_date, status, transition_data)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'Saison fonctionnelle\', \'%s\', \'%s\', \'active\', \'{}\')',
                $this->seasonId,
                $this->clubId,
                $seasonStart,
                $seasonEnd,
            ),
            admin: true,
        );
        $this->dbalExec(
            \sprintf(
                'INSERT INTO app_user (id, created_at, updated_at, email, password_hash, first_name, last_name)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'$2y$13$abcdefghijklmnopqrstuv\', \'Auto\', \'Locate\')',
                $this->userId,
                $email,
            ),
            admin: true,
        );
        $this->dbalExec(
            \sprintf(
                'INSERT INTO club_user (id, created_at, updated_at, club_id, user_id, role, joined_at, is_active)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'%s\', \'admin\', now(), true)',
                $this->uuid(),
                $this->clubId,
                $this->userId,
            ),
            admin: true,
        );
        $this->token = $this->mintToken($email);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
