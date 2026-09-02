"""Level-2 objective — poids, barèmes et alias FIXES (paquet ENG-39).

Base du DAG du paquet ``objective`` : ce module ne dépend d'aucun autre sous-module.
La table de poids T24 est intentionnellement figée — toute modification d'un poids doit
s'accompagner d'un nouveau ``SCORE_FORMULA_VERSION`` (cf. le docstring de ``__init__``).
"""

from __future__ import annotations

from types import MappingProxyType

from ..helpers import MISSING

# V11 (P2-42) — la table gagne `consecutive_days_violation`. Le bump est OBLIGATOIRE même
# si le nouveau terme est INERTE par défaut (la règle naît HARD, donc sans terme soft) :
# garder V10 ferait désigner DEUX tables de poids différentes par un seul identifiant,
# et cet identifiant ne sert qu'à ça. Cf. l'en-tête du module.
# V12 (Lot PASSERELLES PR-2) — l'objectif de PLACEMENT gagne la pénalité des passerelles
# PREFERRED (``add_team_link_penalty``, poids dérivé du tier via ``TEAM_LINK_TIER_WEIGHTS``).
# Le bump suit la même règle que V10/V11 : dès qu'un terme peut faire BOUGER le score rapporté,
# l'identifiant du barème doit changer. INERTE par défaut (aucun ``teamLinks`` PREFERRED ⇒
# aucune pénalité, goldens et score byte-identiques), mais présent dès qu'une passerelle l'est.
# V13 (PR-3 comblement référencé au socle) — l'objectif de PLACEMENT gagne le BONUS de
# référence socle (``add_socle_reference_bonus``, poids dérivé du tier via
# ``SOCLE_REFERENCE_TIER_WEIGHTS``) : en mode comblement, une séance comblée qui retrouve le
# jour+heure de sa version pointée du socle (quel que soit le gymnase) porte +poids dans le
# placement. Même règle de bump que V12 : dès qu'un terme peut faire BOUGER le score rapporté,
# l'identifiant du barème change. INERTE par défaut (aucun ``socleReferenceAssignments`` émis ⇒
# aucun bonus, goldens et score byte-identiques) — le backend ne l'émet QU'en comblement —,
# mais présent dès qu'une référence l'est.
SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V13"

LEVEL_2_OBJECTIVE_WEIGHTS = MappingProxyType(
    {
        "S": 10000,
        "A": 1000,
        "B": 100,
        "session_count": 20,
        # V10 — LE REMPLISSAGE PRIME SUR LE CONFORT (arbitrage fondateur 2026-08-15 :
        # « s'il y a 90 créneaux je veux 90 placés, quitte à ce que certains n'aient pas
        # ce qu'ils veulent »). Le confort ne sert qu'à départager des solutions qui placent
        # le MÊME nombre de séances. Les poids de confort sont donc recalés SOUS le seuil
        # d'une séance nue (tier D 1 + session_count 20 = 21).
        #
        # P1 — cumul MAX de conforts sur une séance = preferred 10 + preferred_day 5 +
        #      preferred_time 5 = 20 < 21 : une préférence empilée ne vaut jamais une séance
        #      nue. Une séance placée dans un gymnase/jour/heure non préférés bat toujours
        #      un trou.
        # P6 — discriminance vs nudges : preferred 10 > rest(3) + spacing(2), donc PREFERRED
        #      oriente réellement. L'égalité preferred_day(5) = rest(3) + spacing(2) est une
        #      INDIFFÉRENCE ASSUMÉE (arbitrée par l'orchestrateur) : 12/6/6 casserait P1
        #      (24 > 21), 10/6/4 casserait l'égalité jour = heure.
        "preferred": 10,
        # Soft "avoid this venue" (ENG-11): a true malus on the avoided slot —
        # a complement bonus would hand the team a flat objective advantage at
        # every other venue and bias cross-team allocation.
        #
        # P2 — |avoided_venue| 10 < 21 : supprimer une séance pour FUIR un gymnase évité
        #      relâche 10 < 21, donc fuir ne supprime jamais une séance (à −60, fuir en
        #      supprimant la séance relâchait 60 > 21 — le trou vécu côté avoided).
        "avoided_venue": -10,
        # Hiérarchie gymnase > jour préservée (ratio preferred:preferred_day = 2:1) ;
        # égalité jour = heure préservée (preferred_day == preferred_time).
        "preferred_day": 5,
        "preferred_time": 5,
        "C": 10,
        "D": 1,
        "rest": 3,
        # P4-51 — le plafond de jours d'un coach (maxDaysOverride) est PRÉFÉRÉ, pas dur
        # (arbitrage fondateur 2026-08-09) : un malus par jour travaillé AU-DELÀ du
        # plafond. 15 < 21 (valeur minimale d'une séance placée : tier D 1 + session 20),
        # donc regrouper des séances ne peut JAMAIS en supprimer une — seulement les
        # déplacer. Et 15 > rest(3) + spacing(2) : le regroupement bat les nudges. Quand
        # le solveur n'y arrive pas, le diagnostic coach_overload (ENG-24) le dit.
        "overload_day": -15,
        # Implicit spacing nudge (ALIGN-06): a small malus when a team trains on
        # two CONSECUTIVE days. Low weight (< rest) so it only breaks ties — never
        # moves or drops a real placement (each session is worth ≥ 21).
        "spacing": -2,
        # Les 4 règles implicites « bien-être » réglées PREFERRED par le club (V9). Chaque
        # littéral de violation AGRÉGÉ (constraints.py) porte un malus de −6.
        #
        # Preuve d'empilement (patron CHAINING_TIER_WEIGHTS ci-dessous) — pourquoi −6 oriente
        # sans jamais SUPPRIMER une séance. Une séance placée vaut au minimum 21 (tier D 1 +
        # session_count 20). En la retirant, on soulage au pire : 1 littéral âge (un couple
        # inversé de son gymnase-jour) + k littéraux repos (les k coach-personnes de l'équipe)
        # + k littéraux chaîne (mêmes k). Cas dominant k=1 → 3 littéraux → 3×6 = 18 < 21 :
        # supprimer la séance coûte plus que ce qu'elle rapporte en malus évités, jamais
        # rentable. Et 6 > rest(3) + spacing(2) : le malus bat les nudges, donc PREFERRED
        # oriente réellement. Le résiduel k≥2 (équipe à plusieurs coach-personnes) n'est pas
        # couvert par cette arithmétique seule ; il est gardé par le test NR « PREFERRED ne
        # supprime jamais une séance » (fixture pire-cas tier D à 2 coach-personnes).
        "coach_rest_violation": -6,
        "salarie_violation": -6,
        "chain_violation": -6,
        "age_violation": -6,
        # P2-42 — « cette ÉQUIPE ne s'entraîne pas N jours d'affilée », réglée PREFERRED.
        # Même masse que ses quatre sœurs : une règle de bien-être ne vaut pas plus qu'une
        # autre, et à −6 elle ne peut pas rentabiliser la suppression d'une séance (≥ 21).
        "consecutive_days_violation": -6,
        # V10 — malus PAR séance SOUS le quota hebdomadaire (une équipe à 1 sur 2 demandées
        # paie −1000 ; 2 manquantes = −2000). Construit dans build_schedule via
        # ``add_missing_session_penalty`` (patron overload_day), passé en soft_terms.
        #
        # P3 — pourquoi les plafonds de confort ne suffisent PAS : le pire swing NET d'un
        #      déplacement bénéficiaire (une équipe gagne son confort maximal 20 en volant
        #      un créneau, ET relâche un avoided_venue 10) = 20 + 10 = 30 > 21. Sans malus,
        #      ce déplacement supprimerait une séance pour un gain de confort de 30 (net +9
        #      après la séance perdue à 21). C'est missing_session qui ferme le trou :
        #      supprimer une séance coûte au moins 1 (tier D) + 20 (session_count) + 1000
        #      (missing_session) = 1021, hors d'atteinte de tout empilement de confort.
        # P4 — dominance : le relief MAX réaliste en supprimant une séance ≈ 107 (confort
        #      direct + littéraux de règles), et chaque déplacement en cascade rend au plus
        #      +30 net ; atteindre 1021 exigerait > 30 mouvements nets — hors de portée des
        #      datasets réels. Le résiduel est gardé par le test NR (même schéma que V9).
        # P5 — les tiers restent souverains : missing_session s'applique SYMÉTRIQUEMENT aux
        #      deux camps d'un conflit de créneau (chaque équipe sous quota paie le sien),
        #      donc il n'inverse jamais l'ordre S > A > B > C > D d'un arbitrage de créneau.
        "missing_session": -1000,
    }
)

# Une équipe à ZÉRO séance paie UNPLACED_PENALTY (question « placée ou pas »), ET, depuis
# V10, spw × |missing_session| (question « combien manque-t-il »). Deux questions distinctes :
# l'ordre 0 placée < 1 placée < 2 placées reste STRICTEMENT décroissant en pénalité.
UNPLACED_PENALTY = 100000

# P3-21 — terme de STABILITÉ (convergence moteur). Chaque variable ``model.x[(team, venue,
# day, start)]`` dont la clé figure dans ``previousAssignments`` porte +STABILITY_TERM_WEIGHT
# dans l'objectif de PHASE 2 (placement déjà verrouillé). But : faire converger une
# régénération vers son placement précédent en DÉPARTAGEANT les ex æquo exacts, jamais en
# arbitrant.
#
# Un créneau HARD n'a PAS de variable (``model.build_model`` l.92-98 : ``continue`` avant le
# ``NewBoolVar``), donc il est absent de ``model.x`` — le terme de stabilité ne peut JAMAIS
# payer double un pin, il ne touche que des créneaux réellement choisis par le solveur.
#
# Séparation LEXICOGRAPHIQUE avec le chaînage — la phase 2 maximise
#   placement + CHAINING_STABILITY_MULTIPLIER × chaînage + STABILITY_TERM_WEIGHT × stabilité.
# Preuve d'empilement (même patron que les « ceilings » de CHAINING_TIER_WEIGHTS) :
#   - masse MAX de stabilité = STABILITY_TERM_WEIGHT × cap(previousAssignments = 2000) = 2000 ;
#   - plus petit incrément de chaînage = min(CHAINING_TIER_WEIGHTS) = 1 (tier D) ;
#   - CHAINING_STABILITY_MULTIPLIER = 4096 > 2000 ⇒ UN SEUL point de chaînage prime TOUTE la
#     stabilité empilée. La stabilité ne départage donc que les solutions à (placement,
#     chaînage) IDENTIQUES — jamais un arbitrage de chaînage.
#   - le placement est verrouillé (``placement_expression >= optimum``) AVANT la phase 2 : la
#     stabilité ne peut pas non plus déplacer une séance (le placement reste optimal). Elle
#     n'arbitre donc ni le score, ni le chaînage — seulement les ex æquo exacts.
# Le score RAPPORTÉ recalcule placement + chaînage aux poids d'ORIGINE (stabilité exclue,
# main._solve) : SCORE_FORMULA_VERSION est inchangé.
STABILITY_TERM_WEIGHT = 1
CHAINING_STABILITY_MULTIPLIER = 4096


# Small INTEGER tiebreaker weights for the same-venue same-day chaining bonus
# (a PERSON present at both back-to-back sessions — coach OR player of the team).
# The scale below is UNCHANGED; what shifts is that a single consecutive pair can
# now carry up to *k* distinct common people, each its own `chained` term, so the
# pair's total chaining reward stacks to k × weight ≤ 8k rather than a flat ≤ 8.
# Two ceilings still keep it a *bonus*, never a decider:
#   1. Dropping a placed session to chain others costs tier(≥1) + session_count(20)
#      AND fires missing_session (−1000) → ≥ 1021. Even a fully stacked pair
#      (k people at S) would need k > 127 to overcome that — unreachable, since k
#      is the handful of people two adjacent same-venue sessions actually share.
#   2. Between adjacent tiers the smallest placement gap is C−D = 9 (30 vs 21).
#      A lone term (≤ 8) never steals a slot from a higher tier; stacking widens
#      only the C↔D wobble slightly (the club treats C/D as indifferent), and the
#      missing_session floor above still forbids ever dropping a session for it.
# Hence per-person max weight = 8. Order preserved (S>A>B>C>D); each term takes the
# pair's highest tier (chaining SF1(S)+U15F(B) → 8, taken on the S).
CHAINING_TIER_WEIGHTS = MappingProxyType(
    {
        "S": 8,
        "A": 6,
        "B": 4,
        "C": 2,
        "D": 1,
    }
)

# Lot PASSERELLES PR-2 — MALUS par paire de placements CHEVAUCHANTS d'une passerelle PREFERRED
# (deux équipes partageant des joueurs, arbitrage n°3). Table DÉDIÉE sur le patron de
# ``CHAINING_TIER_WEIGHTS`` : le poids d'une paire = celui de la PLUS HAUTE des deux équipes
# (``_higher_tier``), appliqué NÉGATIVEMENT dans l'objectif de placement.
#
# Preuve d'empilement — pourquoi une passerelle PREFERRED ne SUPPRIME jamais une séance (patron
# des « ceilings » de CHAINING_TIER_WEIGHTS + du plancher missing_session) :
#   0. Une séance placée vaut au minimum 21 (tier D 1 + session_count 20), et retirer une séance
#      d'une équipe SOUS son quota déclenche missing_session (−1000) : le plancher réel d'une
#      suppression est donc ≥ 1021 (identique aux plafonds chaînage/confort de ce module).
#   1. Pour UNE passerelle donnée, une séance de A ne peut chevaucher qu'AU PLUS UNE séance de B :
#      la contrainte 4 (``add_team_no_overlap``) interdit à B deux séances qui se chevaucheraient
#      entre elles, donc a fortiori deux séances chevauchant le MÊME créneau de A. Une séance ne
#      porte donc qu'UN malus (≤ 8) par passerelle où son équipe figure.
#   2. Le pire empilement sur une séance = (nombre de passerelles de son équipe) × 8. Pour le
#      rendre rentable il faudrait relâcher > 1021, soit > 127 passerelles PREFERRED chevauchantes
#      sur cette seule séance — hors de portée (cap 50 passerelles/club, et une équipe n'en porte
#      qu'une fraction). Le résiduel arithmétique est gardé, falsifiable, par le test NR pire-cas
#      (``tests/semantic/test_team_link_never_drops_session`` + l'invariant hypothesis).
#   3. Discriminance : à tier S le malus 8 > rest(3) + spacing(2) = 5, donc PREFERRED ORIENTE
#      réellement le placement (il sépare quand c'est possible) ; à tier D il vaut 1 (un simple
#      départage), cohérent avec le poids moindre d'une équipe de bas tier.
TEAM_LINK_TIER_WEIGHTS = MappingProxyType(
    {
        "S": 8,
        "A": 6,
        "B": 4,
        "C": 2,
        "D": 1,
    }
)

# PR-3 (comblement référencé au socle) — BONUS d'objectif par séance comblée qui RETROUVE le
# jour+heure de sa version pointée du socle, QUEL QUE SOIT le gymnase (la référence est
# ``(team, day, start)`` SANS venue : « changer de gymnase = pas grave ; changer de jour/heure =
# coûteux »). Table DÉDIÉE sur le patron de ``TEAM_LINK_TIER_WEIGHTS`` : le bonus d'une variable
# ``model.x[(team, venue, day, start)]`` dont ``(team, day, start)`` figure dans
# ``socleReferenceAssignments`` = ``SOCLE_REFERENCE_TIER_WEIGHTS[tier(team)]``, appliqué
# POSITIVEMENT dans l'objectif de PLACEMENT (phase 1, patron ``extra_placement_terms`` du malus
# passerelle). Le poids CROÎT avec le tier (S>A>B>C>D) : une équipe fanion tient plus fort son
# horaire de socle, une équipe secondaire absorbe le déplacement (arbitrage produit).
#
# Fenêtre imposée : chaque poids > 10 (``preferred``) et < 21 (tier D 1 + session_count 20).
# Choix 12/14/16/18/20 (pas de 2, tous dans 11..20, strictement décroissants).
#
# Preuve d'empilement (patron des « ceilings » CHAINING_TIER_WEIGHTS / TEAM_LINK_TIER_WEIGHTS) :
#   0. Une séance PLACÉE vaut au minimum 21 (tier D 1 + session_count 20) ; retirer une séance
#      d'une équipe SOUS son quota déclenche ``missing_session`` (−1000) : le plancher réel d'une
#      suppression est ≥ 1021.
#   1. LE BONUS NE SUPPRIME JAMAIS UNE SÉANCE. Le bonus n'existe QUE sur une variable POSÉE (il
#      s'ajoute quand ``model.x[...] == 1``) ; le retirer coûte donc ≥ 21 (le placement lui-même,
#      + le bonus jusqu'à 20, + 1000 si l'équipe passe sous quota). Un bonus ≤ 20 ne peut donc
#      jamais rentabiliser la suppression d'une séance — il n'oriente QUE le placement d'une
#      séance qui SERA de toute façon posée. Chaque variable ne porte qu'UN bonus socle (sa
#      propre clé ``(team, day, start)`` y est ou n'y est pas), donc pas d'empilement > 20 sur
#      une seule séance.
#   2. L'ORDRE DES TIERS POUR LE PLACEMENT RESTE S>A>B>C>D. Deux équipes qui se disputent UN
#      créneau départagent d'abord sur le placement : les écarts de tier posés sont
#      S−A = 9000, A−B = 900, B−C = 90 (``tier + session_count`` : 10020/1020/120/30). Un bonus
#      ≤ 20 est très loin de combler le plus petit de ces trois écarts (90) : jamais une équipe
#      de tier strictement inférieur (jusqu'à C) ne prend le créneau d'une équipe de tier
#      supérieur (jusqu'à B) grâce au bonus socle. Le SEUL couple à écart < bonus est C−D = 9
#      (30 vs 21) : là, comme le fait DÉJÀ le confort (``preferred`` 10 > 9), le bonus socle peut
#      réallouer un créneau entre C et D. C'est le « wobble C↔D » que ce module DÉCLARE ACCEPTÉ
#      (voir CHAINING_TIER_WEIGHTS : « the club treats C/D as indifferent ») — et il sert ici
#      l'intention : à horaire de socle disputé, le bonus décroissant (C 14 > D 12) fait tenir le
#      tier SUPÉRIEUR (test « à une place pour deux »). Aucune inversion S>A / A>B / B>C possible.
#   3. Discriminance : à tier D le bonus 12 > ``preferred`` 10, donc garder le jour+heure de socle
#      (venue libre) bat une simple préférence de gymnase — l'objet même de la référence.
SOCLE_REFERENCE_TIER_WEIGHTS = MappingProxyType(
    {
        "S": 20,
        "A": 18,
        "B": 16,
        "C": 14,
        "D": 12,
    }
)

TIER_WEIGHT_NAMES = ("S", "A", "B", "C", "D")
BONUS_WEIGHT_NAMES = (
    "preferred",
    "avoided_venue",
    "preferred_day",
    "preferred_time",
    "rest",
    "session_count",
    "spacing",
    "overload_day",
    # V9 — littéraux de violation des règles implicites PREFERRED, passés en soft_terms
    # (jamais des bonus par assignment : aucun champ d'assignment ne porte ces noms).
    "coach_rest_violation",
    "salarie_violation",
    "chain_violation",
    "age_violation",
    "consecutive_days_violation",
    # V10 — littéral « une séance de plus manque au quota », passé en soft_terms depuis
    # add_missing_session_penalty (jamais un bonus par assignment : aucun champ ne le porte).
    "missing_session",
)

_PRIORITY_TIER_FIELDS = (
    "priority_tier",
    "priorityTier",
    "priority_tier_id",
    "priorityTierId",
    "tier",
    "tier_id",
    "tierId",
)

_PRIORITY_RANK_FIELDS = ("priority_rank", "priorityRank", "tier_rank", "tierRank")

_TIER_ALIASES = {
    "S": "S",
    "A": "A",
    "B": "B",
    "C": "C",
    "D": "D",
    "TIER_S": "S",
    "TIER_A": "A",
    "TIER_B": "B",
    "TIER_C": "C",
    "TIER_D": "D",
}

_ONE_BASED_TIER_IDS = {1: "S", 2: "A", 3: "B", 4: "C", 5: "D"}
_ZERO_BASED_TIER_RANKS = {0: "S", 1: "A", 2: "B", 3: "C", 4: "D"}

_BONUS_FIELD_ALIASES = {
    "preferred": (
        "preferred",
        "is_preferred",
        "isPreferred",
        "preferred_slot",
        "preferredSlot",
        "preferred_venue",
        "preferredVenue",
    ),
    "rest": ("rest", "rest_satisfied", "restSatisfied", "respects_rest", "respectsRest"),
}

_EXPLICIT_BONUS_FIELDS = (
    "soft_bonuses",
    "softBonuses",
    "objective_bonuses",
    "objectiveBonuses",
    "bonus_weights",
    "bonusWeights",
    "bonuses",
)

_MISSING = MISSING
