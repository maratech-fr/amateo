# language: fr
Fonctionnalité: La saison suivante se prépare à partir du 1er mai
  Le gestionnaire prépare sa saison suivante à partir du 1er mai. Avant, le serveur refuse,
  même à un appel direct ou à un écran resté ouvert, et aucune saison ne naît. Un club de
  démonstration suit la même date, à son horloge simulée. Un club qui n'a rien préparé avant
  le 15 juillet attend le 1er mai suivant. Son espace annonce la date d'ouverture. Préparer est
  la seule porte : aucune saison ne naît d'une création directe par l'API.

  Plan du scénario: Le 30 avril, préparer est refusé ; le 1er mai, la saison naît
    Étant donné <club>
    Et nous sommes le <jour du refus>
    Quand je prépare la saison suivante
    Alors c'est refusé : « La saison suivante se prépare à partir du 1er mai. »
    Et le club n'a toujours qu'une saison
    Et mon espace annonce l'ouverture le <jour de l'ouverture>
    Quand nous sommes le <jour de l'ouverture>
    Et je prépare la saison suivante
    Alors la saison <saison née> naît

    Exemples:
      | club                                                             | jour du refus                     | jour de l'ouverture               | saison née                 |
      | un petit club réel qui a réglé la saison suivante                | 30 avril de la saison en cours    | 1er mai de la saison en cours     | suivante                   |
      | un petit club de démonstration                                   | 30 avril de la saison en cours    | 1er mai de la saison en cours     | suivante                   |
      | un petit club réel en retard, qui a réglé la saison d'après la suivante | 20 juillet de la saison suivante  | 1er mai de la saison suivante     | d'après la saison suivante |

  Scénario: Créer une saison par l'API est refusé, même quand préparer est ouvert
    Étant donné un petit club réel qui a réglé la saison suivante
    Et nous sommes le 1er mai de la saison en cours
    Quand je crée une saison par l'API, avec ses deux dates
    Alors l'API répond que cette création n'existe pas
    Et le club n'a toujours qu'une saison
