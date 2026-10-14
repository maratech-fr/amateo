# language: fr
Fonctionnalité: Une invitation fait entrer dans le club
  Un gestionnaire saisit une adresse e-mail et un rôle ; la personne reçoit un lien
  personnel. En le suivant, elle crée son compte et arrive directement membre ACTIF du
  club, sans file d'approbation. On garde ici le seul enchaînement de bout en bout (le
  nouveau venu). L'acceptation en un clic d'un compte existant, l'usage unique et le lien
  mort d'une invitation révoquée/expirée sont prouvés au plus bas par
  Security/ClubInvitationTest (bloquant, chemin d'abus « invitation révoquée »).

  Scénario: Un nouveau venu crée son compte et entre membre actif
    Étant donné un gestionnaire de club
    Quand le gestionnaire invite l'adresse e-mail d'un nouveau venu
    Et le nouveau venu ouvre son lien et crée son compte
    Alors le nouveau venu est membre actif du club
    Et l'invitation ne figure plus dans la liste du gestionnaire
