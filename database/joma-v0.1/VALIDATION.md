# JOMA Physical Schema v0.1 — validation status

## Executed

- `python tools/generate-joma-schema.py`: generated 70 InnoDB tables, 173 foreign keys, and nine physical ER views.
- `python tests/joma_schema_static.py`: 18 structural tests passed.
- The structural checks inspect deterministic output, primary-key strategy, declared types and candidate keys of FK targets, source indexes, safe referential actions, pre-case NULLs, the optional unique session link, authority XOR, active-account/open-assignment uniqueness, typed publication audiences, bank destination/method consistency, and private-note separation.

## Not executed

- Actual MySQL parser/import or enforcement of CHECK/UNIQUE/FK.
- Two-connection interval-overlap races, deadlock recovery or idempotent retry.
- mysqli/API/browser authorization, publication or cryptography tests.
- Visual rendering of Mermaid diagrams.
- Migration from existing clinic tables or deployment on the user's hosting account.

No MySQL server/client or Docker is installed in the current workspace. Static tests are NOT substitutes for those runtime checks.

## Runtime test gates

Use a NEW disposable database and artificial data. Required target: Oracle MySQL 8.0.16+ with InnoDB, strict SQL mode, utf8mb4_unicode_ci and UTC connections. This is not a live upgrade or a claim of MariaDB/MySQL 5.7 compatibility.

1. Import all tables and FKs; inspect warnings and verify actual metadata.
2. Attempt two ACTIVE/LOCKED accounts for the same Person; second must fail. INACTIVE history must remain possible.
3. Attempt two ASSIGNED rows for one admission; second must fail. Closed assignment history must remain possible.
4. Attempt authority basis with both/neither direct role and delegation; reject. Reject mismatched Actor/Scope.
5. Create a second session referencing the same non-null appointment; reject. Permit separate sessions with NULL appointment and a valid Case/clinician.
6. Reject cross-Case/clinician session references and mismatched assignment/acceptance ownership.
7. Reject bank receipt without destination or with a destination in another financial space. Reject a falsely copied bank-method flag.
8. Reject malformed JSON and wrong response schema in the application. Valid JSON alone must not pass form validation.
9. Exercise drafts and explicit publication audiences through all endpoints; DB presence is not permission.
10. Race overlapping appointments/Holds through TWO transactions using the required resource-lock protocol. Indexes alone do not provide interval exclusion.
11. Race expiry/confirmation and revoke/publish; verify one consistent business result and no unauthorized disclosure.
12. Verify immutable published versions/history through application commands and restricted operational DB privileges.
13. Exercise duplicate callbacks/commands, late payment, notification failure and full transaction rollback/retry.
14. Verify no actual private-note plaintext, raw test protocol or secret enters general response/config/audit stores.

DDL auto-commits. Do not run DROP/TRUNCATE or disable FK checks to force success. After a failed disposable import, investigate and use a fresh disposable database; do not silently continue in production.
