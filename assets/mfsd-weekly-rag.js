// ============================================================================
// CHANGE 1 — In saveAnswer(), replace the section after stack.push():
//
//   FIND this block (near the end of saveAnswer):
//     stack.push({ q: q, answer: answer });
//     hideQuestionLoading();
//     if (idx < questions.length - 1) {
//         idx++;
//         await renderQuestion();
//     } else {
//         await renderSummary();
//     }
//
//   REPLACE with:
// ============================================================================

    stack.push({ q: q, answer: answer });
    hideQuestionLoading();

    // Red answer on a RAG question → show improvement plan screen first
    if (answer === 'R' && q.q_type === 'RAG') {
      await renderRedFollowup(q, idx);
    } else if (idx < questions.length - 1) {
      idx++;
      await renderQuestion();
    } else {
      await renderSummary();
    }

// ============================================================================
// CHANGE 2 — Add this entire function just before the closing renderIntro() call
//            (i.e. just before the last line of the IIFE: "renderIntro();")
// ============================================================================

  async function renderRedFollowup(q, savedIdx) {
    showQuestionLoading('Loading your action plan...');

    // Fetch AI suggestions + previous plan data from server
    let suggestionData = null;
    try {
      const res = await fetch(cfg.restUrlRedSuggestions, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
        credentials: 'same-origin',
        body: JSON.stringify({ week: week, question_id: q.id })
      });
      if (res.ok) suggestionData = await res.json();
    } catch(err) {
      console.error('Red suggestions error:', err);
    }

    hideQuestionLoading();

    // Fallbacks if fetch failed
    const steveIntro   = suggestionData?.steve_intro  || "Thanks for being honest — let's make a plan to move this forward!";
    const suggestions  = suggestionData?.suggestions  || [];
    const prevPlans    = suggestionData?.prev_plans   || [];
    const wordTarget   = suggestionData?.word_target  || (cfg.redPlanMode === 'fixed-100' ? 100 : 50);

    const wrap = el("div", "rag-wrap");
    const card = el("div", "rag-card");

    // ── Context header ────────────────────────────────────────────────────
    const header = el("div");
    header.style.cssText = "display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;gap:12px;";

    const qInfo = el("div");
    const qLabel = el("div", "rag-pos", "Question " + (savedIdx + 1) + " of " + questions.length + " — " + q.q_text);
    qInfo.appendChild(qLabel);

    const redBadge = el("div");
    redBadge.style.cssText = "display:inline-flex;align-items:center;gap:6px;background:#FCEBEB;color:#A32D2D;font-size:12px;font-weight:500;padding:4px 10px;border-radius:6px;border:0.5px solid #F7C1C1;white-space:nowrap;flex-shrink:0;";
    redBadge.innerHTML = '<span style="width:8px;height:8px;border-radius:50%;background:#E24B4A;display:inline-block;"></span> You answered Red';

    header.appendChild(qInfo);
    header.appendChild(redBadge);
    card.appendChild(header);

    // ── Previous plan (if one exists from last week) ──────────────────────
    if (prevPlans.length > 0) {
      const lastPlan = prevPlans[0];
      const prevBox = el("div");
      prevBox.style.cssText = "background:#fff8e6;border:0.5px solid #ffd966;border-left:3px solid #f0ad4e;border-radius:0 6px 6px 0;padding:12px 14px;margin-bottom:14px;";
      const prevTitle = el("div");
      prevTitle.style.cssText = "font-size:12px;font-weight:500;color:#856404;margin-bottom:5px;";
      prevTitle.textContent = "Your plan from Week " + lastPlan.week_num;
      const prevText = el("div");
      prevText.style.cssText = "font-size:13px;color:#333;line-height:1.6;";
      prevText.textContent = lastPlan.plan_text;
      prevBox.appendChild(prevTitle);
      prevBox.appendChild(prevText);
      card.appendChild(prevBox);
    }

    // ── SteveGPT intro bubble ─────────────────────────────────────────────
    const steveSection = el("div", "rag-card");
    steveSection.style.cssText = "background:#E6F1FB;border:0.5px solid #B5D4F4;padding:16px;margin-bottom:0;";

    const steveName = el("div");
    steveName.style.cssText = "font-size:12px;font-weight:500;color:#185FA5;margin-bottom:6px;";
    steveName.textContent = "SteveGPT";

    const steveText = el("div");
    steveText.style.cssText = "font-size:14px;color:#1d2327;line-height:1.6;";
    if (mfsdTTS.supported) {
      const steveSpan = document.createElement('span');
      steveSection.appendChild(steveName);
      steveSection.appendChild(steveText);
      mfsdTTS.speakWithReveal(steveIntro, steveSpan, null);
      steveText.appendChild(steveSpan);
      steveSection.appendChild(mfsdTTS.makeControls(steveIntro));
    } else {
      steveText.textContent = steveIntro;
      steveSection.appendChild(steveName);
      steveSection.appendChild(steveText);
    }

    // ── Suggestions ───────────────────────────────────────────────────────
    if (suggestions.length > 0) {
      const sugLabel = el("div");
      sugLabel.style.cssText = "font-size:12px;font-weight:500;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin:14px 0 8px;";
      sugLabel.textContent = "Some ideas to get you started";
      steveSection.appendChild(sugLabel);

      suggestions.forEach((sug, i) => {
        const sugRow = el("div");
        sugRow.style.cssText = "display:flex;gap:8px;align-items:flex-start;padding:9px 10px;border-radius:6px;border:0.5px solid #ddd;margin-bottom:6px;background:#fff;cursor:pointer;";
        sugRow.title = "Tap to copy into your plan";

        const num = el("div");
        num.style.cssText = "font-size:12px;font-weight:500;color:#999;min-width:16px;margin-top:2px;flex-shrink:0;";
        num.textContent = (i + 1) + ".";

        const txt = el("div");
        txt.style.cssText = "font-size:13px;color:#333;line-height:1.5;";
        txt.textContent = sug;

        const hint = el("div");
        hint.style.cssText = "font-size:11px;color:#aaa;margin-top:3px;";
        hint.textContent = "Tap to copy into your plan";

        const inner = el("div"); inner.appendChild(txt); inner.appendChild(hint);
        sugRow.appendChild(num); sugRow.appendChild(inner);

        sugRow.addEventListener('click', () => {
          planTextarea.value = (planTextarea.value.trim() ? planTextarea.value.trim() + ' ' : '') + sug;
          updateWordCount();
          planTextarea.focus();
          hint.textContent = "Copied!";
          setTimeout(() => { hint.textContent = "Tap to copy into your plan"; }, 2000);
        });

        steveSection.appendChild(sugRow);
      });
    }

    card.appendChild(steveSection);

    // ── Chat with SteveGPT for more ideas ────────────────────────────────
    const chatSection = el("div", "rag-chatwrap");
    chatSection.style.marginTop = "14px";

    const chatLabel = el("div");
    chatLabel.style.cssText = "font-size:12px;font-weight:500;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin-bottom:8px;";
    chatLabel.textContent = "Ask SteveGPT for more ideas";

    const chatHistory = el("div", "rag-chat-history");
    chatHistory.style.cssText = "max-height:260px;overflow-y:auto;margin-bottom:10px;padding:10px;background:#f5f5f5;border-radius:6px;scroll-behavior:smooth;";

    chatSection.appendChild(chatLabel);
    chatSection.appendChild(chatHistory);

    // Chat input row
    const chatInputRow = el("div");
    chatInputRow.style.cssText = "display:flex;gap:8px;align-items:flex-end;";

    const chatInput = document.createElement("textarea");
    chatInput.rows = 1;
    chatInput.placeholder = "Ask a follow-up question…";
    chatInput.style.cssText = "flex:1;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;resize:none;font-family:inherit;line-height:1.4;";

    const chatSendBtn = el("button", "rag-btn", "Send");
    chatSendBtn.style.cssText = "padding:9px 16px;white-space:nowrap;";

    // Chat mic
    let chatMicBtn = null;
    let chatConvMode = false;

    const startChatListening = () => {
      if (!mfsdSTT.supported || !chatMicBtn) return;
      chatInput.value = "";
      chatInput.placeholder = "Listening…";
      chatMicBtn.classList.add("mfsd-mic-active");
      mfsdSTT.listen(
        (t) => { chatInput.value = t; },
        (t) => { chatInput.value = t; sendChatMessage(); },
        (msg) => {
          chatInput.placeholder = "Ask a follow-up question…";
          if (chatConvMode) setTimeout(() => startChatListening(), 800);
        }
      );
    };

    if (mfsdSTT.supported) {
      chatMicBtn = document.createElement("button");
      chatMicBtn.type = "button"; chatMicBtn.className = "mfsd-mic-btn";
      chatMicBtn.title = "Speak your question"; chatMicBtn.innerHTML = "🎤";
      chatMicBtn.addEventListener("click", () => {
        chatConvMode = !chatConvMode;
        if (chatConvMode) startChatListening();
        else { mfsdSTT.stop(); chatMicBtn.classList.remove("mfsd-mic-active"); chatInput.placeholder = "Ask a follow-up question…"; }
      });
    }

    const sendChatMessage = async () => {
      const msg = chatInput.value.trim();
      if (!msg) return;
      const userEl = el("div", "rag-chat-msg user-msg");
      userEl.style.cssText = "margin-bottom:8px;padding:8px 12px;background:#fff;border-radius:8px;border-left:3px solid #666;text-align:left;font-size:13px;";
      userEl.textContent = msg;
      chatHistory.appendChild(userEl);
      chatInput.value = ""; chatInput.placeholder = "Waiting…"; chatSendBtn.disabled = true;
      chatHistory.scrollTop = chatHistory.scrollHeight;

      try {
        const res = await fetch(cfg.restUrlQuestionChat, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
          credentials: 'same-origin',
          body: JSON.stringify({ week, question_id: q.id, message: msg, is_red_followup: true })
        });
        if (res.ok) {
          const data = await res.json();
          if (data.ok && data.response) {
            const aiEl = el("div", "rag-chat-msg ai-msg");
            aiEl.style.cssText = "margin-bottom:8px;padding:8px 12px;background:#e3f2fd;border-radius:8px;border-left:3px solid #2196f3;font-size:13px;";
            const aiSpan = document.createElement('span');
            aiEl.appendChild(aiSpan);
            chatHistory.appendChild(aiEl);
            chatHistory.scrollTop = chatHistory.scrollHeight;
            if (mfsdTTS.supported) {
              aiEl.appendChild(mfsdTTS.makeControls(data.response));
              mfsdTTS.speakWithReveal(data.response, aiSpan, () => { if (chatConvMode) startChatListening(); });
            } else {
              aiSpan.textContent = data.response;
              if (chatConvMode) setTimeout(() => startChatListening(), 500);
            }
          }
        }
      } catch(err) { console.error('Red chat error:', err); }
      finally { chatSendBtn.disabled = false; chatInput.placeholder = "Ask a follow-up question…"; }
    };

    chatSendBtn.onclick = sendChatMessage;
    chatInput.onkeydown = (e) => { if (e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendChatMessage();} };

    chatInputRow.appendChild(chatInput);
    if (chatMicBtn) chatInputRow.appendChild(chatMicBtn);
    chatInputRow.appendChild(chatSendBtn);
    chatSection.appendChild(chatInputRow);
    card.appendChild(chatSection);

    // ── Plan writing section ──────────────────────────────────────────────
    const hr = el("hr"); hr.style.cssText = "border:none;border-top:0.5px solid #e5e5e5;margin:18px 0;";
    card.appendChild(hr);

    const planHeader = el("div");
    planHeader.style.cssText = "display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;";

    const planTitle = el("div");
    planTitle.style.cssText = "font-size:15px;font-weight:500;color:#1d2327;";
    planTitle.textContent = "Your plan to move from Red to Amber";

    const wordCountDisplay = el("div");
    wordCountDisplay.style.cssText = "text-align:right;font-size:12px;color:#666;line-height:1.5;";
    wordCountDisplay.innerHTML = `Target: <strong style="font-weight:500;">${wordTarget} words</strong><br><span id="rf-word-count" style="color:#185FA5;font-weight:500;">0 / ${wordTarget}</span>`;

    planHeader.appendChild(planTitle);
    planHeader.appendChild(wordCountDisplay);
    card.appendChild(planHeader);

    const ageNote = el("div");
    ageNote.style.cssText = "font-size:12px;color:#666;background:#f8f8f8;border-radius:6px;padding:8px 12px;margin-bottom:12px;border:0.5px solid #e5e5e5;";
    ageNote.textContent = `Write your plan below — aim for ${wordTarget} words. You can type it, speak it using the mic below, or copy ideas from the suggestions above.`;
    card.appendChild(ageNote);

    // Word count progress bar
    const barWrap = el("div");
    barWrap.style.cssText = "height:4px;background:#e5e5e5;border-radius:2px;margin-bottom:14px;";
    const barFill = el("div");
    barFill.style.cssText = "height:100%;width:0%;background:#378ADD;border-radius:2px;transition:width 0.2s;";
    barWrap.appendChild(barFill);
    card.appendChild(barWrap);

    const planTextarea = document.createElement("textarea");
    planTextarea.rows = 4;
    planTextarea.placeholder = "Write your plan here — what will you do this week to improve?";
    planTextarea.style.cssText = "width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit;line-height:1.6;resize:vertical;";
    card.appendChild(planTextarea);

    // Word count updater
    const updateWordCount = () => {
      const words = planTextarea.value.trim().split(/\s+/).filter(Boolean).length;
      const pct   = Math.min(100, Math.round((words / wordTarget) * 100));
      barFill.style.width = pct + '%';
      barFill.style.background = words >= wordTarget ? '#5cb85c' : '#378ADD';
      const display = document.getElementById('rf-word-count');
      if (display) {
        display.textContent = words + ' / ' + wordTarget;
        display.style.color = words >= wordTarget ? '#3b6d11' : '#185FA5';
      }
      saveBtn.disabled = words < wordTarget;
    };
    planTextarea.addEventListener('input', updateWordCount);

    // Plan mic button
    const planInputRow = el("div");
    planInputRow.style.cssText = "display:flex;gap:8px;align-items:center;margin-top:10px;";

    if (mfsdSTT.supported) {
      const planMicBtn = document.createElement("button");
      planMicBtn.type = "button"; planMicBtn.className = "mfsd-mic-btn";
      planMicBtn.title = "Speak your plan"; planMicBtn.innerHTML = "🎤";
      let planMicActive = false;

      planMicBtn.addEventListener("click", () => {
        planMicActive = !planMicActive;
        if (planMicActive) {
          planMicBtn.classList.add("mfsd-mic-active");
          mfsdSTT.listen(
            (t) => { planTextarea.value = t; updateWordCount(); },
            (t) => { planTextarea.value = t; updateWordCount(); planMicActive = false; planMicBtn.classList.remove("mfsd-mic-active"); },
            () => { planMicActive = false; planMicBtn.classList.remove("mfsd-mic-active"); }
          );
        } else {
          mfsdSTT.stop();
          planMicBtn.classList.remove("mfsd-mic-active");
        }
      });
      planInputRow.appendChild(planMicBtn);
    }

    const micHint = el("div");
    micHint.style.cssText = "font-size:12px;color:#aaa;";
    micHint.textContent = "Tap mic to speak your plan";
    planInputRow.appendChild(micHint);
    card.appendChild(planInputRow);

    // Save button
    const saveBtn = el("button", "rag-btn", "Save my plan and continue");
    saveBtn.style.cssText = "width:100%;margin-top:14px;padding:12px;";
    saveBtn.disabled = true;

    saveBtn.onclick = async () => {
      const planText = planTextarea.value.trim();
      const words    = planText.split(/\s+/).filter(Boolean).length;
      if (words < wordTarget) return;

      saveBtn.disabled = true;
      saveBtn.textContent = "Saving…";

      try {
        await fetch(cfg.restUrlSaveRedPlan, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
          credentials: 'same-origin',
          body: JSON.stringify({ week, question_id: q.id, plan_text: planText })
        });
      } catch(err) {
        console.error('Save red plan error:', err);
      }

      // Stop any active mics / TTS
      mfsdTTS.stop();
      mfsdSTT.stop();

      // Proceed to next question or summary
      if (savedIdx < questions.length - 1) {
        idx = savedIdx + 1;
        await renderQuestion();
      } else {
        await renderSummary();
      }
    };

    card.appendChild(saveBtn);

    // Also call updateWordCount once to set initial state
    updateWordCount();

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

// ============================================================================
// END OF ADDITIONS — no other changes needed in the JS file
// ============================================================================