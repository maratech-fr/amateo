# language: fr
Fonctionnalité: Une personne à deux endroits en même temps est signalée
  Compléter son modèle après génération — lier un coach adjoint déclaré tard, un joueur — peut
  mettre une personne sur deux séances DÉJÀ placées qui se chevauchent. L'application le voit sur
  le planning en vigueur et le DIT, sans rien régénérer : deux gymnases différents = conflit ;
  le même gymnase = mutualisation voulue, donc rien.

  Scénario: Deux gymnases différents au même moment : la personne est signalée
    Étant donné le club de démonstration, connecté, avec son planning de saison en vigueur
    Quand je place deux séances qui se chevauchent dans deux gymnases différents, coachées par la même personne
    Alors le radar signale que cette personne est à deux endroits en même temps

  Scénario: Le même gymnase au même moment : mutualisation, rien n'est signalé
    Étant donné le club de démonstration, connecté, avec son planning de saison en vigueur
    Quand je place deux séances qui se chevauchent dans le même gymnase, coachées par la même personne
    Alors le radar ne signale aucun conflit pour cette personne
