---
name: feedback-edit-tool-tab-indented-files
description: Edit tool old_string match fails silently on tab-indented Jeedom desktop/php templates — fall back to a Python/perl script that derives real indentation from the file instead of retyping whitespace by hand.
metadata:
  type: feedback
---

Jeedom's `desktop/php/*.php` templates (e.g. `desktop/php/template.php`) are indented with **tabs**, not
spaces (confirmed via `sed -n '<range>p' file | cat -A` showing `^I` markers), and use **CRLF** line
endings. `core/class/*.class.php`, by contrast, uses 2-space indentation. When constructing a multi-line
`old_string`/`new_string` for the `Edit` tool by re-typing content seen in a `Read` result, the retyped
whitespace does not reliably reproduce the file's actual tabs — the `Edit` call then fails with "String to
replace not found in file" even though the visible text looks identical, because `Read`'s cat -n rendering
doesn't visually distinguish tabs from spaces.

**Why:** wasted several failed `Edit` attempts trying to insert a new HTML block into a tab-indented
`desktop/php/*.php` form before recognizing the root cause was tab vs. space mismatch, not a wrong anchor.

**How to apply:** when an `Edit` on a `desktop/php/*.php` (or any tab-indented) file fails to match despite
the anchor text looking present (confirmed via `Grep`), don't keep retrying `Edit` with re-typed
indentation — switch to a `Write`-then-`Bash` approach that derives the real indentation from the file
rather than guessing. See also [[feedback-no-local-php-verification]] (same environment, no local
`php`/lint to catch the fallout of a bad manual edit).

**Concrete recipe that works end-to-end (inserting a new `<div class="form-group">` block into a
tab+CRLF `desktop/php/*.php`):**
1. `Write` the new block to a scratch file using **real tab characters** for indentation (typed literally,
   not `\t`) and **real newlines** — use *relative* nesting depth starting wherever is convenient (e.g.
   0/1/2/3 tabs), don't try to match the target file's absolute depth yet.
2. Inspect the target file's actual absolute tab depth at the insertion point with
   `sed -n '<n>,<m>p' file | cat -A` (counts `^I` per line) to get the base indent (e.g. 7 tabs for a
   `form-group` at that nesting level).
3. Flat-prepend the missing base tabs to *every* line of the scratch block in one shot:
   `sed -i 's/^/\t\t\t\t/' scratchfile` (count = target base − what you used in step 1) — since every line
   in the block was written at a consistent *relative* depth, a single flat prefix correctly re-bases the
   whole block without touching relative nesting.
4. Splice the now correctly-indented block into the target file with a `perl -0777` script that: reads both
   files raw (`<:raw`), normalizes the block's line endings (`s/\r\n/\n/g; s/\n/\r\n/g` — cheap idempotent
   CRLF enforcement), finds a **stable literal anchor substring already containing real tabs** (copy this
   from a `Grep`/`Read` of the target file, not retyped) via `index($content, $marker)`, and writes
   `substr(...,0,$idx) . $block . substr($content,$idx)` back to the target file. No regex substitution of
   escape-sequence text is needed (avoids the Bash-argument backslash-collapsing pitfall in
   [[feedback-no-local-php-verification]]).
5. Verify structurally: `git diff --stat` (expect a small, clean insertion, no reformatting of surrounding
   lines) and a brace/paren-balance script (see [[feedback-no-local-php-verification]]) on the *whole* file,
   comparing the mismatch count against the same check run on `git show HEAD:<file>` — an unchanged mismatch
   count confirms the insertion didn't introduce a *new* imbalance.

If steps 1-4 go wrong (e.g. wrong tab count guessed), don't try to patch the damage in place — `git status`
then `git checkout -- <file>` to reset to pristine and redo the sequence with the corrected tab count; much
faster than surgical fixes on a mangled CRLF/tab file.
