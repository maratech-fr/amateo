<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\WindowAlreadyPlannedException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ADR-0002 inv. 4 (amendement P2-38, 2026-08-18) — UNE SEULE PLANIFICATION PAR FENÊTRE.
 *
 * Deux plans de période ne doivent jamais gouverner les mêmes dates. Cette garde s'appelle à
 * la NAISSANCE d'un plan de période (le geste « Adapter » et la création d'une entrée-SEMAINE
 * qui naît avec son plan — les deux seuls sites de naissance) : si un AUTRE plan gouverne tout
 * ou partie de la fenêtre qui naît, on refuse (409 `window_already_planned`) en NOMMANT le plan
 * déjà en place. On ne rétrécit ni ne supprime rien : le geste destructif reste au gestionnaire
 * (le message l'invite à modifier ou supprimer le planning existant, ou à découper la période).
 *
 * ⚠ Jamais posée à la création d'une entrée RACINE : déclarer une fermeture par-dessus des
 * vacances (le FAIT, sans plan) doit rester libre — c'est le PLAN d'adaptation qu'on borne.
 *
 * Les deux chevauchements LÉGITIMES qui ne déclenchent PAS la garde sont capturés par un seul
 * prédicat, l'ANCÊTRE RACINE `COALESCE(parent_entry_id, id)` (hiérarchie à un seul niveau, P2-5) :
 *  - parent ↔ enfant : une semaine vit DANS sa mère — même racine, exclue ;
 *  - semaines sœurs : même mère — même racine, exclue (leur non-chevauchement mutuel est déjà
 *    gardé à part par CalendarEntryStateProcessor::assertValidWeekChild, qu'on ne double pas).
 * Deux périodes RACINES distinctes ont des racines différentes : elles, se voient.
 *
 * Comparaison sur les colonnes DATE de `calendar_entry` (jamais les timestamptz du plan) : la
 * fenêtre du plan est GELÉE égale à celle de son entrée (inv. C1), et une comparaison DATE est
 * exacte au jour près, sans ambiguïté de fuseau. SQL brut : `season_filter` épingle les lectures
 * ORM à la saison active, or un plan peut vivre pour une autre saison ; RLS scope le club.
 */
final class PeriodWindowUniquenessGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * Les plages qu'un AUTRE plan de période gouverne à l'intérieur de la fenêtre visée, triées
     * par date de début. FOYER UNIQUE du prédicat : la garde d'écriture ({@see assertWindowFree})
     * et la route de lecture `GET /api/planned-windows` (P2-38, prévention) partagent CE seul texte
     * SQL — la lecture ne peut donc pas promettre une disponibilité que l'écriture refuse. La parité
     * est vraie PAR CONSTRUCTION (un seul SQL, pas deux jumeaux) ET prouvée par le comportement
     * (`PlannedWindowsParityTest`, falsifié dans les deux sens).
     *
     * `$excludedRootEntryId` exclut la FAMILLE de l'entrée qui interroge (même ancêtre racine :
     * parent↔enfant et semaines sœurs, cf. docblock de classe). `null` = aucune exclusion : le
     * chemin « mère pas encore créée » de la route (une fenêtre candidate qui n'a pas encore
     * d'entrée) n'a aucune famille à exclure — il voit donc TOUS les plans qui la recoupent.
     *
     * @param string      $clubId              le club interrogé
     * @param string      $seasonId            sa saison (deux périodes de saisons différentes ne se croisent jamais)
     * @param string|null $excludedRootEntryId l'ancêtre racine à exclure (`COALESCE(parent_entry_id, id)`), ou null
     * @param string      $start               la borne basse de la fenêtre visée (Y-m-d)
     * @param string      $end                 la borne haute de la fenêtre visée (Y-m-d)
     *
     * @return list<array{entry_id: string, entry_title: string, start_date: string, end_date: string}>
     */
    public function governingWindows(string $clubId, string $seasonId, ?string $excludedRootEntryId, string $start, string $end): array
    {
        /** @var list<array{entry_id: string, entry_title: string, start_date: string, end_date: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT e.id AS entry_id, e.title AS entry_title, e.start_date, e.end_date '
            . 'FROM schedule_plan p JOIN calendar_entry e ON e.id = p.calendar_entry_id '
            . 'WHERE p.club_id = :club AND p.season_id = :season AND p.type <> \'SEASON\' '
            // Chevauchement (inclusion OU recouvrement partiel) : début ≤ fin de l'autre ET fin ≥ début.
            . 'AND e.start_date <= :bornEnd AND e.end_date >= :bornStart '
            // La FAMILLE (même ancêtre racine) est exclue : parent↔enfant et semaines sœurs sont
            // légitimes. `:root IS NULL` = pas de famille à exclure (fenêtre candidate sans entrée).
            // CAST explicite en uuid : sans lui, un `:root` NULL n'a pas de type inférable (le
            // `IS NULL` seul laisse Postgres indéterminé) — le cast type le paramètre UNE fois, sans
            // dédoubler le SQL, et rend la comparaison uuid<>uuid explicite (colonnes id/parent_entry_id).
            . 'AND (CAST(:root AS uuid) IS NULL OR COALESCE(e.parent_entry_id, e.id) <> CAST(:root AS uuid)) '
            . 'ORDER BY e.start_date ASC',
            [
                'club' => $clubId,
                'season' => $seasonId,
                'bornStart' => $start,
                'bornEnd' => $end,
                'root' => $excludedRootEntryId,
            ],
        );

        return $rows;
    }

    /**
     * @param string $clubId          le club du plan qui naît
     * @param string $seasonId        sa saison (deux périodes de saisons différentes ne se croisent jamais)
     * @param string $bornRootEntryId l'ancêtre racine de l'entrée qui naît : son parentEntryId, sinon son id
     * @param string $bornStart       la borne basse de la fenêtre qui naît (Y-m-d)
     * @param string $bornEnd         la borne haute de la fenêtre qui naît (Y-m-d)
     *
     * @throws WindowAlreadyPlannedException un autre plan de période gouverne tout ou partie de la fenêtre
     */
    public function assertWindowFree(string $clubId, string $seasonId, string $bornRootEntryId, string $bornStart, string $bornEnd): void
    {
        $conflicts = $this->governingWindows($clubId, $seasonId, $bornRootEntryId, $bornStart, $bornEnd);
        if ([] === $conflicts) {
            return;
        }

        $conflict = $conflicts[0];
        $conflictEntryId = $conflict['entry_id'];
        $windowLabel = $this->schedulePlanProvisioner->windowLabel(
            new DateTimeImmutable($conflict['start_date']),
            new DateTimeImmutable($conflict['end_date']),
        );

        // NOMME la période telle que le gestionnaire l'a INTITULÉE (pas le nom auto-généré du
        // plan, qui répète déjà la fenêtre), + sa fenêtre, et invite aux DEUX issues explicites (modifier ou
        // supprimer le planning en place) plus la découpe en semaines — geste déjà existant (P2-36).
        // Aucun identifiant interne (gardé par PublicTextIsFreeOfInternalIdentifiersTest).
        throw new WindowAlreadyPlannedException($conflictEntryId, \sprintf('%s Une seule planification peut gouverner une même période : modifiez ce planning existant ou supprimez-le avant d’en créer un autre ici. Vous pouvez aussi découper la période en semaines pour les planifier séparément.', self::nameConflict($conflict['entry_title'], $windowLabel)));
    }
    /**
     * COMMENT ON NOMME une fenêtre déjà planifiée — foyer unique de la formule, partagé par le
     * refus 409 et par la route de lecture qui PRÉVIENT ce refus.
     *
     * ⚑ Pourquoi une méthode et pas deux littéraux qui se ressemblent : les deux textes n'ont pas
     * la même SUITE (le 409 invite aux issues, l'écran les offre en boutons), mais ils doivent
     * nommer la période et sa fenêtre de la MÊME manière — sinon le gestionnaire lit deux noms
     * pour un seul objet, et croit à deux plannings. Aucun identifiant interne
     * (`PublicTextIsFreeOfInternalIdentifiersTest`).
     */
    public static function nameConflict(string $entryTitle, string $windowLabel): string
    {
        return \sprintf('Ces dates sont déjà planifiées par « %s » (%s).', $entryTitle, $windowLabel);
    }

}
