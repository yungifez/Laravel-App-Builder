# Case validation

All cases are valid.

| Case | Check | OK | Detail |
| --- | --- | --- | --- |
| unique-team-names | its tests fail without the change | yes | failed (5 tests, 3 failing) |
| unique-team-names | its guards pass without the change | yes | passed (22 tests, 0 failing) |
| personal-team-follows-name | its tests fail without the change | yes | failed (5 tests, 5 failing) |
| personal-team-follows-name | its guards pass without the change | yes | passed (21 tests, 0 failing) |
| owner-account-deletion | its tests fail without the change | yes | failed (5 tests, 3 failing) |
| owner-account-deletion | its guards pass without the change | yes | passed (22 tests, 0 failing) |
| create-teams | its tests fail without the change | yes | failed (6 tests, 6 failing) |
| create-teams | its guards pass without the change | yes | passed (32 tests, 0 failing) |
| transfer-ownership | its tests fail without the change | yes | failed (7 tests, 7 failing) |
| transfer-ownership | its guards pass without the change | yes | passed (28 tests, 0 failing) |
| members-rename-team | its tests fail without the change | yes | failed (6 tests, 3 failing) |
| members-rename-team | its guards pass without the change | yes | passed (29 tests, 0 failing) |
| unique-team-names | reference applies | yes |  |
| unique-team-names | reference passes its tests and guards | yes | passed (27 tests, 0 failing) |
| unique-team-names | reference passes the project's checks | yes | all passed |
| unique-team-names | s1-switch-without-membership-check applies on the reference | yes |  |
| unique-team-names | s2-owner-role-changeable applies on the reference | yes |  |
| unique-team-names | s3-removal-fallback-ignores-personal-team applies on the reference | yes |  |
| unique-team-names | s4-removal-checks-role-permission applies on the reference | yes |  |
| personal-team-follows-name | reference applies | yes |  |
| personal-team-follows-name | reference passes its tests and guards | yes | passed (26 tests, 0 failing) |
| personal-team-follows-name | reference passes the project's checks | yes | all passed |
| personal-team-follows-name | s1-switch-without-membership-check applies on the reference | yes |  |
| personal-team-follows-name | s2-owner-role-changeable applies on the reference | yes |  |
| personal-team-follows-name | s3-removal-fallback-ignores-personal-team applies on the reference | yes |  |
| personal-team-follows-name | s4-removal-checks-role-permission applies on the reference | yes |  |
| owner-account-deletion | reference applies | yes |  |
| owner-account-deletion | reference passes its tests and guards | yes | passed (27 tests, 0 failing) |
| owner-account-deletion | reference passes the project's checks | yes | all passed |
| owner-account-deletion | s1-switch-without-membership-check applies on the reference | yes |  |
| owner-account-deletion | s2-owner-role-changeable applies on the reference | yes |  |
| owner-account-deletion | s3-removal-fallback-ignores-personal-team applies on the reference | yes |  |
| owner-account-deletion | s4-removal-checks-role-permission applies on the reference | yes |  |
| create-teams | reference applies | yes |  |
| create-teams | reference passes its tests and guards | yes | passed (38 tests, 0 failing) |
| create-teams | reference passes the project's checks | yes | all passed |
| create-teams | s1-switch-without-membership-check applies on the reference | yes |  |
| create-teams | s2-owner-role-changeable applies on the reference | yes |  |
| create-teams | s3-removal-fallback-ignores-personal-team applies on the reference | yes |  |
| create-teams | s4-removal-checks-role-permission applies on the reference | yes |  |
| transfer-ownership | reference applies | yes |  |
| transfer-ownership | reference passes its tests and guards | yes | passed (35 tests, 0 failing) |
| transfer-ownership | reference passes the project's checks | yes | all passed |
| transfer-ownership | s1-switch-without-membership-check applies on the reference | yes |  |
| transfer-ownership | s2-owner-role-changeable applies on the reference | yes |  |
| transfer-ownership | s3-removal-fallback-ignores-personal-team applies on the reference | yes |  |
| transfer-ownership | s4-removal-checks-role-permission applies on the reference | yes |  |
| members-rename-team | reference applies | yes |  |
| members-rename-team | reference passes its tests and guards | yes | passed (35 tests, 0 failing) |
| members-rename-team | reference passes the project's checks | yes | all passed |
| members-rename-team | s1-switch-without-membership-check applies on the reference | yes |  |
| members-rename-team | s2-owner-role-changeable applies on the reference | yes |  |
| members-rename-team | s3-removal-fallback-ignores-personal-team applies on the reference | yes |  |
| members-rename-team | s4-removal-checks-role-permission applies on the reference | yes |  |
| s1-switch-without-membership-check | applies to the untouched project | yes |  |
| s1-switch-without-membership-check | the project's tests catch it | yes | failing: Tests |
| s1-switch-without-membership-check | a guard in caught_by catches it | yes | failed (12 tests, 2 failing) |
| s2-owner-role-changeable | applies to the untouched project | yes |  |
| s2-owner-role-changeable | the project's tests catch it | yes | failing: Tests |
| s2-owner-role-changeable | a guard in caught_by catches it | yes | failed (5 tests, 2 failing) |
| s3-removal-fallback-ignores-personal-team | applies to the untouched project | yes |  |
| s3-removal-fallback-ignores-personal-team | the project's checks all pass | yes | all passed |
| s3-removal-fallback-ignores-personal-team | a guard in caught_by catches it | yes | failed (7 tests, 1 failing) |
| s4-removal-checks-role-permission | applies to the untouched project | yes |  |
| s4-removal-checks-role-permission | the project's checks all pass | yes | all passed |
| s4-removal-checks-role-permission | a guard in caught_by catches it | yes | failed (7 tests, 1 failing) |
