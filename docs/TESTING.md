# FindIT testing summary

This summary records the verification completed during development. It distinguishes automated checks from the project owner's manual checks. Tests on disposable MariaDB fixtures used synthetic data. A manual installation and phpMyAdmin walkthrough on an independent Windows computer remain pending.

## Automated verification reported by Codex

| Area | Verified behavior |
| --- | --- |
| Authentication and account access | Registration and login, password verification, session rotation and timeout, logout, account status checks, administrator demotion in an existing session, and role and ownership checks. Phase 6B also tested a database failure without exposing database details. |
| Reporting and photos | Report creation, editing, cancellation, browsing, and My Reports. Tests exercised image upload and authorized streaming, escaped text, and cancelled-photo access. Date tests covered Manila input on Berlin and New York host settings, calendar validation, missing dates, and legacy seconds. |
| Claims and recovery | Phase 3D passed **12 isolated end-to-end groups**. Tests covered authorization, competing claims, staff decisions, failed pickup, handoff, concurrency, and audit rollback. Later phases checked the notification events and disabled student claim actions. |
| Matching | Phase 4C passed **19 groups across 188 HTTP requests**. Tests covered ranking, student and administrator access, stale pairs, photos, and the 120-candidate and five-result limits. |
| Notifications | Phase 5E passed **17 groups across 186 HTTP requests**. Tests covered claim and match alerts, privacy, recipient access, duplicate handling, pagination, read timestamps, and rollback. The bulk-read race test kept both a delayed lower-ID insert and a later arrival unread. |
| Phase 6B regression | **15 isolated groups passed across 170 HTTP requests** on MariaDB 10.4.32. Coverage included login, revocation, report and claim flows, matching, notifications, and repeat category seeding. Separate checks on the original local Apache instance verified the root redirect, public assets, and `403` for direct private-file access. |
| Browser checks | Chrome checks covered **320, 390, 768, and 1440 px**. They verified layout, visible keyboard focus, reduced motion, and accessible names for the bell and notification read states. |
| Syntax | PHP checks passed for **35 files** in Phase 6B. The Phase 6A audit checked syntax for the photo-preview JavaScript. |

The isolated databases and temporary test servers were removed after verification. In Phase 6B, checksums for the original database tables matched before and after testing, and the original student claims flag remained disabled.

## Phase 6E and Step 33

- Phase 6E passed **22 of 23 groups across 412 HTTP requests** against the original submission ZIP. Student and staff workflows, claims, matching, notifications, privacy, concurrency, transaction rollback, and backup recovery passed. The Administrator Match Review page measured 325 px wide at a 320 px viewport. The other 43 browser page and viewport combinations passed the horizontal overflow check.
- Step 33 reproduced the overflow in both cards' metadata grids. The occurrence-date column took almost all the available width, leaving about 8 px for **Location**. The overflowing label reached the 325 px mark. The fix gives `.admin-match-facts` two equal columns at widths up to 660 px. CSS wrapping and focus indicators still apply, and the desktop report comparison stays side by side.
- The targeted regression covered ordinary and long report content at **320, 390, 768, and 1440 px**, plus seven shared pages at 320 and 1440 px. Both reports, reasons, the photo and placeholder, and keyboard focus passed. Anonymous and student access stayed blocked, and a suspended administrator lost access in an existing session. Read-only review preserved all six disposable table checksums. PHP and photo-preview JavaScript syntax checks passed. The original Phase 6E pass count remains the result for its full suite.
- Use the SHA-256 in the companion `FindIT-Campus-Lost-and-Found-Step33.zip.sha256` file to verify the revised ZIP. The manual installation on a separate Windows computer is a remaining host check.

## Manual verification by the project owner

- **Phases 1 and 2.** The owner accepted authentication, reporting, browsing, My Reports, photo upload and replacement, and the Liquid Glass interface.
- **Phase 3.** The owner verified administrator login and staff access denial for students. The isolated browser demonstration covered student submission, competing claims, approval, rejection and claimant feedback, successful handoff, and failed pickup followed by reopening.
- **Phase 4.** The owner verified an Open Lost report with a relevant Found suggestion and meaningful match reasons. Staff dashboard, side-by-side comparison, and student access denial on staff routes also passed.
- **Phase 5.** The owner verified the bell, inbox, All and Unread views, a generated Possible Match alert, its report link, and the read-state change. Rechecking matches preserved the existing notification and read state.
- **Phase 6B.** The owner verified the root entry, a `403` on the schema URL, the Account dashboard, and Philippine time labels on Report Item. Student claims stayed disabled in the original app.

## Checks for a new host

1. Follow the [installation guide](INSTALLATION.md) with a fresh database. Confirm the schema, notification migration, and category seed work in order. Repeat the seed and confirm category values stay intact.
2. Verify the root redirect, public assets, and private-path denials with the host's actual web server. Check file permissions and protected photo streaming before adding any personal data.
3. Run the [student and staff walkthrough](USER_GUIDE.md#sample-classroom-walkthrough) with synthetic reports. Check login, logout, expiry, suspended users, and revoked staff access in existing sessions.
4. If authorized, use a disposable copy with claims enabled to test a full claim workflow there. Check that submitted evidence stays private and that only authorized recipients see the resulting alerts.
5. Check date display, keyboard navigation, viewport widths, session cookies, and HTTPS settings on the target host. Before real campus use, verify a backup restore and test expected usage volumes.

## Limits of the evidence

The results support local development and demonstration. The matching candidate window can miss older reports. One isolated performance test paginated 600 notifications into 50 pages. Its median unread count was 3.70 ms with 450 unread, and a three-report match check took a median of 44 ms with 10,149 fixture reports. These are localhost measurements. Campus load, a different database engine, and the final delivery environment each need their own verification.

Campus deployment requires separate operational approval. See the [security guide](SECURITY.md).
