        // fix30: one synchronous, validated draft transaction; no timeout-based field writes.
        let jarvisCommandSequence = 0;
        function jarvisAccountKey() { return userProfile ? JSON.stringify([userProfile.agencyId,userProfile.role,userProfile.token]) : ''; }
        function jarvisActiveView() { return ['list','map','add','demands','add-demand','members','dashboard','profile','guest-search','guest-agencies','guest-properties'].find(id=>{const el=document.getElementById(id+'-view');return el&&!el.classList.contains('hidden');}) || ''; }
        function jarvisDraftFingerprint() {
            const form=document.getElementById('add-form');
            return JSON.stringify({editingId,fields:Array.from(form.querySelectorAll('input,select,textarea')).filter(el=>el.type!=='file').map(el=>[el.id,el.type==='checkbox'?el.checked:el.value]),images:tempImagesBase64});
        }
        function jarvisDraftIsDirty() {
            if (editingId || tempImagesBase64.length) return true;
            return Array.from(document.getElementById('add-form').querySelectorAll('input,select,textarea')).some(el=>{
                if (el.type==='file') return false;
                if (el.type==='checkbox') return el.checked!==el.defaultChecked;
                if (el.type==='hidden') return el.value!==({ 'deal-type':'فروش','geo-lat':'','geo-lng':'' }[el.id] ?? el.defaultValue);
                if (el.tagName==='SELECT') {
                    const initial=Array.from(el.options).find(option=>option.defaultSelected) || el.options[0];
                    return el.value!==(initial?.value || '');
                }
                return el.value!==el.defaultValue;
            });
        }
        function beginJarvisCommand() {
            return {id:++jarvisCommandSequence,account:jarvisAccountKey(),fingerprint:jarvisDraftFingerprint(),view:jarvisActiveView(),
                wasChatOpen:!document.getElementById('jarvis-modal').classList.contains('hidden')};
        }
        function jarvisCommandCurrent(context) { return context.id===jarvisCommandSequence && context.account===jarvisAccountKey(); }
        function jarvisChatMessage(chatBox, text, role='ai', id=null) {
            const bubble=document.createElement('div');bubble.className='jarvis-message '+role;bubble.textContent=String(text || '');
            if(id)bubble.id=id;chatBox.appendChild(bubble);chatBox.scrollTop=chatBox.scrollHeight;return bubble;
        }
        function jarvisReviewNote(result) {
            const notes=[...result.warnings];
            const required={referrer:'نام مالک/معرف',phone:'شماره تماس',city:'شهر',location:'محله',area:'متراژ'};
            for(const [id,label] of Object.entries(required)) if(!document.getElementById(id).value.trim())notes.push(label+' را تکمیل کنید.');
            const deal=document.getElementById('deal-type').value;
            if(deal==='فروش'&&!document.getElementById('price').value)notes.push('قیمت کل از متن مشخص نشد؛ آن را وارد کنید.');
            if(['رهن کامل','رهن و اجاره'].includes(deal)&&!document.getElementById('deposit').value)notes.push('مبلغ رهن را تکمیل کنید.');
            if(deal==='رهن و اجاره'&&!document.getElementById('rent').value)notes.push('مبلغ اجاره را تکمیل کنید (صفر نیز مقدار معتبر است).');
            const box=document.getElementById('jarvis-draft-note');box.replaceChildren();box.hidden=false;
            const heading=document.createElement('strong');heading.textContent='پیش‌نویس جارویس · اصلاح فرم ۳۰';box.appendChild(heading);
            const hint=document.createElement('div');hint.textContent='هنوز هیچ ملکی ذخیره نشده است؛ قیمت‌ها به تومان‌اند. پیش از ثبت همهٔ مقدارها را بررسی کنید.';box.appendChild(hint);
            if(notes.length) { const ul=document.createElement('ul');for(const note of new Set(notes)){const li=document.createElement('li');li.textContent=note;ul.appendChild(li);}box.appendChild(ul); }
        }
        function applyJarvisDraft(result, askBeforeReplace=false) {
            if(!userProfile || !['مدیر','مشاور'].includes(userProfile.role)) { window.showToast('ابتدا وارد حساب خود شوید.');return false; }
            if(!result.meaningful) { window.showToast('مشخصات قابل انتقالی دریافت نشد.');return false; }
            if(askBeforeReplace && jarvisDraftIsDirty() && !window.confirm('فرم فعلی یا فایل در حال ویرایش، مقدار دارد. پیش‌نویس جدید جارویس جایگزین آن شود؟ اطلاعات قبلی در سرور تغییر نمی‌کند.')) return false;
            const v=result.values,form=document.getElementById('add-form');
            // Clear editing identity, pictures and visibility defaults only after explicit consent.
            editingId=null;form.reset();tempImagesBase64=[];window.renderImagePreviews();
            document.getElementById('geo-lat').value='';document.getElementById('geo-lng').value='';
            document.getElementById('form-main-title').innerHTML='<span>➕</span> ثبت ملک جدید';
            document.getElementById('btn-submit').innerText='ثبت ملک';document.getElementById('btn-cancel-edit').classList.add('hidden');
            const tabText=document.getElementById('tab-add-text');if(tabText)tabText.innerText='ثبت ملک';
            document.getElementById('usage').value=v.usage || '';
            window.toggleLandOptions();window.setDealType(v.dealType || '');window.toggleGuestOptions();
            const fields={referrer:'referrer',phone:'phone',phone2:'phone2',city:'city',location:'location',exactAddress:'exact-address',area:'area',
                floor:'floor',unit:'unit',yearBuilt:'year-built',buildArea:'build-area',description:'description',internalNote:'internal-note'};
            for(const [key,id] of Object.entries(fields)) {
                const el=document.getElementById(id);if(v[key]!==null && v[key]!==undefined)el.value=String(v[key]);
                el.dispatchEvent(new Event('input',{bubbles:true}));
            }
            document.getElementById('rooms').value=v.rooms===null?'':v.rooms;
            const checks={hasParking:'has-parking',hasElevator:'has-elevator',hasStorage:'has-storage',canExchange:'can-exchange',canPartner:'can-partner',isPreSale:'can-presale'};
            for(const [key,id] of Object.entries(checks))document.getElementById(id).checked=v[key]===true;
            // Fill every known amount after visibility is settled. No truthy checks: 0 is valid.
            for(const key of ['price','deposit','rent']) {
                const el=document.getElementById(key);el.value=v[key]===null?'':String(v[key]);
                el.dispatchEvent(new Event('input',{bubbles:true}));
            }
            // Never leave a populated model field hidden from the user's review.
            if(v.floor!==null || v.unit!==null)document.getElementById('residential-options').classList.remove('hidden');
            if(v.buildArea!==null)document.getElementById('land-metrics').classList.remove('hidden');
            if(v.rooms!==null)document.getElementById('rooms-group').classList.remove('hidden');
            if(v.yearBuilt!==null)document.getElementById('year-group').classList.remove('hidden');
            if(v.canPartner===true)document.getElementById('land-options').classList.remove('hidden');
            const roomsVisible=!document.getElementById('rooms-group').classList.contains('hidden'), yearVisible=!document.getElementById('year-group').classList.contains('hidden');
            document.getElementById('building-specs').classList.toggle('hidden',!roomsVisible&&!yearVisible);
            document.getElementById('building-specs').classList.toggle('grid-2',roomsVisible&&yearVisible);
            document.getElementById('jarvis-modal').classList.add('hidden'); // Never toggle a closed chat back open.
            window.switchTab('add');jarvisReviewNote(result);
            window.showToast('پیش‌نویس آمادهٔ بازبینی است؛ برای ذخیره باید خودت «ثبت ملک» را بزنی.');
            return true;
        }
        window.callJarvisBrain = async function(payload, chatBox, typingId, context=beginJarvisCommand()) {
            try {
                const resData=await window.apiCall('jarvisProcess',payload);
                document.getElementById(typingId)?.remove();
                if(!jarvisCommandCurrent(context)) { jarvisChatMessage(chatBox,'پاسخ دستور قبلی کنار گذاشته شد تا فرمِ دستور جدید تغییر نکند.');return; }
                const message=jarvisChatMessage(chatBox,'پیش‌نویس؛ هنوز ثبت نشده. '+(typeof resData.ai_message==='string'?resData.ai_message:'خروجی مدل دریافت شد.'));
                if(!resData.params || typeof resData.params!=='object' || Array.isArray(resData.params)) throw new Error('خروجی مشخصات ملک معتبر نیست.');
                const result=JarvisFields.normalize(resData.params);
                if(resData.jarvisBuild!=='fix30-form-r1')result.warnings.push('نسخهٔ جدید پاسخ سرور تأیید نشد؛ فایل‌های API بستهٔ ۳۰ را هم بررسی کنید.');
                if(!result.meaningful) { jarvisChatMessage(chatBox,'مشخصات کافی دریافت نشد؛ نوع ملک، نوع واگذاری و مبلغ را روشن‌تر بنویسید.');return; }
                const changed=context.fingerprint!==jarvisDraftFingerprint() || context.view!==jarvisActiveView()
                    || (context.wasChatOpen && document.getElementById('jarvis-modal').classList.contains('hidden'));
                if(changed || jarvisDraftIsDirty()) {
                    const note=document.createElement('p');note.textContent='برای حفظ فرم فعلی، چیزی خودکار عوض نشد. در صورت تمایل پیش‌نویس جدید را اعمال کنید.';message.appendChild(note);
                    const button=document.createElement('button');button.type='button';button.className='btn-modern';button.style.cssText='margin-top:8px;padding:8px 12px;';button.textContent='اعمال پیش‌نویس جدید';
                    button.addEventListener('click',()=>{
                        if(context.account!==jarvisAccountKey()) { window.showToast('حساب تغییر کرده است؛ دستور را دوباره بفرستید.');return; }
                        if(applyJarvisDraft(result,true)) { ++jarvisCommandSequence;button.disabled=true;button.textContent='پیش‌نویس اعمال شد'; }
                    });message.appendChild(button);chatBox.scrollTop=chatBox.scrollHeight;
                    window.showToast('نتیجهٔ جارویس آماده است؛ برای حفظ تغییراتت، از داخل گفتگو تأیید کن.');
                } else applyJarvisDraft(result);
            } catch(error) {
                document.getElementById(typingId)?.remove();
                jarvisChatMessage(chatBox,'❌ '+(error.message || 'ارتباط با سرور قطع شد'));
            }
        };
