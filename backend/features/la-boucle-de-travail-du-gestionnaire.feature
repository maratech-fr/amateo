# language: fr
Fonctionnalité: La boucle de travail du gestionnaire tient à la régénération
  Le vrai geste de retouche : le gestionnaire pose une contrainte d'équipe, réserve un créneau
  libre pour une occupation du club, verrouille une séance bien placée, puis régénère. Avant
  cela, il déplace une séance vers un jour où l'équipe s'entraîne déjà : refusé tant qu'elle n'y
  est pas autorisée, accepté à deux séances par jour avec la concession nommée, puis remise. Il
  ignore aussi une règle du club : le récapitulatif l'annonce, la génération ne l'applique ni ne la
  signale ; remise sur Obligatoire, l'annonce disparaît. La présence d'un salarié, elle, ne peut
  jamais être obligatoire. La nouvelle version honore le tout — ce qu'on saisit, le planning le
  respecte, et un verrou est souverain. Sur un petit club réaliste (palier 60 s).

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
    Et je règle « Les jeunes avant les grands » sur Ignorée
    Alors le récapitulatif annonce cette règle comme ignorée
    Quand je régénère le planning de saison du petit club
    Alors la régénération aboutit : le verrou tient sa case, la case réservée reste vide, SM1 n'a aucune séance avant 20h30, et aucun signalement ne porte sur l'ordre des âges
    Quand je remets « Les jeunes avant les grands » sur Obligatoire
    Alors le récapitulatif ne l'annonce plus comme ignorée
    Quand j'essaie de régler la présence d'un salarié sur Obligatoire
    Alors c'est refusé : « La présence d'un salarié ne peut pas être obligatoire : choisissez Souhaitée ou Ignorée. »
