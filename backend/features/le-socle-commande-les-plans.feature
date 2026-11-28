# language: fr
Fonctionnalité: Le planning de saison commande les plannings de période
  Le planning de saison est la base. Valider dit d'abord ce qui change, famille par
  famille : seul un changement du froid (règles, grille, réservations, coach principal…)
  efface les plannings de période ENTIÈREMENT à venir — jamais un planning déjà
  commencé, qu'on ne détruit pas au milieu, ni la collecte de doléances. Une séance
  déplacée s'annonce et ne supprime rien ; un verrou ne s'annonce pas. Le retravailler
  ne détruit rien : le planning validé reste en vigueur tant qu'une copie de travail
  n'est pas validée, et l'écran dit quand des modifications attendent la validation.
  Tant qu'aucun planning de saison n'a été validé, on ne peut pas ouvrir un planning
  de période : il n'y a pas de base sur laquelle l'adapter.

  Scénario: Valider annonce ce qui change ; ce froid supprime les plannings de période à venir, garde celui commencé et la collecte
    Étant donné un petit club dont le planning de saison est en vigueur
    Et un planning de période à venir et un planning de période déjà commencé
    Et une collecte de doléances ouverte sur des vacances à venir
    Et une règle et deux créneaux ajoutés au planning de saison, puis une version générée
    Quand je demande ce que change la validation
    Alors j'apprends que 1 règle et 2 créneaux changent
    Et l'annonce nomme les plannings de période à venir qui seront supprimés, et garde la doléance reçue
    Quand je valide en confirmant ce que la liste annonce
    Alors le planning de période à venir a disparu et celui déjà commencé subsiste
    Et la collecte et ses doléances des vacances à venir subsistent

  Plan du scénario: Ni un nom, ni un verrou, ni une séance déplacée ne suppriment les plannings de période à venir
    Une séance déplacée figure dans la liste, pour information ; un verrou et un nom n'y figurent pas.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et un planning de période à venir et un planning de période déjà commencé
    Et je retravaille le planning de saison
    Quand je <geste> puis je valide la copie de travail
    Alors la liste annonce <liste>
    Et les plannings de période à venir sont gardés

    Exemples:
      | geste                                         | liste             |
      | renomme une équipe                            | rien              |
      | renomme un groupe mutualisé                   | rien              |
      | verrouille une séance sans la déplacer        | rien              |
      | déplace une séance à la main                  | 1 séance déplacée |
      | déplace une séance à la main et la verrouille | 1 séance déplacée |

  Scénario: Retravailler le planning de saison ne détruit rien
    Une copie de travail naît de la version en vigueur, chargée ; tant qu'elle n'est pas validée, rien d'autre ne change.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et un planning de période à venir et un planning de période déjà commencé
    Et une collecte de doléances ouverte sur des vacances à venir
    Quand je retravaille le planning de saison
    Alors les plannings de période subsistent et les matchs restent ouverts
    Et la collecte et ses doléances des vacances à venir subsistent
    Et le planning de saison en vigueur reste le même, et la copie de travail est la version chargée
    Et la copie de travail est à jour

  Scénario: Retravailler alors que des modifications attendent les garde
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et le coach principal d'une équipe est changé, sans valider
    Quand je retravaille le planning de saison
    Alors le changement de coach est toujours en attente

  Scénario: L'écran sait quand des modifications attendent la validation ; revenir au planning en vigueur les abandonne
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Alors rien n'attend la validation
    Quand le coach principal d'une équipe est changé, sans valider
    Alors des modifications attendent la validation
    Quand je reviens au planning en vigueur
    Alors rien n'attend la validation
    Et l'équipe a retrouvé son coach principal
    Quand je retravaille le planning de saison
    Alors des modifications attendent la validation, sur une copie de travail
    Quand je valide la copie de travail
    Alors rien n'attend la validation

  Scénario: Le planning de saison et les vacances ont chacun leur grille
    Les vacances naissent avec une copie de la grille du planning de saison en vigueur, même quand des modifications attendent ; ensuite chacun garde la sienne.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et un créneau ajouté à la grille du planning de saison, sans valider
    Et des vacances à venir, adaptées sur la grille du planning de saison
    Alors la grille des vacances est celle du planning de saison en vigueur, sans le créneau ajouté depuis
    Et modifier la grille du planning de saison ne change pas la grille des vacances

  Scénario: Sans planning de saison jamais validé, ouvrir un planning de période est refusé
    Étant donné un petit club neuf, dont le planning de saison n'a jamais été validé
    Quand je tente d'ouvrir le planning d'une période à venir
    Alors l'ouverture est refusée faute de planning de saison en vigueur
