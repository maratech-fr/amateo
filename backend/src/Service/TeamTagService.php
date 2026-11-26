<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Enum\TeamTagAxis;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA règle des étiquettes d'équipe : un catalogue fermé de noms ({@see catalog}) et ce qu'une
 * équipe en porte ({@see tagNamesOf}), fonction de sa catégorie, de son sexe et de son niveau
 * LUS À L'INSTANT. Rien ne s'enregistre (lot conception 1, PR 3) : modifier une catégorie
 * déplace ses équipes dans toutes les saisons, sans écriture.
 *
 * Charger une version ne crée ni ne supprime de catégorie et ne touche pas leur durée de match
 * (lot 5) : seule la catégorie portée par une équipe revient, si elle existe encore.
 */
final class TeamTagService
{
    /** Deterministic axis of each system tag, for the constraint target grouping (Lot B). */
    private const SYSTEM_TAG_AXES = [
        'FEMININE' => TeamTagAxis::GENRE, 'MASCULINE' => TeamTagAxis::GENRE, 'MIXTE' => TeamTagAxis::GENRE,
        // ADULTE (= +18, ageMin >= 19) et SENIOR (= +22) sont deux tags d'âge qui SE
        // CHEVAUCHENT (décision fondateur 2026-08-15) : « Senior » 22-99 et « Vétéran »
        // 35-99 portent les deux, « U21 » 19-21 n'a qu'ADULTE. Voir `ageBracketsFor`.
        'BABY' => TeamTagAxis::AGE, 'EMB' => TeamTagAxis::AGE, 'JEUNE' => TeamTagAxis::AGE,
        'ADULTE' => TeamTagAxis::AGE, 'SENIOR' => TeamTagAxis::AGE,
        'U9' => TeamTagAxis::AGE, 'U11' => TeamTagAxis::AGE, 'U13' => TeamTagAxis::AGE,
        'U15' => TeamTagAxis::AGE, 'U18' => TeamTagAxis::AGE, 'U21' => TeamTagAxis::AGE,
        'ELITE' => TeamTagAxis::NIVEAU, 'REGIONAL' => TeamTagAxis::NIVEAU, 'NATIONAL' => TeamTagAxis::NIVEAU,
        'DEPARTEMENTAL' => TeamTagAxis::NIVEAU, 'LOISIR_ADULTE' => TeamTagAxis::NIVEAU, 'LOISIR_JEUNE' => TeamTagAxis::NIVEAU,
        'HONNEUR' => TeamTagAxis::NIVEAU, 'PROMOTION' => TeamTagAxis::NIVEAU, 'PRE_REGION' => TeamTagAxis::NIVEAU,
        // COMPETITION (axe NIVEAU) : équipe engagée en compétition — level non nul ET hors
        // loisir (voir `tagNamesOf`). Une équipe sans level n'est PAS en compétition.
        'COMPETITION' => TeamTagAxis::NIVEAU,
    ];

    /** La couleur de chaque nom du catalogue — celle que les lignes enregistrées portaient. */
    private const SYSTEM_TAG_COLORS = [
        'JEUNE' => '#FF6B6B',
        'ADULTE' => '#4ECDC4',
        'SENIOR' => '#009688',
        'COMPETITION' => '#B71C1C',
        'EMB' => '#45B7D1',
        'BABY' => '#F5A9C8',
        'U9' => '#96CEB4',
        'U11' => '#FFEAA7',
        'U13' => '#DDA0DD',
        'U15' => '#98D8C8',
        'U18' => '#F7DC6F',
        'U21' => '#BB8FCE',
        'FEMININE' => '#FF69B4',
        'MASCULINE' => '#4169E1',
        'MIXTE' => '#32CD32',
        'ELITE' => '#FFD700',
        'REGIONAL' => '#C0C0C0',
        'NATIONAL' => '#CD7F32',
        'DEPARTEMENTAL' => '#87CEEB',
        'LOISIR_ADULTE' => '#98FB98',
        'LOISIR_JEUNE' => '#90EE90',
        'HONNEUR' => '#F0E68C',
        'PROMOTION' => '#DDA0DD',
        'PRE_REGION' => '#B0E0E6',
    ];

    /**
     * Les catégories d'âge JEUNES au sens « U9–U18 » (U21 et seniors exclus). Sous-ensemble
     * nommé des tags d'âge système, consommé par {@see isYouthTeam}.
     */
    private const YOUTH_U_CATEGORIES = ['U9', 'U11', 'U13', 'U15', 'U18'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Le catalogue fermé : un nom = un groupe, identique pour tous les clubs. C'est ce que
     * `GET /api/team_tags` sert et ce qu'une règle de groupe peut viser ; l'axe range le
     * sélecteur de cible d'une règle.
     *
     * @return list<array{name: string, color: string, axis: TeamTagAxis}>
     */
    public function catalog(): array
    {
        $catalog = [];
        foreach (self::SYSTEM_TAG_AXES as $name => $axis) {
            $catalog[] = ['name' => $name, 'color' => self::SYSTEM_TAG_COLORS[$name], 'axis' => $axis];
        }

        return $catalog;
    }

    /** Ce nom est-il un groupe du catalogue ? Un nom hors catalogue ne désigne aucune équipe. */
    public function isCatalogName(string $name): bool
    {
        return isset(self::SYSTEM_TAG_AXES[$name]);
    }

    /**
     * Les tranches d'âge d'une catégorie — LA règle, isolée de toute lecture en base.
     *
     * ⚑ Extraite pour être vérifiable (D-35). Elle vivait au milieu d'une méthode qui charge
     * la `SportCategory` : impossible de la faire tourner sur le catalogue sans monter une
     * base, donc impossible de comparer ce qu'elle APPLIQUE à ce que les libellés ANNONCENT.
     * Or l'écart entre les deux a trompé des gestionnaires deux fois (P4-42, P4-63) sur des
     * contraintes qui peuvent être HARD. `TagLabelsMatchTheRuleTest` la compare désormais aux
     * étiquettes ; `TeamTagScopeTest` (phase1) garde ses frontières.
     *
     * ⚠ Un ENSEMBLE, plus un unique tag (décision fondateur 2026-08-15) : ADULTE et SENIOR
     * se CHEVAUCHENT. Le « bracket principal » reste tranché par la CHAÎNE de `if` ci-dessous,
     * dont l'ORDRE porte une garantie (BABY avant EMB : P4-42) ; SENIOR s'y AJOUTE quand
     * `ageMin >= 22`, sans jamais remplacer le principal ni toucher au sens de JEUNE/EMB/BABY.
     *
     * @return list<string>
     */
    public function ageBracketsFor(string $name, ?int $ageMin, ?int $ageMax): array
    {
        $brackets = [];

        $principal = $this->principalAgeBracket($name, $ageMin, $ageMax);
        if (null !== $principal) {
            $brackets[] = $principal;
        }

        // SENIOR (= +22) EN PLUS du principal. « Senior » (22-99) et « Vétéran » (35-99) le
        // portent donc en plus d'ADULTE ; « U21 » (19-21) reste ADULTE seul. C'est le seul
        // point où deux tranches coexistent — les autres restent exclusives.
        if (null !== $ageMin && $ageMin >= 22) {
            $brackets[] = 'SENIOR';
        }

        return $brackets;
    }

    /**
     * L'équipe est-elle JEUNE au sens U9–U18 (U21 et seniors exclus) ? Dérivé de sa catégorie
     * sportive par {@see tagNamesOf} — la SEULE maison du calcul d'âge d'une équipe,
     * jamais une seconde règle (le niveau d'une équipe jeune suit son engagement FFBB, décision
     * fondateur 2026-10-01 : un alignement de niveau ne s'écrit que sur une équipe jeune).
     */
    public function isYouthTeam(Team $team): bool
    {
        return [] !== array_intersect(self::YOUTH_U_CATEGORIES, $this->tagNamesOf($team));
    }

    /**
     * Les étiquettes que porte l'équipe, calculées à l'instant depuis sa catégorie (lue en
     * base), son sexe et son niveau. Une catégorie disparue ne donne aucun groupe d'âge.
     *
     * @return list<string>
     */
    public function tagNamesOf(Team $team): array
    {
        $tags = [];

        // Age-based tags from sport category
        $sportCategory = $this->entityManager->getRepository(SportCategory::class)->find($team->getSportCategoryId());
        if ($sportCategory instanceof SportCategory) {
            $ageMin = $sportCategory->getAgeMin();
            $ageMax = $sportCategory->getAgeMax();
            $name = $sportCategory->getName();

            foreach ($this->ageBracketsFor($name, $ageMin, $ageMax) as $bracket) {
                $tags[] = $bracket;
            }

            // U-category tags from name
            if (str_contains($name, 'U9')) {
                $tags[] = 'U9';
            } elseif (str_contains($name, 'U11')) {
                $tags[] = 'U11';
            } elseif (str_contains($name, 'U13')) {
                $tags[] = 'U13';
            } elseif (str_contains($name, 'U15')) {
                $tags[] = 'U15';
            } elseif (str_contains($name, 'U18')) {
                $tags[] = 'U18';
            } elseif (str_contains($name, 'U21')) {
                $tags[] = 'U21';
            }
        }

        // Gender tags
        $gender = $team->getGender();
        if (Gender::F === $gender) {
            $tags[] = 'FEMININE';
        } elseif (Gender::M === $gender) {
            $tags[] = 'MASCULINE';
        } elseif (Gender::MIXTE === $gender) {
            $tags[] = 'MIXTE';
        }

        // Level tags
        $level = $team->getLevel();
        if ($level instanceof TeamLevel) {
            $tags[] = $level->value;

            // COMPETITION (axe NIVEAU) — décision fondateur 2026-08-15 : une équipe est « en
            // compétition » dès qu'elle a un niveau NON loisir. Une équipe SANS level n'est
            // PAS en compétition (on ne contraint jamais par défaut) : la garde est déjà le
            // `instanceof TeamLevel` ci-dessus. Elle porte alors DEUX tags NIVEAU (ex.
            // REGIONAL + COMPETITION), ce que la liste plate autorise.
            if (!\in_array($level, [TeamLevel::LOISIR_ADULTE, TeamLevel::LOISIR_JEUNE], true)) {
                $tags[] = 'COMPETITION';
            }
        }

        return $tags;
    }

    /**
     * Le bracket PRINCIPAL — une seule tranche, tranchée par l'ORDRE des `if`.
     *
     * ⚠ BABY passe AVANT EMB (P4-42) : U5/U7 satisfont les deux bornes, c'est l'ORDRE qui
     * tranche — le réordonner élargirait EMB en silence. ADULTE (= +18) est le principal dès
     * `ageMin >= 19` (ce que « Adulte » a toujours signifié, jadis nommé SENIOR).
     */
    private function principalAgeBracket(string $name, ?int $ageMin, ?int $ageMax): ?string
    {
        if ($this->isBabyCategory($name, $ageMax)) {
            return 'BABY';
        }
        if (null !== $ageMax && $ageMax <= 12) {
            return 'EMB';
        }
        if (null !== $ageMax && null !== $ageMin && $ageMin <= 18) {
            return 'JEUNE';
        }
        if (null !== $ageMin && $ageMin >= 19) {
            return 'ADULTE';
        }

        return null;
    }

    /**
     * U5-U7, la tranche que le fondateur veut distinguer d'EMB (P4-42).
     *
     * Deux chemins, et l'ÂGE prime quand il est connu : `ageMax <= 7` couvre U5 (3-5) et
     * U7 (6-7), U9 commençant à 8.
     *
     * Le nom ne sert que lorsque `ageMax` est absent, et c'est le cas de « Baby basket » :
     * ses bornes sont `null` au catalogue, si bien que les trois branches d'âge étaient
     * fausses et qu'il n'avait AUCUN tag de tranche — invisible à toute contrainte par âge,
     * alors que le fondateur le tient pour du U5/U7. On le reconnaît donc comme les tags
     * U9…U21 le sont déjà : au nom de sa catégorie.
     *
     * ⚠ La bascule se fait sur `ageMax` SEUL, et non « aucune des deux bornes ». L'API rend
     * `ageMin` et `ageMax` indépendamment optionnels (`SportCategoryInput`) : exiger les
     * deux nulles laissait « Baby basket » renseigné du seul `ageMin` tomber hors de TOUTES
     * les branches — le trou même que ce lot vient boucher (revue #352). `ageMax` est de
     * toute façon le discriminant des branches voisines.
     *
     * ⚠ L'âge d'abord, délibérément : une catégorie personnalisée nommée « Baby … » mais
     * dotée d'un `ageMax` réel doit suivre son âge, pas son nom.
     *
     * ⚠ Et surtout : on ne DONNE PAS d'âges à « Baby basket » pour s'épargner ce test. Ça
     * l'enrôlerait dans la règle solveur d'âge croissant, qui exempte exprès les catégories
     * sans âge (`engine/app/solver/constraints.py`, « Loisir, Baby »), et il faudrait migrer
     * le catalogue recopié chez chaque club à l'inscription.
     */
    private function isBabyCategory(string $name, ?int $ageMax): bool
    {
        if (null !== $ageMax) {
            return $ageMax <= 7;
        }

        return str_contains(mb_strtolower($name, 'UTF-8'), 'baby');
    }
}
