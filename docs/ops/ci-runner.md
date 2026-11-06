# Le runner de CI — VM Hyper-V `amateo-ci`

> **Maison unique** de la mise en place et de l'exploitation du runner GitHub Actions
> auto-hébergé. Le graphe des jobs et ce que chacun prouve : `docs/testing/testing-strategy.md` §1.
> La mise en prod qui lit le verdict du run de `main` : `docs/ops/deploy.md`.
> Les « gestes » sont ceux du fondateur (poste Windows + VM, hors dépôt) ; « ORACLE n » renvoie
> à la numérotation du cadrage des gestes Hyper-V.

## 1. Ce que c'est

Les jobs de `.github/workflows/ci.yml` (et `visual-baselines.yml`) tournent sur **trois runners
GitHub Actions non éphémères**, installés dans une VM Hyper-V `amateo-ci` (Ubuntu Server 24.04) du
poste du fondateur, allumée à la demande. Restent chez GitHub : `deploy.yml`, `security-weekly.yml`
et `mirror-images.yml` (runner littéral `ubuntu-24.04`).

**La CI tourne dans la VM du fondateur : VM éteinte = les checks restent « Queued », et GitHub
échoue un job non acquis après 24 h** (voir §6).

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
- **Garde** : `backend/tests/Unit/Documentation/CiWorkflowRegimeTest.php` (routage, `preflight`,
  `main` allégé, isolation, bloquants, e2e en conteneur, hooks, sonde).

## 4. Protection de `main` : `strict`, Preflight, `enforce_admins`

- **15 required checks** : les 14 historiques plus **Preflight** (job sans checkout, qui rougit sur
  une PR dont `head.repo.full_name` n'est pas le dépôt ; tout autre job `needs: preflight`, sans quoi
  un job sauté vaudrait succès).
- **`strict: true`** (branche à jour obligatoire) : avec le squash, l'arbre mergé est celui que la PR
  a prouvé. Une PR en retard se met à jour (« Update branch ») et rejoue le run complet.
- **`enforce_admins: true`** tant que le dépôt est public.
- **`main` allégé** : sur `push main` ne tournent que `preflight`, `lint`, `secrets-scan`,
  `semgrep`, `dependency-audit`, `engine-tests`, `engine-perf` et `build-docker` ; le reste est
  PR-only (`if: github.event_name == 'pull_request'`). Sens de « CI verte » pour un déploiement :
  `docs/ops/deploy.md`.
- **Règle de merge** : jamais de merge par contournement des checks (bouton admin, VM éteinte
  comprise) — on allume la VM et on attend le vert (`CLAUDE.md` §7 Git).

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

Les checks restent « Queued — Waiting for a runner… » ; à l'allumage la file se vide. Au-delà de
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
