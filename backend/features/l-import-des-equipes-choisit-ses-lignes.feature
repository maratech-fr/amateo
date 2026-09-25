# language: fr
Fonctionnalité: L'import des équipes choisit ses lignes
  Pour éviter d'importer des doublons à l'onboarding, le gestionnaire dépose son
  fichier FFBB, VOIT les équipes qu'il contient et lesquelles sont déjà présentes,
  puis n'importe que les lignes qu'il coche.

  Scénario: Le dépôt liste le fichier et marque l'équipe déjà présente
    Étant donné le club de démonstration, connecté, avec son code FFBB
    Et une équipe jetable « BEHAT SF1 » déjà présente
    Quand j'analyse un fichier de trois équipes dont « BEHAT SF1 »
    Alors l'analyse liste les trois équipes
    Et « BEHAT SF1 » est marquée déjà présente
    Et « BEHAT SM2 » et « BEHAT U13 » ne sont pas marquées déjà présentes

  Scénario: Seules les lignes cochées s'importent
    Étant donné le club de démonstration, connecté, avec son code FFBB
    Quand j'importe le fichier en ne cochant que « BEHAT SM2 » et « BEHAT U13 »
    Alors « BEHAT SM2 » et « BEHAT U13 » sont créées
    Et « BEHAT SF1 » est absente de la base

  Scénario: Le fichier d'un autre club est refusé dès l'analyse
    Étant donné le club de démonstration, connecté, avec son code FFBB
    Quand j'analyse un fichier d'équipes portant le code d'un autre club
    Alors l'analyse est refusée
    Et aucune équipe jetable n'a été créée
