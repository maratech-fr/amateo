import type { ReactNode } from "react";

import { cn } from "@/shared/lib/utils";

/**
 * **Le cadre des pages « fiche »** — Club, Profil, Nouveautés (P4-107, 3ᵉ tranche).
 *
 * Une fiche est une pile de panneaux et de formulaires, pas un écran dense : elle ne prend pas
 * toute la largeur, mais elle ne reste pas non plus à une largeur de mobile élargi sous un
 * shell devenu pleine largeur (1ʳᵉ tranche, PR #613) — c'est ce décalage qui donnait « plus de
 * marge que d'utile » sur 1920×1080.
 *
 * **832 px** (`--container-fiche: 52rem`, `index.css`) : la valeur testée à la main par le
 * fondateur, entre `max-w-3xl` (768) et `max-w-4xl` (896). Le token existe pour qu'il n'y ait
 * qu'UN endroit à changer — un `max-w-[52rem]` recopié dans trois pages redeviendrait trois
 * valeurs à la première divergence.
 *
 * ⚠ **`[&_p]:max-w-prose` n'est pas décoratif.** Élargir le cadre sans borner les paragraphes
 * échangerait un défaut contre un autre : la seule mesure chiffrée du corpus de design
 * (`ui-ux-pro-max`) est 65-75 caractères par ligne, et elle vaut à l'intérieur d'un conteneur
 * plus large. Les textes d'aide des accordéons restent donc lisibles pendant que les
 * formulaires et les tableaux, eux, profitent des 832 px. Les modales ne sont pas concernées :
 * elles sont rendues dans un portail, hors de cet arbre DOM.
 *
 * ⚑ La borne ne vit PAS dans `AccordionSection` : il a quatre autres consommateurs (écrans du
 * wizard, pleine largeur par conception) qu'elle aurait reflowés en silence.
 *
 * `prose={false}` : une page dont les paragraphes SONT le contenu, posés dans des cartes
 * (Nouveautés), renonce à la borne — la règle n'est alors PAS émise. Jamais un `max-w-none` en
 * surcharge : Tailwind 4 émet `[&_p]:max-w-none` AVANT `[&_p]:max-w-prose`, et à spécificité
 * égale la borne gagnerait.
 *
 * Ce que ce cadre n'est pas : la réponse aux écrans DENSES (grille de planning, wizard
 * contraintes, module matchs). Leur inventaire reste ouvert — `specs/evolution/roadmap.md`, P4-107.
 */
export function FichePage({ className, children, prose = true }: { className?: string; children: ReactNode; prose?: boolean }) {
  return <div className={cn("mx-auto max-w-fiche", prose && "[&_p]:max-w-prose", className)}>{children}</div>;
}
