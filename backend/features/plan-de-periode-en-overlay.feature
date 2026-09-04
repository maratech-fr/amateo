# language: fr
Fonctionnalité: Un plan de période se génère en overlay, sur sa propre grille
  Le cockpit adapte une période (fermeture, vacances) : le geste fait naître un
  plan de période, on en génère une version en overlay — sur la grille PROPRE de
  la période, jamais l'union avec la saison — et la génération aboutit. Le
  remplissage d'un trou honore les blocs partagés recopiés du socle : un membre
  libéré recolle sur la case de son partenaire épinglé.

  Scénario: Une période de fermeture obtient son plan et sa génération overlay aboutit
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une période de fermeture à venir
    Quand j'ouvre son plan de période puis je génère une version en overlay
    Alors la génération overlay aboutit avec le statut « COMPLETED »

  Scénario: Le remplissage recolle un membre de bloc libéré sur la case de son partenaire épinglé
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une nouvelle période de fermeture dont le plan recopie les blocs partagés du socle
    Et une transcription depuis le socle qui verrouille ces séances, aboutie en une première version
    Et je libère un membre d'un bloc partagé en supprimant sa séance transcrite
    Quand je lance le remplissage de la période
    Alors le remplissage aboutit et le membre libéré partage de nouveau la case de son partenaire épinglé

  Scénario: Je re-date l'incident : le plan survit et sa version est marquée à régénérer
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une fermeture à venir avec une version overlay aboutie
    Quand je prolonge la fermeture de deux semaines
    Alors la période porte les nouvelles dates, son plan aussi, la version existe toujours et le planning est signalé à régénérer
