<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * P2-14 — LA résolution « nom de tag → équipes de la saison ». Elle existait en TROIS
 * exemplaires : l'expansion du payload (`ScheduleConstraintBuilder::resolveTagToTeamIds`),
 * le gate pré-solve (`ValidateConstraintsController::activeTagTeamIdsByName`) et, en creux,
 * chaque nouveau consommateur à venir. Trois implémentations de la même question = la
 * dérive gate/payload que P2-14 solde ; elle ne vit plus qu'ici.
 *
 * `ResetInterface` n'est PAS décoratif : le worker Messenger garde ses services d'un
 * message à l'autre, et `ResetServicesListener` ne vide que les services tagués
 * `kernel.reset`. Sans lui, le mémo d'une génération servirait à la SUIVANTE des tags
 * figés d'avant une édition (revue #340 round 2 — trouvé en vérifiant le fix round 1).
 */
final class TeamTagResolver implements ResetInterface
{
    /**
     * Les clés de `config` qui portent un ciblage par groupe (tag) — lues ICI, et
     * JAMAIS par le moteur (P2-29 D11 : l'expansion reste backend, le payload sort
     * des lignes TEAM sans clé de tag). Le builder les retire du config sérialisé.
     */
    public const array TAG_CONFIG_KEYS = ['targetTag', 'targetTags', 'excludeTags'];

    /** @var array<string, list<string>> mémo par MESSAGE/REQUÊTE — la sélection ET la sérialisation résolvent les mêmes tags */
    private array $memo = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * LA règle de groupement « nom de tag → équipes qui le portent » sous forme PURE — le
     * foyer côté serveur du calcul que `tagTeamIds()` fait par tag (résoudre une assignation
     * vers le nom de son tag, collecter les teamId, ignorer un tagId inconnu).
     *
     * Extrait pour la parité MÉCANIQUE avec le foyer FRONT `shared/lib/tagTeamIds.ts::buildTagTeamIds`
     * (le miroir client, utilisé par `applicableConstraints` ET `PeriodConstraints`, P4-88) :
     * cas partagés `tagTeamIds.parity.json`, gardés par `TagTeamIdsMirrorParityTest`. Les
     * assignations sont supposées DÉJÀ filtrées à la saison (comme les reçoit le front) — la
     * saison n'entre pas dans le groupement, seulement dans la requête de `tagTeamIds()`.
     *
     * @param list<array{id: string, name: string}>      $tags
     * @param list<array{teamId: string, tagId: string}> $assignments
     *
     * @return array<string, list<string>> nom de tag => teamIds triés
     */
    public static function teamIdsByTagName(array $tags, array $assignments): array
    {
        $nameByTagId = [];
        foreach ($tags as $tag) {
            $nameByTagId[$tag['id']] = $tag['name'];
        }

        $byName = [];
        foreach ($assignments as $assignment) {
            $name = $nameByTagId[$assignment['tagId']] ?? null;
            if (null === $name) {
                continue; // tagId inconnu : aucune équipe, comme le NO-OP du backend
            }
            $byName[$name][] = $assignment['teamId'];
        }

        foreach ($byName as $name => $teamIds) {
            $unique = array_values(array_unique($teamIds));
            sort($unique);
            $byName[$name] = $unique;
        }

        return $byName;
    }

    /**
     * P2-29 D9 — LE foyer serveur de « (∩ targetTags) − (∪ excludeTags) », sous forme
     * PURE (aucune DB, juste de l'algèbre d'ensembles) : le VRAI calcul qu'une contrainte
     * CLUB ciblée par plusieurs tags — ou en excluant — désigne. `resolveConstraintTeamIds`
     * l'alimente en résolvant chaque tag par `tagTeamIds`, le builder (payload) et le
     * sélecteur de période (gate) passent tous deux par lui : une seule maison, jamais deux
     * réponses à « quelles équipes ». Le TRI final fait partie du contrat (il ordonne
     * l'expansion par équipe du payload, donc son hash).
     *
     * Un miroir front naîtra en PR 3 (`shared/lib/tagTeamIds.ts`) : les cas partagés ne
     * sont PAS étendus ici — ce lot ne touche pas le front.
     *
     * @param non-empty-list<list<string>> $targetSets  équipes par tag ciblé (INTERSECTION)
     * @param list<list<string>>           $excludeSets équipes par tag exclu (UNION soustraite)
     *
     * @return list<string> teamIds triés, dédoublonnés
     */
    public static function intersectMinusExclude(array $targetSets, array $excludeSets): array
    {
        $intersection = array_shift($targetSets);
        foreach ($targetSets as $set) {
            $intersection = array_values(array_intersect($intersection, $set));
        }

        $excluded = [];
        foreach ($excludeSets as $set) {
            foreach ($set as $id) {
                $excluded[$id] = true;
            }
        }

        $result = [];
        foreach ($intersection as $id) {
            if (!isset($excluded[$id])) {
                $result[$id] = true;
            }
        }
        $result = array_keys($result);
        sort($result);

        return $result;
    }

    /**
     * Ce `config` cible-t-il un ou plusieurs groupes (tags) ? Vrai dès qu'une des trois
     * clés est présente et non vide : `targetTag` (legacy), `targetTags` (intersection),
     * `excludeTags` (union soustraite — D8 : exclure sans cibler = toutes les équipes).
     *
     * @param array<string, mixed> $config
     */
    public static function targetsTags(array $config): bool
    {
        return [] !== self::targetTagNames($config) || [] !== self::excludeTagNames($config);
    }

    /**
     * Les noms de tags de CIBLE d'un config : `targetTags` s'il est non vide, sinon le
     * `targetTag` singulier (legacy, équivalent à `targetTags: [x]`), sinon `[]`.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    public static function targetTagNames(array $config): array
    {
        $plural = self::tagList($config['targetTags'] ?? null);
        if ([] !== $plural) {
            return $plural;
        }

        $singular = self::singularTag($config);

        return null !== $singular ? [$singular] : [];
    }

    /**
     * Les noms de tags EXCLUS d'un config (union soustraite).
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    public static function excludeTagNames(array $config): array
    {
        return self::tagList($config['excludeTags'] ?? null);
    }

    /**
     * Un libellé lisible des groupes visés — pour les warnings de sélection et le 422
     * « nommant les groupes ». Ex. « ADULTE + SENIOR sauf LOISIR_ADULTE ».
     *
     * @param array<string, mixed> $config
     */
    public static function tagTargetLabel(array $config): string
    {
        $targets = self::targetTagNames($config);
        $excludes = self::excludeTagNames($config);

        $label = [] === $targets ? 'toutes les équipes' : implode(' + ', $targets);
        if ([] !== $excludes) {
            $label .= ' sauf ' . implode(', ', $excludes);
        }

        return $label;
    }

    /**
     * Une liste de libellés de tags NON vides, normalisée depuis une valeur brute de config
     * (ignore ce qui n'est pas une chaîne non vide — robustesse aux imports/écritures SQL).
     *
     * @return list<string>
     */
    private static function tagList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $config */
    private static function singularTag(array $config): ?string
    {
        $tag = $config['targetTag'] ?? null;

        return \is_string($tag) && '' !== trim($tag) ? $tag : null;
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * Les équipes portant ce tag dans la saison, triées par id. Le TRI fait partie du
     * contrat : cette liste ordonne l'expansion par équipe d'une contrainte CLUB+targetTag,
     * donc l'ordre des lignes `constraints` du payload — et le hash calculé dessus. Le
     * `teamId` est STABLE (une équipe n'est pas recréée), contrairement à l'id d'assignation.
     *
     * Tag inconnu → liste vide + warning : la contrainte ne produira aucune ligne, et le
     * silence est exactement ce que la série ENGINE a soldé.
     *
     * @return list<string>
     */
    public function tagTeamIds(string $targetTag, string $seasonId, string $clubId): array
    {
        $memoKey = $clubId . '|' . $seasonId . '|' . $targetTag;
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        $tag = $this->entityManager->getRepository(TeamTag::class)->findOneBy(['name' => $targetTag, 'clubId' => $clubId]);
        if (!$tag instanceof TeamTag) {
            // Placeholders PSR-3, pas d'interpolation : un message unique par tag/club
            // casserait le regroupement/dédoublonnage des agrégateurs de logs.
            $this->logger->warning(
                'Tag \'{targetTag}\' not found for club {clubId} — constraint will be ignored.',
                ['targetTag' => $targetTag, 'clubId' => $clubId, 'seasonId' => $seasonId],
            );

            return $this->memo[$memoKey] = [];
        }

        $teamIds = [];
        foreach ($this->entityManager->getRepository(TeamTagAssignment::class)->findBy(['tagId' => $tag->getId(), 'seasonId' => $seasonId]) as $assignment) {
            $teamIds[] = $assignment->getTeamId();
        }
        sort($teamIds);

        return $this->memo[$memoKey] = $teamIds;
    }

    /**
     * P2-29 D9 — L'ensemble d'équipes FINAL que vise une contrainte CLUB ciblée par tag(s) :
     * (∩ targetTags) − (∪ excludeTags). Le legacy `targetTag` vaut `targetTags: [x]`.
     * D8 : `excludeTags` seul (aucune cible) → base = TOUTES les équipes de la saison
     * (`$seasonTeamIds`), moins les exclus. Passe par le foyer pur `intersectMinusExclude`,
     * comme le gate — d'où la parité.
     *
     * Résolution vide (typo, équipes désactivées, tags non ré-appliqués après bascule de
     * saison) : liste vide, gérée en NO-OP par ses appelants (le builder saute la contrainte,
     * le gate la déclare cachée) — jamais un ban tous-clubs.
     *
     * @param array<string, mixed> $config
     * @param list<string>         $seasonTeamIds base D8 (exclusion sans cible)
     *
     * @return list<string> teamIds triés
     */
    public function resolveConstraintTeamIds(array $config, string $seasonId, string $clubId, array $seasonTeamIds): array
    {
        $targetTags = self::tagList($config['targetTags'] ?? null);
        $singular = self::singularTag($config);
        $excludeTags = self::excludeTagNames($config);

        if ([] !== $targetTags) {
            $targetSets = array_map(fn (string $tag): array => $this->tagTeamIds($tag, $seasonId, $clubId), $targetTags);
        } elseif (null !== $singular) {
            $targetSets = [$this->tagTeamIds($singular, $seasonId, $clubId)];
        } else {
            // D8 : exclusion sans cible → base = toutes les équipes de la saison.
            $base = array_values(array_unique($seasonTeamIds));
            sort($base);
            $targetSets = [$base];
        }

        $excludeSets = array_map(fn (string $tag): array => $this->tagTeamIds($tag, $seasonId, $clubId), $excludeTags);

        return self::intersectMinusExclude($targetSets, $excludeSets);
    }
}
