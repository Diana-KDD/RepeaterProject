# Команды проекта задания для практики

set shell := ["bash", "-euo", "pipefail", "-c"]
set dotenv-load

# Единый источник значений портов по умолчанию; переопределяется через .env (см. .env.example).
backend_port := env_var_or_default("RECALL_BACKEND_PORT", "8080")
frontend_port := env_var_or_default("RECALL_FRONTEND_PORT", "5173")
base := "http://localhost:" + backend_port
front := "http://localhost:" + frontend_port

# docker compose с проброшенными портами, поэтому compose.yaml не нуждается в умолчаниях.
compose := "RECALL_BACKEND_PORT=" + backend_port + " RECALL_FRONTEND_PORT=" + frontend_port + " docker compose"

# Показать доступные рецепты.
default:
    @just --list

# Создать .env из .env.example при первом запуске (локально, в .gitignore).
_env:
    @[ -f .env ] || { cp .env.example .env && echo "создан .env из .env.example"; }

# Запустить приложение на переднем плане для разработки.
dev: _env
    {{ compose }} up

# Запустить приложение в фоне и ждать, пока оно ответит.
up: _env
    {{ compose }} up -d
    @echo "ожидание бэкенда {{ base }}"
    @for i in $(seq 1 90); do curl -sf {{ base }}/stats >/dev/null 2>&1 && break || sleep 1; done
    @echo "ожидание фронтенда {{ front }}"
    @for i in $(seq 1 90); do curl -sf {{ front }} >/dev/null 2>&1 && break || sleep 1; done

# Остановить приложение и удалить его тома.
down:
    {{ compose }} down -v

# API-приёмка — приложение уже должно быть запущено (см. `just up`).
acceptance:
    hurl --variable base={{ base }} --test spec/acceptance/*.hurl

# Браузерные сценарии — приложение уже должно быть запущено (см. `just up`).
e2e:
    cd e2e && npm install
    cd e2e && bash -c '[ -n "${PLAYWRIGHT_BROWSERS_PATH:-}" ] || npx playwright install chromium'
    cd e2e && E2E_BASE_URL={{ front }} npx playwright test

# Автоформат и автоправки: php-cs-fixer + rector, prettier + eslint --fix.
fmt:
    #!/usr/bin/env bash
    set -euo pipefail
    cd app/backend
    composer install -q
    vendor/bin/php-cs-fixer fix
    vendor/bin/rector process
    cd ../frontend
    npm install --no-audit --no-fund
    npm run format
    npm run lint -- --fix

# Статанализ и стиль: linecop, php-cs-fixer, phpstan, phpmd, rector, tsc, eslint, prettier.
# Приложение поднимать не нужно. Так же гоняет CI (через `verify`).
lint: _parallel-cite
    #!/usr/bin/env bash
    set -euo pipefail
    linecop
    # Typst: ссылки только через #link("url")[текст]. Markdown [текст](url) не
    # парсится — Typst автоссылит голый URL и оставляет литерал «[label](».
    if grep -REn '\]\((https?://|/)' www/content; then
        echo "www/content: markdown-ссылки в .typ — используйте #link(...)[...]" >&2
        exit 1
    fi
    (cd app/backend && composer install -q)
    (cd app/frontend && npm install --no-audit --no-fund)
    # Анализаторы независимы после установки зависимостей — гоняем их параллельно.
    parallel --halt now,fail=1 <<'EOF'
    cd app/backend && vendor/bin/php-cs-fixer fix --dry-run --diff
    cd app/backend && vendor/bin/phpstan analyse
    cd app/backend && php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/phpmd src text phpmd.xml
    cd app/backend && vendor/bin/rector process --dry-run
    cd app/frontend && npm run typecheck
    cd app/frontend && npm run lint
    cd app/frontend && npm run format:check
    EOF

# Полная локальная проверка: линтер, юнит-тесты, запуск приложения, все тесты, остановка. Как в CI.
verify:
    #!/usr/bin/env bash
    set -euo pipefail
    just lint
    just test
    just up
    trap 'just down' EXIT
    just acceptance
    just e2e

# Модульные тесты: бэкенд (Testo) и фронтенд (Vitest).
test:
    #!/usr/bin/env bash
    set -euo pipefail
    cd app/backend
    composer install -q
    vendor/bin/testo
    cd ../frontend
    npm install --no-audit --no-fund
    npm test

# Собрать сайт руководства (Typst + Tola) в www/public и проверить ссылки/ассеты.
# validate работает по исходникам (сборка не нужна), но держим его здесь, чтобы
# и Pages-деплой, и preflight ловили битые ссылки. Ассет-ссылки пишите
# source-relative (/assets/...) — префикс сайта Tola добавляет сам.
guide:
    cd www && tola build
    cd www && tola validate

# Предпросмотр руководства локально с горячей перезагрузкой (http://127.0.0.1:5277).
guide-serve:
    cd www && tola serve

# Проверяющий: подтянуть приватные материалы (субмодуль practice-extras, нужен
# доступ к gitlab) и поднять гайд — раздел /review/ с инструкцией доступен только
# локально, в публичную сборку Pages не попадает.
review:
    git submodule update --init www/content/review
    @echo "инструкция проверяющего: http://127.0.0.1:5277/practice/review/"
    cd www && tola serve

# Синхронность гайда с исходниками (outdatty). Сторона веб-студии; в форках no-op.
docs-sync:
    outdatty check

# Сторона веб-студии: здоров ли шаблон для выдачи студентам. Гоняет те же
# проверки, что verify (lint, юнит, acceptance, e2e), плюс гайд/outdatty/гигиену.
# Функциональные падения сверяет с базлайном известных «красных» (стабы для
# студента, preflight-baseline.txt): падение НЕ из базлайна — регрессия, валит;
# внезапно зелёная из базлайна — стаб решён/тест ослаб, тоже валит (обновить
# базлайн — `just preflight-baseline`). В CI на master — preflight.yml.
# Готовность шаблона: гигиена + lint + test + гайд + outdatty + acceptance/e2e vs базлайн.
preflight: _parallel-cite _handoff-clean
    #!/usr/bin/env bash
    set -euo pipefail
    parallel --halt now,fail=1 ::: 'just lint' 'just guide' 'just docs-sync' 'just _failures'
    just test
    if ! diff <(sort preflight-baseline.txt) <(sort var/preflight/failures.txt); then
        echo "функциональные проверки разошлись с базлайном (< ожидалось красным, > сейчас)." >&2
        echo "регрессия — чините; если изменение намеренное — just preflight-baseline." >&2
        exit 1
    fi
    echo "preflight: шаблон здоров, красные проверки совпали с базлайном"

alias handoff := preflight

# Запускать, когда меняется состав намеренно нерешённого в шаблоне; результат
# (preflight-baseline.txt) коммитить.
# Обновить базлайн известных «красных» проверок (acceptance + e2e).
preflight-baseline:
    #!/usr/bin/env bash
    set -euo pipefail
    just _failures
    cp var/preflight/failures.txt preflight-baseline.txt
    echo "базлайн обновлён:"
    cat preflight-baseline.txt

# Гигиена раздачи: рабочее дерево чистое, .env не в индексе, есть .env.example,
# LOGBOOK.md — пустой шаблон (решение/секреты/черновики не утекли студентам).
_handoff-clean:
    #!/usr/bin/env bash
    set -euo pipefail
    dirty="$(git status --porcelain)"
    test -z "$dirty" || { echo "рабочее дерево грязное:" >&2; echo "$dirty" >&2; exit 1; }
    if git ls-files --error-unmatch .env >/dev/null 2>&1; then echo ".env в индексе" >&2; exit 1; fi
    test -f .env.example || { echo "нет .env.example" >&2; exit 1; }
    ! grep -qE '^-[[:space:]]+\S' LOGBOOK.md || { echo "LOGBOOK.md заполнен — должен быть пустой шаблон" >&2; exit 1; }

# Собрать множество падающих функциональных проверок (acceptance + e2e) в
# var/preflight/failures.txt — нормализованно и отсортированно. Поднимает
# приложение и гасит его. Падения тестов сбор не прерывают (|| true): красные —
# это данные, а не ошибка рецепта. Контейнерные тома, локальный vendor/
# node_modules не трогает — безопасно параллельно с lint.
_failures:
    #!/usr/bin/env bash
    set -euo pipefail
    mkdir -p var/preflight
    rm -f var/preflight/acceptance.tap var/preflight/e2e.json
    just up
    trap 'just down' EXIT INT TERM
    hurl --variable base={{ base }} --report-tap var/preflight/acceptance.tap --test spec/acceptance/*.hurl || true
    cd e2e && npm install --no-audit --no-fund
    bash -c '[ -n "${PLAYWRIGHT_BROWSERS_PATH:-}" ] || npx playwright install chromium'
    E2E_BASE_URL={{ front }} npx playwright test --reporter=json > ../var/preflight/e2e.json 2>/dev/null || true
    cd ..
    {
        grep '^not ok' var/preflight/acceptance.tap | sed -E 's/^not ok [0-9]+ - //; s#.*/#acceptance #' || true
        node -e 'const r=require("./var/preflight/e2e.json");const o=[];const w=s=>{(s.specs||[]).forEach(p=>{if(!p.ok)o.push("e2e "+p.title)});(s.suites||[]).forEach(w)};(r.suites||[]).forEach(w);o.forEach(x=>console.log(x))'
    } | sort > var/preflight/failures.txt

# Подавить разовое уведомление GNU parallel о цитировании (пишется в stderr).
_parallel-cite:
    @mkdir -p "${PARALLEL_HOME:-${HOME}/.parallel}" && touch "${PARALLEL_HOME:-${HOME}/.parallel}/will-cite"
