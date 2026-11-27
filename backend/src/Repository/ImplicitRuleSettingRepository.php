<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImplicitRuleSetting;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImplicitRuleSetting>
 */
final class ImplicitRuleSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImplicitRuleSetting::class);
    }

    /**
     * Les réglages d'UN plan (le plan de saison pour la portée saison, sparse ; un plan de période, indexés par la clé de règle. Un plan né après la
     * fonctionnalité en porte TOUJOURS 4 (copie totale) ; un plan legacy en porte 0 → le
     * résolveur retombe alors sur la portée saison (repli vivant).
     *
     * @return array<string, ImplicitRuleSetting>
     */
    public function findByPlanIndexed(string $clubId, string $seasonId, string $schedulePlanId): array
    {
        // Le club et la saison sont RÉPÉTÉS ici alors que le filtre Doctrine et RLS les
        // ajoutent déjà : c'est la 3e couche du modèle tenant (le provider scope
        // explicitement), et la seule qui survit à un contexte où les deux autres sont
        // relâchées — un id de plan ne se devine pas, mais on ne s'en remet pas à ça.
        $indexed = [];
        foreach ($this->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => $schedulePlanId]) as $setting) {
            $indexed[$setting->getRuleKey()->value] = $setting;
        }

        return $indexed;
    }

    /**
     * La ligne d'une règle pour une PORTÉE exacte : le plan de saison pour la saison, un plan de
     * période pour sa copie.
     */
    public function findOneByScopeKey(string $clubId, string $seasonId, string $schedulePlanId, ImplicitRuleKey $ruleKey): ?ImplicitRuleSetting
    {
        return $this->findOneBy([
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'schedulePlanId' => $schedulePlanId,
            'ruleKey' => $ruleKey,
        ]);
    }

    /**
     * Matérialise les 4 règles d'un plan de période — SI et seulement si le plan n'en porte
     * AUCUNE (idempotent, `NOT EXISTS`). Chaque ligne copie la valeur de la portée SAISON
     * (le plan de saison) si elle existe, sinon le défaut du contrat (HARD, seuil au défaut = params
     * NULL). Copie TOTALE : un plan « tout au défaut » garde ses 4 lignes, donc reste
     * distinguable d'un plan legacy sans copie.
     *
     * Appelée à la naissance d'un plan dans une saison sans plan de saison (`SchedulePlanProvisioner`) et à la
     * PREMIÈRE ÉCRITURE portant un `schedulePlanId` (processor) ; la naissance depuis le plan de saison a sa
     * copie à elle (`PlanBirth::copy`, par la porte de l'en vigueur) — jamais à la LECTURE (le build overlay n'écrit
     * pas). SQL brut : `season_filter` épingle les lectures ORM à la saison active, or un plan
     * peut naître pour une autre saison (transition) ; RLS scope le club. `gen_random_uuid()`,
     * comme la copie de grille (`PlanBirth::copy`).
     */
    public function materializeForPlan(string $clubId, string $seasonId, string $schedulePlanId): void
    {
        $keys = ImplicitRuleKey::cases();
        $valuesPlaceholders = [];
        $params = [
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'planId' => $schedulePlanId,
        ];
        // ⚑ P2-42 — le défaut est porté PAR CLÉ, plus par un paramètre unique. Avec un seul
        // `:defaultIntensity` à HARD, la règle opt-in `maxConsecutiveDays` naissait ACTIVE sur
        // chaque plan de période : le club ne l'avait pas demandée en saison, elle s'imposait
        // quand même à ses vacances. Une règle qui s'allume toute seule est exactement ce que
        // l'intensité OFF existe pour empêcher.
        foreach ($keys as $i => $key) {
            $valuesPlaceholders[] = \sprintf('(:k%d, :d%d)', $i, $i);
            $params['k' . $i] = $key->value;
            $params['d' . $i] = $key->isOptIn()
                ? ImplicitRuleIntensity::OFF->value
                : ImplicitRuleIntensity::HARD->value;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO implicit_rule_setting '
            . '(id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, rule_key, intensity, params) '
            . 'SELECT gen_random_uuid(), 1, now(), now(), :clubId, :seasonId, :planId, k.rule_key, '
            . 'COALESCE(s.intensity, k.default_intensity), s.params '
            . 'FROM (VALUES ' . implode(', ', $valuesPlaceholders) . ') AS k(rule_key, default_intensity) '
            . 'LEFT JOIN implicit_rule_setting s '
            . 'ON s.club_id = :clubId AND s.season_id = :seasonId AND s.rule_key = k.rule_key '
            . 'AND s.schedule_plan_id = (SELECT sp.id FROM schedule_plan sp WHERE sp.season_id = :seasonId AND sp.type = \'SEASON\') '
            . 'WHERE NOT EXISTS (SELECT 1 FROM implicit_rule_setting e WHERE e.schedule_plan_id = :planId)',
            $params,
        );
    }
}
