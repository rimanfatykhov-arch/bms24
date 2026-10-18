# Troubleshooting v0.34.1

## The dashboard is empty

Open the economic registry and add values for the company. Check the selected date range, category and **Only verified values** filter.

## Only KPI cards are visible, but no line chart

At least two actual values of the same indicator are required. Plan and forecast do not count as trend points.

## The currency warning is shown

The selected monetary indicator contains values in more than one currency. Filter the period or correct the source data. The dashboard intentionally does not convert currencies.

## The audit block has no values

Economic values must be linked explicitly to that audit and use the `actual` scenario.

## The audit is old and data is not visible

Reset the date filters. An old audit-linked value is included automatically only when the user has not set an explicit date range.

## Charts do not render after update

Purge Moodle caches and verify that the site theme renders Moodle core charts. Check the browser console and server logs for Chart API errors.

## The menu item is missing

Verify company access and the `vieweconomicindicators` capability, then purge caches.

## CSV contains fewer indicators than expected

The export follows the current panel selection and filters. The panel supports a maximum of 8 indicators at once.
