(function () {
  console.log('MFSD_RAG_CFG', window.MFSD_RAG_CFG);
  const cfg = window.MFSD_RAG_CFG || {};
  const root = document.getElementById("mfsd-rag-root");
  if (!root) return;

  const chatSource = document.getElementById("mfsd-rag-chat-source");
  let week = cfg.week || 1;
  console.log('Initial week from config:', week);

  // ============================================================================
  // TTS ENGINE
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
        if (adminVoice) this.preferredVoice = this.voices.find(v => v.name === adminVoice) || null;
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
        .replace(/\*\*\*(.*?)\*\*\*/g, '$1').replace(/\*\*(.*?)\*\*/g, '$1').replace(/\*(.*?)\*/g, '$1')
        .replace(/^#{1,6}\s+/gm, '').replace(/^\s*[-*•]\s+/gm, '').replace(/^\s*\d+\.\s+/gm, '')
        .replace(/\*/g, '').replace(/`/g, '').replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
        .replace(/[\u{1F300}-\u{1FAFF}]/gu, '').replace(/[\u{2600}-\u{27BF}]/gu, '').replace(/[\u{FE00}-\u{FEFF}]/gu, '')
        .replace(/\s{2,}/g, ' ').trim();
    },

    speak(text, onEnd) {
      if (!this.supported || !text) return;
      const cleanText = this._cleanForSpeech(text);
      window.speechSynthesis.cancel();
      const utt = new SpeechSynthesisUtterance(cleanText);
      utt.rate = 0.92; utt.pitch = 1.05; utt.volume = 1;
      if (this.preferredVoice) utt.voice = this.preferredVoice;
      if (onEnd) utt.onend = onEnd;
      window.speechSynthesis.speak(utt);
    },

    stop() { if (!this.supported) return; window.speechSynthesis.cancel(); },

    _splitSentences(text) {
      const s = text.match(/[^.!?]+[.!?]+["'\u201d]?\s*/g);
      if (!s || !s.length) return [text];
      const joined = s.join(''); const rem = text.slice(joined.length).trim();
      if (rem) s.push(rem);
      return s.map(x => x.trim()).filter(Boolean);
    },

    _splitWords(text) { return text.split(/\s+/).filter(Boolean); },

    _makeUtt(text) {
      const utt = new SpeechSynthesisUtterance(text);
      utt.rate = 0.92; utt.pitch = 1.05; utt.volume = 1;
      if (this.preferredVoice) utt.voice = this.preferredVoice;
      return utt;
    },

    speakWithReveal(text, element, onEnd) {
      if (!this.supported || !text) { element.textContent = text; if (onEnd) onEnd(); return; }
      const cleanText = this._cleanForSpeech(text);
      window.speechSynthesis.cancel();
      element.textContent = '';

      if (textReveal === 'block') { element.textContent = text; this.speak(text, onEnd); return; }

      if (textReveal === 'sentence') {
        const sentences = this._splitSentences(text); const cleanSentences = this._splitSentences(cleanText);
        let revealed = '', i = 0;
        const speakNext = () => {
          if (i >= sentences.length) { element.textContent = text; if (onEnd) onEnd(); return; }
          const ds = sentences[i]; const ss = cleanSentences[i] || ds; i++;
          revealed += (revealed ? ' ' : '') + ds; element.textContent = revealed;
          const utt = this._makeUtt(ss);
          utt.onend = speakNext; utt.onerror = () => { element.textContent = text; if (onEnd) onEnd(); };
          window.speechSynthesis.speak(utt);
        };
        speakNext(); return;
      }

      if (textReveal === 'word') {
        const words = this._splitWords(text); const msPerWord = Math.round(60000 / (130 * 0.92) * 0.83);
        let wi = 0, timer = null;
        const revealNext = () => { if (wi >= words.length) { element.textContent = text; clearInterval(timer); return; } wi++; element.textContent = words.slice(0, wi).join(' '); };
        const utt = this._makeUtt(cleanText);
        utt.onstart = () => { timer = setInterval(revealNext, msPerWord); };
        utt.onend   = () => { clearInterval(timer); element.textContent = text; if (onEnd) onEnd(); };
        utt.onerror = () => { clearInterval(timer); element.textContent = text; if (onEnd) onEnd(); };
        window.speechSynthesis.speak(utt); return;
      }

      element.textContent = text; this.speak(text, onEnd);
    },

    makeControls(text) {
      const wrap = document.createElement('div'); wrap.className = 'mfsd-tts-controls';
      const sb = document.createElement('button'); sb.className = 'mfsd-tts-btn mfsd-tts-speak'; sb.title = 'Listen'; sb.innerHTML = '🔊'; sb.onclick = (e) => { e.stopPropagation(); mfsdTTS.speak(text); };
      const xb = document.createElement('button'); xb.className = 'mfsd-tts-btn mfsd-tts-stop'; xb.title = 'Stop'; xb.innerHTML = '⏹'; xb.onclick = (e) => { e.stopPropagation(); mfsdTTS.stop(); };
      wrap.appendChild(sb); wrap.appendChild(xb); return wrap;
    }
  };
  mfsdTTS.init();

  const convMode   = (cfg.conversationMode || 'polite');
  const textReveal = (cfg.textReveal || 'block');

  // ============================================================================
  // STT ENGINE
  // ============================================================================
  const mfsdSTT = {
    supported: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
    recognition: null, isListening: false,
    _silenceTimer: null, _silenceDelay: 2000,
    _onFinalCb: null, _onInterimCb: null, _onErrorCb: null, _accumulated: '',

    init() {},

    listen(onInterim, onFinal, onError) {
      if (!this.supported) { onError('Speech recognition is not supported in this browser.'); return; }
      if (this.isListening) { this.stop(); return; }
      if (convMode === 'polite') mfsdTTS.stop();
      this._onInterimCb = onInterim; this._onFinalCb = onFinal; this._onErrorCb = onError;
      this._accumulated = ''; this._interrupted = false; this.isListening = true;
      const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      this.recognition = new SR();
      this.recognition.lang = 'en-GB'; this.recognition.interimResults = true;
      this.recognition.maxAlternatives = 1; this.recognition.continuous = true;
      this.recognition.onresult = (e) => {
        if (convMode === 'normal' && !this._interrupted) { this._interrupted = true; mfsdTTS.stop(); }
        let interim = '';
        for (let i = e.resultIndex; i < e.results.length; i++) {
          const t = e.results[i][0].transcript;
          if (e.results[i].isFinal) this._accumulated += (this._accumulated ? ' ' : '') + t.trim();
          else interim = t;
        }
        const display = this._accumulated + (interim ? ' ' + interim : '');
        if (this._onInterimCb) this._onInterimCb(display.trim());
        clearTimeout(this._silenceTimer);
        this._silenceTimer = setTimeout(() => {
          const ft = this._accumulated || display.trim(); this.stop();
          if (ft && this._onFinalCb) this._onFinalCb(ft.trim());
        }, this._silenceDelay);
      };
      this.recognition.onerror = (e) => {
        this._cleanup();
        const msgs = {'not-allowed':'Microphone access was denied.','no-speech':'No speech detected.','network':'A network error occurred.','audio-capture':'No microphone found.'};
        if (this._onErrorCb) this._onErrorCb(msgs[e.error] || 'Speech recognition error: ' + e.error);
      };
      this.recognition.onend = () => { this.isListening = false; };
      try { this.recognition.start(); } catch(e) { this._cleanup(); onError('Could not start microphone: ' + e.message); }
    },

    stop() { clearTimeout(this._silenceTimer); if (this.recognition) { try { this.recognition.stop(); } catch(e) {} } this.isListening = false; },
    _cleanup() { clearTimeout(this._silenceTimer); this.isListening = false; this._accumulated = ''; }
  };
  mfsdSTT.init();

  // ============================================================================
  let questions = [], idx = 0, stack = [];

  const el = (t, c, txt) => {
    const n = document.createElement(t);
    if (c) n.className = c;
    if (txt !== undefined) n.textContent = txt;
    return n;
  };

  async function checkWeekStatus() {
    try {
      const res = await fetch(cfg.restUrlStatus + "?week=" + encodeURIComponent(week), {
        method: 'GET', headers: { 'X-WP-Nonce': cfg.nonce || '', 'Accept': 'application/json' }, credentials: 'same-origin'
      });
      if (res.ok) {
        const data = await res.json();
        // Store pending red plans so resumeFromLastQuestion can check them
        cfg._pendingRedPlans = data.pending_red_plans || [];
        return data;
      }
    } catch (err) { console.error('Status check error:', err); }
    return { status: 'not_started', can_start: true };
  }

  async function renderIntro() {
    const status = await checkWeekStatus();

    if (!status.can_start && status.blocking_week) {
      const wrap = el("div","rag-wrap"); const card = el("div","rag-card");
      card.appendChild(el("h2","rag-title","Week " + week + " — Not Available"));
      card.appendChild(el("p","rag-error-msg","Please complete Week " + status.blocking_week + " before starting Week " + week + "."));
      const backBtn = el("button","rag-btn","Back"); backBtn.onclick = () => window.history.back(); card.appendChild(backBtn);
      wrap.appendChild(card); root.replaceChildren(wrap); return;
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
      const ps = status.previous_week_summary;
      const box = el("div"); box.style.cssText = "background:#f0f8ff;border-left:4px solid #4a90e2;padding:12px 14px;border-radius:6px;margin:12px 0;";
      const title = el("div"); title.style.cssText = "font-weight:600;margin-bottom:6px;color:#2c3e50;"; title.textContent = "Last Week (Week " + ps.week + ") Results:"; box.appendChild(title);
      const stats = el("div"); stats.style.cssText = "display:flex;gap:12px;margin:8px 0;flex-wrap:wrap;";
      const gs = el("div","stat"); gs.style.cssText = "background:#d4edda;border:1px solid #c3e6cb;border-radius:6px;padding:6px 10px;font-size:14px;"; gs.textContent = "🟢 Greens: " + ps.greens; stats.appendChild(gs);
      const as = el("div","stat"); as.style.cssText = "background:#fff3cd;border:1px solid #ffeaa7;border-radius:6px;padding:6px 10px;font-size:14px;"; as.textContent = "🟠 Ambers: " + ps.ambers; stats.appendChild(as);
      const rs = el("div","stat"); rs.style.cssText = "background:#f8d7da;border:1px solid #f5c6cb;border-radius:6px;padding:6px 10px;font-size:14px;"; rs.textContent = "🔴 Reds: " + ps.reds; stats.appendChild(rs);
      if (ps.mbti_type) { const ms = el("div","stat"); ms.style.cssText = "background:#e8f4fd;border:1px solid #b8daff;border-radius:6px;padding:6px 10px;font-size:14px;font-weight:600;"; ms.textContent = "MBTI: " + ps.mbti_type; stats.appendChild(ms); }
      box.appendChild(stats); card.appendChild(box);
    }

    if (status.intro_message) {
      const ib = el("div"); ib.style.cssText = "background:#fff8e6;border:1px solid #ffd966;border-left:4px solid #f0ad4e;padding:12px 14px;border-radius:6px;line-height:1.6;margin:12px 0;font-size:14px;color:#333;"; ib.textContent = status.intro_message; card.appendChild(ib);
    } else {
      card.appendChild(el("p","rag-sub","High Performance Pathway RAG + MBTI Weekly Tracker.\nGreens = strengths ; Ambers = mixed ; Reds = needs support.\n"));
    }

    const btn = el("button","rag-btn","Begin RAG");
    btn.onclick = async () => { await loadQuestions(); idx = 0; stack = []; await renderQuestion(); };
    card.appendChild(btn); wrap.appendChild(card); root.replaceChildren(wrap);
  }

  async function resumeFromLastQuestion(lastQuestionId, answeredIds) {
    // First check if any previously-answered Red question is missing its plan
    const pendingPlans = cfg._pendingRedPlans || [];
    if (pendingPlans.length > 0) {
      for (let i = 0; i < questions.length; i++) {
        if (pendingPlans.includes(parseInt(questions[i].id))) {
          idx = i;
          await renderRedFollowup(questions[i], i);
          return;
        }
      }
    }
    // Otherwise find first unanswered question
    let firstUnansweredIdx = -1;
    for (let i = 0; i < questions.length; i++) {
      if (!answeredIds.includes(parseInt(questions[i].id))) { firstUnansweredIdx = i; break; }
    }
    if (firstUnansweredIdx >= 0) { idx = firstUnansweredIdx; stack = []; await renderQuestion(); }
    else { await renderSummary(); }
  }

  async function loadQuestions() {
    try {
      const res = await fetch(cfg.restUrlQuestions + "?week=" + encodeURIComponent(week), {
        method: 'GET', headers: { 'X-WP-Nonce': cfg.nonce || '', 'Accept': 'application/json' }, credentials: 'same-origin'
      });
      if (!res.ok) throw new Error("HTTP " + res.status);
      const data = await res.json();
      if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Failed');
      questions = data.questions || [];
    } catch (err) { console.error('Error loading questions', err); alert('Loading questions failed: ' + err.message); throw err; }
  }

  async function renderQuestion() {
    showQuestionLoading();
    const q = questions[idx];
    const wrap = el("div","rag-wrap"); const card = el("div","rag-card");
    card.appendChild(el("div","rag-pos","Question " + (idx+1) + " of " + questions.length));
    card.appendChild(el("div","rag-qtext", q.q_text));

    // DISC
    if (q.q_type === 'DISC') {
      const sc = el("div","disc-scale-container"); sc.style.cssText = "margin:20px 0;";
      const sl = el("div","disc-scale-label"); sl.style.cssText = "text-align:center;margin-bottom:12px;font-weight:600;color:#555;"; sl.textContent = "How much do you agree with this statement?"; sc.appendChild(sl);
      const lights = el("div","rag-lights"); lights.style.cssText = "display:flex;gap:8px;justify-content:center;flex-wrap:wrap;";
      const opts = [{label:"Completely Disagree",value:1,color:"#d9534f",emoji:"👎"},{label:"Somewhat Disagree",value:2,color:"#f0ad4e",emoji:"🤔"},{label:"Neutral",value:3,color:"#9e9e9e",emoji:"😐"},{label:"Somewhat Agree",value:4,color:"#5cb85c",emoji:"👍"},{label:"Completely Agree",value:5,color:"#4caf50",emoji:"💯"}];
      opts.forEach(opt => {
        const btn = el("button","rag-light disc-scale-btn"); btn.style.cssText = `background:${opt.color};color:white;border:none;border-radius:10px;padding:16px 12px;cursor:pointer;font-weight:600;font-size:13px;min-width:90px;transition:all 0.2s;text-align:center;display:flex;flex-direction:column;align-items:center;gap:6px;`;
        const em = el("span",""); em.style.cssText = "font-size:24px;"; em.textContent = opt.emoji; btn.appendChild(em);
        const lb = el("span",""); lb.style.cssText = "font-size:12px;line-height:1.3;"; lb.textContent = opt.label; btn.appendChild(lb);
        btn.onmouseover = () => { btn.style.transform="translateY(-3px)"; btn.style.boxShadow="0 4px 12px rgba(0,0,0,0.2)"; };
        btn.onmouseout  = () => { btn.style.transform="translateY(0)"; btn.style.boxShadow="none"; };
        btn.onclick = async () => {
          showQuestionLoading('Saving your answer...');
          try {
            let mapping = q.disc_mapping; if (typeof mapping==='string'){try{mapping=JSON.parse(mapping);}catch(e){hideQuestionLoading();alert('Invalid DISC data.');return;}}
            if (!mapping||!mapping.hasOwnProperty('D')){hideQuestionLoading();alert('DISC mapping missing.');return;}
            const c = opt.value - 3;
            const res = await fetch(cfg.restUrlAnswer,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''},credentials:'same-origin',body:JSON.stringify({week,question_id:q.id,q_type:'DISC',disc_answer:opt.value,d_contribution:mapping.D*c,i_contribution:mapping.I*c,s_contribution:mapping.S*c,c_contribution:mapping.C*c})});
            if (!res.ok) throw new Error('Failed'); const data=await res.json(); if(!data.ok)throw new Error(data.error||'Failed');
            hideQuestionLoading(); idx++; if(idx<questions.length)await renderQuestion();else await renderSummary();
          } catch(err){hideQuestionLoading();alert('Error: '+err.message);}
        };
        lights.appendChild(btn);
      });
      sc.appendChild(lights); card.appendChild(sc); wrap.appendChild(card); root.replaceChildren(wrap); hideQuestionLoading(); return;
    }

    // Previous answers
    if (week > 1) {
      try {
        const pr = await fetch(cfg.restUrlPrevious+"?week="+week+"&question_id="+q.id,{method:'GET',headers:{'X-WP-Nonce':cfg.nonce||'','Accept':'application/json'},credentials:'same-origin'});
        if (pr.ok) { const pd=await pr.json(); if(pd.ok&&pd.previous&&pd.previous.length>0){const pDiv=el("div","rag-prev");let pt="Previous answers: ";pd.previous.forEach(p=>{pt+="Week "+p.week_num+": "+(p.answer==='R'?'🔴':p.answer==='A'?'🟠':'🟢')+" ";});pDiv.textContent=pt;card.appendChild(pDiv);} }
      } catch(err){}
    }

    // AI guidance with SteveGPT branding
    const aiGuidanceDiv = el("div","rag-ai-question");
    aiGuidanceDiv.innerHTML = '<em>Loading question guidance...</em>';
    card.appendChild(aiGuidanceDiv);
    try {
      const gr = await fetch(cfg.restUrlGuidance,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''},credentials:'same-origin',body:JSON.stringify({week,question_id:q.id})});
      if (gr.ok) {
        const gd = await gr.json();
        if (gd.ok && gd.guidance) {
          aiGuidanceDiv.innerHTML = '';
          // SteveGPT label
          const steveLabel = el("div");
          steveLabel.style.cssText = "font-size:12px;font-weight:500;color:#856404;margin-bottom:6px;";
          steveLabel.textContent = "SteveGPT";
          aiGuidanceDiv.appendChild(steveLabel);
          const guidanceText = document.createElement('span');
          aiGuidanceDiv.appendChild(guidanceText);
          if (mfsdTTS.supported) { aiGuidanceDiv.appendChild(mfsdTTS.makeControls(gd.guidance)); mfsdTTS.speakWithReveal(gd.guidance, guidanceText); }
          else { guidanceText.textContent = gd.guidance; }
        } else { aiGuidanceDiv.innerHTML = '<em>Question guidance unavailable.</em>'; }
      }
    } catch(err) { aiGuidanceDiv.innerHTML = '<em>Question guidance unavailable.</em>'; }

    // Chatbot
    const chatWrap = el("div","rag-chatwrap");
    const chatHistory = el("div","rag-chat-history"); chatHistory.style.cssText = "max-height:420px;overflow-y:auto;margin-bottom:12px;padding:10px;background:#f5f5f5;border-radius:6px;scroll-behavior:smooth;";
    const initMsg = el("div","rag-chat-msg ai-msg"); initMsg.style.cssText = "margin-bottom:10px;padding:8px 12px;background:#e3f2fd;border-radius:8px;border-left:3px solid #2196f3;";
    const initText = "Hi! How can I help you with this question?"; initMsg.textContent = initText;
    if (mfsdTTS.supported) initMsg.appendChild(mfsdTTS.makeControls(initText));
    chatHistory.appendChild(initMsg); chatWrap.appendChild(chatHistory);

    const inputContainer = el("div"); inputContainer.style.cssText = "display:flex;gap:8px;align-items:flex-end;";
    const chatInput = document.createElement("textarea"); chatInput.rows=2; chatInput.placeholder="Ask about this question..."; chatInput.style.cssText="flex:1;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;resize:none;font-family:inherit;line-height:1.4;";
    const sendBtn = el("button","rag-btn","Send"); sendBtn.style.cssText="padding:10px 20px;white-space:nowrap;";
    let conversationMode = false;

    function startListening() {
      if (!mfsdSTT.supported||!micBtn) return;
      chatInput.value=""; chatInput.placeholder="Listening…"; micBtn.classList.add("mfsd-mic-active"); micBtn.title="Tap to end conversation"; micBtn.innerHTML="🎤";
      mfsdSTT.listen(
        (t)=>{chatInput.value=t;},
        (t)=>{chatInput.value=t;sendMessage();},
        (msg)=>{chatInput.placeholder="Ask about this question...";const e=el("div","rag-chat-msg error-msg");e.style.cssText="margin-bottom:10px;padding:8px 12px;background:#ffebee;border-radius:8px;border-left:3px solid #f44336;font-size:13px;";e.textContent="🎤 "+msg;chatHistory.appendChild(e);chatHistory.scrollTop=chatHistory.scrollHeight;if(conversationMode)setTimeout(()=>startListening(),800);}
      );
    }

    function stopConversation() {
      conversationMode=false; mfsdSTT.stop();
      if (micBtn){micBtn.classList.remove("mfsd-mic-active");micBtn.title="Start voice conversation";micBtn.innerHTML="🎤";}
      chatInput.placeholder="Ask about this question..."; chatInput.value="";
    }

    let micBtn = null;
    if (mfsdSTT.supported) {
      micBtn=document.createElement("button"); micBtn.type="button"; micBtn.className="mfsd-mic-btn"; micBtn.title="Start voice conversation"; micBtn.innerHTML="🎤";
      micBtn.addEventListener("click",()=>{ if(conversationMode){stopConversation();}else{conversationMode=true;startListening();} });
    }

    const sendMessage = async () => {
      const userMsg=chatInput.value.trim(); if(!userMsg)return;
      const ume=el("div","rag-chat-msg user-msg"); ume.style.cssText="margin-bottom:10px;padding:8px 12px;background:#fff;border-radius:8px;border-left:3px solid #666;text-align:left;"; ume.textContent=userMsg;
      chatHistory.appendChild(ume); chatInput.value=""; chatInput.placeholder=conversationMode?"Waiting for AI reply…":"Ask about this question..."; sendBtn.disabled=true; sendBtn.textContent="Sending..."; chatHistory.scrollTop=chatHistory.scrollHeight;
      try {
        const resp=await fetch(cfg.restUrlQuestionChat,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''},credentials:'same-origin',body:JSON.stringify({week,question_id:q.id,message:userMsg})});
        if (resp.ok){const data=await resp.json();if(data.ok&&data.response){
          const aie=el("div","rag-chat-msg ai-msg"); aie.style.cssText="margin-bottom:10px;padding:8px 12px;background:#e3f2fd;border-radius:8px;border-left:3px solid #2196f3;";
          const ait=document.createElement('span'); aie.appendChild(ait); chatHistory.appendChild(aie); chatHistory.scrollTop=chatHistory.scrollHeight;
          if(mfsdTTS.supported){aie.appendChild(mfsdTTS.makeControls(data.response));if(mfsdTTS.enabled){if(convMode==='polite'){mfsdTTS.speakWithReveal(data.response,ait,()=>{if(conversationMode)startListening();});}else{mfsdTTS.speakWithReveal(data.response,ait);if(conversationMode)startListening();}}else{ait.textContent=data.response;if(conversationMode)setTimeout(()=>{if(conversationMode)startListening();},500);}}
          else{ait.textContent=data.response;if(conversationMode)setTimeout(()=>{if(conversationMode)startListening();},500);}
        }}else throw new Error('Failed');
      } catch(err){const em=el("div","rag-chat-msg error-msg");em.style.cssText="margin-bottom:10px;padding:8px 12px;background:#ffebee;border-radius:8px;border-left:3px solid #f44336;";em.textContent="Sorry, couldn't process your message.";chatHistory.appendChild(em);if(conversationMode)setTimeout(()=>{if(conversationMode)startListening();},800);}
      finally{sendBtn.disabled=false;sendBtn.textContent="Send";if(!conversationMode)chatInput.focus();}
    };

    sendBtn.onclick = sendMessage;
    chatInput.onkeydown=(e)=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMessage();}};
    chatInput.addEventListener('input',()=>{if(mfsdSTT.isListening){mfsdSTT.stop();chatInput.placeholder="Ask about this question...";}});
    inputContainer.appendChild(chatInput); if(micBtn)inputContainer.appendChild(micBtn); inputContainer.appendChild(sendBtn);
    chatWrap.appendChild(inputContainer); card.appendChild(chatWrap);

    // RAG buttons
    const lights = el("div","rag-lights");
    [{key:'R',cls:'red',label:'Red'},{key:'A',cls:'amber',label:'Amber'},{key:'G',cls:'green',label:'Green'}].forEach(c=>{
      const b=el("button","rag-light "+c.cls,c.label); b.onclick=()=>{stopConversation();saveAnswer(q,c.key,b);}; lights.appendChild(b);
    });
    card.appendChild(lights);

    const actions=el("div","rag-actions"); const back=el("button","rag-btn secondary","Back"); back.disabled=(stack.length===0);
    back.onclick=()=>{if(!stack.length)return;stack.pop();idx=Math.max(0,idx-1);renderQuestion();};
    actions.appendChild(back); card.appendChild(actions);
    wrap.appendChild(card); root.replaceChildren(wrap); hideQuestionLoading();
  }

  function showQuestionLoading(msg) { const o=el("div","rag-loading-overlay");const s=el("div","rag-spinner");const t=el("div","rag-loading-text",msg||"Saving answer...");o.appendChild(s);o.appendChild(t);document.body.appendChild(o); }
  function hideQuestionLoading() { const o=document.querySelector(".rag-loading-overlay");if(o)o.remove(); }

  async function saveAnswer(q, answer, buttonElement) {
    document.querySelectorAll('.rag-light,.rag-btn').forEach(b=>{b.disabled=true;b.style.opacity='0.5';b.style.cursor='not-allowed';});
    showQuestionLoading();
    try {
      const res=await fetch(cfg.restUrlAnswer,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":cfg.nonce||''},credentials:'same-origin',body:JSON.stringify({week,question_id:q.id,rag:answer})});
      const raw=await res.text(); let j=null;
      try{j=raw?JSON.parse(raw):null;}catch(e){hideQuestionLoading();alert("Server returned non-JSON: "+raw.slice(0,200));return;}
      if(!res.ok||!j||!j.ok){hideQuestionLoading();alert("Save failed: "+((j&&j.error)||res.status+" "+res.statusText));return;}
      stack.push({q,answer}); hideQuestionLoading();
      // Red on RAG → show improvement plan first
      if (answer==='R'&&q.q_type==='RAG') { await renderRedFollowup(q,idx); }
      else if (idx<questions.length-1) { idx++; await renderQuestion(); }
      else { await renderSummary(); }
    } catch(err){hideQuestionLoading();alert('Failed to save answer: '+err.message);}
  }

  function showLoadingOverlay(){const o=el("div","rag-loading-overlay");const s=el("div","rag-spinner");const t=el("div","rag-loading-text","Preparing Summary Results...");o.appendChild(s);o.appendChild(t);document.body.appendChild(o);}
  function hideLoadingOverlay(){const o=document.querySelector(".rag-loading-overlay");if(o)o.remove();}

  async function renderSummary() {
    showLoadingOverlay();
    try {
      const sr=await fetch(cfg.restUrlSummary,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''},credentials:'same-origin',body:JSON.stringify({week})});
      const sraw=await sr.text(); let sd=null;
      try{sd=sraw?JSON.parse(sraw):null;}catch(e){hideLoadingOverlay();alert("Summary non-JSON: "+sraw.slice(0,200));return;}
      if(!sd||!sd.ok){hideLoadingOverlay();alert("Summary failed: "+((sd&&sd.error)||sraw.slice(0,200)));return;}
      const awr=await fetch(cfg.restUrlAllWeeks+"?_="+Date.now(),{method:'GET',headers:{'X-WP-Nonce':cfg.nonce||'','Accept':'application/json'},credentials:'same-origin'});
      let awd=null; if(awr.ok)awd=await awr.json();
      hideLoadingOverlay();
      const wrap=el("div","rag-wrap"); const card=el("div","rag-card"); const weekNum=sd.week||week;
      card.appendChild(el("h2","rag-title","Week "+weekNum+" Summary"));

      if (awd&&awd.ok&&awd.weeks){
        const tabs=el("div","rag-week-tabs"); let mx=0; for(let w=1;w<=6;w++){if(awd.weeks[w]&&awd.weeks[w].completed)mx=w;}
        for(let w=1;w<=mx;w++){if(awd.weeks[w]&&awd.weeks[w].completed){const t=el("button","rag-week-tab"+(w===weekNum?" active":""),"Week "+w);t.setAttribute('data-week',w);t.onclick=async function(){week=w;await renderSummary();};tabs.appendChild(t);}}
        card.appendChild(tabs);
      }
      const cc=el("div","rag-chart-container"); cc.id="chart-display"; card.appendChild(cc);

      if (sd.disc_type&&sd.disc_scores){
        const ds=el("div","rag-disc-section"); ds.style.cssText="margin:20px 0;padding:20px;background:#f8f9fa;border-radius:8px;";
        const dt=el("div","rag-disc-title"); dt.style.cssText="font-size:18px;font-weight:600;margin-bottom:16px;text-align:center;"; dt.textContent="DISC Personality Style: "+sd.disc_type; ds.appendChild(dt);
        const dcw=el("div","disc-content-wrapper"); dcw.style.cssText="display:flex;gap:20px;align-items:center;flex-wrap:wrap;justify-content:center;";
        const pp=el("div","disc-plot-wrapper"); pp.style.cssText="flex:0 0 auto;"; const plt=createDISCPolarPlot(sd.disc_scores); if(plt)pp.appendChild(plt); dcw.appendChild(pp);
        const bd=el("div","disc-breakdown"); bd.style.cssText="display:flex;flex-direction:column;gap:8px;min-width:120px;";
        const dc={'D':'#2d5f8d','I':'#f9b234','S':'#c67a3c','C':'#3b5998'};
        ['D','I','S','C'].forEach(l=>{const sc=sd.disc_scores[l];const row=el("div","disc-score-row");row.style.cssText="display:flex;align-items:center;gap:8px;";const cb=el("div","disc-color-box");cb.style.cssText=`width:20px;height:20px;background:${dc[l]};border-radius:3px;flex-shrink:0;`;const lp=el("div","disc-label-pct");lp.style.cssText="font-weight:600;font-size:14px;color:#333;";lp.textContent=`${l}: ${Math.round(sc.percent)}%`;row.appendChild(cb);row.appendChild(lp);bd.appendChild(row);});
        dcw.appendChild(bd); ds.appendChild(dcw); card.appendChild(ds);
      }

      if (sd.ai){
        const asd=el("div","rag-ai");
        if(mfsdTTS.supported){const tb=document.createElement('div');tb.className='mfsd-tts-summary-bar';tb.innerHTML='<span style="font-size:13px;color:#666;font-style:italic;">AI Summary</span>';tb.appendChild(mfsdTTS.makeControls(sd.ai));card.appendChild(tb);}
        card.appendChild(asd);
        if(mfsdTTS.supported){setTimeout(()=>mfsdTTS.speakWithReveal(sd.ai,asd),400);}else{asd.textContent=sd.ai;}
      }

      const ag=el("button","rag-btn","Back to intro"); ag.onclick=()=>window.location.reload(); card.appendChild(ag);
      wrap.appendChild(card); root.replaceChildren(wrap);

      setTimeout(()=>{
        if(awd&&awd.ok){showWeekChart(weekNum,awd.weeks);}
        else{const c=document.getElementById('chart-display');if(c){const st=el("div","rag-stats");st.appendChild(el("div","stat","Reds: "+sd.rag.reds));st.appendChild(el("div","stat","Ambers: "+sd.rag.ambers));st.appendChild(el("div","stat","Greens: "+sd.rag.greens));st.appendChild(el("div","stat","Score: "+sd.rag.total_score));c.appendChild(st);const cv=document.createElement('canvas');cv.width=400;cv.height=400;c.appendChild(cv);setTimeout(()=>drawPieChart(cv,parseInt(sd.rag.reds),parseInt(sd.rag.ambers),parseInt(sd.rag.greens)),50);}}
      },10);
    } catch(err){hideLoadingOverlay();alert('Failed to load summary: '+err.message);}
  }

  function showWeekChart(weekNum,weeksData){const c=document.getElementById('chart-display');if(!c)return;const wd=weeksData[weekNum];if(!wd||!wd.completed)return;c.innerHTML='';c.appendChild(el("h3","rag-week-chart-title","Week "+weekNum+" Results"));const st=el("div","rag-stats");st.appendChild(el("div","stat","Reds: "+wd.rag.reds));st.appendChild(el("div","stat","Ambers: "+wd.rag.ambers));st.appendChild(el("div","stat","Greens: "+wd.rag.greens));st.appendChild(el("div","stat","Score: "+wd.rag.total_score));c.appendChild(st);const cv=document.createElement('canvas');cv.width=400;cv.height=400;c.appendChild(cv);setTimeout(()=>drawPieChart(cv,parseInt(wd.rag.reds),parseInt(wd.rag.ambers),parseInt(wd.rag.greens)),50);if(wd.mbti)c.appendChild(el("div","rag-mbti-week","MBTI: "+wd.mbti));}

  function drawPieChart(canvas,reds,ambers,greens){const ctx=canvas.getContext('2d');const total=reds+ambers+greens;if(total===0)return;const cx=canvas.width/2,cy=(canvas.height-60)/2,r=Math.min(cx,cy)-20;const ra=(reds/total)*2*Math.PI,aa=(ambers/total)*2*Math.PI,ga=(greens/total)*2*Math.PI;let ca=-Math.PI/2;ctx.clearRect(0,0,canvas.width,canvas.height);if(reds>0){ctx.fillStyle='#d9534f';ctx.beginPath();ctx.moveTo(cx,cy);ctx.arc(cx,cy,r,ca,ca+ra);ctx.closePath();ctx.fill();ca+=ra;}if(ambers>0){ctx.fillStyle='#f0ad4e';ctx.beginPath();ctx.moveTo(cx,cy);ctx.arc(cx,cy,r,ca,ca+aa);ctx.closePath();ctx.fill();ca+=aa;}if(greens>0){ctx.fillStyle='#5cb85c';ctx.beginPath();ctx.moveTo(cx,cy);ctx.arc(cx,cy,r,ca,ca+ga);ctx.closePath();ctx.fill();}const ly=canvas.height-50,lx=30;ctx.font='bold 14px Arial';ctx.fillStyle='#d9534f';ctx.fillRect(lx,ly,20,20);ctx.fillStyle='#000';ctx.fillText('Red: '+Math.round((reds/total)*100)+'%',lx+25,ly+15);ctx.fillStyle='#f0ad4e';ctx.fillRect(lx+120,ly,20,20);ctx.fillStyle='#000';ctx.fillText('Amber: '+Math.round((ambers/total)*100)+'%',lx+145,ly+15);ctx.fillStyle='#5cb85c';ctx.fillRect(lx+260,ly,20,20);ctx.fillStyle='#000';ctx.fillText('Green: '+Math.round((greens/total)*100)+'%',lx+285,ly+15);}

  // ============================================================================
  // RED FOLLOW-UP SCREEN
  // ============================================================================
  async function renderRedFollowup(q, savedIdx) {
    showQuestionLoading('Loading your action plan...');
    let sd = null;
    try {
      const res=await fetch(cfg.restUrlRedSuggestions,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''},credentials:'same-origin',body:JSON.stringify({week,question_id:q.id})});
      if(res.ok) sd=await res.json();
    } catch(err){console.error('Red suggestions error:',err);}
    hideQuestionLoading();

    const steveIntro  = sd?.steve_intro  || "Thanks for being honest — let's make a plan!";
    const suggestions = sd?.suggestions  || [];
    const prevPlans   = sd?.prev_plans   || [];
    const wordTarget  = sd?.word_target  || 50;

    const wrap=el("div","rag-wrap"); const card=el("div","rag-card");

    // Context header
    const hdr=el("div"); hdr.style.cssText="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;gap:12px;";
    const qi=el("div"); qi.appendChild(el("div","rag-pos","Question "+(savedIdx+1)+" of "+questions.length+" — "+q.q_text));
    const rb=el("div"); rb.style.cssText="display:inline-flex;align-items:center;gap:6px;background:#FCEBEB;color:#A32D2D;font-size:12px;font-weight:500;padding:4px 10px;border-radius:6px;border:0.5px solid #F7C1C1;white-space:nowrap;flex-shrink:0;"; rb.innerHTML='<span style="width:8px;height:8px;border-radius:50%;background:#E24B4A;display:inline-block;"></span> You answered Red';
    hdr.appendChild(qi); hdr.appendChild(rb); card.appendChild(hdr);

    // Previous plan
    if (prevPlans.length > 0) {
      const lp=prevPlans[0]; const pb=el("div"); pb.style.cssText="background:#fff8e6;border:0.5px solid #ffd966;border-left:3px solid #f0ad4e;border-radius:0 6px 6px 0;padding:12px 14px;margin-bottom:14px;";
      const pt=el("div"); pt.style.cssText="font-size:12px;font-weight:500;color:#856404;margin-bottom:5px;"; pt.textContent="Your plan from Week "+lp.week_num;
      const px=el("div"); px.style.cssText="font-size:13px;color:#333;line-height:1.6;"; px.textContent=lp.plan_text;
      pb.appendChild(pt); pb.appendChild(px); card.appendChild(pb);
    }

    // SteveGPT section
    const ss=el("div","rag-card"); ss.style.cssText="background:#E6F1FB;border:0.5px solid #B5D4F4;padding:16px;margin-bottom:0;";
    const sn=el("div"); sn.style.cssText="font-size:12px;font-weight:500;color:#185FA5;margin-bottom:6px;"; sn.textContent="SteveGPT"; ss.appendChild(sn);
    const st=el("div"); st.style.cssText="font-size:14px;color:#1d2327;line-height:1.6;"; ss.appendChild(st);
    if (mfsdTTS.supported) {
      const sp=document.createElement('span'); st.appendChild(sp);
      ss.appendChild(mfsdTTS.makeControls(steveIntro));
      // Read intro, then suggestions in sequence
      mfsdTTS.speakWithReveal(steveIntro, sp, () => {
        if (!suggestions.length) return;
        let i = 0;
        const speakNext = () => {
          if (i >= suggestions.length) return;
          const text = 'Idea ' + (i + 1) + '. ' + suggestions[i]; i++;
          mfsdTTS.speak(text, speakNext);
        };
        speakNext();
      });
    } else { st.textContent=steveIntro; }

    // Suggestions
    if (suggestions.length > 0) {
      const sl=el("div"); sl.style.cssText="font-size:12px;font-weight:500;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin:14px 0 8px;"; sl.textContent="Some ideas to get you started"; ss.appendChild(sl);
      suggestions.forEach((sug,i)=>{
        const sr=el("div"); sr.style.cssText="display:flex;gap:8px;align-items:flex-start;padding:9px 10px;border-radius:6px;border:0.5px solid #ddd;margin-bottom:6px;background:#fff;cursor:pointer;";
        const nm=el("div"); nm.style.cssText="font-size:12px;font-weight:500;color:#999;min-width:16px;margin-top:2px;flex-shrink:0;"; nm.textContent=(i+1)+".";
        const tx=el("div"); tx.style.cssText="font-size:13px;color:#333;line-height:1.5;"; tx.textContent=sug;
        const hn=el("div"); hn.style.cssText="font-size:11px;color:#aaa;margin-top:3px;"; hn.textContent="Tap to copy into your plan";
        const inn=el("div"); inn.appendChild(tx); inn.appendChild(hn);
        sr.appendChild(nm); sr.appendChild(inn);
        sr.addEventListener('click',()=>{planTextarea.value=(planTextarea.value.trim()?planTextarea.value.trim()+' ':'')+sug;updateWordCount();planTextarea.focus();hn.textContent="Copied!";setTimeout(()=>{hn.textContent="Tap to copy into your plan";},2000);});
        ss.appendChild(sr);
      });
    }
    card.appendChild(ss);

    // Chat
    const chatSec=el("div","rag-chatwrap"); chatSec.style.marginTop="14px";
    const chatLbl=el("div"); chatLbl.style.cssText="font-size:12px;font-weight:500;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin-bottom:8px;"; chatLbl.textContent="Ask SteveGPT for more ideas";
    const chatHist=el("div","rag-chat-history"); chatHist.style.cssText="max-height:260px;overflow-y:auto;margin-bottom:10px;padding:10px;background:#f5f5f5;border-radius:6px;scroll-behavior:smooth;";
    chatSec.appendChild(chatLbl); chatSec.appendChild(chatHist);
    const cir=el("div"); cir.style.cssText="display:flex;gap:8px;align-items:flex-end;";
    const ci=document.createElement("textarea"); ci.rows=1; ci.placeholder="Ask a follow-up question…"; ci.style.cssText="flex:1;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;resize:none;font-family:inherit;line-height:1.4;";
    const csb=el("button","rag-btn","Send"); csb.style.cssText="padding:9px 16px;white-space:nowrap;";
    let cmb=null, ccm=false;
    const scl=()=>{if(!mfsdSTT.supported||!cmb)return;ci.value="";ci.placeholder="Listening…";cmb.classList.add("mfsd-mic-active");mfsdSTT.listen((t)=>{ci.value=t;},(t)=>{ci.value=t;scm();},(m)=>{ci.placeholder="Ask a follow-up question…";if(ccm)setTimeout(()=>scl(),800);});};
    if(mfsdSTT.supported){cmb=document.createElement("button");cmb.type="button";cmb.className="mfsd-mic-btn";cmb.title="Speak your question";cmb.innerHTML="🎤";cmb.addEventListener("click",()=>{ccm=!ccm;if(ccm)scl();else{mfsdSTT.stop();cmb.classList.remove("mfsd-mic-active");ci.placeholder="Ask a follow-up question…";}});}
    const scm=async()=>{const msg=ci.value.trim();if(!msg)return;const ue=el("div","rag-chat-msg user-msg");ue.style.cssText="margin-bottom:8px;padding:8px 12px;background:#fff;border-radius:8px;border-left:3px solid #666;text-align:left;font-size:13px;";ue.textContent=msg;chatHist.appendChild(ue);ci.value="";ci.placeholder="Waiting…";csb.disabled=true;chatHist.scrollTop=chatHist.scrollHeight;
      try{const r=await fetch(cfg.restUrlQuestionChat,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''},credentials:'same-origin',body:JSON.stringify({week,question_id:q.id,message:msg,is_red_followup:true})});if(r.ok){const d=await r.json();if(d.ok&&d.response){const ae=el("div","rag-chat-msg ai-msg");ae.style.cssText="margin-bottom:8px;padding:8px 12px;background:#e3f2fd;border-radius:8px;border-left:3px solid #2196f3;font-size:13px;";const as=document.createElement('span');ae.appendChild(as);chatHist.appendChild(ae);chatHist.scrollTop=chatHist.scrollHeight;if(mfsdTTS.supported){ae.appendChild(mfsdTTS.makeControls(d.response));mfsdTTS.speakWithReveal(d.response,as,()=>{if(ccm)scl();});}else{as.textContent=d.response;if(ccm)setTimeout(()=>scl(),500);}}}
      }catch(err){console.error(err);}finally{csb.disabled=false;ci.placeholder="Ask a follow-up question…";}};
    csb.onclick=scm; ci.onkeydown=(e)=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();scm();}};
    cir.appendChild(ci); if(cmb)cir.appendChild(cmb); cir.appendChild(csb); chatSec.appendChild(cir); card.appendChild(chatSec);

    // Plan section
    const hr=el("hr"); hr.style.cssText="border:none;border-top:0.5px solid #e5e5e5;margin:18px 0;"; card.appendChild(hr);
    const ph=el("div"); ph.style.cssText="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;";
    const ptitle=el("div"); ptitle.style.cssText="font-size:15px;font-weight:500;color:#1d2327;"; ptitle.textContent="Your plan to move from Red to Amber";
    const wcd=el("div"); wcd.style.cssText="text-align:right;font-size:12px;color:#666;line-height:1.5;"; wcd.innerHTML=`Target: <strong style="font-weight:500;">${wordTarget} words</strong><br><span id="rf-word-count" style="color:#185FA5;font-weight:500;">0 / ${wordTarget}</span>`;
    ph.appendChild(ptitle); ph.appendChild(wcd); card.appendChild(ph);
    const an=el("div"); an.style.cssText="font-size:12px;color:#666;background:#f8f8f8;border-radius:6px;padding:8px 12px;margin-bottom:12px;border:0.5px solid #e5e5e5;"; an.textContent=`Write your plan below — aim for ${wordTarget} words. Type it, speak it, or copy from the suggestions above.`; card.appendChild(an);
    const bw=el("div"); bw.style.cssText="height:4px;background:#e5e5e5;border-radius:2px;margin-bottom:14px;"; const bf=el("div"); bf.style.cssText="height:100%;width:0%;background:#378ADD;border-radius:2px;transition:width 0.2s;"; bw.appendChild(bf); card.appendChild(bw);
    const planTextarea=document.createElement("textarea"); planTextarea.rows=4; planTextarea.placeholder="Write your plan here — what will you do this week to improve?"; planTextarea.style.cssText="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit;line-height:1.6;resize:vertical;"; card.appendChild(planTextarea);
    const updateWordCount=()=>{const w=planTextarea.value.trim().split(/\s+/).filter(Boolean).length;const p=Math.min(100,Math.round((w/wordTarget)*100));bf.style.width=p+'%';bf.style.background=w>=wordTarget?'#5cb85c':'#378ADD';const d=document.getElementById('rf-word-count');if(d){d.textContent=w+' / '+wordTarget;d.style.color=w>=wordTarget?'#3b6d11':'#185FA5';}saveBtn.disabled=w<wordTarget;};
    planTextarea.addEventListener('input',updateWordCount);
    const pir=el("div"); pir.style.cssText="display:flex;gap:8px;align-items:center;margin-top:10px;";
    if(mfsdSTT.supported){const pmb=document.createElement("button");pmb.type="button";pmb.className="mfsd-mic-btn";pmb.title="Speak your plan";pmb.innerHTML="🎤";let pma=false;pmb.addEventListener("click",()=>{pma=!pma;if(pma){pmb.classList.add("mfsd-mic-active");mfsdSTT.listen((t)=>{planTextarea.value=t;updateWordCount();},(t)=>{planTextarea.value=t;updateWordCount();pma=false;pmb.classList.remove("mfsd-mic-active");},()=>{pma=false;pmb.classList.remove("mfsd-mic-active");});}else{mfsdSTT.stop();pmb.classList.remove("mfsd-mic-active");}});pir.appendChild(pmb);}
    const mh=el("div"); mh.style.cssText="font-size:12px;color:#aaa;"; mh.textContent="Tap mic to speak your plan"; pir.appendChild(mh); card.appendChild(pir);
    const saveBtn=el("button","rag-btn","Save my plan and continue"); saveBtn.style.cssText="width:100%;margin-top:14px;padding:12px;"; saveBtn.disabled=true;
    saveBtn.onclick=async()=>{const pt=planTextarea.value.trim();const w=pt.split(/\s+/).filter(Boolean).length;if(w<wordTarget)return;saveBtn.disabled=true;saveBtn.textContent="Saving…";
      try{await fetch(cfg.restUrlSaveRedPlan,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''},credentials:'same-origin',body:JSON.stringify({week,question_id:q.id,plan_text:pt})});}catch(err){console.error(err);}
      mfsdTTS.stop();mfsdSTT.stop();
      if(savedIdx<questions.length-1){idx=savedIdx+1;await renderQuestion();}else{await renderSummary();}
    };
    card.appendChild(saveBtn); updateWordCount();
    wrap.appendChild(card); root.replaceChildren(wrap);
  }

  renderIntro();
})();

// ============================================================================
// DISC POLAR PLOT
// ============================================================================
function createDISCPolarPlot(scores) {
  const canvas=document.createElement('canvas'); canvas.width=500; canvas.height=500;
  const ctx=canvas.getContext('2d'); const cx=250,cy=250,mr=180;
  ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,500,500);
  const colors={'D':'#2d5f8d','I':'#f9b234','S':'#c67a3c','C':'#3b5998'};
  const segs=[{key:'D',sa:0,ea:Math.PI/2,label:'Dominant',traits:['Direct','Decisive','Doer']},{key:'I',sa:Math.PI/2,ea:Math.PI,label:'Influential',traits:['Inspirational','Interactive','Interesting']},{key:'S',sa:Math.PI,ea:3*Math.PI/2,label:'Steady',traits:['Stable','Supportive','Sincere']},{key:'C',sa:3*Math.PI/2,ea:2*Math.PI,label:'Compliant',traits:['Cautious','Careful','Conscientious']}];
  ctx.strokeStyle='#e0e0e0'; ctx.lineWidth=1; for(let i=1;i<=4;i++){ctx.beginPath();ctx.arc(cx,cy,(mr/4)*i,0,2*Math.PI);ctx.stroke();}
  ctx.strokeStyle='#c0c0c0'; ctx.lineWidth=2; ctx.setLineDash([5,5]);
  ctx.beginPath();ctx.moveTo(cx,cy-mr);ctx.lineTo(cx,cy+mr);ctx.stroke();
  ctx.beginPath();ctx.moveTo(cx-mr,cy);ctx.lineTo(cx+mr,cy);ctx.stroke();
  ctx.setLineDash([]);
  segs.forEach(s=>{const pct=(scores[s.key]&&scores[s.key].percent)||0;const fr=(pct/100)*mr;ctx.fillStyle=colors[s.key];ctx.globalAlpha=0.6;ctx.beginPath();ctx.moveTo(cx,cy);ctx.arc(cx,cy,fr,s.sa,s.ea);ctx.closePath();ctx.fill();ctx.globalAlpha=1.0;ctx.strokeStyle=colors[s.key];ctx.lineWidth=2;ctx.beginPath();ctx.moveTo(cx,cy);ctx.arc(cx,cy,mr,s.sa,s.ea);ctx.closePath();ctx.stroke();});
  ctx.font='bold 14px Arial'; ctx.fillStyle='#666'; ctx.textAlign='center';
  ctx.fillText('Active',cx,cy-mr-15); ctx.fillText('Reflective',cx,cy+mr+25);
  ctx.save();ctx.translate(cx-mr-25,cy);ctx.rotate(-Math.PI/2);ctx.fillText('People Focus',0,0);ctx.restore();
  ctx.save();ctx.translate(cx+mr+25,cy);ctx.rotate(Math.PI/2);ctx.fillText('Task Focus',0,0);ctx.restore();
  segs.forEach(s=>{const pct=(scores[s.key]&&scores[s.key].percent)||0;const mid=(s.sa+s.ea)/2;ctx.font='bold 48px Arial';ctx.fillStyle='#333';ctx.fillText(s.key,cx+(mr*0.4)*Math.cos(mid),cy+(mr*0.4)*Math.sin(mid)+15);ctx.font='bold 16px Arial';ctx.fillStyle='#333';const lx=cx+(mr+50)*Math.cos(mid),ly=cy+(mr+50)*Math.sin(mid);ctx.fillText(s.label,lx,ly);ctx.font='11px Arial';ctx.fillStyle='#666';s.traits.forEach((t,i)=>ctx.fillText(t,lx,ly+18+(i*14)));ctx.font='bold 16px Arial';ctx.fillStyle=colors[s.key];ctx.fillText(Math.round(pct)+'%',cx+(mr*0.65)*Math.cos(mid),cy+(mr*0.65)*Math.sin(mid)+5);});
  return canvas;
}