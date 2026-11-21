# language: fr
Fonctionnalité: Une règle de catégorie suit ses équipes
  Le gestionnaire écrit une règle pour un groupe d'équipes (« les U13 ne commencent pas avant
  19h30 »), pas pour chaque équipe. Une équipe qui change de catégorie change d'étiquette : la
  règle la suit, sans qu'on la réécrive. À la génération, la règle est honorée pour toutes les
  équipes du groupe, et seulement pour elles. Les étiquettes se calculent à la lecture, depuis
  la catégorie, le sexe et le niveau de l'équipe : rien ne s'enregistre.

  Scénario: Une équipe passée en U13 tombe sous la règle des U13, et la génération l'honore pour elles seules
    Étant donné un petit club de deux équipes U13, une équipe U15 « U15 A » et une équipe senior, sur deux gymnases
    Et une règle de groupe : les U13 ne commencent pas avant 19:30
    Quand l'équipe « U15 A » passe en catégorie U13
    Alors « U15 A » porte l'étiquette U13 et plus l'étiquette U15
    Et aucune étiquette n'est enregistrée pour le club
    Quand je génère le planning de saison
    Alors la génération aboutit
    Et aucune séance des trois équipes U13 ne commence avant 19:30
    Et la demande au moteur porte la règle pour ces trois équipes, et pour aucune autre

  Plan du scénario: Une règle de groupe qui ne peut rien désigner est refusée, et un groupe du catalogue est reconnu sans rien d'enregistré
    Étant donné un club qui n'a encore aucune équipe
    Quand j'écris une règle pour le groupe « <groupe> »
    Alors la règle est refusée : « <refus> »

    Exemples:
      | groupe  | refus                                                                                   |
      | INCONNU | Le groupe « INCONNU » n'existe pas dans ce club.                                        |
      | U13     | Le ciblage « U13 » ne désigne aucune équipe cette saison — la contrainte n'aurait aucun effet. |
