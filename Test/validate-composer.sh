#!/usr/bin/env bash
#
# validate-composer.sh — composer.json strict-validation regression guard.
#
# Audit reference: docs/architecture/audit-2026-04-25.md §2.7
# (DISTRIBUTION/HIGH). Pins the require-block constraints away from unbound
# `>=` / `*` shapes so a future Magento major bump cannot silently auto-install.
#
# This script asserts: zero unbound-constraint warnings (i.e. no `>=` and no
# bare `*` in require entries other than the standard ext-* slots).
#
# Usage: bash Test/validate-composer.sh
# Exits: 0 on pass, non-zero if any unbound constraint sneaks back in.
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

OUT="$(composer validate 2>&1 || true)"

if echo "$OUT" | grep -q "unbound version constraints (>"; then
    printf '[validate-composer] FAIL: unbound `>=` constraint found.\n' >&2
    echo "$OUT" >&2
    exit 1
fi

# Bare `*` constraints on magento/* requires (ext-* are exempt).
if echo "$OUT" | grep -E "unbound version constraints \(\*\).*magento/" >/dev/null 2>&1; then
    printf '[validate-composer] FAIL: bare `*` constraint on magento/* require.\n' >&2
    echo "$OUT" >&2
    exit 1
fi

printf '[validate-composer] OK: no unbound-constraint warnings on magento/* requires.\n' >&2
