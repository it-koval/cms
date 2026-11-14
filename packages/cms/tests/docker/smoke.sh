#!/usr/bin/env bash
# Smoke test of the Docker kit (packages/cms/docker), run INSIDE the php_dev image built with CRITICAL_CSS=1:
#
#   docker build -f docker/Dockerfile --target php_dev --build-arg CRITICAL_CSS=1 -t itk-docker-smoke:dev .
#   docker run --rm -v "$PWD/../..:/repo:ro" itk-docker-smoke:dev bash /repo/packages/cms/tests/docker/smoke.sh
#
# It proves that the image has what the bundle asks for: the PHP extensions of composer.json, Node 22+ and a browser that
# node/critical-css.mjs can drive. tests/ is export-ignored: this script does not ship to sites.
set -euo pipefail

PACKAGE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fail() { echo "SMOKE FAILED: $*" >&2; exit 1; }
step() { echo "==> $*"; }

step "PHP extensions required by composer.json"
missing="$(php -r '
    $json = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    foreach (array_keys($json["require"]) as $name) {
        if (str_starts_with($name, "ext-") && !extension_loaded(substr($name, 4))) {
            echo $name, " ";
        }
    }
' "$PACKAGE/composer.json")"
[ -z "$missing" ] || fail "PHP extensions missing in the image: $missing(add them to docker/Dockerfile)"
php -r 'exit(extension_loaded("pcntl") ? 0 : 1);' || fail "ext-pcntl is missing (messenger:consume needs it to stop on SIGTERM)"
php -r 'exit(function_exists("imagewebp") ? 0 : 1);' || fail "ext-gd has no WebP support (the bundle makes WebP copies of pictures)"

step "Node 22 or newer"
command -v node >/dev/null || fail "node is not in the image (build with --build-arg CRITICAL_CSS=1)"
major="$(node -p 'process.versions.node.split(".")[0]')"
[ "$major" -ge 22 ] || fail "Node 22 or newer is needed, the image has $(node --version)"
command -v npm >/dev/null || fail "npm is not in the image"

step "the image sets what puppeteer needs"
[ "${PUPPETEER_SKIP_DOWNLOAD:-}" = "1" ] || fail "PUPPETEER_SKIP_DOWNLOAD=1 is not set in the image (npm install crittr would download a second browser)"
[ -x "${PUPPETEER_EXECUTABLE_PATH:-}" ] || fail "PUPPETEER_EXECUTABLE_PATH is empty or not executable: '${PUPPETEER_EXECUTABLE_PATH:-}'"

step "worker command consumes every transport the bundle registers"
transports="$(sed -n '/private function defaultTransports/,$p' "$PACKAGE/src/ItkCmsBundle.php" | grep -o "[\$]out\['transports'\]\['[a-z_]*'\] *=" | sed "s/.*\['transports'\]\['\([a-z_]*\)'\].*/\1/" | grep -v '^failed$' | sort -u)"
[ -n "$transports" ] || fail "could not read the transports from ItkCmsBundle::defaultTransports()"
worker="$(sed -n '/^  worker:/,/^  database:/p' "$PACKAGE/docker/compose.yaml" | grep 'messenger:consume')"
[ -n "$worker" ] || fail "docker/compose.yaml: the worker has no messenger:consume command"
for t in $transports; do
    case " $worker " in *" $t "*) ;; *) fail "docker/compose.yaml: the worker command does not consume the '$t' transport";; esac
done

step "crittr in a temporary project"
PROJECT="$WORK/project"
mkdir -p "$PROJECT/public"
(cd "$PROJECT" && npm init -y >/dev/null && npm install --no-audit --no-fund --loglevel=error 'crittr@^5') || fail "npm install crittr failed"
[ -f "$PROJECT/node_modules/crittr/package.json" ] || fail "crittr is not in $PROJECT/node_modules after npm install"

step "node/critical-css.mjs: one page, two viewports (chrome: null, so the browser comes from PUPPETEER_EXECUTABLE_PATH)"
PROJECT="$PROJECT" node -e '
    const html = "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>t</title></head><body><h1 class=\"hero\">Привет, hello</h1><p class=\"unused-below\">x</p></body></html>";
    process.stdout.write(JSON.stringify({
        v: 1, projectDir: process.env.PROJECT, publicDir: process.env.PROJECT + "/public", chrome: null,
        deadlineMs: 120000, timeoutMs: 60000,
        css: ".hero{color:#c00;font-size:2rem}.never-used-class{color:blue}",
        viewports: [{ width: 390, height: 844, mobile: true }, { width: 1366, height: 900, mobile: false }],
        keepSelectors: [], removeSelectors: [],
        pages: [{ id: "smoke", path: "/smoke", html }],
    }));
' > "$WORK/input.json"

set +e
node "$PACKAGE/node/critical-css.mjs" < "$WORK/input.json" > "$WORK/out.ndjson" 2> "$WORK/err.log"
code=$?
set -e
[ "$code" -eq 0 ] || fail "critical-css.mjs exited with $code. stderr: $(tail -c 1500 "$WORK/err.log")"

lines="$(wc -l < "$WORK/out.ndjson" | tr -d ' ')"
[ "$lines" -eq 1 ] || fail "expected one NDJSON line, got $lines: $(head -c 1500 "$WORK/out.ndjson")"
OUT="$WORK/out.ndjson" node -e '
    const line = JSON.parse(require("fs").readFileSync(process.env.OUT, "utf8"));
    if (line.ok !== true) { console.error("page failed: " + line.error); process.exit(1); }
    if (!Array.isArray(line.css) || line.css.length !== 2) { console.error("expected css for 2 viewports: " + JSON.stringify(line)); process.exit(1); }
    if (!line.css.every((c) => typeof c === "string" && c.includes(".hero"))) { console.error("css is empty or lacks .hero: " + JSON.stringify(line.css)); process.exit(1); }
    if (line.css.some((c) => c.includes("never-used-class"))) { console.error("css kept a rule that is not on the page: " + JSON.stringify(line.css)); process.exit(1); }
' || fail "bad result line: $(head -c 1500 "$WORK/out.ndjson")"

echo "SMOKE OK: extensions, Node $(node --version), crittr and the browser work"
