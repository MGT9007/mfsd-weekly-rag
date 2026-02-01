(function () {
  console.log('MFSD_RAG_CFG', window.MFSD_RAG_CFG);
  const cfg = window.MFSD_RAG_CFG || {};
  const root = document.getElementById("mfsd-rag-root");
  if (!root) return;

  const chatSource = document.getElementById("mfsd-rag-chat-source");

  // CRITICAL: Always get week from cfg which is set by PHP from page title
  let week = cfg.week || 1;
  console.log('Initial week from config:', week);
  
  let questions = [];
  let idx = 0;
  let stack = [];

  const el = (t, c, txt) => {
    const n = document.createElement(t);
    if (c) n.className = c;
    if (txt !== undefined) n.textContent = txt;
    return n;
  };

  // Check if week is already completed or in progress
  async function checkWeekStatus() {
    console.log('Checking status for week:', week);
    try {
      const res = await fetch(cfg.restUrlStatus + "?week=" + encodeURIComponent(week), {
        method: 'GET',
        headers: {
          'X-WP-Nonce': cfg.nonce || '',
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });
      
      if (res.ok) {
        const data = await res.json();
        console.log('Status response:', data);
        return data;
      }
    } catch (err) {
      console.error('Status check error:', err);
    }
    return { status: 'not_started', can_start: true };
  }

  async function renderIntro() {
    console.log('renderIntro called, week =', week);
    
    // Check status and permissions
    const status = await checkWeekStatus();
    
    // Check if user can start this week
    if (!status.can_start && status.blocking_week) {
      const wrap = el("div","rag-wrap");
      const card = el("div","rag-card");
      card.appendChild(el("h2","rag-title","Week " + week + " — Not Available"));
      
      const msg = el("p","rag-error-msg",
        "Please complete Week " + status.blocking_week + " before starting Week " + week + ".");
      card.appendChild(msg);
      
      const backBtn = el("button","rag-btn","Back");
      backBtn.onclick = () => window.history.back();
      card.appendChild(backBtn);
      
      wrap.appendChild(card);
      root.replaceChildren(wrap);
      return;
    }
    
    // If completed, go straight to summary
    if (status.status === 'completed') {
      await renderSummary();
      return;
    }
    
    // If in progress, resume from where they left off
    if (status.status === 'in_progress') {
      await loadQuestions();
      await resumeFromLastQuestion(status.last_question_id, status.answered_question_ids || []);
      return;
    }

    // Otherwise show intro for new start
    const wrap = el("div","rag-wrap");
    const card = el("div","rag-card");
    card.appendChild(el("h2","rag-title","Week " + week + " — RAG + MBTI Tracker"));

    // Show previous week summary for weeks 2-6
    if (status.previous_week_summary) {
      const prevSummary = status.previous_week_summary;
      const summaryBox = el("div","rag-prev-week-summary");
      summaryBox.style.cssText = "background: #f0f8ff; border-left: 4px solid #4a90e2; padding: 12px 14px; border-radius: 6px; margin: 12px 0;";
      
      const summaryTitle = el("div","rag-prev-week-title");
      summaryTitle.style.cssText = "font-weight: 600; margin-bottom: 6px; color: #2c3e50;";
      summaryTitle.textContent = "Last Week (Week " + prevSummary.week + ") Results:";
      summaryBox.appendChild(summaryTitle);
      
      const stats = el("div","rag-prev-stats");
      stats.style.cssText = "display: flex; gap: 12px; margin: 8px 0; flex-wrap: wrap;";
      
      const greenStat = el("div","stat");
      greenStat.style.cssText = "background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; padding: 6px 10px; font-size: 14px;";
      greenStat.textContent = "🟢 Greens: " + prevSummary.greens;
      stats.appendChild(greenStat);
      
      const amberStat = el("div","stat");
      amberStat.style.cssText = "background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; padding: 6px 10px; font-size: 14px;";
      amberStat.textContent = "🟠 Ambers: " + prevSummary.ambers;
      stats.appendChild(amberStat);
      
      const redStat = el("div","stat");
      redStat.style.cssText = "background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; padding: 6px 10px; font-size: 14px;";
      redStat.textContent = "🔴 Reds: " + prevSummary.reds;
      stats.appendChild(redStat);
      
      if (prevSummary.mbti_type) {
        const mbtiStat = el("div","stat");
        mbtiStat.style.cssText = "background: #e8f4fd; border: 1px solid #b8daff; border-radius: 6px; padding: 6px 10px; font-size: 14px; font-weight: 600;";
        mbtiStat.textContent = "MBTI: " + prevSummary.mbti_type;
        stats.appendChild(mbtiStat);
      }
      
      summaryBox.appendChild(stats);
      card.appendChild(summaryBox);
    }

    // Show AI-generated intro message if available
    if (status.intro_message) {
      const introBox = el("div","rag-ai-intro");
      introBox.style.cssText = "background: #fff8e6; border: 1px solid #ffd966; border-left: 4px solid #f0ad4e; padding: 12px 14px; border-radius: 6px; line-height: 1.6; margin: 12px 0; font-size: 14px; color: #333;";
      introBox.textContent = status.intro_message;
      card.appendChild(introBox);
    } else {
      // Fallback to static text if no AI message
      const p = el("p","rag-sub",
        "High Performance Pathway RAG + MBTI Weekly Tracker.\n" +
        "Greens = strengths ; Ambers = mixed ; Reds = needs support.\n");
      card.appendChild(p);
    }

    const btn = el("button","rag-btn","Begin RAG");
    btn.onclick = async () => {
      await loadQuestions();
      idx = 0; 
      stack = [];
      await renderQuestion();
    };
    card.appendChild(btn);

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  async function resumeFromLastQuestion(lastQuestionId, answeredIds) {
    console.log('=== RESUME FUNCTION START ===');
    console.log('Last question ID from DB:', lastQuestionId);
    console.log('Answered question IDs:', answeredIds);
    console.log('Total questions loaded:', questions.length);
    console.log('All question IDs:', questions.map(q => q.id));
    
    // Find the first unanswered question
    let firstUnansweredIdx = -1;
    for (let i = 0; i < questions.length; i++) {
      const questionId = parseInt(questions[i].id);
      const isAnswered = answeredIds.includes(questionId);
      console.log(`Question ${i + 1} (ID: ${questionId}): ${isAnswered ? 'ANSWERED' : 'NOT ANSWERED'}`);
      
      if (!isAnswered) {
        firstUnansweredIdx = i;
        console.log('Found first unanswered question at index:', firstUnansweredIdx);
        break;
      }
    }
    
    if (firstUnansweredIdx >= 0) {
      // Start from the first unanswered question
      idx = firstUnansweredIdx;
      console.log('Resuming from first unanswered question at index:', idx, 'Question ID:', questions[idx].id);
      stack = [];
      await renderQuestion();
    } else {
      // All questions answered, show summary
      console.log('All questions have been answered, showing summary');
      await renderSummary();
    }
    
    console.log('=== RESUME FUNCTION END ===');
  }

  async function loadQuestions() {
    console.log('Loading questions for week:', week);
    const url = cfg.restUrlQuestions + "?week=" + encodeURIComponent(week);

    try {
      const res = await fetch(url, {
        method: 'GET',
        headers: {
          'X-WP-Nonce': cfg.nonce || '',
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });

      if (!res.ok) throw new Error("HTTP " + res.status);

      const data = await res.json();
      console.log('Questions response:', data);
      if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Failed');

      questions = data.questions || [];
      console.log('Loaded ' + questions.length + ' questions');
    } catch (err) {
      console.error('Error loading questions', err);
      alert('Loading questions failed: ' + err.message);
      throw err;
    }
  }

  async function renderQuestion() {
    showQuestionLoading(); // Show loading while building the question
    
    const q = questions[idx];
    const wrap = el("div","rag-wrap");
    const card = el("div","rag-card");

    const pos = el("div","rag-pos", "Question " + (idx+1) + " of " + questions.length);
    const text = el("div","rag-qtext", q.q_text);
    card.appendChild(pos);
    card.appendChild(text);

    // Check if this is a DISC question
    if (q.q_type === 'DISC') {
        // DISC uses 1-5 Likert scale
        const scaleContainer = el("div", "disc-scale-container");
        scaleContainer.style.cssText = "margin: 20px 0;";
        
        const scaleLabel = el("div", "disc-scale-label");
        scaleLabel.style.cssText = "text-align: center; margin-bottom: 12px; font-weight: 600; color: #555;";
        scaleLabel.textContent = "How much do you agree with this statement?";
        scaleContainer.appendChild(scaleLabel);
        
        const lights = el("div", "rag-lights");
        lights.style.cssText = "display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;";
        
        const options = [
            { label: "Completely Disagree", value: 1, color: "#d9534f", emoji: "👎" },
            { label: "Somewhat Disagree", value: 2, color: "#f0ad4e", emoji: "🤔" },
            { label: "Neutral", value: 3, color: "#9e9e9e", emoji: "😐" },
            { label: "Somewhat Agree", value: 4, color: "#5cb85c", emoji: "👍" },
            { label: "Completely Agree", value: 5, color: "#4caf50", emoji: "💯" }
        ];
        
        options.forEach(opt => {
            const btn = el("button", "rag-light disc-scale-btn");
            btn.style.cssText = `
                background: ${opt.color};
                color: white;
                border: none;
                border-radius: 10px;
                padding: 16px 12px;
                cursor: pointer;
                font-weight: 600;
                font-size: 13px;
                min-width: 90px;
                transition: all 0.2s;
                white-space: pre-line;
                text-align: center;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 6px;
            `;
            
            const emoji = el("span", "");
            emoji.style.cssText = "font-size: 24px;";
            emoji.textContent = opt.emoji;
            btn.appendChild(emoji);
            
            const label = el("span", "");
            label.style.cssText = "font-size: 12px; line-height: 1.3;";
            label.textContent = opt.label;
            btn.appendChild(label);
            
            btn.onmouseover = () => {
                btn.style.transform = "translateY(-3px)";
                btn.style.boxShadow = "0 4px 12px rgba(0,0,0,0.2)";
            };
            btn.onmouseout = () => {
                btn.style.transform = "translateY(0)";
                btn.style.boxShadow = "none";
            };
            
            // THIS IS THE KEY PART - sends disc_answer parameter
            btn.onclick = async () => {
                showQuestionLoading('Saving your answer...');
                
                try {
                    
                    // Parse disc_mapping if it's a JSON string
                    let mapping = q.disc_mapping;
                  if (typeof mapping === 'string') {
                   try {
                       mapping = JSON.parse(mapping);
                       } catch (e) {
                          hideQuestionLoading();
                          alert('Error: Invalid DISC question data.');
                          console.error('Failed to parse disc_mapping:', mapping);
                          return;
                        }
                    }
                    
                    // Verify mapping has required properties
                    if (!mapping || !mapping.hasOwnProperty('D')) {
                        hideQuestionLoading();
                        alert('Error: DISC question missing mapping data.');
                        console.error('Invalid mapping:', mapping);
                        return;
                    }

                    const contribution = opt.value - 3;
                    
                    const payload = {
                        week: week,
                        question_id: q.id,
                        q_type: 'DISC',
                        disc_answer: opt.value,  // Send as disc_answer
                        d_contribution: mapping.D * contribution,
                        i_contribution: mapping.I * contribution,
                        s_contribution: mapping.S * contribution,
                        c_contribution: mapping.C * contribution
                    };
                    
                    const res = await fetch(cfg.restUrlAnswer, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': cfg.nonce || ''
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(payload)
                    });
                    
                    if (!res.ok) throw new Error('Failed to save answer');
                    
                    const data = await res.json();
                    if (!data.ok) throw new Error(data.error || 'Failed');
                    
                    hideQuestionLoading();
                    
                    // Move to next question
                    idx++;
                    if (idx < questions.length) {
                        await renderQuestion();
                    } else {
                        await renderSummary();
                    }
                    
                } catch (err) {
                    hideQuestionLoading();
                    console.error('Error saving DISC answer:', err);
                    alert('Error saving answer: ' + err.message);
                }
            };
            
            lights.appendChild(btn);
        });
        
        scaleContainer.appendChild(lights);
        card.appendChild(scaleContainer);
        
        wrap.appendChild(card);
        root.replaceChildren(wrap);
        
        hideQuestionLoading(); // If you have this
        return; // EXIT HERE - don't render RAG buttons
    }
    
    // ============ END DISC CHECK ============

    // Show previous weeks' answers for weeks 2-6 (for both RAG and MBTI)
    if (week > 1) {
      try {
        const prevRes = await fetch(
          cfg.restUrlPrevious + "?week=" + week + "&question_id=" + q.id,
          {
            method: 'GET',
            headers: {
              'X-WP-Nonce': cfg.nonce || '',
              'Accept': 'application/json'
            },
            credentials: 'same-origin'
          }
        );
        
        if (prevRes.ok) {
          const prevData = await prevRes.json();
          console.log('Previous answers for question', q.id, ':', prevData);
          if (prevData.ok && prevData.previous && prevData.previous.length > 0) {
            const prevDiv = el("div","rag-prev");
            let prevText = "Previous answers: ";
            prevData.previous.forEach(function(p) {
              const color = p.answer === 'R' ? '🔴' : (p.answer === 'A' ? '🟠' : '🟢');
              prevText += "Week " + p.week_num + ": " + color + " ";
            });
            prevDiv.textContent = prevText;
            card.appendChild(prevDiv);
          }
        }
      } catch (err) {
        console.error('Error loading previous answers:', err);
      }
    }

    // Generate AI guidance for ALL questions (RAG and MBTI)
    const aiGuidanceDiv = el("div", "rag-ai-question");
    aiGuidanceDiv.innerHTML = '<em>Loading question guidance...</em>';
    card.appendChild(aiGuidanceDiv);

    try {
      const guidanceRes = await fetch(cfg.restUrlGuidance, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce || ''
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          week: week,
          question_id: q.id
        })
      });

      if (guidanceRes.ok) {
        const guidanceData = await guidanceRes.json();
        if (guidanceData.ok && guidanceData.guidance) {
          aiGuidanceDiv.textContent = guidanceData.guidance;
        } else {
          aiGuidanceDiv.innerHTML = '<em>Question guidance unavailable.</em>';
        }
      }
    } catch (err) {
      console.error('Error loading guidance:', err);
      aiGuidanceDiv.innerHTML = '<em>Question guidance unavailable.</em>';
    }

    // Add custom AI chatbot for deeper questions
    const chatWrap = el("div","rag-chatwrap");
    
    // Chat history container
    const chatHistory = el("div", "rag-chat-history");
    chatHistory.style.cssText = "max-height: 300px; overflow-y: auto; margin-bottom: 12px; padding: 10px; background: #f5f5f5; border-radius: 6px;";
    
    // Initial AI message
    const initialMsg = el("div", "rag-chat-msg ai-msg");
    initialMsg.style.cssText = "margin-bottom: 10px; padding: 8px 12px; background: #e3f2fd; border-radius: 8px; border-left: 3px solid #2196f3;";
    initialMsg.textContent = "Hi! How can I help you with this question?";
    chatHistory.appendChild(initialMsg);
    
    chatWrap.appendChild(chatHistory);
    
    // Input container
    const inputContainer = el("div");
    inputContainer.style.cssText = "display: flex; gap: 8px; align-items: center;";
    
    const chatInput = document.createElement("input");
    chatInput.type = "text";
    chatInput.placeholder = "Ask about this question...";
    chatInput.style.cssText = "flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;";
    
    const sendBtn = el("button", "rag-btn", "Send");
    sendBtn.style.cssText = "padding: 10px 20px; white-space: nowrap;";
    
    // Send message function
    const sendMessage = async () => {
      const userMsg = chatInput.value.trim();
      if (!userMsg) return;
      
      // Add user message to history
      const userMsgEl = el("div", "rag-chat-msg user-msg");
      userMsgEl.style.cssText = "margin-bottom: 10px; padding: 8px 12px; background: #fff; border-radius: 8px; border-left: 3px solid #666; text-align: right;";
      userMsgEl.textContent = userMsg;
      chatHistory.appendChild(userMsgEl);
      
      // Clear input and disable send button
      chatInput.value = "";
      sendBtn.disabled = true;
      sendBtn.textContent = "Sending...";
      
      // Scroll to bottom
      chatHistory.scrollTop = chatHistory.scrollHeight;
      
      try {
        const response = await fetch(cfg.restUrlQuestionChat, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': cfg.nonce || ''
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            week: week,
            question_id: q.id,
            message: userMsg
          })
        });
        
        if (response.ok) {
          const data = await response.json();
          if (data.ok && data.response) {
            const aiMsgEl = el("div", "rag-chat-msg ai-msg");
            aiMsgEl.style.cssText = "margin-bottom: 10px; padding: 8px 12px; background: #e3f2fd; border-radius: 8px; border-left: 3px solid #2196f3;";
            aiMsgEl.textContent = data.response;
            chatHistory.appendChild(aiMsgEl);
            chatHistory.scrollTop = chatHistory.scrollHeight;
          }
        } else {
          throw new Error('Failed to get response');
        }
      } catch (err) {
        console.error('Chat error:', err);
        const errorMsgEl = el("div", "rag-chat-msg error-msg");
        errorMsgEl.style.cssText = "margin-bottom: 10px; padding: 8px 12px; background: #ffebee; border-radius: 8px; border-left: 3px solid #f44336;";
        errorMsgEl.textContent = "Sorry, I couldn't process your message. Please try again.";
        chatHistory.appendChild(errorMsgEl);
      } finally {
        sendBtn.disabled = false;
        sendBtn.textContent = "Send";
        chatInput.focus();
      }
    };
    
    // Event listeners
    sendBtn.onclick = sendMessage;
    chatInput.onkeypress = (e) => {
      if (e.key === 'Enter') {
        sendMessage();
      }
    };
    
    inputContainer.appendChild(chatInput);
    inputContainer.appendChild(sendBtn);
    chatWrap.appendChild(inputContainer);
    
    card.appendChild(chatWrap);

    const lights = el("div","rag-lights");
    const choices = [
      {key:'R', cls:'red',   label:'Red'},
      {key:'A', cls:'amber', label:'Amber'},
      {key:'G', cls:'green', label:'Green'},
    ];
    
    for (let i = 0; i < choices.length; i++) {
      const c = choices[i];
      const b = el("button","rag-light " + c.cls, c.label);
      b.onclick = () => saveAnswer(q, c.key, b);
      lights.appendChild(b);
    }
    card.appendChild(lights);

    const actions = el("div","rag-actions");
    const back = el("button","rag-btn secondary","Back");
    back.disabled = (stack.length === 0);
    back.onclick = () => {
      if (!stack.length) return;
      stack.pop();
      idx = Math.max(0, idx - 1);
      renderQuestion();
    };
    actions.appendChild(back);
    card.appendChild(actions);

    wrap.appendChild(card);
    root.replaceChildren(wrap);
    
    hideQuestionLoading(); // Hide loading once everything is ready
  }

  function showQuestionLoading() {
    const overlay = el("div", "rag-loading-overlay");
    const spinner = el("div", "rag-spinner");
    const text = el("div", "rag-loading-text", "Saving answer...");
    overlay.appendChild(spinner);
    overlay.appendChild(text);
    document.body.appendChild(overlay);
  }

  function hideQuestionLoading() {
    const overlay = document.querySelector(".rag-loading-overlay");
    if (overlay) overlay.remove();
  }

  async function saveAnswer(q, answer, buttonElement) {
    console.log('Saving answer for week:', week, 'question:', q.id, 'answer:', answer);
    
    // Disable all buttons and show loading
    const allButtons = document.querySelectorAll('.rag-light, .rag-btn');
    allButtons.forEach(function(btn) {
      btn.disabled = true;
      btn.style.opacity = '0.5';
      btn.style.cursor = 'not-allowed';
    });
    
    showQuestionLoading();
    
    const payload = {
      week: week,
      question_id: q.id,
      rag: answer,
    };

    try {
      const res = await fetch(cfg.restUrlAnswer, {
        method: "POST",
        headers: { 
          "Content-Type": "application/json",
          "X-WP-Nonce": cfg.nonce || ''
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });

      const raw = await res.text();
      console.log('Save response:', raw);
      let j = null;
      
      try {
        j = raw ? JSON.parse(raw) : null;
      } catch (e) {
        hideQuestionLoading();
        alert("Server returned non-JSON: " + raw.slice(0, 200));
        return;
      }

      if (!res.ok || !j || !j.ok) {
        hideQuestionLoading();
        alert("Save failed: " + ((j && j.error) || res.status + " " + res.statusText));
        return;
      }

      stack.push({ q: q, answer: answer });
      
      hideQuestionLoading();
      
      if (idx < questions.length - 1) {
        idx++;
        await renderQuestion();
      } else {
        await renderSummary();
      }
    } catch (err) {
      hideQuestionLoading();
      console.error('Save error:', err);
      alert('Failed to save answer: ' + err.message);
    }
  }

  function showLoadingOverlay() {
    const overlay = el("div", "rag-loading-overlay");
    const spinner = el("div", "rag-spinner");
    const text = el("div", "rag-loading-text", "Preparing Summary Results...");
    overlay.appendChild(spinner);
    overlay.appendChild(text);
    document.body.appendChild(overlay);
  }

  function hideLoadingOverlay() {
    const overlay = document.querySelector(".rag-loading-overlay");
    if (overlay) overlay.remove();
  }

  async function renderSummary() {
    console.log('=== renderSummary START ===');
    console.log('Current week variable:', week);
    
    showLoadingOverlay();

    try {
      // Fetch current week summary
      const summaryRes = await fetch(cfg.restUrlSummary, {
        method: 'POST',
        headers: { 
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce || ''
        },
        credentials: 'same-origin',
        body: JSON.stringify({ week: week })
      });
      
      const summaryRaw = await summaryRes.text();
      let summaryData = null;
      
      try { 
        summaryData = summaryRaw ? JSON.parse(summaryRaw) : null; 
      } catch(e) {
        hideLoadingOverlay();
        alert("Summary returned non-JSON: " + summaryRaw.slice(0, 200));
        return;
      }
      
      if (!summaryData || !summaryData.ok) {
        hideLoadingOverlay();
        alert("Summary failed: " + ((summaryData && summaryData.error) || summaryRaw.slice(0,200)));
        return;
      }

      // Fetch all weeks data
      const allWeeksRes = await fetch(cfg.restUrlAllWeeks + "?_=" + Date.now(), {
        method: 'GET',
        headers: {
          'X-WP-Nonce': cfg.nonce || '',
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });

      let allWeeksData = null;
      if (allWeeksRes.ok) {
        allWeeksData = await allWeeksRes.json();
      }

      hideLoadingOverlay();

      const wrap = el("div","rag-wrap");
      const card = el("div","rag-card");
      
      const weekNum = summaryData.week || week;
      card.appendChild(el("h2","rag-title","Week " + weekNum + " Summary"));

      // Week tabs for navigation - show ALL completed weeks
      if (allWeeksData && allWeeksData.ok && allWeeksData.weeks) {
        const tabsContainer = el("div", "rag-week-tabs");
        
        // Find the highest completed week
        let maxCompletedWeek = 0;
        for (let w = 1; w <= 6; w++) {
          const weekData = allWeeksData.weeks[w];
          if (weekData && weekData.completed) {
            maxCompletedWeek = w;
          }
        }

        // Show buttons for all completed weeks (not just up to current)
        for (let w = 1; w <= maxCompletedWeek; w++) {
          const weekData = allWeeksData.weeks[w];
          if (weekData && weekData.completed) {
            const tab = el("button", "rag-week-tab" + (w === weekNum ? " active" : ""), "Week " + w);
            tab.setAttribute('data-week', w);
            tab.onclick = async function() {
              // Change the global week variable and reload entire summary
              week = w;
              await renderSummary();
            };
            tabsContainer.appendChild(tab);
          }
        }
        card.appendChild(tabsContainer);
      }

      // Chart container (will be populated by tabs)
      const chartContainer = el("div", "rag-chart-container");
      chartContainer.id = "chart-display";
      card.appendChild(chartContainer);

      // Add DISC display with visual plot
if (summaryData.disc_type && summaryData.disc_scores) {
  const discSection = el("div", "rag-disc-section");
  discSection.style.cssText = "margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 8px;";
  
  const discTitle = el("div", "rag-disc-title");
  discTitle.style.cssText = "font-size: 18px; font-weight: 600; margin-bottom: 16px; text-align: center;";
  discTitle.textContent = "DISC Personality Style: " + summaryData.disc_type;
  discSection.appendChild(discTitle);
  
  // Create container for plot and breakdown side by side
  const discContent = el("div", "disc-content-wrapper");
  discContent.style.cssText = "display: flex; gap: 20px; align-items: center; flex-wrap: wrap; justify-content: center;";
  
  // Add polar plot
  const plotContainer = el("div", "disc-plot-wrapper");
  plotContainer.style.cssText = "flex: 0 0 auto;";
  const polarPlot = createDISCPolarPlot(summaryData.disc_scores);
  if (polarPlot) {
    plotContainer.appendChild(polarPlot);
  }
  discContent.appendChild(plotContainer);
  
  // Add score breakdown
  const breakdown = el("div", "disc-breakdown");
  breakdown.style.cssText = "flex: 1 1 200px; min-width: 200px;";
  
  Object.entries(summaryData.disc_scores).forEach(([letter, scores]) => {
    const row = el("div", "disc-score-row");
    row.style.cssText = "margin: 8px 0;";
    
    const label = el("span", "disc-score-label");
    label.style.cssText = "display: inline-block; width: 30px; font-weight: 600;";
    label.textContent = letter + ":";
    
    const bar = el("div", "disc-score-bar");
    bar.style.cssText = `
      display: inline-block;
      width: ${scores.percent}%;
      max-width: 150px;
      height: 20px;
      background: linear-gradient(90deg, #4a90e2, #64b5f6);
      border-radius: 4px;
      margin-left: 8px;
      vertical-align: middle;
    `;
    
    const pct = el("span", "disc-score-pct");
    pct.style.cssText = "margin-left: 8px; font-weight: 600; color: #333;";
    pct.textContent = Math.round(scores.percent) + "%";
    
    row.appendChild(label);
    row.appendChild(bar);
    row.appendChild(pct);
    breakdown.appendChild(row);
  });
  
  discContent.appendChild(breakdown);
  discSection.appendChild(discContent);
  
  card.appendChild(discSection);
}

if (summaryData.ai) {
  card.appendChild(el("div","rag-ai", summaryData.ai));
}
      
      if (summaryData.ai) {
        card.appendChild(el("div","rag-ai", summaryData.ai));
      }

      const again = el("button","rag-btn","Back to intro");
      again.onclick = () => {
        window.location.reload();
      };
      card.appendChild(again);

      wrap.appendChild(card);
      root.replaceChildren(wrap);

      // IMPORTANT: Show the current week's chart AFTER DOM is rendered
      setTimeout(function() {
        if (allWeeksData && allWeeksData.ok) {
          showWeekChart(weekNum, allWeeksData.weeks);
        } else {
          // Fallback to single week display
          const container = document.getElementById('chart-display');
          if (container) {
            const stats = el("div","rag-stats");
            stats.appendChild(el("div","stat","Reds: " + summaryData.rag.reds));
            stats.appendChild(el("div","stat","Ambers: " + summaryData.rag.ambers));
            stats.appendChild(el("div","stat","Greens: " + summaryData.rag.greens));
            stats.appendChild(el("div","stat","Score: " + summaryData.rag.total_score));
            container.appendChild(stats);

            const canvas = document.createElement('canvas');
            canvas.width = 400;
            canvas.height = 400;
            container.appendChild(canvas);
            
            setTimeout(function() {
              drawPieChart(canvas, parseInt(summaryData.rag.reds), parseInt(summaryData.rag.ambers), parseInt(summaryData.rag.greens));
            }, 50);
          }
        }
      }, 10);

      console.log('=== renderSummary END ===');
    } catch (err) {
      hideLoadingOverlay();
      console.error('Summary error:', err);
      alert('Failed to load summary: ' + err.message);
    }
  }

  function showWeekChart(weekNum, weeksData) {
    const container = document.getElementById('chart-display');
    if (!container) return;

    const weekData = weeksData[weekNum];
    if (!weekData || !weekData.completed) return;

    container.innerHTML = '';

    const weekTitle = el("h3", "rag-week-chart-title", "Week " + weekNum + " Results");
    container.appendChild(weekTitle);

    const stats = el("div","rag-stats");
    stats.appendChild(el("div","stat","Reds: " + weekData.rag.reds));
    stats.appendChild(el("div","stat","Ambers: " + weekData.rag.ambers));
    stats.appendChild(el("div","stat","Greens: " + weekData.rag.greens));
    stats.appendChild(el("div","stat","Score: " + weekData.rag.total_score));
    container.appendChild(stats);

    const canvas = document.createElement('canvas');
    canvas.width = 400;
    canvas.height = 400;
    container.appendChild(canvas);

    setTimeout(function() {
      drawPieChart(canvas, parseInt(weekData.rag.reds), parseInt(weekData.rag.ambers), parseInt(weekData.rag.greens));
    }, 50);

    if (weekData.mbti) {
      const mbtiDiv = el("div", "rag-mbti-week", "MBTI: " + weekData.mbti);
      container.appendChild(mbtiDiv);
    }
  }

  function drawPieChart(canvas, reds, ambers, greens) {
    console.log('Drawing pie chart:', reds, ambers, greens);
    const ctx = canvas.getContext('2d');
    const total = reds + ambers + greens;
    
    if (total === 0) {
      console.log('Total is 0, not drawing chart');
      return;
    }

    const centerX = canvas.width / 2;
    const centerY = (canvas.height - 60) / 2;
    const radius = Math.min(centerX, centerY) - 20;

    const redPercent = (reds / total) * 100;
    const amberPercent = (ambers / total) * 100;
    const greenPercent = (greens / total) * 100;

    const redAngle = (reds / total) * 2 * Math.PI;
    const amberAngle = (ambers / total) * 2 * Math.PI;
    const greenAngle = (greens / total) * 2 * Math.PI;

    let currentAngle = -Math.PI / 2;

    // Clear canvas
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Red slice
    if (reds > 0) {
      ctx.fillStyle = '#d9534f';
      ctx.beginPath();
      ctx.moveTo(centerX, centerY);
      ctx.arc(centerX, centerY, radius, currentAngle, currentAngle + redAngle);
      ctx.closePath();
      ctx.fill();
      currentAngle += redAngle;
    }

    // Amber slice
    if (ambers > 0) {
      ctx.fillStyle = '#f0ad4e';
      ctx.beginPath();
      ctx.moveTo(centerX, centerY);
      ctx.arc(centerX, centerY, radius, currentAngle, currentAngle + amberAngle);
      ctx.closePath();
      ctx.fill();
      currentAngle += amberAngle;
    }

    // Green slice
    if (greens > 0) {
      ctx.fillStyle = '#5cb85c';
      ctx.beginPath();
      ctx.moveTo(centerX, centerY);
      ctx.arc(centerX, centerY, radius, currentAngle, currentAngle + greenAngle);
      ctx.closePath();
      ctx.fill();
    }

    // Legend at bottom
    const legendY = canvas.height - 50;
    const legendStartX = 30;
    ctx.font = 'bold 14px Arial';
    
    // Red legend
    ctx.fillStyle = '#d9534f';
    ctx.fillRect(legendStartX, legendY, 20, 20);
    ctx.fillStyle = '#000';
    ctx.fillText('Red: ' + Math.round(redPercent) + '%', legendStartX + 25, legendY + 15);

    // Amber legend
    ctx.fillStyle = '#f0ad4e';
    ctx.fillRect(legendStartX + 120, legendY, 20, 20);
    ctx.fillStyle = '#000';
    ctx.fillText('Amber: ' + Math.round(amberPercent) + '%', legendStartX + 145, legendY + 15);

    // Green legend
    ctx.fillStyle = '#5cb85c';
    ctx.fillRect(legendStartX + 260, legendY, 20, 20);
    ctx.fillStyle = '#000';
    ctx.fillText('Green: ' + Math.round(greenPercent) + '%', legendStartX + 285, legendY + 15);
    
    console.log('Pie chart drawn successfully');
  }

  renderIntro();
})();// ============================================================================
// DISC POLAR PLOT VISUALIZATION
// Add this to your mfsd-weekly-rag.js file
// ============================================================================

// DISC Descriptions (age-appropriate for 12-14 year olds)
const DISC_DESCRIPTIONS = {
  "D": {
    "title": "Dominance (D) - The Leader",
    "short": "You like to take charge and get things done! You're confident, direct, and love a good challenge.",
    "strengths": "You're brave, determined, and great at making quick decisions.",
    "growth": "Sometimes remember to slow down and listen to others' ideas.",
    "tip": "Your leadership skills are awesome! Try letting others share their thoughts too."
  },
  "I": {
    "title": "Influence (I) - The Enthusiast",
    "short": "You're fun, friendly, and love being around people! You make friends easily.",
    "strengths": "You're optimistic, creative, and amazing at bringing people together.",
    "growth": "Try to stay focused on finishing what you start.",
    "tip": "Your positive energy brightens everyone's day! Balance fun with getting stuff done."
  },
  "S": {
    "title": "Steadiness (S) - The Supporter",
    "short": "You're calm, loyal, and a great friend. People know they can count on you.",
    "strengths": "You're patient, reliable, and an excellent listener.",
    "growth": "It's okay to share your own opinions and try new things!",
    "tip": "Your steady support means so much to others. Don't be afraid to speak up too!"
  },
  "C": {
    "title": "Conscientiousness (C) - The Thinker",
    "short": "You're thoughtful, detail-oriented, and love getting things right.",
    "strengths": "You're careful, organized, and produce high-quality work.",
    "growth": "Remember that sometimes 'good enough' is okay - not everything needs to be perfect.",
    "tip": "Your attention to detail is a superpower! It's okay to make mistakes sometimes."
  },
  "DI": {
    "title": "Dominance + Influence - The Inspiring Leader",
    "short": "You're confident and outgoing! You love leading groups and getting people excited.",
    "strengths": "You're energetic, persuasive, and great at motivating others.",
    "growth": "Try to pause and listen to quieter voices in the group.",
    "tip": "You're a natural leader who people want to follow! Balance your energy with patience."
  },
  "DC": {
    "title": "Dominance + Conscientiousness - The Strategic Leader",
    "short": "You're determined and smart! You set high goals and make detailed plans.",
    "strengths": "You're focused, analytical, and excellent at solving complex problems.",
    "growth": "Try to be flexible when plans change.",
    "tip": "Your combination of drive and thinking skills is powerful!"
  },
  "IS": {
    "title": "Influence + Steadiness - The Friendly Helper",
    "short": "You're warm, kind, and love working with others. You make everyone feel welcome.",
    "strengths": "You're empathetic, supportive, and create harmony in groups.",
    "growth": "It's okay to say 'no' sometimes and share when you disagree.",
    "tip": "Your caring nature is a gift! Don't forget to take care of yourself too."
  },
  "IC": {
    "title": "Influence + Conscientiousness - The Creative Planner",
    "short": "You're friendly and organized! You enjoy working with people while doing things well.",
    "strengths": "You're sociable yet detail-oriented.",
    "growth": "Try not to worry too much about what others think.",
    "tip": "You balance fun and focus really well! Believe in yourself."
  },
  "SC": {
    "title": "Steadiness + Conscientiousness - The Reliable Achiever",
    "short": "You're calm, careful, and dependable. You take time to do things properly.",
    "strengths": "You're patient, thorough, and consistently produce great work.",
    "growth": "Try to be more comfortable with change and taking risks.",
    "tip": "Your steady, quality work is amazing! Don't be afraid to try new things."
  },
  "DS": {
    "title": "Dominance + Steadiness - The Determined Supporter",
    "short": "You're strong-willed yet patient. You stand up for what's right while staying calm.",
    "strengths": "You're resilient, dependable, and balance taking charge with being supportive.",
    "growth": "Try to be more flexible and open to different approaches.",
    "tip": "Your mix of strength and stability is unique!"
  },
  "CD": {
    "title": "Conscientiousness + Dominance - The Strategic Achiever",
    "short": "You're thoughtful and driven! You plan carefully and work hard to make things happen.",
    "strengths": "You're logical, determined, and great at turning ideas into reality.",
    "growth": "Remember to collaborate and hear others out.",
    "tip": "Your planning and drive combo is strong! Include others for even better results."
  },
  "ID": {
    "title": "Influence + Dominance - The Dynamic Motivator",
    "short": "You're energetic and confident! You love getting people excited and leading them.",
    "strengths": "You're charismatic, action-oriented, and amazing at rallying people together.",
    "growth": "Take time to think things through before jumping in.",
    "tip": "Your energy and leadership inspire others! Balance enthusiasm with planning."
  },
  "SI": {
    "title": "Steadiness + Influence - The Caring Connector",
    "short": "You're friendly and supportive! You build strong friendships and help everyone get along.",
    "strengths": "You're warm, sociable, and create positive environments.",
    "growth": "Practice being more assertive and making quick decisions.",
    "tip": "Your ability to connect people is special! Trust yourself to take the lead sometimes."
  },
  "CS": {
    "title": "Conscientiousness + Steadiness - The Thoughtful Supporter",
    "short": "You're careful and patient. You think things through and work steadily toward goals.",
    "strengths": "You're methodical, reliable, and produce consistent quality work.",
    "growth": "Try to be more comfortable with uncertainty and faster decisions.",
    "tip": "Your careful approach creates great results! Challenge yourself to try quick decisions."
  },
  "CI": {
    "title": "Conscientiousness + Influence - The Analytical Communicator",
    "short": "You're detail-focused and friendly! You explain complex things clearly.",
    "strengths": "You're precise yet personable. You make complicated ideas easy to understand!",
    "growth": "Don't worry too much about being perfect in social situations.",
    "tip": "Your mix of smarts and social skills is valuable! Relax and trust yourself."
  }
};

/**
 * Create DISC polar plot using Canvas
 * @param {Object} scores - Object with D, I, S, C scores (normalized 0-100)
 * @param {string} primaryStyle - Primary DISC style (e.g., "D", "DI")
 * @returns {HTMLCanvasElement} - Canvas element with the plot
 */
function createDISCPolarPlot(scores) {
  const canvas = document.createElement('canvas');
  canvas.width = 500;
  canvas.height = 500;
  canvas.id = 'disc-polar-plot';
  
  const ctx = canvas.getContext('2d');
  const centerX = 250;
  const centerY = 250;
  const maxRadius = 180;
  
  // Background
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, 500, 500);
  
  // DISC segment colors (matching reference image)
  const colors = {
    'D': '#2d5f8d',  // Blue (Dominant)
    'I': '#f9b234',  // Yellow (Influential)
    'S': '#c67a3c',  // Orange (Steady)
    'C': '#3b5998'   // Dark blue (Compliant)
  };
  
  // Segment labels with positions and characteristics
  const segments = [
    { 
      key: 'D', 
      startAngle: 0, 
      endAngle: Math.PI / 2,
      label: 'Dominant',
      traits: ['Direct', 'Decisive', 'Doer'],
      labelPos: { x: centerX + 140, y: centerY - 140 }
    },
    { 
      key: 'I', 
      startAngle: Math.PI / 2, 
      endAngle: Math.PI,
      label: 'Influential',
      traits: ['Inspirational', 'Interactive', 'Interesting'],
      labelPos: { x: centerX - 140, y: centerY - 140 }
    },
    { 
      key: 'S', 
      startAngle: Math.PI, 
      endAngle: 3 * Math.PI / 2,
      label: 'Steady',
      traits: ['Stable', 'Supportive', 'Sincere'],
      labelPos: { x: centerX - 140, y: centerY + 140 }
    },
    { 
      key: 'C', 
      startAngle: 3 * Math.PI / 2, 
      endAngle: 2 * Math.PI,
      label: 'Compliant',
      traits: ['Cautious', 'Careful', 'Conscientious'],
      labelPos: { x: centerX + 140, y: centerY + 140 }
    }
  ];
  
  // Draw concentric circles (grid) - 4 levels for 25%, 50%, 75%, 100%
  ctx.strokeStyle = '#e0e0e0';
  ctx.lineWidth = 1;
  for (let i = 1; i <= 4; i++) {
    const r = (maxRadius / 4) * i;
    ctx.beginPath();
    ctx.arc(centerX, centerY, r, 0, 2 * Math.PI);
    ctx.stroke();
  }
  
  // Draw cross axes
  ctx.strokeStyle = '#c0c0c0';
  ctx.lineWidth = 2;
  ctx.setLineDash([5, 5]);
  
  // Vertical axis
  ctx.beginPath();
  ctx.moveTo(centerX, centerY - maxRadius);
  ctx.lineTo(centerX, centerY + maxRadius);
  ctx.stroke();
  
  // Horizontal axis
  ctx.beginPath();
  ctx.moveTo(centerX - maxRadius, centerY);
  ctx.lineTo(centerX + maxRadius, centerY);
  ctx.stroke();
  
  ctx.setLineDash([]);
  
  // Draw filled segments based on percentages
  segments.forEach(seg => {
    const percent = scores[seg.key].percent || 0;
    const fillRadius = (percent / 100) * maxRadius;
    
    // Draw filled segment
    ctx.fillStyle = colors[seg.key];
    ctx.globalAlpha = 0.6;
    ctx.beginPath();
    ctx.moveTo(centerX, centerY);
    ctx.arc(centerX, centerY, fillRadius, seg.startAngle, seg.endAngle);
    ctx.closePath();
    ctx.fill();
    ctx.globalAlpha = 1.0;
    
    // Draw segment outline
    ctx.strokeStyle = colors[seg.key];
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(centerX, centerY);
    ctx.arc(centerX, centerY, maxRadius, seg.startAngle, seg.endAngle);
    ctx.closePath();
    ctx.stroke();
  });
  
  // Add compass labels (Active, People Focus, etc.)
  ctx.font = 'bold 14px Arial';
  ctx.fillStyle = '#666';
  ctx.textAlign = 'center';
  
  ctx.fillText('Active', centerX, centerY - maxRadius - 15);
  ctx.fillText('Reflective', centerX, centerY + maxRadius + 25);
  
  ctx.save();
  ctx.translate(centerX - maxRadius - 25, centerY);
  ctx.rotate(-Math.PI / 2);
  ctx.fillText('People Focus', 0, 0);
  ctx.restore();
  
  ctx.save();
  ctx.translate(centerX + maxRadius + 25, centerY);
  ctx.rotate(Math.PI / 2);
  ctx.fillText('Task Focus', 0, 0);
  ctx.restore();
  
  // Draw segment labels and traits
  ctx.textAlign = 'center';
  segments.forEach(seg => {
    const percent = scores[seg.key].percent || 0;
    
    // Main letter in center of segment
    const midAngle = (seg.startAngle + seg.endAngle) / 2;
    const letterX = centerX + (maxRadius * 0.4) * Math.cos(midAngle);
    const letterY = centerY + (maxRadius * 0.4) * Math.sin(midAngle);
    
    ctx.font = 'bold 48px Arial';
    ctx.fillStyle = '#333';
    ctx.fillText(seg.key, letterX, letterY);
    
    // Full label outside circle
    ctx.font = 'bold 16px Arial';
    ctx.fillStyle = '#333';
    const labelAngle = (seg.startAngle + seg.endAngle) / 2;
    const labelX = centerX + (maxRadius + 50) * Math.cos(labelAngle);
    const labelY = centerY + (maxRadius + 50) * Math.sin(labelAngle);
    ctx.fillText(seg.label, labelX, labelY);
    
    // Traits (smaller text)
    ctx.font = '11px Arial';
    ctx.fillStyle = '#666';
    seg.traits.forEach((trait, i) => {
      ctx.fillText(trait, labelX, labelY + 18 + (i * 14));
    });
    
    // Percentage
    ctx.font = 'bold 14px Arial';
    ctx.fillStyle = colors[seg.key];
    const pctX = centerX + (maxRadius * 0.65) * Math.cos(midAngle);
    const pctY = centerY + (maxRadius * 0.65) * Math.sin(midAngle);
    ctx.fillText(Math.round(percent) + '%', pctX, pctY);
  });
  
  return canvas;
}

/**
 * Render DISC results in the summary
 * Add this inside your renderSummary() function
 * @param {Object} summaryData - Summary data from API including disc_results
 */
function renderDISCResults(summaryData) {
  if (!summaryData.disc_type || !summaryData.disc_scores) {
    return null;
  }
  
  const discCard = el("div", "rag-card");
  discCard.style.cssText = "margin-top: 20px;";
  
  // Title
  const title = el("h3", "rag-title", "🎯 Your DISC Personality Style");
  discCard.appendChild(title);
  
  // Primary style with description
  const desc = DISC_DESCRIPTIONS[summaryData.disc_type];
  
  if (desc) {
    const styleHeader = el("div", "disc-style-header");
    styleHeader.style.cssText = "background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 16px; border-radius: 10px; margin: 12px 0;";
    
    const styleTitle = el("h4", "");
    styleTitle.style.cssText = "margin: 0 0 8px 0; font-size: 20px;";
    styleTitle.textContent = desc.title;
    styleHeader.appendChild(styleTitle);
    
    const styleShort = el("p", "");
    styleShort.style.cssText = "margin: 0; font-size: 15px; line-height: 1.5;";
    styleShort.textContent = desc.short;
    styleHeader.appendChild(styleShort);
    
    discCard.appendChild(styleHeader);
  }
  
  // Create polar plot
  const plotContainer = el("div", "disc-plot-container");
  plotContainer.style.cssText = "display: flex; flex-direction: column; align-items: center; margin: 20px 0; background: white; padding: 20px; border-radius: 12px; border: 1px solid #e5e5e5;";
  
  const plotTitle = el("h4", "");
  plotTitle.style.cssText = "margin: 0 0 16px 0; color: #2c3e50; font-size: 18px;";
  plotTitle.textContent = "Your DISC Profile Visualization";
  plotContainer.appendChild(plotTitle);
  
  const canvas = createDISCPolarPlot(summaryData.disc_scores, summaryData.disc_type);
  plotContainer.appendChild(canvas);
  
  const plotCaption = el("p", "");
  plotCaption.style.cssText = "margin: 12px 0 0 0; font-size: 13px; color: #666; text-align: center; max-width: 360px;";
  plotCaption.textContent = "The green arrow shows your unique personality blend. Each dimension contributes to where you land on the plot.";
  plotContainer.appendChild(plotCaption);
  
  discCard.appendChild(plotContainer);
  
  // Score breakdown with percentages
  const breakdownTitle = el("h4", "");
  breakdownTitle.style.cssText = "margin: 20px 0 12px 0; color: #2c3e50; font-size: 17px;";
  breakdownTitle.textContent = "Your DISC Breakdown";
  discCard.appendChild(breakdownTitle);
  
  const breakdown = el("div", "disc-breakdown");
  breakdown.style.cssText = "display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 16px 0;";
  
  const dimensions = [
    { key: 'D', label: 'Dominance', color: '#e74c3c' },
    { key: 'I', label: 'Influence', color: '#f39c12' },
    { key: 'S', label: 'Steadiness', color: '#2ecc71' },
    { key: 'C', label: 'Conscientiousness', color: '#3498db' }
  ];
  
  dimensions.forEach(dim => {
    const score = summaryData.disc_scores[dim.key];
    const bar = el("div", "disc-bar");
    bar.style.cssText = `
      background: linear-gradient(to top, ${dim.color}, ${dim.color}dd);
      border-radius: 8px;
      padding: 12px 8px;
      color: white;
      text-align: center;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      min-height: 120px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    `;
    
    const label = el("div", "");
    label.style.cssText = "font-weight: 600; font-size: 16px;";
    label.textContent = dim.key;
    bar.appendChild(label);
    
    const percent = el("div", "");
    percent.style.cssText = "font-size: 24px; font-weight: bold; margin: 8px 0;";
    percent.textContent = Math.round(score.percent) + "%";
    bar.appendChild(percent);
    
    const name = el("div", "");
    name.style.cssText = "font-size: 11px; opacity: 0.9;";
    name.textContent = dim.label;
    bar.appendChild(name);
    
    breakdown.appendChild(bar);
  });
  
  discCard.appendChild(breakdown);
  
  // Personality insights
  if (desc) {
    const insights = el("div", "disc-insights");
    insights.style.cssText = "background: #f8f9fa; border-radius: 8px; padding: 16px; margin: 16px 0;";
    
    const strengthsBox = el("div", "");
    strengthsBox.style.cssText = "margin-bottom: 12px;";
    const strengthsLabel = el("strong", "");
    strengthsLabel.textContent = "💪 Your Strengths: ";
    strengthsBox.appendChild(strengthsLabel);
    strengthsBox.appendChild(document.createTextNode(desc.strengths));
    insights.appendChild(strengthsBox);
    
    const growthBox = el("div", "");
    growthBox.style.cssText = "margin-bottom: 12px;";
    const growthLabel = el("strong", "");
    growthLabel.textContent = "🌱 Room to Grow: ";
    growthBox.appendChild(growthLabel);
    growthBox.appendChild(document.createTextNode(desc.growth));
    insights.appendChild(growthBox);
    
    const tipBox = el("div", "");
    tipBox.style.cssText = "background: #fff3cd; padding: 12px; border-radius: 6px; border-left: 4px solid #f0ad4e;";
    const tipLabel = el("strong", "");
    tipLabel.textContent = "💡 Tip: ";
    tipBox.appendChild(tipLabel);
    tipBox.appendChild(document.createTextNode(desc.tip));
    insights.appendChild(tipBox);
    
    discCard.appendChild(insights);
  }
  
  return discCard;
}

// ============================================================================
// DISC ANSWER HANDLING
// Add this function to handle DISC question answers
// ============================================================================

async function handleDISCAnswer(question, answerValue) {
  showLoading('Saving your answer...');
  
  try {
    const mapping = question.disc_mapping;
    const contribution = answerValue - 3; // Convert 1-5 scale to -2 to +2
    
    const payload = {
      week: week,
      question_id: question.id,
      q_type: 'DISC',
      answer: answerValue,
      d_contribution: mapping.D * contribution,
      i_contribution: mapping.I * contribution,
      s_contribution: mapping.S * contribution,
      c_contribution: mapping.C * contribution
    };
    
    const res = await fetch(cfg.restUrlAnswer, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce || ''
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    });
    
    if (!res.ok) throw new Error('Failed to save answer');
    
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed');
    
    hideLoading();
    
    // Move to next question or summary
    idx++;
    if (idx < questions.length) {
      await renderQuestion();
    } else {
      await renderSummary();
    }
    
  } catch (err) {
    hideLoading();
    console.error('Error saving DISC answer:', err);
    alert('Error saving answer: ' + err.message);
  }
}

// ============================================================================
// UPDATE YOUR renderQuestion() FUNCTION
// Add DISC question rendering
// ============================================================================

// Inside renderQuestion(), add this case for DISC questions:
if (q.q_type === 'DISC') {
  // DISC uses 1-5 Likert scale
  const scaleContainer = el("div", "disc-scale-container");
  scaleContainer.style.cssText = "margin: 20px 0;";
  
  const scaleLabel = el("div", "disc-scale-label");
  scaleLabel.style.cssText = "text-align: center; margin-bottom: 12px; font-weight: 600; color: #555;";
  scaleLabel.textContent = "How much do you agree with this statement?";
  scaleContainer.appendChild(scaleLabel);
  
  const lights = el("div", "rag-lights");
  lights.style.cssText = "display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;";
  
  const options = [
    { label: "Completely\nDisagree", value: 1, color: "#d9534f", emoji: "👎" },
    { label: "Somewhat\nDisagree", value: 2, color: "#f0ad4e", emoji: "🤔" },
    { label: "Neutral", value: 3, color: "#9e9e9e", emoji: "😐" },
    { label: "Somewhat\nAgree", value: 4, color: "#5cb85c", emoji: "👍" },
    { label: "Completely\nAgree", value: 5, color: "#4caf50", emoji: "💯" }
  ];
  
  options.forEach(opt => {
    const btn = el("button", "rag-light disc-scale-btn");
    btn.style.cssText = `
      background: ${opt.color};
      color: white;
      border: none;
      border-radius: 10px;
      padding: 16px 12px;
      cursor: pointer;
      font-weight: 600;
      font-size: 13px;
      min-width: 90px;
      transition: all 0.2s;
      white-space: pre-line;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
    `;
    
    const emoji = el("span", "");
    emoji.style.cssText = "font-size: 24px;";
    emoji.textContent = opt.emoji;
    btn.appendChild(emoji);
    
    const label = el("span", "");
    label.style.cssText = "font-size: 12px; line-height: 1.3;";
    label.textContent = opt.label.replace('\n', ' ');
    btn.appendChild(label);
    
    btn.onmouseover = () => {
      btn.style.transform = "translateY(-3px)";
      btn.style.boxShadow = "0 4px 12px rgba(0,0,0,0.2)";
    };
    btn.onmouseout = () => {
      btn.style.transform = "translateY(0)";
      btn.style.boxShadow = "none";
    };
    btn.onclick = () => handleDISCAnswer(q, opt.value);
    
    lights.appendChild(btn);
  });
  
  scaleContainer.appendChild(lights);
  card.appendChild(scaleContainer);
}