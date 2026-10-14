# language: fr
Fonctionnalité: Un petit club réaliste se bâtit et se génère par l'API
  La maison du décor des parcours : un club jetable de 11 équipes et 3 gymnases, bâti
  entièrement par l'API comme le ferait un vrai gestionnaire, tient le palier solveur de
  60 secondes (bien plus léger que le club de démonstration complet). Ce smoke prouve que
  la fondation génère et valide son planning de saison ; les enchaînements s'appuient dessus.

  Scénario: Un petit club bâti par l'API génère et valide son planning de saison
    Étant donné un petit club réaliste bâti par l'API : 11 équipes, 3 gymnases, un coach sur deux équipes
    Quand je génère et valide le planning de saison du petit club
    Alors la génération aboutit et le socle du petit club est en vigueur
