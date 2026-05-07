# MFSD Weekly RAG — Technical Specification v1.0

**Plugin directory:** `mfsd-weekly-rag/`
**Shortcode(s):** `[mfsd_rag]`
**Version:** 7.1.0
**Author:** MisterT9007
**Purpose:** A six-week self-assessment tool for students on the High Performance Pathway. Each week, students answer a set of RAG (Red/Amber/Green), MBTI (Myers-Briggs Type Indicator), and optional DISC (Dominance, Influence, Steadiness, Conscientiousness) questions, presented in an interleaved order (2 RAG, 1 MBTI, repeat). When a student answers Red, they are prompted to write an improvement plan. At the end of each week's questions, an AI-generated summary is produced via SteveGPT. The plugin integrates with `mfsd-ordering` for course progression gating and supports voice input (Web Speech API STT) and speech output (Web Speech API TTS) for every AI-generated piece of text. The week number is inferred from the page title (e.g. "Week 3 RAG"). Integrates with UltimateMember (`um_get_display_name`) and ProfilePress (`pp_dob`) for personalisation.

---

## File Structure

| File | Purpose |
|------|---------|
| `mfsd-weekly-rag.php` | Single-file plugin: singleton class, DB install/upgrade, age helpers, asset registration, shortcode, all REST routes and callbacks, DISC calculation, MBTI logic, admin page and question management |
| `assets/mfsd-weekly-rag.js` | React-style vanilla JS frontend: TTS engine, STT engine, question rendering, RAG/MBTI/DISC answer handling, Red follow-up flow, summary screen, week tabs, DISC polar plot |
| `assets/mfsd-weekly-rag.css` | Dark "gamer" theme with Exo 2 and Nunito fonts, RAG traffic lights, chat bubbles, DISC scale, TTS controls, mic button, Red plan progress bar |

---

## Database Schema

All tables created in `register_activation_hook` via `MFSD_Weekly_RAG::install()`. Auto-upgrade runs on `admin_init` via `maybe_upgrade_db()`.

### wp_mfsd_rag_questions

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `q_order` | INT | Display order within type |
| `q_type` | ENUM('RAG','MBTI','DISC') | Question type |
| `q_text` | TEXT | The question text shown to the student |
| `red_label` | VARCHAR(16) | Custom Red button label (RAG only) |
| `amber_label` | VARCHAR(16) | Custom Amber button label (RAG only) |
| `green_label` | VARCHAR(16) | Custom Green button label (RAG only) |
| `red_score` | INT | Score awarded for Red answer |
| `amber_score` | INT | Score awarded for Amber answer |
| `green_score` | INT | Score awarded for Green answer |
| `disc_mapping` | JSON | DISC contribution weights `{D, I, S, C}` (DISC questions only) |
| `w1`–`w6` | TINYINT(1) | Whether this question appears in each week (1 = yes) |

**Indexes:** `idx_type (q_type)`, `idx_order (q_order)`

### wp_mfsd_rag_answers

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | WP user ID |
| `week_num` | TINYINT | 1–6 |
| `question_id` | BIGINT UNSIGNED | FK to questions table |
| `answer` | ENUM('R','A','G') | Student's answer |
| `score` | INT | Score at time of answer (from question's score config) |
| `created_at` | DATETIME | Auto-set |

**Indexes:** `idx_user_week (user_id, week_num)`, `idx_user_question (user_id, question_id)`

### wp_mfsd_mbti_answers

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | WP user ID |
| `week_num` | TINYINT | 1–6 |
| `question_id` | BIGINT UNSIGNED | FK to questions table |
| `answer` | ENUM('R','A','G') | Student's answer (same scale as RAG) |
| `axis` | CHAR(1) | MBTI axis identifier: E, S, T, or J |
| `letter` | CHAR(1) | Letter scored by this answer (e.g. I, N, F, P) |
| `created_at` | DATETIME | Auto-set |

**Indexes:** `idx_user_week (user_id, week_num)`

### wp_mfsd_mbti_results

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | WP user ID |
| `week_num` | TINYINT | 1–6 |
| `type4` | CHAR(4) | Computed MBTI type, e.g. `ISFJ` |
| `details` | JSON | Raw letter counts used to compute `type4` |
| `created_at` | DATETIME | Auto-set |

**Unique key:** `uniq_user_week (user_id, week_num)` — `REPLACE` semantics used on insert.

### wp_mfsd_answers_disc

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | WP user ID |
| `week_num` | TINYINT | 1–6 |
| `question_id` | BIGINT UNSIGNED | FK to questions table |
| `answer` | INT | 1–5 Likert scale |
| `d_contribution` | INT | D score contribution (mapping × (answer − 3)) |
| `i_contribution` | INT | I score contribution |
| `s_contribution` | INT | S score contribution |
| `c_contribution` | INT | C score contribution |
| `created_at` | DATETIME | Auto-set |

### wp_mfsd_disc_results

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | WP user ID |
| `week_num` | TINYINT | 1–6 |
| `d_score`/`i_score`/`s_score`/`c_score` | INT | Raw summed contributions |
| `d_normalized`/`i_normalized`/`s_normalized`/`c_normalized` | DECIMAL | Normalised 0–100 |
| `d_percent`/`i_percent`/`s_percent`/`c_percent` | DECIMAL | Percentage of total |
| `primary_style` | VARCHAR(2) | Dominant DISC style or two-letter blend (e.g. `DI`) |
| `created_at` | DATETIME | Auto-set |

**Unique key:** `uniq_user_week (user_id, week_num)` — `REPLACE` semantics used.

### wp_mfsd_week_summaries

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | WP user ID |
| `week_num` | TINYINT | 1–6 |
| `reds` / `ambers` / `greens` | INT | Aggregated counts from `rag_answers` |
| `total_score` | INT | Sum of all RAG scores |
| `mbti_type` | CHAR(4) | MBTI result for this week |
| `disc_type` | VARCHAR(2) | DISC result for this week (nullable) |
| `ai_summary` | LONGTEXT | Cached AI-generated summary text |
| `created_at` | DATETIME | Auto-set |

**Unique key:** `uniq_user_week (user_id, week_num)` — `REPLACE` semantics. **Indexes:** `idx_user (user_id)`

### wp_mfsd_rag_red_plans

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | WP user ID |
| `week_num` | TINYINT | 1–6 |
| `question_id` | BIGINT UNSIGNED | FK to questions table |
| `plan_text` | TEXT | Student's improvement plan |
| `word_count` | INT | Computed on save via `str_word_count()` |
| `created_at` | DATETIME | Auto-set |
| `updated_at` | DATETIME | Auto-updated |

**Unique key:** `uniq_user_week_q (user_id, week_num, question_id)` — `REPLACE` semantics.

---

## Key Flows

### 1. Page load / shortcode render

1. `[mfsd_rag]` shortcode fires. The week number is parsed from the page title (`/Week\s*([1-6])\s*RAG/i`), defaulting to 1.
2. **Ordering gate:** If `mfsd-ordering` is active and course management is enabled (`mfsd_rag_course_management=1`), `mfsd_get_task_status()` is called for `rag_week_N`. A `locked` status returns a locked message; `available`, `in_progress`, and `completed` all proceed.
3. The JS configuration object `MFSD_RAG_CFG` is localised with all REST URLs, nonce, week, TTS voice, conversation mode, text reveal style, and Red plan mode.
4. The PHP renders `<div id="mfsd-rag-root"></div>` plus a hidden `#mfsd-rag-chat-source` div containing the MWAI chatbot shortcode (legacy integration, not used in primary flow).
5. The JS `renderIntro()` function immediately calls the `/mfsd/v1/status` REST endpoint. Depending on status:
   - `not_started`: shows the intro card (with previous week summary if applicable).
   - `in_progress`: resumes at the first unanswered question, or at a pending Red plan.
   - `completed`: renders the summary screen directly.
   - `blocked`: shows an error card with the blocking week number.

### 2. Answering questions

1. Questions are fetched from `/mfsd/v1/questions?week=N`. The PHP interleaves RAG and MBTI questions (2 RAG, 1 MBTI, repeat).
2. For each question, JS renders a card with:
   - **RAG/MBTI questions:** three traffic-light buttons (Red/Amber/Green) plus an optional "Ask SteveGPT" guidance button.
   - **DISC questions:** a 5-point Likert scale (1 = Completely Disagree → 5 = Completely Agree) with emoji and colour coding.
3. Clicking any answer button POST to `/mfsd/v1/answer`. The PHP writes to the appropriate answers table and, on first answer, marks the ordering task as `in_progress`.
4. **Guidance flow:** Clicking "Ask SteveGPT" sends a POST to `/mfsd/v1/question-guidance`. The PHP queries previous weeks' answers for context, then calls `$GLOBALS['mwai']->simpleTextQuery($prompt)` to get coaching text. A TTS control (speaker + stop buttons) is attached to the guidance text.
5. **Previous-answer strip:** `/mfsd/v1/previous-answer` fetches prior weeks' answers for the same question to show a "trend" indicator.
6. After answering, the next question is rendered. A progress indicator shows "Question N of Total".
7. When all questions are answered, the flow moves to the summary screen.

### 3. Red answer follow-up flow

1. After a student answers Red to a RAG question, a follow-up screen is shown before moving to the next question.
2. POST to `/mfsd/v1/red-suggestions` generates (via SteveGPT) an intro message and three practical improvement suggestions, taking into account any previous Red plan for the same question.
3. The student can tap a suggestion to pre-fill a textarea, or type their own plan.
4. A word-count progress bar fills as they type. The word target is determined by admin setting: `fixed-50` (50 words), `fixed-100` (100 words), or `age-specific` (50 for ages 11–12, 100 for ages 13–14, reading `pp_dob` from ProfilePress).
5. A mini chat window ("Ask SteveGPT for more ideas") allows follow-up questions via `/mfsd/v1/question-chat?is_red_followup=true`.
6. Submitting the plan POSTs to `/mfsd/v1/red-plan` which saves it to `wp_mfsd_rag_red_plans`.
7. If the student resumes mid-week with pending Red plans (no plan saved for a Red answer), the resume flow routes to the Red follow-up screen first.

### 4. Weekly summary

1. After all questions are answered (or on revisit of a completed week), POST to `/mfsd/v1/summary`.
2. PHP aggregates RAG counts and total score, calculates the MBTI type from letter counts, calculates DISC results if applicable, and queries previous weeks' summaries for context.
3. It also checks `wp_mfsd_ai_dream_jobs_results` (if the table exists) to include dream job context in the prompt.
4. Red plan follow-up context is constructed: for each Red plan from the previous week, the current week's answer to the same question determines the outcome ("improved to GREEN", "improved to AMBER", "still Red").
5. `$GLOBALS['mwai']->simpleTextQuery($prompt)` generates a personalised AI summary.
6. The summary is saved to `wp_mfsd_week_summaries` (caching enabled by default; can be disabled in admin settings).
7. The ordering task is marked `completed`.
8. The frontend renders: RAG stat chips, MBTI type, DISC polar plot (Canvas-based), AI summary text with TTS bar, previous-week tabs for review, and a "Start Next Week" prompt.

### 5. Week-over-week intro message

When a student starts a new week (status `not_started`, week > 1), the `/mfsd/v1/status` response includes `previous_week_summary` and an `intro_message` generated by SteveGPT referencing the prior week's RAG and MBTI results.

### 6. MBTI calculation

The `mbti_letter_for($qid, $ans)` method maps each MBTI question (by `q_order` 1–12) to an axis and a letter:
- Orders 1–3: E/I axis (Green → I, Red/Amber → E)
- Orders 4–6: S/N axis (Green → S, Red/Amber → N)
- Orders 7–9: T/F axis (Green → F, Red/Amber → T)
- Orders 10–12: J/P axis (Green → J, Red/Amber → P)

`mbti_type_from_counts()` tallies letter frequencies across all MBTI answers for a week and picks the majority letter per axis.

### 7. DISC calculation

`calculate_disc_results($uid, $week)` sums each dimension's contributions across all DISC answers. Contributions are computed as `mapping_weight × (answer − 3)` (range −2 to +2). Scores are normalised to 0–100 against the theoretical maximum, then converted to percentages. The primary style is the highest-scoring dimension; if two are within 20 points, a two-letter blend is used.

---

## AJAX / REST Endpoints

All routes use namespace `mfsd/v1`. All student-facing routes require login (`check_permission()`). POST/PUT/DELETE additionally verify `X-WP-Nonce` header against the `wp_rest` nonce. Admin-only routes require `manage_options`.

| Route | Method | Auth | Description |
|-------|--------|------|-------------|
| `/mfsd/v1/questions` | GET | Login | Returns questions for a given week, interleaved RAG then MBTI (2:1 ratio). Accepts `?week=N`. |
| `/mfsd/v1/answer` | POST | Login + nonce | Saves a single answer. Body: `week`, `question_id`, `rag` (R/A/G for RAG/MBTI) or `disc_answer` (1–5 for DISC). Also marks ordering task `in_progress` on first answer. |
| `/mfsd/v1/summary` | POST | Login + nonce | Aggregates results, calculates MBTI/DISC, generates AI summary via SteveGPT, saves to `week_summaries`, marks task `completed`. Respects summary caching option. |
| `/mfsd/v1/status` | GET | Login | Returns completion status, answer counts vs expected, answered question IDs, pending Red plan IDs, previous week summary, and AI intro message. Accepts `?week=N`. |
| `/mfsd/v1/previous-answer` | GET | Login | Returns this student's prior answers for a given `question_id` in all weeks before `week`. |
| `/mfsd/v1/question-guidance` | POST | Login + nonce | Generates SteveGPT guidance for a specific question, including trend context and previous Red plan. |
| `/mfsd/v1/all-weeks-summary` | GET | Login | Returns completion status and RAG/MBTI results for all 6 weeks for the current student. Used for the week-tab review screen. |
| `/mfsd/v1/question-chat` | POST | Login + nonce | Single-turn chat with SteveGPT in context of a question. `is_red_followup=true` uses a Red-specific system prompt. |
| `/mfsd/v1/red-suggestions` | POST | Login + nonce | Generates SteveGPT intro + 3 practical suggestions for a Red answer. Includes previous plan context. Returns `steve_intro`, `suggestions[]`, `prev_plans[]`, `prev_answer`, `word_target`. |
| `/mfsd/v1/red-plan` | POST | Login + nonce | Saves or replaces a Red improvement plan to `mfsd_rag_red_plans`. |
| `/mfsd/v1/admin-reset-week` | POST | `manage_options` | Deletes all answers, MBTI/DISC results, red plans, and cached summary for a given `user_id` + `week`. |
| `/mfsd/v1/admin-reset-cm-progress` | POST | `manage_options` | Deletes a specific ordering progress record from `wp_mfsd_task_progress` for a given `user_id` + `task_slug`. |

---

## Admin Panel

**Location:** Top-level menu "MFSD RAG" (dashicons-forms, position 66).

**Capability required:** `manage_options`

**Four tabs:**

### Settings tab

| Setting | Option key | Default | Notes |
|---------|-----------|---------|-------|
| Summary Caching | `mfsd_rag_cache_summaries` | `1` | When on, AI summaries are saved to `week_summaries` and reused. When off, they regenerate every visit. |
| Course Management | `mfsd_rag_course_management` | `1` | Enables ordering/locking integration with `mfsd-ordering`. Task slugs are `rag_week_1` through `rag_week_6`. |
| TTS Voice | `mfsd_rag_tts_voice` | `''` | Admin selects a browser TTS voice via an interactive preview dropdown (uses `SpeechSynthesisUtterance` in the admin page JS). |
| AI Text Reveal Style | `mfsd_rag_text_reveal` | `block` | `block` = all at once; `sentence` = sentence by sentence; `word` = word by word. |
| Conversation Mode | `mfsd_rag_conversation_mode` | `polite` | `polite` = mic stops while AI speaks; `normal` = mic stays open and speaking interrupts TTS. |
| Red Plan Word Target | `mfsd_rag_red_plan_mode` | `fixed-50` | `fixed-50` (50 words), `fixed-100` (100 words), or `age-specific` (reads `pp_dob` from ProfilePress). |

### Questions tab

Tabbed by question type (RAG / MBTI / DISC). For each type:
- Lists all questions with order number, truncated text, scores (RAG) or DISC mapping (DISC), week checkboxes (W1–W6).
- The "±" toggle link batch-checks/unchecks an entire week column.
- Inline edit form (hidden row) per question.
- "Add New" form slides in.
- "Save Week Configuration" submits all week checkboxes in a single POST, updating all `w1`–`w6` flags for all questions of that type.
- All actions protected by nonce `mfsd_rag_question_crud`.

### Student Reset tab

Selects a student and week, then POSTs to `/mfsd/v1/admin-reset-week` via fetch with the REST nonce. On success, displays deleted record count. Permanently removes all answers, MBTI/DISC results, red plans, and cached summary for that student/week.

### Course Management tab

- Shows a warning if `mfsd-ordering` is not active.
- Displays a table of all students with ordering records for any RAG week (`rag_week_%` task slugs), showing status badges.
- Provides a separate reset widget for ordering progress only (does not remove RAG answer data) via `/mfsd/v1/admin-reset-cm-progress`.

---

## SteveGPT Integration

This plugin calls SteveGPT via the global `$GLOBALS['mwai']` reference (the MWAI/SteveGPT compatibility interface). All calls use `$mwai->simpleTextQuery($prompt)`.

| Context | Endpoint | Key prompt elements |
|---------|----------|---------------------|
| Week intro message | `api_status` | Previous week's R/A/G counts, MBTI type, week number, student name, age description |
| Per-question guidance | `api_question_guidance` | Question text, question type (RAG/MBTI), previous weeks' answers, previous Red plan for same question |
| Red suggestions | `api_red_suggestions` | Question text, previous Red plan context, whether student is Red again or has improved |
| Red plan follow-up chat | `api_question_chat` (is_red_followup) | Question text, Red context, student message |
| Per-question chat | `api_question_chat` | Question text, type, previous answers, student message |
| Week summary | `api_summary` | Full RAG/MBTI/DISC results, previous weeks' history, dream jobs data, Red plan outcomes, Steve's Solutions Mindset principles |

**Age-aware prompting:** All prompts include `"aged {N}"` (exact age from `pp_dob`) or `"aged 11-14"` fallback. This affects language complexity and is also used to determine the Red plan word target in `age-specific` mode.

**No direct Anthropic API calls.** The plugin relies entirely on the `mwai` global, making it compatible with both the legacy MWAI plugin and the SteveGPT (`stevegtp`) replacement. Cross-plugin calls use the `$GLOBALS['stevegtp'] ?? $GLOBALS['mwai']` pattern; this plugin currently references `$GLOBALS['mwai']` directly.

---

## Assets

| File | Handle | Dependencies | When loaded |
|------|--------|-------------|-------------|
| `assets/mfsd-weekly-rag.js` | `mfsd-weekly-rag` | `wp-element` | Enqueued in shortcode when page contains `[mfsd_rag]`; loaded in footer |
| `assets/mfsd-weekly-rag.css` | `mfsd-weekly-rag` | None | Enqueued in shortcode |

### `assets/mfsd-weekly-rag.js` — Architecture

The JS is a ~675-line IIFE (immediately invoked function expression). Key subsystems:

**TTS Engine (`mfsdTTS`):** Web Speech API wrapper. Supports `speak()`, `stop()`, and `speakWithReveal()` (three reveal modes: block, sentence, word). Strips markdown formatting before speaking. Attempts to find the admin-configured voice first, then falls back through UK English Female, Samantha, any `en-GB`, any `en-*`. Creates speaker/stop button controls for attachment to AI text.

**STT Engine (`mfsdSTT`):** Web Speech API recognition wrapper with `continuous=true`, `en-GB` language. Accumulates interim results; 2-second silence timer fires the final callback. `polite` conversation mode stops TTS before listening; `normal` mode interrupts TTS when the student starts speaking.

**Screen flow functions:** `renderIntro()`, `renderQuestion()`, `renderSummary()`, `renderRedFollowup()`, `resumeFromLastQuestion()` — each replaces the root div's children with a new DOM tree built using the `el()` helper.

**DISC polar plot:** Canvas-based radar/polar chart drawn entirely in JS, using four DISC colours.

**Week tabs:** On the summary screen, tabs for weeks 1–6 are rendered; clicking a tab calls the `/mfsd/v1/all-weeks-summary` endpoint and re-renders the summary for that week.

### `assets/mfsd-weekly-rag.css` — Design

CSS custom properties on `.rag-wrap`:
- Dark background: `--bg-page: #0A0E1A`, `--bg-card: #0F1628`
- Accent: `--accent: #00D4FF` (cyan), `--accent-2: #9333EA` (purple), `--accent-3: #FFD000` (gold)
- Fonts: Exo 2 (headings, UI elements), Nunito (body)
- RAG colours: `--rag-red: #d9534f`, `--rag-amber: #f0ad4e`, `--rag-green: #5cb85c`
- Includes specific override rules (`INLINE STYLE OVERRIDES` section) that neutralise hardcoded `style=""` attributes injected by the JS, keeping the dark theme consistent.

---

## Security

| Check | Where |
|-------|-------|
| `if (!defined('ABSPATH')) exit` | Top of main file |
| `check_permission()` on all student REST routes | Checks `is_user_logged_in()`. For POST routes: verifies `X-WP-Nonce` header against `wp_rest` nonce |
| `current_user_can('manage_options')` | Both admin-only REST routes and admin form handlers |
| `wp_verify_nonce()` | Admin settings save and question CRUD actions |
| `$wpdb->prepare()` | All parameterised DB queries |
| `sanitize_textarea_field()` | `plan_text` on Red plan save |
| `sanitize_text_field()` | Chat message, question text in admin |
| `sanitize_key()` | `task_slug` in ordering reset endpoint |
| `(int)` cast | All numeric inputs (week, question_id, user_id) |
| `max(1, min(6, ...))` | Week number clamping on all REST callbacks |
| `in_array($ans, ['R','A','G'])` | RAG/MBTI answer validation |
| `$da >= 1 && $da <= 5` | DISC answer validation |
| `in_array($message_type, [...])` | Admin settings validation for enum-style options |
| Student ID from session | `get_current_user_id()` used for all write operations — students cannot write answers for other users |

---

## Inter-Plugin Dependencies

| Dependency | Type | Notes |
|-----------|------|-------|
| SteveGPT / MWAI (`$GLOBALS['mwai']`) | Soft required | All AI features silently degrade — no summaries, no guidance — if neither `stevegtp` nor `mwai` is present in globals. Error logged via `error_log()`. |
| `mfsd-ordering` (`mfsd_get_task_status`, `mfsd_set_task_status`) | Optional | Course gating and progress tracking. Bypassed entirely if functions do not exist or `mfsd_rag_course_management=0`. |
| UltimateMember (`um_get_display_name`, `um_profile_id`) | Optional | Used for display name and profile ID in AI prompts. Falls back to WP `get_userdata()->display_name` and `get_current_user_id()`. |
| ProfilePress (`pp_dob` user meta) | Optional | Used to read student DOB for age-aware prompting and Red plan word targets. Gracefully absent — defaults to `"aged 11-14"` and 50-word target. |
| `ai-dream-jobs` (`wp_mfsd_ai_dream_jobs_results` table) | Optional | Weekly summary checks for this table and includes top-5 dream jobs in the AI summary prompt if present. |

---

## WordPress Options

| Option key | Default | Purpose |
|-----------|---------|---------|
| `mfsd_rag_cache_summaries` | `1` | Enable/disable AI summary caching |
| `mfsd_rag_course_management` | `1` | Enable/disable ordering integration |
| `mfsd_rag_tts_voice` | `''` | Preferred browser TTS voice name |
| `mfsd_rag_conversation_mode` | `polite` | TTS/STT interaction mode |
| `mfsd_rag_text_reveal` | `block` | AI text reveal animation style |
| `mfsd_rag_red_plan_mode` | `fixed-50` | Red plan word target mode |

---

## Version History

| Version | Changes |
|---------|---------|
| 7.1.0 | Current. Full DISC integration, Red plan follow-up flow with word-count progress bar, TTS/STT voice engine, age-aware prompting via ProfilePress DOB, ordering integration via `mfsd-ordering`, Course Management admin tab, admin-reset-cm-progress endpoint |
| 7.0.x | DISC questions and scoring added; polar plot rendering |
| 6.x.x | Red plan feature (improvement planning for Red answers) |
| 5.x.x | TTS/STT voice features |
| 4.x.x | Per-question guidance and chat |
| 3.x.x | Week summary caching and ordering gate |
| 2.x.x | MBTI integration |
| 1.x.x | Initial RAG-only weekly tracker |
