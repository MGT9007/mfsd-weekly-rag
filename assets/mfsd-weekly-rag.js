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
    if (status.status === 'in_progress' && status.last_question_id) {
      await loadQuestions();
      await resumeFromLastQuestion(status.last_question_id);
      return;
    }

    // Otherwise show intro for new start
    const wrap = el("div","rag-wrap");
    const card = el("div","rag-card");
    card.appendChild(el("h2","rag-title","Week " + week + " — RAG + MBTI Tracker"));

    const p = el("p","rag-sub",
      "High Performance Pathway RAG + MBTI Weekly Tracker.\n" +
      "Greens = strengths ; Ambers = mixed ; Reds = needs support.\n");
    card.appendChild(p);

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

  async function resumeFromLastQuestion(lastQuestionId) {
    console.log('Resuming from last question:', lastQuestionId);
    
    // Find the index of the last answered question
    let lastIdx = -1;
    for (let i = 0; i < questions.length; i++) {
      if (questions[i].id === lastQuestionId) {
        lastIdx = i;
        break;
      }
    }
    
    if (lastIdx >= 0 && lastIdx < questions.length - 1) {
      // Start from the next question
      idx = lastIdx + 1;
      stack = []; // Could rebuild stack from DB if needed
      await renderQuestion();
    } else {
      // Couldn't find the question or it was the last one, start from beginning
      idx = 0;
      stack = [];
      await renderQuestion();
    }
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

    // Add AI chatbot for deeper questions
    if (chatSource && chatSource.firstChild) {
      const chatWrap = el("div","rag-chatwrap");
      // Move the original element instead of cloning to preserve event listeners
      const chatElement = chatSource.firstChild;
      chatWrap.appendChild(chatElement);
      card.appendChild(chatWrap);
    }

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

      // Week tabs for pie charts
      if (allWeeksData && allWeeksData.ok && allWeeksData.weeks) {
        const tabsContainer = el("div", "rag-week-tabs");

        for (let w = 1; w <= weekNum; w++) {
          const weekData = allWeeksData.weeks[w];
          if (weekData && weekData.completed) {
            const tab = el("button", "rag-week-tab" + (w === weekNum ? " active" : ""), "Week " + w);
            tab.setAttribute('data-week', w);
            tab.onclick = function() {
              document.querySelectorAll('.rag-week-tab').forEach(function(t) {
                t.classList.remove('active');
              });
              tab.classList.add('active');
              showWeekChart(w, allWeeksData.weeks);
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