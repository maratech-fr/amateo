<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-294 — un club de démonstration prospect peut être CONSERVÉ 14 jours après la démo
 * (décision fondateur 2026-10-03, option B).
 *
 * `club.demo_retained_until` (DATE, nullable) = l'échéance de conservation d'un club démo
 * détaché de l'animateur. Non-null → le club survit à la purge nocturne jusqu'à cette date,
 * et l'approbation P3-4 du contact officiel sait le reprendre (code FFBB homonyme) plutôt que
 * d'en créer un neuf. Null = le cas de tout club (démo du jour à purger, vrai club).
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 * RLS sans objet (migrations sous `amateo_owner`, BYPASSRLS).
 */
final class Version20261005100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-294: club.demo_retained_until DATE NULL — kept prospect demo clubs (14-day retention).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD demo_retained_until DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP demo_retained_until');
    }
}
