# FindIT administrator guide

Administrator access is reserved for staff authorized to review lost-and-found claims. This guide covers local demonstration accounts and the steps a campus operator would need to govern that access.

## Access requirements

Sign in through the same Login page used by students. The account must have `role = 'administrator'` and `account_status = 'active'`. FindIT reads the current role and status during protected requests. A role change takes effect on the next request. An inactive, suspended, or missing account loses its session on its next protected request.

An administrator's Account page links to [Manage Claims](http://localhost/findit/pages/admin-claims.php), [Administrator Matches](http://localhost/findit/pages/admin-matches.php), and the notification bell. Students cannot enter the staff pages.

## Provision a local administrator

Public registration creates student accounts. Arrange administrator provisioning with the person authorized to manage the local database. Use a dedicated account for each operator. The project ships no setup or password-reset endpoint.

1. Confirm the intended local instance and database before provisioning. Use a disposable installation for a demonstration account.
2. Choose a unique email and display name for the account. For a demo, use synthetic details. Confirm the email is available before creating the account.
3. Use an authorized local CLI process that accepts the password interactively with masked input, calls PHP `password_hash()` with `PASSWORD_DEFAULT`, and uses a prepared insert. The required `users` fields are `full_name`, `email`, and `password_hash`. Set `role` to `administrator` and `account_status` to `active`. A dedicated administrator can leave `student_id` and `phone` null. [User schema](../database/schema.sql)
4. Verify that the operator can sign in and open the staff pages. Use a separate student session to check access denial. Store the administrator password in the operator's approved password manager.

Keep plaintext passwords and password hashes out of screenshots, command arguments, shell history, logs, and project files. Remove any temporary provisioning helper after the authorized operation.

## Review claims and record recovery

The original installation keeps student claim submission and withdrawal disabled. Authorized staff can review existing claims. For teaching, exercise the full workflow in an authorized disposable setup with synthetic claims.

1. Open **Manage Claims**. Start with the pending queue and open the claim. Review the item's status and the claimant's identifying details privately. Consult the campus verification process when two claims conflict.
2. Choose and confirm the appropriate action. **Approve claim** reserves the Found item for pickup and makes its report **Claimed**. **Reject claim** closes the request and can include brief feedback visible to that claimant.
3. Keep the claim approved while awaiting pickup. After verifying the person and handing over the item, choose **Confirm handoff**. This completes the approved claim, resolves the report, and closes remaining pending claims for that item.
4. If pickup fails, use **Cancel approved claim**. This cancels the approved claim and reopens the Found report when the workflow permits it. Other pending claims stay available for review.

Active administrators receive alerts for new pending claims. An alert stops being actionable when that claim leaves the pending queue. Staff who gain access later can find existing pending requests in **Manage Claims**.

## Protect evidence and audit history

Claim evidence belongs in the authorized claim view. Keep it out of messages, public reports, screenshots, and classroom materials. Rejection feedback should contain only what the claimant needs to understand the decision. Notifications contain short event summaries. Staff match comparisons contain reports and match reasons.

FindIT records administrator claim decisions and related claim closures in `activity_logs`. The entries reference the actor, item, claim, action, and timestamp. They are written with the decision transaction and omit claim evidence and rejection feedback. Maintain a separate approved process for recording physical custody and verifying the handoff. FindIT provides staff review pages and database audit records. [Review implementation](../includes/admin-claims.php)

## Deactivate an account or recover a password

- **Deactivation.** An authorized operator should confirm the intended user row by ID and email, then set `account_status` to `inactive` through the trusted local maintenance process. This preserves related reports and audit history. Changes take effect on the next protected request. Use the campus staff offboarding procedure to record the operation.
- **Password recovery.** Confirm authorization and verify the exact ID, email, and administrator role before the reset. Use a local masked prompt and confirm the new password. Hash it with `password_hash()` and use a prepared, targeted update in a transaction. Verify that exactly one account was updated. After success, remove the temporary helper and verify the new login in a fresh session. An existing session can remain signed in until logout, inactivity, or account deactivation. If compromise is suspected, follow the approved session-revocation procedure before restoring access.

## Before operating on campus

Assign authorized staff, define what counts as ownership evidence, and agree on pickup verification, safe storage, lost-item retention, and escalation for disputed claims. Review the [security and operational requirements](SECURITY.md#requirements-before-campus-use) before enabling student claim actions. Local verification supports a demonstration. Campus activation requires separate approval.

Return to the [FindIT README](../README.md) for installation and testing resources.
