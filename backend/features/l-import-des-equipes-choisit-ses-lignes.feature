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
    Et chaque équipe analysée reçoit un rang et un genre suggérés
    Et « BEHAT SF1 » est suggérée en genre féminin
    Et « BEHAT SM2 » est suggérée en genre masculin

  Scénario: La ligne éditée s'importe avec le rang et le genre choisis
    Étant donné le club de démonstration, connecté, avec son code FFBB
    Quand j'importe « BEHAT SM2 » en forçant son rang « S » et son genre mixte
    Alors « BEHAT SM2 » est créée au rang « S » et au genre mixte

  Scénario: Seules les lignes cochées s'importent
    Étant donné le club de démonstration, connecté, avec son code FFBB
    Quand j'importe le fichier en ne cochant que « BEHAT SM2 » et « BEHAT U13 »
    Alors « BEHAT SM2 » et « BEHAT U13 » sont créées
    Et « BEHAT SF1 » est absente de la base

  Scénario: Une équipe en entente est suggérée, mais n'est jamais partagée sans décision
    Étant donné le club de démonstration, connecté, avec son code FFBB
    Quand j'analyse un fichier contenant « BEHAT U15F CTC »
    Alors « BEHAT U15F CTC » est suggérée en entente
    Quand j'importe « BEHAT U15F CTC » en décochant l'entente
    Alors « BEHAT U15F CTC » est créée sans entente

  Scénario: Le fichier d'un autre club est refusé dès l'analyse
    Étant donné le club de démonstration, connecté, avec son code FFBB
    Quand j'analyse un fichier d'équipes portant le code d'un autre club
    Alors l'analyse est refusée
    Et aucune équipe jetable n'a été créée
