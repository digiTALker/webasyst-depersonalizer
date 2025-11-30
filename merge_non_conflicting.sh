#!/usr/bin/env bash
set -euo pipefail

if ! command -v git >/dev/null 2>&1; then
  echo "git is required to run this script" >&2
  exit 1
fi

# Ensure we are inside a git repository
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "This script must be run inside a git repository." >&2
  exit 1
fi

# Ensure working tree clean
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "Working tree has uncommitted changes. Please commit or stash them first." >&2
  exit 1
fi

target_branch="${1:-$(git rev-parse --abbrev-ref HEAD)}"

# Ensure target branch exists
if ! git show-ref --verify --quiet "refs/heads/${target_branch}"; then
  echo "Target branch '${target_branch}' does not exist." >&2
  exit 1
fi

echo "Target branch: ${target_branch}"

# Determine branches to merge
if [ $# -gt 1 ]; then
  shift
  branches=("$@")
else
  # All local branches except the target
  mapfile -t branches < <(git for-each-ref --format='%(refname:short)' refs/heads/ | grep -v "^${target_branch}$")
fi

if [ ${#branches[@]} -eq 0 ]; then
  echo "No branches to merge." >&2
  exit 0
fi

current_branch=$(git rev-parse --abbrev-ref HEAD)
if [ "${current_branch}" != "${target_branch}" ]; then
  git checkout "${target_branch}"
fi

declare -a merged_branches=()
declare -a conflicted_branches=()

for branch in "${branches[@]}"; do
  echo "Attempting to merge '${branch}' into '${target_branch}'..."
  if git merge --no-ff --no-edit "${branch}"; then
    merged_branches+=("${branch}")
  else
    echo "Merge conflict detected with '${branch}'. Aborting merge."
    git merge --abort || true
    conflicted_branches+=("${branch}")
  fi
  echo ""
done

echo "Merge summary for '${target_branch}':"
if [ ${#merged_branches[@]} -gt 0 ]; then
  printf "  Merged: %s\n" "${merged_branches[@]}"
else
  echo "  No branches were merged."
fi

if [ ${#conflicted_branches[@]} -gt 0 ]; then
  printf "  Conflicts: %s\n" "${conflicted_branches[@]}"
fi

exit 0
