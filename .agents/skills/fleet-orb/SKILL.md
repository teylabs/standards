---
name: fleet-orb
description: Connects an Amp orb to Fleetbox (the fleet coordination server) as itself, using the orb's Amp id token. Load it before using fleet-orb tools (whoami, inbox, message_ack, event_post).
---

# Fleet (orb)

This skill gives the orb the `fleet-orb` MCP server, Fleetbox at https://fleet.tey.pub/mcp/fleet. The orb signs in with its own Amp workload identity: no token is pasted, stored or passed as an argument. Fleetbox knows the orb by its thread.

Call `whoami` first. On `connection_denied`, report it: Jasper may need to approve the Amp workspace on Fleetbox's Amp settings page.
