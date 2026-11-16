# Le runner de CI — VM Hyper-V `amateo-ci`

> **Maison unique** de la mise en place et de l'exploitation du runner GitHub Actions
> auto-hébergé. Le graphe des jobs et ce que chacun prouve : `docs/testing/testing-strategy.md` §1.
> La mise en prod qui lit le verdict de `main` (« Main Verdict ») : `docs/ops/deploy.md`.
> Les « gestes » sont ceux du fondateur (poste Windows + VM, hors dépôt) ; « ORACLE n » renvoie
> à la numérotation du cadrage des gestes Hyper-V.

## 1. Ce que c'est

Le **gate complet** de `.github/workflows/ci.yml` (toute PR vers `main`) et le job de
`visual-baselines.yml` tournent sur le pool que désignent les variables de dépôt `CI_RUNS_ON` /
`CI_RUNS_ON_HEAVY` (§2) : **la VM auto-hébergée une fois les variables posées (geste 19), les
runners GitHub tant qu'elles sont absentes**. Le **gate de lot** (toute PR vers `lot/<sujet>`) et le
**verdict de `main`** sont **hébergés par défaut** (`CI_RUNS_ON_LOT`, `CI_RUNS_ON_VERDICT`) et ne
passent sur la VM que par la réserve de minutes (§9). La VM, c'est **trois runners GitHub Actions non
éphémères**, installés dans une VM Hyper-V `amateo-ci` (Ubuntu Server 24.04) du poste du fondateur,
allumée à la demande. Restent toujours chez GitHub (runner littéral `ubuntu-24.04`) : `deploy.yml`
(les secrets de production ne touchent jamais la VM), `security-weekly.yml`, `mirror-images.yml` et
`cache-warm.yml`.

**VM éteinte = les checks du gate complet restent « Queued », et GitHub échoue un job non acquis
après 24 h (voir §6) — seulement tant que les variables pointent vers la VM.** Les §3, §5 (gestes de
mise en place), §6 et §8 décrivent la VM.

Le dépôt étant public, le runner ne sert que les PR du dépôt lui-même (§7). La VM est la borne
de ressources (mémoire, vCPU) ; il n'y a ni cgroup ni limite mémoire par conteneur côté dépôt.

## 2. Routage et labels

| Variable de dépôt | Valeur sur la VM | Label du runner | Jobs |
|---|---|---|---|
| `CI_RUNS_ON` | `amateo-light` | `amateo-light` (runner 3 seul) | les jobs « légers » du gate complet : `preflight`, `lint`, `secrets-scan`, `semgrep`, `dependency-audit`, `phpstan`, `rector`, `blocking-tests`, `engine-tests`, `engine-perf`, `engine-semantics` |
| `CI_RUNS_ON_HEAVY` | `amateo-heavy` | `amateo-heavy` (runners 1 et 2) | les jobs « lourds » du gate complet : `frontend`, `backend-tests`, `e2e`, `functional-tests`, `build-docker` ; et le job de `visual-baselines.yml` |
| `CI_RUNS_ON_LOT` | `amateo` (réserve, §9) | `amateo` (les 3 runners) | `lot-gate` (« Lot Gate ») |
| `CI_RUNS_ON_VERDICT` | `amateo` (réserve, §9) | `amateo` (les 3 runners) | `main-verdict` (« Main Verdict ») |

Chaque `runs-on` vaut `${{ vars.CI_RUNS_ON || 'ubuntu-24.04' }}` (ou `…_HEAVY`, `…_LOT`,
`…_VERDICT`) : variable absente = runner hébergé par GitHub. `CI_RUNS_ON_LOT` et `CI_RUNS_ON_VERDICT`
restent absentes tant que la réserve de minutes (§9) ne les pose pas ; `CI_RUNS_ON` et
`CI_RUNS_ON_HEAVY` désignent le pool du gate complet. Le classement par label est une **classe de
lourdeur**, pas un poids mesuré : au plus deux jobs lourds en parallèle, le runner 3 seul pour les
jobs légers. Le label `amateo-light` s'ajoute au runner `amateo-ci-3` (geste 19).

La sonde `ci-runner-isolation.yml` utilise `vars.CI_RUNS_ON` **sans repli** (chez GitHub elle ne
prouverait rien : son premier step refuse tout runner non auto-hébergé). Elle ne tourne donc que sur
le runner 3, point unique des jobs légers ; runner 3 hors ligne :
`gh variable set CI_RUNS_ON --body amateo`.

**Retour arrière « VM en panne »** : supprimer `CI_RUNS_ON` et `CI_RUNS_ON_HEAVY` (Settings → Secrets
and variables → Actions → Variables), et `CI_RUNS_ON_LOT` / `CI_RUNS_ON_VERDICT` si elles sont posées.
Le run suivant part chez GitHub, mêmes conteneurs, mêmes captures de référence : rien à régénérer.
Quand `CI_RUNS_ON` et `CI_RUNS_ON_HEAVY` sont toutes deux absentes, `preflight` l'écrit en
avertissement (« Gate complet sur un runner hébergé ») : ce run consomme des minutes hébergées.
Variante : retirer `CI_RUNS_ON_HEAVY` seule (lourds chez GitHub, le reste dans la VM).

## 3. Régime de la CI sur le runner

- **Isolation entre jobs** (le démon Docker de la VM est persistant et partagé) : tout job compose
  porte `COMPOSE_PROJECT_NAME` = `AMATEO_STACK` = `ci-<run_id>-<run_attempt>-<job>` et un
  `COMPOSE_FILE` à chemins absolus (`docker-compose.yml` + `docker-compose.test.yml`, qui ne publie
  aucun port). Le step `Isolate the Docker credentials of this job`, avant tout login Docker (premier
  step, ou juste après le checkout ; troisième dans `lot-gate`, après la garde fork et le checkout),
  redirige `DOCKER_CONFIG` vers `$RUNNER_TEMP`. `build-docker` tague ses images
  `scan-<run>-<attempt>/…`.
- **Nettoyage** : dernier step `ci-stack-down` (`.github/actions/ci-stack-down`, `if: always()`, ne
  rougit jamais) ; doublé par les hooks du runner (§8).
- **Image php** : `.github/actions/build-php-cached` suit `runner.environment` — `docker compose
  build` (cache du démon) sur le runner, buildx + cache GHA (scope `ci-php`) chez GitHub. Ce cache
  GHA, que lit `Lot Gate` quand il est hébergé, est réécrit chaque jour à 04:00 UTC depuis `main` par
  `.github/workflows/cache-warm.yml` (« Cache Warm », `schedule` et `workflow_dispatch`, runner
  littéral `ubuntu-24.04`) : sans lui, plus aucun run n'entretient le cache lu par les PR de lot. Après
  un changement du Dockerfile, le premier run de chaque PR de lot paie un build à froid jusqu'au
  réchauffage suivant ; un lot qui modifie lui-même le Dockerfile le paie au premier run de chacune de
  ses PR, jusqu'à son merge. La VM, elle, s'appuie sur le cache de couches de son démon.
- **e2e** : dans le conteneur Playwright du service compose `e2e` (Vite dans `frontend-dev`,
  mailpit dans le projet), plus d'installation de Chromium ni de Node sur l'hôte. Les PNG de
  référence dépendent de l'image, pas du poste.
- **Bloquants** : une seule invocation PHPUnit (`--group phase1`, step `Blocking suite`) plus le
  step filtré `ParcImportCorrectionTenantNR` — détail : `docs/testing/blocking-tests.md`. `Lot Gate`
  rejoue ces deux steps par alias des ancres YAML `&blocking_suite_run` et `&filtered_tenant_nr_run`
  de `blocking-tests` (§9) : le contenu de ces steps n'a qu'une maison.
- **Dependabot** : mensuel (`.github/dependabot.yml`), les cinq dossiers Dockerfile en une entrée
  `docker` groupée.
- **Garde** : `backend/tests/Unit/Documentation/CiWorkflowRegimeTest.php` (routage, `preflight` —
  PR vers `main` seulement ; `Lot Gate` porte sa propre garde fork en premier step —, les trois
  régimes, contenu et ordre des steps de `Lot Gate`, `Main Verdict`, isolation, bloquants, e2e en
  conteneur, hooks, sonde) ; la logique du verdict : `scripts/ci-verdict-test.py` (step
  `ci-verdict self-test` du job `lint`).

## 4. Protection de `main` : `strict`, Preflight, `enforce_admins`

- **15 required checks** : les 14 historiques plus **Preflight** (job sans checkout, qui rougit sur
  une PR dont `head.repo.full_name` n'est pas le dépôt ; tout autre job du gate complet
  `needs: preflight`, sans quoi un job sauté vaudrait succès). Ni « Engine Perf Gate », ni
  « Lot Gate », ni « Main Verdict » n'en font partie.
- **`strict: true`** (branche à jour obligatoire) : l'arbre mergé est celui que la PR a prouvé. Une
  PR en retard se met à jour (« Update branch ») et rejoue le run complet — sauf la branche d'un
  lot, qui se rebase (§9). Régime par branche cible : une PR vers `main` joue le gate complet, une
  PR vers `lot/<sujet>` le seul « Lot Gate » (§9).
- **`enforce_admins: true`** tant que le dépôt est public.
- **Le verdict de `main`** : sur `push main` ne tourne que `main-verdict` (« Main Verdict »), qui
  prouve que la PR mergée a joué le gate complet — chaque job hors « Lot Gate » et « Main Verdict »
  réussi (§9). Rien d'autre de `ci.yml` ne tourne sur `main`. Sens de « CI verte » pour un déploiement :
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
19. **Label et variables de dépôt**, VM allumée. Avant ce geste, le premier run de la PR part chez GitHub et prouve le YAML. Ajouter le label `amateo-light` au runner `amateo-ci-3` (Settings → Actions → Runners), puis `gh variable set CI_RUNS_ON --body amateo-light` et `gh variable set CI_RUNS_ON_HEAVY --body amateo-heavy`, puis `gh run rerun` du dernier run de la PR. `CI_RUNS_ON_LOT` et `CI_RUNS_ON_VERDICT` ne se posent pas ici (réserve de minutes, §9). Preuve : `gh api repos/maratech-fr/amateo/actions/variables --jq '.variables[].name'` ; les jobs légers affichent « amateo-ci-3 » comme runner, les lourds « amateo-ci-1 » ou « amateo-ci-2 ».
20. **Deux runs complets verts sur la VM** (PR vers `main`), avec cinq preuves : (a) après le second, `docker ps -a`, `docker volume ls`, `docker compose ls` sans projet `ci-*` ni tag `scan-*` ; (b) `docker stats --no-stream` et `free -h` quand deux jobs lourds, `blocking-tests` et `Engine Perf Gate` tournent ensemble (pire cas) — un OOM (`exit 137`) remonte au fondateur ; (c) les durées de `Engine Perf Gate` (`--durations=0`, dans son log) : si `test_bccl_nominal_solve_stays_in_main_order` dépasse la moitié de son budget (10 s sur 20 s), la question remonte au fondateur — `engine-perf` ne repasse sur le littéral `ubuntu-24.04` que sur sa décision ; (d) le step `Isolate the Docker credentials of this job` montre `HOME=/opt/actions-runner-N/home` ; (e) le temps mural de chacun des deux runs.
21. **Ruleset `lot-gate` réduit au seul contexte « Lot Gate »**, AVANT le GO de merge de la PR (un ruleset qui garde ses anciens contextes est satisfait par des jobs sautés : le gate de lot devient décoratif). D'abord sauvegarder l'état, hors dépôt : `gh api repos/maratech-fr/amateo/rulesets/26933899 > ruleset-avant.json`. Puis `gh api -X PUT repos/maratech-fr/amateo/rulesets/26933899 --input ruleset.json`, où `ruleset.json` est ce même ruleset dont la règle `required_status_checks` ne garde que « Lot Gate » (même `integration_id`). Preuve : relecture du ruleset, un seul contexte. **Retour arrière** (revert de la PR) : remettre `ruleset-avant.json` par le même `PUT` — sans ce geste, chaque PR de lot attend « Lot Gate » pour toujours. Tout lot ouvert avant le merge se rebase sur `main`.

### Après le merge

22. **Sonde en workflow**, rejouable, depuis `main`, stack de dev allumée, AVANT le geste 23 : `gh workflow run ci-runner-isolation.yml -f host_ip=10.250.0.1 -f lan_ip=<IP LAN> -f wsl_ip=<IP WSL>`. Elle part sur `CI_RUNS_ON`, donc sur le runner 3. Les IP ne vont jamais dans le dépôt. Un rouge nomme la cible jointe à tort. À rejouer après tout changement réseau du poste ou de la VM.
23. **Protection de `main`** : recompter `gh api repos/maratech-fr/amateo/branches/main/protection --jq '.required_status_checks.contexts'` ; `gh api -X PATCH …/protection/required_status_checks -F strict=true` avec les contextes existants + « Preflight » ; puis `gh api -X POST …/protection/enforce_admins`. Preuve : relecture = `strict: true`, Preflight présent, `enforce_admins.enabled: true`.
24. **`%UserProfile%\.wslconfig`** : `[wsl2]` `memory=14GB`, puis `wsl --shutdown`. Preuve : `free -g` dans WSL ≈ 14.
25. **ORACLE 14-17 — exploitation** (§8) : timer systemd hebdomadaire `docker builder prune -f --keep-storage 20G` ; `Optimize-VHD -Path <vhdx> -Mode Full` mensuel VM éteinte ; raccourcis `Start-VM amateo-ci` / `Stop-VM amateo-ci` ; veille « Jamais » sur secteur ; `timedatectl` synchronized et service d'intégration « Synchronisation date/heure » actif. **Règle d'usage** : jamais d'arrêt pendant un job ; tant que `CI_RUNS_ON_VERDICT` pointe vers la VM (réserve, §9), après le dernier merge de la journée attendre aussi la fin du job « Main Verdict » avant `Stop-VM`.
26. **Mesure à J+10** : minutes hébergées (Settings → Billing → Actions) = les postes fixes (« Main Verdict », `cache-warm.yml`, `deploy.yml`, `security-weekly.yml`) plus les PR de lot ; attente moyenne par PR sur dix runs (`started_at`/`completed_at` via `gh api …/runs/<id>/jobs`) ; temps mural d'un run PR→`main` seul sur la VM (cible : médiane ≤ 24 min sur dix runs).
27. **En régime : la réserve de minutes** (dépôt privé) — lecture de la page de facturation et variables `CI_RUNS_ON_LOT` / `CI_RUNS_ON_VERDICT` : §9.

## 6. VM éteinte

Vaut pour les jobs dont la variable de routage pointe vers la VM : le gate complet (`CI_RUNS_ON`,
`CI_RUNS_ON_HEAVY`) ; le gate de lot et le verdict de `main` seulement une fois que la réserve de
minutes (§9) a posé `CI_RUNS_ON_LOT` / `CI_RUNS_ON_VERDICT`. Les checks restent « Queued — Waiting
for a runner… » ; à l'allumage la file se vide. Au-delà de 24 h, GitHub rougit le job (« not acquired
within 24 hours ») : « Re-run failed jobs ».

Le verdict de `main` (« Main Verdict ») est hébergé par défaut : un déploiement programmé la nuit VM
éteinte **fonctionne** (le verdict est conclu chez GitHub, `deploy.yml` s'y exécute aussi). Une fois
`CI_RUNS_ON_VERDICT` posée, le verdict attend l'allumage de la VM : `isCiGreen` reste faux,
programmer un déploiement depuis la console est refusé (422) et la bascule à l'heure H re-vérifie ;
GitHub annule le job resté en file plus de 24 h, et la console refuse alors le tag. Remède : allumer
la VM puis relancer le job (« Re-run jobs », ou `gh run rerun`) — le run garde son identifiant. D'où
la règle du geste 25.

PR Dependabot mensuelles : après le merge de la première, « Update branch » (ou `@dependabot rebase`)
sur les autres, à enchaîner VM allumée.

## 7. Sécurité tant que le dépôt est public

- Geste 16 (approbation obligatoire des contributeurs externes) = le garde réel ; la ceinture est le
  step `Refuse pull requests from forks`. Pour une PR vers `main`, c'est le job `Preflight` : une PR
  de fork approuvée par erreur le rougit, tous les autres jobs sont sautés, le merge est bloqué. Pour
  une PR vers `lot/**`, `preflight` n'existe pas : la garde est le premier step de `Lot Gate`, avant
  tout checkout, et le ruleset bloque le merge. Le verdict ne tourne que sur `push main`, qu'un fork
  ne peut pas faire.
- Dependabot ouvre ses PR depuis le dépôt : il passe.
- Le jeton d'enregistrement ne s'écrit jamais ; `.credentials` du runner = identité du runner (le
  VHDX se protège comme le poste) ; `RELEASE_BRANCH_TOKEN` reste confiné à l'environnement
  `release` ; aucun identifiant Docker ne survit à un job (`DOCKER_CONFIG` par job).
- Réseau : NAT interne + pare-feu entrant coupent VM → hôte ; VM → LAN reste ouvert (accepté tant
  que les runs sont ceux du dépôt). Sondes : gestes 18 et 22.
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
- **Arrêt de la VM** : manuel par raccourci (règle du geste 25).
- **Horloge** : une dérive se voit en jobs « Queued » ; un job en cours pendant une veille est perdu.
- **Docker Hub** : quota anonyme par IP — le login conditionnel est conservé, par job.
- **Relevé des jobs rouges** : `make ci-releve` (`DAYS=14` par défaut ; `ARGS="--since AAAA-MM-JJ
  --until AAAA-MM-JJ --branch B --json CHEMIN --no-cache"`) dit POURQUOI chaque job rouge de `ci.yml`
  l'a été, sans ouvrir un log. Il lit l'API GitHub par le `gh` du poste (lecture seule, jamais Docker,
  donc lançable depuis toute copie, worktree nu compris) ; code retour 0, 2 si `gh` est absent ou non
  authentifié ou si Python < 3.10. Six causes : vrai bug, instable, infra, externe-CVE, Dependabot,
  inconnu. **Les règles de classement, leurs précédences, la découpe de la section du step échoué et
  l'assainissement des lignes : docstring de `classify` et de `step_section` dans
  `scripts/ci-releve.py` — maison unique, rien n'est recopié ici.** Il ne lit jamais que la section du
  step échoué (ni le step voisin, ni le corps recopié du `run:`) ; toute ligne portant un mot sensible
  est supprimée ; le cache `~/.cache/amateo/ci-releve/jobs/<id>.json` ne garde que le verdict, jamais
  le log. Rétention : logs GitHub 90 jours (au-delà, classement par la famille du step, « log
  expiré »), traces Playwright 7 jours. Il classe aussi les steps de `Lot Gate` et de `Main Verdict`
  (table `STEP_FAMILIES`). Qui le lance : l'orchestrateur à chaque bilan de lot et avant
  toute phrase « ce job est flaky » ; le fondateur à la demande. Auto-test : step `ci-releve self-test`,
  dernier du job `lint`. **Limites** : (a) « instable » = même `head_sha` : un commit vide ou un rebase
  change le sha et laisse « vrai bug » (relancer le job : `gh run rerun`) ; sur une PR le `head_sha` est
  celui de la branche alors que le run teste le commit de fusion, donc « instable » peut être faux si
  `main` a bougé ; (b) un step INFRA cassé par un bug du YAML de CI reste « infra » ; (c) la colonne PR
  reste « — » pour une PR fermée ou mergée (l'API renvoie `pull_requests: []`) ; (d) les signatures
  RUNNER et réseau/disque ne sont prouvées que par des cas synthétiques (aucun log réel) ; (e) un job
  tué par délai n'a jamais été observé : on ignore si son step en cours passe en `cancelled` ; (f) un
  Rector qui plante (`[ERROR] Could not process … file`) tombe en « step … exit code 1 (identifiant non
  lu) » : repli conforme, pas d'extracteur ; (g) la sortie peut être recopiée dans la roadmap ou l'état
  des lieux (publics) : l'assainissement vaut aussi pour cela.

Après tout changement du `.env` d'un runner : relancer son service. Après une modification des hooks
dans le dépôt : les recopier dans la VM (le runner ne lit pas le dépôt pour eux).

## 9. Branche de lot

> **Maison unique** du régime de CI (trois régimes), du gate de lot, du verdict de `main` et des
> gestes d'une branche de lot. Un lot se merge PR après PR (squash) dans `lot/<sujet>` ; à la fin,
> UNE PR `lot/<sujet>` → `main` rejoue le gate complet. Décision et raison :
> `specs/courantes/etat-des-lieux.md` §2 (CI : trois régimes).

### Les régimes (`ci.yml`, tri par l'événement et par `github.base_ref`, jamais par dossier)

| Régime | Condition (`if`) | Jobs | Pool (variable, §2) |
|---|---|---|---|
| PR vers `main` (PR finale d'un lot comprise) | `pull_request` ET base `main` | le gate complet : les 15 checks requis de `main` (§4) et `engine-perf` (« Engine Perf Gate », non requis) | `CI_RUNS_ON` / `CI_RUNS_ON_HEAVY` |
| PR vers `lot/<sujet>` | `pull_request` ET base différente de `main` | `lot-gate` (« Lot Gate »), seul job | `CI_RUNS_ON_LOT`, hébergé par défaut |
| push `main` | `push` | `main-verdict` (« Main Verdict »), seul job | `CI_RUNS_ON_VERDICT`, hébergé par défaut |
| push `lot/**` | — | aucun run | — |
| quotidien | `schedule` de `cache-warm.yml` | « Cache Warm » | `ubuntu-24.04` littéral |

- **`dependency-audit` et `build-docker` ne tournent plus qu'en PR vers `main`** : ni sur une PR de
  lot (la PR finale les joue), ni sur `push main`.
- **Un seul job de perf, « Engine Perf Gate »** (`engine-perf`) : en PR vers `main`, `needs:
  [preflight, engine-tests]`, tous les tests `-m perf` (`pytest -m perf --durations=0`, aucun `-k`,
  aucun filtre par chemin). Non requis au merge, mais le verdict de `main` le compte.
- **Un job sauté compte comme RÉUSSI pour un required check.** D'où des listes fermées et des `if`
  exacts, gardés par `CiWorkflowRegimeTest` ; d'où aussi la règle N2 ci-dessous et le verdict de
  `main`.
- **`edited`** fait partie des types de `pull_request` : changer la base d'une PR (`lot/<sujet>` →
  `main`) relance un run sous le régime de la base COURANTE. Aucun `if` ne lit `github.event.action`.
  Coût : éditer titre ou corps relance un run — facturé sur une PR de lot, où « Lot Gate » est
  hébergé — et la concurrence annule le run en cours ; un run annulé ne compte pas comme réussi.
  **Titre et corps se posent à `gh pr create`, pas d'édition pendant un run** (Dependabot réédite le
  corps de ses PR : coût en runs, pas de risque).
- **Garde fork** : `preflight` ne couvre que le gate complet (PR vers `main`) ; `Lot Gate` porte la
  sienne en premier step ; `Main Verdict` n'en a pas besoin (voir son paragraphe, ci-dessous).
- **Ce que le test ne prouve pas** (docblock de `CiWorkflowRegimeTest`) : la sémantique GitHub
  « sauté = réussi » (la logique du verdict : `scripts/ci-verdict-test.py`) ; que GitHub accepte les
  ancres YAML (premier run de la PR) ; le comportement réel de `vitest --changed` et du cache de
  l'image PHP (PR d'essai) ; les rulesets ; la concordance des constantes de checks avec la protection
  et les rulesets réels ; qu'un check requis de `main` soit satisfait par un run qui n'a pas joué le
  gate complet (même tête partagée, run annulé) — la garde est la règle N2 avant le merge, puis le
  verdict.

### Le gate de lot (`lot-gate`, « Lot Gate »)

Seul job d'une PR vers `lot/<sujet>` et seul check requis du ruleset `lot-gate`. Sans `needs`
(`preflight` est sauté dans ce régime, et un `needs` sauté le sauterait), `timeout-minutes: 25`.
Hébergé par défaut ; la réserve de minutes (ci-dessous) le bascule sur la VM.

- **Steps, dans l'ordre** : garde fork (premier step, avant tout checkout) · checkout complet
  (`fetch-depth: 0` : gitleaks lit tout l'historique, `vitest --changed` compare l'arbre à
  `origin/<base>`) · isolation des identifiants Docker, logins Docker Hub et ghcr · gitleaks
  (historique complet) · moteur : build, pytest + couverture + cliquet de couverture du moteur,
  ruff, `ruff format --check`, mypy, bandit, deptry · front, **avant** la stack PHP (sur 2 vCPU,
  Vitest ne partage pas la machine avec elle) : Node, `npm ci`, build (`tsc -b` + vite), gardes
  statiques Vitest (`npx vitest run src/test/ tooling/ .guard.test`, à chaque run : elles lisent les
  sources par grep, hors du graphe d'imports que `--changed` suit), puis Vitest lié au diff
  (`npx vitest run --changed "origin/<base>" --passWithNoTests`) · image PHP (`build-php-cached`),
  services, Composer, PHPStan · clés JWT, base de test · suite bloquante et step filtré · démontage
  de la stack (`always()`).
- **La liste bloquante, par alias** : la suite bloquante et le step filtré sont `&blocking_suite_run`
  et `&filtered_tenant_nr_run`, ancres YAML posées sur les steps de `blocking-tests` et rejouées par
  alias dans `lot-gate` : le contenu de ces deux steps n'a qu'une maison (le job `blocking-tests`,
  liste : `docs/testing/blocking-tests.md`), et chaque fichier qu'on y ajoute se paie aussi en
  minutes de ce job.
- **Trois conditions de step seulement** : la garde fork, le login Docker Hub, le démontage. Le
  premier rouge arrête le job.
- **Vitest lié** : `forceRerunTriggers` de `frontend/vitest.config.ts` ajoute aux déclencheurs par
  défaut de Vitest `package-lock.json`, `tsconfig*.json`, `tooling/**`, `act-warnings-ceiling.json`,
  `coverage-floor.json` et `src/test/setup.ts` : un diff qui ne touche qu'eux joue la suite complète.
  C'est la **seule sélection par contenu du dépôt** (toujours aucun `paths`, aucun job sauté selon
  le diff), et elle reste limitée à ce step.
- **Ce qu'il ne joue pas** : Rector, CS-Fixer et composer-unused, Semgrep, ESLint et knip,
  `engine-semantics` (le groupe `contract` entier contre le vrai moteur : seuls les tests de ce
  groupe qui figurent dans la liste bloquante sont joués), le Vitest complet, le cliquet des
  avertissements `act` (sans verdict sur un run partiel), la couverture backend et front (le cliquet
  du moteur, lui, est joué), `dependency-audit`, `build-docker`, Behat, e2e, la perf. Ce qui le
  rattrape : les commandes locales avant le merge vers le lot (`CLAUDE.md` §7 étape 5), puis la PR
  finale vers `main` ; un rouge de fin de lot se diagnostique par `git bisect` (règles de geste).

### Le verdict de `main` (`main-verdict`, « Main Verdict »)

Seul job d'un `push main`, celui que lit `isCiGreen` (`docs/ops/deploy.md`). Un job sauté vaut
succès pour un check requis : une PR vers `main` dont le run n'a joué qu'une partie du gate (run de
lot d'une tête partagée, run annulé puis remplacé) passerait la protection ; ce job ferme le trou
après le merge (`scripts/ci-verdict.py`, auto-test : step `ci-verdict self-test` du job `lint`).

- **Ce qu'il vérifie** : il retrouve la PR mergée vers `main` dont le commit de merge est ce commit
  (squash d'une PR, ou merge commit de la PR finale d'un lot), lit le run `ci.yml` de PR le plus
  récent de sa tête, et exige que chaque job hors « Lot Gate » et « Main Verdict » ait réussi.
- **Trois codes de retour** : 0 preuve trouvée ; 1 preuve absente (aucune PR mergée ne porte le
  commit, aucun run sur sa tête, un job du gate qui n'a pas réussi, ou aucun job du gate complet
  n'a tourné : pas prouvé) ; 2 GitHub injoignable après trois essais, ou entrée mal formée.
- **Réessai sur liste vide** : une liste de PR ou de runs vide juste après le merge (décalage
  d'indexation de GitHub) est relue six fois, à vingt secondes d'intervalle, avant de conclure.
- **Faux rouge** : relancer le job « Main Verdict » (« Re-run jobs », environ une minute ; le run
  garde son identifiant). **Vrai rouge** (la PR n'a pas joué le gate complet) : on tague le commit
  suivant.
- **Pool** : hébergé par défaut (`CI_RUNS_ON_VERDICT`). Basculé sur la VM, un verdict resté en file
  plus de 24 h est annulé par GitHub : relancer le job (§6).
- **Pas de garde fork, pas de `needs: preflight`** : il ne tourne que sur `push main`, qu'un fork ne
  peut pas faire, et VM éteinte il attendrait sinon `preflight`. Checkout par défaut (le commit
  poussé, jamais une réf de PR), aucun step Docker, aucun secret : seul le jeton du job, en lecture
  (`contents`, `actions`, `pull-requests`).

### Règle N2 — avant tout GO ou merge d'une PR vers `main`

Les 15 contextes requis de `main` portent tous l'`app_id` de GitHub Actions : un run qui n'a pas
joué le gate complet (run de lot d'une tête partagée, run annulé) les satisfait donc tous (les jobs
« skipped » comptent comme réussis), et `strict` ne protège pas (une branche issue d'un lot
fraîchement rebasé est à jour). Règle : on **lit la sortie complète de `gh pr checks <n>`** ;
**deux lignes « skipping » sont admises, « Lot Gate » et « Main Verdict »** (leurs `if` les sautent
sur une PR vers `main`, et ni l'une ni l'autre n'est un contexte requis) ; toute autre bloque le GO.
« Engine Perf Gate » n'est plus sauté : il tourne sur toute PR vers `main`. Le **code retour ne
compte pas** : `gh pr checks` sort à 0 avec des « skipping ». Variante mécanique :
`gh pr checks <n> --required --json name,bucket`, zéro entrée `skipping` (les deux jobs admis n'étant
pas requis, elle n'en voit aucun). La commande figure dans le résumé de GO de l'orchestrateur.
Remède si un autre « skipping » apparaît : fermer ou recibler toute autre PR partie de la même tête,
puis `gh run rerun` CIBLÉ du run PR→`main` ; un commit vide n'est admis que si la tête ne porte
qu'UNE PR (sinon il relance les deux et recrée la course).

### Rulesets de `lot/**` (hors dépôt, POSÉS)

Posés par `gh api -X POST repos/{owner}/{repo}/rulesets` (`integration_id` de l'app GitHub Actions
relu dans la protection de `main`) ; relire l'état réel avec `gh api repos/{owner}/{repo}/rulesets`.
Réduire `lot-gate` au seul « Lot Gate » est le geste 21 du fondateur : un agent ne modifie pas les
réglages du dépôt.

- **`lot-gate`** : cible `refs/heads/lot/**` ; règle `required_status_checks` sur le seul contexte
  « Lot Gate » (`LOT_REQUIRED_CHECKS` de `CiWorkflowRegimeTest` : copie, à relire contre le ruleset),
  `strict_required_status_checks_policy`, `do_not_enforce_on_create` ; **pas** de règle
  `non_fast_forward` (le force-push du rebase final est voulu). **Bypass : rôle admin du dépôt, mode
  `always`** — nécessaire (voir B2 ci-dessous). « Engine Perf Gate » et « Main Verdict » n'y sont pas
  requis.
- **`lot-no-delete`** : règle `deletion`, bypass admin. Sans elle la branche d'un lot serait
  indestructible. **La suppression de la branche de lot est un geste admin** (`git push origin
  --delete lot/<sujet>`, puis `git branch -D`) ; elle fonctionne via le bypass. L'auto-suppression
  par GitHub au merge final (`delete_branch_on_merge`) n'a pas été testée : à vérifier à la première
  vraie branche de lot, sinon geste manuel.

### Preuves

**Faites (2026-11-10, sur une branche et des PR d'essai, supprimées ensuite), indépendantes du
régime de CI** — chiffres et détail : `specs/courantes/etat-des-lieux.md` §3 (2026-11-10).

- **B2** : un push direct d'un commit sur `lot/**` est refusé (« repository rule violations »). Un
  force-push d'un commit à l'arbre identique à celui de `main` passe, mais un rebase qui change le
  contenu est refusé sans bypass : le bypass admin est nécessaire et posé. Un rebase demande
  `git rebase --continue` si un commit devient vide.
- **Héritage du bypass** : les agents poussent et mergent avec l'identité GitHub du fondateur et
  héritent du bypass. Mais `gh pr merge --squash` SANS `--admin` reste refusé tant que les checks sont
  en attente (« the base branch policy prohibits the merge ») : **le gate du lot tient tant que
  `--admin` n'est jamais utilisé. Jamais `--admin`, jamais le bouton bypass ;
  `gh pr checks <n> --watch` vert AVANT `gh pr merge --squash`.**
- **Un squash dans la branche de lot ne lance aucun run.**
- **B1** : changer la base d'une PR de `lot/<sujet>` vers `main` relance un run sous le régime de la
  base courante, et la concurrence annule l'ancien run. **Se fait par l'API REST** (`gh api -X PATCH
  repos/{owner}/{repo}/pulls/<n> -f base=main`) : `gh pr edit --base` échoue sur l'avertissement
  GraphQL « Projects (classic) ».
- **M1** : deux PR depuis la même tête (une vers `main`, une vers le lot) : le run de la PR→lot
  remplace des check runs de même nom de la PR→`main`, dont des contextes requis passent de « pass »
  à « skipping ». Règle M1 et N2 ci-dessous en sont la garde.

**À rejouer avec le régime en trois étages** (orchestrateur, quatre PR d'essai sur `lot/essai-ci`,
avant le GO de la PR du régime) : (1) doc seule, « Lot Gate » seul check, vert ; (2) un fichier front,
`--changed` joue ses tests ; (3) un test bloquant cassé, « Lot Gate » rouge, merge refusé ; (4) base
changée vers `main`, gate complet joué, « Lot Gate » et « Main Verdict » sautés. Le second run d'une
PR de lot montre l'image PHP en moins de 60 s.

**À vérifier à la première vraie branche de lot** : l'auto-suppression au merge final (ci-dessus) et le
comportement avec plusieurs agents en parallèle. Mode d'emploi d'une première branche : worktrees des
agents depuis `origin/lot/<sujet>`, PR ouvertes avec `--base lot/<sujet>`.

### Réserve de minutes (dépôt privé)

Applicable après le passage du dépôt en privé (`specs/evolution/roadmap.md`, P4-392). Le quota
hébergé du plan Team est de 3 000 minutes « Actions Linux » par mois, remis à zéro au jour de
renouvellement du cycle de facturation : jour à lire une fois sur la page de facturation (Settings →
Billing), non relevé tant que le dépôt est public. Consomment ce quota : « Lot Gate » et « Main
Verdict » (hébergés par défaut), `cache-warm.yml`, `deploy.yml`, `security-weekly.yml` et
`mirror-images.yml` ; le gate complet est sur la VM. `deploy.yml` reste chez GitHub : un quota vidé
avant son renouvellement empêche tout déploiement, d'où la réserve. Gestes du fondateur, sur lecture
de l'usage « Actions Linux » depuis le jour de renouvellement :

- **À 75 % du quota (2 250 minutes)** : `gh variable set CI_RUNS_ON_LOT --body amateo`, VM allumée
  (« Lot Gate » en file si elle est éteinte, §6). Preuve : le run de lot suivant affiche
  « amateo-ci-N » comme runner.
- **À 90 % (2 700 minutes)** : `gh variable set CI_RUNS_ON_VERDICT --body amateo`. Le verdict est alors
  sur la VM : règle d'usage du geste 25, annulation après 24 h de file (§6).
- **Au renouvellement** : `gh variable delete CI_RUNS_ON_LOT`, puis
  `gh variable delete CI_RUNS_ON_VERDICT`.

### Critère de durée (décision fondateur 2026-11-15)

- **Gate de lot** : cible 9 minutes facturées par run. Critère : sur 20 runs réussis, médiane
  ≤ 9 minutes et 9ᵉ décile ≤ 12. Valable pour le pool sur lequel le job tourne : **une mesure sur un
  autre pool (runners hébergés d'un dépôt public ou privé, VM) ne vaut pas**, on remesure si
  `CI_RUNS_ON_LOT` ou la visibilité du dépôt change. Critère non tenu : le contenu du gate de lot se
  rediscute avec le fondateur.
- **Gate complet sur la VM** : cible médiane ≤ 24 min pour un run seul, sur dix runs (geste 26).

### Règles de geste

- **M1 — jamais deux PR ouvertes depuis la même tête** (une PR→lot et une PR→`main`), **jamais
  « Re-run all jobs » sur un run PR→lot dont la tête porte une PR→`main`** : le run de lot, qui
  saute tout le gate complet, pourrait remplacer les check runs de la PR→`main`. La règle N2 en est
  le filet avant le merge, « Main Verdict » après.
- **Faute de frappe de base** (`lots/x` au lieu de `lot/x`) : aucun run, bouton de merge vert. Non
  testable. L'orchestrateur ouvre avec `--base lot/<sujet>` et **vérifie que « Lot Gate »
  apparaît**.
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

Essai du Lot Gate : ligne de test, branche jetable.
