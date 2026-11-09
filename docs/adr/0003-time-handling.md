# ADR 0003: Time handling (UTC storage, IANA local rules, DST policy)

- Status: Accepted (2026-11-08)

## Decision
1. The app timezone is `UTC`. Every stored instant (`scheduled_start_at`, `completed_at`, `sent_at`, …) is UTC.
2. Each user has an IANA timezone (e.g. `America/New_York`) in `user_preferences`. Each occurrence also stores the timezone it was planned in.
3. Recurring local times (daily review times, preferred start times, work windows, quiet hours) are stored as **local wall-clock values** (`"19:00"`, weekday sets), never as precomputed UTC. They are converted to UTC each time they are evaluated.
4. **DST policy:**
   - A local time that doesn't exist (spring-forward gap) fires at the **next valid minute**. For example, 02:30 becomes 03:00.
   - A local time that happens twice (fall-back) fires **once**, on its first occurrence. Unique keys such as `(user_id, local_date, kind)` enforce this.
5. A user's "local day" is always computed from their timezone. The server date is never used.
6. Tests that involve time use `Carbon::setTestNow()` and cover the New York DST transition days plus at least one non-US timezone.
