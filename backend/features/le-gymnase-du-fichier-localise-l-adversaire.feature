# language: fr
Fonctionnalité: Le gymnase du fichier localise l'adversaire tout seul
  Quand une rencontre à l'extérieur est importée avec, dans le fichier, le nom du
  gymnase où l'adversaire reçoit, « Mettre à jour les adversaires » retrouve ce
  gymnase FÉDÉRAL et localise l'équipe adverse toute seule — source automatique, sans
  qu'un gestionnaire ne saisisse rien. Un nom de gymnase inventé ne localise rien, et
  revenir au défaut du club efface la localisation automatique.

  Scénario: Le gymnase adverse écrit dans le fichier localise l'adversaire
    Étant donné une rencontre à l'extérieur dont le fichier porte un gymnase réel et un adversaire à code connu
    Quand le club met à jour ses adversaires
    Alors l'équipe adverse est localisée sur ce gymnase, source automatique
    Étant donné une autre rencontre dont le fichier porte un gymnase inventé
    Quand le club met à jour ses adversaires
    Alors cette équipe-là n'est pas localisée depuis le fichier
    Quand le club revient au défaut du club pour l'équipe localisée
    Alors la localisation automatique de cette équipe a disparu
