.DEFAULT_GOAL := help

FIG := docker compose -f ../docker-compose.yml
APP_ENV ?= $(shell awk -F= '$$1=="APP_ENV" {print $$2}' .env 2>/dev/null | tail -n 1)
APP_ENV ?= dev
USERID := $(shell id -u)
GROUPID := $(shell id -g)
EXEC := $(FIG) exec -e APP_ENV=$(APP_ENV) -u $(USERID):$(GROUPID) engine
RUN := $(FIG) exec -T engine

exec: ## Enter the engine container shell (auto-start if needed)
	@$(FIG) ps engine | grep -q "Up" || ($(FIG) up -d engine && echo "Container started")
	$(EXEC) bash || $(EXEC) sh

install: ## Install Python dependencies — they live in the IMAGE (rebuild), never in the running container
	# Since SEC-15 the container runs as `engine` (uid 1000, no home, site-packages root-only):
	# a `pip install` from inside falls back to a user-site under /home/engine that does not
	# exist → « Permission denied » — and it was pointless anyway: the Dockerfile already
	# installs ".[dev]" and the code comes from the bind-mount (`app` resolves to /app/engine).
	# New or changed dependencies in pyproject.toml → rebuild the image, then recreate.
	$(FIG) build engine
	$(FIG) up -d engine

test: ## Run pytest
	$(RUN) ruff check .
	$(RUN) ruff format --check .
	$(RUN) mypy
	$(RUN) bandit -r app/
	$(RUN) pytest
	$(RUN) pytest --cov=app --cov-report=term-missing

lint: ## Run ruff + mypy
	$(RUN) ruff check .
	$(RUN) ruff format --check .
	$(RUN) mypy
	$(RUN) bandit -r app/

format: ## Format code with ruff (l'arbre est formaté ET gardé — P4-64)
	$(RUN) ruff format .

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-18s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
