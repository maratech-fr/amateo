<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Promesse : un membre sans rôle de gestion LIT le planning en vigueur et les matchs, et EXPORTE le
 * planning (le tableur part au gymnase). Le versant « il n'écrit rien » est prouvé, geste par geste
 * et motif compris, par `un-club-ne-voit-jamais-un-autre-club.feature` ({@see TenantIsolationContext}).
 *
 * Le membre est SEMÉ (compte + adhésion `member`) sur le club de démonstration partagé, qui n'est
 * jamais modifié : lectures et export tableur sont sans effet de bord
 * (`Security/MemberReadsAreSideEffectFreeTest`). Retiré en fin de scénario.
 */
final class MemberReadOnlyContext extends BaseContext
{
    private const string MANAGER_EMAIL = 'dev-bccl@amateo.local';

    private string $clubId = '';

    private string $socleId = '';

    private string $memberUserId = '';

    private string $memberToken = '';

    /** @var array{status: int, json: array<mixed>} */
    private array $read = ['status' => 0, 'json' => []];

    private int $exportStatus = 0;

    private string $exportBytes = '';

    #[Given('le club de démonstration, son planning de saison en vigueur, et l\'un de ses membres sans rôle de gestion')]
    public function leClubEtUnMembre(): void
    {
        $manager = $this->mintToken(self::MANAGER_EMAIL);
        $club = $this->apiGet('me', $manager)['json']['club'] ?? null;
        $this->clubId = \is_array($club) && \is_string($club['id'] ?? null) ? $club['id'] : '';
        if ('' === $this->clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->socleId = $this->dbalScalar(\sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND chosen_schedule_id IS NOT NULL', $this->clubId), admin: true);
        if ('' === $this->socleId) {
            throw new RuntimeException('le club de démonstration n\'a pas de planning de saison en vigueur — la base est-elle seedée ?');
        }

        $this->memberUserId = $this->uuid();
        $email = 'membre-lecteur-' . substr(md5(uniqid('', true)), 0, 8) . '@fonctionnel.test';
        $this->dbalExec(\sprintf(
            'INSERT INTO app_user (id, created_at, updated_at, email, password_hash, first_name, last_name) VALUES (\'%s\', now(), now(), \'%s\', \'$2y$13$abcdefghijklmnopqrstuv\', \'Membre\', \'Lecteur\')',
            $this->memberUserId,
            $email,
        ), admin: true);
        $this->dbalExec(\sprintf(
            'INSERT INTO club_user (id, created_at, updated_at, club_id, user_id, role, joined_at, is_active) VALUES (\'%s\', now(), now(), \'%s\', \'%s\', \'member\', now(), true)',
            $this->uuid(),
            $this->clubId,
            $this->memberUserId,
        ), admin: true);
        $this->memberToken = $this->mintToken($email);
    }

    #[When('ce membre consulte les plannings du club')]
    public function ceMembreConsulteLesPlannings(): void
    {
        $this->read = $this->apiGet('schedules', $this->memberToken);
    }

    #[Then('il y trouve le planning en vigueur, avec ses séances')]
    public function ilYTrouveLePlanningEnVigueur(): void
    {
        $ids = array_map(static fn (array $s): mixed => $s['id'] ?? null, $this->members($this->read['json']));
        if (200 !== $this->read['status'] || !\in_array($this->socleId, $ids, true)) {
            throw new RuntimeException(\sprintf('la liste des plannings servie au membre (HTTP %d) ne contient pas le planning en vigueur', $this->read['status']));
        }
        $slots = $this->apiGet(\sprintf('schedule_slot_templates?scheduleId=%s', $this->socleId), $this->memberToken);
        $withTeam = array_filter($this->members($slots['json']), static fn (array $slot): bool => \is_string($slot['teamId'] ?? null) && '' !== $slot['teamId']);
        if (200 !== $slots['status'] || [] === $withTeam) {
            throw new RuntimeException(\sprintf('les séances du planning en vigueur ne sont pas servies au membre (HTTP %d, %d séance(s))', $slots['status'], \count($withTeam)));
        }
    }

    #[When('ce membre consulte les matchs du club')]
    public function ceMembreConsulteLesMatchs(): void
    {
        $this->read = $this->apiGet('fixtures', $this->memberToken);
    }

    #[Then('la liste des matchs lui est servie')]
    public function laListeDesMatchsLuiEstServie(): void
    {
        if (200 !== $this->read['status'] || !\array_key_exists('member', $this->read['json'])) {
            throw new RuntimeException(\sprintf('la liste des matchs n\'est pas servie au membre (HTTP %d)', $this->read['status']));
        }
    }

    #[When('ce membre exporte le planning en vigueur en tableur')]
    public function ceMembreExporte(): void
    {
        $response = $this->client->request('POST', \sprintf('schedules/%s/export-xlsx', $this->socleId), [
            'headers' => ['Authorization' => 'Bearer ' . $this->memberToken],
            'json' => (object) [],
        ]);
        $this->exportStatus = $response->getStatusCode();
        $this->exportBytes = $response->getContent(false);
    }

    #[Then('il repart avec un classeur Excel non vide')]
    public function ilRepartAvecUnClasseur(): void
    {
        // Un .xlsx est une archive ZIP : signature « PK ».
        if (200 !== $this->exportStatus || !str_starts_with($this->exportBytes, 'PK')) {
            throw new RuntimeException(\sprintf('l\'export tableur du membre a répondu %d sans classeur Excel', $this->exportStatus));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' !== $this->memberUserId) {
            $this->dbalExec(\sprintf('DELETE FROM club_user WHERE user_id=\'%s\'', $this->memberUserId), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM app_user WHERE id=\'%s\'', $this->memberUserId), admin: true);
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

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
