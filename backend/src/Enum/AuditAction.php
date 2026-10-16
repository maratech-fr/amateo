<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * RGPD — actions tracées dans le journal d'audit append-only (accountability).
 * Liste FERMÉE et volontairement courte : événements d'auth, opérations
 * destructrices et exercices de droits — pas un log applicatif générique.
 */
enum AuditAction: string
{
    case AUTH_REGISTER = 'auth.register';
    case AUTH_LOGIN_FAILED = 'auth.login_failed';
    case ACCOUNT_ERASED = 'account.erased';
    case EXPORT_USER = 'export.user';
    case EXPORT_CLUB = 'export.club';
    case ENTITY_DELETED = 'entity.deleted';
    case SEASON_RESET = 'season.reset';
    case SEASON_PURGED = 'season.purged';
    case CLUB_PURGED = 'club.purged';
    // P4-320 — gestes de GRILLE et de VERROU du plan : accountability RGPD (art. 5.2) sur qui a
    // touché quoi, quand, SANS PII. `lock.changed` = pose/retrait d'un verrou (ou « Charger »,
    // agrégé en un seul événement à compteur) ; `grid.changed` = création/édition d'un créneau de
    // grille ou d'un réglage de gymnase de période. Une suppression reste `entity.deleted`.
    case LOCK_CHANGED = 'lock.changed';
    case GRID_CHANGED = 'grid.changed';
    // P2-4 — le raccourci démo du register : émission d'une session démo + remplacement
    // du club démo précédent. Tracé GLOBALEMENT (club_id null) pour rester lisible même
    // après la suppression des lignes club détruites (M-3).
    case DEMO_SHORTCUT = 'demo.shortcut';
}
