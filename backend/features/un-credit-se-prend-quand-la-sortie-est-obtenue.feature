# language: fr
Fonctionnalité: Un crédit se prend quand la sortie est obtenue
  En offre Découverte, le club a dix sorties gratuites. Une sortie coûte un crédit quand je
  l'obtiens : une version prête, un PDF prêt à télécharger. Demander ne coûte rien ; une
  génération qui échoue ou qui est bloquée me laisse mon solde. Combler un planning de période
  est une sortie comme une autre : à zéro, il est refusé avant de partir au calcul. Avec un seul
  crédit, deux exports lancés coup sur coup ne produisent qu'un fichier, et celui déjà livré n'est
  jamais remplacé. Une offre payante, la bêta et la démonstration ne sont jamais décomptées.
  Ce que Behat ne peut pas provoquer (le registre des crédits en panne au milieu du résultat, un
  rendu PDF en échec, le comblement impossible pour le solveur) se prouve dans
  `MessageHandler/GenerationCreditIsPartOfTheResultTest` et `PdfCreditIsTakenOnDeliveryTest`.
  Le tableur et le PDF des matchs, réglés à la réponse : `en-decouverte-une-sortie-coute-un-credit.feature`.

  Scénario: Je comble un planning de période : mon solde baisse de 1 quand la version est prête ; sans crédit, combler est refusé
    Étant donné le petit club inscrit en offre Découverte, avec 10 crédits et aucun d'utilisé
    Quand je génère son planning de saison et le valide
    Alors la version est prête et j'ai utilisé 1 crédit sur 10
    Quand je déclare une fermeture de deux semaines et que son plan part du planning de saison
    Alors j'ai utilisé 1 crédit sur 10
    Quand je comble automatiquement le planning de la fermeture
    Alors la version est prête et j'ai utilisé 2 crédits sur 10
    Quand j'exporte le planning de saison en tableur 8 fois
    Alors j'ai utilisé 10 crédits sur 10
    Quand je tente de combler le planning de la fermeture
    Alors c'est refusé (403) : « Les 10 générations gratuites de votre club sont utilisées — l'offre Essentiel les rend illimitées. »
    Et aucune version n'est née du refus
    Et j'ai utilisé 10 crédits sur 10

  Scénario: Une génération impossible ou bloquée me laisse mon solde
    Étant donné le petit club inscrit en offre Découverte, avec 10 crédits et aucun d'utilisé
    Et un entraînement mutualisé qu'aucun créneau ne peut accueillir
    Quand je génère le planning de saison
    Alors la génération échoue et j'ai utilisé 0 crédit sur 10
    Quand je régénère la version échouée
    Alors la génération échoue et j'ai utilisé 0 crédit sur 10
    Étant donné un entraînement mutualisé dont aucun coach n'est jamais disponible
    Quand je tente de générer le planning de saison
    Alors c'est refusé comme un blocage (422), sans partir au calcul
    Et j'ai utilisé 0 crédit sur 10

  Plan du scénario: Avec un seul crédit, deux exports en PDF lancés coup sur coup ne produisent qu'un fichier
    Étant donné un club inscrit en offre Découverte, à qui il reste un seul crédit
    Quand je demande coup sur coup l'export PDF <premier> puis <second>
    Alors les deux demandes sont acceptées
    Et le premier fichier est prêt, se télécharge, et j'ai utilisé 10 crédits sur 10
    Et le second export <issue>
    Et j'ai utilisé 10 crédits sur 10

    Exemples:
      | premier                           | second                                | issue                                                        |
      | du planning A                     | du planning B                         | échoue, sans fichier                                         |
      | du planning A, tous les gymnases  | du planning A, au seul Gymnase Nord   | ne livre aucun fichier, et le premier reste téléchargeable   |
      | du planning A, tous les gymnases  | du planning A, tous les gymnases      | reçoit le même fichier que le premier                        |

  Scénario: Une offre payante, la bêta ou la démonstration ne décomptent jamais un PDF obtenu
    Étant donné un club inscrit en offre Découverte, à qui il reste un seul crédit
    Quand le club passe à l'offre Essentiel, réglée pour la saison
    Et j'exporte le planning A en PDF
    Alors le fichier est prêt et le club n'a utilisé aucun crédit de plus, sans plafond
    Quand le club passe à la bêta, réglée pour la saison
    Et j'exporte le planning A en PDF
    Alors le fichier est prêt et le club n'a utilisé aucun crédit de plus, sans plafond
    Quand le club revient en Découverte et passe en démonstration
    Et j'exporte le planning A en PDF
    Alors le fichier est prêt et le club n'a utilisé aucun crédit de plus, sans plafond
