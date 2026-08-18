-- Per-agent credit-cost overrides for Tracing 2.0 tools (2026-08-18).
-- NULL = this agent has no overrides at all, every tool costs whatever
-- includes/tracing2_tools.php's TRACING2_TOOLS/TRACING2_UNKNOWN_COST_CREDITS
-- says (today's global behaviour, unchanged for every existing agent).
-- A saved value is a JSON object {"slug": credits, ...} - only tools present
-- in the map are overridden; anything missing still falls back to the
-- global default. Same TEXT-column-holding-JSON pattern as tracing2_tools.
ALTER TABLE users
  ADD COLUMN tracing2_tool_credits TEXT NULL DEFAULT NULL AFTER tracing2_tools;
