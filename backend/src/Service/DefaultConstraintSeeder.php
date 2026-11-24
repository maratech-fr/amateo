<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\Season;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Plans\PlanComposition;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-16 — les contraintes de base d'un club NEUF (« la page pas vierge », cadrage
 * ffbb-appariement-source-de-verite §1bis). Semées UNE fois, à la création du club
 * (`AuthController::seedNewClub`) — jamais au changement de saison (le planning
 * précédent y est copié, le travail est déjà prémâché).
 *
 * Décisions fondateur (2026-08-04) :
 *  - TOUT en PREFERRED — du HARD semé d'office peut rendre INFEASIBLE un club
 *    atypique dès son premier planning ; le gestionnaire durcit lui-même s'il veut ;
 *  - liste : jeunes ≤ 19h30 · baby ≤ 18h30 · EMB ≤ 19h · adultes ≥ 19h · pas le
 *    dimanche (club entier) ;
 *  - « pas après » se sème en `maxStartTime` (heure de DÉBUT) : `maxEndTime` n'existe
 *    qu'en HARD (le chemin soft de l'engine ne lit que min/maxStartTime) ;
 *  - visibles et supprimables comme n'importe quelle contrainte de l'écran
 *    Contraintes (jamais de règle implicite silencieuse — série ENGINE) ; `source`
 *    = 'onboarding_seed' les rend identifiables.
 *
 * Les cibles par tag suivent le patron de l'UI : scope CLUB + `config.targetTag`,
 * que `ScheduleConstraintBuilder::resolveTagToTeamIds` éclate en N contraintes
 * équipe à la génération. Un tag sans équipe (club sans baby) → no-op logué, jamais
 * une règle imposée à tout le club (garde existante du builder). Les NOMS reprennent
 * mot pour mot ceux que l'écran Contraintes aurait générés (libellés `tagLabels.ts`),
 * pour qu'une contrainte semée soit indistinguable d'une contrainte saisie.
 */
final class DefaultConstraintSeeder
{
    public const SOURCE = 'onboarding_seed';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanComposition $planComposition,
    ) {}

    public function seed(Club $club, Season $season): void
    {
        $rows = [
            // [nom (EXACTEMENT ce que le wizard génère : « <cible> · <prédicat> », cible tag =
            //  « Groupe <libellé affiché> »), family, config]
            ['Groupe Jeune (U13-U18) · pas après 19:30', ConstraintFamily::TIME, ['targetTag' => 'JEUNE', 'maxStartTime' => '19:30']],
            ['Groupe Baby (U5-U7, Baby basket) · pas après 18:30', ConstraintFamily::TIME, ['targetTag' => 'BABY', 'maxStartTime' => '18:30']],
            ['Groupe EMB (U9-U11) · pas après 19:00', ConstraintFamily::TIME, ['targetTag' => 'EMB', 'maxStartTime' => '19:00']],
            ['Groupe Adulte (+ de 18) · pas avant 19:00', ConstraintFamily::TIME, ['targetTag' => 'ADULTE', 'minStartTime' => '19:00']],
            ['Toutes les équipes · pas dimanche', ConstraintFamily::DAY, ['forbiddenDays' => [7]]],
        ];

        foreach ($rows as $order => [$name, $family, $config]) {
            $constraint = new Constraint;
            $constraint->setClubId($club->getId());
            $constraint->setSeasonId($season->getId());
            $constraint->setName($name);
            $constraint->setScope(ConstraintScope::CLUB);
            $constraint->setFamily($family);
            $constraint->setRuleType(ConstraintRuleType::PREFERRED);
            $constraint->setConfig($config);
            $constraint->setSource(self::SOURCE);
            $constraint->setSortOrder($order);
            $this->entityManager->persist($constraint);
        }

        // Lot 4, PR 4 — une règle permanente a sa ligne dans chaque plan de période de sa saison (aucun à la naissance
        // d'un club : la porte écrit ce qui attend, puis ne pose rien). Seule porte, comme pour tout créateur de règle.
        $this->planComposition->ensureLines($season->getId());
    }
}
