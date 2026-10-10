# language: fr
Fonctionnalité: Les entraîneurs expriment leurs vœux par un lien public, sans se connecter
  Le gestionnaire ouvre une campagne de sollicitation sur une période de
  vacances : chaque entraîneur reçoit un lien personnel. Ce lien EST son
  identité — il ouvre la page publique et soumet ses vœux SANS jamais se
  connecter (le seul chemin d'écriture non authentifié de l'application), et le
  vœu remonte côté gestionnaire.

  Scénario: Une campagne envoie un lien, l'entraîneur répond sans compte, le vœu remonte
    Étant donné le club de démonstration et son gestionnaire connecté
    Et un entraîneur rattaché à une équipe
    Et une période de vacances à venir
    Quand le gestionnaire ouvre une campagne de vœux et envoie les liens
    Alors au moins un lien de sollicitation est envoyé
    Et la page publique du lien reconnaît l'entraîneur sans qu'il se connecte
    Et l'entraîneur soumet ses vœux depuis cette page sans se connecter
    Et le vœu soumis remonte côté gestionnaire
    Et le jour souhaité par l'entraîneur remonte côté gestionnaire

  Scénario: Un entraîneur déclare une mutualisation sans compte, elle remonte côté gestionnaire
    Étant donné le club de démonstration et son gestionnaire connecté
    Et un entraîneur rattaché à une équipe
    Et une deuxième équipe pour la mutualisation
    Et une période de vacances à venir
    Quand le gestionnaire ouvre une campagne couvrant l'équipe et sa partenaire et envoie les liens
    Et l'entraîneur déclare une mutualisation avec la deuxième équipe sans se connecter
    Alors la mutualisation déclarée remonte côté gestionnaire

  Scénario: La collecte attend le planning des vacances
    Étant donné le club de démonstration et son gestionnaire connecté
    Et un entraîneur rattaché à une équipe
    Et une période de vacances à venir
    Quand le gestionnaire tente d'ouvrir une campagne sans planning de vacances
    Alors la collecte est refusée tant qu'aucun planning n'existe
    Et une fois le planning des vacances créé, la campagne s'ouvre
