# Ingress Demonstration Data & Real MSEUF Facts

> **Local-only legacy fixture:** this page describes the opt-in demo seed, which contains realistic names, student identifiers, and public event labels. Do not seed it into Render, Neon staging, an Event Node, or any database that may hold real attendee records. The former mobile attendee login shown below has been removed; student and organizer demo flows use the website, and the current scanner pairs with a separate Event Node over HTTPS. Use the cloud operator workflow in [Deployment and Operations](deployment.md#prepare-synthetic-staging-accounts-and-venues) for synthetic organizer and staff accounts, and create synthetic events in the organizer portal. Student web tests require a Firebase-verified test identity. Production refuses `INGRESS_DEMO_MODE=true`.

**Manuel S. Enverga University Foundation (MSEUF)**  
*Reference Guide: Real Public Facts vs. Synthetic Ingress Demo Configuration*

---

## 1. Overview

To keep the Ingress course final project demonstration realistic and defensible during academic presentations, all seeded events and venues are derived directly from official, publicly verifiable Manuel S. Enverga University Foundation resources.

At the same time, operational parameters required to demonstrate the application—such as entrance gate names, seat capacities, registration windows, staff assignments, and ticket issuance—are explicitly identified as **synthetic Ingress demo values**.

```
+--------------------------------------------------------------------------+
|                        REAL PUBLIC FACTS                                 |
|  • Event Title: MSEUF University Intramurals 2026                        |
|  • Official Calendar Dates: September 30 – October 3, 2026               |
|  • Official Venue: University Gymnasium (Athletic Complex)               |
|  • Source: mseuf.edu.ph/events/2026 & mseuf.edu.ph/oscr                  |
+--------------------------------------------------------------------------+
                                    │
                                    ▼ Combined in Ingress Demo
+--------------------------------------------------------------------------+
|                   SYNTHETIC DEMO CONFIGURATION                           |
|  • Demo Entrances: Entrance 1, Entrance 2, Entrance 3                    |
|  • Demo Capacity: 50 seats (demonstration cap)                           |
|  • Demo Schedule Window: Active window for registration and scanning     |
|  • Staff Assignments: Staff Alex (Entr 1), Staff Bea (Entr 2), Staff Chris|
|  • Primary Student: Ron Vincent Cada (A23-35524, CCMS)                   |
+--------------------------------------------------------------------------+
```

---

## 2. Event Reference Table

| Event | Official Source | Real Facts Used | Synthetic Demo Configuration |
| :--- | :--- | :--- | :--- |
| **MSEUF University Intramurals 2026** *(Main Demo Event)* | [MSEUF Events Calendar 2026](https://mseuf.edu.ph/events/2026) & [OSCR University Intramurals](https://mseuf.edu.ph/oscr/pages/university-intramurals) | • Official Title: *Intramurals 2026* / *University Intramurals*<br>• Official Dates: Sept 30 – Oct 3, 2026<br>• Official Venue: *University Gymnasium* ([OSCR Facility](https://mseuf.edu.ph/oscr/pages/facilities-12))<br>• Description: University-wide sports, parade, e-sports, and athletic competition across colleges | • Venue Capacity: Configured to 50 seats for demonstration<br>• Registration Window: Open now (`now - 1d` to `now + 7d`)<br>• Event Timing: Scheduled active for mobile demo (`now + 2d` to `now + 5d`)<br>• Demo Entrances: **Entrance 1**, **Entrance 2**, **Entrance 3**<br>• Staff: Alex (Entr 1), Bea (Entr 2), Chris (Entr 3)<br>• Primary Attendee: Ron Vincent Cada |
| **MSEUF 79th Foundation Anniversary: Thanksgiving Mass** | [Enverga University 79th Foundation](https://mseuf.edu.ph/79/event/miss-enverga) & [2026 Calendar](https://mseuf.edu.ph/events/2026) | • Official Title: *Thanksgiving Mass (79th Anniversary)*<br>• Official Date: February 9, 2026 (8:00 AM - 9:00 AM)<br>• Official Venue: *University Gymnasium* | • Capacity: 300 seats<br>• Access Point: *Main Gymnasium Entrance* (`MAIN-GYM`)<br>• Lifecycle Status: `completed` (demonstrating concluded historical events) |
| **Miss Enverga University 2026** | [Miss Enverga University Pageant Portal](https://mseuf.edu.ph/79/event/miss-enverga) & [Alumni Calendar](https://mseuf.edu.ph/79/event/alumni) | • Official Title: *Miss Enverga University 2026*<br>• Official Date: February 12, 2026 (5:00 PM - 8:00 PM)<br>• Official Venue: *University Gymnasium*<br>• Description: University coronation pageant showcasing student talent and advocacy | • Capacity: 500 seats<br>• Access Points: *Main Entrance* (`MAIN`), *North Gate* (`NORTH`)<br>• Lifecycle Status: `draft` (demonstrating unpublished organizer workflow) |

---

## 3. Venue Reference

| Venue Name | Code | Location | Real vs. Synthetic Attributes |
| :--- | :--- | :--- | :--- |
| **MSEUF University Gymnasium** | `GYM` | University Athletic Complex, Lucena City | **Real**: Official university sports and major assembly facility documented on the OSCR portal (`https://mseuf.edu.ph/oscr/pages/facilities-12`). Facility renovation announced in official news on July 23, 2026 (`https://mseuf.edu.ph/news/2026/7/23/enverga-university-partners-with-linksports-inc-begins-gymnasium-renovation`).<br>**Synthetic**: Default capacity set to 1,500. |
| **MSEUF University Auditorium** | `AUD` | Main Academic Building, 2nd Floor, Lucena City | **Real**: Primary university theater and academic hall.<br>**Synthetic**: Default capacity set to 600. |
| **Enverga Multipurpose Hall** | `MPH` | Student Activity Center, Lucena City | **Real**: Student organization and seminar hall.<br>**Synthetic**: Default capacity set to 300. |
| **CCS Innovation & Robotics Lab** | `CCSLAB` | College of Computer Studies Building, 3rd Floor | **Real**: Computer science laboratory and research facility.<br>**Synthetic**: Default capacity set to 80. |

---

## 4. Demonstration Accounts & Identities

All demonstration accounts use the password: `password`.

### 4.1 Primary Demo Student
- **Name**: Ron Vincent Cada
- **Student ID**: `A23-35524`
- **Email**: `a23-35524@student.mseuf.edu.ph`
- **College**: CCMS (College of Computing and Multimedia Studies / Computer Studies)
- **Role**: `student`
- **Status**: Registered in `UserSeeder.php`

#### Primary Student Acceptance Scenario:
1. **Sign In**: Ron signs in to the local demo website with the seeded account.
2. **Discovery**: Opens the event catalog and selects **MSEUF University Intramurals 2026**.
3. **Registration**: Registers for the event; capacity changes inside the server transaction.
4. **Ticket Issuance**: Receives a ticket with an opaque credential (`ingress:v1:ING-XXXX:<secret>`).
5. **Display QR**: Opens the web ticket and displays or downloads its QR as a PDF.
6. **Admission at Entrance 1**: Import and activate the event snapshot on one Event Node, then pair an assigned staff scanner over HTTPS. Staff Alex Rivera previews and explicitly confirms admission at **Entrance 1**.
7. **Anti-Passback Block**: Staff at another assigned gate scans the same QR; the Event Node rejects a second entry while the attendee is inside.
8. **Organizer Dashboard**: After synchronization, the organizer sees the admission in the attendance view.

### 4.2 Event Staff Accounts
- **Entrance 1 Staff**: Staff Alex Rivera (`staff.gate_a@mseuf.edu.ph`) $\rightarrow$ Assigned to **Entrance 1**
- **Entrance 2 Staff**: Staff Bea Ramos (`staff.gate_b@mseuf.edu.ph`) $\rightarrow$ Assigned to **Entrance 2**
- **Entrance 3 Staff**: Staff Chris Dizon (`staff.gate_c@mseuf.edu.ph`) $\rightarrow$ Assigned to **Entrance 3**

### 4.3 Organizer & Admin Accounts
- **Organizer 1**: Prof. Juan Dela Cruz (`organizer@mseuf.edu.ph`) — CCMS Department Organizer
- **Organizer 2**: Engr. Maria Santos (`organizer2@mseuf.edu.ph`) — Engineering Department Organizer
- **Administrator**: MSEUF Administrator (`admin@mseuf.edu.ph`) — System Administrator

### 4.4 Additional Synthetic Students
- **Student 2**: Patricia Reyes (`student2@mseuf.edu.ph`, Student ID: `2024-01002`)
- **Student 3**: Mark Aquino (`student3@mseuf.edu.ph`, Student ID: `2024-01003`)

---

## 5. Security & Isolation Guarantee

The environment flag `INGRESS_DEMO_MODE=true` strictly controls the seeding of demonstration accounts:
- In demo mode (`INGRESS_DEMO_MODE=true`), `UserSeeder` and `EventSeeder` create the accounts and events listed above.
- Outside demo mode (`INGRESS_DEMO_MODE=false`), `DatabaseSeeder`, `UserSeeder`, and `EventSeeder` create **no** user accounts and **no** events. Only reference venues are created.
- In production (`APP_ENV=production`), enabling demo mode raises a fatal `LogicException` on boot to prevent accidental exposure of predictable credentials.
