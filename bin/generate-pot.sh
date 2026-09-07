#!/usr/bin/env bash
#
# Regenerate languages/bale-connector.pot for Bale Connector.
#
# ROOT-CAUSE NOTE (do not remove — see Phase 5 i18n follow-up, PR: i18n-context-fix):
#
# The original .pot was produced with an xgettext keyword spec equivalent to
#   --keyword=__:1,2c
# The "c" flag tells xgettext to treat the SECOND argument of __() as a
# msgctxt. For __() the second argument is the TEXT DOMAIN ('bale-connector'),
# so xgettext wrote "msgctxt \"bale-connector\"" on every string extracted
# from a plain __() call (98 of 154 entries).
#
# WordPress resolves __()/esc_html__() lookups WITHOUT context, so those
# 98 context-bearing catalog entries could never match at runtime: every
# translated string with the "bale-connector" context badge fell back to
# English, while context-less strings (esc_html_e/esc_attr_e) translated
# fine — exactly the bug observed on the live site via Loco Translate.
#
# CORRECT SPEC: the context argument (…c) is only valid on the two-argument
# context functions (_x, _ex, esc_html_x, esc_attr_x) and on plural variants
# (_nx, _nx_noop) where the context is the LAST parameter. __(), _e(), _n()
# and the esc_*__()/esc_*_e() wrappers must carry NO context flag.
#
# The full WordPress keyword set is passed explicitly because any -k flag
# resets xgettext's built-in PHP keyword defaults; being explicit keeps the
# output deterministic across gettext versions.
#
# Usage:  bash bin/generate-pot.sh        (run from the repository root)
# Requires: GNU gettext-tools (xgettext). Gettext 0.21 tested.
#
# NOTE: lib/ (vendored Action Scheduler — ships its own 'action-scheduler'
# domain) and tests/ are deliberately excluded from extraction, matching the
# shipped catalog's scope.

set -euo pipefail

if ! command -v xgettext >/dev/null 2>&1; then
	echo "error: xgettext not found (install gettext-tools)" >&2
	exit 1
fi

# Corrected keyword specification — context flags ONLY on context-aware functions:
#   two-arg context functions:     <func>:1,2c   (arg 2 = context)
#   plural with trailing context:  _nx:1,2,4c    (arg 4 = context)
#   plain (NO context flag):       __, _e, _n, esc_*__, esc_*_e, _n_noop
KEYWORDS='
--keyword=__:1
--keyword=_e:1
--keyword=_x:1,2c
--keyword=_ex:1,2c
--keyword=_n:1,2
--keyword=_n_noop:1,2
--keyword=_nx:1,2,4c
--keyword=_nx_noop:1,2,3c
--keyword=esc_html__:1
--keyword=esc_html_e:1
--keyword=esc_html_x:1,2c
--keyword=esc_attr__:1
--keyword=esc_attr_e:1
--keyword=esc_attr_x:1,2c
'

xgettext \
	--language=PHP \
	--from-code=UTF-8 \
	$KEYWORDS \
	--package-name="Bale Connector" \
	--package-version=$(grep -oP '(?<=^ \* Version:           )[0-9.]+' bale-connector.php | head -1) \
	--msgid-bugs-address="https://wordpress.org/support/plugin/bale-connector" \
	--copyright-holder="Sobhan Askari" \
	--foreign-user \
	--add-comments=translators \
	--add-location=file \
	-o languages/bale-connector.pot \
	$(find . \
		-path ./lib -prune -o \
		-path ./tests -prune -o \
		-path ./.hermes -prune -o \
		-type f -name '*.php' \
		! -path './node_modules/*' \
		-print)

echo "OK: languages/bale-connector.pot regenerated."
echo "Sanity: msgctxt entries must be 0 unless a real _x()/esc_*_x() call exists:"
grep -c '^msgctxt' languages/bale-connector.pot || true
