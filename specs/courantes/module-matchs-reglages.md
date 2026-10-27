# Module matchs (FFBB) — écrans Configuration, Contraintes & Semaine type

> Les réglages de saison rares : Configuration (échéances, durées, accès match, libellés FFBB),
> Contraintes (ligue/club/équipes/coachs) et Semaine type (créneaux idéaux). Découpé
> mécaniquement de [`module-matchs.md`](module-matchs.md) (anciens §8, §8bis et §10 — §10
> rejoint ce fichier malgré son ordre d'origine après §9, pour rester avec les autres écrans de
> réglage) — contenu déplacé VERBATIM, non réédité, plafond `DocSizeCeilingTest`.

## 8. Écran Configuration (`/matchs/configuration`)

Réglages de saison RARES, quatre `AccordionSection` contrôlées (une seule ouverte, défaut **tout
replié**), ancrées `?section=<clé>` : Échéances de saisie (§4) · Durée des matchs (`sport_category`
par famille, table partagée) · Accès match (fenêtres `VenueMatchWindow`, modale par gymnase — même
éditeur que le wizard) · Libellés FFBB des gymnases (écran d'appariement, ci-dessous). Un résumé
discret dans le nom accessible de chaque bouton (`configSummaries.ts`, fonctions pures — comptent ce
que le backend a déjà calculé, jamais une règle métier recalculée) ; une lecture en échec rend
`null`, jamais un « 0 » fabriqué. **Les adversaires ont leur propre onglet**
(§ Écran Adversaires, `/matchs/adversaires`) — l'ancien deep-link `?section=adversaires` de cet
écran redirige.

**Deep-link `?acces=<venueId>`** (M11, retours Nico BCCL 2026-10-27) : force la section « Accès
match » ouverte (même si une autre clé `?section=` est posée) et ouvre d'emblée l'éditeur de CE
gymnase (`MatchWindowsEditor`, focus posé au montage) — état porté par l'URL, retiré (`?acces=`
seul) à la fermeture de l'éditeur sans perdre la section ouverte. Posé par le bouton « Configurer
les accès » d'un conflit `ACCESS_WINDOW_LOST` (§2 « Hors accès match »,
`module-matchs-radar-et-placement.md`), consommé par `ConfigurationPage`.

**Écran d'appariement des libellés** (`VenueLabelsSection`) : une ligne par LIBELLÉ de l'inventaire
(`GET /api/venues/fbi-labels`), `VenueSelect` dont la valeur effective est le gymnase confirmé sinon
la suggestion « d'après les rencontres » pré-sélectionnée. Trois gestes : **Confirmer** (additif,
sans confirmation) ; **Réaffecter** (`reassign: true` — corrige un alias posé sur le mauvais
gymnase en un geste, re-pointe les non placés, épargne toujours les placés, `ConfirmDialog` nommant
ce qui bouge) ; **Retirer** (ne touche aucune rencontre déjà rattachée). Un signal partagé
(`UnpairedVenueLabelsBanner`) renvoie vers cet écran unique depuis Importer et le Calendrier —
décision fermée (une seule maison d'appariement, jamais une modale sur une modale).

## 8bis. Écran Contraintes (`/matchs/contraintes`)

L'écran UNIQUE des contraintes de match (P4-272 ①, demande fondateur « un endroit pour éditer les
contraintes de match, ligue et personnelles, prises en compte pour le placement automatique »), en
accordéon (`AccordionSection`, ancré `?section=<ligue|club|equipes|coachs>`, patron
`ConfigurationPage`). Les quatre sections **Ligue**, **Club**, **Équipes** et **Coachs** sont
livrées — P4-272 est désormais livré EN ENTIER (①②③④⑤).

**Section Ligue** : le CRUD gestionnaire de la copie club de l'enveloppe fédérale
(`ClubLeagueWindow`, §1) — une **ligne compacte par fenêtre** (résumé + ✎ + 🗑 au repos, dépliée en
champs catégorie/niveau/genre/jour/de-à à l'édition, P4-271), ajout/suppression, badge
`added`/`modified` calculé SERVEUR (affiché, jamais redérivé, § règle frontend « le backend dit »).
Bandeau si la copie est VIDE (« Aucune fenêtre ligue — le placement n'applique plus de règle
fédérale », §1). Le placement, le radar et cet écran lisent la MÊME copie
— une correction ici est immédiatement honorée par le placement automatique (§3) et le radar (§2).

**Bloc « Plages suggérées (estimation) » (P4-272 ②)** : sous le tableau, une aide FACULTATIVE
(`LeagueSuggestions.tsx`) qui propose la tendance dominante des plages saisies par les AUTRES
clubs de l'instance fédérale du demandeur — jamais une source de vérité, une estimation à
appliquer ou ignorer. Instance dérivée du `ffbbClubCode` du club (§ glossaire « Code club FFBB ») ;
son échelle dépend du NIVEAU de la combinaison (§ glossaire « Instance qui fixe les horaires de
match ») : `DEPARTEMENTAL` (et assimilés) groupe par **comité**, `REGIONAL` par **ligue**,
`NATIONAL`/`ELITE` par **fédération** entière. Une combinaison (catégorie, niveau, genre, jour)
n'est proposée que si l'ensemble de plages est saisi À L'IDENTIQUE par **≥ 3 clubs** de l'instance
**ET par plus de la moitié** des clubs de l'instance ayant saisi cette combinaison — sinon rien
pour elle (« une aide facultative, pas une vérité », pas de médiane). Seules les saisons `active`
des AUTRES clubs comptent, le demandeur est exclu. Sans tendance, repli sur le catalogue fédéral
de LA LIGUE DU DEMANDEUR SEULE (`ARA` → `AURA` aujourd'hui) — **jamais** la donnée d'une autre
ligue. Une combinaison déjà identique à la copie du club est MASQUÉE côté serveur (rien à
suggérer). Chaque ligne affiche « Estimation à partir de N clubs de votre comité / ligue — à
vérifier auprès de votre ligue et de votre comité » (un COMPTE, jamais lesquels) ou « données
fédérales » pour le repli. Geste **Appliquer** (par ligne) ou **Tout appliquer** : le serveur
RECALCULE la suggestion et REMPLACE la copie de la combinaison — aucune plage saisie par le client
n'est jamais écrite telle quelle. Réservé au gestionnaire (`GET`/`POST /api/league-window-
suggestions[/apply]`, `ManagementAccessGuard`). Le calcul cross-tenant vit dans la fonction SQL
`SECURITY DEFINER` `league_window_suggestions` (`docs/security/rls.md` § SECURITY DEFINER) —
gardée par le NR bloquant `Security/LeagueWindowSuggestionShareTest`
(`docs/testing/blocking-tests.md`).

**Section Club (P4-272 ③)** : le CRUD gestionnaire des règles de match du club
(`MatchConstraint`, §1, scope CLUB seulement — TEAM ④ vit dans la section Équipes ci-dessous, COACH
⑤ dans la section Coachs ci-après). Chaque règle est une **ligne compacte** (résumé + ✎ + 🗑 au
repos, dépliée en champs à l'édition, P4-271) : un ou
plusieurs jours (`DayToggles`, bascule multi-sélection), un type — **Obligatoire** (HARD, honorée
par le placement) ou **Préférée** (PREFERRED, une préférence, `W_CLUB_RULE=30`, §3) — et une
fourchette de coup d'envoi « pas avant »/« pas après » (chaque borne facultative, au moins une
exigée par le serveur). Une ligne pointillée en bas ajoute une règle (POST) ; chaque règle existante
s'édite en place (PUT, bouton Enregistrer actif seulement si modifiée et complète) et se supprime
avec confirmation (`ConfirmDialog`, destructive). Liste vide → phrase neutre (« le placement ne
s'impose que les fenêtres de la ligue »), jamais un tableau vide muet.

Sous chaque règle, l'**alerte de cohérence** (`GET /api/match-constraints/coherence`,
`ClubRuleCoherenceChecker`, §1) affiche, en LECTURE SEULE, les créneaux idéaux d'équipe qu'elle
heurte (« Cette règle heurte le créneau idéal des <équipe> (semaine A/B) : <jour> <heure> ») — la
même donnée est affichée à l'ENVERS sous chaque créneau idéal concerné dans l'écran Semaine type
(§10, `IdealSlotsEditor`) : deux vues du même croisement serveur, jamais redérivées côté client. Ne
bloque ni n'empêche aucune écriture — le gestionnaire tranche (décision fondateur 2026-09-29 :
signaler, ne jamais bloquer).

**Section Équipes (P4-272 ④)** : le CRUD gestionnaire des INTERDICTIONS de gymnase par équipe
(`TeamsSection`/`TeamVenueBanRow`/`AddTeamVenueBanRow`, `MatchConstraint` scope TEAM, §1). Chaque
ligne : « **‹équipe›** ne joue jamais à **‹gymnase›** », toujours HARD (aucun choix de type à
l'écran — une interdiction n'est jamais une simple préférence, celle-ci vit dans l'habitude de la
semaine type, §10). Une ligne pointillée en bas ajoute une interdiction (deux `Select` équipe +
gymnase, POST) ; chaque interdiction existante se supprime avec confirmation (`ConfirmDialog`,
destructive) — pas d'édition en place (lever puis recréer). Liste vide → phrase neutre (« chaque
équipe peut jouer dans n'importe quel gymnase du club »), jamais un tableau vide muet. Un rappel
pointe vers la Semaine type pour la PRÉFÉRENCE de gymnase (l'inverse d'une interdiction). Pas
d'alerte de cohérence sur cette section — `ClubRuleCoherenceChecker` ne croise que les règles CLUB.

**Section Coachs (P4-272 ⑤)** : le CRUD gestionnaire des INDISPONIBILITÉS d'entraîneur
(`CoachsSection`/`CoachUnavailabilityRow`/`AddCoachUnavailabilityRow`, `MatchConstraint` scope
COACH, §1). Chaque ligne, **compacte au repos** (résumé + ✎ + 🗑, P4-271) : un `Select` entraîneur, un ou plusieurs jours (`DayToggles`) et une
fourchette « pas avant »/« pas après » (chaque borne facultative, au moins une exigée par le
serveur, comme en section Club) — TOUJOURS PREFERRED (aucun choix de type à l'écran : une
indisponibilité de coach ne bloque jamais un match, elle est une préférence par construction).
Plusieurs plages pour un même entraîneur, y compris le même jour, sont légitimes. Une ligne
pointillée en bas ajoute une indisponibilité (POST) ; chaque ligne existante s'édite en place (PUT,
bouton Enregistrer actif seulement si modifiée et complète) et se supprime avec confirmation
(`ConfirmDialog`, destructive). Liste vide → phrase neutre (« chaque entraîneur est réputé
disponible pour tous les matchs »), jamais un tableau vide muet. Pas d'alerte de cohérence sur
cette section — `ClubRuleCoherenceChecker` ne croise que les règles CLUB. **Bandeau Indicatif
(ALIGN-19)** : si au moins une indisponibilité listée vise un entraîneur **adjoint sur toutes ses
équipes** (jamais principal), un bandeau neutre (`NoticeBanner tone="muted"`) rappelle « un adjoint
indisponible ne bloque jamais une séance » — le moteur de placement traite déjà toute
indisponibilité coach en SOFT (§1/§3), la phrase reste donc vraie ici ; même nuance côté
entraînement (`GET /api/constraints/validate` récap, `backend/docs/constraint-coverage.md`) où
l'indisponibilité d'un adjoint n'est, elle, jamais lue pour PLACER une séance — ⚠ **exception
chantier H** : le moteur de génération lit désormais l'indisponibilité déclarée d'un adjoint pour
UNE SEULE décision, le relais d'arrivée tardive (`coachLateToleranceMinutes` sur une séance
VERROUILLÉE, `engine/app/solver/constraints/common.py::_late_arrival_relay_opens`) — sans
tolérance posée (chemin historique, défaut), elle reste bien jamais lue.

## 10. Écran Semaine type (`/matchs/semaine-type`)

Le MODÈLE sans dates que le placement respecte au maximum : le réglage d'affichage du club, le
gabarit idéal (`TypicalWeekendGrid` — créneaux idéaux Sam/Dim × gymnases, sans dates, collisions
posées côte à côte) en vedette, et l'éditeur **« Créneaux idéaux »** (`IdealSlotsEditor`, P4-271 —
remplace l'ex-éditeur de rotations « Créneaux partagés (alternance) »).

**Réglage `Club.weekendAlternates` (P4-271)** : case gestionnaire seule **« Mon modèle de week-end
a deux semaines (A/B) »** (`FilterToggle`, en tête d'écran, sous `canManage`/`isManagementRole`) —
VÉRITÉ SERVEUR (`GET /api/me` → `club.weekendAlternates`, écrite par `PUT /api/clubs/{id}`, gate
management déjà posée sur la ressource Club, §1) jamais redérivée des créneaux existants. Décochée
(défaut d'un club neuf) : le champ Semaine disparaît de l'éditeur, le segmenté disparaît du gabarit,
tout reste en semaine `A` sans qu'aucun geste ne le réécrive (une valeur déjà `B` n'est pas touchée,
seulement masquée). La semaine type reste une AIDE VISUELLE pour le gestionnaire — ce réglage
change ce qu'elle MONTRE, jamais une obligation faite au club d'alterner.

**Éditeur « Créneaux idéaux »** : une **ligne compacte par équipe AYANT un créneau** (résumé + ✎ +
🗑 au repos — jour · heure · gymnase · semaine A/B si le club alterne —, dépliée en champs à
l'édition) ; un bouton **« Ajouter un créneau idéal »** ouvre une ligne pour une équipe SANS
créneau (`TeamSelect` des seules équipes qui n'en ont pas encore, désactivé une fois toutes les
équipes servies) — une équipe porte UN SEUL créneau idéal. Le champ Semaine (A/B, plus de
« toutes ») n'apparaît, en ligne comme à l'ajout, que si `weekendAlternates` est vrai. Segmenté
« Semaine A/Semaine B » sur le gabarit ssi `weekendAlternates` est vrai (sinon la grille reste la
vue unique montrant tous les créneaux, aucun segmenté) — le tag `week` reste une AIDE VISUELLE,
jamais une contrainte, il ne voyage jamais au moteur (§3). Le bouton **« Passerelles »**
(`HabitsLinksDialog`, désormais dédiée aux seuls liens entre équipes — les créneaux idéaux ne s'y
saisissent plus) s'ouvre d'ici. Un signal « hors image » (écart entre placement réel et créneau
idéal du jour) et un signal « même week-end » (deux équipes dont le créneau idéal coïncide
physiquement — même gymnase+jour+heure — reçues à domicile le même week-end, contredit
l'alternance A/B) restent des SIGNAUX, jamais un blocage.

**Géométrie des blocs (P4-206, 2026-09-29)** : chaque bloc va du coup d'envoi à coup d'envoi + la
durée RÉELLE de match de la catégorie de l'équipe (`matchMinutesOf`, durées servies par
`GET /api/sport-categories` — override de club sinon défaut de famille), **exactement la même
géométrie que la grille datée du Calendrier** (§5) — aucun échauffement dessiné, aucun
enchaînement (la vue est un gabarit sans dates, pas un planning). `TypicalWeekPage` gate cette
lecture avec ses autres lectures de page : pas de repli silencieux sur une durée par défaut tant
que le serveur n'a pas répondu.

Sous un créneau idéal, l'**alerte de cohérence** (P4-272 ③, `GET /api/match-constraints/
coherence`, §1/§8bis) affiche les règles de match du CLUB qu'il heurte (« Heurte la règle du club «
<libellé> » ») — la même donnée serveur que la section Club de l'écran Contraintes, vue depuis le
créneau plutôt que depuis la règle ; LECTURE SEULE, ne bloque rien.

