---
name: ship
description: Ship work to MapChat's master as a merged PR, from the shared checkout. Use when the user says ship, ship it, or merge it.
---

# Ship

Ship means a merged PR: reviewed code on a branch from GitHub's master, one
commit, a PR, a merge, and the local checkout brought up to date.

The checkout is shared with other sessions, so `git status` holds their
uncommitted work beside this session's. Ship this session's changes, file by
file and hunk by hunk; ship everything only when the user says so ("ship
all"). A swept-in half change once broke production (PR #172), so when the
two are tangled in one hunk, ask.

## Steps

1. **See where things stand.** `git fetch origin`, then compare local
   `master` with `origin/master`: PRs merged elsewhere may sit in the
   working tree uncommitted too.
2. **Check the work.** Run what AGENTS.md's "Check your work" table asks
   for the files you're shipping.
3. **Review the code.** Run the `code-review` skill on the changes you're
   shipping, against `origin/master`. Fix each finding, then check the work
   again; a finding you'd leave, show the user, and leave it only on their
   say. Done when every finding is fixed or the user has agreed to leave it.
4. **Branch from GitHub's master.** `git switch -c <kind>/<what> origin/master`
   (`feat/`, `fix/`, `docs/`). When the work sits on an older base, carry
   it over with `git stash push -u`, then `git stash pop` on the new branch;
   changes from PRs already merged fall away as identical. Resolve each
   conflict inside its file, hunk by hunk, keeping the lines that merged on
   their own around it.
5. **Commit.** As AGENTS.md's Commits rule says: authored as the repo's
   owner, with no AI attribution. The subject is a sentence saying what
   changes for people, as the repo's history reads.
6. **Open and merge the PR.** Push, write the body with the `pr` skill,
   `gh pr create`, then `gh pr merge --merge` (it merges with merge
   commits; CI runs the tests after the merge, so the merge doesn't wait
   on it).
7. **Bring the checkout up to date.** `git switch master`, then
   `git pull --ff-only`.

Done when `gh pr view` says MERGED, local `master` is at its merge commit,
and none of the shipped work is left uncommitted.
