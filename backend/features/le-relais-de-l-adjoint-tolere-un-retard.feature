# language: fr
Fonctionnalité: Le relais de l'adjoint tolère un retard
  Un coach principal qui enchaîne deux séances dans deux gymnases arrive parfois un peu en retard à
  la seconde. Le gestionnaire le SAIT et le matérialise : il tolère ce retard SUR LA SÉANCE rejointe,
  à la minute près (0 à 30), pré-rempli avec le retard calculé (fin de la première + trajet − début
  de la seconde). La tolérance ne tient QUE si un adjoint disponible ouvre la séance le temps que le
  principal arrive. Cas réel Rillieux : Sébastien finit à 20:15 au Mont Blanc et rejoint le Louison
  Bobet (séance 20:00) ; trajet 15 min, chevauchement 15 min → 30 min de retard, toléré, Jules ouvre.

  Scénario: Un retard toléré ouvert par un adjoint passe, avec un avertissement
    Étant donné le club de démonstration, connecté, avec son planning de saison en vigueur
    Et Sébastien coache les U21 au Mont Blanc le mardi de 19h00 à 20h15, et les SENIORS au Louison Bobet le mardi à 20h00
    Et le trajet entre le Mont Blanc et le Louison Bobet est de 15 minutes
    Et Jules est adjoint disponible des SENIORS le mardi
    Quand je tolère un retard de 30 minutes sur la séance des SENIORS et je demande le récap
    Alors le récap ne bloque pas, et il avertit que Sébastien arrivera en retard aux SENIORS, Jules ouvrant la séance

  Scénario: Sans adjoint disponible, le même retard bloque
    Étant donné le club de démonstration, connecté, avec son planning de saison en vigueur
    Et Sébastien coache les U21 au Mont Blanc le mardi de 19h00 à 20h15, et les SENIORS au Louison Bobet le mardi à 20h00
    Et le trajet entre le Mont Blanc et le Louison Bobet est de 15 minutes
    Et les SENIORS n'ont aucun adjoint disponible le mardi
    Quand je tolère un retard de 30 minutes sur la séance des SENIORS et je demande le récap
    Alors le récap bloque, car personne n'ouvre la séance des SENIORS
