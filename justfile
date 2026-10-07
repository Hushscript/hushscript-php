# Every recipe runs in containers; nothing here touches host toolchains.
# Examples: `just ci`, `just test 8.5`, `CONFORMANCE_CONTEXT=../hushscript-sdk-conformance just conformance`.
set shell := ["bash", "-euo", "pipefail", "-c"]

export HOST_UID := `id -u`
export HOST_GID := `id -g`
# Until every op of the runner contract is mapped, an unmapped scenario is skipped, not failed.
export CONFORMANCE_STRICT := env("CONFORMANCE_STRICT", "0")
# The conformance suite is read at the ref in conformance-ref, the one place it is pinned.
conformance_ref := `tr -d '[:space:]' < conformance-ref`
export CONFORMANCE_CONTEXT := env("CONFORMANCE_CONTEXT", "https://github.com/Hushscript/hushscript-sdk-conformance.git#" + conformance_ref)

hadolint_image := "hadolint/hadolint:v2.14.0@sha256:27086352fd5e1907ea2b934eb1023f217c5ae087992eb59fde121dce9c9ff21e"
actionlint_image := "rhysd/actionlint:1.7.8@sha256:96d4a8c87dbbfb3bdd324f8fdc285fc3df5261e2decc619a4dd7e8ee52bbfd46"
zizmor_image := "ghcr.io/zizmorcore/zizmor:1.16.0@sha256:a6460f1b6fe3dc08e588d314e73563bdd218ee30c995a4be5e9ee5bd300391ce"
shellcheck_image := "koalaman/shellcheck:v0.11.0@sha256:61862eba1fcf09a484ebcc6feea46f1782532571a34ed51fedf90dd25f925a8d"
gitleaks_image := "ghcr.io/gitleaks/gitleaks:v8.28.0@sha256:cdbb7c955abce02001a9f6c9f602fb195b7fadc1e812065883f695d1eeaba854"
sandbox := "--rm --init --label sdk.test=1 --cap-drop ALL --read-only --security-opt no-new-privileges --pids-limit 512 --memory 4g --tmpfs /tmp"
floor := "8.2"
latest := "8.5"

# unit tests with coverage on PHP 8.2 (default) or 8.5; no network beyond the mock (the hint tests read the conformance fixtures: CONFORMANCE_DIR, else CONFORMANCE_CONTEXT, else a clone of the pinned tag)
test version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.5" { "" } else { error("version must be 8.2 or 8.5") } }}
    export PHP_VERSION={{version}}
    CONFORMANCE_DIR="$(scripts/conformance-dir.sh)"
    export CONFORMANCE_DIR
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    docker compose build mock test
    docker compose run --rm test

# Infection on the HTTP core (nightly)
mutation version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.5" { "" } else { error("version must be 8.2 or 8.5") } }}
    export PHP_VERSION={{version}}
    CONFORMANCE_DIR="$(scripts/conformance-dir.sh)"
    export CONFORMANCE_DIR
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    docker compose build mock mutation
    docker compose run --rm mutation

# the conformance suite against the mock
conformance version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.5" { "" } else { error("version must be 8.2 or 8.5") } }}
    export PHP_VERSION={{version}}
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    docker compose build mock conformance
    docker compose run --rm conformance

# every example against the mock
examples version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.5" { "" } else { error("version must be 8.2 or 8.5") } }}
    export PHP_VERSION={{version}}
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    docker compose build mock examples
    docker compose run --rm examples

# every static check: pint, phpstan max, psalm taint analysis, composer validate, dependency analyser, hadolint, actionlint, zizmor, shellcheck, gitleaks, typos, composer audit
lint version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.5" { "" } else { error("version must be 8.2 or 8.5") } }}
    export PHP_VERSION={{version}}
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    docker compose build mock lint audit
    docker compose run --rm lint
    run() { docker run {{sandbox}} --network none --user "$(id -u):$(id -g)" -v "$(pwd)":/work:ro -w /work -e HOME=/tmp "$@"; }
    run {{hadolint_image}} /bin/hadolint docker/dev.Dockerfile docker/typos.Dockerfile
    run {{actionlint_image}} -color
    run {{zizmor_image}} --pedantic --offline --config zizmor.yml .github
    run {{shellcheck_image}} scripts/*.sh
    run {{gitleaks_image}} git --no-banner --redact --verbose /work
    docker build --quiet -f docker/typos.Dockerfile -t hushscript-php-typos:local docker
    run hushscript-php-typos:local --config _typos.toml
    # The one lint step with network access: it asks the advisory database about the locked set.
    docker compose run --rm audit

# composer validate, then an archive of the package installed next to the runtime dependencies only (no network) and loaded
build version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.5" { "" } else { error("version must be 8.2 or 8.5") } }}
    export PHP_VERSION={{version}}
    docker build --quiet --target dev --build-arg PHP_VERSION={{version}} -f docker/dev.Dockerfile -t "hushscript-php-dev:php{{version}}" .
    docker build --quiet --target proddeps --build-arg PHP_VERSION={{version}} -f docker/dev.Dockerfile -t "hushscript-php-proddeps:php{{version}}" .
    out="$(mktemp -d)"
    trap 'rm -rf "$out"' EXIT
    chmod 777 "$out"
    user="$(id -u):$(id -g)"
    docker run {{sandbox}} --network none --user "$user" -e HOME=/tmp -v "$out":/out "hushscript-php-dev:php{{version}}" \
      sh -c 'timeout 120 composer validate --strict && timeout 120 composer archive --format=tar --dir=/out --file=hushscript'
    check='require "/work/vendor/autoload.php"; spl_autoload_register(static function (string $c): void { $f = "/tmp/pkg/src/" . str_replace("\\", "/", substr($c, 10)) . ".php"; if (str_starts_with($c, "Hushscript\\") && is_file($f)) { require $f; } }); $hs = new Hushscript\Client("hsr1:eu:pat.0123456789abcdef0123456789abcdef.0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"); assert(Hushscript\Generated\Catalog::ENTRIES !== [] && Hushscript\Generated\Hints::TABLE !== [] && Hushscript\Generated\Scopes::OPERATIONS !== []); echo "archive ok ", Hushscript\Version::SDK, "\n";'
    docker run {{sandbox}} --network none --user "$user" -v "$out":/dist:ro -w /tmp "hushscript-php-proddeps:php{{version}}" \
      sh -c 'mkdir /tmp/pkg && cd /tmp/pkg && tar -xf /dist/hushscript.tar -C /tmp/pkg && test -f /tmp/pkg/src/Client.php && test ! -e /tmp/pkg/tests && cd /work && timeout 60 php -d zend.assertions=1 -r "$0"' "$check"

# regenerate openapi.json and src/Generated from the production spec, or from a local spec file (outbound network)
regen spec="":
    #!/usr/bin/env bash
    set -euo pipefail
    export PHP_VERSION={{floor}}
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    extra=()
    if [ -n "${CONFORMANCE_DIR:-}" ]; then
      extra=(-v "$(realpath "$CONFORMANCE_DIR")":/conformance:ro -e CONFORMANCE_DIR=/conformance)
    fi
    spec="{{spec}}"
    # A local file is bind-mounted, so the generator reads exactly the bytes that were diffed.
    if [ -n "$spec" ] && [ -f "$spec" ]; then
      extra+=(-v "$(realpath "$spec")":/spec/openapi.json:ro)
      spec=/spec/openapi.json
    fi
    docker compose build regen
    docker compose run --rm ${extra[@]+"${extra[@]}"} regen timeout 600 php scripts/generate.php ${spec:+"$spec"}

# lint, then test and conformance, then examples and build; no argument runs PHP 8.2 and 8.5
ci version="":
    #!/usr/bin/env bash
    set -euo pipefail
    if [ -n "{{version}}" ]; then versions="{{version}}"; else versions="{{floor}} {{latest}}"; fi
    first="${versions%% *}"
    just lint "$first"
    for v in $versions; do
      just test "$v"
      just conformance "$v"
    done
    just examples "$first"
    just build "$first"

# nightly smoke against production; needs SMOKE_TOKEN (outbound network)
smoke:
    #!/usr/bin/env bash
    set -euo pipefail
    : "${SMOKE_TOKEN:?set SMOKE_TOKEN to a token that can read the account}"
    export PHP_VERSION={{floor}}
    docker compose build smoke
    docker compose run --rm smoke
