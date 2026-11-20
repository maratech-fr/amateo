# language: fr
Fonctionnalité: Le club de démonstration n'envoie rien à un tiers
  Une démonstration se joue devant un prospect, avec des coachs et des adresses qui ne sont pas
  les siennes : rien n'en sort. Ni conflits envoyés aux coachs, ni invitation, ni liens de
  collecte de doléances, et le compte de démonstration ne se supprime pas. À l'inverse, l'horloge
  simulée n'appartient qu'à la démonstration : un club réel ne la règle pas, et c'est bien parce
  qu'il n'est pas en démonstration — son gestionnaire la règle dès que le club y passe.

  Scénario: Un club réel ne règle pas l'horloge ; passé en démonstration, il la règle et n'envoie plus rien
    Étant donné un petit club réel dont le planning de saison est en vigueur, un coach joignable par e-mail et une collecte de doléances ouverte pour des vacances
    Quand je règle l'horloge simulée du club
    Alors c'est refusé (403) : « L'horloge simulée est réservée aux comptes de démonstration. »
    Quand le club et son gestionnaire passent en démonstration
    Alors chacun de ces envois m'est refusé (403), avec ce message :
      | envoi                                        | message                                              |
      | envoyer les conflits aux coachs              | Envoi d'e-mails indisponible en démonstration.       |
      | inviter quelqu'un dans le club               | Un compte de démonstration ne peut pas inviter.      |
      | envoyer les liens de la collecte de doléances | Envoi d'e-mails indisponible en démonstration.       |
      | supprimer mon compte                         | Ce compte de démonstration ne peut pas être modifié. |
    Et aucun e-mail n'est parti vers le coach ni vers la personne invitée
    Quand je règle l'horloge simulée du club
    Alors l'horloge simulée du club est réglée
