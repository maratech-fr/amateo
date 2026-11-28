<?php

declare(strict_types=1);

namespace App\Plans;

/**
 * Ce qui diffère entre deux copies du froid d'un plan ({@see PlanState::changesTo}) : une ligne par famille qui
 * change, avec le nombre de lignes ajoutées, retirées ou modifiées. Un champ froid qui diffère supprimerait les
 * plans de période pas commencés (`destroys`) ; la ligne des séances déplacées ({@see self::MOVED_SESSIONS}), posée
 * à la validation d'une version, est pour information seulement (`destroys: false`).
 *
 * Elle garde aussi les lignes changées elles-mêmes (avant, après), que lit le contrôle d'incohérences pour savoir
 * quelles séances la modification touche ({@see InForceCoherence::touchedSlotIds}).
 */
final readonly class PlanChanges
{
    /** La famille de la ligne « séances déplacées » : les séances d'une version, hors du froid. */
    public const string MOVED_SESSIONS = 'ScheduleSlotTemplate';

    /**
     * @param list<array{family: string, count: int, destroys: bool}>                                         $lines
     * @param array<string, array<string, array{0: array<string, mixed>|null, 1: array<string, mixed>|null}>> $rows  famille => id => [avant, après]
     */
    public function __construct(private array $lines, private array $rows = []) {}

    public function isEmpty(): bool
    {
        return [] === $this->lines;
    }

    public function destroys(): bool
    {
        foreach ($this->lines as $line) {
            if ($line['destroys']) {
                return true;
            }
        }

        return false;
    }

    /** L'empreinte de la liste : une confirmation porte le jeton de la liste vue. */
    public function token(): string
    {
        return hash('sha256', json_encode($this->lines, \JSON_THROW_ON_ERROR));
    }

    /** @return list<array{family: string, count: int, destroys: bool}> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return array<string, array<string, array{0: array<string, mixed>|null, 1: array<string, mixed>|null}>> famille => id => [avant, après] */
    public function rows(): array
    {
        return $this->rows;
    }

    /** La même liste, plus la ligne des séances déplacées quand il y en a (pour information, ne supprime rien). */
    public function withMovedSessions(int $count): self
    {
        if ($count <= 0) {
            return $this;
        }
        $lines = [...$this->lines, ['family' => self::MOVED_SESSIONS, 'count' => $count, 'destroys' => false]];
        usort($lines, static fn (array $a, array $b): int => strcmp($a['family'], $b['family']));

        return new self($lines, $this->rows);
    }
}
