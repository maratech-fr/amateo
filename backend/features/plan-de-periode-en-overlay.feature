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

  Scénario: Un remplissage honore un jour imposé déjà couvert par une séance épinglée du socle
    Le remplissage épingle en dur les séances de la version source : une séance épinglée le jour
    imposé SATISFAIT « au moins une séance ce jour-là » (ALIGN-16), sans quoi le jour imposé
    viderait le modèle et le remplissage échouerait. Il aboutit, l'équipe gardant sa séance ce jour.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une nouvelle période de fermeture dont le plan recopie les blocs partagés du socle
    Et une transcription depuis le socle qui verrouille ces séances, aboutie en une première version
    Et je libère un membre d'un bloc partagé en supprimant sa séance transcrite
    Et une règle « au moins une séance » sur le jour d'une séance épinglée du socle
    Quand je lance le remplissage de la période
    Alors le remplissage aboutit et l'équipe au jour imposé garde sa séance ce jour-là

  Scénario: Je re-date l'incident : le plan survit, la version aussi, et l'empreinte reste inchangée
    Prolonger une fermeture SANS faire entrer ni sortir de contrainte datée ne change rien à ce que
    le solveur placerait : l'empreinte de structure reste égale au snapshot figé de la version, donc
    le planning ne se signale PAS à régénérer (règle fondateur : si ça ne change rien au planning,
    pas de bandeau).
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une fermeture à venir avec une version overlay aboutie
    Quand je prolonge la fermeture de deux semaines
    Alors la période porte les nouvelles dates, son plan aussi, la version existe toujours et le planning n'est pas signalé à régénérer

  Scénario: Je re-date l'incident découpé : l'aperçu m'annonce ce qui change, je confirme
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une fermeture à venir découpée en trois semaines-segments, chacune avec son plan
    Quand je demande l'aperçu du re-datage qui la raccourcit d'une semaine, puis je confirme
    Alors l'aperçu annonçait plusieurs changements, la mère porte les nouvelles dates et ses semaines sont ré-appariées

  Scénario: Une fermeture déclarée pendant un plan validé retire ses verrous, le signale périmé, et il se régénère sans le gymnase fermé
    « Enregistrer une fermeture vaut geste » : déclarer la fermeture d'un gymnase un jour où un plan
    de période validé y avait une séance verrouillée retire ce verrou, et seulement lui ; le plan se
    signale périmé, et une fois rouvert il se régénère pour de vrai sans aucune séance à ce gymnase
    ce jour-là, la séance verrouillée d'un autre jour revenant à sa case.
    Étant donné un petit club réaliste avec un plan de période VALIDÉ dont deux séances sont verrouillées en dur sur deux jours différents
    Quand je déclare la fermeture du gymnase de la première séance verrouillée, le jour de cette séance
    Alors le verrou de ce jour-là est retiré, celui de l'autre jour est intact, et le plan de période est signalé périmé
    Quand je rouvre puis régénère le plan de période
    Alors la régénération aboutit sans aucune séance à ce gymnase ce jour-là, et la séance verrouillée de l'autre jour revient verrouillée à la même case
