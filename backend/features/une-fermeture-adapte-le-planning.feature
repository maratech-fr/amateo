# language: fr
Fonctionnalité: Une fermeture de gymnase adapte le planning de période, sur le petit club
  La mairie prévient : un gymnase ferme deux semaines. Le gestionnaire déclare la fermeture comme
  dans l'application (une période au calendrier ET une indisponibilité datée qui porte le gymnase),
  ouvre le plan de période et l'adapte. Sur un petit club réaliste de 11 équipes / 3 gymnases
  (palier 60 s), fermer le gymnase le PLUS OCCUPÉ du socle oblige le solveur à replacer ses séances
  sur les deux gymnases restants — faisable parce que les deux équipes de Camille sont mutualisées.
  Promesse éprouvée de bout en bout, contre la vraie API et le vrai moteur (rail async
  Messenger → worker → moteur → import).

  Scénario: La fermeture du gymnase le plus occupé replace ses séances ailleurs sans déclasser le rang S
    Le planning s'adapte : aucune séance ne se tient au gymnase fermé pendant la période, d'autres se
    tiennent bien sur les gymnases restants (le planning n'est pas vide), et les équipes de rang S
    (SM1, SF1) gardent toutes leurs séances — aucune prioritaire n'est déclassée.
    Étant donné un petit club réaliste dont les deux équipes de Camille sont mutualisées, avec son planning de saison en vigueur
    Et une fermeture de deux semaines du gymnase le plus occupé du socle, déclarée comme dans l'application
    Quand j'ouvre le plan de période et je génère une version
    Alors la génération aboutit, aucune séance ne se tient au gymnase fermé, d'autres se tiennent ailleurs, et aucune équipe de rang S ne perd de séance
