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

  Scénario: Un mot de passe erroné est refusé avec un message en français
    Étant donné un club neuf dont le gestionnaire vient d'inscrire son compte et de valider son e-mail
    Quand il tente de se connecter avec un mot de passe erroné
    Alors la connexion est refusée avec le message « Identifiants invalides. »

  Scénario: Reprendre un club sans membre passe par l'approbation du contact officiel
    Étant donné un club neuf dont le gestionnaire vient d'inscrire son compte et de valider son e-mail
    Et le dernier membre actif de ce club s'en va
    Quand une nouvelle personne s'inscrit avec le code FFBB de ce club et fait valider sa demande
    Alors elle devient gestionnaire du club repris, sans qu'un second club soit créé
