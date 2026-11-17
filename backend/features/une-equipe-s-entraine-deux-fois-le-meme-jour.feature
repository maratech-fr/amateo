# language: fr
Fonctionnalité: Une équipe peut s'entraîner deux fois le même jour
  Le gestionnaire règle, équipe par équipe, le nombre maximum de séances le même jour (1 par
  défaut, jusqu'à 4). Le récapitulatif s'y aligne : il laisse passer deux réservations le même jour
  quand l'équipe y est autorisée, et il arrête toujours deux séances qui se chevauchent. Une fois
  le planning de saison en vigueur, ce réglage ne bouge plus ; une période garde le sien. En
  période, une équipe qui garde ses créneaux de saison garde aussi deux créneaux qui se suivent,
  et le planning le signale sans les séparer. Sur un petit club réaliste (palier 60 s).

  Scénario: Une équipe autorisée à deux séances par jour : le récapitulatif l'accepte, la saison tient ses deux réservations, la période les garde
    Étant donné un petit club réaliste bâti par l'API, pas encore généré
    Et je réserve SM1 deux fois le lundi, sur deux créneaux du même gymnase qui se suivent
    Quand je demande le récapitulatif
    Alors il bloque : SM1 est réservée deux fois le lundi, une seule séance par jour, et il propose d'autoriser 2 séances par jour
    Quand j'autorise 2 séances par jour pour SM1
    Alors le récapitulatif ne bloque plus
    Quand je réserve SM1 une troisième fois le lundi, sur un créneau d'un autre gymnase qui chevauche l'un des deux
    Alors le récapitulatif bloque : les créneaux se chevauchent, sans proposer d'autoriser davantage
    Quand je retire cette troisième réservation
    Alors le récapitulatif ne bloque plus
    Quand je génère et valide le planning de saison du petit club
    Alors les deux séances de SM1 tiennent le lundi, et le planning ne signale aucun écart pour SM1
    Quand j'essaie de remettre SM1 à 1 séance par jour
    Alors c'est refusé : « Rouvrez le planning de saison pour modifier le nombre de séances. »
    Quand j'ouvre une période où SM1 garde ses créneaux de saison, et j'y autorise 3 séances par jour pour SM1
    Alors la période porte 3 séances par jour pour SM1, la saison reste à 2
    Quand je génère le planning de cette période
    Alors SM1 garde ses deux créneaux du lundi et le planning signale « gardées du planning de saison »
