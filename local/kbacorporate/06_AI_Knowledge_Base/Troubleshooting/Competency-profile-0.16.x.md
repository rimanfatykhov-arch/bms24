# Troubleshooting — Competency Profile 0.16.x

## Symptom: `Мои компетенции` link is missing
Root cause in 0.16.0: own-profile visibility depended on a newly added capability. Fixed in 0.16.1 by allowing authenticated users to view their own profile directly.

## Symptom: database read error on `Мои компетенции`
Root cause in 0.16.1: query used `frameworkid` instead of Moodle 4.0 field `competencyframeworkid`. Fixed in 0.16.2.

## Symptom: user has a course but zero competencies
Verify the course is actually linked to competencies in Moodle core. From 0.16.3, Learning Plan assignment is not required; enrolled-course competencies are a primary source.
