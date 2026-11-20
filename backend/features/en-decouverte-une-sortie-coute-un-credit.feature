# language: fr
Fonctionnalité: En Découverte, une sortie coûte un crédit
  Un club en offre Découverte a un nombre fixe de sorties gratuites, partagées entre ses
  gestionnaires. Chaque sortie (le planning en tableur, les matchs en PDF) en coûte une ;
  consulter ne coûte rien. Quand il n'en reste plus, la sortie est refusée et le message dit
  pourquoi et comment lever la limite. Un club de démonstration n'est jamais décompté.
  Le placement des matchs (abouti : un crédit ; refusé : aucun) se prouve dans
  `les-matchs-attendent-le-planning-de-saison.feature`, sur le même genre de club.

  Scénario: Chaque sortie coûte un crédit, consulter rien, et à zéro la sortie est refusée
    Étant donné un club inscrit en offre Découverte, avec 10 crédits et aucun d'utilisé
    Et un planning de saison en brouillon
    Quand je consulte mes plannings, leurs séances et mes matchs
    Alors j'ai utilisé 0 crédit sur 10
    Quand j'exporte le planning en tableur
    Alors j'ai utilisé 1 crédit sur 10
    Quand j'exporte les matchs de la semaine en PDF
    Alors j'ai utilisé 2 crédits sur 10
    Quand j'exporte le planning en tableur 8 fois
    Alors j'ai utilisé 10 crédits sur 10
    Et exporter le planning en tableur m'est refusé (403) : « Les 10 générations gratuites de votre club sont utilisées — l'offre Essentiel les rend illimitées. »
    Et générer le planning m'est refusé (403) : « Les 10 générations gratuites de votre club sont utilisées — l'offre Essentiel les rend illimitées. »

  Scénario: Un club de démonstration n'est jamais décompté
    Étant donné un club inscrit en offre Découverte, avec 10 crédits et aucun d'utilisé
    Et un planning de saison en brouillon
    Et le club passe en démonstration
    Quand j'exporte le planning en tableur
    Alors l'export est servi
    Et le club n'a utilisé aucun crédit
