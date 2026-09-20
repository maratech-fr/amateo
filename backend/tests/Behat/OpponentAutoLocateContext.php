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

    private string $realLinkId = '';

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
        // Le gymnase apparié se lit désormais par CLUB adverse : un `venue` en source AUTO,
        // né du libellé du fichier re-résolu contre l'index fédéral (grain lien, plus de trajet).
        $opponent = $this->opponentOf($this->realCode);
        if (null === $opponent) {
            throw new RuntimeException('l\'adversaire du fichier réel n\'apparaît pas dans la liste des adversaires');
        }
        $venue = $this->autoVenueOf($opponent);
        if (null === $venue) {
            throw new RuntimeException('l\'équipe adverse n\'a pas été localisée depuis le fichier (aucun gymnase apparié en source automatique)');
        }
        $label = $venue['label'] ?? null;
        if (!\is_string($label) || '' === $label) {
            throw new RuntimeException('le gymnase apparié n\'expose pas son libellé fédéral');
        }
        // La promesse tient au niveau du LIEN : un `opponent_venue_link` AUTO existe en base pour
        // le libellé du fichier, pointant le gymnase fédéral (coordonnées présentes).
        $linkCount = (int) $this->dbalScalar(
            \sprintf(
                'SELECT count(*) AS behatval FROM opponent_venue_link'
                . ' WHERE club_id=\'%s\' AND opponent_organisme_code=\'%s\' AND source=\'AUTO\''
                . ' AND latitude IS NOT NULL AND longitude IS NOT NULL',
                $this->clubId,
                $this->realCode,
            ),
            admin: true,
        );
        if ($linkCount < 1) {
            throw new RuntimeException('aucun lien AUTO en base pour le libellé du fichier réel');
        }
        $id = $venue['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException('le gymnase apparié n\'expose pas son identifiant de lien');
        }
        $this->realLinkId = $id;
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
        // Un gymnase inventé ne résout rien : AUCUN lien AUTO en base, et le libellé reste
        // « à apparier » (jamais promu en gymnase apparié).
        $linkCount = (int) $this->dbalScalar(
            \sprintf(
                'SELECT count(*) AS behatval FROM opponent_venue_link'
                . ' WHERE club_id=\'%s\' AND opponent_organisme_code=\'%s\' AND source=\'AUTO\'',
                $this->clubId,
                $this->fakeCode,
            ),
            admin: true,
        );
        if (0 !== $linkCount) {
            throw new RuntimeException('un gymnase inventé n\'aurait pas dû créer de lien AUTO');
        }
        $opponent = $this->opponentOf($this->fakeCode);
        if (null !== $opponent && null !== $this->autoVenueOf($opponent)) {
            throw new RuntimeException('un gymnase inventé n\'aurait pas dû apparaître comme gymnase localisé');
        }
    }

    #[When('le club revient au défaut du club pour l\'équipe localisée')]
    public function leClubRevientAuDefaut(): void
    {
        // « Revenir au défaut » = retirer l'appariement LOCAL (le lien AUTO) ; le catalogue
        // fédéral n'est jamais touché.
        if ('' === $this->realLinkId) {
            throw new RuntimeException('aucun lien à retirer (la localisation automatique n\'a pas été captée)');
        }
        $result = $this->apiDelete('opponents/venue-links/' . $this->realLinkId, $this->token);
        if (204 !== $result['status']) {
            throw new RuntimeException(\sprintf('le retour au défaut du club a échoué (HTTP %d)', $result['status']));
        }
    }

    #[Then('la localisation automatique de cette équipe a disparu')]
    public function laLocalisationAutoADisparu(): void
    {
        $opponent = $this->opponentOf($this->realCode);
        if (null !== $opponent && null !== $this->autoVenueOf($opponent)) {
            throw new RuntimeException('la localisation automatique aurait dû disparaître après le retour au défaut');
        }
        $linkCount = (int) $this->dbalScalar(
            \sprintf(
                'SELECT count(*) AS behatval FROM opponent_venue_link'
                . ' WHERE club_id=\'%s\' AND opponent_organisme_code=\'%s\' AND source=\'AUTO\'',
                $this->clubId,
                $this->realCode,
            ),
            admin: true,
        );
        if (0 !== $linkCount) {
            throw new RuntimeException('le lien AUTO aurait dû disparaître en base après le retour au défaut');
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        foreach ([$this->realCode, $this->fakeCode] as $code) {
            if ('' === $code) {
                continue;
            }
            $this->dbalExec(\sprintf('DELETE FROM opponent_venue_link WHERE opponent_organisme_code=\'%s\'', $code), admin: true);
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
     * L'adversaire (groupe par CLUB adverse) servi par GET /api/opponents/travel, repéré par
     * son code fédéral (`code`) — porte `venues` (gymnases appariés) et `unmatchedLabels`.
     *
     * @return array<string, mixed>|null
     */
    private function opponentOf(string $code): ?array
    {
        $result = $this->apiGet('opponents/travel', $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('lecture des adversaires refusée (HTTP %d)', $result['status']));
        }
        $opponents = $result['json']['opponents'] ?? [];
        foreach (\is_array($opponents) ? $opponents : [] as $opponent) {
            if (\is_array($opponent) && ($opponent['code'] ?? null) === $code) {
                return $opponent;
            }
        }

        return null;
    }

    /**
     * Le premier gymnase apparié en source AUTO d'un adversaire, ou null.
     *
     * @param array<string, mixed> $opponent
     *
     * @return array<string, mixed>|null
     */
    private function autoVenueOf(array $opponent): ?array
    {
        $venues = $opponent['venues'] ?? [];
        foreach (\is_array($venues) ? $venues : [] as $venue) {
            if (\is_array($venue) && 'AUTO' === ($venue['source'] ?? null)) {
                return $venue;
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
