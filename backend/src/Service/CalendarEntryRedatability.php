<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Enum\CalendarEntryPeriodType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * D3 v1 (décision fondateur 2026-09-04) — LE prédicat « cette période est-elle re-datable ? ».
 *
 * SOURCE UNIQUE : le processor s'en sert pour DÉGELER la fenêtre au PUT (re-datage « d'un bloc »),
 * et le mapping de sortie pour SERVIR le champ `redatable` au front (règle d'or : le backend dit,
 * le front affiche — il n'arbitre pas). Les deux consomment ce seul prédicat, jamais deux copies.
 *
 * Est re-datable EXACTEMENT une racine de FERMETURE portant un plan « d'un bloc » : type CLOSURE,
 * SANS mère (racine), SANS semaines-enfants (pas découpée), et PORTANT un plan de période. Une
 * racine HOLIDAY (liée au référentiel scolaire), une mère découpée en semaines, une semaine-enfant
 * et une fermeture SANS plan (le simple fait déclaré, dont la fenêtre n'était déjà pas gelée)
 * restent hors du champ.
 *
 * Anti-N+1 : le mapping de sortie tourne aussi sur la collection (GET /api/calendar_entries). Plutôt
 * que deux requêtes par entrée (plan + enfants), on lit UNE fois l'ensemble des racines re-datables
 * du club (RLS scope le club), par une seule requête à sous-requêtes EXISTS/NOT EXISTS, mémoïsée pour
 * la durée de la requête HTTP courante (clé = l'objet Request). Chaque test est ensuite un O(1) en
 * mémoire — une requête pour toute la page, aucune hors d'un contexte HTTP.
 */
class CalendarEntryRedatability
{
    /** @var array<string, true>|null ids des racines re-datables du club, mémoïsés pour la requête courante */
    private ?array $memo = null;

    private ?int $memoRequestId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {}

    public function isRedatable(CalendarEntry $entity): bool
    {
        // Porte structurelle sans base : seule une racine de fermeture peut l'être. Une collection
        // d'événements ne déclenche alors jamais la requête d'ensemble.
        if (CalendarEntryPeriodType::CLOSURE !== $entity->getPeriodType() || null !== $entity->getParentEntryId()) {
            return false;
        }

        return isset($this->clubRedatableSet()[$entity->getId()]);
    }

    /**
     * L'ensemble des racines de fermeture re-datables du club (RLS), mémoïsé pour la requête HTTP
     * courante. Hors requête (CLI) : jamais mémoïsé, relu à chaque appel — aucune fuite entre
     * contextes. Une nouvelle requête HTTP (nouvel objet Request) reconstruit l'ensemble : dans une
     * même requête, aucun consommateur ne modifie l'existence d'un plan ou d'une semaine-enfant
     * AVANT de lire la re-databilité, l'ensemble ne peut donc pas s'y périmer.
     *
     * @return array<string, true>
     */
    private function clubRedatableSet(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $requestId = $request instanceof Request ? spl_object_id($request) : null;
        if (null !== $requestId && null !== $this->memo && $requestId === $this->memoRequestId) {
            return $this->memo;
        }

        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT ce.id FROM calendar_entry ce '
            . 'WHERE ce.period_type = :closure AND ce.parent_entry_id IS NULL '
            . 'AND EXISTS (SELECT 1 FROM schedule_plan sp WHERE sp.calendar_entry_id = ce.id) '
            . 'AND NOT EXISTS (SELECT 1 FROM calendar_entry child WHERE child.parent_entry_id = ce.id)',
            ['closure' => CalendarEntryPeriodType::CLOSURE->value],
        );

        $set = array_fill_keys(array_map(strval(...), $ids), true);
        if (null !== $requestId) {
            $this->memo = $set;
            $this->memoRequestId = $requestId;
        }

        return $set;
    }
}
