# language: fr
Fonctionnalité: Une fermeture se prolonge à travers les vacances, sans perdre le planning déjà travaillé
  La mairie rallonge les travaux : la fermeture d'un gymnase, déjà validée, doit maintenant courir
  par-delà les vacances scolaires. Le gestionnaire ne régénère pas tout — il prolonge la fenêtre de
  la fermeture, voit l'aperçu exact, puis confirme. Le milieu déjà travaillé reste intact, les
  semaines ajoutées (avant la pause, après la pause, la fin entamée) naissent en copies validées du
  milieu, et les reprises de vacances déjà posées au milieu restent intactes et non validées : une
  période de vacances ignore les fermetures de gymnase, le gestionnaire les régénère quand il veut.
  Promesse éprouvée de bout en bout contre la vraie API.

  Scénario: Prolonger la fermeture par-delà les vacances donne quatre plannings et conserve le milieu
    Le gestionnaire part d'une fermeture validée qui s'arrête à l'entrée des vacances, avec des
    reprises non validées posées pendant les vacances. Il étend la fermeture au-delà des vacances.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une fermeture validée qui s'arrête à l'entrée de vacances portant deux reprises non validées
    Quand je prolonge la fermeture par-delà les vacances après avoir vu l'aperçu exact
    Alors l'aperçu annonçait exactement ce qui est appliqué
    Et la fermeture compte quatre plannings : le milieu conservé et trois copies validées
    Et la fermeture datée court jusqu'au nouveau bout de la fenêtre, son début inchangé
    Et les deux reprises de vacances recoupées restent intactes, non validées
