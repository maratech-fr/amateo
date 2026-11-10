# language: fr
Fonctionnalité: Les conflits partent aux coachs
  Sur l'onglet Conflits, le gestionnaire envoie à chaque coach concerné, à titre indicatif, les
  conflits de personne sur un match qui le touchent : lui-même en double, ou une personne d'une
  équipe dont il est le coach principal. Un coach sans adresse est sauté et nommé. Un coach qui
  avait reçu une liste et n'a plus de conflit reçoit un e-mail le disant — c'est la promesse
  « il ne pourra pas être surpris ». Par défaut, un nouvel envoi ne repart qu'aux coachs qui ont
  un conflit nouveau ou modifié, avec leur liste complète et les nouveautés marquées ; « Tout
  renvoyer » renvoie chaque liste. Le décor est un petit club jetable bâti par l'API ; les
  e-mails sont lus dans le webmail de test, comme le coach les reçoit.

  Scénario: Le gestionnaire prévient les coachs, puis leur dit quand c'est fini
    Étant donné un petit club dont le planning de saison est en vigueur
    Et Karim, avec une adresse, coache U15F1 et U13F1, qui jouent à l'extérieur le même samedi à 15h30 et à 16h
    Et Emma, sans adresse, coache U17F1 qui joue à l'extérieur ce samedi à 16h, et joue elle-même avec U15F1
    Quand le gestionnaire envoie les conflits aux coachs
    Alors Karim reçoit un e-mail qui liste ses 2 conflits de personne
    Et Emma est nommée comme coach sans adresse, et la ligne d'envoi compte 1 e-mail envoyé par le gestionnaire
    Quand le match de U15F1 est avancé au dimanche matin
    Et le gestionnaire envoie de nouveau les conflits aux coachs
    Alors Karim reçoit un e-mail qui lui dit que plus aucun conflit ne le concerne
    Et un nouvel envoi est refusé, faute de conflit à envoyer ou de coach à prévenir

  Scénario: Un deuxième envoi ne repart qu'avec le nouveau
    Étant donné un petit club dont le planning de saison est en vigueur
    Et Karim, avec une adresse, coache U15F1 et U13F1, qui jouent à l'extérieur le même samedi à 15h30 et à 16h
    Quand le gestionnaire envoie les conflits aux coachs
    Alors Karim reçoit un e-mail qui liste son conflit, sans marque de nouveauté
    Quand U11M1, aussi coachée par Karim, joue à l'extérieur ce samedi à 15h30
    Et le gestionnaire envoie de nouveau les conflits aux coachs
    Alors Karim reçoit un e-mail qui liste ses 3 conflits, dont 2 marqués nouveaux
    Et un nouvel envoi sans changement est refusé : rien de nouveau depuis le dernier envoi
    Quand le gestionnaire renvoie tout aux coachs
    Alors Karim reçoit sa liste complète, qui dit qu'aucun conflit n'est nouveau ou modifié depuis l'envoi précédent
