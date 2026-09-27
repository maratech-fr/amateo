<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Enum\TeamTagAxis;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

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
        // loisir (voir `determineTagNames`). Une équipe sans level n'est PAS en compétition.
        'COMPETITION' => TeamTagAxis::NIVEAU,
    ];

    /**
     * Les colonnes écrites par `insertMissingSystemTags`, dans l'ordre du tuple.
     *
     * ⚠ Le SQL est CONSTRUIT à partir de cette constante, il ne la recopie pas — sans quoi le
     * test qui la compare au mapping Doctrine garderait une copie et non le code (revue #356,
     * défaut que le correctif du round 1 avait lui-même introduit). Le garde vit dans
     * `TeamTagScopeTest::testTheInsertColumnListMatchesTheEntityMapping`.
     */
    private const INSERT_COLUMNS = ['id', 'version', 'created_at', 'updated_at', 'club_id', 'name', 'color', 'is_system', 'axis'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function syncTeamTags(Team $team, string $seasonId): void
    {
        $clubId = $team->getClubId();
        $teamId = $team->getId();

        // ⚠ LES TAGS D'ABORD, LES SUPPRESSIONS ENSUITE — l'ordre porte une garantie.
        //
        // `getOrCreateSystemTags` peut ÉCHOUER FORT (`RuntimeException`, voir sa relecture) :
        // tant qu'il passait APRÈS la suppression, l'équipe était déjà dépouillée quand
        // l'exception partait. Sous l'ancien flush inconditionnel, ces suppressions étaient
        // même déjà COMMITÉES — perte sèche. Depuis P2-13 elles resteraient simplement en
        // attente dans l'unit of work, ce qui n'est pas mieux : un flush ultérieur sur le
        // même EntityManager les commiterait sans rien pour les remplacer. En lisant les
        // tags d'abord, l'échec précède toute destruction : il n'y a rien à réparer.
        $systemTags = $this->getOrCreateSystemTags($clubId);

        // Existing assignments for this team/season.
        $existingAssignments = $this->entityManager->getRepository(TeamTagAssignment::class)->findBy([
            'teamId' => $teamId,
            'seasonId' => $seasonId,
        ]);

        // Determine which tags apply to this team, resolved to the system-tag ids we would write.
        $tagNames = $this->determineTagNames($team);
        $desiredTagIds = [];
        foreach ($tagNames as $tagName) {
            if (isset($systemTags[$tagName])) {
                $desiredTagIds[] = $systemTags[$tagName]->getId();
            }
        }

        // ⚠ COURT-CIRCUIT SI L'ENSEMBLE EST IDENTIQUE — NE RIEN ÉCRIRE (retour terrain 2026-09-27).
        //
        // Ce listener est rejoué à chaque écriture de Team (renommage, édition) ET à chaque édition
        // de SportCategory (`TeamTagSyncListener::categoryUpdated`, même quand seule la DURÉE de
        // match change). Un delete+recreate systématique du MÊME ensemble de tags écrivait des
        // `TeamTagAssignment` pour rien, ce que `ResourceChangeStaleScheduleListener` prenait pour
        // un vrai changement de ressource → bannière « à régénérer — les données du club ont
        // changé » à tort. On compare donc l'ensemble d'ids DÉSIRÉ à l'ENSEMBLE existant (par tag,
        // pas par ligne) : identiques ⇒ aucune écriture, aucun faux marquage.
        //
        // Approche choisie plutôt qu'un filtre de changeset côté listener (« seuls name/ageMin/
        // ageMax comptent ») : `determineTagNames` est libre d'aller lire un quatrième champ demain,
        // et le filtre serait alors un bug SILENCIEUX (cf. `TeamTagSyncListener::categoryUpdated`).
        // Comparer la SORTIE (l'ensemble de tags) reste correct quels que soient ses intrants.
        $existingTagIds = array_map(static fn (TeamTagAssignment $a): string => $a->getTagId(), $existingAssignments);
        sort($existingTagIds);
        $wantedTagIds = $desiredTagIds;
        sort($wantedTagIds);
        if ($existingTagIds === $wantedTagIds) {
            return;
        }

        // L'ensemble diffère : delete+recreate (l'écriture EST le signal de péremption légitime).
        foreach ($existingAssignments as $assignment) {
            $this->entityManager->remove($assignment);
        }

        // Create assignments
        foreach ($tagNames as $tagName) {
            if (!isset($systemTags[$tagName])) {
                continue;
            }

            $assignment = new TeamTagAssignment;
            // BCK-11 : l'assignation porte son tenant. L'équipe EST la source —
            // une assignation ne peut pas appartenir à un autre club qu'elle.
            $assignment->setClubId($clubId);
            $assignment->setTeamId($teamId);
            $assignment->setTagId($systemTags[$tagName]->getId());
            $assignment->setSeasonId($seasonId);

            $this->entityManager->persist($assignment);
        }
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

    /**
     * @return array<string, TeamTag>
     */
    private function getOrCreateSystemTags(string $clubId): array
    {
        $tags = $this->readClubTags($clubId);

        $axisBackfilled = false;
        foreach ($tags as $tag) {
            // Backfill de l'axe sur un tag antérieur au lot B (idempotent).
            //
            // ⚠ Le garde `isset(SYSTEM_TAG_AXES[...])` limite l'écriture aux NOMS système : un
            // tag personnalisé « MonTag » n'est jamais touché. En revanche un tag personnalisé
            // nommé « ELITE » l'est, et c'est VOULU (revue #356) — le nom étant unique dans un
            // club, « ELITE » désigne le niveau ELITE quelle que soit l'origine de la ligne,
            // et lui poser son axe le sort de la section « Autres » du sélecteur de cible.
            if (null === $tag->getAxis() && isset(self::SYSTEM_TAG_AXES[$tag->getName()])) {
                $tag->setAxis(self::SYSTEM_TAG_AXES[$tag->getName()]);
                $axisBackfilled = true;
            }
        }

        $requiredTags = [
            'JEUNE' => '#FF6B6B',
            'ADULTE' => '#4ECDC4',
            // Nouveau tag d'âge « + de 22 » (couleur distincte d'ADULTE, qu'il chevauche).
            'SENIOR' => '#009688',
            // Engagement en compétition (axe NIVEAU) — couleur reprise du tag maison BCCL.
            'COMPETITION' => '#B71C1C',
            'EMB' => '#45B7D1',
            // P4-42. Pas de migration : un club antérieur gagne le tag à sa prochaine écriture
            // d'équipe. La course qu'ouvrait cette ligne est fermée depuis P4-64 (index unique
            // + `ON CONFLICT DO NOTHING`, cf. `insertMissingSystemTags`).
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

        $manquants = array_diff_key($requiredTags, $tags);
        if ([] !== $manquants) {
            $this->insertMissingSystemTags($clubId, $manquants);
        }

        // Le backfill d'axe ci-dessus est une écriture ORM ordinaire : il part avec ce
        // flush — et c'est la SEULE chose que ce flush ait à porter (`insertMissingSystemTags`
        // écrit en SQL brut, hors unit of work). P2-13 : il était inconditionnel, donc
        // exécuté à CHAQUE `syncTeamTags` — c'est-à-dire une fois PAR ÉQUIPE d'un import
        // FFBB ou d'une bascule de saison — alors que le backfill ne concerne que des tags
        // antérieurs au lot B, une fois dans la vie du club.
        if ($axisBackfilled) {
            $this->entityManager->flush();
        }

        if ([] === $manquants) {
            return $tags;
        }

        // RELECTURE obligatoire après l'insertion (P4-64). `ON CONFLICT DO NOTHING` peut avoir
        // laissé passer une ligne écrite par une transaction concurrente : l'id que NOUS avons
        // tiré n'est alors pas celui qui existe en base, et une assignation le référençant
        // pointerait dans le vide. On repart donc de ce que la base contient vraiment.
        foreach ($this->readClubTags($clubId, array_keys($manquants)) as $nom => $tag) {
            $tags[$nom] = $tag;
        }

        // ⚠ ÉCHOUER FORT plutôt que perdre une assignation en silence (revue #356).
        //
        // Avant P4-64 la boucle construisait toujours l'objet en mémoire : un nom système ne
        // pouvait pas manquer, et le `continue` de `syncTeamTags` était du code mort. Depuis
        // que le jeu vient d'une RELECTURE, un nom qu'elle manquerait serait simplement sauté
        // et l'équipe perdrait ce tag, sans exception ni log. Le pire mode de panne, et
        // exactement celui que ce lot combat.
        //
        // ⚠ La portée de cette garde tient à l'ORDRE dans `syncTeamTags`, qui appelle cette
        // méthode AVANT de supprimer quoi que ce soit : quand l'exception part, les anciennes
        // assignations sont intactes (P2-13 — auparavant elles étaient déjà supprimées, et
        // même commitées par le flush inconditionnel d'alors).
        $introuvables = array_diff(array_keys($manquants), array_keys($tags));
        if ([] !== $introuvables) {
            throw new RuntimeException(\sprintf('Tags système introuvables après insertion pour le club %s : %s. Abandonner ici laisse les assignations existantes intactes — rien n\'a encore été supprimé.', $clubId, implode(', ', $introuvables)));
        }

        return $tags;
    }

    /**
     * LA lecture des tags d'un club — point unique, critère unique.
     *
     * ⚠ Aucun filtre sur `isSystem`, et cette méthode existe pour que ce choix ne puisse pas
     * diverger (revue #356). L'arbitre d'écriture est `ON CONFLICT (club_id, name)`, qui ne
     * regarde pas `is_system` : une lecture qui filtrerait dessus rendrait une ligne homonyme
     * non-système invisible à la lecture, avalée à l'insertion et absente de la relecture — et
     * `syncTeamTags` sauterait l'assignation APRÈS avoir supprimé les anciennes.
     *
     * Les deux lectures passaient auparavant par deux `findBy` distincts : rétablir « la parité
     * de critère » entre eux — geste naturel pour un mainteneur — réintroduisait le défaut. Ici
     * il n'y a plus qu'un endroit où le filtre pourrait être ajouté, et l'y ajouter fait rougir
     * `TeamTagScopeTest::testANonSystemTagWithASystemNameIsUsedInsteadOfBeingLost`.
     *
     * Le nom d'un tag est unique dans un club (`uniq_team_tag_club_name`) : un tag nommé
     * « U13 » EST le U13 du club. `is_system` dit d'où il vient, pas ce qu'il est.
     *
     * @param list<string>|null $noms restreint la lecture à ces noms ; null = tout le club
     *
     * @return array<string, TeamTag> indexés par nom
     */
    private function readClubTags(string $clubId, ?array $noms = null): array
    {
        $criteres = ['clubId' => $clubId];
        if (null !== $noms) {
            $criteres['name'] = $noms;
        }

        $tags = [];
        foreach ($this->entityManager->getRepository(TeamTag::class)->findBy($criteres) as $tag) {
            $tags[$tag->getName()] = $tag;
        }

        return $tags;
    }

    /**
     * Insère les tags système absents en **une** requête, sans jamais échouer sur une course.
     *
     * P4-64 — `getOrCreateSystemTags` lisait « ce tag manque » puis le persistait : deux
     * écritures de `Team` concurrentes sur le même club inséraient le tag DEUX fois, et le
     * résolveur en choisissait ensuite une pendant que les assignations se répartissaient
     * entre les deux. Une contrainte ciblant ce tag n'atteignait alors qu'une partie des
     * équipes, **sans erreur** — le tag était bien trouvé.
     *
     * L'index unique `uniq_team_tag_club_name` interdit désormais le doublon ; reste à ne pas
     * transformer la course en erreur 500 pour le perdant. D'où `ON CONFLICT DO NOTHING` :
     * celui qui arrive second n'écrit rien et lit la ligne de l'autre.
     *
     * ⚠ SQL natif plutôt que l'ORM, délibérément : un `flush()` qui viole une contrainte
     * **ferme l'EntityManager**, ce qui perdrait aussi le travail de l'appelant (assignations,
     * backfill d'axe). L'insertion doit donc ne jamais lever. Le prix est cette liste de
     * colonnes, à tenir alignée sur l'entité — comparée au mapping Doctrine par
     * `TeamTagScopeTest::testTheHandWrittenColumnListMatchesTheEntityMapping`.
     *
     * @param array<string, string> $manquants nom du tag → couleur
     */
    private function insertMissingSystemTags(string $clubId, array $manquants): void
    {
        $now = (new DateTimeImmutable)->format('Y-m-d H:i:sP');
        $valeurs = [];
        $params = [];

        foreach (array_keys($manquants) as $i => $name) {
            $valeurs[] = \sprintf('(:id%1$d, 1, :now, :now, :club, :name%1$d, :color%1$d, true, :axis%1$d)', $i);
            $params['id' . $i] = Uuid::v4()->toRfc4122();
            $params['name' . $i] = $name;
            $params['color' . $i] = $manquants[$name];
            $params['axis' . $i] = self::SYSTEM_TAG_AXES[$name]->value;
        }

        $params['now'] = $now;
        $params['club'] = $clubId;

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO team_tag (' . implode(', ', self::INSERT_COLUMNS) . ') VALUES '
            . implode(', ', $valeurs)
            . ' ON CONFLICT (club_id, name) DO NOTHING',
            $params,
        );
    }

    /**
     * @return list<string>
     */
    private function determineTagNames(Team $team): array
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
}
