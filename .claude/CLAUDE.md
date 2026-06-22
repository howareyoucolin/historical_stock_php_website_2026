# Stock Report Website

This repository keeps local Claude guidance under `.claude/`.

## Skills

- When the task involves deploying the PHP site to DreamHost, use
  `.claude/skills/dreamhost-deploy/SKILL.md`.

## Repo Notes

- The DreamHost deploy workflow lives under `deploy/`.
- Keep deploy secrets in `deploy/dreamhost.env`, which is intentionally ignored
  by git.
- The report UI lives in `public/`, and report tab partials live in
  `public/parts/reports/`.
