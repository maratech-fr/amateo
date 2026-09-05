# language: fr
Fonctionnalité: Une semaine de vacances s'adapte et obtient son propre planning
  Le cockpit adapte une semaine de vacances (reprise, stage) : détacher la semaine
  fait naître son plan, on en génère une version — sur la grille PROPRE de la
  semaine, jamais l'union avec le planning de saison — et la génération aboutit.
  Les créneaux placés appartiennent au plan de la semaine, pas au socle.

  Scénario: Une semaine détachée des vacances obtient son plan et sa génération aboutit
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et des vacances jetables couvrant deux semaines pleines
    Quand je détache une semaine entière et je génère son planning
    Alors la génération aboutit sur la grille propre de la semaine, avec le statut « COMPLETED »
