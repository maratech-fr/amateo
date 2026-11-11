# language: fr
Fonctionnalité: Un planning de période commencé depuis une semaine se valide seul
  Le gestionnaire adapte une fermeture, en prépare deux versions, en charge une, puis oublie de
  valider. Sept jours après le début de la période, l'application valide seule la version
  chargée, garde l'autre, et le marque « Validé automatiquement » : la période n'est jamais
  laissée sans planning en vigueur. S'il rouvre ce planning, l'application ne le revalide plus
  jamais d'elle-même — la décision lui revient.

  Scénario: La version chargée d'une fermeture commencée depuis huit jours entre en vigueur, l'autre est gardée
    Une fermeture commencée il y a huit jours, jamais validée, porte deux versions terminées.
    Le passage quotidien pointe la version chargée, ne supprime pas l'autre, et la marque.
    Étant donné un petit club réaliste dont le planning de saison est en vigueur, avec une fermeture adaptée portant deux versions terminées, la première chargée
    Et cette fermeture a commencé il y a huit jours sans que personne ne valide son planning
    Quand la validation automatique des plannings de période passe pour ce club
    Alors le planning de la fermeture est en vigueur sur la version chargée, l'autre version est gardée, et il est marqué validé automatiquement

  Scénario: Un planning rouvert après la validation automatique n'est jamais revalidé
    Après la validation automatique, le gestionnaire rouvre le planning pour choisir lui-même.
    Le passage suivant le laisse ouvert, avec ses deux versions.
    Étant donné un petit club réaliste dont le planning de saison est en vigueur, avec une fermeture adaptée portant deux versions terminées, la première chargée
    Et cette fermeture a commencé il y a huit jours sans que personne ne valide son planning
    Et la validation automatique des plannings de période est passée pour ce club
    Quand je rouvre le planning de cette fermeture
    Et la validation automatique des plannings de période repasse pour ce club
    Alors le planning de la fermeture reste ouvert et ses deux versions sont gardées
