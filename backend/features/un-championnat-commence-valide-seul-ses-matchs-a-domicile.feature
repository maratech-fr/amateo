# language: fr
Fonctionnalité: Un championnat commencé valide seul ses matchs à domicile
  Un club met l'application en route alors que la saison est déjà lancée : il importe son
  fichier FBI, où des rencontres à domicile portent déjà leur date, leur heure et leur
  gymnase. Un championnat COMMENCÉ (échéance de saisie atteinte, jour inclus, ou premier
  match joué) n'a plus rien à confirmer : dès qu'un gestionnaire ouvre la vue, ses domiciles
  datés passent « Déjà dans FBI » tout seuls, sans geste (statut dit « validé ligue » dans les
  étapes ci-dessous). Une rencontre sans heure n'est pas validée mais elle est nommée à
  traiter, et un championnat qui n'a pas commencé n'est proposé nulle part.

  Scénario: Championnat commencé — la lecture du gestionnaire valide seule ses domiciles datés
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose un fichier FBI avec un domicile daté à « GYM BEHAT » et un domicile sans heure
    Et j'apparie le libellé « GYM BEHAT » au gymnase jetable
    Et l'échéance de saisie du championnat est déjà passée
    Et un gestionnaire ouvre la vue « validé ligue »
    Alors 1 rencontre a basculé « validé ligue »
    Et la rencontre datée est « validé ligue »
    Et le domicile sans heure est nommé parmi les rencontres à traiter
    Et le domicile sans heure est resté « à placer »
    Et plus aucune rencontre n'est validable « validé ligue »

  Scénario: Championnat déjà commencé — le dépôt valide tout seul ses domiciles
    Un club démarre en cours de saison alors que le libellé de salle est DÉJÀ apparié au gymnase.
    Le fichier porte un domicile de championnat dont le premier match est déjà joué : au dépôt,
    sans aucun geste en lot, ce domicile daté (heure + gymnase) bascule « validé ligue » tout seul,
    et plus rien n'est proposé à la validation. La lecture d'un gestionnaire rattrape ce qui mûrit entre deux dépôts.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et le libellé « GYM BEHAT » est déjà apparié au gymnase jetable
    Quand je dépose un fichier FBI avec un domicile daté dans le passé à « GYM BEHAT »
    Alors le domicile passé est « validé ligue »
    Et aucune rencontre n'est validable « validé ligue »

  Scénario: Échéance non passée — rien n'est proposé pour ce championnat
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose un fichier FBI avec un domicile daté à « GYM BEHAT » et un domicile sans heure
    Et j'apparie le libellé « GYM BEHAT » au gymnase jetable
    Et l'échéance de saisie du championnat n'est pas encore passée
    Alors aucune rencontre n'est validable « validé ligue »

  Scénario: Premier match joué, sans échéance — le dépôt valide tout seul
    Un championnat sans échéance renseignée dont le premier match est déjà joué a DÉMARRÉ :
    appariée au dépôt, sa rencontre datée bascule « validé ligue » toute seule, sans qu'aucune
    échéance n'ait à être saisie — rien ne reste à proposer au lot. (Depuis « pas de salle
    appariée, pas d'import », le gymnase est posé dès le dépôt, donc l'auto-validation du
    championnat commencé s'applique AU DÉPÔT au lieu d'attendre un appariement ultérieur.)
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un domicile daté dans le passé à « GYM BEHAT »
    Et j'apparie le libellé « GYM BEHAT » au gymnase jetable
    Alors le domicile passé est « validé ligue »
    Et aucune rencontre n'est validable « validé ligue »

  Scénario: Un amical passé se valide tout seul et n'est jamais proposé au lot
    Un amical dont la date est passée n'a plus rien à confirmer : dès qu'un gestionnaire ouvre
    la vue, il bascule « validé ligue » tout seul — et il n'est JAMAIS proposé au lot des
    championnats (« validé ligue » n'a pas de sens pour un amical).
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un amical passé à « GYM BEHAT »
    Et un gestionnaire ouvre la vue « validé ligue »
    Alors l'amical passé est « validé ligue »
    Et l'amical passé est suivi par la fédération
    Et aucune rencontre n'est validable « validé ligue »

  Scénario: Un amical posé à la main s'arrête à « Placé » : jamais à saisir dans FBI, jamais validé seul
    Un amical créé à la main, sans compétition, est rangé dans « Amicaux », une compétition que la
    fédération ne connaît pas : il n'est pas suivi. Placé, il reste « Placé » et ne compte pas parmi
    les matchs à saisir dans FBI ; sa date passée, la lecture d'un gestionnaire ne le valide pas.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je pose à la main un amical à domicile à « GYM BEHAT », sans compétition
    Alors le match posé à la main n'est pas suivi par la fédération
    Et il ne compte pas parmi les matchs à saisir dans FBI
    Quand la date du match posé à la main est révolue
    Et un gestionnaire ouvre la vue « validé ligue »
    Alors le match posé à la main est « Placé »
    Et il ne compte pas parmi les matchs à saisir dans FBI

  Scénario: Un match posé à la main dans un championnat FBI est suivi, et dé-placé il redevient à saisir
    Un championnat venu du fichier FBI est suivi par la fédération, même pour un match que le
    gestionnaire y crée à la main : placé, il est à saisir ; marqué « saisi », il quitte ce compte.
    Remis « à placer », son suivi repart de « à saisir » : il n'est plus « saisi », et replacé il
    compte de nouveau parmi les matchs à saisir dans FBI.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose un fichier FBI avec un domicile daté à « GYM BEHAT » et un domicile sans heure
    Et je pose à la main un domicile à « GYM BEHAT » dans le championnat déposé
    Alors le match posé à la main est suivi par la fédération
    Et il compte parmi les matchs à saisir dans FBI
    Quand je marque « saisi » le match posé à la main
    Alors le match posé à la main est « saisi »
    Et il ne compte pas parmi les matchs à saisir dans FBI
    Quand je remets « à placer » le match posé à la main
    Alors le match posé à la main est « à placer »
    Quand je replace le match posé à la main à « GYM BEHAT »
    Alors le match posé à la main est « Placé »
    Et il compte parmi les matchs à saisir dans FBI
