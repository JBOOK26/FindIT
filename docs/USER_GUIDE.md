# FindIT user guide

Open [FindIT on localhost](http://localhost/findit/) to reach Login or Account. Use disposable accounts and reports for classroom demonstrations. The [installation guide](INSTALLATION.md) covers first-time setup.

## Student tasks

### Register and log in

1. Choose **Register**. Enter your name, student ID, email, and password, then confirm the password. The form requires at least eight password characters and accepts up to 72 bytes.
2. Return to **Log In** and enter your email and password. Your **Account** page shows the student actions.
3. Use **Log Out** on Account when you finish, especially on a shared computer. After 30 minutes of inactivity, sign in again.

### Report a Lost or Found item

1. Choose **Report an Item** from Account. Select **Lost** if you are looking for something you misplaced. Select **Found** if you picked up an item that appears to belong to someone else.
2. Pick a category and enter a descriptive title, relevant details, and the location. You can add an occurrence date and time in Philippine time.
3. To attach a photo, choose a JPEG, PNG, or WebP file up to **5 MiB** and **24 megapixels**. Preview it before submitting. Keep contact details and evidence you would use to prove ownership out of the report and photo.
4. Submit the report. Find it in **My Reports**.

### Browse and manage reports

- Use **Browse Items** to search by title or description, filter by category and report type, and open item details. Cancelled reports are hidden from browsing.
- Use **My Reports** to view your own Lost and Found reports, including past cancellations. Open a report to see its status and available actions.
- You can edit or cancel an **Open** report with no claims. Editing can replace the photo. Cancellation requires confirmation, is final, and keeps the report in your own history.

### View matches and notifications

1. In **My Reports**, choose **View Potential Matches** on one of your open Lost reports. The report details page shows suggested Found reports and reasons such as a similar title or location.
2. Open the bell to see **Notifications**. Choose **Check for matches** to create alerts for new suggestions. A check covers up to three open Lost reports. Use **Check remaining reports** when more are available.
3. Use **All** or **Unread** to filter the inbox. Open an alert to follow its link. FindIT rechecks access and match eligibility before opening the related report.
4. Use **Mark as read** for one notice or **Mark all as read** for the inbox snapshot. If another tab refreshed the snapshot, reload the inbox before trying again.

A possible match suggests that two reports may describe the same item. Staff verify ownership separately when the claim workflow is available.

### Submit or track a claim

Student claim submission and withdrawal are disabled in the original installation pending operational approval. You can still use **My Claims** to view your existing claims.

In a separately authorized environment with claims enabled, open another person's **Open Found** report and choose **Claim item**. Send private identifying details the staff can use to verify ownership. You can submit one claim per report. Use **My Claims** to check the decision or withdraw a pending claim. An approved claim is awaiting pickup. It becomes **Returned** after staff confirm the handoff.

## Administrator tasks

Sign in with an active administrator account provisioned by an authorized local operator. The **Account** dashboard links to **Manage Claims** and **Administrator Matches**. See the [administrator guide](ADMIN_GUIDE.md) for account setup and evidence handling.

| Task | What to do |
| --- | --- |
| Review a claim | Open **Manage Claims**, choose a pending request, and check the item details and private evidence. |
| Approve or reject | Approve a verified claim to reserve its item for pickup. Reject a claim when verification fails. Optional rejection feedback goes to that claimant. |
| Confirm a handoff | Choose **Confirm handoff** after the item is returned. FindIT completes the claim, resolves the report, and closes competing pending claims. |
| Cancel a failed pickup | Cancel the approved claim to reopen the Found report. Other pending claims remain available for review. |
| Review possible matches | Open **Administrator Matches**, select an open Lost report, and compare it with a suggested Found report. Treat the comparison as a lead for follow-up. |

## Sample classroom walkthrough

1. With one disposable student account, create a Found report for a sample item. With a second account, report the same kind of item as Lost. Use the same category and enough shared detail, such as the item type and color, to make a useful comparison.
2. With the Lost report owner, open **Potential Matches**, then use **Check for matches**. Open the resulting alert and mark it read. Use a separate browser profile for each account so your sessions stay separate.
3. With an active test administrator, open **Administrator Matches** and review the same pair side by side.
4. Demonstrate claim submission and staff decisions only in an authorized disposable environment with claims enabled. Keep the original installation's claim flag disabled.

Return to the [FindIT README](../README.md) for the other guides.
