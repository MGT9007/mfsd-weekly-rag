(function () {
  console.log('MFSD_RAG_CFG', window.MFSD_RAG_CFG);
  const cfg = window.MFSD_RAG_CFG || {};
  const root = document.getElementById("mfsd-rag-root");
  if (!root) return;

  const chatSource = document.getElementById("mfsd-rag-chat-source");

  let week = cfg.week || 1;
  console.log('Initial week from config:', week);

  // ============================================================================
  // MFSD TEXT-TO-SPEECH ENGINE (Web Speech API)
  // ============================================================================
  const mfsdTTS = {
    supported: ('speechSynthesis' in window),
    enabled: true,
    voices: [],
    preferredVoice: null,

    init() {
      if (!this.supported) return;
      const loadVoices = () => {
        this.voices = window.speechSynthesis.getVoices();
        const adminVoice = (cfg.ttsVoice || '').trim();
        if (adminVoice) {
          this.preferredVoice = this.voices.find(v => v.name === adminVoice) || null;
        }
        if (!this.preferredVoice) {
          this.preferredVoice =
            this.voices.find(v => v.name.includes('Google UK English Female')) ||
            this.voices.find(v => v.name.includes('Samantha')) ||
            this.voices.find(v => v.lang === 'en-GB') ||
            this.voices.find(v => v.lang.startsWith('en-')) ||
            this.voices[0] || null;
        }
      };
      loadVoices();
      window.speechSynthesis.onvoiceschanged = loadVoices;
    },

    _cleanForSpeech(text) {
      return text
        .replace(/\*\*\*(.*?)\*\*\*/g, '$1')
        .replace(/\*\*(.*?)\*\*/g, '$1')
        .replace(/\*(.*?)\*/g, '$1')
        .replace(/^#{1,6}\s+/gm, '')
        .replace(/^\s*[-*•]\s+/gm, '')
        .replace(/^\s*\d+\.\s+/gm, '')
        .replace(/\*/g, '')
        .replace(/`/g, '')
        .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
        .replace(/[\u{1F300}-\u{1FAFF}]/gu, '')
        .replace(/[\u{2600}-\u{27BF}]/gu, '')
        .replace(/[\u{FE00}-\u{FEFF}]/gu, '')
        .replace(/\s{2,}/g, ' ')
        .trim();
    },

    speak(text, onEnd) {
      if (!this.supported || !text) return;
      const cleanText = this._cleanForSpeech(text);
      window.speechSynthesis.cancel();
      const utt = new SpeechSynthesisUtterance(cleanText);
      utt.rate   = 0.92;
      utt.pitch  = 1.05;
      utt.volume = 1;
      if (this.preferredVoice) utt.voice = this.preferredVoice;
      if (onEnd) utt.onend = onEnd;
      window.speechSynthesis.speak(utt);
    },

    stop() {
      if (!this.supported) return;
      window.speechSynthesis.cancel();
    },

    _splitSentences(text) {
      const sentences = text.match(/[^.!?]+[.!?]+["'\u201d]?\s*/g);
      if (!sentences || !sentences.length) return [text];
      const joined = sentences.join('');
      const remainder = text.slice(joined.length).trim();
      if (remainder) sentences.push(remainder);
      return sentences.map(s => s.trim()).filter(Boolean);
    },

    _splitWords(text) {
      return text.split(/\s+/).filter(Boolean);
    },

    _makeUtt(text) {
      const utt = new SpeechSynthesisUtterance(text);
      utt.rate   = 0.92;
      utt.pitch  = 1.05;
      utt.volume = 1;
      if (this.preferredVoice) utt.voice = this.preferredVoice;
      return utt;
    },

    speakWithReveal(text, element, onEnd) {
      if (!this.supported || !text) {
        element.textContent = text;
        if (onEnd) onEnd();
        return;
      }
      const cleanText = this._cleanForSpeech(text);
      window.speechSynthesis.cancel();
      element.textContent = '';

      if (textReveal === 'block') {
        element.textContent = text;
        this.speak(text, onEnd);
        return;
      }

      if (textReveal === 'sentence') {
        const sentences      = this._splitSentences(text);
        const cleanSentences = this._splitSentences(cleanText);
        let revealed = '';
        let i = 0;
        const speakNext = () => {
          if (i >= sentences.length) {
            element.textContent = text;
            if (onEnd) onEnd();
            return;
          }
          const displaySentence = sentences[i];
          const speechSentence  = cleanSentences[i] || displaySentence;
          i++;
          revealed += (revealed ? ' ' : '') + displaySentence;
          element.textContent = revealed;
          const utt = this._makeUtt(speechSentence);
          utt.onend   = speakNext;
          utt.onerror = () => { element.textContent = text; if (onEnd) onEnd(); };
          window.speechSynthesis.speak(utt);
        };
        speakNext();
        return;
      }

      if (textReveal === 'word') {
        const words     = this._splitWords(text);
        const msPerWord = Math.round(60000 / (130 * 0.92) * 0.83);
        let wordIndex = 0;
        let timer = null;
        const revealNext = () => {
          if (wordIndex >= words.length) { element.textContent = text; clearInterval(timer); return; }
          wordIndex++;
          element.textContent = words.slice(0, wordIndex).join(' ');
        };
        const utt = this._makeUtt(cleanText);
        utt.onstart = () => { timer = setInterval(revealNext, msPerWord); };
        utt.onend   = () => { clearInterval(timer); element.textContent = text; if (onEnd) onEnd(); };
        utt.onerror = () => { clearInterval(timer); element.textContent = text; if (onEnd) onEnd(); };
        window.speechSynthesis.speak(utt);
        return;
      }

      element.textContent = text;
      this.speak(text, onEnd);
    },

    makeControls(text) {
      const wrap = document.createElement('div');
      wrap.className = 'mfsd-tts-controls';
      const speakBtn = document.createElement('button');
      speakBtn.className = 'mfsd-tts-btn mfsd-tts-speak';
      speakBtn.title = 'Listen';
      speakBtn.innerHTML = '🔊';
      speakBtn.onclick = (e) => { e.stopPropagation(); mfsdTTS.speak(text); };
      const stopBtn = document.createElement('button');
      stopBtn.className = 'mfsd-tts-btn mfsd-tts-stop';
      stopBtn.title = 'Stop';
      stopBtn.innerHTML = '⏹';
      stopBtn.onclick = (e) => { e.stopPropagation(); mfsdTTS.stop(); };
      wrap.appendChild(speakBtn);
      wrap.appendChild(stopBtn);
      return wrap;
    }
  };

  mfsdTTS.init();
  // ============================================================================

  const convMode   = (cfg.conversationMode || 'polite');
  const textReveal = (cfg.textReveal || 'block');

  // ============================================================================
  // MFSD SPEECH-TO-TEXT ENGINE
  // ============================================================================
  const mfsdSTT = {
    supported: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
    recognition: null,
    isListening: false,
    _silenceTimer: null,
    _silenceDelay: 2000,
    _onFinalCb: null,
    _onInterimCb: null,
    _onErrorCb: null,
    _accumulated: '',

    init() {
      // Instance created fresh in each listen() call
    },

    listen(onInterim, onFinal, onError) {
      if (!this.supported) { onError('Speech recognition is not supported in this browser.'); return; }
      if (this.isListening) { this.stop(); return; }

      if (convMode === 'polite') mfsdTTS.stop();

      this._onInterimCb = onInterim;
      this._onFinalCb   = onFinal;
      this._onErrorCb   = onError;
      this._accumulated = '';
      this._interrupted = false;
      this.isListening  = true;

      const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      this.recognition = new SR();
      this.recognition.lang = 'en-GB';
      this.recognition.interimResults = true;
      this.recognition.maxAlternatives = 1;
      this.recognition.continuous = true;

      this.recognition.onresult = (e) => {
        if (convMode === 'normal' && !this._interrupted) {
          this._interrupted = true;
          mfsdTTS.stop();
        }
        let interim = '';
        for (let i = e.resultIndex; i < e.results.length; i++) {
          const t = e.results[i][0].transcript;
          if (e.results[i].isFinal) {
            this._accumulated += (this._accumulated ? ' ' : '') + t.trim();
          } else {
            interim = t;
          }
        }
        const display = this._accumulated + (interim ? ' ' + interim : '');
        if (this._onInterimCb) this._onInterimCb(display.trim());
        clearTimeout(this._silenceTimer);
        this._silenceTimer = setTimeout(() => {
          const finalText = this._accumulated || display.trim();
          this.stop();
          if (finalText && this._onFinalCb) this._onFinalCb(finalText.trim());
        }, this._silenceDelay);
      };

      this.recognition.onerror = (e) => {
        this._cleanup();
        const msgs = {
          'not-allowed'  : 'Microphone access was denied. Please allow microphone permission and try again.',
          'no-speech'    : 'No speech was detected. Please try again.',
          'network'      : 'A network error occurred. Please check your connection.',
          'audio-capture': 'No microphone was found on this device.',
        };
        if (this._onErrorCb) this._onErrorCb(msgs[e.error] || 'Speech recognition error: ' + e.error);
      };

      this.recognition.onend = () => { this.isListening = false; };

      try {
        this.recognition.start();
      } catch(e) {
        this._cleanup();
        onError('Could not start microphone: ' + e.message);
      }
    },

    stop() {
      clearTimeout(this._silenceTimer);
      if (this.recognition) { try { this.recognition.stop(); } catch(e) {} }
      this.isListening = false;
    },

    _cleanup() {
      clearTimeout(this._silenceTimer);
      this.isListening  = false;
      this._accumulated = '';
    }
  };

  mfsdSTT.init();
  // ============================================================================

  let questions = [];
  let idx = 0;
  let stack = [];

  const el = (t, c, txt) => {
    const n = document.createElement(t);
    if (c) n.className = c;
    if (txt !== undefined) n.textContent = txt;
    return n;
  };

  async function checkWeekStatus() {
    console.log('Checking status for week:', week);
    try {
      const res = await fetch(cfg.restUrlStatus + "?week=" + encodeURIComponent(week), {
        method: 'GET',
        headers: { 'X-WP-Nonce': cfg.nonce || '', 'Accept': 'application/json' },
        credentials: 'same-origin'
      });
      if (res.ok) { const data = await res.json(); console.log('Status response:', data); return data; }
    } catch (err) { console.error('Status check error:', err); }
    return { status: 'not_started', can_start: true };
  }

  async function renderIntro() {
    console.log('renderIntro called, week =', week);
    const status = await checkWeekStatus();

    if (!status.can_start && status.blocking_week) {
      const wrap = el("div","rag-wrap"); const card = el("div","rag-card");
      card.appendChild(el("h2","rag-title","Week " + week + " — Not Available"));
      const msg = el("p","rag-error-msg","Please complete Week " + status.blocking_week + " before starting Week " + week + ".");
      card.appendChild(msg);
      const backBtn = el("button","rag-btn","Back"); backBtn.onclick = () => window.history.back();
      card.appendChild(backBtn); wrap.appendChild(card); root.replaceChildren(wrap); return;
    }

    if (status.status === 'completed') { await renderSummary(); return; }
    if (status.status === 'in_progress') {
      await loadQuestions();
      await resumeFromLastQuestion(status.last_question_id, status.answered_question_ids || []);
      return;
    }

    const wrap = el("div","rag-wrap"); const card = el("div","rag-card");
    card.appendChild(el("h2","rag-title","Week " + week + " — RAG + MBTI Tracker"));

    if (status.previous_week_summary) {
      const prevSummary = status.previous_week_summary;
      const summaryBox = el("div","rag-prev-week-summary");
      summaryBox.style.cssText = "background:#f0f8ff;border-left:4px solid #4a90e2;padding:12px 14px;border-radius:6px;margin:12px 0;";
      const summaryTitle = el("div","rag-prev-week-title");
      summaryTitle.style.cssText = "font-weight:600;margin-bottom:6px;color:#2c3e50;";
      summaryTitle.textContent = "Last Week (Week " + prevSummary.week + ") Results:";
      summaryBox.appendChild(summaryTitle);
      const stats = el("div","rag-prev-stats");
      stats.style.cssText = "display:flex;gap:12px;margin:8px 0;flex-wrap:wrap;";
      const greenStat = el("div","stat"); greenStat.style.cssText = "background:#d4edda;border:1px solid #c3e6cb;border-radius:6px;padding:6px 10px;font-size:14px;"; greenStat.textContent = "🟢 Greens: " + prevSummary.greens; stats.appendChild(greenStat);
      const amberStat = el("div","stat"); amberStat.style.cssText = "background:#fff3cd;border:1px solid #ffeaa7;border-radius:6px;padding:6px 10px;font-size:14px;"; amberStat.textContent = "🟠 Ambers: " + prevSummary.ambers; stats.appendChild(amberStat);
      const redStat = el("div","stat"); redStat.style.cssText = "background:#f8d7da;border:1px solid #f5c6cb;border-radius:6px;padding:6px 10px;font-size:14px;"; redStat.textContent = "🔴 Reds: " + prevSummary.reds; stats.appendChild(redStat);
      if (prevSummary.mbti_type) { const mbtiStat = el("div","stat"); mbtiStat.style.cssText = "background:#e8f4fd;border:1px solid #b8daff;border-radius:6px;padding:6px 10px;font-size:14px;font-weight:600;"; mbtiStat.textContent = "MBTI: " + prevSummary.mbti_type; stats.appendChild(mbtiStat); }
      summaryBox.appendChild(stats); card.appendChild(summaryBox);
    }

    if (status.intro_message) {
      const introBox = el("div","rag-ai-intro");
      introBox.style.cssText = "background:#fff8e6;border:1px solid #ffd966;border-left:4px solid #f0ad4e;padding:12px 14px;border-radius:6px;line-height:1.6;margin:12px 0;font-size:14px;color:#333;";
      introBox.textContent = status.intro_message;
      card.appendChild(introBox);
    } else {
      card.appendChild(el("p","rag-sub","High Performance Pathway RAG + MBTI Weekly Tracker.\nGreens = strengths ; Ambers = mixed ; Reds = needs support.\n"));
    }

    const btn = el("button","rag-btn","Begin RAG");
    btn.onclick = async () => { await loadQuestions(); idx = 0; stack = []; await renderQuestion(); };
    card.appendChild(btn); wrap.appendChild(card); root.replaceChildren(wrap);
  }

  async function resumeFromLastQuestion(lastQuestionId, answeredIds) {
    let firstUnansweredIdx = -1;
    for (let i = 0; i < questions.length; i++) {
      if (!answeredIds.includes(parseInt(questions[i].id))) { firstUnansweredIdx = i; break; }
    }
    if (firstUnansweredIdx >= 0) { idx = firstUnansweredIdx; stack = []; await renderQuestion(); }
    else { await renderSummary(); }
  }

  async function loadQuestions() {
    console.log('Loading questions for week:', week);
    try {
      const res = await fetch(cfg.restUrlQuestions + "?week=" + encodeURIComponent(week), {
        method: 'GET', headers: { 'X-WP-Nonce': cfg.nonce || '', 'Accept': 'application/json' }, credentials: 'same-origin'
      });
      if (!res.ok) throw new Error("HTTP " + res.status);
      const data = await res.json();
      if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Failed');
      questions = data.questions || [];
      console.log('Loaded ' + questions.length + ' questions');
    } catch (err) { console.error('Error loading questions', err); alert('Loading questions failed: ' + err.message); throw err; }
  }

  async function renderQuestion() {
    showQuestionLoading();
    const q = questions[idx];
    const wrap = el("div","rag-wrap"); const card = el("div","rag-card");
    card.appendChild(el("div","rag-pos","Question " + (idx+1) + " of " + questions.length));
    card.appendChild(el("div","rag-qtext", q.q_text));

    // DISC question
    if (q.q_type === 'DISC') {
      const scaleContainer = el("div","disc-scale-container"); scaleContainer.style.cssText = "margin:20px 0;";
      const scaleLabel = el("div","disc-scale-label"); scaleLabel.style.cssText = "text-align:center;margin-bottom:12px;font-weight:600;color:#555;"; scaleLabel.textContent = "How much do you agree with this statement?";
      scaleContainer.appendChild(scaleLabel);
      const lights = el("div","rag-lights"); lights.style.cssText = "display:flex;gap:8px;justify-content:center;flex-wrap:wrap;";
      const options = [
        {label:"Completely Disagree",value:1,color:"#d9534f",emoji:"👎"},
        {label:"Somewhat Disagree",value:2,color:"#f0ad4e",emoji:"🤔"},
        {label:"Neutral",value:3,color:"#9e9e9e",emoji:"😐"},
        {label:"Somewhat Agree",value:4,color:"#5cb85c",emoji:"👍"},
        {label:"Completely Agree",value:5,color:"#4caf50",emoji:"💯"}
      ];
      options.forEach(opt => {
        const btn = el("button","rag-light disc-scale-btn");
        btn.style.cssText = `background:${opt.color};color:white;border:none;border-radius:10px;padding:16px 12px;cursor:pointer;font-weight:600;font-size:13px;min-width:90px;transition:all 0.2s;text-align:center;display:flex;flex-direction:column;align-items:center;gap:6px;`;
        const emoji = el("span",""); emoji.style.cssText = "font-size:24px;"; emoji.textContent = opt.emoji; btn.appendChild(emoji);
        const label = el("span",""); label.style.cssText = "font-size:12px;line-height:1.3;"; label.textContent = opt.label; btn.appendChild(label);
        btn.onmouseover = () => { btn.style.transform="translateY(-3px)"; btn.style.boxShadow="0 4px 12px rgba(0,0,0,0.2)"; };
        btn.onmouseout  = () => { btn.style.transform="translateY(0)"; btn.style.boxShadow="none"; };
        btn.onclick = async () => {
          showQuestionLoading('Saving your answer...');
          try {
            let mapping = q.disc_mapping;
            if (typeof mapping === 'string') { try { mapping = JSON.parse(mapping); } catch(e) { hideQuestionLoading(); alert('Error: Invalid DISC question data.'); return; } }
            if (!mapping || !mapping.hasOwnProperty('D')) { hideQuestionLoading(); alert('Error: DISC question missing mapping data.'); return; }
            const contribution = opt.value - 3;
            const payload = { week, question_id: q.id, q_type: 'DISC', disc_answer: opt.value, d_contribution: mapping.D*contribution, i_contribution: mapping.I*contribution, s_contribution: mapping.S*contribution, c_contribution: mapping.C*contribution };
            const res = await fetch(cfg.restUrlAnswer, { method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''}, credentials:'same-origin', body:JSON.stringify(payload) });
            if (!res.ok) throw new Error('Failed to save answer');
            const data = await res.json(); if (!data.ok) throw new Error(data.error||'Failed');
            hideQuestionLoading();
            idx++; if (idx < questions.length) await renderQuestion(); else await renderSummary();
          } catch(err) { hideQuestionLoading(); console.error('Error saving DISC answer:',err); alert('Error saving answer: '+err.message); }
        };
        lights.appendChild(btn);
      });
      scaleContainer.appendChild(lights); card.appendChild(scaleContainer);
      wrap.appendChild(card); root.replaceChildren(wrap); hideQuestionLoading(); return;
    }

    // Previous answers (weeks 2+)
    if (week > 1) {
      try {
        const prevRes = await fetch(cfg.restUrlPrevious + "?week=" + week + "&question_id=" + q.id, { method:'GET', headers:{'X-WP-Nonce':cfg.nonce||'','Accept':'application/json'}, credentials:'same-origin' });
        if (prevRes.ok) {
          const prevData = await prevRes.json();
          if (prevData.ok && prevData.previous && prevData.previous.length > 0) {
            const prevDiv = el("div","rag-prev");
            let prevText = "Previous answers: ";
            prevData.previous.forEach(function(p) {
              const color = p.answer==='R'?'🔴':(p.answer==='A'?'🟠':'🟢');
              prevText += "Week " + p.week_num + ": " + color + " ";
            });
            prevDiv.textContent = prevText; card.appendChild(prevDiv);
          }
        }
      } catch(err) { console.error('Error loading previous answers:', err); }
    }

    // AI guidance
    const aiGuidanceDiv = el("div","rag-ai-question");
    aiGuidanceDiv.innerHTML = '<em>Loading question guidance...</em>';
    card.appendChild(aiGuidanceDiv);
    try {
      const guidanceRes = await fetch(cfg.restUrlGuidance, { method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''}, credentials:'same-origin', body:JSON.stringify({week,question_id:q.id}) });
      if (guidanceRes.ok) {
        const guidanceData = await guidanceRes.json();
        if (guidanceData.ok && guidanceData.guidance) {
          const guidanceText = document.createElement('span');
          aiGuidanceDiv.innerHTML = ''; aiGuidanceDiv.appendChild(guidanceText);
          if (mfsdTTS.supported) { aiGuidanceDiv.appendChild(mfsdTTS.makeControls(guidanceData.guidance)); mfsdTTS.speakWithReveal(guidanceData.guidance, guidanceText); }
          else { guidanceText.textContent = guidanceData.guidance; }
        } else { aiGuidanceDiv.innerHTML = '<em>Question guidance unavailable.</em>'; }
      }
    } catch(err) { console.error('Error loading guidance:', err); aiGuidanceDiv.innerHTML = '<em>Question guidance unavailable.</em>'; }

    // Chatbot
    const chatWrap = el("div","rag-chatwrap");
    const chatHistory = el("div","rag-chat-history");
    chatHistory.style.cssText = "max-height:420px;overflow-y:auto;margin-bottom:12px;padding:10px;background:#f5f5f5;border-radius:6px;scroll-behavior:smooth;";
    const initialMsg = el("div","rag-chat-msg ai-msg");
    initialMsg.style.cssText = "margin-bottom:10px;padding:8px 12px;background:#e3f2fd;border-radius:8px;border-left:3px solid #2196f3;";
    const initialText = "Hi! How can I help you with this question?";
    initialMsg.textContent = initialText;
    if (mfsdTTS.supported) initialMsg.appendChild(mfsdTTS.makeControls(initialText));
    chatHistory.appendChild(initialMsg);
    chatWrap.appendChild(chatHistory);

    const inputContainer = el("div"); inputContainer.style.cssText = "display:flex;gap:8px;align-items:flex-end;";
    const chatInput = document.createElement("textarea"); chatInput.rows = 2; chatInput.placeholder = "Ask about this question..."; chatInput.style.cssText = "flex:1;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;resize:none;font-family:inherit;line-height:1.4;";
    const sendBtn = el("button","rag-btn","Send"); sendBtn.style.cssText = "padding:10px 20px;white-space:nowrap;";

    let conversationMode = false;

    function startListening() {
      if (!mfsdSTT.supported || !micBtn) return;
      chatInput.value = ""; chatInput.placeholder = "Listening…";
      micBtn.classList.add("mfsd-mic-active"); micBtn.title = "Tap to end conversation"; micBtn.innerHTML = "🎤";
      mfsdSTT.listen(
        (text) => { chatInput.value = text; },
        (text) => { chatInput.value = text; sendMessage(); },
        (msg) => {
          chatInput.placeholder = "Ask about this question...";
          const errEl = el("div","rag-chat-msg error-msg"); errEl.style.cssText = "margin-bottom:10px;padding:8px 12px;background:#ffebee;border-radius:8px;border-left:3px solid #f44336;font-size:13px;"; errEl.textContent = "🎤 " + msg;
          chatHistory.appendChild(errEl); chatHistory.scrollTop = chatHistory.scrollHeight;
          if (conversationMode) setTimeout(() => startListening(), 800);
        }
      );
    }

    function stopConversation() {
      conversationMode = false; mfsdSTT.stop();
      if (micBtn) { micBtn.classList.remove("mfsd-mic-active"); micBtn.title = "Start voice conversation"; micBtn.innerHTML = "🎤"; }
      chatInput.placeholder = "Ask about this question..."; chatInput.value = "";
    }

    let micBtn = null;
    if (mfsdSTT.supported) {
      micBtn = document.createElement("button"); micBtn.type = "button"; micBtn.className = "mfsd-mic-btn"; micBtn.title = "Start voice conversation"; micBtn.innerHTML = "🎤";
      micBtn.addEventListener("click", () => {
        if (conversationMode) { stopConversation(); } else { conversationMode = true; startListening(); }
      });
    }

    const sendMessage = async () => {
      const userMsg = chatInput.value.trim(); if (!userMsg) return;
      const userMsgEl = el("div","rag-chat-msg user-msg"); userMsgEl.style.cssText = "margin-bottom:10px;padding:8px 12px;background:#fff;border-radius:8px;border-left:3px solid #666;text-align:left;"; userMsgEl.textContent = userMsg;
      chatHistory.appendChild(userMsgEl); chatInput.value = ""; chatInput.placeholder = conversationMode?"Waiting for AI reply…":"Ask about this question..."; sendBtn.disabled = true; sendBtn.textContent = "Sending..."; chatHistory.scrollTop = chatHistory.scrollHeight;
      try {
        const response = await fetch(cfg.restUrlQuestionChat, { method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''}, credentials:'same-origin', body:JSON.stringify({week,question_id:q.id,message:userMsg}) });
        if (response.ok) {
          const data = await response.json();
          if (data.ok && data.response) {
            const aiMsgEl = el("div","rag-chat-msg ai-msg"); aiMsgEl.style.cssText = "margin-bottom:10px;padding:8px 12px;background:#e3f2fd;border-radius:8px;border-left:3px solid #2196f3;";
            const aiMsgText = document.createElement('span'); aiMsgEl.appendChild(aiMsgText); chatHistory.appendChild(aiMsgEl); chatHistory.scrollTop = chatHistory.scrollHeight;
            if (mfsdTTS.supported) {
              aiMsgEl.appendChild(mfsdTTS.makeControls(data.response));
              if (mfsdTTS.enabled) {
                if (convMode === 'polite') { mfsdTTS.speakWithReveal(data.response, aiMsgText, () => { if (conversationMode) startListening(); }); }
                else { mfsdTTS.speakWithReveal(data.response, aiMsgText); if (conversationMode) startListening(); }
              } else { aiMsgText.textContent = data.response; if (conversationMode) setTimeout(() => { if (conversationMode) startListening(); }, 500); }
            } else { aiMsgText.textContent = data.response; if (conversationMode) setTimeout(() => { if (conversationMode) startListening(); }, 500); }
          }
        } else { throw new Error('Failed to get response'); }
      } catch(err) {
        console.error('Chat error:', err);
        const errorMsgEl = el("div","rag-chat-msg error-msg"); errorMsgEl.style.cssText = "margin-bottom:10px;padding:8px 12px;background:#ffebee;border-radius:8px;border-left:3px solid #f44336;"; errorMsgEl.textContent = "Sorry, I couldn't process your message. Please try again.";
        chatHistory.appendChild(errorMsgEl);
        if (conversationMode) setTimeout(() => { if (conversationMode) startListening(); }, 800);
      } finally { sendBtn.disabled = false; sendBtn.textContent = "Send"; if (!conversationMode) chatInput.focus(); }
    };

    sendBtn.onclick = sendMessage;
    chatInput.onkeydown = (e) => { if (e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMessage();} };
    chatInput.addEventListener('input', () => { if (mfsdSTT.isListening) { mfsdSTT.stop(); chatInput.placeholder = "Ask about this question..."; } });

    inputContainer.appendChild(chatInput);
    if (micBtn) inputContainer.appendChild(micBtn);
    inputContainer.appendChild(sendBtn);
    chatWrap.appendChild(inputContainer);
    card.appendChild(chatWrap);

    // RAG buttons
    const lights = el("div","rag-lights");
    const choices = [{key:'R',cls:'red',label:'Red'},{key:'A',cls:'amber',label:'Amber'},{key:'G',cls:'green',label:'Green'}];
    for (let i = 0; i < choices.length; i++) {
      const c = choices[i]; const b = el("button","rag-light "+c.cls,c.label);
      b.onclick = () => { stopConversation(); saveAnswer(q, c.key, b); };
      lights.appendChild(b);
    }
    card.appendChild(lights);

    const actions = el("div","rag-actions");
    const back = el("button","rag-btn secondary","Back"); back.disabled = (stack.length===0);
    back.onclick = () => { if (!stack.length) return; stack.pop(); idx = Math.max(0,idx-1); renderQuestion(); };
    actions.appendChild(back); card.appendChild(actions);
    wrap.appendChild(card); root.replaceChildren(wrap);
    hideQuestionLoading();
  }

  function showQuestionLoading() {
    const overlay = el("div","rag-loading-overlay"); const spinner = el("div","rag-spinner"); const text = el("div","rag-loading-text","Saving answer...");
    overlay.appendChild(spinner); overlay.appendChild(text); document.body.appendChild(overlay);
  }
  function hideQuestionLoading() { const overlay = document.querySelector(".rag-loading-overlay"); if (overlay) overlay.remove(); }

  async function saveAnswer(q, answer, buttonElement) {
    const allButtons = document.querySelectorAll('.rag-light, .rag-btn');
    allButtons.forEach(function(btn) { btn.disabled=true; btn.style.opacity='0.5'; btn.style.cursor='not-allowed'; });
    showQuestionLoading();

    const payload = { week, question_id: q.id, rag: answer };
    try {
      const res = await fetch(cfg.restUrlAnswer, { method:"POST", headers:{"Content-Type":"application/json","X-WP-Nonce":cfg.nonce||''}, credentials:'same-origin', body:JSON.stringify(payload) });
      const raw = await res.text(); let j = null;
      try { j = raw ? JSON.parse(raw) : null; } catch(e) { hideQuestionLoading(); alert("Server returned non-JSON: "+raw.slice(0,200)); return; }
      if (!res.ok||!j||!j.ok) { hideQuestionLoading(); alert("Save failed: "+((j&&j.error)||res.status+" "+res.statusText)); return; }

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
    } catch(err) { hideQuestionLoading(); console.error('Save error:',err); alert('Failed to save answer: '+err.message); }
  }

  function showLoadingOverlay() { const overlay=el("div","rag-loading-overlay"); const spinner=el("div","rag-spinner"); const text=el("div","rag-loading-text","Preparing Summary Results..."); overlay.appendChild(spinner); overlay.appendChild(text); document.body.appendChild(overlay); }
  function hideLoadingOverlay() { const overlay=document.querySelector(".rag-loading-overlay"); if(overlay)overlay.remove(); }

  async function renderSummary() {
    console.log('=== renderSummary START ===');
    showLoadingOverlay();
    try {
      const summaryRes = await fetch(cfg.restUrlSummary, { method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''}, credentials:'same-origin', body:JSON.stringify({week}) });
      const summaryRaw = await summaryRes.text(); let summaryData = null;
      try { summaryData = summaryRaw ? JSON.parse(summaryRaw) : null; } catch(e) { hideLoadingOverlay(); alert("Summary returned non-JSON: "+summaryRaw.slice(0,200)); return; }
      if (!summaryData||!summaryData.ok) { hideLoadingOverlay(); alert("Summary failed: "+((summaryData&&summaryData.error)||summaryRaw.slice(0,200))); return; }

      const allWeeksRes = await fetch(cfg.restUrlAllWeeks+"?_="+Date.now(), { method:'GET', headers:{'X-WP-Nonce':cfg.nonce||'','Accept':'application/json'}, credentials:'same-origin' });
      let allWeeksData = null; if (allWeeksRes.ok) allWeeksData = await allWeeksRes.json();

      hideLoadingOverlay();
      const wrap = el("div","rag-wrap"); const card = el("div","rag-card");
      const weekNum = summaryData.week || week;
      card.appendChild(el("h2","rag-title","Week " + weekNum + " Summary"));

      if (allWeeksData&&allWeeksData.ok&&allWeeksData.weeks) {
        const tabsContainer = el("div","rag-week-tabs");
        let maxCompletedWeek = 0;
        for (let w=1;w<=6;w++) { const wd=allWeeksData.weeks[w]; if(wd&&wd.completed) maxCompletedWeek=w; }
        for (let w=1;w<=maxCompletedWeek;w++) {
          const wd=allWeeksData.weeks[w];
          if (wd&&wd.completed) {
            const tab=el("button","rag-week-tab"+(w===weekNum?" active":""),"Week "+w);
            tab.setAttribute('data-week',w);
            tab.onclick = async function() { week=w; await renderSummary(); };
            tabsContainer.appendChild(tab);
          }
        }
        card.appendChild(tabsContainer);
      }

      const chartContainer = el("div","rag-chart-container"); chartContainer.id = "chart-display"; card.appendChild(chartContainer);

      if (summaryData.disc_type&&summaryData.disc_scores) {
        const discSection=el("div","rag-disc-section"); discSection.style.cssText="margin:20px 0;padding:20px;background:#f8f9fa;border-radius:8px;";
        const discTitle=el("div","rag-disc-title"); discTitle.style.cssText="font-size:18px;font-weight:600;margin-bottom:16px;text-align:center;"; discTitle.textContent="DISC Personality Style: "+summaryData.disc_type; discSection.appendChild(discTitle);
        const discContent=el("div","disc-content-wrapper"); discContent.style.cssText="display:flex;gap:20px;align-items:center;flex-wrap:wrap;justify-content:center;";
        const plotContainer=el("div","disc-plot-wrapper"); plotContainer.style.cssText="flex:0 0 auto;";
        const polarPlot=createDISCPolarPlot(summaryData.disc_scores); if(polarPlot) plotContainer.appendChild(polarPlot);
        discContent.appendChild(plotContainer);
        const breakdown=el("div","disc-breakdown"); breakdown.style.cssText="display:flex;flex-direction:column;gap:8px;min-width:120px;";
        const discColors={'D':'#2d5f8d','I':'#f9b234','S':'#c67a3c','C':'#3b5998'};
        ['D','I','S','C'].forEach(letter => {
          const scores=summaryData.disc_scores[letter]; const row=el("div","disc-score-row"); row.style.cssText="display:flex;align-items:center;gap:8px;";
          const colorBox=el("div","disc-color-box"); colorBox.style.cssText=`width:20px;height:20px;background:${discColors[letter]};border-radius:3px;flex-shrink:0;`;
          const labelPct=el("div","disc-label-pct"); labelPct.style.cssText="font-weight:600;font-size:14px;color:#333;"; labelPct.textContent=`${letter}: ${Math.round(scores.percent)}%`;
          row.appendChild(colorBox); row.appendChild(labelPct); breakdown.appendChild(row);
        });
        discContent.appendChild(breakdown); discSection.appendChild(discContent); card.appendChild(discSection);
      }

      if (summaryData.ai) {
        const aiSummaryDiv = el("div","rag-ai");
        if (mfsdTTS.supported) {
          const ttsBar=document.createElement('div'); ttsBar.className='mfsd-tts-summary-bar'; ttsBar.innerHTML='<span style="font-size:13px;color:#666;font-style:italic;">AI Summary</span>';
          ttsBar.appendChild(mfsdTTS.makeControls(summaryData.ai)); card.appendChild(ttsBar);
        }
        card.appendChild(aiSummaryDiv);
        if (mfsdTTS.supported) { setTimeout(()=>mfsdTTS.speakWithReveal(summaryData.ai,aiSummaryDiv),400); }
        else { aiSummaryDiv.textContent = summaryData.ai; }
      }

      const again=el("button","rag-btn","Back to intro"); again.onclick=()=>window.location.reload(); card.appendChild(again);
      wrap.appendChild(card); root.replaceChildren(wrap);

      setTimeout(function() {
        if (allWeeksData&&allWeeksData.ok) { showWeekChart(weekNum,allWeeksData.weeks); }
        else {
          const container=document.getElementById('chart-display');
          if (container) {
            const stats=el("div","rag-stats");
            stats.appendChild(el("div","stat","Reds: "+summaryData.rag.reds)); stats.appendChild(el("div","stat","Ambers: "+summaryData.rag.ambers)); stats.appendChild(el("div","stat","Greens: "+summaryData.rag.greens)); stats.appendChild(el("div","stat","Score: "+summaryData.rag.total_score));
            container.appendChild(stats);
            const canvas=document.createElement('canvas'); canvas.width=400; canvas.height=400; container.appendChild(canvas);
            setTimeout(function(){drawPieChart(canvas,parseInt(summaryData.rag.reds),parseInt(summaryData.rag.ambers),parseInt(summaryData.rag.greens));},50);
          }
        }
      }, 10);
    } catch(err) { hideLoadingOverlay(); console.error('Summary error:',err); alert('Failed to load summary: '+err.message); }
  }

  function showWeekChart(weekNum, weeksData) {
    const container=document.getElementById('chart-display'); if(!container)return;
    const weekData=weeksData[weekNum]; if(!weekData||!weekData.completed)return;
    container.innerHTML='';
    container.appendChild(el("h3","rag-week-chart-title","Week "+weekNum+" Results"));
    const stats=el("div","rag-stats");
    stats.appendChild(el("div","stat","Reds: "+weekData.rag.reds)); stats.appendChild(el("div","stat","Ambers: "+weekData.rag.ambers)); stats.appendChild(el("div","stat","Greens: "+weekData.rag.greens)); stats.appendChild(el("div","stat","Score: "+weekData.rag.total_score));
    container.appendChild(stats);
    const canvas=document.createElement('canvas'); canvas.width=400; canvas.height=400; container.appendChild(canvas);
    setTimeout(function(){drawPieChart(canvas,parseInt(weekData.rag.reds),parseInt(weekData.rag.ambers),parseInt(weekData.rag.greens));},50);
    if (weekData.mbti) container.appendChild(el("div","rag-mbti-week","MBTI: "+weekData.mbti));
  }

  function drawPieChart(canvas, reds, ambers, greens) {
    const ctx=canvas.getContext('2d'); const total=reds+ambers+greens; if(total===0)return;
    const cx=canvas.width/2, cy=(canvas.height-60)/2, r=Math.min(cx,cy)-20;
    const ra=(reds/total)*2*Math.PI, aa=(ambers/total)*2*Math.PI, ga=(greens/total)*2*Math.PI;
    let ca=-Math.PI/2; ctx.clearRect(0,0,canvas.width,canvas.height);
    if(reds>0){ctx.fillStyle='#d9534f';ctx.beginPath();ctx.moveTo(cx,cy);ctx.arc(cx,cy,r,ca,ca+ra);ctx.closePath();ctx.fill();ca+=ra;}
    if(ambers>0){ctx.fillStyle='#f0ad4e';ctx.beginPath();ctx.moveTo(cx,cy);ctx.arc(cx,cy,r,ca,ca+aa);ctx.closePath();ctx.fill();ca+=aa;}
    if(greens>0){ctx.fillStyle='#5cb85c';ctx.beginPath();ctx.moveTo(cx,cy);ctx.arc(cx,cy,r,ca,ca+ga);ctx.closePath();ctx.fill();}
    const ly=canvas.height-50, lx=30; ctx.font='bold 14px Arial';
    ctx.fillStyle='#d9534f'; ctx.fillRect(lx,ly,20,20); ctx.fillStyle='#000'; ctx.fillText('Red: '+Math.round((reds/total)*100)+'%',lx+25,ly+15);
    ctx.fillStyle='#f0ad4e'; ctx.fillRect(lx+120,ly,20,20); ctx.fillStyle='#000'; ctx.fillText('Amber: '+Math.round((ambers/total)*100)+'%',lx+145,ly+15);
    ctx.fillStyle='#5cb85c'; ctx.fillRect(lx+260,ly,20,20); ctx.fillStyle='#000'; ctx.fillText('Green: '+Math.round((greens/total)*100)+'%',lx+285,ly+15);
  }

  // ============================================================================
  // RED FOLLOW-UP SCREEN
  // ============================================================================

  async function renderRedFollowup(q, savedIdx) {
    showQuestionLoading('Loading your action plan...');
    let suggestionData = null;
    try {
      const res = await fetch(cfg.restUrlRedSuggestions, {
        method: 'POST', headers: {'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''}, credentials: 'same-origin',
        body: JSON.stringify({ week, question_id: q.id })
      });
      if (res.ok) suggestionData = await res.json();
    } catch(err) { console.error('Red suggestions error:', err); }
    hideQuestionLoading();

    const steveIntro  = suggestionData?.steve_intro  || "Thanks for being honest — let's make a plan to move this forward!";
    const suggestions = suggestionData?.suggestions  || [];
    const prevPlans   = suggestionData?.prev_plans   || [];
    const wordTarget  = suggestionData?.word_target  || 50;

    const wrap = el("div","rag-wrap"); const card = el("div","rag-card");

    // Context header
    const header = el("div"); header.style.cssText = "display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;gap:12px;";
    const qInfo = el("div"); qInfo.appendChild(el("div","rag-pos","Question "+(savedIdx+1)+" of "+questions.length+" — "+q.q_text));
    const redBadge = el("div"); redBadge.style.cssText = "display:inline-flex;align-items:center;gap:6px;background:#FCEBEB;color:#A32D2D;font-size:12px;font-weight:500;padding:4px 10px;border-radius:6px;border:0.5px solid #F7C1C1;white-space:nowrap;flex-shrink:0;"; redBadge.innerHTML = '<span style="width:8px;height:8px;border-radius:50%;background:#E24B4A;display:inline-block;"></span> You answered Red';
    header.appendChild(qInfo); header.appendChild(redBadge); card.appendChild(header);

    // Previous plan
    if (prevPlans.length > 0) {
      const lastPlan = prevPlans[0];
      const prevBox = el("div"); prevBox.style.cssText = "background:#fff8e6;border:0.5px solid #ffd966;border-left:3px solid #f0ad4e;border-radius:0 6px 6px 0;padding:12px 14px;margin-bottom:14px;";
      const prevTitle = el("div"); prevTitle.style.cssText = "font-size:12px;font-weight:500;color:#856404;margin-bottom:5px;"; prevTitle.textContent = "Your plan from Week " + lastPlan.week_num;
      const prevText = el("div"); prevText.style.cssText = "font-size:13px;color:#333;line-height:1.6;"; prevText.textContent = lastPlan.plan_text;
      prevBox.appendChild(prevTitle); prevBox.appendChild(prevText); card.appendChild(prevBox);
    }

    // SteveGPT intro
    const steveSection = el("div","rag-card"); steveSection.style.cssText = "background:#E6F1FB;border:0.5px solid #B5D4F4;padding:16px;margin-bottom:0;";
    const steveName = el("div"); steveName.style.cssText = "font-size:12px;font-weight:500;color:#185FA5;margin-bottom:6px;"; steveName.textContent = "SteveGPT";
    const steveText = el("div"); steveText.style.cssText = "font-size:14px;color:#1d2327;line-height:1.6;";
    steveSection.appendChild(steveName); steveSection.appendChild(steveText);
    if (mfsdTTS.supported) {
  const sp = document.createElement('span');
  steveText.appendChild(sp);
    steveSection.appendChild(mfsdTTS.makeControls(steveIntro));

    // After intro finishes, read each suggestion in sequence
    mfsdTTS.speakWithReveal(steveIntro, sp, () => {
      if (!suggestions.length) return;
      let i = 0;
      const speakNextSuggestion = () => {
        if (i >= suggestions.length) return;
        const text = 'Idea ' + (i + 1) + '. ' + suggestions[i];
        i++;
        mfsdTTS.speak(text, speakNextSuggestion);
      };
      speakNextSuggestion();
    });
   }

    // Suggestions
    if (suggestions.length > 0) {
      const sugLabel = el("div"); sugLabel.style.cssText = "font-size:12px;font-weight:500;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin:14px 0 8px;"; sugLabel.textContent = "Some ideas to get you started";
      steveSection.appendChild(sugLabel);
      suggestions.forEach((sug, i) => {
        const sugRow = el("div"); sugRow.style.cssText = "display:flex;gap:8px;align-items:flex-start;padding:9px 10px;border-radius:6px;border:0.5px solid #ddd;margin-bottom:6px;background:#fff;cursor:pointer;"; sugRow.title = "Tap to copy into your plan";
        const num = el("div"); num.style.cssText = "font-size:12px;font-weight:500;color:#999;min-width:16px;margin-top:2px;flex-shrink:0;"; num.textContent = (i+1)+".";
        const txt = el("div"); txt.style.cssText = "font-size:13px;color:#333;line-height:1.5;"; txt.textContent = sug;
        const hint = el("div"); hint.style.cssText = "font-size:11px;color:#aaa;margin-top:3px;"; hint.textContent = "Tap to copy into your plan";
        const inner = el("div"); inner.appendChild(txt); inner.appendChild(hint);
        sugRow.appendChild(num); sugRow.appendChild(inner);
        sugRow.addEventListener('click', () => {
          planTextarea.value = (planTextarea.value.trim() ? planTextarea.value.trim()+' ' : '') + sug;
          updateWordCount(); planTextarea.focus();
          hint.textContent = "Copied!"; setTimeout(() => { hint.textContent = "Tap to copy into your plan"; }, 2000);
        });
        steveSection.appendChild(sugRow);
      });
    }
    card.appendChild(steveSection);

    // Chat with SteveGPT
    const chatSection = el("div","rag-chatwrap"); chatSection.style.marginTop = "14px";
    const chatLabel = el("div"); chatLabel.style.cssText = "font-size:12px;font-weight:500;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin-bottom:8px;"; chatLabel.textContent = "Ask SteveGPT for more ideas";
    const chatHistory = el("div","rag-chat-history"); chatHistory.style.cssText = "max-height:260px;overflow-y:auto;margin-bottom:10px;padding:10px;background:#f5f5f5;border-radius:6px;scroll-behavior:smooth;";
    chatSection.appendChild(chatLabel); chatSection.appendChild(chatHistory);

    const chatInputRow = el("div"); chatInputRow.style.cssText = "display:flex;gap:8px;align-items:flex-end;";
    const chatInput = document.createElement("textarea"); chatInput.rows = 1; chatInput.placeholder = "Ask a follow-up question…"; chatInput.style.cssText = "flex:1;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;resize:none;font-family:inherit;line-height:1.4;";
    const chatSendBtn = el("button","rag-btn","Send"); chatSendBtn.style.cssText = "padding:9px 16px;white-space:nowrap;";

    let chatMicBtn = null, chatConvMode = false;
    const startChatListening = () => {
      if (!mfsdSTT.supported||!chatMicBtn) return;
      chatInput.value=""; chatInput.placeholder="Listening…"; chatMicBtn.classList.add("mfsd-mic-active");
      mfsdSTT.listen(
        (t)=>{chatInput.value=t;},
        (t)=>{chatInput.value=t;sendChatMessage();},
        ()=>{ chatInput.placeholder="Ask a follow-up question…"; if(chatConvMode)setTimeout(()=>startChatListening(),800); }
      );
    };
    if (mfsdSTT.supported) {
      chatMicBtn=document.createElement("button"); chatMicBtn.type="button"; chatMicBtn.className="mfsd-mic-btn"; chatMicBtn.title="Speak your question"; chatMicBtn.innerHTML="🎤";
      chatMicBtn.addEventListener("click",()=>{ chatConvMode=!chatConvMode; if(chatConvMode)startChatListening(); else{mfsdSTT.stop();chatMicBtn.classList.remove("mfsd-mic-active");chatInput.placeholder="Ask a follow-up question…";} });
    }

    const sendChatMessage = async () => {
      const msg=chatInput.value.trim(); if(!msg)return;
      const userEl=el("div","rag-chat-msg user-msg"); userEl.style.cssText="margin-bottom:8px;padding:8px 12px;background:#fff;border-radius:8px;border-left:3px solid #666;text-align:left;font-size:13px;"; userEl.textContent=msg;
      chatHistory.appendChild(userEl); chatInput.value=""; chatInput.placeholder="Waiting…"; chatSendBtn.disabled=true; chatHistory.scrollTop=chatHistory.scrollHeight;
      try {
        const res=await fetch(cfg.restUrlQuestionChat,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''},credentials:'same-origin',body:JSON.stringify({week,question_id:q.id,message:msg,is_red_followup:true})});
        if(res.ok){const data=await res.json();if(data.ok&&data.response){
          const aiEl=el("div","rag-chat-msg ai-msg"); aiEl.style.cssText="margin-bottom:8px;padding:8px 12px;background:#e3f2fd;border-radius:8px;border-left:3px solid #2196f3;font-size:13px;";
          const aiSpan=document.createElement('span'); aiEl.appendChild(aiSpan); chatHistory.appendChild(aiEl); chatHistory.scrollTop=chatHistory.scrollHeight;
          if(mfsdTTS.supported){aiEl.appendChild(mfsdTTS.makeControls(data.response));mfsdTTS.speakWithReveal(data.response,aiSpan,()=>{if(chatConvMode)startChatListening();});}
          else{aiSpan.textContent=data.response;if(chatConvMode)setTimeout(()=>startChatListening(),500);}
        }}
      } catch(err){console.error('Red chat error:',err);}
      finally{chatSendBtn.disabled=false;chatInput.placeholder="Ask a follow-up question…";}
    };
    chatSendBtn.onclick=sendChatMessage;
    chatInput.onkeydown=(e)=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendChatMessage();}};
    chatInputRow.appendChild(chatInput); if(chatMicBtn)chatInputRow.appendChild(chatMicBtn); chatInputRow.appendChild(chatSendBtn);
    chatSection.appendChild(chatInputRow); card.appendChild(chatSection);

    // Plan writing
    const hr=el("hr"); hr.style.cssText="border:none;border-top:0.5px solid #e5e5e5;margin:18px 0;"; card.appendChild(hr);
    const planHeader=el("div"); planHeader.style.cssText="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;";
    const planTitle=el("div"); planTitle.style.cssText="font-size:15px;font-weight:500;color:#1d2327;"; planTitle.textContent="Your plan to move from Red to Amber";
    const wordCountDisplay=el("div"); wordCountDisplay.style.cssText="text-align:right;font-size:12px;color:#666;line-height:1.5;"; wordCountDisplay.innerHTML=`Target: <strong style="font-weight:500;">${wordTarget} words</strong><br><span id="rf-word-count" style="color:#185FA5;font-weight:500;">0 / ${wordTarget}</span>`;
    planHeader.appendChild(planTitle); planHeader.appendChild(wordCountDisplay); card.appendChild(planHeader);

    const ageNote=el("div"); ageNote.style.cssText="font-size:12px;color:#666;background:#f8f8f8;border-radius:6px;padding:8px 12px;margin-bottom:12px;border:0.5px solid #e5e5e5;"; ageNote.textContent=`Write your plan below — aim for ${wordTarget} words. You can type it, speak it using the mic, or copy ideas from the suggestions above.`;
    card.appendChild(ageNote);

    const barWrap=el("div"); barWrap.style.cssText="height:4px;background:#e5e5e5;border-radius:2px;margin-bottom:14px;";
    const barFill=el("div"); barFill.style.cssText="height:100%;width:0%;background:#378ADD;border-radius:2px;transition:width 0.2s;";
    barWrap.appendChild(barFill); card.appendChild(barWrap);

    const planTextarea=document.createElement("textarea"); planTextarea.rows=4; planTextarea.placeholder="Write your plan here — what will you do this week to improve?"; planTextarea.style.cssText="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit;line-height:1.6;resize:vertical;";
    card.appendChild(planTextarea);

    const updateWordCount=()=>{
      const words=planTextarea.value.trim().split(/\s+/).filter(Boolean).length;
      const pct=Math.min(100,Math.round((words/wordTarget)*100));
      barFill.style.width=pct+'%'; barFill.style.background=words>=wordTarget?'#5cb85c':'#378ADD';
      const display=document.getElementById('rf-word-count');
      if(display){display.textContent=words+' / '+wordTarget;display.style.color=words>=wordTarget?'#3b6d11':'#185FA5';}
      saveBtn.disabled=words<wordTarget;
    };
    planTextarea.addEventListener('input',updateWordCount);

    const planInputRow=el("div"); planInputRow.style.cssText="display:flex;gap:8px;align-items:center;margin-top:10px;";
    if(mfsdSTT.supported){
      const planMicBtn=document.createElement("button"); planMicBtn.type="button"; planMicBtn.className="mfsd-mic-btn"; planMicBtn.title="Speak your plan"; planMicBtn.innerHTML="🎤";
      let planMicActive=false;
      planMicBtn.addEventListener("click",()=>{
        planMicActive=!planMicActive;
        if(planMicActive){planMicBtn.classList.add("mfsd-mic-active");mfsdSTT.listen((t)=>{planTextarea.value=t;updateWordCount();},(t)=>{planTextarea.value=t;updateWordCount();planMicActive=false;planMicBtn.classList.remove("mfsd-mic-active");},()=>{planMicActive=false;planMicBtn.classList.remove("mfsd-mic-active");});}
        else{mfsdSTT.stop();planMicBtn.classList.remove("mfsd-mic-active");}
      });
      planInputRow.appendChild(planMicBtn);
    }
    const micHint=el("div"); micHint.style.cssText="font-size:12px;color:#aaa;"; micHint.textContent="Tap mic to speak your plan";
    planInputRow.appendChild(micHint); card.appendChild(planInputRow);

    const saveBtn=el("button","rag-btn","Save my plan and continue"); saveBtn.style.cssText="width:100%;margin-top:14px;padding:12px;"; saveBtn.disabled=true;
    saveBtn.onclick=async()=>{
      const planText=planTextarea.value.trim(); const words=planText.split(/\s+/).filter(Boolean).length;
      if(words<wordTarget)return;
      saveBtn.disabled=true; saveBtn.textContent="Saving…";
      try{await fetch(cfg.restUrlSaveRedPlan,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''},credentials:'same-origin',body:JSON.stringify({week,question_id:q.id,plan_text:planText})});}
      catch(err){console.error('Save red plan error:',err);}
      mfsdTTS.stop(); mfsdSTT.stop();
      if(savedIdx<questions.length-1){idx=savedIdx+1;await renderQuestion();}else{await renderSummary();}
    };
    card.appendChild(saveBtn);
    updateWordCount();
    wrap.appendChild(card); root.replaceChildren(wrap);
  }

  renderIntro();
})();

// ============================================================================
// DISC POLAR PLOT
// ============================================================================

const DISC_DESCRIPTIONS = {
  "D":{"title":"Dominance (D) - The Leader","short":"You like to take charge and get things done!","strengths":"Brave, determined, great at making quick decisions.","growth":"Remember to slow down and listen to others.","tip":"Your leadership skills are awesome!"},
  "I":{"title":"Influence (I) - The Enthusiast","short":"You're fun, friendly, and love being around people!","strengths":"Optimistic, creative, amazing at bringing people together.","growth":"Try to stay focused on finishing what you start.","tip":"Your positive energy brightens everyone's day!"},
  "S":{"title":"Steadiness (S) - The Supporter","short":"You're calm, loyal, and a great friend.","strengths":"Patient, reliable, excellent listener.","growth":"It's okay to share your own opinions!","tip":"Your steady support means so much to others."},
  "C":{"title":"Conscientiousness (C) - The Thinker","short":"You're thoughtful, detail-oriented, and love getting things right.","strengths":"Careful, organized, produce high-quality work.","growth":"Sometimes good enough is okay.","tip":"Your attention to detail is a superpower!"}
};

function createDISCPolarPlot(scores) {
  const canvas=document.createElement('canvas'); canvas.width=500; canvas.height=500; canvas.id='disc-polar-plot';
  const ctx=canvas.getContext('2d'); const cx=250,cy=250,mr=180;
  ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,500,500);
  const colors={'D':'#2d5f8d','I':'#f9b234','S':'#c67a3c','C':'#3b5998'};
  const segments=[
    {key:'D',startAngle:0,endAngle:Math.PI/2,label:'Dominant',traits:['Direct','Decisive','Doer']},
    {key:'I',startAngle:Math.PI/2,endAngle:Math.PI,label:'Influential',traits:['Inspirational','Interactive','Interesting']},
    {key:'S',startAngle:Math.PI,endAngle:3*Math.PI/2,label:'Steady',traits:['Stable','Supportive','Sincere']},
    {key:'C',startAngle:3*Math.PI/2,endAngle:2*Math.PI,label:'Compliant',traits:['Cautious','Careful','Conscientious']}
  ];
  ctx.strokeStyle='#e0e0e0'; ctx.lineWidth=1;
  for(let i=1;i<=4;i++){ctx.beginPath();ctx.arc(cx,cy,(mr/4)*i,0,2*Math.PI);ctx.stroke();}
  ctx.strokeStyle='#c0c0c0'; ctx.lineWidth=2; ctx.setLineDash([5,5]);
  ctx.beginPath();ctx.moveTo(cx,cy-mr);ctx.lineTo(cx,cy+mr);ctx.stroke();
  ctx.beginPath();ctx.moveTo(cx-mr,cy);ctx.lineTo(cx+mr,cy);ctx.stroke();
  ctx.setLineDash([]);
  segments.forEach(seg=>{
    const pct=(scores[seg.key]&&scores[seg.key].percent)||0; const fr=(pct/100)*mr;
    ctx.fillStyle=colors[seg.key]; ctx.globalAlpha=0.6; ctx.beginPath(); ctx.moveTo(cx,cy); ctx.arc(cx,cy,fr,seg.startAngle,seg.endAngle); ctx.closePath(); ctx.fill(); ctx.globalAlpha=1.0;
    ctx.strokeStyle=colors[seg.key]; ctx.lineWidth=2; ctx.beginPath(); ctx.moveTo(cx,cy); ctx.arc(cx,cy,mr,seg.startAngle,seg.endAngle); ctx.closePath(); ctx.stroke();
  });
  ctx.font='bold 14px Arial'; ctx.fillStyle='#666'; ctx.textAlign='center';
  ctx.fillText('Active',cx,cy-mr-15); ctx.fillText('Reflective',cx,cy+mr+25);
  ctx.save();ctx.translate(cx-mr-25,cy);ctx.rotate(-Math.PI/2);ctx.fillText('People Focus',0,0);ctx.restore();
  ctx.save();ctx.translate(cx+mr+25,cy);ctx.rotate(Math.PI/2);ctx.fillText('Task Focus',0,0);ctx.restore();
  ctx.textAlign='center';
  segments.forEach(seg=>{
    const pct=(scores[seg.key]&&scores[seg.key].percent)||0; const mid=(seg.startAngle+seg.endAngle)/2;
    ctx.font='bold 48px Arial'; ctx.fillStyle='#333'; ctx.fillText(seg.key,cx+(mr*0.4)*Math.cos(mid),cy+(mr*0.4)*Math.sin(mid)+15);
    ctx.font='bold 16px Arial'; ctx.fillStyle='#333'; const lx=cx+(mr+50)*Math.cos(mid),ly=cy+(mr+50)*Math.sin(mid); ctx.fillText(seg.label,lx,ly);
    ctx.font='11px Arial'; ctx.fillStyle='#666'; seg.traits.forEach((t,i)=>ctx.fillText(t,lx,ly+18+(i*14)));
    ctx.font='bold 16px Arial'; ctx.fillStyle=colors[seg.key]; ctx.fillText(Math.round(pct)+'%',cx+(mr*0.65)*Math.cos(mid),cy+(mr*0.65)*Math.sin(mid)+5);
  });
  return canvas;
}