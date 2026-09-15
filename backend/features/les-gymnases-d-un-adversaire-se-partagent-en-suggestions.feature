# language: fr
Fonctionnalité: Les gymnases d'un adversaire se partagent en suggestions
  Quand un club épingle le gymnase d'un adversaire, un autre club engagé à l'extérieur
  contre le MÊME adversaire voit ce gymnase suggéré — « choisi par N clubs », jamais
  LESQUELS. Le choix d'un club ne change jamais le trajet d'un autre, et retirer son
  choix fait redescendre le compte sans effacer la suggestion.

  Scénario: Un club voit le gymnase choisi par un autre, sans savoir qui
    Étant donné deux clubs engagés à l'extérieur contre le même adversaire
    Quand le premier club épingle un gymnase pour cet adversaire
    Alors le second club voit ce gymnase suggéré, choisi par 1 club
    Et la suggestion ne révèle jamais quel club l'a choisi
    Quand le second club épingle un autre gymnase pour cet adversaire
    Alors le trajet du premier club n'a pas changé
    Quand le premier club retire son choix
    Alors la suggestion reste, choisie par 0 club
