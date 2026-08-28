<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SEC-13 — la saison de la RESSOURCE qu'une écriture cible, résolue HORS filtres.
 *
 * Le garde « saison archivée » ({@see SeasonAccessGuard}) ne voyait que la saison
 * SÉLECTIONNÉE (header `X-Season-Id` ou saison courante). Sans header, la saison
 * courante est writable par définition — mais l'écriture peut viser une ressource
 * qui, elle, vit dans une saison archivée. Ce résolveur rend la saison de la cible.
 *
 * SQL brut FILTER-FREE, exactement le patron de
 * {@see SchedulePlanProvisioner::fetchPlanContext} : la saison de la cible n'est pas
 * forcément la saison filtrée du moment, donc le filtre `season_filter` la cacherait.
 * La RLS scope le club (connexion `amateo_app` — JAMAIS la connexion `admin` qui la
 * contourne) : une cible d'un AUTRE club rend `null`, et l'appelant retombe sur le
 * header pendant que ses gardes tenant/404 gardent la main. Le garde saison ne doit
 * JAMAIS devenir un oracle d'existence : cible introuvable → `null` → repli.
 */
final class WriteTargetSeasonResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function ofSchedule(string $id): ?string
    {
        return $this->fetchSeasonId('SELECT season_id FROM schedule WHERE id = :id', $id);
    }

    public function ofSchedulePlan(string $id): ?string
    {
        return $this->fetchSeasonId('SELECT season_id FROM schedule_plan WHERE id = :id', $id);
    }

    public function ofScheduleSlot(string $id): ?string
    {
        return $this->fetchSeasonId('SELECT season_id FROM schedule_slot_template WHERE id = :id', $id);
    }

    /**
     * ⚠ SÉCURITÉ — le garde doit résoudre EXACTEMENT ce que résout le chemin d'écriture.
     *
     * Une version antérieure pré-filtrait l'id sur une regex d'UUID CANONIQUE (hyphéné). Or
     * `uuid_in` de PostgreSQL est plus permissif : il accepte la forme sans tirets, avec
     * accolades, avec des tirets déplacés, en majuscules. Le garde et le contrôleur ne
     * résolvaient donc pas le même ensemble d'ids — un différentiel de PARSEUR, et un
     * contournement complet : `str_replace('-', '', $id)` rendait `null` ici (« cible
     * introuvable » → repli, le garde ne mord pas) pendant que la même chaîne désignait
     * parfaitement la ressource côté écriture. Mesuré : `clear-grid` sur un plan de saison
     * archivée rendait 200 au lieu de 409, et `reset-grid` écrivait des lignes neuves dans la
     * saison gelée (security-review SEC-13, 2026-08-21).
     *
     * On laisse donc la BASE trancher ce qu'est un uuid, et on n'attrape que son refus. Ne
     * jamais réintroduire de validation d'id ici : toute règle plus stricte que Postgres rouvre
     * la même porte. Si l'on veut restreindre les formes acceptées, cela se fait EN AMONT (des
     * `requirements` de route, une validation du corps) — pour que le garde et l'écriture voient
     * la même chose.
     */
    private function fetchSeasonId(string $sql, string $id): ?string
    {
        try {
            $value = $this->entityManager->getConnection()->fetchOne($sql, ['id' => $id]);
        } catch (DriverException) {
            // 22P02 « invalid input syntax for type uuid » : aucune forme d'uuid, donc aucune
            // cible possible → repli. Le catch existe pour ne pas avorter la transaction sous
            // le harnais de test, PAS pour filtrer — cf. le docblock ci-dessus.
            return null;
        }

        return false === $value || null === $value ? null : (string) $value;
    }
}
