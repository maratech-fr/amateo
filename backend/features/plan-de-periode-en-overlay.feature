# language: fr
Fonctionnalité: Un plan de période se génère en overlay, sur sa propre grille
  Le cockpit adapte une période (fermeture, vacances) : le geste fait naître un
  plan de période, on en génère une version en overlay — sur la grille PROPRE de
  la période, jamais l'union avec la saison — et la génération aboutit. Le
  remplissage d'un trou honore les blocs partagés recopiés du socle : un membre
  libéré recolle sur la case de son partenaire épinglé.

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

  Scénario: Une fermeture obtient son planning sur sa propre grille, occupations comprises ; la prolonger ne demande pas de le refaire
    Prolonger une fermeture sans faire entrer ni sortir de contrainte datée ne change rien à ce que
    le système placerait : le planning ne se signale pas à régénérer.
    La grille de la période est sa copie de celle de la saison, occupations comprises : la modifier ne touche pas la saison.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une période de fermeture à venir
    Et l'occupation « École » posée dans la grille de saison
    Quand j'ouvre son plan de période puis je génère une version en overlay
    Alors la génération overlay aboutit avec le statut « COMPLETED »
    Quand je prolonge la fermeture de deux semaines
    Alors la période porte les nouvelles dates, son plan aussi, la version existe toujours et le planning n'est pas signalé à régénérer
    Et le plan de période porte sa copie de l'occupation « École »
    Quand je déplace celle de la période au vendredi
    Alors celle de la saison n'a pas bougé
    Quand je reprends la grille du planning de saison pour ce gymnase
    Alors la période porte de nouveau l'occupation de la saison

  Scénario: Une règle de saison ajoutée après la naissance d'une fermeture entre dans sa génération
    Le plan de la fermeture est né avant la règle : elle y entre quand même, comme dans le planning de saison.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une fermeture déjà créée
    Quand j'ajoute la règle de saison « pas le vendredi »
    Et je génère une version de la fermeture
    Alors la génération de la fermeture respecte « pas le vendredi »

  Plan du scénario: J'écarte une équipe des vacances, je lui donne une séance, puis je lui rends ses réglages de saison
    Chaque génération des vacances suit le réglage du moment : écartée, l'équipe n'y joue pas, sauf la séance qu'elle
    tient par un créneau réservé dans les vacances (la réservation gagne) ; une séance, elle en a une ; ses réglages de
    saison rendus, elle retrouve ses deux séances, groupe mutualisé compris.
    Étant donné un petit club réaliste dont le planning de saison est en vigueur, et des vacances à venir
    Et <situation>
    Quand j'écarte les <équipe> des vacances
    Alors les <équipe> ont <écartée> séance dans le planning des vacances
    Quand je donne 1 séance aux <équipe> pour les vacances
    Alors les <équipe> ont 1 séance dans le planning des vacances
    Quand je rends aux <équipe> leurs réglages de saison
    Alors les <équipe> ont leurs 2 séances dans le planning des vacances

    Exemples:
      | équipe | situation                                                               | écartée |
      | U17M1  | les U17M1 n'ont rien de particulier dans les vacances                   | 0       |
      | U20M1  | les U20M1 ont un créneau réservé dans les vacances                      | 1       |
      | SM1    | les SM1 s'entraînent en groupe mutualisé avec les SF1 dans les vacances | 0       |

  Scénario: Un plan de vacances garde les séances qu'il avait à sa création quand celles du planning de saison changent
    Décision du fondateur (2026-11-22) : tant que le gestionnaire ne les change pas dans le plan de vacances, ses
    séances ne suivent plus le planning de saison ; l'équipe y apparaît alors avec un réglage propre.
    Étant donné un petit club réaliste dont le planning de saison est en vigueur, et des vacances à venir
    Quand les SM1 passent à 3 séances dans le planning de saison
    Alors les SM1 ont toujours 2 séances dans le planning des vacances
    Et les SM1 y figurent parmi les équipes au réglage propre, avec 2 séances

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
