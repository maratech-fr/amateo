# language: fr
Fonctionnalité: La boucle de travail du gestionnaire tient à la régénération
  Le vrai geste de retouche : le gestionnaire pose une contrainte d'équipe, réserve un créneau
  libre pour une occupation du club, verrouille une séance bien placée, puis régénère. Avant
  cela, il déplace une séance vers un jour où l'équipe s'entraîne déjà : refusé tant qu'elle n'y
  est pas autorisée, accepté à deux séances par jour avec la concession nommée, puis remise. Il
  ignore aussi une règle du club : le récapitulatif l'annonce, la génération ne l'applique ni ne la
  signale ; remise sur Obligatoire, l'annonce disparaît. La présence d'un salarié, elle, ne peut
  jamais être obligatoire. La nouvelle version honore le tout — ce qu'on saisit, le planning le
  respecte, et un verrou est souverain. Une séance que tient une réservation de gymnase ne se
  déplace pas depuis le planning : le refus dit de retirer d'abord la réservation, rien ne bouge.
  Sur un petit club réaliste (palier 60 s).
  Après la régénération, seule une donnée froide ou à chaud fait dire « périmé » au planning : une
  couleur ou un niveau que rien ne vise ne le périme pas ; un nom, un libellé, un adjoint, la case
  salarié, le véhiculé, les jours maximum d'un coach et un temps de trajet sont libres et ne le
  périment pas ; une séance de plus, un créneau de grille ou un coach-joueur le périment, et le geste
  inverse efface toujours le signal.

  Scénario: Je contrains, je réserve, je verrouille, je régénère : tout tient
    Étant donné un petit club réaliste dont le planning de saison est en vigueur, puis rouvert pour y retravailler
    Quand j'essaie de déplacer une séance de SF1 vers un jour où elle s'entraîne déjà
    Alors c'est refusé : une seule séance par jour
    Quand j'autorise 2 séances par jour pour SF1 et je refais ce déplacement vers un créneau qui ne chevauche pas l'autre séance
    Alors il est accepté, et le geste nomme la concession : deux séances à moins de 2 h d'écart
    Quand je remets cette séance à sa case d'origine
    Alors c'est accepté, et SF1 n'a plus qu'une séance ce jour-là
    Quand j'interdis à SM1 de s'entraîner avant 20h30
    Et je réserve un créneau libre sur une case occupée du socle
    Et je verrouille une autre séance du socle
    Et je réserve à SF1 la case de sa séance
    Et je règle « Les jeunes avant les grands » sur Ignorée
    Alors le récapitulatif annonce cette règle comme ignorée
    Quand je régénère le planning de saison du petit club
    Alors la régénération aboutit : le verrou tient sa case, la case réservée reste vide, SM1 n'a aucune séance avant 20h30, et aucun signalement ne porte sur l'ordre des âges
    Quand j'essaie de déplacer la séance réservée de SF1 vers une case libre
    Alors c'est refusé : « SF1 tient ce créneau par une réservation de gymnase », et la séance n'a pas bougé
    Quand je change la couleur d'un gymnase et le niveau de l'équipe Loisir
    Alors le planning n'est pas signalé périmé
    Quand j'ajoute une séance par semaine à l'équipe Loisir
    Alors il est signalé périmé
    Quand je la retire
    Alors il ne l'est plus
    Quand je renomme l'équipe Loisir
    Alors il ne l'est toujours pas
    Quand je renomme une contrainte
    Alors il ne l'est toujours pas
    Quand je renomme la réservation de SF1
    Alors il ne l'est toujours pas
    Quand j'ajoute Sacha comme adjoint de SF1
    Alors il ne l'est toujours pas
    Quand je coche salarié et véhiculé pour Dominique et lui fixe 3 jours au plus
    Alors il ne l'est toujours pas
    Quand je saisis puis corrige le trajet entre le Gymnase Nord et le Gymnase Sud
    Alors il ne l'est toujours pas
    Quand j'ajoute un créneau au Gymnase Sud le vendredi
    Alors il est signalé périmé
    Quand je retire ce créneau
    Alors il ne l'est plus
    Quand Alex joue aussi en SM1
    Alors il est signalé périmé
    Quand Alex ne joue plus en SM1
    Alors il ne l'est plus
    Quand je remets « Les jeunes avant les grands » sur Obligatoire
    Alors le récapitulatif ne l'annonce plus comme ignorée
    Quand j'essaie de régler la présence d'un salarié sur Obligatoire
    Alors c'est refusé : « La présence d'un salarié ne peut pas être obligatoire : choisissez Souhaitée ou Ignorée. »

  Scénario: Une séance verrouillée compte comme une séance de l'équipe
    Le moteur joue chaque séance verrouillée : une équipe ne peut pas en avoir, avec ses réservations, plus que de
    séances par semaine. Un verrou posé sur la case de sa propre réservation compte une fois. Le Récapitulatif nomme les
    séances verrouillées en trop, la régénération est refusée par le même motif, et ôter le bon cadenas lève le blocage.
    Étant donné un petit club réaliste dont le planning de saison est en vigueur, puis rouvert pour y retravailler
    Quand je verrouille les deux séances de U20M1 et lui réserve la case de la première
    Alors le récapitulatif ne bloque pas
    Quand je lui réserve un second créneau un jour où elle ne s'entraîne pas
    Alors le récapitulatif bloque U20M1 et nomme sa seule séance verrouillée hors réservation
    Quand j'essaie de régénérer le planning de saison du petit club
    Alors c'est refusé par le même motif, sans nouvelle version
    Quand j'ôte le cadenas de la séance posée sur la réservation
    Alors le récapitulatif bloque toujours par le même motif
    Quand j'ôte le cadenas de la séance nommée
    Alors le récapitulatif ne bloque pas
    Quand je retire ses deux réservations
    Alors U20M1 n'a plus ni réservation ni séance verrouillée
