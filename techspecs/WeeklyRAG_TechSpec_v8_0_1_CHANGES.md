# Weekly RAG — Tech Spec v8.0.1 CHANGES (mfsd-weekly-rag 7.4.0 → 8.0.0)

Supersedes `WeeklyRAG_TechSpec_v1.3_CHANGES_checked.md`. Sources: Commitments spec v1.0.0 §4.2 and §13, the Safeguarding Prompt Layer v1.0.0 (§3, §6, §7), and the Dream & Junk Jobs spec v1.3.1 §4.4.
Status: built in `mfsd-weekly-rag` 8.0.0. Open decisions are in §12; each has a default, and the build uses the default.

## 1. Summary of changes

| # | Change | Depends on |
|---|---|---|
| 1 | Each Red Plan creates or updates a commitment card | `mfsd-commitments` (optional) |
| 2 | New **"Your plan for next week"** step after the summary, which creates the short-term card. Task completion moves here. | none (commitments optional) |
| 3 | New **"Review your cards"** step before the questions | `mfsd-commitments` (optional) |
| 4 | `=== COMMITMENTS THIS WEEK ===` block added to the summary prompts | `mfsd-commitments` (optional) |
| 5 | Dream jobs read from `mfsd_get_dream_junk_jobs()`, falling back to the old table | DJJ 7.0.0 (optional) |
| 6 | Safeguarding: keyword check on saving Red Plans and the short-term plan; a safeguarding context on every SteveGPT call | `mfsd-safeguarding` (required; see §12 D9) |
| 7 | Parent view: two tabs ("{Child}'s view" and "Parent view") plus a weekly parent summary | `MFSD_Parent_Tabs` (bundled) |
| 8 | New week stage `plan`, shown as "Choosing next week's plan" | none |
| 9 | One-time end-of-course screen after the last course week's plan | `mfsd-commitments` (optional) |
| 10 | Code-leak fixes: MBTI codes no longer reach the browser; age fallback changed to 10–14 | none |

## 2. Files

- `mfsd-weekly-rag.php`: all of the server-side changes below.
- `includes/class-mfsd-parent-tabs.php` and `includes/mfsd-shared-helpers.php`: copied **unchanged** from `ai-junk-jobs/includes/`. Both are guarded, so whichever plugin loads first defines them.
- `assets/mfsd-weekly-rag.js` and `assets/mfsd-weekly-rag.css`: the review step, the plan step and the end screen; MBTI text removed.

## 3. Schema (front-end safe upgrade)

`maybe_upgrade_db()` runs on `init` whenever the option `mfsd_rag_db_version` does not match `VERSION`. It no longer waits for `admin_init` or checks `is_admin()`, so a front-end request after the plugin update gets the new columns before any RAG route uses them. Every change is additive and guarded by `SHOW COLUMNS`.

| Table | Column | Notes |
|---|---|---|
| `mfsd_rag_red_plans` | `commitment_id BIGINT UNSIGNED NULL` | The card created from this plan |
| `mfsd_week_summaries` | `disc_type VARCHAR(4) NULL` | Only added if missing. 7.4.0 already writes it, but `install()` never created it. |
| | `steps_json TEXT NULL` | Steve's 2–3 `STEP:` suggestions |
| | `short_term_plan_text VARCHAR(140) NULL` | The plan the child chose |
| | `short_term_commitment_id BIGINT UNSIGNED NULL` | |
| | `short_term_saved_at DATETIME NULL` | Non-null means the week is complete |
| | `parent_ai_summary LONGTEXT NULL` | Per-week parent summary cache |

**The summary row is no longer written with `$wpdb->replace()`.** That call deletes and re-inserts the row, so it would wipe the plan whenever summaries regenerate (caching off). A new `upsert_summary()` updates the row if it exists and inserts it otherwise.

## 4. Red Plan cards (Commitments §4.2, §13.1)

`POST mfsd-rag/v1/red-plan` saves the plan exactly as before (the RAG's own row). It then does three things:

1. **Safeguarding (§8.1).** Calls `mfsd_safeguarding_check( $uid, 'weekly_rag', "Week {w} Red Plan: {question}", $plan, ['field'=>'red_plan','source_ref'=>"rag:{w}:q{id}"] )`.
2. **Card.** If `function_exists('mfsd_create_commitment')`, calls it with:
   - `type` `action`, `source_task` `weekly_rag`, `source_ref` `rag:{week}:q{question_id}`
   - `title`: see the title rule below
   - `detail`: the full plan
   The commitments plugin handles repeated reds (it updates the open card for the same `q{id}`), the 3-reds signal and its own `commitments` keyword check. The returned id is stored in `red_plans.commitment_id`.
3. **Response.** Adds `commitment_id`, which is `null` when commitments is inactive or creation fails. A failed card is logged and never blocks the save.

**Title rule:**
1. If the plan's first sentence is 140 characters or fewer, use it.
2. Otherwise use the `red_plan_title` Steve slot (SUGGESTION kind; max 12 words; the reply is trimmed to 140).
3. Otherwise use the first 137 characters plus "…".

The weekly RAG registers itself as a commitment source (`mfsd_commitment_sources`: label "Weekly RAG", kind "Action", icon 🚦) and as a safeguarding source (`mfsd_safeguarding_sources`: `weekly_rag` → "Weekly RAG").

## 5. "Your plan for next week" step (short-term plan)

- **Summary prompt.** The summary prompt (built-in or template) always ends with: *"Finish with 2–3 lines, each starting `STEP:`, each one small specific thing they could do next week (max 15 words)."*
  - `STEP:` lines are removed from the summary text and stored in `steps_json`, up to 3.
  - They are returned as `steps` from both the fresh and the cached summary.
- **UI.** Under the summary, a new card titled **"Your plan for next week"**:
  - Steve's steps appear as tappable chips (44px minimum) that fill a textarea.
  - The child can edit the text or write their own (maximum 140 characters, with a live counter).
  - **Save my plan** is enabled once there are at least 3 words.
- **Route.** `POST mfsd-rag/v1/short-term-plan {week, plan_text}`:
  - Needs a summary row for the week; otherwise returns 409.
  - Saves to `short_term_plan_text` and `short_term_saved_at`.
  - Runs the safeguarding check: item `"Week {w} plan for next week"`, field `short_term`, `source_ref` `rag:{w}:short_term`.
  - Creates the card if commitments is active: `rag:{week}:short_term`, title = the text, `type` `action`.
  - Clears the week's `parent_ai_summary`.
  - Marks `rag_week_{w}` **completed** (Course Management on). This replaces completion on summary save.
  - Returns `commitment_id`, the cards created this week (red plans and short-term) for the end screen, and `course_end` (§9).
  - Saving again updates the text. The card updates in place, because commitments matches the live `source_ref`.
- **Commitments inactive.** Everything still works. The plan lives only on the summary row, and the task completes.

## 6. Week stage `plan` ("Choosing next week's plan")

`GET mfsd-rag/v1/status` keeps `status` exactly as in 7.4.0 (`not_started` / `in_progress` / `completed`, from answer counts), so older consumers are unaffected. It adds:

- `stage`, which is one of:
  - `not_started`
  - `in_progress`
  - `plan`: every question is answered but no short-term plan is saved yet. This covers the time on the summary screen.
  - `completed`: the plan is saved
- `stage_label`: "Not started" / "In progress" / **"Choosing next week's plan"** / "Completed"
- `short_term_plan`: the saved text, or null

A public helper `mfsd_rag_week_stage( int $user_id, int $week ): array{stage,label}` gives the Parent Portal and Course Progress the same values. **Legacy:** a week whose summary was saved under 7.4.0, with no plan, reports `completed`; Course Management already marked it completed.

The JS treats both `plan` and `completed` as "go to summary"; the plan card shows when no plan is saved.

## 7. "Review your cards" step (Commitments §13.3)

- **When it runs.** Before the first question, but only when:
  - the week has not started, and
  - `window.MFSDCommitments` exists, and
  - at least one card is due.

  The shortcode calls `mfsd_commitments_enqueue_modal()` when commitments is active and passes `commitments: true`.
- **Loading cards.** Cards come from `MFSDCommitments.api('GET','')` (`/mfsd/v1/commitments`, the child's live cards).
  - **Due:** status `open` / `carried` / `not_yet` with `review_week <= week`.
  - Due cards from every source are shown. `checked_in` cards with `review_week <= week` appear as green, information-only rows ("Waiting for {parent}").
- **Each due card offers three buttons (44px minimum):**
  - **Done** → `MFSDCommitments.openCheckin(card, {onDone})` (the shared modal). The row turns green.
  - **Started** → `MFSDCommitments.review(id, 'started', week)`. The row turns amber and the card is carried.
  - **Not yet** → `POST /mfsd/v1/commitments/{id}/review-question` (`MFSDCommitments.reviewQuestion`), then Steve's question is shown with two buttons:
    - **Keep it going** → `review(id,'carry',week)`
    - **Drop it** → `review(id,'drop',week)`
    The row turns red. The commitments plugin applies its own safeguarding context to the question.
- **Continue.** "Start this week's questions" is enabled once every due card has an answer. Any API error marks that row "Couldn't save — you can carry on", so the step can never trap the child.
- **Not shown** when commitments is inactive, nothing is due, or the week is resumed in progress.

## 8. Safeguarding

### 8.1 Keyword checks on save

Two saves are checked, both under source `weekly_rag`:

- Red Plan text (`field` `red_plan`)
- the short-term plan (`field` `short_term`)

`source_ref` is the card reference. The child sees no difference (Prompt Layer §1.6).

### 8.2 SteveGPT context

`steve_task( $slot, $fallback, $tokens, $uid, array $ctx = [], string $append = '' )` passes `['safeguarding' => $ctx]` as the third argument of `SteveGPT_Chatbot::query()`. Each call point sends:

| Call point | Context sent |
|---|---|
| week intro | `kind` summary |
| guidance | `kind` item, `item` = the question, `source_ref` `rag:{w}:q{id}` |
| red suggestions, red plan title | `kind` suggestion |
| question chat, red chat | `kind` chat, `item_text` = message |
| week summary | `kind` summary |
| parent summary | `kind` parent_summary, `user_id` = the **child** |

Every context includes `source_task` `weekly_rag` and `user_id`. SteveGPT 14.0.0+ appends the safeguarding block and strips markers; older SteveGPT ignores the extra argument. There is no hand-added safeguarding rule in any prompt.

### 8.3 Parent redaction (Prompt Layer §6)

- **Parent summary prompt.** A Red Plan or short-term plan with an unreleased moderation flag (`mfsd_safeguarding_is_withheld( child, 'weekly_rag', source_ref )`) is sent as "(not shown)". Its flag is not listed (`mfsd_get_concern_flags( …, include_withheld=false )`).
- **"{Child}'s view" tab.** The same item shows "This answer is being looked at" in place of its text.
- **Released moderation flags** are treated as **urgent**: in the prompt they get the GP / 111 / 999 / Childline / NSPCC wording, and `concern_fallback_html()` already does the same.

## 9. End screen and end of course

- **After the plan saves**, a confirmation card says: *"Your plan is saved."*
  - If any cards were created this week, it lists them: *"These are now on your home screen. Show {parent} when you've done them."* `{parent}` comes from the first card's `parent_name`, with "your parent" as the fallback.
- **End of course, shown once.** When `week == get_option('mfsd_commitments_last_course_week', 3)` and the user meta `mfsd_rag_course_end_seen` is empty, the response includes `course_end: true` and the meta is set. The card then adds: *"That's the end of the course. Your cards stay on your home screen. We'll look at them together in your next module."*

## 10. Parent view and weekly parent summary

- **Entry and access.** `[mfsd_rag]` with `MFSD_Parent_Tabs::is_parent_view()` reads `?student_id=` and runs `assert_linked()`. It skips the ordering gate and never writes task status or anything else. The week still comes from the page title.
- **"{Child}'s view" tab** (server-rendered, read-only):
  - the week's R/A/G counts
  - Steve's summary (cached text with `STEP:` lines removed)
  - each Red Plan with its question
  - the plan for next week
  - "{Child} hasn't finished Week N yet" when there is no summary row
  - moderation-withheld items handled as in §8.3
- **"Parent view" tab.** Shows a placeholder, then the inline script fetches `GET mfsd-rag/v1/parent-summary?week=&student_id=` (wp_rest nonce; logged in; `assert_linked`).
  - **Cache and when it is cleared.** The cache is `parent_ai_summary` on the week's summary row. It is cleared when the plan saves, when the summary is regenerated, on `mfsd_concern_flag_raised` for `weekly_rag` / `commitments`, and on admin reset.
  - **Slot:** `mfsd_stevegpt_map_rag_parent_summary`. Tokens: `child_name`, `age`, `week`, `reds`, `ambers`, `greens`, `previous_weeks`, `red_plans`, `short_term_plan`, `commitments`, `concerns`.
  - **Prompt.** Adult UK English, about 300 words. Sections in this order:
    1. `[SECTION:Something to be aware of]`, only when there are flags, always first
    2. `[SECTION:This week]`
    3. `[SECTION:Their plans]`
    4. `[SECTION:Commitments]`, including the cards waiting for the parent to see them, with how to help and validate
    5. `[SECTION:How you can help]`

    The prompt also covers repeated reds handled with care (Solutions Mindset). No personality codes or names. Final line `PARENT_NOTE:` (saved with `mfsd_concern_save_parent_note` when present).
  - **Flags.** The parent-visible open flags for `weekly_rag`, plus an open `commitments` flag with `trigger_type = 'signal'` (several Red Plans). `build_parent_html( $text, CONCERN_TITLE, concern_fallback_html( $flags ) )` ensures a concern is never lost to an AI failure.
  - **Amber dot.** Shown on the Parent view tab when such a flag is open.

## 11. Summary feed and dream jobs

- **Commitments block.** When `function_exists('mfsd_get_commitments_for_summary')`, the child prompt gets a `=== COMMITMENTS THIS WEEK ===` block covering:
  - cards created this week, by task
  - review outcomes this week
  - cards validated this week
  - repeated reds (with care)

  "Waiting for validation" goes to the **parent** prompt only. The same text is the new `commitments` token on the `summary` slot.
- **Red Plan follow-up** lines now add the card status when the plan has a `commitment_id`: "card: checked in / waiting for parent / validated / still open".
- **Dream jobs.**
  - **Primary:** `mfsd_get_dream_junk_jobs( $uid )['dream_ranked']`, top 5. The getter returns null until the task is completed.
  - **Fallback:** the 7.4.0 `mfsd_ai_dream_jobs_results.ranking_json` read, used when the function is missing or returns null.

## 12. Open decisions for Mark (build uses the default)

| # | Question | Default |
|---|---|---|
| D1 | Is the plan step required before the week counts as complete? | **Yes.** Completion moves to the plan save. |
| D2 | Where does completion sit for students who saw a 7.4.0 summary with no plan? | Leave them completed. They are offered the plan card if they revisit. |
| D3 | Should the review step allow "Skip for now"? | **No.** Each due card needs an answer. API errors never block. |
| D4 | Who checks the Red Plan / plan-for-next-week text? | **Decided (Mark, decision 2): `mfsd-commitments` owns it** when active — one flag under `commitments`, keyed by the card id. The RAG checks under `weekly_rag` only when Commitments is inactive. Parent redaction and the parent summary look at both (`withheld()` takes the card id; `parent_flags()` includes Commitments flags on this child's RAG cards). |
| D5 | Which flags lead the weekly parent summary? | All open, parent-visible `weekly_rag` flags (any week) plus the commitments "several reds" signal. |
| D6 | Should the parent summary use the personality name? | **No.** It uses RAG, plans and commitments only. |
| D7 | Short-term plan length | 140 characters (the card title limit), with a minimum of 3 words. |
| D8 | Last course week | `mfsd_commitments_last_course_week`, default 3. |
| D9 | Should the RAG close when `mfsd-safeguarding` is inactive? | **Decided: yes**, same rule as every task (safeguarding spec §3.1): the shortcode shows "Steve's having a quick break…" and POSTs return 503 `safeguarding_unavailable`. The parent view still works. |

## 13. Follow-ups (not in this build)

- **Parent Portal / Course Progress:** map stage `plan` to "Choosing next week's plan" using `mfsd_rag_week_stage()`. Course Manager status stays `in_progress` until the plan saves.
- **Commitments:** the `rag:{w}:short_term` card title may need a "Plan for next week" prefix in widgets.
- **Steve:** sign off the `red_plan_title` and `parent_summary` prompts and the `STEP:` wording.
- **Legacy `[mfsd_rag]` chatbot shortcode:** the chat check comes from SteveGPT itself and is out of scope.

## 14. Tests

The harness runs against DB `wp_d` with a stubbed SteveGPT, commitments, safeguarding and course status. It checks:

- the schema upgrade on `init`
- a Red Plan creates a card and stores `commitment_id`
- a repeated red updates the same card
- the plan route returns 409 without a summary
- the plan creates the short-term card and completes the task
- summary regeneration keeps the plan (no `replace`)
- the stage goes `in_progress` → `plan` → `completed`
- `STEP:` lines are parsed out of the summary
- the commitments block is in the summary prompt
- the dream jobs getter is used, with the fallback
- every `query()` call carries a safeguarding context
- Red Plan keyword flags are raised
- the parent summary redacts withheld items and treats a released moderation flag as urgent
- the child's view tab hides withheld items
- a non-linked parent gets 403
- the browser payloads carry no MBTI code
- commitments inactive: the plan saves and the task completes

Lint with `php -l` and `node --check`. The JS is smoke-tested in Chromium at 375px.

## 15. v8.0.1 spec: fixes from the Claude Code code review (24 Oct 2026, plugin still 8.0.0)

- **B2: parent summary commitments.**
  - The `=== COMMITMENTS THIS WEEK ===` block for the parent uses `mfsd_get_commitments_for_parent_summary( $sid, $week, 0 )` (Commitments 2.0.0).
  - Because the summary is cached per child, it leaves out any card a linked parent hasn't unlocked yet.
  - With an older Commitments, the parent block is left out.
- **B3: open flags mean not closed.** `parent_flags()` uses `open_flags()` (`mfsd_get_open_concern_flags()`, with a fallback), so a flag a parent marked "seen" still shows until an admin closes it.
- **Card creation fails.** If `mfsd_create_commitment()` returns a `WP_Error` for a Red Plan or the plan for next week, the text is checked here under `weekly_rag`.
- **Legacy weeks.** A week completed under 7.4.0 (stage `completed`, no plan) now gets the "Your plan for next week" card.
- **Word count.**
  - `str_word_count()` ignored digits ("5 mins", "Year 7"). It's replaced by a whitespace token count, the same as the JS.
  - It's used for the Red Plan `word_count` and the 3-word minimum on the plan for next week.
- **"Hasn't finished".**
  - The parent summary now waits on the week stage (plan saved, or completed under 7.4.0), not on `ai_summary`.
  - An empty child summary no longer leaves the message up for good. Stage `plan` shows "is choosing their plan for next week".
- **No codes.**
  - `disc_scores` is removed from the summary response.
  - The JS DISC plot never drew anyway, because `disc_type` wasn't sent.
