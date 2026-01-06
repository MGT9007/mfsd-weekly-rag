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

      // Week tabs for navigation
      if (allWeeksData && allWeeksData.ok && allWeeksData.weeks) {
        const tabsContainer = el("div", "rag-week-tabs");

        for (let w = 1; w <= weekNum; w++) {
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

      // Add MBTI and AI sections to card first
      if (summaryData.mbti) {
        card.appendChild(el("div","rag-mbti","MBTI Type: " + summaryData.mbti));
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
})();