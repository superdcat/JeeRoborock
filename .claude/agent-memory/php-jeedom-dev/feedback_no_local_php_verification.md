---
name: feedback-no-local-php-verification
description: No `php` binary exists in this dev environment (Windows/Git Bash) — use a Python-based structural sanity check on the diff instead of `php -l`, and write scripts to a file rather than inline heredoc.
metadata:
  type: feedback
---

`php -v`/`php -l` are unavailable in this repo's dev shell (Git Bash on Windows) — confirmed via
`which php` returning nothing. `CLAUDE.md` already flags that validation runs in CI, but CI only runs on
push/PR, so there's no fast local signal during an editing loop.

**Why:** for a large PHP class file (`core/class/<id>.class.php` can grow to thousands of lines),
eyeballing brace/paren balance after an Edit is error-prone, and a `*/`-inside-a-docblock or an unbalanced
bracket breaks prod undetected until CI.

**How to apply:** `python` (not `python3`/`py` — those may resolve to broken Windows Store shims in this
environment; `python` resolves to the real interpreter) is available and can run a small script that strips
PHP `//`/`#`/`/* */` comments and `'...'`/`"..."` string literals (with backslash-escape handling), then
counts `{}`/`()`/`[]` balance and tracks a running depth to catch a close-before-matching-open. This catches
the exact class of bug (`*/` closing early, unbalanced brackets) that `php -l` would catch, without a PHP
install. **Write the script to a file with the `Write` tool first, then run it with Bash** — passing it
inline via a Bash heredoc mangles backslash escapes (`'\\'` inside the command string gets collapsed to
`'\'`, causing a Python `SyntaxError`) because of how the Bash tool's command parameter handles escaping.
This is a supplement to reading the diff carefully, not a replacement — it doesn't catch semantic bugs,
only structural/lexical ones.

**Generalizes beyond Python**: the same backslash-collapsing hits **any** inline script text passed as a
Bash tool command argument — e.g. an inline `perl -e '...s/\\t/\t/g...'` meant to convert literal two-char
`\t`/`\n` escape sequences into real tab/CRLF bytes; the double backslashes silently become single
backslashes before reaching perl, so the substitution runs on the wrong pattern and produces garbled output
that has to be reverted (`git checkout --`) and redone. **Fix that actually works**: never put
escape-sequence *text* (`\t`, `\n`, `\\`) into a Bash command string at all — write the **real**
tab/newline characters directly into a scratch file via the `Write` tool (the tool call's own string
decoding handles those correctly, unlike a nested Bash-command string), then do purely *byte-level*
file-to-file operations (`sed 's/^/\t\t\t\t/'` to flat-prepend real tab characters already typed literally
in the sed script, or a `perl -0777` script that reads two files and splices their raw bytes with
`s/\r\n/\n/;s/\n/\r\n/` for line-ending normalization). See [[feedback-edit-tool-tab-indented-files]] for
the full recipe.

**Refinement — the balance checker must respect `<?php ?>` tag boundaries on mixed HTML/PHP files**
(`desktop/php/*.php`). Running the naive whole-file comment/string-stripper (designed for pure-PHP
`core/class/*.php`) on a template file produces a false-positive brace imbalance. Root cause: raw HTML text
sitting *outside* `<?php ?>` tags routinely contains a bare apostrophe (a French contraction, e.g. `l'API`,
inside an `<!-- HTML comment -->`) which the stripper — unaware it isn't looking at PHP — reads as the
*start of a single-quoted string literal*, then silently swallows everything up to the next `'` as "inside
a string", desyncing all brace counts that follow. **Fix**: before stripping, first split the file on
`<?php` / `?>` and concatenate only the PHP segments; run the comment/string stripper (and the balance
count) on that PHP-only text, ignoring HTML segments entirely (HTML's own `{{...}}` i18n markers and stray
punctuation are not PHP syntax). Sanity-check the tool itself by also running it against the file's
last-committed (`git show HEAD:<path>`) version — if HEAD already reports a nonzero imbalance, the checker
(not the new edit) is broken.
