# language: fr
Fonctionnalité: Retirer une séance placée
  À l'étape génération, le gestionnaire peut VIDER un créneau : la séance placée est retirée du
  plan, sans appeler le moteur (un retrait creuse un trou, il ne crée pas de conflit). La séance
  passe « à replacer ». Une séance verrouillée se retire quand même — le verrou part avec elle. Et
  retirer une séance de GROUPE emporte tout le groupe d'un coup, mais le groupe (le bloc de
  mutualisation) continue d'exister.

  Scénario: Retirer une séance placée la rend à replacer
    Étant donné le club de démonstration et un plan de période généré
    Et deux séances placées d'équipes distinctes, chacune sur sa propre case
    Quand je retire la première séance placée
    Alors la séance retirée a disparu du plan
    Et son équipe est à replacer

  Scénario: Une séance verrouillée se retire quand même
    Étant donné le club de démonstration et un plan de période généré
    Et deux séances placées d'équipes distinctes, chacune sur sa propre case
    Et je verrouille la première séance placée
    Quand je retire la première séance placée
    Alors la séance retirée a disparu du plan

  Scénario: Retirer la séance d'un groupe emporte tout le groupe, le bloc survit
    Étant donné le club de démonstration et un plan de période généré
    Et deux séances placées d'équipes distinctes, chacune sur sa propre case
    Et je mutualise la première séance en y rattachant la seconde équipe
    Quand je retire la séance du groupe
    Alors les deux séances du groupe ont disparu du plan
    Et le bloc de mutualisation existe toujours
