# language: fr
Fonctionnalité: Une semaine n'est de vacances que si lundi à vendredi est couvert
  Une semaine ne relève des vacances que si toute sa plage lundi-vendredi tombe
  dans les vacances. Une semaine dont seule une partie est en vacances se planifie
  comme une semaine de saison, jamais comme une semaine de reprise.

  Scénario: Une semaine partiellement en vacances est refusée en reprise
    Étant donné le club de démonstration et son gestionnaire connecté
    Et des vacances jetables qui se terminent un lundi
    Quand je tente d'en détacher la semaine qui commence ce dernier lundi
    Alors la semaine est refusée car pas entièrement en vacances

  Scénario: Une semaine entièrement en vacances est acceptée
    Étant donné le club de démonstration et son gestionnaire connecté
    Et des vacances jetables qui se terminent un lundi
    Quand je détache une semaine entièrement comprise dans ces vacances
    Alors la semaine est acceptée et porte son plan
