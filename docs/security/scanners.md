# Outillage sécurité externe (SEC A19 — 2026-08-05)

> Décision fondateur : la revue IA systématique (`/code-review` par PR) sort du cycle —
> coût en tokens + régressions en boucle (mesuré — protocole `review-response`, `.claude/skills/`). Le filet AUTOMATIQUE
> est **à zéro token** : scanners gratuits en CI + tests de non-régression +
> suite bloquante. Les revues IA (`/code-review`, `/audit`) restent disponibles **à la
> demande du fondateur** ; `/security-review` reste systématique sur les diffs
> auth/données/intégrations externes.

## Qui tourne quand — et pourquoi cette cadence

| Outil | Ce qu'il attrape | Quand | Pourquoi cette cadence |
|---|---|---|---|
| `composer audit` / `npm audit` / `pip-audit` | bibliothèques applicatives vulnérables | chaque push (job `dependency-audit`, bloquant) | rapide, zéro faux positif — une dépendance trouée doit se voir avant merge |
| **Gitleaks** | secrets dans le code ET tout l'historique git | chaque push (job `secrets-scan`, bloquant) | un secret commité doit rougir dans la minute : chaque heure passée exige de le révoquer |
| **Semgrep** | motifs de sécurité dans le code (taint, injections, désérialisation) | chaque push (job `semgrep`, **BLOQUANT**, SEC-14) | l'inventaire initial est soldé — un nouveau finding est un vrai signal |
| **Trivy** (build) | paquets OS des images prod (openssl, libc, nginx…) — l'angle mort de dependency-audit | chaque build d'images (`build-docker`, gate CRITICAL fixables) | une image neuve ne doit pas naître trouée |
| **Trivy** (hebdo) | CVE découvertes APRÈS le build sur les images ghcr publiées | lundi 06:00 UTC (`security-weekly.yml`, relançable à la main) | le monde bouge même quand l'image ne change pas |
| **ZAP** | comportement de l'app qui tourne (headers, cookies, injections) vu de l'extérieur | **manuel, avant une release** (baseline) ; scan actif une fois avant la mise en prod puis à chaque changement d'infra | lent et bruyant ; l'isolation multi-tenant est mieux testée par la suite phase1, qui comprend « ce club ne voit pas l'autre » — pas lui |
| **Nuclei** | empreintes de failles connues sur un serveur EXPOSÉ (config nginx, TLS, panneaux oubliés) | **manuel, mensuel, sur la prod déployée** — sans objet avant qu'elle existe | sa cible est ce que le monde extérieur voit du serveur |

Rien n'est installé sur le poste : tout tourne en GitHub Actions ou via image Docker.

## Triage des findings

- **Gitleaks** : chaque exception vit dans `.gitleaks.toml`, analysée une par une
  (canaris de test, findings historiques traités et documentés dans le fichier
  lui-même). Ne JAMAIS élargir pour faire passer la CI. Un vrai
  secret commité : révoquer d'abord, allowlister le commit ensuite.
- **Trivy** : exceptions dans `.trivyignore`, datées et justifiées CVE par CVE.
  Gate = CRITICAL **fixables** seulement (`--ignore-unfixed`) — un CRITICAL sans
  correctif disponible ne bloque pas, il se surveille via le rapport hebdo.
- **Semgrep** : GATE (triage SEC-14 soldé, le reste justifié individuellement). Exclusions
  de PORTÉE dans `.semgrepignore` (outillage dev, code de test) ; cas ponctuels en
  commentaire **inline `nosemgrep: <rule>` motivé** sur la ligne même. Ne jamais élargir
  pour faire passer la CI.

## Rituel pré-production (ZAP + Nuclei) — roadmap SEC-19

Le jour où une préprod/prod existe :

```bash
# ZAP baseline (passif — aucune attaque, ~2 min)
docker run --rm -t zaproxy/zap-stable zap-baseline.py -t https://preprod.example.tld

# Nuclei (empreintes CVE/configs sur l'hôte exposé)
docker run --rm projectdiscovery/nuclei:latest -u https://preprod.example.tld
```

Scan actif ZAP (`zap-full-scan.py`) : une fois avant la vraie mise en prod, sur la
préprod UNIQUEMENT, avec un compte de test — jamais sur la prod avec des données réelles.

## Ce que ces outils ne verront jamais (et qui est couvert ailleurs)

L'isolation multi-tenant/RLS, les autorisations métier, Mercure (topics SSE par club)
et la frontière engine : aucun scanner du marché ne les comprend. Leur défense est la
suite bloquante phase1 (`TenantIsolationTest`, `RlsIsolationTest`, `MercureHardeningTest`,
SEC-01→12, SA0…) et les tests de non-régression exigés sur tout axe structurant —
c'est le cœur du dispositif, les scanners n'en sont que la ceinture.
