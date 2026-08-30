---
name: documentation-update
description: Refresh the living docs before opening a PR — general + per-subproject READMEs, CLAUDE.md index, per-zone docs/, ADRs, and specs/ reconciliation (roadmap = open only, etat-des-lieux = delivered). Enforces WHERE a doc lives (one zone → that zone ; the seam between zones → root ; specs/ = the product's time axis), verifies claims against code (no drift) on the PR's docs PLUS a bounded rotation of the oldest stamps, bans volatile counts, enforces evolution→courantes graduation. Flags misplaced and duplicated docs — never moves them itself. No filler.
---

## Documentation Update

**Run before opening EVERY PR** (CLAUDE.md §7 step 6 — both lanes). The docs are alive: a PR that fixes, adds or removes something has documentation to update **somewhere**. Finding nothing is a conclusion you reach by looking, not an assumption you start from — and if you truly find nothing, say which files you checked and why they are unaffected.

Goal: a developer landing in `backend/` vs `engine/` vs `frontend/` grasps the scope, how work is done *there* (each zone works differently), and where the core-business docs live — and the evolution stays legible.

**The two-file rule (refonte 2026-07-31) — never blur it:**
- [`specs/evolution/roadmap.md`](../../../specs/evolution/roadmap.md) holds **ONLY what is still open** (bugs, evolutions, technical debt, parking, vision). Nothing delivered stays there.
- [`specs/courantes/etat-des-lieux.md`](../../../specs/courantes/etat-des-lieux.md) holds **what is delivered**: the capability map, the **closed decisions** (deliberate abandons — they are what stops a settled question from being re-opened every three months), and the dated delivery traces.

A delivered item **moves**: it is deleted from the roadmap and gains a trace line in the état des lieux, with its behaviour documented in **the doc that owns that behaviour** — `specs/courantes/` quand elle traverse les zones, **`<zone>/docs/` quand elle n'en concerne qu'une** (règle de placement ci-dessous : une livraison backend gradue vers `backend/docs/backend-inventory.md`, une livraison d'écran vers `frontend/docs/frontend-spec.md`). Never both. Never neither.

**Prime directive: a doc that lies is worse than no doc.** This project is agent-driven; a false fact in CLAUDE.md/AGENTS.md/project-map is injected into every future plan. Accuracy beats completeness.

### Where each doc lives (one canonical home — never copy across)

| File | Audience | Holds |
|------|----------|-------|
| `README.md` (root) | human | project purpose, problem solved, architecture sketch, quickstart, layout, links to subproject READMEs |
| `<zone>/README.md` | human | that zone's scope + what/why, **command recap**, **project recap** (structure/entry points), pointers to its structuring/business docs |
| `<zone>/AGENTS.md` | agent | **pointers + zone-only gotchas.** NOT a re-description of commands, flows, tooling or counts already in CLAUDE.md / project-map / zone README — duplication here is the #1 historical cause of doc rot (audit 2026-07-03, DOC-02). If a fact exists elsewhere, link it. |
| `CLAUDE.md` (root) | agent | short operational index (< ~200 lines), facts not obvious from filenames |
| `<zone>/docs/` | both | deep-dives (business rules, how-to guides, structuring mechanisms). **Single dir per zone** — `doc/` merged into `docs/` 2026-07-11; never recreate a `doc/` |
| `docs/` (root) | both | cross-cutting: project-map, glossary, testing, architecture/ADRs |
| `specs/evolution/roadmap.md` | both | **the OPEN only**: prioritized backlog (P1-P4), deliberate-keep debt, parking, unpriced vision. A delivered line does not live here |
| `specs/courantes/etat-des-lieux.md` | both | **the DELIVERED**: capability map (pointers, not behaviour), **closed decisions**, dated delivery traces |
| `specs/` | both | living product specs (see reconciliation below) |
| `business/` | human | **LOCAL-ONLY, gitignoré** (stratégie, pricing, pilote). ⚠ Ne JAMAIS l'exiger dans une PR, ne jamais l'attendre en CI, ne jamais y renvoyer depuis un fichier versionné comme s'il était lisible par tous — un lecteur du dépôt public ne l'a pas. Une décision business qui doit être OPPOSABLE au code (un prix qui devient un plafond, une promesse qui devient une contrainte) est recopiée **en substance** dans `specs/courantes/`, jamais par lien |

Rule: **README points, it does not recopy.** If a fact is in `docs/` or `AGENTS.md`, the README links to it. Every added line must carry a fact a future reader would otherwise get wrong.

### Où un doc VIT — la règle de placement (à appliquer AVANT d'écrire une ligne)

Trois questions, dans l'ordre. La première qui répond « oui » décide.

1. **Ce doc décrit-il UNE SEULE zone** (son métier, sa stack, ses pièges, son inventaire) ?
   → il vit dans **`<zone>/docs/`**. Un dev qui débarque dans `frontend/` doit trouver la doc du
   frontend **dans `frontend/`**, pas ailleurs.
2. **Décrit-il la COUTURE entre zones** (contrat backend⇄engine, ADR structurant, glossaire,
   sécurité transverse, ops, stratégie de test) ? → **`docs/` racine**. La ranger dans une zone
   obligerait à la dupliquer dans l'autre — c'est le motif « une vérité, deux endroits ».
3. **Est-ce du PRODUIT sur l'axe TEMPS** (le besoin d'origine, ce que l'app fait aujourd'hui, ce
   qu'elle fera) ? → **`specs/`**. C'est la seule chose que `docs/` ne sait pas dire, et la raison
   d'être des deux dossiers : `specs/` n'est pas « la doc bis », c'est la lecture
   `initiales → courantes → evolution`.

⚠ **`specs/courantes/` n'est pas le dossier du « courant »** — c'est le dossier du **produit**
courant. Un inventaire technique de zone, une stratégie de test de zone, une liste de composants
n'y ont rien à faire : ils décrivent UNE zone, ils appartiennent à cette zone. Ce glissement est le
défaut historique du dépôt (mesuré le 2026-08-18 : ~4 000 lignes de doc de zone logées en
`specs/courantes/`, pendant que `frontend/docs/` ne contenait qu'un fichier).

**Ce que tu fais quand tu constates un fichier mal placé — et ce que tu ne fais PAS.**
Tu le **SIGNALES** dans le résumé de changement (fichier, où il devrait vivre, pourquoi) et, si
c'est structurant, tu ouvres une ligne P4 dans `roadmap.md`. **Tu ne le déplaces pas dans cette
PR.** Ce skill tourne avant CHAQUE PR : y glisser des déplacements gonflerait des diffs sans
rapport avec le sujet, casserait des références croisées en série, et rendrait la revue
impossible. **Ranger est une migration one-shot, relue pour elle-même.** L'exception : le fichier
que TA PR crée ou récrit largement — celui-là, tu le places correctement du premier coup, c'est
gratuit.

### Mutualiser ? le TEST, jamais le réflexe

Deux fichiers qui parlent du même sujet ne sont pas forcément un doublon. Avant de proposer une
fusion, applique le test maison (`specs/evolution/duplications-de-verite.md`) :

> **La divergence serait-elle SILENCIEUSE ?** Si les deux fichiers dérivent, est-ce que quelqu'un
> s'en aperçoit — ou est-ce qu'on lit tranquillement le faux pendant six mois ?

- **Deux AUDIENCES distinctes, chacune servie** → ce n'est pas un doublon. Exemple vivant :
  `backend/docs/RLS.md` (mode d'emploi pour l'exploitant : env, rôles, dépannage) et
  `docs/security/rls.md` (architecture effective) — un exploitant qui débugge à 2 h du matin et
  un architecte ne lisent pas le même document. Fusionner appauvrirait les deux.
- **Mais un « garder les deux en phase » écrit noir sur blanc est un AVEU** : c'est exactement le
  motif « une vérité, deux endroits ». Ces fichiers-là sont des **candidats** — signalés, discutés,
  jamais fusionnés d'autorité au détour d'une PR de feature.

### Anti-drift rules (hard)

1. **Verify before you write.** Any factual claim you add or keep in a touched doc must be checked against the code *now* (read the file, grep the config). Never propagate a claim just because the doc already said it.
2. **No volatile counts.** Never write "N controllers", "N entities", "N fixtures", "N tests" in any doc — these rot in days. Describe *where* things live (`src/Controller/`), not how many there are. If you find a count while editing, delete or replace it with a location.
3. **Security-critical facts require a code citation.** Tenant listener priority, RLS status, auth/firewall behaviour, lock mechanics: when a touched doc states one, quote the source (`file:line` or config key) in the doc itself. If doc and code disagree, the code wins and the doc is fixed in the same pass.
4. **Claimed ≠ implemented.** Docs must state what the code *does*, not what is planned. If a mechanism is prepared but inactive (e.g. an RLS template never executed), the doc must say "prepared, NOT active" explicitly. Aspirational statements go to `specs/evolution/`, nowhere else.
5. **Cross-file consistency.** When you change a fact, grep for its other occurrences (`grep -rn` on the key term across `CLAUDE.md`, `docs/`, `*/AGENTS.md`, `specs/courantes/`) and fix or remove them all — half-updated facts created the priority-7/8 contradiction (DOC-01).
6. **Stamp what you verified.** Files carrying `Last verified @ <sha|date>`: bump the stamp **only for files whose claims you actually re-checked this pass** — a stamp is a proof of verification, not a courtesy.
   ⚑ **Un stamp REMPLACE, il ne s'empile pas** (audit DOC-33, 2026-08-19). Écris la vérification
   COURANTE — la date, et ce que tu as confronté — puis **efface celle d'avant** : pas de chaîne
   de « ; précédemment : … ». La pile avait atteint **49 entrées et 24 Ko sur UNE ligne** dans
   `backend/docs/backend-inventory.md` : un agent traversait 24 Ko d'historique avant le premier
   fait utile, et l'info la plus précieuse (« vérifié quand, contre quoi ») s'y noyait.
   L'historique des passes vit dans **git** (`git log -p --follow <fichier>`), et une décision
   prise en chemin vit dans `etat-des-lieux.md` — jamais dans un stamp.
   ⚠ **Toucher la ligne de stamp EST une édition du fichier** : `DocStampFreshnessTest` compare
   le stamp à la dernière édition du fichier en git, sans distinguer l'en-tête du contenu. Donc
   si tu aplatis ou réécris un stamp, tu dois **re-vérifier les affirmations du fichier dans la
   même passe et redater** — sinon le garde rougit, et il a raison. (Constaté le 2026-08-19 :
   la passe DOC-33 a fait rougir 3 fichiers exactement comme ça.) Et il ne rougit qu'APRÈS
   commit, puisqu'il lit une date git : le lancer avant ne prouve rien.

### Fraîcheur — le balayage TOUCHÉ, plus une ROTATION bornée

Deux passes, et la seconde est ce qui empêche le corpus de pourrir en silence.

**(a) Les docs que ta PR touche** — obligatoire, décrit ci-dessous.

**(b) Une rotation de 2 ou 3 fichiers**, choisis par leur stamp le plus ANCIEN, quel que soit le
sujet de ta PR. Tu vérifies leurs 3-6 affirmations les plus fortes contre le code, tu corriges ce
qui est faux, tu redates. Si tout est juste : tu redates quand même **en nommant ce que tu as
re-vérifié** — un stamp est une preuve de vérification, pas une politesse.

```bash
# Les stamps les plus anciens du dépôt, tous dossiers confondus.
grep -rl "Last verified @" --include="*.md" . | grep -v node_modules | while read -r f; do
  printf '%s\t%s\n' "$(grep -m1 -oE 'Last verified @ [0-9-]+' "$f" | grep -oE '[0-9-]+$')" "$f"
done | sort | head -5
```

⚑ **Pourquoi une rotation et pas tout le corpus** : ~70 fichiers vérifiés à chaque PR, c'est un
skill si lent qu'on finit par le sauter — et un skill sauté ne protège rien. Deux fichiers par PR,
c'est indolore, et le corpus entier est couvert en quelques semaines. Le coût est **réparti**, pas
supprimé. ⚠ Une rotation qui trouve du faux **le dit dans le résumé** : c'est le signal que la
zone concernée dérive, et il vaut souvent une ligne de roadmap.

### Drift sweep (mandatory, cheap)

Before writing, for every doc file you are about to touch **and** the zone `AGENTS.md` of the affected zone(s):
- extract its 3–6 strongest factual claims (priorities, statuses "stub/implemented", file paths, commands, versions);
- verify each against the code;
- fix anything wrong **even if unrelated to the current feature** — you are the last line of defence against rot.
If the sweep finds more than trivial drift, say so in the change summary (it is a signal the docs were not maintained).

### Per-subproject README — required sections (adapt to the zone's real style)

Each `<zone>/README.md` must let a newcomer answer "what is this, how do I work here, where's the hard stuff":
1. **Scope & role** — 1–2 paragraphs: what this zone owns, its boundaries (what it must never do — e.g. engine never calls backend, frontend never calls engine directly).
2. **Command recap** — the commands that matter *for this zone*, and the note that backend/engine run **inside Docker** while frontend dev runs **on the host**.
3. **Project recap** — entry point(s), main structure, key mechanisms in one glance.
4. **Structuring / business docs** — a pointer list to the docs that explain the core: e.g. backend → `scripts/generate-schedule.sh` (how to drive a generation), tenant isolation (`docs/TENANT.md`, `docs/RLS.md`), the constraints model, `docs/commands.md`, `docs/ffbb-api.md`; engine → `docs/business.md`, `docs/nominal-flow.md`, `docs/solver-errors.md`, `docs/constraint-vocabulary.md`; frontend → feature workflow (planning work-loop, wizard), component/UX conventions. Transverse vocabulary → `docs/glossary.md` (root).

Respect the distinct working style per zone — don't flatten them into one template. The backend README reads like an API/persistence service, the engine like a solver, the frontend like a UI app.

### specs/ reconciliation (initiales → courantes → evolution)

Three buckets, distinct meaning — keep them true:
- **`initiales/`** — origin specs (v2/v3). **Frozen**: never edit. They are the starting point; the evolution is read as the delta `initiales` → `courantes`.
- **`courantes/`** — what the app does **today**. Must reflect reality: if a courante spec no longer matches the code, **update it**; if the feature was removed, **delete it**. When an `evolution` item ships, its behaviour lands here.
- **`evolution/`** — what the app will do **later** (future/vision). When an item is delivered, **remove it from evolution** (it has graduated into `courantes`).

**Purge des items livrés (mandatory each run) — the MOVE, in four steps.** For every item delivered since the last pass:
1. **Delete the backlog line** (P*x-y*, DOC-n, SEC-n…) from `roadmap.md`. Do **not** flip it to ✅ and leave it there — the roadmap holds no ✅. The id is never reused: a numbering hole means delivered, by design. **Entretenir le compteur du titre** (« Roadmap (N) — … », exception fondateur 2026-08-04 à la règle anti-décompte) : chaque ligne supprimée décrémente N, chaque ligne ajoutée l'incrémente — en cas de doute, `grep -cE '^\| [A-Z]+[0-9]*-[0-9]+[a-z]? \|' specs/evolution/roadmap.md` fait foi (le suffixe littéral compte : `P5-4b` est un sous-item, pas une coquille). ⚑ **Le compteur ET l'unicité des identifiants sont tenus par `RoadmapIdentityTest`** depuis le 2026-08-22 — inutile de les recompter à la main, mais inutile aussi de les laisser dériver : un identifiant est une CLÉ, cité depuis le code et les PR, et **ne se réattribue jamais**. Un trou de numérotation signifie « livré » ; le prochain numéro est le successeur du plus grand de sa famille, jamais un trou recyclé.
2. **Add one trace line** to `etat-des-lieux.md` §3 (date · id · subject · pointer to the `courantes/` file that now holds the behaviour). A delivered item MUST leave a dated trace — deletion without trace is the failure mode this step blocks.
3. **Update the capability map** (`etat-des-lieux.md` §1) if the delivery changed what the app can do. It is a **map**: pointers and one-liners, never behavioural detail.
4. **Detail file** — if a `specs/evolution/*.md` detail file is now fully delivered, delete it (history lives in git) and drop its entry from the roadmap header's active-files list.

**Closed decisions (`etat-des-lieux.md` §2).** When work settles a question **against** an obvious-looking option ("abandoned", "assumed as-is", "deliberately not done"), record it there with its *why*. This is not bookkeeping: an undocumented abandon gets re-proposed every few months. A closed decision is worth as much as a delivery.

**Dette technique (same pipeline):** actionable debt lives as **P4-x backlog lines in the roadmap** (proof `file:line` required); deliberate "won't fix" keeps live in roadmap **§Dette — keeps délibérés** with their rationale. When a debt is resolved: same MOVE ritual. New debt found during any pass → new P4 line, never a separate file.

**Graduation check (mandatory each run, opposable):** for **every item delivered this run**, either **name the `courantes/` file that receives the behaviour** (create or extend it in the same pass) or **state in the change summary why no graduation applies**. Silence is not an option — "updated the roadmap line" alone is the failure mode this check blocks (2026-07-06: school-holidays + public-holidays imports fully specced in roadmap notes, nothing in courantes, snapshot stale across 2 PRs). Then also sweep `specs/evolution/` for older delivered items (merged PR, code present) and graduate them. The audit found shipped features still sitting in evolution/ months later (DOC-04).

**A roadmap line is a line, not a spec.** It holds the problem, its proof (`file:line`), the decision if one was taken, and the traps a future implementer would step into. If you are writing the *solution's* behavioural detail (filters, natural keys, endpoints, edge cases) into a roadmap line, stop: that content belongs in a `specs/evolution/` detail file while open, and in `courantes/` once delivered.

**New open items.** A bug or a need surfaced by the founder or by a review gets a roadmap line **in the right bucket**, with a **verified** proof (`file:line`, read the code — never from memory), an impact and an effort. If the need is not yet framed, say so in the line rather than inventing a solution.

**API-change trigger:** if the work changed any API Platform resource, custom controller route, or DTO exposed over HTTP, regenerate `specs/courantes/openapi-snapshot.json` from the running backend and update `openapi-snapshot.meta.md` (SHA + date). A stale snapshot silently poisons the frontend type-gen pipeline. ⚠ A custom Symfony `#[Route]` is **invisible to the export** unless declared in a `CustomPathContributor` (`backend/src/OpenApi/PathContributor/`, one per domain, composed by `App\OpenApi\CustomRoutesOpenApiFactory` since P4-138, 2026-08-30 — adding to the factory file itself does nothing anymore) — a new custom route means an entry in its domain's contributor **plus** the regen; regenerating alone silently misses it.

No `archive/` bucket — nothing is lost (git + `initiales` hold the history). When reconciling, flag files that are neither current nor future (e.g. one-off process/handoff notes) and propose removing them; do not silently delete large specs — surface the list first.

### Steps
1. **Decide what genuinely changed** among: business behaviour, architecture, conventions, public APIs, subproject scope. **Look before concluding** — list the docs you checked. "Nothing changed" is a finding to justify, not a default.
1bis. **Pour chaque doc que tu crées ou récris largement : applique la règle de placement** (une
   zone → sa zone ; la couture → racine ; l'axe temps du produit → `specs/`). Pour les autres,
   note ce qui te saute aux yeux comme mal placé, **sans le déplacer** — ça part au résumé, et en
   ligne P4 si c'est structurant.
2. **Drift sweep** on every doc you will touch + affected zone `AGENTS.md` (see above).
3. **READMEs** — refresh the root README and any affected `<zone>/README.md` per the required sections above.
4. **CLAUDE.md** — update only non-obvious facts; keep it a short index (< ~200 lines).
5. **Zone docs** (`<zone>/docs`, `docs/project-map.md`, `docs/testing/`, `docs/technique/`) — update the affected deep-dives; add a how-to/business doc if a new structuring mechanism appeared.
6. **ADR** — if a structural decision was made, add one and reference it in `docs/architecture/adr-index.md`.
7. **specs/** — reconcile per the rules above: update stale `courantes`, **run the graduation check**, **run the MOVE** (roadmap line deleted + trace in `etat-des-lieux.md` §3 + map §1 refreshed), record any **closed decision** in §2, add roadmap lines for newly found open items, apply the API-change trigger, delete removed features, surface dead process notes for confirmation.
8. **Change summary** — list files touched, facts corrected by the drift sweep, and **one line per item delivered this run: → receiving courantes file, or the explicit reason no graduation applies**. Plus, systématiquement :
   - **la rotation** : quels fichiers vérifiés, ce qui était faux (ou « tout juste ») ;
   - **le mal placé** : fichier → où il devrait vivre → pourquoi (signalé, jamais déplacé ici) ;
   - **les candidats à mutualisation** : uniquement ceux dont la divergence serait SILENCIEUSE.

### Doc-only PRs

A PR that touches no code gets no `/code-review` (it would have nothing to review). It gets a **completeness check** instead, written into the PR description: what moved, where it landed, and the proof that nothing was lost. `/code-review` stays mandatory on every PR that touches code.

### Rules
- **No filler.** A future agent must get something wrong without the line.
- **One canonical home**, README points to detail, detail is not copied back.
- **Never edit `specs/initiales/`.** Reconcile `courantes`/`evolution` only.
- **Ce skill ne DÉPLACE rien** — il place correctement ce que la PR crée, et signale le reste.
  Ranger le dispersé est une migration one-shot, relue pour elle-même.
- **`business/` est local-only** : jamais exigé dans une PR, jamais vérifié en CI, jamais cité
  depuis un fichier versionné comme une source lisible.
- **Deletions are surfaced, not silent** — list what you propose to remove and why before removing large specs/docs.
