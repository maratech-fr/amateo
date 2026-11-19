# language: fr
Fonctionnalité: Une occupation nomme un moment où le gymnase n'est pas au club
  Dans l'écran Gymnases, le gestionnaire nomme un intervalle où le gymnase sert à
  d'autres (« École », le mardi de 14:00 à 16:15). L'occupation n'informe que le
  lecteur : elle ne change rien au planning. Une occupation et un créneau
  d'entraînement ne se superposent jamais à la saisie, avec les règles de
  chevauchement de deux créneaux ; leurs bornes peuvent se toucher.

  Scénario: Je nomme un moment où le gymnase n'est pas au club
    Étant donné un petit club réaliste bâti par l'API, sans génération
    Quand je pose l'occupation « École » le mardi de 14:00 à 16:15 dans la grille de saison d'un gymnase
    Alors l'occupation « École » est listée pour ce gymnase le mardi de 14:00 à 16:15
    Quand je pose un créneau d'entraînement le mardi à 15:00 dans ce gymnase
    Alors le créneau est refusé : « Ce créneau chevauche l'occupation « École » (mardi 14:00–16:15) du même gymnase. »
    Quand je pose une occupation sur le créneau d'entraînement du mardi à 18:00 de ce gymnase
    Alors l'occupation est refusée : « Cette occupation chevauche un créneau du même gymnase (mardi 18:00–19:30). »
    Quand je pose un créneau d'entraînement le mardi à 16:15 dans ce gymnase
    Alors le créneau est posé : ses bornes touchent celles de l'occupation
    Quand je déplace l'occupation « École » au vendredi de 13:45 à 15:45
    Alors l'occupation « École » est listée pour ce gymnase le vendredi de 13:45 à 15:45
    Quand je supprime l'occupation « École »
    Alors ce gymnase ne liste plus aucune occupation
