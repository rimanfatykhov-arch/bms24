# BMS 0.7.6 — Learning Linkage

## Purpose

Connect Development Plan actions to the existing Moodle/KBA Corporate learning contour without creating a second learning engine inside BMS.

## Ownership

- BMS owns the development-plan intent and the canonical link to a selected company course.
- KBA Corporate owns the company learning catalogue and licences.
- Moodle Core owns enrolments, course completion, grades and certificates.
- Assessment Engine owns competency-attainment decisions.

## Link model

`local_kbacompany_devitem.courseid` stores the selected Moodle course id when the action is linked to a company-catalogue course. `learningurl` remains available for an explicit external or activity URL.

The selected course must exist in the current company learning catalogue exposed through the KBA Corporate bridge. BMS does not accept an arbitrary Moodle course id as a canonical company-course link.

## Live status

The development-plan UI reads enrolment and completion directly from Moodle for the target employee and displays:

- not started;
- in progress;
- completed;
- unavailable.

This status is not persisted as competency evidence or competency attainment.

## Competency boundary

Course completion never closes a competency gap automatically. It may be used as evidence in a later formal assessment. Only an approved Assessment Engine decision can update the Employee Competency Profile.
