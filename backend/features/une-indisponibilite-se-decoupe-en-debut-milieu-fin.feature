# language: fr
Fonctionnalité: Une indisponibilité se découpe en début, milieu et fin
  Une fermeture qui déborde des semaines complètes se découpe en début (semaine
  entamée de tête), milieu (les semaines pleines, un seul plan) et fin (semaine
  entamée de queue). Jamais une semaine complète isolée, jamais « d'un bloc » sur
  une fermeture à semaine entamée ; les trois segments, eux, sont acceptés.

  Scénario: Une semaine complète isolée au milieu est refusée
    Étant donné le club de démonstration et son gestionnaire connecté
    Et une indisponibilité dont le milieu couvre plusieurs semaines pleines
    Quand je tente d'en détacher une seule semaine complète au milieu
    Alors le découpage est refusé car les semaines pleines forment un seul plan

  Scénario: Adapter « d'un bloc » une fermeture à semaine entamée est refusé
    Étant donné le club de démonstration et son gestionnaire connecté
    Et une indisponibilité qui a une semaine entamée
    Quand je tente de l'adapter d'un bloc
    Alors l'adaptation est refusée car la fermeture a une semaine entamée

  Scénario: La même fermeture se découpe en début, milieu et fin
    Étant donné le club de démonstration et son gestionnaire connecté
    Et une indisponibilité qui a une semaine entamée
    Quand je la découpe en début, milieu et fin
    Alors les trois segments sont acceptés et chacun porte son plan
