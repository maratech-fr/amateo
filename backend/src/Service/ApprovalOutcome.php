<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ce que l'approbation d'une demande de club a RÉELLEMENT fait ({@see ClubApprovalService::approve}) —
 * le mail au demandeur et la réponse de la page publique en dépendent :
 *  - `Created`    : club neuf créé (ou club démo conservé repris), le demandeur en est gestionnaire ;
 *  - `Reprise`    : club réel SANS gestionnaire repris, le demandeur en devient gestionnaire ;
 *  - `Promoted`   : club réel GÉRÉ, le demandeur (Membre actif dont la demande de rôle a été
 *                   transmise à l'adresse officielle) devient gestionnaire À CÔTÉ des gestionnaires ;
 *  - `Pending`    : le club a des gestionnaires, la demande devient une adhésion EN ATTENTE ;
 *  - `Superseded` : la demande est dépassée (un gestionnaire est revenu entre-temps) ;
 *  - `NoOp`       : rien à faire — demande déjà décidée/annulée, ou demandeur effacé (404 public).
 */
enum ApprovalOutcome
{
    case Created;
    case Reprise;
    case Promoted;
    case Pending;
    case Superseded;
    case NoOp;
}
