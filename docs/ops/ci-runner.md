# Le runner de CI — VM Hyper-V `amateo-ci`

> **Maison unique** de la mise en place et de l'exploitation du runner GitHub Actions
> auto-hébergé. Le graphe des jobs et ce que chacun prouve : `docs/testing/testing-strategy.md` §1.
> La mise en prod qui lit le verdict du run de `main` : `docs/ops/deploy.md`.
> Les « gestes » sont ceux du fondateur (poste Windows + VM, hors dépôt) ; « ORACLE n » renvoie
> à la numérotation du cadrage des gestes Hyper-V.

## 1. Ce que c'est

Les jobs de `.github/workflows/ci.yml` (et `visual-baselines.yml`) tournent sur le pool que
désignent les variables de dépôt `CI_RUNS_ON` / `CI_RUNS_ON_HEAVY` (§2) : **runners GitHub tant que
le dépôt est public ; VM auto-hébergée au passage en privé**. La VM, c'est **trois runners GitHub
Actions non éphémères**, installés dans une VM Hyper-V `amateo-ci` (Ubuntu Server 24.04) du poste du
fondateur, allumée à la demande. Restent toujours chez GitHub : `deploy.yml`, `security-weekly.yml`
et `mirror-images.yml` (runner littéral `ubuntu-24.04`).

**VM éteinte = les checks restent « Queued », et GitHub échoue un job non acquis après 24 h
(voir §6) — seulement tant que les variables pointent vers la VM.** Les §3, §5 (gestes de mise en
place), §6 et §8 décrivent la VM.

Le dépôt étant public, le runner ne sert que les PR du dépôt lui-même (§7). La VM est la borne
de ressources (mémoire, vCPU) ; il n'y a ni cgroup ni limite mémoire par conteneur côté dépôt.

## 2. Routage et labels

| Variable de dépôt | Label du runner | Jobs |
|---|---|---|
| `CI_RUNS_ON` (`amateo`) | `amateo` (les 3 runners) | tous les jobs de `ci.yml` non « lourds », dont `preflight`, `blocking-tests`, `engine-tests`, `engine-semantics`, `phpstan`, `rector` |
| `CI_RUNS_ON_HEAVY` (`amateo-heavy`) | `amateo-heavy` (runners 1 et 2) | `frontend`, `backend-tests`, `e2e`, `functional-tests`, `build-docker`, et le job de `visual-baselines.yml` |

Chaque `runs-on` vaut `${{ vars.CI_RUNS_ON || 'ubuntu-24.04' }}` (ou `…_HEAVY`) : variable absente =
runner hébergé par GitHub. Le classement par label est une **classe de lourdeur**, pas un poids
mesuré : au plus deux jobs lourds en parallèle, un troisième runner pour le reste.

La sonde `ci-runner-isolation.yml` utilise `vars.CI_RUNS_ON` **sans repli** (chez GitHub elle ne
prouverait rien : son premier step refuse tout runner non auto-hébergé).

**Retour arrière** : supprimer les deux variables de dépôt (Settings → Secrets and variables →
Actions → Variables). Le run suivant part chez GitHub, mêmes conteneurs, mêmes captures de
référence : rien à régénérer. Variante : retirer `CI_RUNS_ON_HEAVY` seule (lourds chez GitHub,
le reste dans la VM).

## 3. Régime de la CI sur le runner

- **Isolation entre jobs** (le démon Docker de la VM est persistant et partagé) : tout job compose
  porte `COMPOSE_PROJECT_NAME` = `AMATEO_STACK` = `ci-<run_id>-<run_attempt>-<job>` et un
  `COMPOSE_FILE` à chemins absolus (`docker-compose.yml` + `docker-compose.test.yml`, qui ne publie
  aucun port). Le premier step de chaque job qui touche Docker, `Isolate the Docker credentials of
  this job`, redirige `DOCKER_CONFIG` vers `$RUNNER_TEMP`. `build-docker` tague ses images
  `scan-<run>-<attempt>/…`.
- **Nettoyage** : dernier step `ci-stack-down` (`.github/actions/ci-stack-down`, `if: always()`, ne
  rougit jamais) ; doublé par les hooks du runner (§8).
- **Image php** : `.github/actions/build-php-cached` suit `runner.environment` — `docker compose
  build` (cache du démon) sur le runner, buildx + cache GHA chez GitHub.
- **e2e** : dans le conteneur Playwright du service compose `e2e` (Vite dans `frontend-dev`,
  mailpit dans le projet), plus d'installation de Chromium ni de Node sur l'hôte. Les PNG de
  référence dépendent de l'image, pas du poste.
- **Bloquants** : une seule invocation PHPUnit (`--group phase1`, step `Blocking suite`) plus le
  step filtré `ParcImportCorrectionTenantNR` — détail : `docs/testing/blocking-tests.md`.
- **Dependabot** : mensuel (`.github/dependabot.yml`), les cinq dossiers Dockerfile en une entrée
  `docker` groupée.
- **Garde** : `backend/tests/Unit/Documentation/CiWorkflowRegimeTest.php` (routage, `preflight` —
  sa garde fork couvre aussi les PR vers `lot/**` —, régime par branche cible, `main` allégé,
  isolation, bloquants, e2e en conteneur, hooks, sonde).

## 4. Protection de `main` : `strict`, Preflight, `enforce_admins`

- **15 required checks** : les 14 historiques plus **Preflight** (job sans checkout, qui rougit sur
  une PR dont `head.repo.full_name` n'est pas le dépôt ; tout autre job `needs: preflight`, sans quoi
  un job sauté vaudrait succès).
- **`strict: true`** (branche à jour obligatoire) : l'arbre mergé est celui que la PR a prouvé. Une
  PR en retard se met à jour (« Update branch ») et rejoue le run complet — sauf la branche d'un
  lot, qui se rebase (§9). Régime par branche cible : une PR vers `main` joue le gate complet, une
  PR vers `lot/<sujet>` le régime léger (§9).
- **`enforce_admins: true`** tant que le dépôt est public.
- **`main` allégé** : sur `push main` ne tournent que `preflight`, `lint`, `secrets-scan`,
  `semgrep`, `dependency-audit`, `engine-tests`, `engine-perf` et `build-docker` ; le reste est
  PR-only (`if` sur l'événement `pull_request`, plus la base `main` pour `backend-tests`, `e2e` et
  `functional-tests` ; régimes complets : §9). Sens de « CI verte » pour un déploiement :
  `docs/ops/deploy.md`.
- **Règle de merge** : jamais de merge par contournement des checks (bouton admin, `--admin`,
  runner indisponible compris) — on attend le vert, en rallumant la VM si les variables de runner
  pointent vers elle (`CLAUDE.md` §7 Git).

## 5. Les gestes, dans l'ordre

### Avant la PR (poste Windows + VM, hors dépôt)

1. **ORACLE 1 — Hyper-V activé.** `Get-WindowsOptionalFeature -Online -FeatureName Microsoft-Hyper-V-All` → `Enabled` ; puis `wsl -l -v` Running et `make start` OK.
2. **ORACLE 2 — groupe Administrateurs Hyper-V.** `Add-LocalGroupMember -SID S-1-5-32-578 -Member $env:USERNAME`, session rouverte ; preuve : `Get-VM` sans élévation.
3. **ORACLE 3 — réseau NAT interne.** `New-VMSwitch -Name amateo-ci-nat -SwitchType Internal`, `New-NetIPAddress -IPAddress 10.250.0.1 -PrefixLength 24 -InterfaceAlias "vEthernet (amateo-ci-nat)"`, `New-NetNat -Name amateo-ci-nat -InternalIPInterfaceAddressPrefix 10.250.0.0/24`. Preuve : `Get-NetNat`. `New-NetNat` refusé (conflit avec un NAT existant) : s'arrêter, le choix du réseau se rouvre.
4. **ORACLE 4 — pare-feu « la VM ne joint jamais l'hôte ».** Règle entrante `Block` sur `10.250.0.0/24`, `-Profile Any`. Preuve : `Get-NetFirewallRule -DisplayName "amateo-ci*" | Select Enabled,Action` = True/Block. Ne couvre pas le reste du LAN (accepté tant que les runs sont ceux du dépôt).
5. **ORACLE 5 — la VM.** `amateo-ci`, génération 2, 8 vCPU, VHDX dynamique (plafond 64 Go) **sur C:**, mémoire dynamique (démarrage 2 Go, minimum 1 Go, maximum 10 Go), checkpoints désactivés, `AutomaticStopAction ShutDown`, Secure Boot modèle `MicrosoftUEFICertificateAuthority`. Preuve : `Get-VM amateo-ci | Select Generation,ProcessorCount,DynamicMemoryEnabled,MemoryMaximum,AutomaticStopAction` et `Get-VHD` = Dynamic.
6. **ORACLE 6 — Ubuntu Server 24.04 LTS.** IPv4 fixe 10.250.0.10/24, passerelle 10.250.0.1, DNS 1.1.1.1 / 9.9.9.9 ; LVM étendu (`lvextend -r -l +100%FREE`), OpenSSH, aucun snap. Preuve : `ip -4 addr show eth0`, `curl -sSI https://github.com`.
7. **ORACLE 7 — Docker Engine CE** (dépôt apt officiel : `docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin`) + `/etc/docker/daemon.json` copié depuis `.github/runner/docker-daemon.json`. Preuve : `docker run --rm hello-world` ; `docker info` = Cgroup Driver systemd, Cgroup Version 2.
8. **ORACLE 8 — compte `runner`.** `adduser runner`, `usermod -aG docker runner`, aucun sudo. Aucun uid particulier n'est requis (chaque job écrit `USER_ID=$(id -u)` dans `.env`). Preuve : `id runner`, `sudo -l -U runner` vide.
9. **Swap 4 Go dans la VM** (`fallocate -l 4G /swapfile`, `chmod 600`, `mkswap`, `swapon`, ligne `fstab`). Preuve : `free -h`.
10. **ORACLE 9 — outils hôte.** `apt install -y git curl jq make zstd pipx fontconfig unzip`. Ni Node ni `playwright install-deps` sur la VM : l'e2e tourne dans le conteneur, `setup-node` apporte Node dans le tool-cache du runner pour `frontend` et `dependency-audit`.
11. **ORACLE 10 — programme runner** (page GitHub « New self-hosted runner », sha256 vérifié) dans `/opt/actions-runner-1`, `-2`, `-3`, puis `sudo ./bin/installdependencies.sh`.
12. **ORACLE 11 — enregistrement.** Jeton valable 1 h lu par `read -rsp`, jamais écrit ni collé dans une conversation, `unset T` après : `./config.sh --unattended --url https://github.com/maratech-fr/amateo --token "$T" --name amateo-ci-N --labels <labels> --work _work`. Labels : runners 1 et 2 → `amateo,amateo-heavy` ; runner 3 → `amateo`. Preuve : trois runners « Offline » aux bons labels (Settings → Actions → Runners).
13. **ORACLE 12 — hooks.** `job-started.sh` et `job-completed.sh` de `.github/runner/` copiés dans `/opt/amateo-ci/hooks/` (root, 755) ; dans chaque `/opt/actions-runner-N/.env`, les quatre clés de `.github/runner/runner.env.example` (deux hooks, `DOCKER_CONFIG`, `HOME` propres au runner, dossier `HOME` créé et `chown runner:runner`). Preuve : « A job started hook has been configured » au premier job ; le step `Isolate the Docker credentials of this job` imprime `HOME=/opt/actions-runner-N/home`.
14. **ORACLE 13 — services.** `sudo ./svc.sh install runner && sudo ./svc.sh start` ×3 (à relancer après tout changement du `.env`). Preuve : `systemctl is-active actions.runner.maratech-fr-amateo.amateo-ci-N.service` ×3, trois runners « Idle ».
15. **Environnement GitHub `release`** : politique « Selected branches » = **`main` seule** (suffisant même pour un hotfix tagué sur `release` : `scripts/deploy.sh` et le backend dispatchent `deploy.yml` sur `main`, qui checkoute le tag). Secret d'environnement `RELEASE_BRANCH_TOKEN` = PAT fine-grained, dépôt `maratech-fr/amateo` seul, `Contents` et `Workflows` en lecture/écriture, expiration 1 an (date : `docs/ops/deploy.md` § *Branche `release`*). Preuve : `gh api repos/maratech-fr/amateo/environments/release/secrets --jq '.secrets[].name'`. Au passage : supprimer l'environnement vide `ENV_GPG_PASSPHRASE` (résidu d'un rail retiré).

### Pendant la PR

16. **« Fork pull request workflows from outside collaborators » → « Require approval for all external contributors »** (Settings → Actions → General). Seul garde réel contre un fork (un fork exécute SA version du YAML) ; sans API sur un dépôt public. Preuve : capture de la page.
17. **Re-baseline des captures dans le conteneur** (variables pas encore posées) : `gh workflow run visual-baselines.yml --ref <branche> -f update=true`, `gh run download <id> -n visual-baselines`, commit de `frontend/tests/e2e/visual-reference.spec.ts-snapshots/`.
18. **ORACLE 18 — sonde manuelle d'étanchéité**, depuis le shell de la VM en `runner`, stack de dev ALLUMÉE (`make start`, `make -C frontend dev`). Doivent ÉCHOUER : `curl -m5` sur `10.250.0.1:5173`, `10.250.0.1:8080`, `<IP LAN>:8080`, `<IP WSL>:8080` ; `nc -zvw3 10.250.0.1 445` et `135` ; `ls /mnt/c`. Doivent RÉUSSIR : `curl -sSI https://github.com`, `https://ghcr.io/v2/` (401 = joignable), `docker pull alpine:3`. Aller-retour : `Disable-NetFirewallRule -DisplayName "amateo-ci*"` → `nc 445` réussit → `Enable-NetFirewallRule` → rééchoue.
19. **Variables de dépôt** `CI_RUNS_ON=amateo` et `CI_RUNS_ON_HEAVY=amateo-heavy`, puis `gh run rerun` du dernier run de la PR. Preuve : `gh api repos/maratech-fr/amateo/actions/variables --jq '.variables[].name'` ; les jobs affichent « amateo-ci-N » comme runner.
20. **Deux runs complets verts sur le runner**, avec quatre preuves : (a) après le second, `docker ps -a`, `docker volume ls`, `docker compose ls` sans projet `ci-*` ni tag `scan-*` ; (b) `docker stats --no-stream` et `free -h` quand `e2e`, `backend-tests` et `blocking-tests` tournent ensemble (pire trio) ; (c) sonde perf : clone jetable de la branche dans `/home/runner/perf-probe`, `.env` depuis `.env.dist` + `USER_ID`/`GROUP_ID`, `COMPOSE_FILE=docker-compose.yml:docker-compose.test.yml`, `docker compose -p perf-probe run --rm engine pytest -m perf --durations=0`, puis `down -v --rmi local --remove-orphans` et suppression du clone — si `test_bccl_nominal_solve_stays_in_main_order` dépasse la moitié de son budget (10 s sur 20 s), `engine-perf` repasse sur le littéral `ubuntu-24.04` et la question remonte au fondateur ; (d) le step `Isolate the Docker credentials of this job` montre `HOME=/opt/actions-runner-N/home`.

### Après le merge

21. **Sonde en workflow**, rejouable, depuis `main`, stack de dev allumée, AVANT le geste 22 : `gh workflow run ci-runner-isolation.yml -f host_ip=10.250.0.1 -f lan_ip=<IP LAN> -f wsl_ip=<IP WSL>`. Les IP ne vont jamais dans le dépôt. Un rouge nomme la cible jointe à tort. À rejouer après tout changement réseau du poste ou de la VM.
22. **Protection de `main`** : recompter `gh api repos/maratech-fr/amateo/branches/main/protection --jq '.required_status_checks.contexts'` ; `gh api -X PATCH …/protection/required_status_checks -F strict=true` avec les contextes existants + « Preflight » ; puis `gh api -X POST …/protection/enforce_admins`. Preuve : relecture = `strict: true`, Preflight présent, `enforce_admins.enabled: true`.
23. **`%UserProfile%\.wslconfig`** : `[wsl2]` `memory=14GB`, puis `wsl --shutdown`. Preuve : `free -g` dans WSL ≈ 14.
24. **ORACLE 14-17 — exploitation** (§8) : timer systemd hebdomadaire `docker builder prune -f --keep-storage 20G` ; `Optimize-VHD -Path <vhdx> -Mode Full` mensuel VM éteinte ; raccourcis `Start-VM amateo-ci` / `Stop-VM amateo-ci` ; veille « Jamais » sur secteur ; `timedatectl` synchronized et service d'intégration « Synchronisation date/heure » actif. **Règle d'usage** : après le dernier merge de la journée, attendre la fin du run de `main` avant `Stop-VM` ; jamais d'arrêt pendant un job.
25. **Mesure à J+10** : minutes hébergées (Settings → Billing → Actions) ≈ `deploy` + `security-weekly` seulement ; attente moyenne par PR sur dix runs (`started_at`/`completed_at` via `gh api …/runs/<id>/jobs`) ; durée du run de `main`.

## 6. VM éteinte

Vaut tant que `CI_RUNS_ON` / `CI_RUNS_ON_HEAVY` pointent vers la VM. Les checks restent « Queued — Waiting for a runner… » ; à l'allumage la file se vide. Au-delà de
24 h, GitHub rougit le job (« not acquired within 24 hours ») : « Re-run failed jobs ».

Le run de `main` non conclu rend `isCiGreen` faux : programmer un déploiement depuis la console est
refusé (422), et la bascule à l'heure H re-vérifie. Remède : allumer la VM, `gh run rerun`. Un
déploiement programmé la nuit VM éteinte **fonctionne** si le run de `main` est déjà conclu
(`deploy.yml` s'exécute chez GitHub) — d'où la règle du geste 24.

PR Dependabot mensuelles : après le merge de la première, « Update branch » (ou `@dependabot rebase`)
sur les autres, à enchaîner VM allumée.

## 7. Sécurité tant que le dépôt est public

- Geste 16 (approbation obligatoire des contributeurs externes) = le garde réel ; le job `Preflight`
  (step `Refuse pull requests from forks`) est la ceinture : une PR de fork approuvée par erreur rougit
  Preflight, tous les autres jobs sont sautés, le merge est bloqué.
- Dependabot ouvre ses PR depuis le dépôt : il passe.
- Le jeton d'enregistrement ne s'écrit jamais ; `.credentials` du runner = identité du runner (le
  VHDX se protège comme le poste) ; `RELEASE_BRANCH_TOKEN` reste confiné à l'environnement
  `release` ; aucun identifiant Docker ne survit à un job (`DOCKER_CONFIG` par job).
- Réseau : NAT interne + pare-feu entrant coupent VM → hôte ; VM → LAN reste ouvert (accepté tant
  que les runs sont ceux du dépôt). Sondes : gestes 18 et 21.
- Passage du dépôt en privé : en plan Free, plus de protection de branche — la règle de merge du
  §4 tient lieu de garde (suite : `specs/evolution/roadmap.md`).

## 8. Exploitation

- **Hooks** (`.github/runner/`, copiés dans `/opt/amateo-ci/hooks/`) : `set -u` sans `-e`, `trap 'exit 0' EXIT`,
  chaque geste Docker suffixé `|| true` — un hook qui sort non nul ferait échouer le JOB.
  `job-completed.sh` rejoue le `down -v --rmi local --remove-orphans` du projet du job, retire ses tags
  `scan-*`, puis rend le workspace et `RUNNER_TEMP` à l'uid du service par un conteneur `alpine:3`
  (le service tourne sous un uid non root, un `chown` direct échouerait). `job-started.sh` rattrape un
  job tué : projets `ci-*`, volumes et réseaux orphelins et tags `scan-*` trop anciens, mêmes `chown`.
- **`docker-daemon.json`** : rotation des journaux, plage d'adresses des réseaux compose
  (`10.251.0.0/16`, /24), garbage-collect du cache de build.
- **Disque** : le VHDX dynamique ne rétrécit jamais seul — `Optimize-VHD -Mode Full` mensuel VM éteinte.
- **Mémoire** : borne = la VM (10 Go max, swap 4 Go). Un OOM tue un processus d'un job (`exit 137`), pas
  la stack de dev. Pas de limite par service tant qu'aucun OOM n'est observé (`specs/evolution/roadmap.md`).
- **Arrêt de la VM** : manuel par raccourci (règle du geste 24).
- **Horloge** : une dérive se voit en jobs « Queued » ; un job en cours pendant une veille est perdu.
- **Docker Hub** : quota anonyme par IP — le login conditionnel est conservé, par job.

Après tout changement du `.env` d'un runner : relancer son service. Après une modification des hooks
dans le dépôt : les recopier dans la VM (le runner ne lit pas le dépôt pour eux).

## 9. Branche de lot

> **Maison unique** du régime de CI par branche cible et des gestes d'une branche de lot. Un lot se
> merge PR après PR (squash) dans `lot/<sujet>` ; à la fin, UNE PR `lot/<sujet>` → `main` rejoue le
> gate complet. Décision et raison : `specs/courantes/etat-des-lieux.md` §2 (CI : branche de lot).

### Les régimes (`ci.yml`, tri par `github.base_ref`, jamais par dossier)

| Régime | Condition | Jobs |
|---|---|---|
| toujours | aucune | `preflight`, `lint`, `secrets-scan`, `semgrep`, `engine-tests` |
| toute PR | `pull_request` | `phpstan`, `rector`, `blocking-tests`, `frontend`, `engine-semantics`, `engine-perf-pr` |
| PR vers `main` | `pull_request` ET base `main` | `backend-tests`, `e2e`, `functional-tests` |
| cible `main` | pas une PR, OU base `main` | `dependency-audit`, `build-docker` |
| push `main` | réf `main` | `engine-perf` |

- **PR vers `main`** : tout sauf `engine-perf`. **PR vers `lot/**`** : « toujours » + « toute PR » ;
  les cinq jobs lourds (`backend-tests`, `e2e`, `functional-tests`, `dependency-audit`,
  `build-docker`) et `engine-perf` sont sautés. **Push `main`** : inchangé (`isCiGreen`). **Push
  `lot/**`** : aucun run.
- **Un job sauté compte comme RÉUSSI pour un required check.** D'où des listes fermées et des `if`
  exacts, gardés par `CiWorkflowRegimeTest` ; d'où aussi la règle N2 ci-dessous.
- **`edited`** fait partie des types de `pull_request` : changer la base d'une PR (`lot/<sujet>` →
  `main`) relance un run sous le régime de la base COURANTE. Aucun `if` ne lit `github.event.action`.
  Coût : éditer titre ou corps relance le run, et la concurrence annule le run en cours ; un run
  annulé ne compte pas comme réussi. **Titre et corps se posent à `gh pr create`, pas d'édition
  pendant un run** (Dependabot réédite le corps de ses PR : coût en runs, pas de risque).
- **Preflight** : sa garde fork couvre les PR vers `lot/**` comme vers `main`.
- **Ce que le test ne prouve pas** (docblock de `CiWorkflowRegimeTest`) : la sémantique GitHub
  « sauté = réussi » ; le changement de base et les deux PR d'une même tête (gestes ci-dessous) ;
  les rulesets ; la concordance des constantes de checks avec la protection et les rulesets réels ;
  qu'un check requis de `main` soit satisfait par un run léger.

### Règle N2 — avant tout GO ou merge d'une PR vers `main`

Les 15 contextes requis de `main` portent tous l'`app_id` de GitHub Actions : un run **léger** les
satisfait donc tous (les lourds « skipped » comptent comme réussis), et `strict` ne protège pas (une
branche issue d'un lot fraîchement rebasé est à jour). Règle : on **lit la sortie complète de
`gh pr checks <n>`** ; **une seule ligne « skipping » est admise, « Engine Perf Gate (main only) »**
(pas un contexte requis, pas plus que « Engine Perf (PR, dense only) ») ; toute autre bloque le GO.
Le **code retour ne compte pas** : `gh pr checks` sort à 0 avec des « skipping ». Variante mécanique :
`gh pr checks <n> --required --json name,bucket`, zéro entrée `skipping`. La commande figure dans le
résumé de GO de l'orchestrateur. Remède si un « skipping » apparaît : fermer ou recibler toute autre
PR partie de la même tête, puis `gh run rerun` CIBLÉ du run PR→`main` ; un commit vide n'est admis
que si la tête ne porte qu'UNE PR (sinon il relance les deux et recrée la course).

### Rulesets de `lot/**` (hors dépôt)

À poser après le merge de la PR qui introduit ce régime, par l'orchestrateur (`gh api -X POST
repos/{owner}/{repo}/rulesets`, `integration_id` de l'app GitHub Actions relu dans la protection de
`main`) ; relire l'état réel avec `gh api repos/{owner}/{repo}/rulesets`.

- **`lot-gate`** : cible `refs/heads/lot/**` ; règle `required_status_checks` sur les checks légers
  (`LOT_REQUIRED_CHECKS` de `CiWorkflowRegimeTest` : copie, à relire contre le ruleset),
  `strict_required_status_checks_policy`, `do_not_enforce_on_create` ; **pas** de règle
  `non_fast_forward` (le force-push du rebase final est voulu). « Engine Perf (PR, dense only) »
  n'est pas requis, miroir de `main`.
- **`lot-no-delete`** : règle `deletion`, bypass admin. Sans elle la branche d'un lot serait
  indestructible ; avec elle, `delete_branch_on_merge` échoue sur `lot/<sujet>` au merge final :
  **la suppression de la branche de lot est un geste manuel admin** (`git push origin --delete
  lot/<sujet>`, puis `git branch -D`).

### Ordre des gestes d'exploitation (après le merge, sur une branche `lot/essai`)

1. **B2 en premier** : `lot-gate` SANS bypass ; `lot/essai` depuis `main` ; commit trivial, rebase,
   `git push --force-with-lease` — un refus est possible (un `required_status_checks` de ruleset
   peut s'appliquer aux push directs : les commits rebasés, nouveaux SHA sans run, seraient
   refusés). Si refus : ajouter le bypass admin à `lot-gate` et rejouer.
2. **Héritage du bypass** : si `lot-gate` porte un bypass admin, les agents poussent et mergent avec
   l'identité GitHub du fondateur et en héritent. **Jamais `--admin`, jamais le bouton bypass ;
   `gh pr checks <n> --watch` vert AVANT `gh pr merge --squash`.** À prouver sur `lot/essai` : un
   check en attente bloque-t-il `gh pr merge --squash` sans `--admin` pour un acteur bypass ? Sinon
   la discipline porte seule le gate du lot.
3. `lot-no-delete` ; vérifier que `git push origin --delete lot/essai` est refusé sans bypass.
4. PR d'essai vers `lot/essai` : régime léger (cinq lourds sautés, checks requis de `lot-gate`
   présents) ; un check en attente bloque le merge.
5. **B1** : PR d'essai vers `lot/essai`, run léger, changer sa base vers `main` (« Edit ») ; un nouveau
   run du gate complet, lourds exécutés, est attendu ; fermer sans merger.
6. **M1** (ci-dessous) : à rejouer et consigner.
7. Merge (squash) d'une PR d'essai dans `lot/essai` (aucun run sur ce push) ; rebase +
   `--force-with-lease` ; PR `lot/essai` → `main` : gate complet ; **la fermer sans merger**
   (décision fondateur 2026-11-10).
8. Mesure du critère de durée (ci-dessous). Suppression de `lot/essai` (bypass admin). Première
   vraie branche de lot : worktrees des agents depuis `origin/lot/<sujet>`, PR ouvertes avec
   `--base lot/<sujet>`.

### Critère de durée (décision fondateur 2026-11-10 ; mesure APRÈS le merge, pas encore faite)

Une PR de lot doit durer **≤ 10 min murales ET ≤ 50 % du run PR→`main`**, les deux mesurés sur **LE
MÊME pool de runners** (la mesure nomme la valeur de `CI_RUNS_ON` du moment) : 5 runs PR→lot, et un
run PR→`main` de référence (geste 7). Au-dessus, retour au fondateur avant la première vraie branche
de lot. Un run mesuré sur un autre pool (VM ou runners GitHub) ne vaut pas pour celui-ci.

### Règles de geste

- **M1 — jamais deux PR ouvertes depuis la même tête** (une PR→lot et une PR→`main`), **jamais
  « Re-run all jobs » sur un run PR→lot dont la tête porte une PR→`main`** : le run léger le plus
  récent pourrait remplacer les check runs lourds de la PR→`main`. La règle N2 en est le filet.
- **Faute de frappe de base** (`lots/x` au lieu de `lot/x`) : aucun run, bouton de merge vert. Non
  testable. L'orchestrateur ouvre avec `--base lot/<sujet>` et **vérifie que les checks
  apparaissent**.
- **Worktree parti de `main`** au lieu de `origin/lot/<sujet>` : la PR→lot reste saine (`strict`),
  mais on repart du lot. **Branche du lot ouverte vers `main` par erreur** : la refuser ou la
  recibler (`edited` rejoue alors le régime de la base courante).
- **Rebase final** : `lot/<sujet>` rebasée sur `main` à jour, `git push --force-with-lease`, quand
  aucune PR vers le lot n'est ouverte. **Jamais « Update branch »** (il crée un merge commit dans le
  lot). Si `main` avance, re-rebase. Les PR Dependabot visent `main` : le rebase final les ramène.
- **Merge de la PR finale** : merge commit (jamais squash), titre posé par `gh pr merge --merge
  --subject "(IA Claude) <titre> (#N)"`. On tague ensuite le merge commit ou un commit de `main`
  (`docs/ops/deploy.md`).
- **Fin de lot** : `RoadmapIdentityTest` (dans `backend-tests`) ne tourne plus qu'à la PR finale ;
  un rouge de fin de lot se diagnostique par `git bisect` entre `merge-base lot main` et la tête du
  lot (`CLAUDE.md` §7 étape 5). Un correctif urgent passe par une PR vers `release`
  (`docs/ops/deploy.md`) puis report sur `main` ; le rebase final le ramène dans le lot.
