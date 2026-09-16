# language: fr
Fonctionnalité: Un domicile non placé dont la ligue change la salle est arbitré, jamais réparé en silence
  Un match à domicile non placé peut être rattaché à un gymnase du club (par son libellé
  fédéral) sans être placé pour autant. Si un dépôt suivant nomme une AUTRE salle, l'écart
  n'est jamais réécrit en silence : il est présenté au gestionnaire, qui garde son gymnase
  (le libellé source est mémorisé pour ne plus reposer la question) ou adopte la salle du
  fichier (l'alias confirmé repose le bon gymnase, sinon la salle remonte « à rattacher »).

  Scénario: Un dépôt divergent ouvre un écart de salle et laisse le gymnase intact
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable rattaché au libellé « SALLE ORIGINE »
    Quand je dépose un domicile non placé au libellé « SALLE ORIGINE »
    Alors la rencontre a son gymnase et reste non placée
    Quand la ligue re-dépose le domicile au libellé « SALLE RAPHAEL DE BARROS », sans trancher
    Alors un écart de salle est ouvert et le gymnase reste intact

  Scénario: « Garder l'appli » sur l'écart rend le re-dépôt identique muet
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable rattaché au libellé « SALLE ORIGINE »
    Quand je dépose un domicile non placé au libellé « SALLE ORIGINE »
    Et la ligue re-dépose le domicile au libellé « SALLE RAPHAEL DE BARROS », sans trancher
    Et je garde l'appli sur l'écart de salle
    Alors l'écart de salle est retiré et la rencontre est traitée
    Quand la ligue re-dépose le même libellé divergent
    Alors aucun nouvel écart de salle n'est ouvert

  Scénario: « Prendre le fichier » suit l'alias confirmé de la salle
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable rattaché au libellé « SALLE ORIGINE »
    Et un deuxième gymnase jetable rattaché au libellé « SALLE RAPHAEL DE BARROS »
    Quand je dépose un domicile non placé au libellé « SALLE ORIGINE »
    Et la ligue re-dépose le domicile au libellé « SALLE RAPHAEL DE BARROS », sans trancher
    Et j'adopte le fichier sur l'écart de salle
    Alors le domicile bascule sur le deuxième gymnase et reste non placé
