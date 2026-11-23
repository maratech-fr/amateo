# language: fr
Fonctionnalité: Au-dessus du palier, plus de sorties
  À l'offre complète, le palier fixe le nombre d'équipes d'une saison : 20 au palier 1, 34 au
  palier 2, sans limite au palier 3. Toutes les équipes de la saison comptent, désactivées
  comprises. Un club qui compte plus d'équipes que son palier n'a plus de sorties : générer,
  régénérer, combler, placer les matchs, exporter le planning en tableur ou en PDF et exporter les
  matchs en PDF lui sont refusés, avec un message qui dit quoi faire ; chaque écran le prévient.
  Consulter, charger une version et télécharger un PDF déjà produit restent ouverts. Tout revient
  dès qu'il repasse sous son palier, en supprimant des équipes qui n'ont pas de match ou en
  changeant de palier ; une équipe qui a un match ne se supprime pas. Découverte, la bêta, le
  palier 3 et un club de démonstration ne sont jamais concernés.

  Scénario: Au palier 1 avec 21 équipes, aucune sortie n'est servie, et seul le palier rouvre quand les équipes jouent
    Étant donné le petit club, son planning de saison généré et validé, un PDF de ce planning déjà produit et un planning en brouillon
    Et le club compte 21 équipes, dont 3 désactivées
    Quand le support règle l'offre complète au palier 1 pour la saison en cours
    Alors le club est prévenu : « Votre club compte 21 équipes, votre palier en permet 20. Les générations, le placement des matchs et les exports sont suspendus : supprimez des équipes qui n'ont pas de match, ou changez de palier. »
    Et chacune de ces sorties m'est refusée (403) avec ce message :
      | sortie                         |
      | générer le planning            |
      | régénérer le planning          |
      | combler le planning            |
      | placer les matchs              |
      | exporter le planning en tableur |
      | exporter le planning en PDF    |
      | exporter les matchs en PDF     |
    Et aucune version, aucun placement ni aucun nouveau PDF n'est né des refus
    Et je peux toujours consulter mes plannings et mes matchs, et télécharger le PDF déjà produit
    Quand un match est prévu pour l'équipe SM1
    Et je tente de supprimer l'équipe SM1
    Alors la suppression est refusée (409) : « Cette équipe joue en compétition : ses matchs sont engagés auprès de la fédération. Elle ne peut plus être supprimée. »
    Et le club est toujours prévenu
    Quand le support règle l'offre complète au palier 2 pour la saison en cours
    Alors le club n'est plus prévenu
    Et aucune de ces sorties ne m'est plus refusée pour le palier :
      | sortie                          |
      | générer le planning             |
      | exporter le planning en tableur |

  Scénario: Supprimer une équipe sans match rouvre les sorties ; charger une version de 21 équipes est accepté, puis les sorties sont refusées
    Étant donné le petit club, avec 21 équipes, et une version de son planning de saison générée sans être validée
    Quand le support règle l'offre complète au palier 1 pour la saison en cours
    Alors le club est prévenu : « Votre club compte 21 équipes, votre palier en permet 20. Les générations, le placement des matchs et les exports sont suspendus : supprimez des équipes qui n'ont pas de match, ou changez de palier. »
    Quand je supprime une équipe qui n'a pas de match
    Alors le club n'est plus prévenu
    Et aucune de ces sorties ne m'est plus refusée pour le palier :
      | sortie                          |
      | exporter le planning en tableur |
    Quand je charge la version générée à 21 équipes
    Alors le chargement est accepté et le club compte 21 équipes
    Et le club est prévenu : « Votre club compte 21 équipes, votre palier en permet 20. Les générations, le placement des matchs et les exports sont suspendus : supprimez des équipes qui n'ont pas de match, ou changez de palier. »
    Et chacune de ces sorties m'est refusée (403) avec ce message :
      | sortie                          |
      | exporter le planning en tableur |
    Et charger cette version m'est toujours ouvert

  Plan du scénario: Avec 21 équipes, un club sans palier plafonné n'est jamais concerné
    Étant donné un club inscrit par l'API, avec 21 équipes et un planning de saison en brouillon
    Et <le régime du club>
    Alors le club n'est pas prévenu
    Et aucune de ces sorties ne m'est refusée pour le palier :
      | sortie                          |
      | exporter le planning en tableur |

    Exemples:
      | le régime du club                                                     |
      | aucun geste du support                                                |
      | le support règle la bêta pour la saison en cours                      |
      | le support règle l'offre complète au palier 3 pour la saison en cours |
      | le club passe en démonstration, sans aucun geste du support           |
