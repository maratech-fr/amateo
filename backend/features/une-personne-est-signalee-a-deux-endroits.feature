# language: fr
Fonctionnalité: Une personne à deux endroits en même temps est signalée
  Compléter son modèle après génération — lier un coach déclaré tard, un joueur — peut laisser une
  séance DÉJÀ placée sans personne pour la tenir. L'application le voit sur le planning en vigueur
  et le DIT, sans rien régénérer : le seul coach dans deux gymnases différents = conflit ; le même
  gymnase = mutualisation voulue, donc rien.

  Scénario: Deux gymnases différents au même moment : la personne est signalée
    Étant donné le club de démonstration, connecté, avec son planning de saison en vigueur
    Quand je place deux séances qui se chevauchent dans deux gymnases différents, coachées par la même personne
    Alors le radar signale que cette personne est à deux endroits en même temps

  Scénario: Le même gymnase au même moment : mutualisation, rien n'est signalé
    Étant donné le club de démonstration, connecté, avec son planning de saison en vigueur
    Quand je place deux séances qui se chevauchent dans le même gymnase, coachées par la même personne
    Alors le radar ne signale aucun conflit pour cette personne

  Scénario: Un retard toléré ouvert par un adjoint est un relais, pas un conflit (chantier H)
    Deux séances se chevauchent dans deux gymnases pour la même personne, mais la séance rejointe en
    retard TOLÈRE ce retard et a un adjoint disponible qui l'ouvre : le radar l'inscrit comme un
    RELAIS, hors du compte des conflits — la personne arrive en retard, l'adjoint tient le début.
    Étant donné le club de démonstration, connecté, avec son planning de saison en vigueur
    Quand je place deux séances qui se chevauchent dans deux gymnases, dont la seconde tolère le retard et a un adjoint disponible
    Alors le radar ne compte aucun conflit pour cette personne, mais inscrit un relais
