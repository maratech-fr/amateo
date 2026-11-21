<?php

declare(strict_types=1);

namespace App\Structure;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\TransitionReminderLog;
use App\Service\SeasonDataPurger;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use ReflectionClass;

/**
 * Lit les déclarations `#[Family]` et `#[RefersTo]` des entités Doctrine et en rend les listes que
 * les purges suivent : `SeasonDataPurger` (purgedViaOwner, purgedWithSeason) et `ErasedClubPurger`
 * (purgedWithClub). Les purgeurs ne tiennent plus de liste propre.
 */
final class FamilyCatalog
{
    /**
     * La ligne pivot de la saison : de niveau club, mais effacée par la purge de saison elle-même,
     * sous condition (rétention, effacement), jamais par la boucle par club.
     */
    private const string SEASON_PIVOT = Season::class;

    /** Niveaux dont les familles portent `clubId` + `seasonId` et suivent la purge de saison. */
    private const array SEASON_LEVELS = [Level::SEASON, Level::PLAN, Level::VERSION];

    /** Niveaux dont une famille doit être purgée, épargnée avec sa raison, ou nommée ici. */
    private const array TENANT_LEVELS = [Level::CLUB, Level::SEASON, Level::PLAN, Level::VERSION];

    /**
     * Familles de niveau tenant qu'aucune boucle de purge n'efface et qui ne déclarent pas
     * d'épargne — chacune nommée avec sa raison (les tables traitées à part par la purge de saison
     * se lisent dans {@see SeasonDataPurger::HANDLED_APART}).
     *
     * @var array<class-string, string>
     */
    private const array OUTSIDE_THE_PURGE_LOOPS = [
        Club::class => 'la racine du tenant : l\'effacement GARDE la fiche (identité publique FFBB) et en vide l\'état d\'abonnement et le siège',
        TransitionReminderLog::class => 'journal d\'envoi des rappels de bascule, clé par saison sans club_id : jamais purgé aujourd\'hui (comportement conservé)',
    ];

    /** @var array<class-string, Family>|null */
    private ?array $families = null;

    private bool $tenantFamiliesAccountedFor = false;

    /** @var list<array{class: class-string, field: string, refersTo: RefersTo}>|null */
    private ?array $references = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** @return list<class-string> les entités Doctrine, triées */
    public function all(): array
    {
        return array_keys($this->families());
    }

    /** @param class-string|string $entityClass */
    public function of(string $entityClass): Family
    {
        return $this->families()[$entityClass]
            ?? throw new LogicException(\sprintf('%s ne déclare pas sa famille (#[Family]).', $entityClass));
    }

    /**
     * Les familles effacées par (club, saison), chaque enfant avant le parent dont il meurt
     * (références DELETE), à égalité dans l'ordre alphabétique.
     *
     * Une famille y entre si son niveau est saison, plan ou version, si elle porte les deux colonnes
     * `clubId` et `seasonId`, si elle n'est pas purgée par son propriétaire et si la purge de saison
     * ne l'épargne pas.
     *
     * @return list<class-string>
     */
    public function purgedWithSeason(): array
    {
        $this->assertEveryTenantFamilyIsAccountedFor();

        return $this->seasonFamilies();
    }

    /**
     * Les familles purgées par la saison de leur propriétaire : famille => [champ, classe du
     * propriétaire] (`#[RefersTo(owner: true)]`).
     *
     * @return array<class-string, array{0: string, 1: class-string}>
     */
    public function purgedViaOwner(): array
    {
        $owners = [];
        foreach ($this->references() as $reference) {
            if (!$reference['refersTo']->owner) {
                continue;
            }
            if (isset($owners[$reference['class']])) {
                throw new LogicException(\sprintf('%s déclare deux propriétaires.', $reference['class']));
            }
            $owners[$reference['class']] = [$reference['field'], $reference['refersTo']->family];
        }

        return $owners;
    }

    /**
     * Les familles de niveau club effacées par `clubId` à l'effacement du club : toutes celles qui
     * portent la colonne, sauf celles que l'effacement épargne et la ligne pivot de la saison.
     *
     * @return list<class-string>
     */
    public function purgedWithClub(): array
    {
        $this->assertEveryTenantFamilyIsAccountedFor();

        return $this->clubFamilies();
    }

    /**
     * Les colonnes qui désignent la famille donnée.
     *
     * @param class-string|string $entityClass
     *
     * @return list<array{class: class-string, field: string, onDelete: OnDelete}>
     */
    public function referencesTo(string $entityClass): array
    {
        $found = [];
        foreach ($this->references() as $reference) {
            if ($reference['refersTo']->family === $entityClass) {
                $found[] = ['class' => $reference['class'], 'field' => $reference['field'], 'onDelete' => $reference['refersTo']->onDelete];
            }
        }

        return $found;
    }

    /** @return list<class-string> */
    private function seasonFamilies(): array
    {
        $viaOwner = $this->purgedViaOwner();
        $candidates = [];
        foreach ($this->families() as $class => $family) {
            if (\in_array($family->level, self::SEASON_LEVELS, true)
                && $this->hasColumns($class, 'clubId', 'seasonId')
                && !isset($viaOwner[$class])
                && null === $family->keptAtSeasonPurge
            ) {
                $candidates[] = $class;
            }
        }

        return $this->childrenFirst($candidates);
    }

    /** @return list<class-string> */
    private function clubFamilies(): array
    {
        $purged = [];
        foreach ($this->families() as $class => $family) {
            if (Level::CLUB === $family->level
                && self::SEASON_PIVOT !== $class
                && $this->hasColumns($class, 'clubId')
                && null === $family->keptAtErasure
            ) {
                $purged[] = $class;
            }
        }

        return $purged;
    }

    /**
     * Une famille de niveau club, saison, plan ou version que ni la purge de saison ni
     * l'effacement n'emporte, qui ne déclare aucune épargne, n'a pas de propriétaire et n'est pas
     * un cas nommé, survivrait en silence à l'effacement RGPD — par exemple une famille qui désigne
     * son club par une association plutôt que par une colonne `clubId`. On refuse plutôt que d'omettre.
     */
    private function assertEveryTenantFamilyIsAccountedFor(): void
    {
        if ($this->tenantFamiliesAccountedFor) {
            return;
        }

        $accounted = array_fill_keys([
            ...$this->seasonFamilies(),
            ...$this->clubFamilies(),
            ...array_keys($this->purgedViaOwner()),
            ...array_keys(self::OUTSIDE_THE_PURGE_LOOPS),
            self::SEASON_PIVOT,
        ], true);
        $handledApart = SeasonDataPurger::HANDLED_APART;

        foreach ($this->families() as $class => $family) {
            if (!\in_array($family->level, self::TENANT_LEVELS, true)
                || isset($accounted[$class])
                || null !== $family->keptAtSeasonPurge
                || null !== $family->keptAtErasure
                || isset($handledApart[$this->entityManager->getClassMetadata($class)->getTableName()])
            ) {
                continue;
            }

            throw new LogicException(\sprintf('%s (niveau %s) n\'entre dans aucune purge : ni la purge de saison (colonnes clubId + seasonId), ni l\'effacement (colonne clubId), ni par un propriétaire, et ne déclare aucune épargne (#[Family(keptAtSeasonPurge: …, keptAtErasure: …)]).', $class, $family->level->name));
        }

        $this->tenantFamiliesAccountedFor = true;
    }

    /** @return array<class-string, Family> */
    private function families(): array
    {
        if (null !== $this->families) {
            return $this->families;
        }

        $families = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }
            $class = $metadata->getName();
            $attributes = new ReflectionClass($class)->getAttributes(Family::class);
            if ([] === $attributes) {
                throw new LogicException(\sprintf('%s ne déclare pas sa famille (#[Family]).', $class));
            }
            $families[$class] = $attributes[0]->newInstance();
        }
        ksort($families);

        return $this->families = $families;
    }

    /** @return list<array{class: class-string, field: string, refersTo: RefersTo}> */
    private function references(): array
    {
        if (null !== $this->references) {
            return $this->references;
        }

        $references = [];
        foreach (array_keys($this->families()) as $class) {
            foreach (new ReflectionClass($class)->getProperties() as $property) {
                foreach ($property->getAttributes(RefersTo::class) as $attribute) {
                    $references[] = ['class' => $class, 'field' => $property->getName(), 'refersTo' => $attribute->newInstance()];
                }
            }
        }

        return $this->references = $references;
    }

    /** @param class-string $class */
    private function hasColumns(string $class, string ...$fields): bool
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        foreach ($fields as $field) {
            if (!$metadata->hasField($field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ordre topologique sur les références DELETE entre les familles données : une famille sort
     * quand toutes celles qui meurent avec elle sont sorties. L'auto-référence n'impose rien (une
     * famille s'efface en une requête). Un cycle est une erreur de déclaration.
     *
     * @param list<class-string> $classes
     *
     * @return list<class-string>
     */
    private function childrenFirst(array $classes): array
    {
        $pendingChildren = array_fill_keys($classes, []);
        foreach ($this->references() as $reference) {
            $child = $reference['class'];
            $parent = $reference['refersTo']->family;
            if (OnDelete::DELETE === $reference['refersTo']->onDelete
                && $child !== $parent
                && isset($pendingChildren[$child], $pendingChildren[$parent])
            ) {
                $pendingChildren[$parent][$child] = true;
            }
        }

        $ordered = [];
        while ([] !== $pendingChildren) {
            $ready = array_keys(array_filter($pendingChildren, static fn (array $children): bool => [] === $children));
            if ([] === $ready) {
                throw new LogicException('Cycle entre références DELETE : ' . implode(', ', array_keys($pendingChildren)));
            }
            sort($ready);
            $next = $ready[0];
            $ordered[] = $next;
            unset($pendingChildren[$next]);
            foreach ($pendingChildren as $parent => $children) {
                unset($pendingChildren[$parent][$next]);
            }
        }

        return $ordered;
    }
}
