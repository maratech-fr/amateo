# language: fr
Fonctionnalité: Une erreur FBI alimente le registre « à corriger dans FBI »
  Face à une collision de gymnase, le gestionnaire peut trancher que le tort vient de FBI
  (une salle importée fausse) plutôt que de son planning. Il pose le statut « erreur FBI » sur le
  conflit — qui reste sur le radar, comme tout conflit traité — ET, du même geste, une entrée
  apparaît dans le registre « à corriger dans FBI » pour la rencontre et le champ fautifs. La valeur
  cible reste vide : l'appli a importé l'erreur, elle ne connaît pas la bonne valeur (le champ est
  « à vérifier »). L'horloge est épinglée pour que les matchs du décor restent futurs.

  Scénario: Déclarer « erreur FBI » sur une salle pose la résolution ET ouvre une entrée de registre
    Étant donné un club de démonstration prêt à traiter une collision de gymnase
    Et deux matchs à domicile qui se chevauchent dans le même gymnase
    Quand le gestionnaire déclare « erreur FBI » sur la salle du premier match
    Alors ce conflit de gymnase porte la résolution « erreur FBI »
    Et le registre « à corriger dans FBI » gagne une entrée « salle à vérifier » pour ce match
