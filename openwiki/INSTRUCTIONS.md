# Wiki brief

Document what this repository's code actually does, for engineers and coding agents who work on it.

- Code wins over docs. Source, configuration and tests are the truth. README, docs/, design notes, decision records
  and code comments describe intent; when they disagree with the code, state what the code does and never present
  intent or plans as current behaviour.
- State each concrete fact (a count, default, limit, timeout, environment variable, error code) once, on the page
  that owns it, with code evidence. Other pages link to that page instead of repeating the value. Keep counts out of
  summaries and quick-reference lists.
- Prefer precise, checkable statements: names, defaults, bounds, units, which mode or transport a check applies to,
  and whether state is per request or shared.
- Internal links are relative to the current page and point to pages that exist.
- Paths in .openwikiignore (plans, specs, brainstorms, ideas, archives, handovers, reports) are out of scope: do not
  read, cite or describe them.
- Do not mention who or what generated or wrote this wiki.
