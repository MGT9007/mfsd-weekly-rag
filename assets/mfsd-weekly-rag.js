(function () {
  const cfg = window.MFSD_RAG_CFG || {};
  const root = document.getElementById("mfsd-rag-root");
  if (!root) return;

  const chatSource = document.getElementById("mfsd-rag-chat-source");

  let week = cfg.week || 1;
  let questions = []; // ordered per-week list, interleaved
  let idx = 0;        // current question index
  let stack = [];     // {q, answer} visited stack for back nav

  // UI helpers
  const el = (t, c, txt) => {
    const n = document.createElement(t);
    if (c) n.className = c;
    if (txt !== undefined) n.textContent = txt;
    return n;
  };

  // Intro screen (week-sensitive)
  function renderIntro() {
    const wrap = el("div","rag-wrap");
    const card = el("div","rag-card");
    card.appendChild(el("h2","rag-title",`Week ${week} — RAG + MBTI Tracker`));

    const p = el("p","rag-sub",
      "High Performance Pathway RAG + MBTI Weekly Tracker.\n" +
      "Greens = strengths ; Ambers = mixed ; Reds = needs support.\n");
    card.appendChild(p);

    // If you want the chatbot, move it in here:
    if (chatSource && chatSource.firstChild) {
      const chatWrap = el("div","rag-chatwrap");
      while (chatSource.firstChild) chatWrap.appendChild(chatSource.firstChild);
      card.appendChild(chatWrap);
    }

    const btn = el("button","rag-btn","Begin RAG");
    btn.onclick = async () => {
      await loadQuestions();
      idx = 0; stack = [];
      renderQuestion();
    };
    card.appendChild(btn);

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  // Fetch ordered questions for this week
  async function loadQuestions() {
    const url = `${cfg.restUrl}/questions?week=${encodeURIComponent(week)}`;
    const res = await fetch(url, { headers: { 'X-WP-Nonce': cfg.nonce }});
    const raw = await res.text();
    let j=null;
    try { j = raw ? JSON.parse(raw) : null; } catch(e) {
      throw new Error(`Non-JSON from server: ${raw.slice(0,200)}`);
    }
    if (!j?.ok) throw new Error(j?.error || 'Failed getting questions');
    questions = j.questions || [];
  }

  // Render one question (RAG or MBTI)
  function renderQuestion() {
    const q = questions[idx];
    const wrap = el("div","rag-wrap");
    const card = el("div","rag-card");

    const pos = el("div","rag-pos", `Question ${idx+1} of ${questions.length}`);
    const text = el("div","rag-qtext", q.q_text);
    card.appendChild(pos);
    card.appendChild(text);

    // Previous weeks’ answers summary (weeks >1) would be fetched via another endpoint
    // left for v2; scaffold space here:
    if (week > 1) {
      card.appendChild(el("div","rag-prev","(Prev weeks summary appears here in v2)"));
    }

    const lights = el("div","rag-lights");
    const choices = [
      {key:'R', cls:'red',   label:'Red'},
      {key:'A', cls:'amber', label:'Amber'},
      {key:'G', cls:'green', label:'Green'},
    ];
    for (const c of choices) {
      const b = el("button",`rag-light ${c.cls}`, c.label);
      b.onclick = () => saveAnswer(q, c.key);
      lights.appendChild(b);
    }
    card.appendChild(lights);

    // Back
    const actions = el("div","rag-actions");
    const back = el("button","rag-btn secondary","Back");
    back.disabled = (stack.length===0);
    back.onclick = () => {
      if (!stack.length) return;
      // step back one
      const last = stack.pop();
      idx = Math.max(0, idx-1);
      renderQuestion();
    };
    actions.appendChild(back);
    card.appendChild(actions);

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  async function saveAnswer(q, answer) {
    // Persist one answer
    const payload = {
      _wpnonce: cfg.nonce,
      week, question_id: q.id, answer
    };

    const res = await fetch(`${cfg.restUrl}/answer`, {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify(payload)
    });

    const raw = await res.text();
    let j=null;
    try { j = raw ? JSON.parse(raw) : null; }
    catch(e){ alert(`Server returned non-JSON: ${raw.slice(0,200)}`); return; }

    if (!res.ok || !j?.ok) {
      alert(`Save failed: ${(j && j.error) || `${res.status} ${res.statusText}`}`);
      return;
    }

    // Push to stack and move next
    stack.push({q,answer});
    if (idx < questions.length-1) {
      idx++;
      renderQuestion();
    } else {
      // Finish → summary
      renderSummary();
    }
  }

  // Ask server to compute aggregates + MBTI type (+ optional AI)
  async function renderSummary() {
    const payload = { _wpnonce: cfg.nonce, week };
    const res = await fetch(`${cfg.restUrl}/summary`, {
      method:'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const raw = await res.text();
    let j=null; try { j = raw ? JSON.parse(raw) : null; } catch(e) {}
    if (!j?.ok) {
      alert(`Summary failed: ${(j && j.error) || raw.slice(0,200)}`); return;
    }

    const wrap = el("div","rag-wrap");
    const card = el("div","rag-card");
    card.appendChild(el("h2","rag-title","This week’s summary"));

    const stats = el("div","rag-stats");
    stats.appendChild(el("div","stat",`Reds: ${j.rag.reds}`));
    stats.appendChild(el("div","stat",`Ambers: ${j.rag.ambers}`));
    stats.appendChild(el("div","stat",`Greens: ${j.rag.greens}`));
    stats.appendChild(el("div","stat",`Score: ${j.rag.total_score}`));
    card.appendChild(stats);

    if (j.mbti) card.appendChild(el("div","rag-mbti",`MBTI this week: ${j.mbti}`));
    if (j.ai) card.appendChild(el("div","rag-ai", j.ai));

    const again = el("button","rag-btn","Back to intro");
    again.onclick = () => renderIntro();
    card.appendChild(again);

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  // Start
  renderIntro();
})();
