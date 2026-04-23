# Reactions Plugin Roadmap & Tasks

## Security & Architecture

- [ ] **CSRF Re-evaluation**: Compare the current custom HMAC-signed tokens against Kirby's built-in `csrf()` and `csrf_check()`.
  - _Current_: Custom tokens are page-scoped and time-limited (30m) but not tied to a session until the POST occurs. This avoids forcing sessions on every visitor.
  - _Task_: Decide if the privacy benefit of session-less tokens outweighs the security of session-bound CSRF protection.
- [ ] **Token Binding**: If sticking with custom tokens, consider hashing the session ID into the HMAC to prevent a single token from being reused by multiple IP-masked visitors within its 30-minute window.

## User Experience

- [ ] **Non-HTMX Status Feedback**: Implement a way to show the "Reaction saved" message for visitors without HTMX.
  - _Idea_: Use Kirby's `$session->flash('reactions.status', ...)` before the redirect and check for it in the snippet.
- [ ] **Loading States**: Add CSS hooks or attributes for "pending" states during HTMX requests to improve perceived performance.

## Maintenance & Tooling

- [ ] **Log Compaction CLI**: Create a Kirby CLI command or custom script to compact `events.jsonl`.
  - _Goal_: Process the log to keep only the final `on`/`off` state for each visitor + page + reaction pair.
  - _Benefit_: Reduces log size and speeds up `counts()` replay for high-traffic pages.
- [ ] **Storage Health Check**: Add a method to verify log integrity (detect malformed JSON lines or orphaned events).

## Features

- [ ] **Reaction Analytics**: Create a simple Panel view or a separate route to see aggregate reaction trends across the site.
- [ ] **Custom Event Actions**: Allow developers to hook into the `on`/`off` events (e.g., to trigger notifications or sync with external databases).
