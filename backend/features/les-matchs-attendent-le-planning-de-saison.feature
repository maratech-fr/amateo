# language: fr
Fonctionnalité: Les matchs attendent le planning de saison
  Un match se place autour des entraînements : tant que le planning de saison n'est pas validé,
  le module des matchs refuse d'importer, de placer et d'envoyer les conflits aux coachs, et le
  dit. Une fois le planning validé, ces refus tombent. En offre Découverte, un placement refusé
  ne coûte rien ; un placement abouti coûte un crédit.

  Scénario: Avant le planning de saison validé, les matchs sont refusés ; après, le placement aboutit et coûte un crédit
    Étant donné un petit club neuf en offre Découverte, deux équipes et un gymnase ouvert aux matchs le samedi, sans planning de saison validé
    Alors chacun de ces gestes m'est refusé (409) : « Validez le planning de saison avant de créer un match ou un planning de période. »
      | geste                           |
      | importer les rencontres         |
      | placer les matchs du week-end   |
      | envoyer les conflits aux coachs |
    Et ces refus n'ont coûté aucun crédit
    Quand je génère et valide le planning de saison
    Alors aucun de ces gestes n'est plus refusé faute de planning de saison
      | geste                           |
      | importer les rencontres         |
      | placer les matchs du week-end   |
      | envoyer les conflits aux coachs |
    Quand un match à domicile de la première équipe est prévu ce samedi, et que je place les matchs du week-end
    Alors le match est placé dans le gymnase, le samedi
    Et ce placement abouti a coûté un crédit
