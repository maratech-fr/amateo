# Module matchs (FFBB) — écran Adversaires

> Localisation + trajets vers les adversaires, au grain GYMNASE. Découpé mécaniquement de
> [`module-matchs.md`](module-matchs.md) (ancien §9) — contenu déplacé VERBATIM, non réédité,
> plafond `DocSizeCeilingTest`.

## 9. Écran Adversaires (`/matchs/adversaires`, au grain GYMNASE)

Localisation + trajets vers les adversaires, page sœur dédiée entre Configuration et Semaine type
dans la nav (`MatchesLayout.tsx`). `OpponentsPage` rend la liste LUI-MÊME (`OpponentTravelCard` a
disparu, absorbée) en `Table` : un `<tbody>` PAR CLUB adverse — ligne club (`<th scope="row">`,
son nom, puis « Ajouter un gymnase » ; un club sans aucun lien affiche « Aucun gymnase connu »),
puis une ligne par GYMNASE apparié (`OpponentVenueLink`, `SourceBadge` AUTO/MANUEL), puis les
libellés de fichier encore « à apparier » dans le MÊME rowgroup que leur club (bouton
« Apparier »). Colonnes ≥ `@md` (container query, repli sous 360 px — Trajet et Rencontres se
replient en sous-ligne) : logo (`OpponentLogo`, § Logo fédéral d'un adversaire, sur la ligne club
seulement) · gymnase (label + `StatusPill` « ville seule » si un club sans lien n'a qu'une
précision `CITY`) · trajet (`TravelMinutes`, fragment extrait d'`AwayTravelChip`, « en cours… »/
« indisponible » selon `travelStatus`) · rencontres (`fixtureCount` servi par ligne — le compte
RÉEL de rencontres qui résolvent vers CE gymnase, jamais re-dérivé) · action. Tri : les clubs SANS
gymnase d'abord, puis alphabétique (fr). **Rang gelé au montage** (décision fondateur) : le front
fige cet ordre backend à la première liste non vide (`OpponentsPage.tsx`,
un `setState` posé PENDANT le rendu — patron React « stocker une info des rendus précédents »,
pas un effet) pour qu'un club tout juste apparié ne saute plus de place sous le curseur ; quitter
l'onglet et y revenir recalcule (assumé). Un club apparu après le gel s'ajoute en fin, rang
« infini ».

**Gestes d'appariement** passent par le menu APG partagé sur chaque ligne gymnase — **« Modifier le
gymnase » en premier** (ouvre `LocateOpponentModal` en mode remplacement, cf. ci-dessous), puis
« Fusionner dans « X » » par gymnase du MÊME club, puis « Retirer ce gymnase » (`DELETE`) — et une
`ConfirmDialog` dont le texte vient de la donnée SERVIE (`fallbackVenueName`, `fixtureCount` — le
front n'invente aucune règle métier, § `.claude/rules/frontend.md`). « Modifier le gymnase » et
« Fusionner dans « X » » appellent le **même** `PUT /api/opponents/venue-links/{id}` (re-pointage
du lien) : « Modifier » cherche le nouveau gymnase via `LocateOpponentModal` (recherche FFBB, cf.
ci-dessous), « Fusionner » pointe directement vers un gymnase déjà apparié au club — le libellé de
fichier reste reconnu dans les deux cas. « Ajouter un gymnase »/« Apparier » (**inconditionnels**, y
compris pour un adversaire sans code fédéral — voir ci-dessous) ouvrent `LocateOpponentModal`
(recherche `/api/ffbb/salles`, écrit via `POST /{code}/venues` ou `POST /{code}/venue-links`). La
recherche part **préfiltrée par le code postal fédéral de l'adversaire** (`OpponentClub.postalCode`,
additif servi par `GET /api/opponents/travel`) — le gestionnaire n'a pas à ressaisir un CP déjà
connu ; un sous-titre situe le club (« · Brignais 69530 »), jamais inventé quand ville/CP sont
absents de l'annuaire fédéral. Chaque résultat de recherche affiche « Nom · adresse · CP Ville »
(code postal fédéral de la salle, `commune.codePostal`, relayé par `GET /api/ffbb/salles` — détail
[`../../backend/docs/ffbb-api.md`](../../backend/docs/ffbb-api.md) § « Salles d'une commune »).

**Adversaires SANS code fédéral.** Un adversaire sans code (amical saisi à la main, coupe non
appariée) n'a par nature aucune clé d'appariement fédérale — sans traitement dédié, ses rencontres
resteraient hors du trajet et les deux boutons ci-dessus seraient absents (`club.code !== null`
garde sinon le geste). `App\Service\OpponentPairingKey` (maison unique) dérive une clé SENTINELLE
stable de son libellé normalisé (`'X' + sha256(libellé)[0:40]`, 41 caractères alphanumériques —
passe la borne `[A-Za-z0-9]+` des routes `/api/opponents/{code}/…`, tient dans le `VARCHAR(64)`
existant, **zéro migration**) quand le code fédéral est absent. `GET /api/opponents/travel` sert
cette clé (`pairingKey`) que le front réutilise TELLE QUELLE dans les routes d'écriture — il ne la
redérive jamais (🔴 `.claude/rules/frontend.md`). La sentinelle reste strictement LOCALE au club :
la garde qui neutralise toute ref fédérale pour une clé sentinelle vit ENTIÈREMENT dans la MAISON
UNIQUE `OpponentVenueLinkManager::writeGym` (AVANT toute résolution fédérale, `resolveFederalVenue`
jamais appelé pour une sentinelle) — le contrôleur ne porte **aucun** filtre propre, POST comme PUT
(une garde dupliquée sur le contrôleur court-circuiterait le manager AVANT qu'il soit atteint et
rendrait sa propre garde intestable par ce chemin). ⚠ **Piège de test** : un NR de sécurité qui
envoie une référence ne résolvant fédéralement dans AUCUN cas reste vert même la garde désactivée
(le payload ne crédite jamais, garde ou pas) — le stub FFBB doit servir une référence RÉELLEMENT
résolvable pour que le test soit probant. L'appariement reste tenant seul, jamais crédité au
catalogue partagé `opponent_venue_suggestion` (falsifié par `OpponentVenueSuggestionShareTest`,
rouge sur la garde du manager désactivée — POST et PUT). `LocateOpponentModal` remplace la section
« Gymnases connus » (le partagé fédéral n'a rien à proposer pour une sentinelle) par une phrase
d'explication : le choix restera propre au club.

**La modale enchaîne les libellés orphelins.** Après un appariement réussi,
`LocateOpponentModal` retire le libellé apparié d'une file LOCALE (figée à l'ouverture — le
libellé cliqué en tête puis les autres orphelins du même club) et avance au suivant, code postal
et liste de salles déjà chargée restent en place ; footer « Terminer » remplace « Fermer ». File
vidée → fermeture, comme avant. Le mode « Ajouter un gymnase » (pas de file, `fbiLabel` null)
garde la fermeture immédiate au succès.

**Recherche par nom.** `GET /api/ffbb/salles` accepte `q` (≥ 3 caractères) en
alternative à `postalCode` — plein-texte fédéral sur l'index `ffbbserver_salles`
(`FfbbApiClient::searchSallesByName`, détail + sonde réseau réelle :
[`../../backend/docs/ffbb-api.md`](../../backend/docs/ffbb-api.md) § « Salles d'une commune »). La
modale gagne un champ « Nom du gymnase » à côté du code postal, débounced (300 ms) ; une recherche
par nom active prend la main sur le code postal. Même repli côté **auto-appariement** :
`OpponentVenueAutoLocator` retente par NOM (égalité stricte, un seul hit retenu) quand la voie
commune/rayon ne rend pas un match unique — le comptage `ambiguous`/`unmatched` de la voie commune
ne bouge pas pour ce repli.

**Filtre segmenté** `role="group"` `aria-pressed` — **Sans gymnase · À apparier · Trajet
indisponible · Tous** — état URL `?filtre=` (`lib/urlState.ts`, type `OpponentFilter`), trois
unités de compte distinctes (clubs pour « Sans gymnase », salles pour « À apparier » et « Trajet
indisponible », nommées par l'`aria-label`, jamais par le seul chiffre visible). **« Trajet
indisponible »** (P4-293) ne garde que les clubs ayant ≥ 1 gymnase dont `travelStatus ===
"unavailable"`, et — à la différence de « Sans gymnase »/« À apparier », qui n'affichent aucune
ligne gymnase — affiche les lignes gymnase, filtrées aux seules salles en échec (sinon le club
réapparaît sans montrer la ligne à réparer). Recherche instantanée (club, gymnase, libellé non
apparié) combinée au filtre. **Progression** : région `aria-live="polite"` à deux phrases stables +
compteur chiffré frère, dérivée de `travelStatus` (rafraîchi par Mercure via `useTravelStream`, §
Cache de trajets et calcul asynchrone) ; bouton « Mettre à jour » désactivé pendant le calcul,
aucun spinner par ligne. Le compte « N indisponibles » de la phrase d'échec partiel est un bouton
qui pose directement le filtre « Trajet indisponible » (P4-293) — du constat au recadrage de la
liste en un clic. **Échec partiel** : `NoticeBanner` qui, depuis M14 (retours Nico BCCL
2026-10-27), distingue la CAUSE quand le serveur la connaît — « N en attente de relance, M
introuvable(s) » (`MATCHES_TEXTS.travelFailuresByReason`) plutôt que le générique « n trajets n'ont
pas pu être calculé(s) » (gardé en repli, ex. siège non localisé — cause GLOBALE, sans ligne à
nommer) — suivi des gymnases concernés, nommés (`travelFailureVenues`, plafonné à 10 + reliquat) ;
bouton « Réessayer les manquants » (`POST /api/opponents/travel/resolve`, qui ne route déjà que
les paires siège→gymnase manquantes). Depuis D7 (retours Nico BCCL 2026-10-27, précisé fondateur),
cette relance n'est **jamais refusée** au-delà de 60 adversaires (fini l'ancien 422 « réduisez le
nombre de rencontres ») : un run calcule un POOL de 60 paires non calculées et laisse le reste « en
attente de relance » ; tant qu'il reste des paires en attente, le bandeau ajoute l'invite
`MATCHES_TEXTS.travelRelaunchToContinue` (« relancez pour continuer ») — une relance reprend le pool
suivant jusqu'au bout, sans aucun enchaînement automatique (détail : pool/cap/anti-monopole/relance
dans [`module-matchs-modele-donnees.md`](module-matchs-modele-donnees.md) § Cache de trajets et
calcul asynchrone). « En attente de relance » (`awaiting_retry`) = le budget
worker s'est épuisé avant d'atteindre la paire, une relance la reprend ; « introuvable »
(`unroutable`) = IGN muet/pas d'itinéraire/gymnase mal géolocalisé — une relance seule n'y change
rien tant que la donnée ne bouge pas. Détail serveur (`travelFailureReason` par ligne de gymnase,
`OpponentTravelFailureStore`) : [`module-matchs-modele-donnees.md`](module-matchs-modele-donnees.md)
§ Cache de trajets et calcul asynchrone, `backend/docs/geo-api.md` §2. Bandeau siège
(`useClubGeolocated`, § Prérequis du trajet AUTO) réutilisé tel quel. **Garde anti-double-dispatch** : si un calcul de trajets tourne déjà pour
le club (verrou tenu), « Réessayer les manquants »/« Mettre à jour » ne redispatche rien — toast « Un calcul de
trajets est déjà en cours. » (`alreadyRunning` servi par la route, § Cache de trajets et calcul
asynchrone).

