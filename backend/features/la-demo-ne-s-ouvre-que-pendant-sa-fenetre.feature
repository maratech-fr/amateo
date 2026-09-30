# language: fr
Fonctionnalité: La démo ne s'ouvre que pendant sa fenêtre d'activation
  Un compte de démonstration ne doit se connecter que lorsque sa fenêtre
  d'activation est ouverte. En dehors de cette fenêtre, sa connexion échoue
  exactement comme un mauvais mot de passe : rien ne trahit qu'il s'agit d'un
  compte démo dont la porte est simplement fermée.

  Scénario: Le compte démo se connecte dans sa fenêtre, jamais en dehors
    Étant donné un compte démo inscrit et vérifié
    Quand sa fenêtre d'activation est fermée
    Et il tente de se connecter avec son bon mot de passe
    Alors la connexion est refusée avec le message « Identifiants invalides. »
    Quand sa fenêtre d'activation est ouverte
    Et il tente de se connecter avec son bon mot de passe
    Alors la connexion réussit
