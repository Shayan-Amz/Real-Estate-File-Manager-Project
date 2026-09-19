(() => {
    'use strict';
    const M = window.ThesisMetrics, $ = id => document.getElementById(id);
    const storageKey = 'amlak-thesis-observations-v1';
    const uid = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2,'0')).join('');
    let runs = [], dataset = null, metadata = null, token = '', active = null, recorder = null, stream = null, mediaTimer = null, version = 0, requestController = null;
    let experimentId = uid();
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
        if (saved?.schema === storageKey && Array.isArray(saved.runs)) {
            runs = saved.runs; experimentId = saved.experiment_id || experimentId;
            runs.forEach(run => { if (!['completed','failed','cancelled'].includes(run.status)) { run.status = 'cancelled'; run.error = 'صفحه پیش از اتمام آزمون بسته شده است.'; } });
        }
    } catch (_) { /* Corrupt storage must not stop the measurement interface. */ }
    function message(text = '', error = false) { $('message').textContent = text; $('message').classList.toggle('error', error); }
    function persist() {
        try { localStorage.setItem(storageKey, JSON.stringify({schema:storageKey, experiment_id:experimentId, runs})); }
        catch (_) { message('ذخیرهٔ مرورگر پر یا غیرفعال است؛ پیش از بستن صفحه خروجی را دانلود کنید.', true); }
        renderRuns();
    }
    const ms = value => Number.isFinite(value) ? new Intl.NumberFormat('fa-IR', {maximumFractionDigits:0}).format(value) + ' میلی‌ثانیه' : '—';
    function renderRuns() {
        $('results-body').replaceChildren();
        for (const run of runs) {
            const row = document.createElement('tr');
            const cells = [run.case_id, {text:'متنی',voice:'صوتی',manual:'دستی'}[run.mode],
                {running:'در حال اجرا',recording:'ضبط',review:'بازبینی',completed:'تکمیل',failed:'ناموفق',cancelled:'لغوشده'}[run.status] || run.status,
                ms(run.asr?.server_asr_ms), ms(run.llm?.result?.server_ms),
                run.raw_field_score ? new Intl.NumberFormat('fa-IR',{style:'percent',maximumFractionDigits:1}).format(run.raw_field_score.accuracy) : '—'];
            for (const value of cells) { const cell = document.createElement('td'); cell.textContent = String(value); row.appendChild(cell); }
            $('results-body').appendChild(row);
        }
        $('progress').textContent = `${runs.length.toLocaleString('fa-IR')} اجرای ثبت‌شده؛ شکست‌ها و لغوها نیز در فایل خروجی می‌مانند.`;
        $('download').disabled = runs.length === 0;
        $('raw-output').textContent = runs.length ? JSON.stringify(runs[runs.length-1], null, 2) : '';
    }
    function selectedCase() { return dataset.cases.find(c => c.id === $('case-select').value); }
    function refreshCase() {
        const c = selectedCase(); if (!c) return;
        $('case-title').textContent = c.id + ' · ' + c.title; $('reference').textContent = c.reference;
        const voice = $('mode').value === 'voice';
        $('condition').disabled = !voice || !!active; $('audio-file').disabled = !voice || !!active;
        $('use-file').disabled = !voice || !!active;
    }
    function busy(value) {
        for (const id of ['participant','case-select','mode','start']) $(id).disabled = value;
        $('cancel').hidden = !value; refreshCase();
    }
    function stopTracks() {
        if (mediaTimer) clearTimeout(mediaTimer); mediaTimer = null;
        if (stream) stream.getTracks().forEach(track => track.stop()); stream = null;
        $('stop').hidden = true;
    }
    async function api(action, payload, isFile = false) {
        const controller = new AbortController(); requestController = controller;
        const timer = setTimeout(() => controller.abort(), 190000);
        const headers = {}, requestToken = token;
        if (requestToken) headers['X-Evaluation-Token'] = requestToken; // Keep Apache Basic Auth independent.
        if (!isFile) headers['Content-Type'] = 'application/json';
        try {
            const response = await fetch('api.php?action=' + encodeURIComponent(action), {
                method:'POST', credentials:'same-origin', cache:'no-store', headers,
                body:isFile ? payload : JSON.stringify(payload || {}), signal:controller.signal
            });
            let body;
            try { body = await response.json(); }
            catch (_) { throw new Error('پاسخ سرور JSON نیست؛ مسیر نصب یا خطای وب‌سرور را بررسی کنید.'); }
            if (!response.ok || !body.ok) {
                const error = new Error(body.error || 'آزمون ناموفق بود.'); error.response = body; error.http = response.status;
                if (response.status === 401 && token === requestToken) { token = ''; $('login-panel').hidden = false; }
                throw error;
            }
            return body;
        } finally { clearTimeout(timer); if (requestController === controller) requestController = null; }
    }
    async function loadCases() {
        const response = await fetch('cases.json', {cache:'no-store'}); if (!response.ok) throw new Error('فایل سناریوها بارگذاری نشد.');
        dataset = await response.json();
        if (!Array.isArray(dataset.cases) || !dataset.cases.length) throw new Error('سناریوها معتبر نیستند.');
        $('case-select').replaceChildren();
        for (const c of dataset.cases) { const option=document.createElement('option'); option.value=c.id; option.textContent=c.id+' · '+c.title; $('case-select').appendChild(option); }
        refreshCase();
    }
    $('login-form').addEventListener('submit', async event => {
        event.preventDefault(); message(); $('login-button').disabled = true;
        if (location.protocol !== 'https:') { message('برای ورود و ضبط صدا، این ابزار را با HTTPS باز کنید.',true); $('login-button').disabled=false; return; }
        const password = $('master-password').value; $('master-password').value = '';
        try {
            const response = await api('login', {password,consent:$('consent').checked}); token=response.token;
            const status=await api('status',{}); metadata=status.metadata;
            if (!dataset) await loadCases();
            $('asr-model').textContent=metadata.asr_provider+' / '+metadata.asr_model; $('llm-model').textContent=metadata.llm_model;
            $('login-panel').hidden=true; $('workspace').hidden=false; renderRuns();
            message(metadata.curl_available && metadata.asr_configured && metadata.llm_configured ? 'آماده است. سناریو و روش اجرا را انتخاب کنید؛ هر بار فقط یک آزمون.' : 'بخشی از تنظیمات سرویس‌ها کامل نیست؛ اجرای ناموفق نیز ثبت می‌شود. مشخصات تنظیمات را بررسی کنید.', !metadata.curl_available || !metadata.asr_configured || !metadata.llm_configured);
        } catch(error) { message(error.message,true); }
        finally { $('login-button').disabled=false; }
    });
    function newRun(mode) {
        if (active || !dataset || !token) throw new Error('ابتدا وارد ابزار شوید و آزمون جاری را پایان دهید.');
        const participant=$('participant').value.trim(); if (!/^P[0-9]{2,4}$/.test(participant)) throw new Error('شناسهٔ ناشناس مانند P01 انتخاب کنید؛ نام واقعی ننویسید.');
        const c=selectedCase();
        const run={id:uid(),experiment_id:experimentId,case_id:c.id,case_snapshot:JSON.parse(JSON.stringify(c)),dataset_version:dataset.version,
            participant,mode,condition:mode==='voice'?$('condition').value:'not-applicable',
            started_utc:new Date().toISOString(),client_timezone:Intl.DateTimeFormat().resolvedOptions().timeZone,
            user_agent:navigator.userAgent.slice(0,200),metadata:{...metadata},status:'running',error:null};
        active={run,started:performance.now(),generation:++version}; runs.push(run); busy(true); persist();
        $('review').hidden=true; $('stage').textContent='آزمون آغاز شد.'; message(); return active;
    }
    const stillCurrent = context => active === context && context.generation === version;
    function finishFailure(context, error, phase) {
        if (!stillCurrent(context)) return;
        const run=context.run;
        if (phase==='asr' && error.response) run.asr=error.response;
        if (phase==='llm' && error.response) run.llm=error.response;
        run.status='failed'; run.failed_at=phase; run.error=error.message; run.error_http=error.http ?? null;
        run.ended_utc=new Date().toISOString(); run.workflow_ms=performance.now()-context.started;
        active=null; stopTracks(); busy(false); $('review').hidden=true; $('stage').textContent='این اجرا ناموفق ثبت شد؛ از خروجی حذف نمی‌شود.';
        message(error.message+' اگر خطای سهمیه دیدید، پی‌درپی تکرار نکنید و خروجی فعلی را بفرستید.',true); persist();
    }
    async function extract(context, text, pipelineStart) {
        if (!stillCurrent(context)) return;
        $('stage').textContent='در حال استخراج مشخصات با مدل تنظیم‌شده…';
        const started=performance.now();
        try {
            const response=await api('extract',{text}); if (!stillCurrent(context)) return;
            const run=context.run; run.llm=response; run.client_extract_ms=performance.now()-started;
            run.client_pipeline_ms=performance.now()-pipelineStart; run.extraction_input=text;
            run.raw_field_score=M.fieldScore(run.case_snapshot,response.result.params);
            review(context,response.result.params);
        } catch(error) { if (stillCurrent(context)) context.run.client_pipeline_ms=performance.now()-pipelineStart; finishFailure(context,error,'llm'); }
    }
    async function measuredDuration(blob) {
        const Audio = window.AudioContext || window.webkitAudioContext;
        if (!Audio) return null;
        const audio=new Audio();
        try { const buffer=await audio.decodeAudioData(await blob.arrayBuffer()); return Number.isFinite(buffer.duration)&&buffer.duration>0 ? buffer.duration : null; }
        catch (_) { return null; }
        finally { await audio.close().catch(()=>{}); }
    }
    async function sendAudio(context, blob, name, wallSeconds = null) {
        let phase='prepare-audio';
        try {
            if (!stillCurrent(context)) return;
            const max=metadata.asr_provider==='hf' ? 2*1024*1024 : 8*1024*1024;
            if (!blob.size || blob.size>max) throw new Error('صدا خالی یا بزرگ‌تر از سقف سرویس است؛ یک کلیپ کوتاه بفرستید.');
            const run=context.run;
            const hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await blob.arrayBuffer())), b=>b.toString(16).padStart(2,'0')).join('');
            const decoded=await measuredDuration(blob); if (!stillCurrent(context)) return;
            run.audio_sha256_client=hash; run.audio_bytes=blob.size; run.audio_mime=blob.type;
            run.audio_duration_s=decoded ?? wallSeconds; run.audio_duration_source=decoded!==null?'browser-decoded':(wallSeconds!==null?'recorder-wall-clock-estimate':'unavailable');
            run.reused_audio=runs.some(other=>other.id!==run.id && other.audio_sha256_client===hash);
            const audioURL=URL.createObjectURL(blob); if ($('playback').dataset.url) URL.revokeObjectURL($('playback').dataset.url);
            $('playback').dataset.url=audioURL; $('playback').src=audioURL; $('playback').hidden=false;
            phase='asr'; $('stage').textContent='در حال تبدیل صدا به متن…';
            const form=new FormData(); form.append('audio_file',blob,name);
            const pipelineStart=performance.now(), started=performance.now();
            const response=await api('transcribe',form,true); if (!stillCurrent(context)) return;
            run.asr=response; run.client_asr_ms=performance.now()-started;
            run.transcript=response.result.text; run.wer=M.wordError(run.case_snapshot.reference,run.transcript);
            run.rtf=run.audio_duration_s>0 ? response.server_asr_ms/1000/run.audio_duration_s : null;
            run.rtf_is_estimate=run.audio_duration_source!=='browser-decoded';
            persist(); await extract(context,run.transcript,pipelineStart);
        } catch(error) { finishFailure(context,error,phase); }
    }
    async function record(context) {
        try {
            if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) throw new Error('ضبط در این مرورگر در دسترس نیست؛ فایل صوتی همین متن را انتخاب کنید.');
            const requested=performance.now(); const acquired=await navigator.mediaDevices.getUserMedia({audio:true});
            if (!stillCurrent(context)) { acquired.getTracks().forEach(track=>track.stop()); return; }
            stream=acquired;
            context.run.microphone_permission_ms=performance.now()-requested;
            const settings=acquired.getAudioTracks()[0].getSettings();
            context.run.microphone_settings=Object.fromEntries(['sampleRate','channelCount','echoCancellation','noiseSuppression','autoGainControl'].filter(k=>settings[k]!==undefined).map(k=>[k,settings[k]]));
            const preferred=['audio/webm;codecs=opus','audio/webm','audio/mp4','audio/ogg;codecs=opus'].find(m=>MediaRecorder.isTypeSupported(m));
            recorder=new MediaRecorder(stream,preferred?{mimeType:preferred}:undefined);
            const currentRecorder=recorder,chunks=[]; const begin=performance.now();
            currentRecorder.addEventListener('dataavailable',event=>{if(event.data.size)chunks.push(event.data);});
            currentRecorder.addEventListener('error',()=>finishFailure(context,new Error('خطای ضبط صدا'),'recording'));
            currentRecorder.addEventListener('stop',()=>{
                const duration=(performance.now()-begin)/1000;
                if (!stillCurrent(context)) { acquired.getTracks().forEach(track=>track.stop()); return; }
                stopTracks(); if(recorder===currentRecorder)recorder=null;
                const mime=currentRecorder.mimeType || 'audio/webm';
                const ext=mime.includes('mp4')?'m4a':(mime.includes('ogg')?'ogg':'webm');
                context.run.recording_ms=duration*1000; context.run.status='running'; persist();
                sendAudio(context,new Blob(chunks,{type:mime}),context.run.case_id+'.'+ext,duration);
            },{once:true});
            currentRecorder.start(); context.run.status='recording'; $('stop').hidden=false;
            $('stage').textContent='در حال ضبط؛ فقط متن سناریو را بخوانید و سپس «پایان ضبط» را بزنید. سقف ضبط یک دقیقه است.';
            mediaTimer=setTimeout(()=>{if(currentRecorder.state==='recording')currentRecorder.stop();},60000); persist();
        } catch(error) { finishFailure(context,error,'microphone'); }
    }
    function review(context, params) {
        if (!stillCurrent(context)) return;
        context.reviewStart=performance.now(); context.run.status='review'; $('review-fields').replaceChildren();
        for (const [key,[label,kind]] of Object.entries(M.fields)) {
            const wrapper=document.createElement('label'); wrapper.textContent=label;
            const input=document.createElement(kind==='boolean'?'select':'input'); input.dataset.field=key;
            if(kind==='boolean') {
                for(const [value,title] of [['','نامشخص'],['true','دارد'],['false','ندارد']]) {const option=document.createElement('option');option.value=value;option.textContent=title;input.appendChild(option);}
                input.value=typeof params[key]==='boolean'?String(params[key]):'';
            } else { input.type='text'; input.value=params[key]===null||params[key]===undefined?'':String(params[key]); if(kind==='number'){input.inputMode='decimal';input.dir='ltr';} }
            wrapper.appendChild(input);$('review-fields').appendChild(wrapper);
        }
        $('review').hidden=false; $('stage').textContent=context.run.mode==='manual'?'فرم خالی را از روی متن پر کنید؛ زمان‌سنج فعال است.':'خروجی خام ثبت شد؛ اکنون بازبینی کنید و پایان آزمون را بزنید.'; persist();
    }
    $('start').addEventListener('click',async()=>{
        let context;
        try {context=newRun($('mode').value);if(context.run.mode==='manual')review(context,{hasParking:false,hasElevator:false,hasStorage:false});else if(context.run.mode==='voice')await record(context);else await extract(context,context.run.case_snapshot.reference,performance.now());}
        catch(error){if(context)finishFailure(context,error,'setup');else message(error.message,true);}
    });
    $('stop').addEventListener('click',()=>{if(recorder?.state==='recording'){ $('stop').disabled=true;recorder.stop();$('stop').disabled=false; }});
    $('use-file').addEventListener('click',async()=>{
        const file=$('audio-file').files[0]; if(!file){message('ابتدا فایل صوتی را انتخاب کنید.',true);return;}
        let context;
        try{context=newRun('voice');context.run.audio_source='uploaded-file';const ext=file.name.split('.').pop().toLowerCase();await sendAudio(context,file,context.run.case_id+'.'+ext);}
        catch(error){if(context)finishFailure(context,error,'file');else message(error.message,true);}
    });
    $('review-form').addEventListener('submit',event=>{
        event.preventDefault();if(!active||active.run.status!=='review')return;
        const context=active,params={};
        for(const input of $('review-fields').querySelectorAll('[data-field]')) {
            const key=input.dataset.field,kind=M.fields[key][1],value=input.value.trim();
            if(value==='')params[key]=null;else if(kind==='boolean')params[key]=value==='true';
            else if(kind==='number'){const num=M.canonical(value,'number');params[key]=Number.isFinite(num)?num:value;}else params[key]=value;
        }
        const run=context.run;run.final_params=params;run.final_field_score=M.fieldScore(run.case_snapshot,params);
        run.review_ms=performance.now()-context.reviewStart;run.workflow_ms=performance.now()-context.started;
        run.ended_utc=new Date().toISOString();run.status='completed';active=null;busy(false);$('review').hidden=true;
        $('stage').textContent='این مشاهده ثبت شد. سناریو یا روش بعدی را انتخاب کنید.';persist();
    });
    function cancel(reason='آزمون به انتخاب کاربر لغو شد.') {
        if(!active)return;const context=active;++version;active=null;
        context.run.status='cancelled';context.run.error=reason;context.run.ended_utc=new Date().toISOString();context.run.workflow_ms=performance.now()-context.started;
        if(requestController)requestController.abort();if(recorder?.state==='recording')recorder.stop();stopTracks();recorder=null;
        $('review').hidden=true;busy(false);$('stage').textContent='لغو ثبت شد؛ نتیجه از فایل خروجی حذف نمی‌شود.';persist();
    }
    $('cancel').addEventListener('click',()=>cancel());
    $('logout').addEventListener('click',()=>{cancel('خروج کاربر پیش از پایان آزمون');token='';$('workspace').hidden=true;$('login-panel').hidden=false;message('از ابزار خارج شدید؛ نتایج غیرمحرمانه روی همین مرورگر باقی است.');});
    $('download').addEventListener('click',()=>{
        const output={schema:storageKey,experiment_id:experimentId,exported_utc:new Date().toISOString(),dataset,metadata,
            scoring:{version:'fa-pilot-v1',wer:'NFKC, Arabic letter normalization, digit normalization, punctuation removal, ZWNJ to space. Number words are NOT expanded; lexical/number formatting differences count as errors.',
                field_scope:Object.keys(M.fields),field_rule:'Exact normalized match with predeclared aliases. Wrong value counts FP+FN. Unknown false booleans and zero inactive monetary fields are defaults, not hallucinations. Numeric strings may match semantically but are counted as type errors.',
                aggregation:'All attempts retained; conditional extraction metrics are accompanied by coverage and all-run exact success. Percentiles use linear interpolation (type 7).'},
            limitations:['Pilot data are synthetic and small; participants and conditions are self-reported.','ASR latency includes provider/network/queue, not GPU-only inference.','Wall-clock audio duration gives estimated RTF when decoding is unavailable.','No statistical population generalization or production load-test claim.','Manual entry uses this instrumented compact 16-field form, not the complete production interface.'],runs,summary:M.summarize(runs)};
        const url=URL.createObjectURL(new Blob([JSON.stringify(output,null,2)],{type:'application/json'}));
        const anchor=document.createElement('a');anchor.href=url;anchor.download='amlak-thesis-results-'+new Date().toISOString().replace(/[:.]/g,'-')+'.json';anchor.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    });
    $('case-select').addEventListener('change',refreshCase);$('mode').addEventListener('change',refreshCase);
    window.addEventListener('pagehide',()=>{cancel('صفحه هنگام اجرای آزمون بسته شد.');token='';stopTracks();});
    renderRuns();
})();
