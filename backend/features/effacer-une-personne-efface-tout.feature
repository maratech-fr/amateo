# language: fr
Fonctionnalité: Effacer une personne efface tout ce qui vient d'elle
  Une coach demande l'effacement de ses données. Aucun écran ne le permet : le support le fait par
  une commande, dans le club, en désignant l'un de ses rôles. Elle quitte toutes les saisons du club ;
  ses séances restent, sans coach, et la collecte ouverte passe au coach suivant. Plus rien dans la
  base ne porte son nom, son mail, son téléphone ni le commentaire de sa doléance : les plannings
  déjà générés, les signalements et la boîte d'un club de démonstration gardent leur contenu, avec
  « Personne supprimée » à la place de son nom.

  Plan du scénario: Le support efface une coach dont le planning est généré, la doléance commentée et le planning signalé
    Étant donné un petit club dont la coach « Camille Durand », joignable par mail et par téléphone, entraîne seule « U13M1 » et partage « U15M1 » avec « Dominique »
    Et « Camille Durand » est indisponible le vendredi, et le planning de saison est généré
    Et une collecte de doléances est envoyée pour des vacances à venir, et « Camille Durand » y répond avec un commentaire
    Et le club est <régime> quand le récapitulatif de la collecte part
    Et je signale le planning généré
    Quand le support efface la personne « Camille Durand »
    Alors plus aucune colonne de la base ne contient son nom, son mail, son téléphone ni son commentaire
    Et aucune copie d'une version ne garde l'identifiant de son rôle
    Et ses séances restent, sans coach
    Et la collecte ouverte désigne le coach suivant

    Exemples:
      | régime                                      |
      | réel                                        |
      | passé en démonstration sous horloge simulée |
