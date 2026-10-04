# language: fr
Fonctionnalité: Un compte sans club est supprimé au bout de trente jours
  Quand un membre perd son dernier accès à un club, son compte n'a plus de raison
  d'exister. On le prévient par e-mail en lui donnant une date, puis on supprime le
  compte trente jours plus tard. S'il regagne un accès avant l'échéance, le compte
  est conservé et le préavis est annulé.

  Scénario: Le membre désactivé est prévenu de la suppression de son compte
    Étant donné un gestionnaire de club et un membre actif de son club
    Quand le gestionnaire désactive l'accès du membre
    Alors le membre reçoit un e-mail l'informant de la date de suppression de son compte

  Scénario: À l'échéance, le compte du membre sans club disparaît
    Étant donné un gestionnaire de club et un membre actif de son club
    Quand le gestionnaire désactive l'accès du membre
    Et la date d'échéance de suppression est atteinte
    Et la purge des comptes sans club s'exécute
    Alors le compte du membre a disparu

  Scénario: Un membre réactivé avant l'échéance conserve son compte
    Étant donné un gestionnaire de club et un membre actif de son club
    Quand le gestionnaire désactive l'accès du membre
    Et le gestionnaire réactive l'accès du membre avant l'échéance
    Et la purge des comptes sans club s'exécute
    Alors le compte du membre est conservé
