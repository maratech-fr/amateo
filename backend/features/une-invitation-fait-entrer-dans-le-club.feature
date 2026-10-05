# language: fr
Fonctionnalité: Une invitation fait entrer dans le club
  Un gestionnaire saisit une adresse e-mail et un rôle ; la personne reçoit un lien
  personnel. En le suivant, elle crée son compte (ou l'accepte connectée si elle en a
  déjà un) et arrive directement membre ACTIF du club, sans file d'approbation. Le lien
  est à usage unique : une fois accepté, l'invitation disparaît. Révoquée, elle rend un
  lien mort.

  Scénario: Un nouveau venu crée son compte et entre membre actif
    Étant donné un gestionnaire de club
    Quand le gestionnaire invite l'adresse e-mail d'un nouveau venu
    Et le nouveau venu ouvre son lien et crée son compte
    Alors le nouveau venu est membre actif du club
    Et l'invitation ne figure plus dans la liste du gestionnaire

  Scénario: Une personne qui a déjà un compte accepte en un clic
    Étant donné un gestionnaire de club et une personne qui a déjà un compte
    Quand le gestionnaire invite cette personne comme gestionnaire
    Et la personne connectée accepte son invitation
    Alors la personne est gestionnaire actif du club

  Scénario: Une invitation révoquée rend un lien mort
    Étant donné un gestionnaire de club qui a invité une adresse
    Quand le gestionnaire révoque l'invitation
    Alors le lien d'invitation est mort
