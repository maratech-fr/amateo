# language: fr
Fonctionnalité: Un club neuf s'inscrit et obtient son premier planning
  Un gestionnaire crée son compte, valide son e-mail, saisit le strict minimum
  (une équipe, un gymnase avec un créneau, un entraîneur) et lance la génération.
  C'est le parcours d'accueil de bout en bout : « je m'inscris, je fais le
  minimum, je génère, et j'ai mon planning. »

  Scénario: Le club fraîchement inscrit saisit le minimum et génère son planning
    Étant donné un club neuf dont le gestionnaire vient d'inscrire son compte et de valider son e-mail
    Et son nouveau club est vide au départ
    Quand il saisit le minimum : une équipe, un gymnase avec un créneau, un entraîneur
    Et il lance la génération de son premier planning
    Alors son parcours d'accueil est marqué comme terminé
    Et la génération aboutit avec le statut « COMPLETED »
