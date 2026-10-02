# Club PC monitoring

## Deployment verification: 2026-10-02

- Monitoring code through commit `79a12b3` deployed to the VPS after a database
  and frontend backup; additive migration completed successfully.
- SPOT Gaming: 139 PCs imported, existing manual equipment retained. A second
  import created zero duplicates. At verification: 37 online, 102 offline.
- Shared thresholds: warning 50, critical above 79, hold 0 seconds.
- Readiness returned HTTP 200; anonymous club monitoring returned HTTP 401.
- All four Playwright scenarios passed on this revision in isolated VPS Docker,
  including thermal colors, polling, filtering and mobile layout. Earlier checks
  on this revision passed 29 backend tests (200 assertions), TypeScript and build;
  agent unit tests passed 4 tests. UI temperature fixtures are not real readings.
- No physical-PC temperature readings yet: Windows agent installation, real WMI
  verification and HTTPS or a secure tunnel remain required (see below).

## Sources and limits

iCafeCloud `pcs` supplies inventory; `onlinePcList` supplies connection state.
Live responses from pcs, pcList, onlinePcList and bootPcs for the connected
license contained no CPU/GPU temperatures. Never infer temperature from online
status, session activity, or hardware model strings.

Temperatures are supplied by `agent/agent.py` on each physical Windows PC via
LibreHardwareMonitor's WMI namespace `root/LibreHardwareMonitor`. No agents were
installed on club PCs by this deployment. Until provisioned, temperatures are
shown as unavailable. The source integration and frontend are ready to consume
real samples; actual Windows hardware verification still requires access to PCs.

## Admin workflow

1. Connect iCafeCloud in the club card.
2. Open Monitoring, select the club, click Import PCs from iCafeCloud.
3. Use the shared CPU/GPU template. Defaults: green below 50, yellow 50 through
   79 inclusive, red above 79. Both sensors use the same alarm threshold and hold.
4. Register a separate agent for each imported equipment ID. Its secret is shown
   once. Never share/clone tokens in a common CCBoot image.
5. On the target PC, run LibreHardwareMonitor with WMI sensors available and use
   Python to run the existing agent. Copy config.example.json to a private config,
   set equipment_id, the per-device token and a secure server URL. Bind there:

```powershell
python agent.py --config config.json --bind-machine
python agent.py --config config.json --once
python agent.py --config config.json
```

The agent automatically selects the maximum valid CPU and GPU temperature from
their temperature sensors; explicit sensor_ids override this. Run continuously
via your managed startup/service mechanism under an account with sensor access.
Reports run about every 15 seconds plus collection time. Browser polls every 15
seconds; iCafe connectivity caches for 30 seconds. This is polling, not streaming.

The current public deployment is HTTP: provision HTTPS or a secure tunnel before
sending agent credentials. Do not enable allow_insecure_local on the public VPS.

## Safety and semantics

- Imports filter by cafe ID and PC type, reuse unambiguous name/MAC matches, and
  are repeatable. They never delete absent PCs or overwrite local service history.
- Unmatched renamed PCs without a stable MAC require manual reconciliation.
- Offline PCs are gray. Missing/stale (>180 seconds) sensors are null, never zero.
- A missing sensor prevents a green all-clear; a known hot sensor still warns.
- Color is immediate and uses the worst CPU/GPU value; hold_seconds only delays
  alerts. Recovery is below the yellow threshold. Maintenance suppresses alerts,
  not observed temperatures. Editing a template takes effect on the next sample.
- Club representatives have read-only access to their own clubs. Import, agent
  provisioning and template changes require platform owner/lead permissions.
- Legacy load/disk thresholds remain available separately; temperature thresholds
  are now managed only by the unified club template.
