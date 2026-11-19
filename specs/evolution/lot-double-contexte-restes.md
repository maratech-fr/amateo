# Lot « double contexte » : ce qui reste ouvert et ce qui est abandonné

**Statut : lot clos le 2026-11-18.** Le besoin d'origine : un club où une équipe de haut niveau et des équipes amateurs partagent les mêmes gymnases, avec un staff en partie commun. Le lot a livré ce qui bloquait la génération ; le reste est noté ici, jamais cadré en plan.

**Livré** (le détail, les règles et les décisions vivent dans [`etat-des-lieux.md`](../courantes/etat-des-lieux.md)) :
- plafond de séances par jour et par équipe (contrat 1.10) ;
- règles du club à trois états Obligatoire / Souhaitée / Ignorée (contrat 1.11) ;
- occupations de gymnase, information seulement ;
- contrat allégé (ENG-60, contrat 1.12) ;
- « déplacer verrouille », en trois PR : un verrou compte comme une séance au Récap et aux guichets, l'origine d'une séance verrouillée se lit sur le plan de la séance (enregistrement du résultat du moteur), avertir avant « Régénérer » une version modifiée à la main et refuser de déplacer une séance tenue par une réservation. Reste ouvert : le verrou au lancement (P4-607, conditionné).

**Reste ouvert** : quatre PR, jamais cadrées, une ligne de roadmap chacune : PR 3 (P4-611), PR 4 (P4-612), PR 5 (P4-613), PR 7 (P4-614). **Abandonné** : PR 2, voir plus bas. **À vérifier** : Ch.7, Ch.8, Ch.9, voir plus bas.

Les constats « ce que le code fait » ci-dessous ont été relus dans le code le 2026-11-18, sauf mention « non vérifié ». Chaque PR repasse par le cycle complet (besoin validé, plan, `smith` quand c'est un axe §7.1) ; ce fichier n'est qu'une matière de départ.

---

## PR 3 : fenêtres de match du règlement de la ligue régionale (P4-611)

**Besoin.** Un club d'une autre ligue que celle du catalogue hérite de fenêtres de match fausses sans le savoir. La ligue visée est résolue par le code `BFC`.

**Ce que le code fait.**
- `LeagueResolver.php:32` traduit `BFC` en `BOFC` (le résolveur est correct, vérifié à la source dans l'index FFBB des organismes).
- Le catalogue fédéral ne contient que les fenêtres de la ligue de référence : `backend/data/league-match-windows.aura.json`, semé par `SeedLeagueWindowsCommand`. Une ligue absente du catalogue retombe sur cette ligue par défaut (`LeagueMatchWindowRepository.php:36`, règle unique du repli).
- La copie du club se modifie à la main : [`module-matchs-reglages.md`](../courantes/module-matchs-reglages.md).

**Ce qui coince.**
- Le règlement sportif de la ligue n'a pas été lu : son site renvoyait une erreur 503 aux deux essais du 2026-11-13. À rejouer.
- Du côté du comité départemental du club, le règlement lu laisse les clubs proposer leurs horaires (aucun créneau imposé), fixe la journée de championnat du vendredi au dimanche, et un changement de date demandé 21 jours avant est gratuit. Ces règles ne sont pas des fenêtres de ligue : elles ne se modélisent pas dans le catalogue.
- Les motifs de coupe de P4-426 sont propres à la ligue de référence. Les codes de division FBI de la ligue visée et la place des compétitions nationales dans le JSON : non vérifiés.

**Taille.** XS-S : un fichier de données pour la ligue et son semis. Sans code : le gestionnaire corrige sa copie à la main (c'est le contournement actuel).
**Axes §7.1.** Aucun. **Liens.** P4-426 (non embarquée : accès par type de compétition).

---

## PR 4 : variante de semaine, « planning avec événement » et planning normal (P4-612)

**Besoin, dans les mots du fondateur (2026-11-18).** Deux situations de planning d'entraînement :
1. une semaine « avec événement » : un événement récurrent semi-permanent (le match à domicile du mercredi soir de l'équipe de haut niveau) rend le gymnase indisponible un créneau précis, et deux équipes perdent leur séance. Aujourd'hui le gestionnaire les reporte à la main sur deux créneaux libres (des « créneaux bonus ») du jeudi et du vendredi ;
2. la semaine normale, où tout se passe comme d'habitude.

Ce que veut le fondateur : déclarer une **indisponibilité régulière** et avoir deux plannings, « semaine A » et « semaine B », plutôt qu'une fermeture datée par semaine.

**Volume.** Environ une dizaine de semaines par saison, plus ou moins selon que l'équipe va en play-off. Ce n'est PAS une alternance stricte : les semaines concernées suivent le calendrier de la compétition. Exemple : les mercredis 14 octobre, 28 octobre, 25 novembre, soit des intervalles de deux puis quatre semaines.

**Décision déjà prise (2026-11-13) : Amateo ne décide PAS quand la variante s'applique.** Le club sait quand l'équipe joue, le gestionnaire choisit le planning n°2. Abandonnés : assignation de semaines, règle récurrente, suggestion depuis les matchs. Le problème d'Amateo est de CONSTRUIRE le planning n°2 (variante de semaine, sans date) et de fournir les deux plannings et leurs exports. La PR 4 ne dépend donc plus d'une compétition créée à la main.

**Décision du fondateur : ne PAS traiter maintenant**, pour ne pas faire grossir le lot.

**Ce que le code fait déjà (à regarder au cadrage).**
- Une fermeture de gymnase est datée au jour : `startDate` et `endDate` au format `AAAA-MM-JJ` (`backend/docs/constraint-config-keys.md`, ligne FACILITY) ; le réglage d'un plan de période est un masque par jour OPEN / CLOSED (`VenuePeriodOverride.php:72`). Rien ne ferme un gymnase à l'heure. Le chemin « fermer seulement le soir » par retouche de la grille copiée d'une période n'a jamais été joué : non vérifié.
- Un plan de période est une copie de la grille du socle pour une fenêtre de dates ; une fermeture datée découpe un plan en semaines, chaque semaine touchée naissant sans les épingles retirées (`fermetures-de-gymnase-plan.md`, [`types-de-planning.md`](../courantes/types-de-planning.md), [`accueil-cockpit-temporel.md`](../courantes/accueil-cockpit-temporel.md), ADR-0002 et ADR-0004).
- Les occupations de gymnase (PR 6) disent pourquoi une salle n'est pas libre, sans aucun effet sur le moteur (`VenueOccupancy.php`).
- Le radar signale qu'un match à domicile chevauche une séance d'une autre équipe (`VENUE_TRAINING_OVERLAP`, `Conflicts/VenueConflicts.php`), mais la fenêtre de salle d'un match va du coup d'envoi à la fin du match, sans échauffement (`MatchFootprint.php`) : une séance qui finit pile avant le coup d'envoi n'est pas vue. Revenir sur ce choix est une décision de fondateur.

**Ce qui coince.** Une variante sans date doit coexister avec le plan de saison (validation et réouverture du socle, ADR-0002 : valider ou rouvrir le socle détruit les plans de période futurs). Question à instruire au cadrage : une fermeture partielle dans la journée suffit-elle, ou faut-il une règle récurrente ? Construction envisagée : saison plus fermeture à l'heure, puis replacement.

**Taille.** M à L selon la voie : fermeture à l'heure seule (M) ; variante de semaine complète avec export des deux plannings (L).
**Axes §7.1.** planning lifecycle (ADR-0002), constraint semantics. `smith` obligatoire au plan.
**Liens.** P4-484 (coupe d'un plan multi-semaines autour d'une fermeture, même terrain, non embarquée), P4-445 (placement, non embarquée).

---

## PR 5 : planning des salariés (P4-613)

**Besoin.** Le planning réel du club est celui de ses salariés : séances, interventions scolaires, formations, réunions. Décisions du fondateur (2026-11-13) : fonctionnalité nouvelle, à part et INFORMATIVE (aucun solveur) ; plages horaires et activités nommées attribuées à un salarié ou à tous (exemple : la réunion hebdomadaire) ; séances d'entraînement affectées au salarié ; **fiche salarié indépendante du coach** (tous les salariés ne sont pas des coachs, lien coach optionnel) ; lue par les gestionnaires, fin de chaîne = **export simple** ; **aucun compte créé pour consulter**, donc pas d'axe auth & memberships.

**Ce que le code fait.**
- `Coach.isEmployee` existe (`Coach.php:57`) ; une règle « un salarié présent chaque jour ouvré » joue en objectif (`engine/app/solver/constraints/wellness/salarie.py`).
- L'indisponibilité d'un coach se saisit par jours et plage horaire (`ConstraintsStep.tsx`, non revérifié ligne à ligne) ; son nom est généré, sans motif ni lieu. Le planning se filtre par coach.
- Aucune notion d'activité hors équipe ni de vue « la semaine de tel salarié ».

**Ce qui coince.** Le motif d'une absence se perd. Contournement actuel : les interventions deviennent des indisponibilités de coach, sans motif.

**Taille.** Mini (S) : un motif libre sur l'indisponibilité, visible dans la vue coach. Module complet (L-XL) : entité activité (qui, où, quand, pourquoi), vue par personne, export ; 1 à 2 PR dans la décision du lot.
**Axes §7.1.** Aucun pour la version informative ; auth & memberships seulement si les salariés se connectaient (écarté). Chemin d'abus à tester si la fiche porte des données de personnes : lecture par id d'une activité d'un autre club (isolation entre clubs).
**Liens.** AUD-ALIGN-22 (cran « Obligatoire » de la règle des salariés : soldé par les trois états du lot), PR 7 (dépend de cette PR), PR 6 (occupations).

---

## PR 7 : reconnaître les cases de l'import du parc (P4-614)

**Besoin (2026-11-13).** Apprendre à l'import à reconnaître les cases qui ne sont pas des équipes (école, section d'un autre établissement, formations, réunions) pour que TOUT le planning soit conservé, rien perdu en « À classer ». Dépend de la PR 5 (la destination des activités) et de la PR 6 (livrée : occupations).

**Ce que le code fait.**
- Tout libellé qui n'est pas une équipe arrive en « À classer » (`TeamNameInterpreter.php:34`, `UNFILED`) et crée une fausse équipe tant qu'il reste coché ; une case porte 6 équipes au plus ; les coachs ne sont pas extraits ; voir [`import-du-parc.md`](../courantes/import-du-parc.md).
- Une case sans équipe devient un créneau sans réservation (même doc).

**Ce qui coince.** Sans destination pour les activités (PR 5) ni pour les occupations (PR 6, livrée), rien ne dit où ranger une case reconnue.

**Taille.** M. **Axes §7.1.** Isolation entre clubs (l'import écrit dans plusieurs tables, NR `ParcImportExecuteTest`).
**Liens.** P4-412, P4-385 (embarquées d'après la définition du lot) ; P4-410 (non embarquée), P4-409.

---

## Abandonné : PR 2, compétition créée à la main, échauffement et durée par compétition

**Décision du fondateur, 2026-11-18.** Une compétition hors FBI (exemple : la compétition européenne de l'équipe de haut niveau) est placée par sa propre ligue, hors du planning d'entraînement. Le gestionnaire connaît déjà ses dates ; l'application n'a rien à y gérer ni à placer, donc la créer à la main ne sert à rien. Le vrai besoin derrière est le planning d'entraînement : PR 4.

**Exemple chiffré** (calendrier public vérifié) : une équipe en compétition européenne joue ses matchs à domicile en SEMAINE, un mercredi soir vers 20 h dans la salle du club, et à l'extérieur un mercredi ou un jeudi. Ses matchs à domicile des autres championnats sont le week-end, placés par la fédération, sans conflit avec l'entraînement. Phase de poule : 6 matchs (3 à domicile), jusqu'au 3 décembre ; puis tours à élimination directe jusqu'à des finales début avril.

**Ce que le plan suspendu contenait** (supprimé avec cette décision, il reste dans git) : trois PR (échauffement et durée par compétition, contrat 1.12 ; compétition créée à la main ; règles propres d'une compétition créée à la main au radar et au placement, contrat 1.13), quatre questions ouvertes.

**Ce qui reste utile ailleurs**, décisions déjà prises :
- l'échauffement d'un match à domicile est aujourd'hui réglé par catégorie (`MatchDurationResolver.php`) et n'entre pas dans la fenêtre de salle d'un match (`MatchFootprint.php`) ; le revoir est une décision de fondateur à reprendre à la PR 4, pas une PR dédiée ;
- un match posé à la main dont l'échauffement déborde l'accès match est accepté et signalé « Hors accès match » ; l'heure fixée par la ligue reste et est signalée ;
- le nom d'une division FBI fait foi : en cas de collision avec un nom saisi à la main, on signale, le gestionnaire renomme ;
- un match hors FBI s'arrête à « Placé », jamais « Saisi dans FBI » ;
- vocabulaire : « Occupation » est réservé à ce qu'un tiers (l'école) fait d'un gymnase.

---

## Ch.7, Ch.8, Ch.9 : points à vérifier en bac à sable, jamais consignés

La définition du lot prévoyait de les vérifier en bac à sable puis de les consigner en doc. Une recherche dans `specs/` et `docs/` n'en trouve aucune trace : **à vérifier**, rien n'est établi.
- **Ch.7**, équipe de loisir « Libre » : un créneau libre se place ou se verrouille comme une équipe ; chemin non joué.
- **Ch.8**, salle de repli déclarée sans créneau de saison : un gymnase sans créneau de saison ne déclenche qu'un avertissement (`PreSolvePreventionWarnings.php`) ; « créer un créneau du repli dans la seule grille d'une fermeture » n'a pas été joué.
- **Ch.9**, deux gymnases à la même adresse : l'import lève l'alerte de chevauchement si les deux salles restent dans une seule colonne (`ParcImportAnalyzer.php`) ; le contournement est de nommer deux gymnases. Le trajet d'un coach entre les deux : non vérifié.
