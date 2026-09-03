# Seed assets

Optional binary assets used by the dev seed (`App\Seed\BcclSeeder`, run via `app:bccl:seed`/
`app:demo:seed` — `backend/docs/commands.md`).

## `bccl-logo.png`

Drop a PNG here named exactly `bccl-logo.png` (≤ 500 KB, same limits as the upload
endpoint) to ship a default logo for the seeded club **B CHARPENNES CROIX LUIZET**.

`BcclSeeder` stores it via `LogoStorage` and sets the club's `logoUrl` to the
public serve route (`/api/clubs/{id}/logo?v=<hash>`), exactly like a manual upload.
If the file is absent the seed skips the logo silently — it never fails on it.
