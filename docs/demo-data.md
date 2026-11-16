# Local MSEUF demo events

These three events are synthetic course-project fixtures. Their dates, descriptions, capacities, ticket settings, and access points are not official MSEUF information. The `— Demo` titles identify them as simulations. The year-end party is university-wide and is not a CCMS party.

The fixture creates Cloud-side event drafts only. It creates no users, Firebase identities, registrations, tickets, credentials, snapshots, admission records, or authority grants. It does not publish events or modify an Event Node. The existing Cloud-to-LAN snapshot workflow remains an explicit organizer action.

## Event settings

All times below use `Asia/Manila` (PHT). Laravel stores the equivalent UTC timestamps.

| Event | Venue | Capacity | Registration window (PHT) | Event time (PHT) | Eligibility | Re-entry | Gates |
| --- | --- | ---: | --- | --- | --- | --- | --- |
| MSEUF University Intramurals 2026 — Demo | MSEUF University Gymnasium | 500 | Nov 15, 2026 8:00 AM–Nov 16, 2026 8:00 AM | Nov 16, 2026 9:00 AM–10:00 PM | Students | No | Main Entrance, Gate A, Gate B |
| MSEUF Year-End Party 2026 — Demo | Enverga Multipurpose Hall | 200 | Nov 16, 2026 8:00 AM–Nov 20, 2026 4:00 PM | Nov 20, 2026 5:00 PM–10:00 PM | Students | Yes | Main Entrance |
| Miss Enverga University 2026 — Demo | MSEUF University Gymnasium | 400 | Nov 16, 2026 8:00 AM–Nov 18, 2026 5:00 PM | Nov 18, 2026 6:00 PM–10:00 PM | Public | No | Main Entrance, Gate A |

Every event uses the standard identity policy, has ticket transfers disabled, and starts as a draft. Simulated payments stay disabled. General Admission is free. Intramurals and the year-end party each have one student-only category with capacity equal to the event capacity. Miss Enverga has two public categories: General Admission (300) and Supporter / Alumni Pass (100).

### Descriptions

- **Intramurals:** Celebrate Envergan unity and sportsmanship through athletic competitions, spirited college rivalries, team performances, and activities that bring the university community together. Students are invited to support their respective colleges and celebrate teamwork, determination, and school pride.
- **Year-End Party:** Celebrate the year's achievements, friendships, and memorable experiences at the MSEUF Year-End Party. Join fellow Envergans for an evening of music, performances, interactive activities, and university spirit. This is a university-wide simulation, not a CCMS Year-End Party.
- **Miss Enverga:** Witness elegance, confidence, intelligence, and Envergan excellence at Miss Enverga University 2026. Celebrate outstanding representatives as they showcase their talents, advocacies, and dedication to the Envergan community. Students, alumni, families, and outside supporters are welcome.

## Seed an isolated local Cloud database

Do not run this seeder against Render, Neon, an Event Node, or a database containing real event records. Use a local database and an existing local organizer or administrator. The seeder refuses production, Render, Event Node, Neon, and other non-local database hosts. It is separate from `DatabaseSeeder`, `INGRESS_DEMO_MODE`, and the legacy account/event seeders.

1. Confirm the existing venue rows are present. If they are missing, run `php artisan db:seed --class="Database\Seeders\VenueSeeder"` in the local environment. That seeder upserts the reference venues by code; the demo fixture itself only looks up `GYM` and `MPH` and never creates or updates venue rows.
2. Find the ID of an existing local organizer or administrator:

   ```sql
   SELECT id, email FROM users WHERE role IN ('organizer', 'admin') ORDER BY id;
   ```

3. In the local `api/.env`, set:

   ```dotenv
   APP_ENV=local
   INGRESS_RUNTIME=cloud
   INGRESS_MSEUF_DEMO_EVENTS_ENABLED=true
   INGRESS_MSEUF_DEMO_EVENTS_ORGANIZER_ID=123
   ```

   Replace `123` with the existing local account's ID. Keep the normal local `DB_CONNECTION` and database host. Do not set `DB_URL` to Neon or a hosted database.

4. Clear cached configuration and run only the focused seeder:

   ```sh
   php artisan config:clear
   php artisan db:seed --class="Database\Seeders\MseufDemoEventsSeeder"
   ```

Rerunning is safe while all matching events remain drafts and have no registrations, checkout orders, prepared snapshots, admissions, or transferred authority. The seeder checks all three events before writing and aborts the full operation if it finds existing lifecycle data or a slug owned by another event. It never resets a published event to draft.

After local setup, open the Cloud organizer interface in the local app. To try guest registration or scanning, explicitly publish a draft in that isolated environment and use the existing Firebase/student and snapshot/authority workflows. Do not use this fixture to provision test identities or attendee records.

## Create equivalent drafts through the Cloud organizer interface

Use the organizer interface against an isolated local or staging Cloud database, with an existing authorized organizer account. Do not add these synthetic records to a production Render/Neon database.

1. Create each event as a draft using the title, description, venue, capacity, PHT schedule, student/public eligibility, standard identity policy, and re-entry setting in the table above. Keep ticket transfers and simulated payments disabled.
2. In the event details, keep General Admission as the default, free category. Set its eligibility and capacity from the table. For Miss Enverga, add the public, free Supporter / Alumni Pass category with capacity 100.
3. The existing event wizard creates Main Entrance, Gate A, and Gate B. In the event's Gate access points section, leave active only the gates listed above: deactivate Gate A and Gate B for the Year-End Party, and deactivate Gate B for Miss Enverga. Keep all three active for Intramurals.
4. Confirm all three events still show Draft. Leave them unpublished.

The event wizard and Cloud-to-LAN handoff remain unchanged. Publishing, student or guest registration, snapshot preparation, authority transfer, and LAN admission are separate explicit actions.
