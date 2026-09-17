---
name: feedback-configuration-php-permission-scope
description: The permission restriction on plugin_info/configuration.php blocks Bash-level reads (diff/md5sum/cat), not just the Read/Edit tools — don't waste a turn trying to verify the .txt→.php sync by reading the .php back.
metadata:
  type: feedback
---

`CLAUDE.md` documents that `plugin_info/configuration.php` can't be read/edited via the Read/Edit tools
(session permissions) and must be kept in sync from `configuration.txt` via
`cp plugin_info/configuration.txt plugin_info/configuration.php`. Confirmed that this restriction is
**broader than just the Read/Edit tools**: any Bash command whose purpose is to *read*
`configuration.php` — `diff a.txt b.php`, `md5sum b.php`, `cat b.php` — is also denied by the permission
system, even though the identical `cp` **write** (truncate+overwrite) to that same path succeeds without
prompting. `git status`/`git diff --stat` on the path also work fine (git reads via its own object model,
not a raw file read tool call, and isn't blocked).

**Why:** tried to double-check the sync after `cp` with `diff`/`md5sum` on the two files — both calls were
denied outright ("Permission ... has been denied"), which briefly looked like the `cp` itself might have
silently failed. It hadn't; the denial is scoped to *reading* `configuration.php`, not to whether the sync
worked.

**How to apply:** after `cp plugin_info/configuration.txt plugin_info/configuration.php`, don't spend a
turn trying to verify equality by reading `configuration.php` back (`diff`, `md5sum`, `cat`, `Read` tool —
all denied). Instead confirm via signals that don't require reading that file's content:
`git status --short plugin_info/configuration.php` (shows `M` if the overwrite changed it relative to HEAD)
and/or `git diff --stat` on `configuration.txt` alone to confirm what was intended to be mirrored. Treat a
no-error `cp` exit as sufficient success signal for the sync step. See also
[[feedback-no-local-php-verification]] (same environment, different reason no local verification is
available here).
