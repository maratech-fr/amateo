# Audit ClubScheduler (Amateo) — édition 2026-10-03

| Méta | Valeur |
|---|---|
| Date | 2026-10-03 |
| Modèle | `claude-opus-5-5` (Opus 5.5, Anthropic) |
| HEAD | `617e5c3b` (`main`) — fichier écrit sur la branche `audit/2026-10-03`, non commité |
| Méthode | 7 agents d'analyse parallèles (doc, backend, engine avec sondes moteur, frontend **avec exécution**, UX statique, alignement 3 couches avec sondes moteur, cyber A1–A28) + checks directs (supply chain ×3 EXÉCUTÉS, Mercure, secrets, workflows, politiques de redémarrage, prod-readiness) + **Behat complet EXÉCUTÉ** (89/89) + **drill de retour arrière des migrations EXÉCUTÉ** sur base jetable + **axe EXÉCUTÉ sur les écrans publics** (2 thèmes) + contre-vérification manuelle de tous les findings ≥ Moyenne |
| Édition précédente | `AUDIT-2026-09-18-claude-fable-5-1.md` (HEAD `870194bf`) — depuis : **132 commits, 1 276 fichiers** (+89 484 / −37 358) : lot correctif de l'audit (#919-#923, #946-#965), passe doc « le présent » (#980-#990), DA « papier chaud » + marque produit (#973-#979), P4-240 placement gros lots, P4-272 ①-⑤ (règles de match du club, gymnase interdit, indispos coach au placement), import d'équipes FBI (#970/#971), **horloge par club `ClubClock`** (#1058, #1061-#1063), **comptes démo** + fenêtre d'activation (#1035/#1037/#1040), rôle Postgres `amateo_read` (#1021), seed BCCL prod (#1020), splash de connexion (#1034), passe uniformité UI (#1041-#1049), **contrat moteur remis à 1.0** (#1057), nettoyage code mort et 19 opérations API (#1064/#1065) |

---

## Tableau de couverture

| Axe | Couverture | Détail |
|---|---|---|
| Documentation | ✅ couvert | 14 sondes contre le code (CLAUDE.md 8/8 exact, 1 imprécis) ; script de résolution de tous les chemins/symboles de CLAUDE.md + project-map + 3 AGENTS.md + TENANT.md + rls.md (0 chemin manquant) ; DOC-37→48 re-statués |
| Besoin produit | ✅ couvert | 5/5 livraisons récentes tracées en état des lieux, 5/5 ids ouverts réellement ouverts au code |
| Code backend | ✅ couvert | statique ; **21 migrations neuves lues** (RLS 100 %) ; rôle `amateo_read` lu ; horloge et démos suivies écrivain par écrivain |
| Code engine | ✅ couvert | statique + sondes moteur en lecture (`python -` dans le conteneur) + sous-ensemble pytest (60 passed) ; mesures RSS du placement |
| Code frontend | ✅ couvert | **exécuté** : `tsc -b --force` 0, eslint 0 erreur / 1 warning, knip 0, **vitest 5 : 3 656 tests / 358 fichiers verts au run 1, 0 retry** (image tooling rebâtie avant) |
| Supply chain | ✅ couvert | `composer audit` + `npm audit --omit=dev` + `pip-audit` (installé à la volée dans le conteneur, puis retiré) EXÉCUTÉS : **0 vuln ×3** |
| Cybersécurité — surface d'attaque | ✅ couvert | A1–A23 re-verdictés + **5 lignes neuves A24–A28** (horloge, démos, rôle lecture seule, appropriation de club, dépôt public) ; visibilité du dépôt lue via `gh repo view` (**PUBLIC**) ; valeurs des `.env*` suivis NON lues (refus du classifieur — liste de fichiers seule) |
| Infra / Mercure | ✅ couvert | hub dev épinglé v0.24.2, non-anonyme ; prod v0.19 (tag, pas de digest) ; **11/11 services longue durée avec `restart:`** en dev (INF-04 corrigé) ; 5/5 workflows avec `permissions:` |
| Prod-readiness / observabilité | ✅ couvert | Sentry 3 DSN câblés (activés quand le compte existe) ; sauvegarde nocturne `DatabaseBackupCommand` + crochet hors site ; 10 `restart` / 10 limites / 11 healthchecks en prod |
| RGPD | ✅ couvert (statique) | BCK-24 corrigé **avec méta-garde** ; 2 findings neufs (RGPD-02 siège, RGPD-03 PII coachs au moteur) + SEC-29 (données réelles publiées) |
| Performance solveur | ✅ couvert | **Behat complet EXÉCUTÉ** : 89/89 scénarios, 505 étapes, **8 min 31 s** ; + mesures RSS du placement (370 / 435 MiB) |
| Alignement contraintes 3 couches | ✅ couvert | table complète (22 cellules entraînement + 8 champs matchs) × 3 couches + radar ⇄ placement ; **3 scissions Élevées** confirmées par sonde moteur et relecture manuelle |
| **Réversibilité des migrations** | 🟡 partiel (**était partiel, drill exécuté pour la 1ʳᵉ fois**) | base jetable `amateo_audit_drill` : migrate complet (169 migrations, 4 s) → `down` des 5 dernières → `up` → **`pg_dump -s` identique** (seuls les jetons aléatoires `\restrict` diffèrent) ; base supprimée. Limites : base vide (pas de données), 5 migrations seulement (la 6ᵉ crée un rôle de cluster — non rejouable sans toucher les autres bases) |
| Restauration de backup | ⬜ non couvert cette édition | drill du 08-27 non rejoué ; chemin de code inchangé |
| **Temps simulé & invariants temporels** | ✅ couvert (statique) — **NOUVEL AXE** | ouvert par l'arrivée de `ClubClock` : qui écrit l'horloge, qui la lit, quelles échéances de sécurité la suivent (JWT, Mercure, jetons e-mail, grâce RGPD, audit, échéances publiques) ; **4 findings** (SEC-25, SEC-30, BCK-34, SEC-31) |
| UX-Cohérence | ✅ couvert | collecte statique exhaustive, commandes reproductibles |
| UX-Simplicité / Intuitivité | 🟡 partiel | proxys statiques (4 flux-clés comptés, jargon, gardes de chargement) ; **parcours authentifié NON joué** : le jeton de lecture console a été refusé par le classifieur de permissions (non contourné) |
| Inclusivité-a11y | 🟡 partiel (était couvert) | **axe EXÉCUTÉ** sur `/login`, `/register`, `/forgot-password` × clair/sombre : **0 violation** ; clavier `/login` : ordre logique, anneau visible ; écrans authentifiés en calcul statique seulement (même raison) |
| Coûts / scalabilité financière | ⬜ non couvert (pas de données réelles) | ligne permanente ; seule donnée nouvelle : RSS du placement (435/512 MiB, ENG-49) |

---

## Synthèse des notes

| Critère | 2026-08-27 | 2026-09-18 | **2026-10-03** |
|---|---|---|---|
| 1. Documentation | 90 | 82 | **82** |
| 2. Pertinence du besoin | 93 | 92 | **91** |
| 3a. Code backend | 84 | 72 | **62** |
| 3b. Code engine | 87 | 85 | **79** |
| 3c. Code frontend | 86 | 84 | **82** |
| 4. Supply chain | 96 | 96 | **96** |
| 5. Performance solveur | 90 | 88 | **84** |
| **État global (pondéré)** | 87 | 82 | **76** |

Pondération inchangée : doc 10 % · besoin 10 % · backend 25 % · engine 20 % · frontend 15 % · supply 5 % · perf 7,5 % · UX 7,5 %.
Calcul = 82·.10 + 91·.10 + 62·.25 + 79·.20 + 82·.15 + 96·.05 + 84·.075 + 66·.075 = **76,2** → **76**. Malus transversal 0.

⚠ **Deuxième baisse d'affilée (82 → 76), et elle a une cause unique et nette.** Le registre du 09-18 a été **presque entièrement soldé** (2 Élevées sur 2, 11 Moyennes sur 11, la plupart avec un garde mécanique). Ce qui fait baisser la note, ce sont les **surfaces neuves livrées en une semaine sans modèle de menace** : l'horloge par club, les comptes démo, et le code FFBB comme identité d'un club. Sept Élevées confirmées à la main, contre deux. Cinq d'entre elles tiennent au même objet : **l'identité d'un club** (qui peut prendre un code FFBB, qui peut reprendre un club, ce qu'une démo occupe) — et le dépôt est public.

### Score UX (axe additif — noté À PART, sévérité extrême)

| Sous-axe | 08-27 | 09-18 | **10-03** | Plafond appliqué |
|---|---|---|---|---|
| UX-Cohérence | 75 | 80 | **72** | **UXC-26 (Moyen confirmé)** → plafond 75 ; 5 Faibles neufs |
| UX-Simplicité & Intuitivité | 75 | 75 | **66** | **UXS-09, UXS-10, UXS-14 (Moyens confirmés)** → plafond 75 ; le motif UXS-05 en est à sa 3ᵉ migration ; ALIGN-17 (l'écran dit l'inverse de l'effet) pèse ici aussi |
| Inclusivité / a11y | 80 | 72 | **70** | **A11Y-25 (Moyen, calculé)** → plafond 75 ; axe dynamique limité aux écrans publics |
| **Score UX général** | 75 | 72 | **66** | = le PLUS BAS des sous-axes couverts |

**Lecture rapide.** L'édition **du registre qui tient et des surfaces qui débordent**. Toutes les recos P1 du 09-18 sont livrées, et livrées par leur **règle** : purge dérivée du marqueur (`PurgeCompletenessTest`), radar unique (`ConflictRadarLoader`), registre de parité sans exemption, garde d'opacité AA, garde de version et de poids dans la doc, contre-garde serveur du placement. Le frontend passe 3 656 tests verts au premier run, Behat 89/89, le drill de migration est propre, 0 vulnérabilité ×3. Mais trois livraisons de fin de semaine (#1035-#1063) ouvrent quatre chemins qu'aucun test ne parcourt : prendre le code FFBB d'un autre club (SEC-26), reprendre un club sans membre (SEC-27), capturer une vraie inscription dans une démo (BCK-33), et faire piloter les échéances publiques d'un vrai club par l'horloge d'une démo (SEC-25). Et côté produit, trois promesses d'écran dépassent l'effet : « au moins une séance tel jour » fait échouer toute la génération dès qu'une séance verrouillée la satisfait (ALIGN-16), « pas avant 14h » pour un coach produit l'inverse (ALIGN-17), « Verrouillé » sur « évite ce gymnase » est proposé puis refusé (ALIGN-18).

---

## Registre des findings

### Findings de l'édition précédente — statuts

| ID | Titre | Zone | Gravité | Vérif | **Statut** |
|---|---|---|---|---|---|
| **ALIGN-11** | « Préfère » + Obligatoire = exclusivité muette | align | Élevée | contre-vérifié (3 couches) | **corrigé** (#923, D1) — `preferredVenueId` HARD/LOCK refusé à l'écriture (422, `ConstraintStateProcessor.php:174-186`) + migration `Version20260918200000` ; exclusivité retirée (`ScheduleConstraintBuilder.php:1356-1360`) ; legacy forcé côté moteur en défense en profondeur |
| **BCK-24** | `opponent_travel` jamais purgé | backend | Élevée | contre-vérifié | **corrigé + MÉCANISÉ** — table supprimée (`Version20260920140000.php:151-153`), successeurs purgés (`ErasedClubPurger.php:77,82`) ; **`tests/Security/PurgeCompletenessTest.php:33-99`** dérive la liste du marqueur `TenantOwnedInterface` pour les deux purgers. Résidu → BCK-38 (test non bloquant) |
| BCK-23 | Radar du gardien ≠ radar de l'écran | backend | Moyenne | confirmé | **corrigé** — un seul `detect()` dans `ConflictRadarLoader.php:184` ; `MatchVisitDeltaParityTest:358-381,698` appelle enfin `/api/fixtures/conflicts` |
| ENG-41 | Exemption fausse du registre de parité | engine | Moyenne | confirmé | **corrigé** — `DECLARED_ARG_DIVERGENCES = {}` (`test_hard_layer_parity_registry.py:375`) |
| ENG-42 | Famille inconnue droppée sans diagnostic | engine | Moyenne | confirmé | **corrigé** — `parse_warning` (`parsing.py:449-470`) + test |
| ENG-40 | Build de `/place-matches` non borné | engine | Moyenne | confirmé | **corrigé, résidu** — `BUILD_BUDGET_SECONDS = 10.0` (`match_placement.py:100`) + gate perf `test_perf_place_matches.py` (main seulement) ; le budget est en TEMPS, pas en mémoire → ENG-49 |
| FRT-30 | setState du parent pendant le rendu | frontend | Moyenne | exécuté | **corrigé** — `DiagnosticsPanel.tsx:106-109,137-142` ; 0 avertissement au run |
| FRT-31 | Gardes de placement front-only | frontend | Moyenne | confirmé | **corrigé des deux côtés** — `usePlacementGuards.ts:24-48` (readState) + `FixtureStateProcessor.php:229-305` (`assertVenueAccessAllowed`, même prédicat que le radar) |
| FRT-32 | Miroir enveloppe non déclaré | frontend | Moyenne | confirmé | **corrigé** — `FrontRederivationRegistryTest.php:106-110` + 2 tests de parité |
| UXS-08 | Échec de chargement = « rien à configurer » | ux | Moyenne | confirmé | **corrigé** (Configuration, Semaine type) — **le motif migre une 3ᵉ fois** → UXS-09/10 |
| A11Y-22 | Texte à opacité réduite sous AA | ux | Moyenne | mesuré 09-18 | **corrigé + garde** (`test/textOpacityGuard.test.ts`) — **contourné par un autre motif** → A11Y-25 |
| DOC-45 | Poids faux dans 2 docs sur 3 | doc | Moyenne | confirmé | **corrigé + garde** (`engine/tests/test_weights_doc_sync.py`) — registre figé avant P4-272 → DOC-54 |
| DOC-37 | Carte engine amputée | doc | Moyenne | confirmé | **corrigé** (`project-map.md:138,150-153`) |
| DOC-38 | `module-matchs.md` journal de 3 093 l. | doc | Moyenne | confirmé | **corrigé (forme)** — 1 555 l., 14 `##` par écran, règle de forme écrite ; pas de borne de taille |
| DOC-39 | Garde de version incomplet | doc | Moyenne | confirmé | **corrigé (règle)** — `test_contract_version_doc_sync.py:56-58,71` |
| SEC-19 | Pas de limiteur sur l'épinglage de gymnase | cyber | Moyenne | confirmé | **corrigé** — `opponent_travel_manual` (`rate_limiter.yaml:81-90`) sur les 3 routes successeures |
| ALIGN-12 | Placement aveugle au trajet adverse | align | Moyenne | confirmé | **⛔ fermé par décision** (P4-240 ③ « décision B » : le solveur ignore tout extérieur, documenté `module-matchs.md` ~l.627) — commentaires backend périmés → ALIGN-20 |
| BCK-19 | God-services | backend | Moyenne | `wc -l` | **ouvert, aggravé** — fichiers > 300 l. **54 → 66** ; `BcclSeeder` 3 128, `FbiFixtureImporter` 1 954, `MatchConflictDetector` 1 309 |
| BCK-21/22/26/27/28/29/30/31/32 | (Faibles/Mineures/Info) | backend | — | confirmé | **corrigés** (BCK-27 résidu Mineure → BCK-39 ; BCK-31 : COUNT natif, une requête par compétition) |
| BCK-20 | PUT partiel `VenueTravelTimeInput` | backend | Mineure | confirmé | **ouvert** (`VenueTravelTimeStateProcessor.php:87-92`) |
| BCK-18 / SEC-20 | Provenance des tables partagées | backend | Faible | confirmé | **⛔ fermé par décision** (triage 2026-09-25, `roadmap.md:317`) — reste visible en A21/A22 `partiel` |
| SEC-21 | `permissions:` absent | infra | Mineure | confirmé | **corrigé** (5/5 workflows) |
| SEC-22 | Import xlsx sans borne | cyber | Faible | confirmé | **corrigé** — `XlsxUploadGuard.php:36-42` (2 Mo, 20 Mo décompressé, 5 000 lignes) + limiteur 30/h ; résidu Info : mime OU extension |
| ENG-43 | Snapshot ≠ payload envoyé | engine/back | Faible | confirmé | **corrigé** — `schedule.payload_graft`, `Schedule::engineInput()` testé égal au corps envoyé |
| ENG-44/45/46/47 | (Faibles/Info) | engine | — | confirmé | **corrigés** (bandit + deptry en CI, `max_length` partout, défauts de version gardés) |
| ENG-34 | Déterminisme 8 workers | engine | Info | confirmé | **ouvert, assumé** — voir ENG-52 |
| ENG-39 | Fichiers moteur massifs | engine | Info | `wc -l` | **partiel, aggravé** — `diagnostics.py` 1 666, `validate_assignments.py` 1 049, `match_placement.py` 821 (neuf) |
| ALIGN-10 | MIN_SESSIONS plancher | align | Faible | confirmé | **⛔ fermé par décision** (soft assumé, triage 2026-09-25) — garde armé ; la doc dit encore « à trancher » → DOC-60 |
| ALIGN-13 | Heure estimée lue comme certaine | align | Faible | confirmé | **dissous de fait** (extérieurs ignorés) — mais roadmap et schéma le décrivent encore → ALIGN-20 |
| ALIGN-14 | Le test prouve la clé, pas la cellule | align | Faible | confirmé | **partiel** (#953) — non prouvés : minStartTime / forbiddenDays / forbiddenVenueId PREFERRED, toutes les cellules LOCK |
| ALIGN-15 | Gate `forcedDays` ignore la FACILITY | align | Faible | confirmé | **corrigé** (`PreSolvePreventionWarnings::forcedVenueOf:415-448`) |
| FRT-20 | Hook-mocking | frontend | Faible | confirmé | **ouvert, s'étend** — 76 fichiers vs 47 au patron API |
| FRT-21 | Cycles inter-features | frontend | Faible | confirmé | **ouvert** — 6 paires bidirectionnelles, aucun garde entre features |
| FRT-22 | Types ×3 | frontend | Faible | confirmé | **partiel** (3 copies, Team gardé) |
| FRT-28 | Types manuels sans filet | frontend | Faible | confirmé | **partiel** — 9 paires gardées / 254 interfaces |
| FRT-33 | Monolithes matchs | frontend | Faible | `wc -l` | **partiel** — `api` découpé, mais `matches/queries.ts` **757 → 1 007**, fichiers > 400 l. **31 → 39** |
| FRT-34/35/36/37 | (Faibles/Info) | frontend | — | exécuté | **corrigés** (cliquet act 70 → 30 au plafond ; msw retiré ; chemins d'échec testés ; garde de forme) |
| UXC-20/21/22/23 | (Faibles) | ux | — | confirmé | **corrigés** sur leurs sites (résidus : UXC-28, UXC-22 réapparu 2 fois, « salle » ×2) |
| UXC-10 | Empty states inline | ux | Faible | confirmé | **résiduel** (2 sites défendables) |
| UXC-24 / UXS-07 / A11Y-21 | (décisions) | ux | — | confirmé | **fermés par décision** (#966) — A11Y-21 : 15 sites `h-7`, voir UXC-29 |
| A11Y-16 | `text-[10px]` | ux | Info | confirmé | **ouvert, aggravé hors grille** (2 → 12 sites) → A11Y-27 |
| A11Y-23/24 | Tabs aria-controls / région défilante | ux | Faible | confirmé | **corrigés** — A11Y-24 revient sur 5 sites → A11Y-26 |
| DOC-40/41/42/43/44/47/48 | (Faibles/Mineures/Info) | doc | — | confirmé | **corrigés** |
| DOC-46 | Ligne citée fausse | doc | Faible | confirmé | **ouvert, réincarné** → DOC-50 |
| INF-04 | Pas de redémarrage en dev | infra | Faible | constaté | **corrigé** (11/11 services longue durée) |
| INF-05 | Retour arrière non exercé | infra | Faible | **exécuté** | **partiel** — drill de cette édition propre sur 5 migrations (base vide) ; toujours aucun `down()` joué en CI |

**Bilan reprise : 2/2 Élevées et 11/11 Moyennes fermées** (dont 7 par un garde mécanique). ~45 findings soldés au total, 4 fermés par décision, **aucune régression** sur un corrigé — mais trois motifs ont **migré** au lieu de disparaître (UXS-05→08→09/10, A11Y-22→25, DOC-46→50).

### Nouveaux findings (cette édition)

> Les valeurs sensibles (identifiants, adresses, noms de personnes) ne sont **jamais** reproduites ici — `fichier:ligne` seulement : ce fichier a vocation à être commité dans un dépôt public.

| ID | Titre | Zone | Gravité | Vérif | Statut |
|---|---|---|---|---|---|
| **SEC-26** | **Le code FFBB d'un club est modifiable par `PUT /api/clubs/{id}` → contournement complet de l'anti-squatting P3-4.** `ClubResource.php:25-27` expose `Put` ; `ClubInput.php:51-53` n'a qu'un `Length(max:64)` ; `ClubStateProcessor.php:139-140` appelle `setFfbbClubCode()` ; seul garde : rôle de gestion (`:52-69`). Le front n'écrit jamais ce champ (`ClubPage.tsx:329`, lecture seule) : porte résiduelle. Effets : un gestionnaire de n'importe quel club (démo comprise, ou repris via SEC-27) prend le code d'un vrai club non inscrit ; le vrai club ne peut plus créer son espace (index unique) ; ses gestionnaires qui s'inscrivent deviennent **membres PENDING du squatteur** (`AuthController.php:318-322`), même après approbation par la boîte FFBB (`ClubApprovalService.php:135-145`), et le squatteur voit leurs nom/e-mail. Oracle secondaire « ce club est-il client ? » (violation d'index). Aucun test | cyber/backend | **Élevée** | **confirmé (processeur relu)** | nouveau |
| **SEC-27** | **Reprise « win-back » d'un club sans membre = workspace intact remis à n'importe quel inscrit.** Pendant les 30 j de grâce après le départ du dernier membre (`AccountErasureService.php:157-168`, données intactes), un inscrit qui vérifie **sa propre** adresse avec le code FFBB (public) devient **gestionnaire actif**, l'effacement est annulé (`AuthController.php:296-317`, `clubIsMemberless`). Accès aux données personnelles (coachs, e-mails, vœux, plannings) d'un club qui ne lui appartient pas. Règle documentée (« même confiance que la création », `docs/security/rgpd.md:81`) mais jamais réalignée sur P3-4, où la création exige l'approbation du club | cyber/backend | **Élevée** | **confirmé (branche relue)** | nouveau |
| **SEC-29** | **Le dépôt PUBLIC publie l'identité réelle d'un club, de ses coachs et des identifiants de dev d'un compte gestionnaire réel.** `BcclSeedProfile.php:118-128,145` (identifiants du compte gestionnaire et d'un second compte) ; `BcclSeeder.php:727-745` (noms réels de coachs, docblock « identités réelles » `BcclSeedProfile.php:149-153`) ; statut salarié et indisponibilités hebdomadaires (`BcclSeeder.php:845-850,1212+`). Irrévocable dans l'historique git. Ces identifiants ouvrent la base de jeu du fondateur dès que la stack dev est exposée par le tunnel démo (`docs/technique/demo-tunnel-cloudflare.md`), où tout connecté peut aussi régler l'horloge **globale** de dev (`DevClockController.php:45-71`, garde `kernel.debug` seule). `gh repo view` → `PUBLIC` | cyber/RGPD | **Élevée** | **confirmé (lu, valeurs masquées)** | nouveau |
| **BCK-33** | **Une vraie inscription sur le code d'une démo du jour tombe dans le club démo, et la démo ne meurt plus.** Le club démo est créé avec le vrai code FFBB du prospect (`DemoClubMaterializer.php:74-75`) ; register et approbation ignorent `is_demo` (`AuthController.php:318-322`) → le prospect convaincu devient membre PENDING de la démo ; `hasOtherMember` compte les PENDING (`:258-266`) → purge nocturne sautée **pour toujours** (`:225`), vrai code squatté par un club exempté de quotas, prochain raccourci démo en 409 (`:154`). Déclenchable anonymement (register + vérification d'e-mail). Aucun test | backend | **Élevée** | **confirmé (3 chemins relus)** | nouveau |
| **ALIGN-16** | **Une séance verrouillée qui SATISFAIT « au moins une séance tel jour » fait échouer toute la génération du club, sans cause nommée.** Le moteur exige ≥ 1 séance LIBRE le jour imposé (`targeting.py:209-213`) alors que `one_session_per_day` met à zéro toutes les séances libres d'un jour verrouillé (`wellness.py:704-713`) ; aucun crédit des verrous (contrairement au plancher de gymnase P4-97). Sonde moteur : forcedDays [mercredi] + verrou HARD le mercredi → `INFEASIBLE`, `causes: []`, l'autre équipe non placée. Même résultat avec une indispo coach ou un minStartTime HARD qui vide le jour. Gate aveugle (`PreSolvePreventionWarnings.php:315-366` ne lit ni verrous ni indispos) ; diagnostic de verrou muet (`diagnostics.py:272-284`). Flux touchés : **comblement de période** (épingle HARD toute la version source, `GenerateScheduleHandler.php:246-251`) et toute régénération après un verrou | align/engine | **Élevée** | **confirmé (3 couches lues + sonde)** | nouveau |
| **ALIGN-17** | **L'indisponibilité d'un coach au placement des matchs fait l'INVERSE de l'écran.** L'écran donne l'exemple « pas de match avant 14h le samedi » (`ConstraintsPage.tsx:752-753`) avec un champ « Pas avant (heure de début) » ; « Pas avant 14:00 » est stocké `kickoffMin=14:00` (figé par `ConstraintsPage.test.tsx:313-318`) ; le moteur lit `kickoff_min` comme le **début de l'indisponibilité** et pénalise tout coup d'envoi ≥ 14:00 (`match_placement.py:182-191`). Sonde : match placé à 10:30. Poids 60 (= conflit coach principal). Les deux cas à une borne sont inversés. Né en #1029 (champs recopiés des règles club, intention inverse) | align/front | **Élevée** | **confirmé (3 couches lues + sonde)** | nouveau |
| **ALIGN-18** | **« Verrouillé » est proposé sur « évite ce gymnase » puis refusé au récap, qui bloque la génération.** Sélecteur `RULES = [PREFERRED, HARD, LOCK]` sur la famille gymnase (`ConstraintsStep.tsx:45,944`, figé par `ConstraintsStep.test.tsx:450`) ; `ConstraintValidationService.php:184-186` : « Le verrouillage n'est possible que sur une contrainte d'horaire ou de jour » → erreur bloquante (`useStepValidation.ts:285-288`) ; le moteur l'honorerait en dur (`parsing.py:382`) et la matrice le déclare offert. Bruyant et récupérable — Élevée par la règle « scission sur flux critique » | align | **Élevée** | **confirmé (3 couches lues)** | nouveau |
| **SEC-25** | **Une requête ANONYME qui porte `X-Club-Id` fixe le contexte club → l'horloge d'une démo pilote les échéances publiques d'un vrai club.** `TenantFilterListener.php:120-122` pose `_club_id` puis ne vérifie l'appartenance **que si** un `User` est connecté ; `ClubDay::todayFor()` d'un club réel sans date simulée retombe sur l'horloge décorée (`ClubDay.php:76`), qui lit ce `_club_id`. Avec l'UUID d'une démo à date passée : échéance des doléances coach d'un club réel contournée (`PublicCoachWishController.php:245-254`), approbation expirée rejouable (`ClubApprovalController.php:109-111`), jetons d'e-mail prolongés. Exige le jeton en main. Contredit la décision du 2026-10-02 (« un club réel n'est jamais affecté ») ; `TenantFromJwtTest` ne couvre que l'authentifié | cyber | **Moyenne** | **confirmé (listener + ClubDay relus)** | nouveau |
| **SEC-28** | **Compte démo : garde indexée sur l'e-mail, gestes de compte ouverts → un compte démo devient un compte permanent.** `UserChecker.php:54,59-64` reconnaît le compte démo par son adresse ; aucune garde sur `POST /api/me/email`, `/api/me/password`, `DELETE /api/me` (`AuthController.php:592-680`, `DeleteAccountController.php:38`). Pendant la fenêtre de 4 h, qui a le mot de passe (qui circule, par conception) peut changer l'e-mail → sortie définitive de la fenêtre avec le rôle gestionnaire du club démo, ou verrouiller l'animateur. Aucune révocation du JWT à la fermeture (TTL 3 600 s) | cyber | **Moyenne** | confirmé | nouveau |
| **SEC-23** | **Secrets de prod chiffrés en SYMÉTRIQUE dans un dépôt PUBLIC.** `.env.prod.gpg` suivi, `gpg --symmetric --cipher-algo AES256` (`Makefile:155`), déchiffré en CI (`deploy.yml:225-247`) ; toute la sécurité tient à une passphrase attaquable hors ligne sans limite ; l'ancien chiffré reste dans l'historique (#1007). Point mort de l'édition 09-18 (A15 noté protégé sans regarder la visibilité du dépôt). Élevée si la passphrase est faible (non vérifiable) | cyber | **Moyenne** | confirmé (sauf entropie) | nouveau |
| **SEC-24** | **Un seul club peut affamer le placement de tous les autres.** Un jeton de placement global (sémaphore 1, `main.py:138`), solve 60 s (`MatchPlacementPayloadBuilder.php:335`), transport 90 s (`PlaceMatchesController.php:54`) ; `/api/fixtures/place` sans limiteur dédié ni quota par club (seuls le verrou par club et `api` 300/min) ; un club démo échappe au placement semaine par semaine (`:122-131`). Corollaire : 5 générations/h × 600 s laissent ~83 % du solveur à un seul club. Voir ENG-50 (la mesure) | cyber/engine | **Moyenne** | confirmé | nouveau |
| **ENG-48** | **Un seul match FIXED qui finit après minuit vide TOUT l'appel de placement, avec un message faux.** `span_end` borné à `day_end` (`match_placement.py:723`) et contrainte inconditionnelle `span_end >= kick + m_match` pour les FIXED (`:730-731`) → INFEASIBLE ; branche `else: # pragma: no cover — the model is always feasible` (`:781-782`) → tous les matchs, toutes dates, `not_selected` (« relancez le placement ») avec `status: "completed"`. Sonde : FIXED 21:00 → 1 placé ; 22:30 → 0, et un match d'un autre jour vidé aussi. Ouvert par la pose manuelle, qui n'exige que kickoff ∈ [start, end[ | engine | **Moyenne** | **confirmé (exécuté + code relu)** | nouveau |
| **ENG-49** | **La mémoire du placement n'est pas budgétée face au `mem_limit: 512m` du moteur** (`docker-compose.prod.yml:284`) : pic RSS **370 MiB** (291 matchs) et **435 MiB** (600 matchs) ; `MAX_MATCHES=2000` ; garde de build en temps seul ; uvicorn mono-processus → un OOM tuerait aussi une génération en cours | engine | **Moyenne** | **mesuré** (cas concurrent non mesuré) | nouveau |
| **ENG-50** | **`/place-matches` brûle tout son budget sans jamais prouver l'optimum** : résultat identique à 8 s et 20 s (même hash), mais le budget backend est 60 s → un 2ᵉ club attend ~62 s puis tourne 60 s, au-delà des 90 s de transport → 502 pendant que le moteur finit un solve orphelin (`to_thread` non annulable). Doc périmée « ~3 s » (`config.py:28`, `main.py:772`) | engine | **Moyenne** | mesuré (famine inférée) | nouveau |
| **BCK-34** | **Sous une date simulée, l'horloge falsifie des durées de sécurité.** `MercureAuthController` (`:48,77-81`) : une démo à date passée reçoit un JWT/cookie Mercure **déjà expiré** (progression de génération en direct perdue) ; une date future les prolonge (jusqu'en 9999) ; même mécanique pour le jeton de changement d'e-mail (`EmailChangeVerifier.php:42-43`). Aucune borne sur la date (`ClubClockController.php:105-111` accepte 0001→9999). Aucun test | backend | **Moyenne** | confirmé (effet UI non vérifié) | nouveau |
| **UXC-26** | **Le planning de saison a trois noms visibles** : « planning principal » (`AppLayout.tsx:106`, ~21 lignes), « planning de (la) saison » (`shared/lib/socle.ts:24`, ~20), « planning de base » (`DayDialog.tsx:795`) — sur l'objet central du produit, déjà signalé par le fondateur (`SeasonPlanBanner.tsx:93-95`) | ux | **Moyenne** | confirmé | nouveau |
| **UXS-09** | **Échec de chargement rendu comme du vide sur les deux écrans principaux (UXS-05, 3ᵉ migration).** `PlanningPage.tsx:97` (`schedules = []`, seul `isLoading` gardé) → « Aucun planning — Passez par l'assistant… » (`:701`), conseil faux et actionnable ; créneaux → « Planning vide » (`:903`) ; accueil `CockpitPage.tsx:33,36,44` sans `readState` ; `RadarPanel.tsx:758` peut dire « Rien à l'horizon. Tout roule. » sans avoir lu l'échéancier | ux | **Moyenne** | confirmé | nouveau |
| **UXS-10** | **Les étapes de l'assistant n'ont aucun garde de chargement.** Dispatcher `WizardLayout.tsx:45-59` sans attente ; `TeamsStep` → « Aucune équipe pour le moment » (`:871`), `VenuesStep` (`:607`), **`CoachesStep` (neuf, #1033)** → « Aucun coach pour le moment » (`:432`) ; seuls les `impact*.isError` sont lus. Sur l'onboarding : un échec de lecture invite à re-saisir ce qui existe | ux | **Moyenne** | confirmé | nouveau |
| **UXS-14** | **Jargon régional au premier écran de l'inscription** : « Code ARA du club » (`RegisterPage.tsx:225,260`) ne parle qu'à Auvergne-Rhône-Alpes (le préfixe de 3 lettres = la ligue) ; exemple « Ex. BCCL0123 » (`:261`) hors format FFBB | ux | **Moyenne** | confirmé | nouveau |
| **A11Y-25** | **Texte de même teinte sur teinte translucide, hors de la garde A11Y-22** — calculé (clair) : libellé vacances `text-warning` sur `bg-warning/30` (`MonthCalendar.tsx:104,125`, écran d'accueil) **3,16:1** ; « F » férié **3,13** (`:115`) ; `text-accent` sur `bg-accent/10` (`LocksPanel.tsx:76`) **4,41** ; `WeekGrid.tsx:367` **4,41**. Sombre conforme. `a11y-contrast.spec.ts` ne visite pas le cockpit | ux | **Moyenne** | **calculé** (mesure navigateur non jouée) | nouveau |
| **DOC-49** | `backend/AGENTS.md:32` : coach-wish « LA SEULE ROUTE `/api/*` NON AUTHENTIFIÉE » — faux depuis le 2026-08-05 : 14 chemins `PUBLIC_ACCESS` (`security.yaml:45-79`), dont `/api/club-approvals/`, `/api/dev/demo-register`, `/api/register`, `/api/password`. Affirmation de surface d'attaque dans un fichier chargé par les agents | doc | **Moyenne** | **confirmé** | nouveau |
| **DOC-50** | #1064 a décalé `engine/app/main.py` de −34 lignes et re-tamponné les docs sans recaler les citations : **9 citations `main.py:NNN` fausses**, dont 3 sous un « ✓ » de stamp (`_adaptive_timeout` cité `:374-389`, réel `:340` — `nominal-flow.md:6`, `solver-errors.md:9`, `business.md:5`). Cause : `DocStampFreshnessTest` compare dates, jamais le code cité ; une citation par numéro de ligne pourrit à chaque refactor (ancrer sur le symbole) | doc | **Moyenne** | **confirmé** | nouveau |
| **DOC-51** | `docs/project-map.md:162` = **une ligne de 8 101 caractères** (19 % du doc n°2 de l'ordre de lecture) : changelog complet du contrat 2.1→2.29 (« 2.25 » deux fois), contraire à la règle 7 ; `SpecsCarryNoHistoryTest` le laisse passer (`/superseded/` ne ferre pas « superseding ») ; après le reset 1.0, des étiquettes « contrat 2.x » subsistent (engine-inventory ×16, frontend-spec, constraint-matrix) et l'OpenAPI publié dit « contrat moteur 2.7 » ×5 | doc | **Moyenne** | **confirmé** | nouveau |
| ALIGN-19 | L'indispo d'un coach **assistant** n'a aucun effet, sans signal, sous la pastille « Obligatoire » : assistants exclus de `team_coach_map` (`parsing.py:215-225`), indispo appliquée via cette table seule (`structural.py:586-590`) ; sonde : ASSISTANT → équipe placée un jour interdit, 0 diagnostic | align | Moyenne | confirmé (3 couches + sonde) | nouveau |
| SEC-30 | L'invariant « horloge = démo seulement » n'est tenu qu'à l'ÉCRITURE : `ClubClock::simulatedTodayFor()` sans contrôle `is_demo` (`ClubClock.php:102-118`), aucun CHECK en base ; une valeur résiduelle (PR C du 2026-10-02 l'ouvrait à tout club) serait honorée ; dans un contexte démo, `AccountErasureService.php:82,154,167` calcule la grâce RGPD de **tous** les clubs orphelins de l'utilisateur sur la date simulée, `AuditTrail.php:63` horodate le journal d'audit à la date simulée (journal falsifié, purge prématurée) | cyber/backend | Faible | confirmé | nouveau |
| SEC-31 | La boîte aux lettres démo stocke en clair les liens à jeton des coachs (`CoachWishMailBuilder.php:44,113`), lisibles par **tout membre** du club (`MailboxController.php:22-27,48-59`, pas de garde gestionnaire) et par `amateo_read` (`Version20261002120000`, « aucun secret ») — contradictoire avec l'exclusion de la colonne `token` (`Version20260930090000.php:83-88`) ; périmètre démo | cyber | Faible | confirmé | nouveau |
| BCK-35 | Reset de la démo BCCL = sous-processus synchrone de 600 s sans verrou (`DemoResetRunner.php:37-43`) derrière nginx 120 s → 504 pendant que le seed continue, deux clics = deux seeds concurrents | backend | Faible | confirmé | nouveau |
| BCK-36 | `league_window_suggestions` compte les clubs démo dans les pairs (`Version20260929120000.php:69-75`) et ne lie pas `p_requesting_club` au contexte tenant | backend | Faible | confirmé | nouveau |
| BCK-37 | Logo adverse sans cache négatif : chaque GET d'un code inconnu relance 8 s de FFBB, sans limiteur dédié (`OpponentLogoController.php:39-49`) | backend | Mineure | confirmé | nouveau |
| BCK-38 | `PurgeCompletenessTest` et `TenantOwnedInterfaceCompletenessTest` ne sont pas des steps de `blocking-tests` : ils tournent après le gate, sans bloquer `build-docker` (CLAUDE.md §4) | backend | Mineure | confirmé | nouveau |
| BCK-39 | `TeamCoachInput` : `teamId`/`coachId` en `NotBlank` sans `Uuid` (résidu BCK-27) | backend | Mineure | confirmé | nouveau |
| RGPD-02 | L'effacement d'un club garde le siège ÉDITÉ par le club (adresse + coordonnées, `ClubSiegeController.php:97-100`) — non re-synchronisé depuis la FFBB, contre « seule l'identité FFBB survit » (`ErasedClubPurger.php:30,135-145`) | RGPD | Faible | confirmé | nouveau |
| RGPD-03 | E-mail et téléphone des coachs envoyés au moteur (`ScheduleConstraintBuilder.php:1183-1184`), jamais lus, persistés dans chaque `schedule.snapshot_data` et recopiés dans `Feedback.context` (`FeedbackController.php:127`) — minimisation ; purge de ces copies à la suppression d'un coach non vérifiée | RGPD | Faible | confirmé (purge non vérifiée) | nouveau |
| ENG-51 | Le verdict confond UNKNOWN (2 s) et INFEASIBLE (`validate_assignments.py:931-932`) ; le contrôle de base sous le même budget peut affirmer à tort « déjà infaisable » (`:965-983`) | engine | Faible | lecture | nouveau |
| ENG-52 | Déterminisme sur limites de temps MURAL partout (aucun `max_deterministic_time`) : sonde placement 0 placé à 4 s, 103 à 8 s → reproductibilité dépendante de la charge CPU | engine | Faible | mesuré | nouveau |
| ENG-53 | Champs de schéma acceptés jamais lus (`isActive` équipes/gymnases/coachs, `minSessionsOverride`, `tags`, `orToolsWeight`…) ; le backend n'exclut pas les inactifs (`ScheduleConstraintBuilder.php:986-996,1127`) → une équipe désactivée par l'API serait planifiée | engine | Faible | grep | nouveau |
| ALIGN-20 | `kickoffEstimated`, `roundTripMinutes`, `warmupMinutes` transportés jamais lus ; commentaires faux (`match_input_schema.py:168-170` ; `MatchPlacementPayloadBuilder.php:181-185,400-403` « le solveur étend la fenêtre AWAY ») ; en-tête « contract 2.2 » (`:31`) ; test qui garde un champ mort ; ligne roadmap ALIGN-13 sans objet | align | Faible | confirmé | nouveau |
| ALIGN-21 | Indispo coach ciblée par tag (API seule) acceptée partout, résolue en ÉQUIPES, sans effet ni avertissement (`ConstraintConfigValidator:42`, `ScheduleConstraintBuilder.php:1327-1354`, `parsing.py:267`) | align | Faible | confirmé | nouveau |
| FRT-38 | Double retour d'erreur quand `onError` est passé à `mutate()` : le filet global ne regarde que le niveau hook (`queryClient.ts:30-38`) → 19 sites (même message toasté deux fois sur `ClubPage.tsx:298`) ; invisible en test (clients sans filet) | frontend | Faible | **exécuté (sonde)** | nouveau |
| FRT-40 | Lectures non gardées qui orientent la création d'un match : compétitions en `?? []` (`CalendarPage.tsx:576-580`) → en échec le formulaire n'offre qu'« Amical » (`FixtureFormDialog.tsx:104-111`) et le match échappe au refus « fenêtre d'accès » serveur | frontend | Faible | confirmé | nouveau |
| FRT-41 | Widget horloge démo : erreur en toast générique qui masque le message serveur (`DemoClockWidget.tsx:55`), aucun test d'échec (focus → A11Y-29) | frontend | Faible | confirmé | nouveau |
| UXC-25 | « Entraîneur » ×7 dans la section « Coachs » (`ConstraintsPage.tsx:94,718-867`) — « coach » partout ailleurs | ux | Faible | confirmé | nouveau |
| UXC-27 | Bandeaux faits main hors portée du garde (`CreditsBanner.tsx:37,58` en `shared/`, le garde ne lit que `features/` ; `slotFields.tsx:42`, `SlotDetail.tsx:249`) | ux | Faible | confirmé | nouveau |
| UXC-28 | Contrôle segmenté recodé 6 fois sans primitive (hauteurs divergentes) ; puces `rounded-full` dans `CampaignDialog.tsx:436-458` malgré `pillPrimitiveGuard` | ux | Faible | confirmé | nouveau |
| UXC-29 | Norme 36 px contournée par `h-7` (garde ESLint ne vise que `h-(8\|10\|11)`, `eslint.config.js:80`) : 14 sites, dont `FilterChip` lui-même (`filter-chip.tsx:39`) | ux | Faible | confirmé | nouveau |
| UXC-30 | Norme « 1h30 » contournée par `${x} min` (`SlotDetail.tsx:162`, `PeriodVenues.tsx:502`, `MatchDurationsEditor.tsx:208`) ; « 21h » en prose | ux | Faible | confirmé | nouveau |
| UXS-11 | Jargon visible : « solveur » ×6 (`implicitRules.ts`, `leagueValidation.ts:33`, `title` de `CoachesStep`), « API FFBB » ×4, « Couleur (hexadécimal) » | ux | Faible | confirmé | nouveau |
| UXS-12 | « Vérifiez que le moteur tourne, puis réessayez » (`GenerateStep.tsx:329`) — message de développeur sur le flux de génération ; « moteur » ×10 visible | ux | Faible | confirmé | nouveau |
| UXS-13 | 14 `onError: () => toast.error("X impossible")` dans `matches/queries.ts` écrasent le message serveur (409/422 expliqués) ; 17 toasts sans cause ni suite | ux | Faible | confirmé | nouveau |
| UXS-15 | Trois pictogrammes rouges quasi identiques sans légende sur le calendrier d'accueil (⛔ 🛑 🚫, `cockpit/lib/markers.ts:35,46,48`) — sens au survol seul | ux | Faible | confirmé | nouveau |
| A11Y-26 | A11Y-24 revient : 5 régions défilantes sans descendant focalisable (`ImportFbiDialog.tsx:271,289,306,330`, `CoachesStep.tsx:199`) | ux | Faible | confirmé | nouveau |
| A11Y-27 | Libellés de champs à 10 px atténués hors grille (`IdealSlotsEditor.tsx:169-194,374`, ajoutés par l'uniformité) | ux | Faible | confirmé | nouveau |
| A11Y-28 | Noms accessibles génériques répétés en liste (« Supprimer »/« Modifier »/« Retirer », 14 sites) | ux | Faible | confirmé | nouveau |
| A11Y-29 | Popover d'horloge démo `role="dialog"` sans Échap ni gestion du focus (`DemoClockWidget.tsx:89-124`) — son voisin `BetaBadge` les gère | ux | Faible | confirmé | nouveau |
| A11Y-30 | Raison d'une désactivation portée par un `title` sur un élément non focalisable (~22 boutons, lien « Matchs » grisé `AppLayout.tsx:103-107`) | ux | Faible | confirmé | nouveau |
| DOC-52 | Résidus du nettoyage #1064 : `backend/AGENTS.md:30` décrit `PurgeOrphansCommand` supprimé ; `gestion-matchs-ffbb.md:88` cite `Enum/ImplicitConstraint.php` supprimé | doc | Faible | confirmé | nouveau |
| DOC-53 | Horloge par club et mode démo **absents de la carte** (0 hit dans `project-map.md` et `glossary.md`) ; la règle « aujourd'hui passe par ClockInterface » ne vit que dans `.claude/rules/backend.md` | doc | Faible | confirmé | nouveau |
| DOC-54 | `W_COACH_UNAVAILABLE`/`W_CLUB_RULE` cités avec leur valeur dans 5 docs, hors du registre de `test_weights_doc_sync.py` (motif DOC-39 : garde né, pas étendu) | doc | Faible | confirmé | nouveau |
| DOC-55 | Roadmap en retard sur P5-28 : checklist JOUR J (`roadmap.md:88-93`) le programme encore ; P5-21 ouvert et compté alors que vidé ; étiquettes [AVANT/APRÈS PROD] contraires à la décision fondateur du 2026-09-30 (non consignée dans le dépôt) | doc | Faible | confirmé | nouveau |
| DOC-56 | Graduation non faite sur ~1 200 l. d'`evolution/` majoritairement closes (`ffbb-appariement-source-de-verite.md`, `reprise-perimetre-engage.md`, `gestion-matchs-ffbb.md`) | doc | Faible | confirmé | nouveau |
| DOC-60 | Les 3 docs d'alignement portent des affirmations périmées : exclusivité « Camus réservé » (`constraint-vocabulary.md:104`), `allowedDays` « toujours dur » (`:54`), règles réglables marquées « dur » (`:182-186`), MIN_SESSIONS « à trancher » (`constraint-coverage.md:83`), « Coach indisponible ✅ » / « Au moins une séance ✅ » qui ignorent ALIGN-19/16, `constraint-emission.md:105` (HARD preferred → forcé), commentaires morts (`ConstraintValidationService.php:146-147`, `parsing.py:437-438`) ; `constraint_matrix.py:172-192` déclare offertes des cellules que le wizard ne propose plus | doc/align | Faible | confirmé | nouveau |
| INF-06 | `with-sandbox` ne migre pas le bac à sable : après un pull qui ajoute une migration, **Behat est 89/89 rouge en 1 min 20** (HTTP 500 partout) — constaté par cet audit (2 migrations en retard), corrigé par `with-sandbox.sh make -C backend db-init` ; aucun avertissement ne pointe la cause | infra | Faible | **constaté en réel** | nouveau |
| DOC-57 | `specs/initiales` modifié (`rechercherRencontre.xlsx`, #955) alors qu'il est figé (`specs/README.md:15`) — sert de fixture à `XlsxUploadGuardTest.php:29` | doc | Mineure | confirmé | nouveau |
| DOC-58 | CLAUDE.md:51 décrit `engine make test` sans `deptry` (`engine/Makefile:32`) | doc | Mineure | confirmé | nouveau |
| FRT-39 | Régression FRT-29 : 1 warning react-refresh (`LoginSplash.tsx:29`) ; `npm run lint` sans `--max-warnings 0` | frontend | Info | exécuté | nouveau |
| FRT-42 | Fixture de test hors type (`ClubPage.test.tsx:167`) → avertissement « unique key » au run ; 165 `as unknown as`/`as never` | frontend | Info | exécuté | nouveau |
| ENG-54 | 12 fixtures `engine/tests/fixtures/*.json` en version `"2.0"`/`"2.7"`, rejetées par la vraie garde, passent parce que les tests contournent FastAPI | engine | Info | confirmé | nouveau |
| ENG-55 | Le reset réutilise l'étiquette « 1.0 » de l'ère pré-2.0 : `Schedule.constraintVersion` persisté devient non monotone (sans effet aujourd'hui) | engine | Info | confirmé | nouveau |
| ENG-56 | `/place-matches` : `rule_type`/`type`/`role` en `str` libres → une valeur inconnue est ignorée en silence ; pas de test miroir à cas partagés pour la règle club ; registre de parité AST limité à `/generate` ⇄ verdict | engine | Info | confirmé | nouveau |
| UXC-31 | 9 `<Loader2>` bruts hors de `Spinner` + un chargement recodé (`PlanningPage.tsx:893-900`) | ux | Info | confirmé | nouveau |
| A11Y-31 | Animations sans `motion-reduce` (`RootShell.tsx:46`, 4 `animate-pulse`, 12 `animate-spin`) | ux | Info | confirmé | nouveau |
| A11Y-32 | Cibles < 24 px (`FbiEntryList.tsx:281` 20 px, coche au survol ; `toaster.tsx:34`, `CreditsBanner.tsx:64`) | ux | Info | confirmé | nouveau |
| DOC-59 | Croissance sans borne : `frontend-spec.md` 167 → 188 Ko, `backend-inventory.md` 152 → 178 Ko en 15 j ; lignes de 4,5 Ko dans `backend/AGENTS.md` | doc | Info | confirmé | nouveau |

**7 nouvelles Élevées (SEC-26, SEC-27, SEC-29, BCK-33, ALIGN-16, ALIGN-17, ALIGN-18)**, toutes contre-vérifiées à la main — contre 2 le 09-18. **15 Moyennes**, dont 4 mesurées ou sondées. Aucun Critique.

---

## Tableau de posture cybersécurité (A1–A28)

| # | Attaque | Verdict | Preuve `fichier:ligne` | SEC- |
|---|---|---|---|---|
| A1 | Accès cross-tenant / IDOR | **partiel** *(était protégé)* | garde d'en-tête usurpé pour un connecté (`TenantFilterListener.php:122-136`) ; 6 tables tenant neuves conformes RLS ; **mais** un club sans membre est repris par n'importe quel inscrit (SEC-27) et une requête anonyme fixe le contexte club (SEC-25) | SEC-27, SEC-25 |
| A2 | Brute-force `/login` | protégé | `security.yaml:31-32` ; 2ᵉ oracle de mot de passe (compte démo) limité par IP (`rate_limiter.yaml:4-7`) | — |
| A3 | Énumération de comptes | protégé | refus traduits sous la même clé (`security.fr.xlf:18`), garde `LoginFailureCopyTest` | — |
| A4 | Falsification JWT | protégé | RS256, 0 fichier suivi dans `config/jwt` ; Lexik garde son horloge native (ClubClock n'affecte pas l'`exp`) | — |
| A5 | Escalade de privilège | protégé | `AbstractStateProcessor.php:88-90,130` (gestion requise par défaut) ; après #1065, aucune écriture sans garde | — |
| A6 | Mass-assignment | **partiel** *(était protégé)* | `ffbbClubCode` inscriptible (`ClubInput.php:51-53`, `ClubStateProcessor.php:139-140`) | SEC-26 |
| A7 | Injection SQL | protégé | SQL neuf paramétré ; colonne de `ClubClockCommand.php:88-91` issue d'un choix binaire fixe | — |
| A8 | XSS | protégé | boîte aux lettres rendue en `<iframe sandbox="" srcDoc>` (`MailboxPage.tsx:139`) ; logo raster en liste blanche + `finfo` | — |
| A9 | CSRF | protégé | cookie `BEARER` `samesite: strict` + `httpOnly` | — |
| A10 | DoS bombe de génération / placement | **partiel** *(était protégé)* | listes bornées ; **un jeton de placement global, aucun quota par club** ; mémoire non budgétée | SEC-24, ENG-49/50 |
| A11 | Spam routes anonymes | protégé | 1 route publique neuve active en prod (`/api/dev/demo-register`, fenêtre ouverte + limiteur IP) ; commentaire périmé `security.yaml:74-78` | — |
| A12 | SSRF | protégé | hôtes en dur, `max_redirects: 0`, timeouts, plafond de taille (`BanGeocodingClient.php:161-169`) | — |
| A13 | Abus d'upload | **protégé** *(était partiel)* | `XlsxUploadGuard` sur les deux imports + limiteur 30/h | SEC-22 corrigé |
| A14 | Fuite Mercure | protégé | dev épinglé v0.24.2 ; prod v0.19 par tag (pas de digest) | — |
| A15 | Exposition de secrets | **partiel** *(était protégé)* | 0 clé suivie ; **dépôt PUBLIC** + `.env.prod.gpg` symétrique ; identifiants dev réels publiés | SEC-23, SEC-29 |
| A16 | Erreurs verboses | protégé | `APP_ENV: prod` ; imports : exception loggée, message générique (valeurs `.env.prod` non lues) | — |
| A17 | Clickjacking / en-têtes | protégé | CSP `frame-ancestors 'none'`, XFO DENY, nosniff, HSTS ; polices du splash auto-hébergées | — |
| A18 | Dépendance vulnérable | protégé | **0 vuln ×3 exécutés ce jour** | — |
| A19 | Usurpation d'approbation de club | **partiel** *(était protégé)* | jeton inchangé ; **mais** SEC-26 détourne l'approbation légitime en adhésion PENDING chez le squatteur ; l'expiration 7 j se contourne via SEC-25 | SEC-26, SEC-25 |
| A20 | Chaîne de déploiement | **protégé** *(était partiel)* | 5/5 workflows avec `permissions:` ; Info : `deploy.yml` sans `environment:` protégé | SEC-21 corrigé |
| A21 | Empoisonnement — échéances partagées | partiel | aucune provenance — fermé par décision | (BCK-18 ⛔) |
| A22 | Empoisonnement — annuaire / salles adverses | partiel | appariement choisi par le club, sans provenance — fermé par décision | (SEC-20 ⛔) |
| A23 | Amplification d'appels sortants | **protégé** *(était partiel)* | limiteur 30/h sur les 3 routes (`OpponentTravelController.php:150,303,641-645`) | SEC-19 corrigé |
| **A24** | **Horloge par club détournée** — *ligne neuve* | **partiel** | 3 écrivains tous réservés aux démos (`ClubClockController.php:57-59`) ; JWT/limiteur/reset de mot de passe hors de portée ; **mais** lecture sans `is_demo`, contexte posé par en-tête anonyme | SEC-25, SEC-30, BCK-34 |
| **A25** | **Comptes démo détournés** — *ligne neuve* | **partiel** | création anonyme impossible, fenêtre en temps réel, purge sûre ; **mais** garde par e-mail et gestes de compte ouverts ; capture d'une vraie inscription | SEC-28, BCK-33 |
| **A26** | **Rôle Postgres lecture seule abusé** — *ligne neuve* | protégé | `NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS` (`Version20260930090000:143`), liste blanche sans secrets, pas d'`ALTER DEFAULT PRIVILEGES` ; Info : boîte aux lettres accordée entière | SEC-31 |
| **A27** | **Appropriation d'un code FFBB / d'un club hors approbation** — *ligne neuve* | **absent** | aucune garde ni test d'immuabilité du code ; reprise sans preuve d'appartenance | SEC-26, SEC-27 |
| **A28** | **Exposition par dépôt public** — *ligne neuve* | **partiel** | données réelles et identifiants dev dans `src/Seed` ; secrets de prod chiffrés symétriquement | SEC-23, SEC-29 |

**Bilan cyber : 17 protégé · 10 partiel · 1 absent · 0 non vérifié** (vs 09-18 : 18/5/0/0 sur 23 lignes). 3 lignes **remontent** (A13, A20, A23 : SEC-19/21/22 corrigés), 5 **descendent** (A1, A6, A10, A15, A19), **5 neuves** dont une `absent` (A27). La régression vient entièrement des surfaces de la semaine (code FFBB comme identité, horloge, démos) et d'un angle mort (dépôt public) que l'édition précédente n'avait pas ouvert.

---

## Détail par critère

### 1. Documentation — 82/100 (exactitude 34/40 · structure 15/20 · utilité IA 21/25 · cycle specs 12/15)
**Forces.** CLAUDE.md exact sur 8 sondes (priorité 7, RLS, contrat 1.0 aux 6 sites + 3 tests, 5 routes moteur, required checks lus via `gh api`, versions de stack) ; 0 chemin manquant sur tout l'index ; **11/12 DOC du 09-18 soldés, et soldés par leur règle** (gardes de version, de poids, d'historique) ; état des lieux −65 %, `module-matchs.md` −53 % et réorganisé par écran ; compteur roadmap 43 = 43 ; 5/5 ouverts réels, 5/5 livraisons tracées ; aucun stamp empilé sur 40.
**Faiblesses.** Le motif a changé : les fautes naissent là où un garde compare **dates ou chaînes** au lieu du **code** — stamp contre édition (DOC-50 : 9 lignes mortes sous « ✓ »), `superseded` contre `superseding` (DOC-51 : 8 Ko de changelog sur une ligne de la carte), registre de poids figé avant P4-272 (DOC-54). DOC-49 est une affirmation de surface d'attaque fausse depuis 59 jours dans un fichier chargé par les agents. La carte ignore l'horloge et la démo (DOC-53), mécanismes transverses de la semaine.

### 2. Pertinence du besoin — 91/100
La semaine a livré ce qu'un commercialisable exige au-delà du solveur : démonstration en 4 h pilotée par la console, horloge pour rejouer une saison devant un prospect, import d'équipes FBI, splash de marque, uniformité des écrans, contrat remis à 1.0. Le cycle « passe de tests → PR » reste exemplaire (passe du 27/09 : 10 correctifs). Réserves : la démo a été construite sur le **vrai code FFBB** du prospect sans penser à la vraie inscription qui suit (BCK-33) — exactement le moment de conversion qu'elle prépare ; et deux promesses d'écran neuves (ALIGN-17, ALIGN-18) disent autre chose que l'effet.

### 3a. Code backend — 62/100 (correction+sécu 24 · architecture 14 · tests 14 · robustesse 10)
**Forces.** 14/17 anciens findings soldés avec preuve ; **`PurgeCompletenessTest`** ferme BCK-24 par la règle ; radar unique ; garde de redélivrance ; 21 migrations 100 % RLS ; rôle `amateo_read` exemplaire (NOBYPASSRLS, liste blanche, colonnes) ; intercepteur de mails démo correct (liste blanche, destinataires du club, GUC club par club) ; seed prod sans route, saisie masquée, refus si compte existant.
**Faiblesses.** **Trois Élevées sur l'identité d'un club** (SEC-26 PUT du code FFBB, SEC-27 reprise sans preuve, BCK-33 capture par une démo), une Élevée de publication (SEC-29), plus SEC-25/28 et BCK-34 en Moyenne : l'horloge et les démos ont été livrées avec leurs gardes d'**écriture**, sans modèle de menace sur la **lecture** ni sur les gestes de compte. Aucun test ne parcourt un seul de ces chemins d'abus. BCK-19 s'aggrave (54 → 66 fichiers > 300 l.). Pas de défaut Critique : pas de plafond à 60 — mais la note tombe sous la tranche « solide ».

### 3b. Code engine — 79/100 (correction+sécu 31 · architecture 19 · tests 17 · robustesse 12)
**Forces.** 8 findings soldés ; registre de parité **sans aucune exemption** ; 13/13 clés consommées, champs P4-272 tous lus ; contrat 1.0 = barrière MAJOR franche dans les deux sens ; bandit + deptry en CI ; garde de build en temps sur le placement + gate perf ; harnais de test qui suit `_solve`.
**Faiblesses.** **ALIGN-16** est un défaut moteur (le plancher de jour imposé ne crédite pas les verrous) qui rend la génération entière INFEASIBLE sans cause, sur le comblement de période. **ENG-48** : une branche marquée `no cover` « toujours faisable » est atteignable par une seule pose manuelle tardive, et vide alors toute la saison avec un message faux. ENG-49/50 : le rail de placement, devenu lourd (P4-240), n'est budgété ni en mémoire ni en équité entre clubs. Déterminisme en temps mural partout (ENG-52).

### 3c. Code frontend — 82/100 (correction+sécu 34 · architecture 19 · tests 17 · robustesse 12)
**Forces.** Exécuté : **3 656 tests / 358 fichiers verts au run 1, 0 retry**, tsc 0, knip 0 ; FRT-30/31/32/37 + UXS-08 soldés (FRT-31 avec contre-garde serveur au même prédicat) ; cliquet `act` 70 → 30 ; filets globaux toujours en place ; splash de connexion propre (anti-blocage à 3 sorties, reduced-motion, `role=status`).
**Faiblesses.** ALIGN-17 et ALIGN-18 sont des défauts d'écran (libellé inversé, cran proposé puis refusé) ; FRT-38 (double toast invisible en test) ; FRT-40 (match créé « Amical » faute de lecture) ; 39 fichiers > 400 l., `matches/queries.ts` 1 007 l., 6 cycles inter-features sans garde, hook-mocking 76 fichiers.

### 4. Supply chain — 96/100
**0 vulnérabilité aux trois audits exécutés ce jour** (composer, npm, pip-audit installé à la volée puis retiré). knip, composer-unused et deptry posés en garde (#1036) ; image moteur de prod sans outils dev (#1051) ; Dependabot étendu aux images Docker. Retenues inchangées : pas de SBOM ; Mercure de prod épinglé par tag v0.19, pas par digest ; paquet local hors pip-audit.

### 5. Performance solveur — 84/100
Behat complet : **89/89 scénarios, 505 étapes, 8 min 31 s** (vs 60 / 316 / 433 s) — la suite a grandi de 48 % et reste verte au premier run une fois le bac à sable migré (INF-06). Retenues mesurées : placement à **435 MiB** pour 600 matchs face à 512 Mo (ENG-49), budget de 60 s brûlé pour un plateau atteint à 8 s (ENG-50), première solution entre 4 et 8 s selon la charge (ENG-52). Harnais de charge toujours jamais lancé.

### Cybersécurité — voir tableau A1–A28 : 17 protégé · 10 partiel · 1 absent
La surface historique tient et progresse (A13, A20, A23 remontent). La régression est concentrée sur un objet que le dépôt n'avait jamais modélisé comme une **identité** : le code FFBB d'un club. Trois portes y mènent (PUT, reprise, démo) et aucune n'a de test d'abus. Deuxième leçon : le dépôt est **public** — ce qui transforme un seed de dev en publication de données personnelles (SEC-29) et un fichier de secrets chiffré en cible hors ligne (SEC-23).

### RGPD — couvert, 1 Élevée (SEC-29), 2 Faibles
BCK-24 fermé par un méta-garde (la meilleure correction de l'édition). Neuf : publication de données personnelles réelles dans le dépôt public (SEC-29) ; siège édité qui survit à l'effacement (RGPD-02) ; e-mail/téléphone des coachs envoyés au moteur et copiés dans les snapshots et les signalements (RGPD-03) ; grâce RGPD et journal d'audit calculés sur la date simulée en contexte démo (SEC-30).

### UX — cohérence 72 · simplicité 66 · inclusivité 70 → général 66
**Cohérence (72)** : la passe d'uniformité a fait converger heures, durées, boutons, bandeaux, pastilles et sélecteurs de jours, avec des gardes. Plafonnée par **UXC-26** (trois noms pour le planning de saison, l'objet central) ; les gardes ont des trous de portée (`shared/` pour les bandeaux, `h-7` pour les hauteurs, « N min » pour les durées).
**Simplicité (66)** : les 4 flux-clés restent courts (inscription 2 écrans + assistant 6 étapes ; générer ~4 clics ; placer 3-5 gestes). Plafonnée par **UXS-09/10** (le motif « échec de lecture rendu comme du vide » a migré une 3ᵉ fois, cette fois sur le planning, l'accueil et l'assistant — il mérite un garde statique, pas un 4ᵉ correctif local) et **UXS-14** (« Code ARA » au premier écran). Retour du jargon (« solveur », « moteur », « API FFBB »). Parcours authentifié non joué cette édition.
**Inclusivité (70)** : axe dynamique à 0 violation sur les écrans publics, clavier correct ; animations du splash et des scènes respectent reduced-motion. Plafonnée par **A11Y-25** (même teinte sur teinte translucide, 3,13-3,16:1 sur l'écran d'accueil, calculé) ; régressions de motif A11Y-24 → 26 et A11Y-16 → 27.

---

## Avis global + axes priorisés

**L'édition du registre qui tient et des surfaces livrées sans modèle de menace.** Tout ce que l'audit du 18/09 demandait a été fait, et fait par la règle : c'est la meilleure tenue de registre de la série. La note baisse quand même de 6 points, pour une raison qu'il faut regarder en face : en une semaine, trois fonctionnalités de démonstration et de mise en production ont touché à **qui possède un club** (code FFBB, reprise, démo sur le vrai code) et à **quelle heure il est** (horloge par club), avec des gardes d'écriture soignées et aucune question « et si quelqu'un d'hostile… ». Comme le dépôt est public, deux défauts deviennent des publications. Les recos P0 ci-dessous sont toutes courtes (S) : rendre le code FFBB immuable, aligner la reprise sur l'approbation, sortir la démo du vrai code, retirer les identités réelles du dépôt.

| Reco | Priorité | Effort | Traité |
|---|---|---|---|
| ALIGN-11 — dire l'exclusivité ou y renoncer | ~~P1~~ | — | ✅ (#923, D1 : exclusivité retirée, HARD refusé à l'écriture) |
| BCK-24 — méta-gardes de complétude des purgers | ~~P1~~ | — | ✅ (`PurgeCompletenessTest`, les deux purgers) |
| ENG-41 — retirer l'exemption du registre | ~~P1~~ | — | ✅ (`DECLARED_ARG_DIVERGENCES = {}`) |
| BCK-23 — `ConflictRadarLoader` unique + parité réelle | ~~P1~~ | — | ✅ |
| A11Y-22 — tokens AA + garde | ~~P1~~ | — | ✅ (garde d'opacité) — contourné par A11Y-25 |
| ENG-42 / FRT-30 / FRT-31 / FRT-32 / UXS-08 / SEC-19 / DOC-45 / DOC-37/39 | ~~P2~~ | — | ✅ tous (FRT-31 avec refus serveur ; DOC-45 avec garde) |
| DOC-38 — refondre `module-matchs.md` | ~~P2~~ | — | ✅ forme (1 555 l., par écran) ; pas de borne de taille |
| ENG-40 — borner le build de `/place-matches` | ~~P2~~ | — | ✅ temps (10 s + gate perf) ; mémoire ⬜ → ENG-49 |
| ALIGN-12 — trajet adverse au placement | ~~P2~~ | — | ⛔ décision B de P4-240 ③ (le solveur ignore les extérieurs, le radar signale) |
| ALIGN-10 — trancher MIN_SESSIONS | ~~P3~~ | — | ⛔ décision : soft assumé (triage 2026-09-25), garde armé |
| BCK-18 — provenance des tables partagées | ~~P2~~ | — | ⛔ fermé par décision (triage 2026-09-25) |
| FRT-22 — dédupliquer les types API | P2 | M | ⬜ reconduit (3 copies) |
| INF-05 — drill migrate → down → up | P3 | S | 🟡 joué par cet audit sur 5 migrations ; ⬜ toujours pas en CI |
| Fond de sac 09-18 | P3 | S | ✅ ENG-43/44/45/46/47, BCK-21/22/26-32, SEC-21/22, FRT-33-37 (FRT-33 🟡), UXC-20-23, A11Y-23/24, DOC-40-48 (DOC-46 ⬜), INF-04 · ⛔ UXC-24, UXS-07, A11Y-21 (décisions #966) · ⬜ BCK-20, FRT-20/21, ALIGN-14 🟡 |
| **SEC-26 — `ffbbClubCode` immuable côté serveur** (retirer du `ClubInput` ou refuser tout changement hors console superadmin, avec format FFBB validé) + test d'abus | **P0** | S | ⬜ nouveau |
| **SEC-27 — aligner la reprise d'un club sans membre sur P3-4** (approbation par la boîte FFBB du club, comme la création) + test d'abus | **P0** | S | ⬜ nouveau |
| **SEC-29 — sortir les identités réelles et les identifiants du dépôt public** (profil dev générique, identités BCCL injectées hors dépôt comme le profil prod), changer les mots de passe concernés ; décider du traitement de l'historique | **P0** | M | ⬜ nouveau |
| **BCK-33 — une démo n'occupe jamais le vrai code FFBB** (code synthétique dérivé, ou register/approbation qui ignorent les clubs `is_demo`) ; `hasOtherMember` ne compte que les actifs | **P0** | S | ⬜ nouveau |
| **ALIGN-16 — créditer les séances verrouillées dans « au moins une séance tel jour »** (comme le plancher de gymnase P4-97) + gate/diagnostic qui le nomment | **P1** | S | ⬜ nouveau |
| **ALIGN-17 — indispo coach au placement : libellés « Indisponible de / à »** (ou inverser le stockage) ; test qui vérifie l'EFFET, pas le payload | **P1** | S | ⬜ nouveau |
| **ALIGN-18 — retirer « Verrouillé » du sélecteur gymnase** (ou l'accepter au récap, le moteur l'honore) ; recaler `constraint_matrix.py` | **P1** | S | ⬜ nouveau |
| **SEC-25 — ignorer `X-Club-Id` sans `User` connecté** ; `ClubDay` lit l'horloge réelle pour un club non démo ; test anonyme | **P1** | S | ⬜ nouveau |
| SEC-28 — gestes de compte (e-mail, mot de passe, suppression) refusés au compte démo ; garde par drapeau, pas par adresse | P1 | S | ⬜ nouveau |
| SEC-23 — sortir les secrets de prod du dépôt public (gestionnaire de secrets de la CI / de l'hôte) ou passer à une clé asymétrique ; rotation | P1 | M | ⬜ nouveau |
| BCK-34 + SEC-30 — horloge : bornes de date, `is_demo` à la lecture + CHECK en base, horloge réelle pour Mercure / jetons e-mail / grâce RGPD / audit | P1 | S | ⬜ nouveau |
| ENG-48 — FIXED tardif : contrainte conditionnelle ou domaine étendu ; supprimer le `pragma: no cover` et tester la branche | P1 | S | ⬜ nouveau |
| UXS-09/10 — **garde statique** « une page qui rend un EmptyHint lit `readState` » (3ᵉ migration du motif) + correctifs Planning / Accueil / assistant | P2 | M | ⬜ nouveau |
| ENG-49/50 + SEC-24 — budget mémoire du placement, budget de solve aligné sur le plateau mesuré, quota par club | P2 | M | ⬜ nouveau |
| UXC-26 — un seul nom pour le planning de saison | P2 | S | ⬜ nouveau |
| UXS-14 — « Code club FFBB » + exemple au bon format | P2 | S | ⬜ nouveau |
| A11Y-25 — étendre la garde A11Y-22 aux paires teinte/teinte translucide ; ajouter le cockpit à `a11y-contrast.spec.ts` | P2 | S | ⬜ nouveau |
| ALIGN-19 — indispo d'un coach assistant honorée (ou dite « sans effet ») | P2 | S | ⬜ nouveau |
| DOC-49/50/51 — corriger la surface publique dans `backend/AGENTS.md` ; citations par symbole ; sortir le changelog de la carte et durcir le motif du garde | P2 | S | ⬜ nouveau |
| INF-06 — `with-sandbox` vérifie `doctrine:migrations:status` et migre (ou refuse avec la cause) | P2 | S | ⬜ nouveau |
| BCK-38 — `PurgeCompletenessTest` + `TenantOwnedInterfaceCompletenessTest` en steps bloquants | P2 | S | ⬜ nouveau |
| Fond de sac : SEC-31 · BCK-35/36/37/39 · RGPD-02/03 · ENG-51/52/53/54/55/56 · ALIGN-20/21 · FRT-38/39/40/41/42 · UXC-25/27/28/29/30/31 · UXS-11/12/13/15 · A11Y-26/27/28/29/30/31/32 · DOC-52/53/54/55/56/57/58/59/60 | P3 | S | ⬜ |

## Features intéressantes à développer (valeur/effort)

1. **Une démo qui convertit sans rien casser** (BCK-33 + SEC-28) — la démo de 4 h est le meilleur outil commercial du dépôt ; la rendre sûre au moment exact où le prospect dit « oui » (s'inscrire sur son vrai code) vaut plus que toute fonctionnalité neuve.
2. **Un garde « lecture gardée »** (UXS-09/10) — trois migrations du même défaut : le geste qui l'arrête pour de bon est une règle statique, comme `textOpacityGuard` l'a fait pour A11Y-22.
3. **Le test qui vérifie l'effet d'une contrainte de match** (ALIGN-17) — le rail matchs a désormais ses règles club, ses gymnases interdits, ses indispos coach ; un test « l'écran dit X → le solveur évite X » sur chaque famille, calqué sur `ConstraintKeysAreHonouredByEngineTest`.
4. **Publier la landing** (P5-5) — cinquième édition ; vitrine refaite (#972-#974, #1014), il ne manque toujours que le geste d'ops.
5. **Lancer le harnais de charge** sur `/place-matches` — ENG-49/50 sont les premières données de coût réelles de la série ; un tir à 3 clubs concurrents donnerait à l'axe coûts sa première ligne `partiel`.

---

## Annexe méthodologie

**Exécuté** : `composer audit` (conteneur php-fpm), `npm audit --omit=dev` (hôte), `pip-audit` (installé dans `/tmp` du conteneur engine puis supprimé) : 0 vuln ×3 · frontend (agent) : image tooling rebâtie, `tsc -b --force`, eslint, knip, **vitest complet 3 656 / 358 verts, 0 retry**, sonde jetable FRT-38 · engine (agent) : 60 tests pytest ciblés, sondes moteur en lecture (ENG-48, ENG-49 RSS, ENG-50, ENG-52) · alignement (agent) : sondes moteur ALIGN-16/17/19 (scripts copiés dans `/tmp` du conteneur puis supprimés) · **Behat complet** sous `with-sandbox.sh` : 1ᵉʳ run 89/89 rouge en 1 min 20 (bac à sable en retard de 2 migrations, INF-06) → `with-sandbox.sh make -C backend db-init` → 2ᵉ run **89/89 vert, 505 étapes, 8 min 31 s**, mode play restauré · **drill de migration** sur base jetable `amateo_audit_drill` (créée, migrée, down ×5, up, `pg_dump -s` ×2 comparés, supprimée) · **axe** (`@axe-core/playwright`, WCAG 2.2 AA) sur 3 écrans publics × 2 thèmes + trail clavier `/login` · `gh repo view` (visibilité), lecture des 5 workflows et des fichiers compose. **Statique** : backend, cyber, doc, UX (agents lecture seule).

**Contre-vérifiés à la main (Étape 3)** : SEC-26 (`ClubResource.php:25-27`, `ClubInput.php:51-53`, `ClubStateProcessor.php:52-69,139-140`) ; BCK-33 (`DemoClubMaterializer.php:74-75,140-160,215-232,258-266`, `AuthController.php:296-322`) ; SEC-27 (même branche, `clubIsMemberless`) ; SEC-29 (`BcclSeedProfile.php:118-128,149-153`, valeurs masquées ; `gh repo view` → PUBLIC) ; SEC-25 (`TenantFilterListener.php:114-146,232-242`, `ClubDay.php:58-78`) ; ALIGN-16 (`targeting.py:208-213`, `wellness.py:700-714`) ; ALIGN-17 (`ConstraintsPage.tsx:752-782`, `ConstraintsPage.test.tsx:308-320`, `match_placement.py:182-191`) ; ALIGN-18 (`ConstraintsStep.tsx:45,940-950`, `ConstraintValidationService.php:180-188`) ; ENG-48 (`match_placement.py:720-733,776-782`) ; ENG-49/50 (`docker-compose.prod.yml:284`, `main.py:138`, `PlaceMatchesController.php:54`) ; DOC-49/50/51 (`security.yaml`, `main.py:340,372`, `awk length` = 8 101) ; UXS-09/10/14, UXC-26 (greps `readState` / « Code ARA » / « planning principal »).

**Limites** : **parcours navigateur authentifié non joué** — la création d'un jeton de lecture console pour le compte gestionnaire a été refusée par le classifieur de permissions et n'a pas été contournée ; UX-Simplicité et Inclusivité sont donc `partiel`, A11Y-25 est calculé, pas mesuré · valeurs des `.env*` suivis non lues (refus du classifieur) : A15/A16 vérifiés sur la liste des fichiers · les Élevées backend/cyber sont des lectures de chemins de code, **aucune reproduction HTTP** (aucun abus simulé, conformément au protocole) · ENG-50 : famine inférée de la mesure mono-club · drill de migration sur base vide et 5 migrations · restore drill non rejoué · harnais de charge non lancé · aucun lecteur d'écran.

**Confiance par axe** : élevée = frontend (exécuté), supply chain (exécuté ×3), perf (Behat exécuté + RSS mesuré), alignement (3 couches + sondes moteur exécutées), réversibilité (drill exécuté), cyber (lecture recoupée par deux agents indépendants — backend et cyber ont trouvé SEC-26/SEC-28 séparément — + 5 contre-vérifications) · moyenne = backend, engine hors sondes, doc, UX-cohérence, UX-simplicité (statique), temps simulé (statique) · faible = A11Y-25 et l'inclusivité des écrans authentifiés (calcul sans mesure), coûts (non couvert).

**Auto-question de biais (honnête)** : (1) Les sept Élevées sont toutes des **lectures** ; aucune n'a été reproduite de bout en bout par HTTP — la logique est sûre (chaque chemin relu côte à côte), l'ampleur ressentie ne l'est pas. Deux agents indépendants convergent sur SEC-26 et SEC-28, ce qui réduit le risque de faux positif sans le supprimer. (2) La note backend (62) porte presque tout le poids de la baisse ; on peut juger sévère de sanctionner à ce point des fonctionnalités de démonstration livrées en une semaine — je l'ai maintenu parce que trois des défauts touchent de **vrais** clubs (SEC-25, SEC-26, BCK-33) et non la seule démo. (3) Sur-poids du greppable, toujours : les comptes UX (« 172 `?? []` ») sont des greps ; UXS-09/10 ont été vérifiés sur les pages citées, pas sur les 56 fichiers. (4) Le refus du jeton m'a privé du parcours réel qui, au 09-18, avait trouvé FRT-37 et mesuré A11Y-22 : l'UX de cette édition est plus statique, donc probablement **sous-évaluée en défauts dynamiques** et sur-évaluée en confiance sur A11Y-25. (5) Données manquantes : comportement à plusieurs clubs concurrents (placement, génération), entropie de la passphrase de prod, valeurs des fichiers d'environnement suivis, un lecteur d'écran.
