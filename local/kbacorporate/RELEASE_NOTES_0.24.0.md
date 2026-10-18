# KBA Corporate Dashboard 0.24.0

This release turns a selected BMS implementation package into a structured customer request.

## Customer flow

1. Open **BMS implementation**.
2. Select a package.
3. Confirm the contact person, email and optional phone.
4. Add a comment and submit the request.
5. Review the saved request card and open the implementation plan when it becomes available.

## Administrator flow

- Open the implementation client registry and export request contacts to CSV.
- Open a program to correct contact details, assign a curator and manage commercial/program statuses.
- When automatic Bitrix24 deal creation is enabled, the request contact details and comment are included in the deal description.

## Upgrade

Install the full ZIP over the existing plugin and complete the Moodle database upgrade. Existing programs remain valid; the new fields are nullable.
