# Contenu riche des notes de nouveautés — liste blanche serveur, rendu sans HTML

> Une note du journal est écrite par la superadmin (console) et **rendue chez TOUS les clubs**
> (modale « Quoi de neuf ? », page `/nouveautes`) : c'est le seul contenu mis en forme que l'application
> stocke puis affiche à des tiers. Le risque est la XSS stockée ; la défense est double : un serveur qui
> n'accepte qu'une forme fermée, un rendu qui ne sait pas produire de HTML.

## Pourquoi du JSON, jamais du HTML

Le corps riche est un **document JSON de type ProseMirror** (`release_note.body_rich`, JSONB), jamais une
chaîne HTML ni du Markdown. Il n'y a donc rien à « assainir » : le serveur **valide une forme** et
**reconstruit** la valeur stockée des seules clés connues. `symfony/html-sanitizer` n'est pas utilisé.
`release_note.body` reste le miroir texte brut, **dérivé** par le serveur (`toPlainText`) : un ancien bundle
frontend lit encore `body`. Une note sans `body_rich` (toutes les notes antérieures) est une note texte,
rendue comme avant ; aucune réécriture des notes existantes.

## La liste blanche (`App\Service\ReleaseNoteRichDoc`)

Forme **fermée** : toute autre clé, tout autre type ou toute autre marque → 400
« Le contenu mis en forme est invalide. » (le détail reste dans le log).

| Élément | Admis |
|---|---|
| bloc | `paragraph`, `heading` (`attrs.level` = 3 exactement), `bulletList`, `orderedList`, `listItem` |
| `orderedList` | `attrs.start` entier dans [1, 9999] (défaut 1) ; une clé `type` n'est acceptée qu'à `null` et n'est jamais stockée |
| `listItem` | un paragraphe d'abord, puis paragraphes ou sous-listes — **jamais un titre** |
| en ligne | `text` (non vide), `hardBreak` (ses marques sont acceptées puis retirées) |
| marques | `bold`, `italic`, `noteColor` (`attrs.name` ∈ rouge, orange, vert, bleu, gris) ; pas de doublon |

Pas de liens, d'images, de tableaux, de souligné, de code. `content`/`marks` doivent être des listes
(`array_is_list`) ; `type`, `text`, `name` des chaînes.

## Bornes

| Borne | Valeur | Message 400 |
|---|---|---|
| corps HTTP brut, **avant** `json_decode` | 64 Kio | « Corps de requête trop volumineux. » |
| nœuds | 500 | « Le contenu contient trop d'éléments (500 au plus). » |
| profondeur de listes | 3 niveaux | « Les listes ne peuvent pas dépasser 3 niveaux. » |
| texte cumulé (points de code, `mb_strlen`) | 10 000, même borne pour un `body` brut | « Le contenu dépasse 10 000 caractères. » |

Un emoji composé compte plusieurs points de code. Les constantes sont dans `ReleaseNoteRichDoc`.

## Caractères refusés (`App\Service\TextSanity`)

Titre, corps brut et chaque nœud texte refusent : C0 sauf la tabulation (donc U+0000, que JSONB refuse —
évite un 500 —, et U+000A/U+000D), U+007F–U+009F, commandes bidi U+202A–U+202E et U+2066–U+2069, et
toute chaîne qui n'est pas de l'UTF-8 valide. Dans le corps riche, les sauts de ligne passent par
`hardBreak` et les paragraphes ; dans un corps **brut** (ancien bundle), `\n` et `\r\n` sont tolérés.

**Jamais d'interdiction de la catégorie Unicode `Cf` en bloc** : les emoji composés reposent sur des
caractères de format hors des plages ci-dessus (U+200D, U+FE0E/U+FE0F, U+20E3, teints U+1F3FB–U+1F3FF,
indicateurs régionaux).

## Rendu : éléments React, jamais une chaîne HTML

`shared/components/ui/rich-text.tsx` (`RichText`) construit `<p>`, `<h3>`/`<h4>`, `<ul>`/`<ol>`/`<li>`,
`<strong>`, `<em>`, `<span class>`, `<br>` en éléments React ; il n'importe pas `@tiptap`. Un nœud ou une
marque inconnus sont ignorés sans lever. Une balise saisie (`<img src=x onerror=…>`) s'affiche donc
**littéralement**. Les couleurs sont des classes issues d'un `Map` par peau (`RICH_COLOR_CLASS`) : 5
couleurs, jamais un style en ligne.

- App : rouge `text-destructive`, orange `text-warning`, vert `text-success`, bleu `text-info`
  (jeton `--info`, ajouté pour l'occasion), gris `text-muted-foreground`. Les contrastes AA sont gardés
  par `tests/e2e/a11y-contrast.spec.ts` (`text-info` y figure, deux thèmes).
- Console : jetons `text-console-*` (bleu = `text-console-info`). Aucun scan automatisé de contraste n'y
  existe.
- L'**aperçu** de la console est rendu en peau console, pas en peau membre.

## Gardes

- `frontend/src/test/noInnerHtmlGuard.test.ts` (statique, `src/**` hors tests) rougit sur
  `dangerouslySetInnerHTML`, `.innerHTML =`, `insertAdjacentHTML(`, `getHTML(`, `generateHTML(`,
  `setContent("…")`, sur `srcDoc` **sauf** avec `sandbox=""` sur la même ligne (aperçu d'e-mail), et sur
  tout import `@tiptap/*` hors `features/admin/releaseNoteEditor/`.
- `backend/tests/Unit/ReleaseNoteRichDocTest.php`, `TextSanityTest.php` (abus, Unicode) et
  `backend/tests/Integration/Api/ReleaseNotesTest.php` (API : bornes, `bodyRich` null / `body` ignoré).
- `backend/tests/CrossStack/ReleaseNoteRichDocEditorFixturesTest.php` : l'éditeur ne produit que des
  documents acceptés par le serveur — les fixtures `features/admin/releaseNoteEditor/fixtures/*.json`
  sont rejouées des deux côtés (`editorOutput.test.ts` asserte la sortie réelle de l'éditeur).

## L'éditeur (console) se conforme à la liste blanche

TipTap (v3, extensions nommées — pas `StarterKit`) est confiné à `features/admin/releaseNoteEditor/`.
Écarts réduits à la liste blanche : tout titre collé `h1`–`h6` devient le titre unique (niveau 3) ; liens
collés → texte brut ; souligné, barré, tailles et couleurs externes perdus (une couleur externe ne devient
jamais une `noteColor`) ; titre interdit dans une puce ; Tab refusé au 3ᵉ niveau de liste. Liste
numérotée : `type` toujours `null` en JSON (une liste collée en lettres ou chiffres romains reste
numérotée 1, 2, 3) et `start` borné à [1, 9999] (`noteOrderedList.ts`). Le seul écart admis entre
éditeur et serveur est la profondeur : un collage de 4 niveaux est accepté par l'éditeur et refusé par le
serveur (fixture négative).

## Limites connues

- **Collage depuis Word pour Windows** : sa liste n'est pas une liste HTML (`mso-list`) ; elle arrive en
  paragraphes « · texte ». La superadmin sélectionne les lignes et clique « Liste à puces ». Word en
  ligne, Word Mac et Google Docs envoient de vraies listes.
- **Collage de texte brut commençant par « a. … »** : suspecté de passer par un autre chemin TipTap
  (règle de saisie / liste collée) et de produire un `type` « a » sur la liste numérotée, refusé 400 à
  l'enregistrement. **Non testé** ; à vérifier avant d'y compter.
- Pas de route de modification d'une note (roadmap).
