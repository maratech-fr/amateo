# language: fr
Fonctionnalité: Ce que FBI doit encore refléter
  Quand l'appli et FBI divergent et que le gestionnaire GARDE l'appli, c'est FBI qui
  est en retard : il faut le corriger à la main dans le portail fédéral. L'appli tient
  le registre « à corriger dans FBI » — ce qu'il faut y taper, en face de ce que FBI
  affiche encore. Un re-dépôt de la même valeur ne le harcèle pas ; un dépôt qui montre
  FBI corrigé ferme l'entrée tout seul ; et il peut aussi la cocher « corrigé ».

  Scénario: Garder l'appli ouvre une entrée, fermée quand FBI est corrigé
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose puis place un domicile au gymnase « GYM BEHAT » à 15h30
    Et FBI affiche 17h00 et je garde l'appli
    Alors une entrée « à corriger dans FBI » est ouverte : taper 15:30, FBI affiche 17:00
    Quand FBI affiche toujours 17h00 au dépôt suivant
    Alors aucun écart n'est à traiter et l'entrée dit « vu dans FBI »
    Quand FBI est corrigé et affiche de nouveau 15h30
    Alors le registre « à corriger dans FBI » est vide

  Scénario: Le gestionnaire coche « corrigé dans FBI »
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose puis place un domicile au gymnase « GYM BEHAT » à 15h30
    Et FBI affiche 17h00 et je garde l'appli
    Alors une entrée « à corriger dans FBI » est ouverte : taper 15:30, FBI affiche 17:00
    Quand je marque la correction faite dans FBI
    Alors le registre « à corriger dans FBI » est vide
