# language: fr
Fonctionnalité: L'import du parc en un geste
  Pour saisir tout son parc d'un coup à l'onboarding, le gestionnaire colle le
  texte parc-v2 rendu par son assistant — une ligne par case (gymnase, jour,
  heure, durée, équipes, groupe) — VOIT l'aperçu cochable de ce que l'app
  DÉDUIRAIT, puis importe : gymnase, équipes, créneaux, bloc mutualisé et
  réservations naissent en une seule fois, la réservation posée au socle. Les
  coachs ne sont plus extraits (saisis à la main). Importer un parc est un geste
  d'onboarding : tant que le planning de saison est en vigueur, il est refusé. Et
  recoller le même parc ne crée jamais de doublon.

  Scénario: Mon parc parc-v2 s'importe en un geste et la réservation se pose au socle
    Étant donné le club de démonstration, prêt à importer son parc, le planning de saison rouvert
    Quand j'analyse mon parc de deux équipes, un gymnase, deux cases dont une mutualisée
    Alors l'aperçu propose de tout créer
    Quand j'importe tout le parc
    Alors l'import réussit
    Et les deux équipes et le gymnase existent
    Et le bloc mutualisé « PARC BEHAT GROUPE » existe avec ses deux équipes
    Et la réservation de ma première équipe est posée au socle sur sa case réservée du lundi

  Scénario: Un parc est refusé tant que le planning de saison est en vigueur
    Étant donné le club de démonstration, prêt à importer son parc, le planning de saison en vigueur
    Quand j'importe tout le parc
    Alors l'import est refusé faute de planning rouvert
    Et rien du parc n'a été écrit

  Scénario: Recoller le même parc ne crée aucun doublon
    Étant donné le club de démonstration, prêt à importer son parc, le planning de saison rouvert
    Et mon parc déjà importé une fois
    Quand j'analyse mon parc de deux équipes, un gymnase, deux cases dont une mutualisée
    Alors l'aperçu reconnaît les équipes et le gymnase déjà présents
    Quand j'importe tout le parc
    Alors aucun doublon n'est créé
