/* fix30: deterministic boundary between model output and the existing property form.
 * No network, DOM, storage or database work. Unsupported/ambiguous values stay null.
 */
(function (root) {
    'use strict';
    const MAX_MONEY = Number.MAX_SAFE_INTEGER;
    const small = {صفر:0,یک:1,یه:1,دو:2,سه:3,چهار:4,پنج:5,شش:6,شیش:6,هفت:7,هشت:8,نه:9,ده:10,یازده:11,دوازده:12,سیزده:13,چهارده:14,پانزده:15,شانزده:16,هفده:17,هجده:18,هیجده:18,نوزده:19,بیست:20,سی:30,چهل:40,پنجاه:50,شصت:60,هفتاد:70,هشتاد:80,نود:90,صد:100,یکصد:100,دویست:200,سیصد:300,چهارصد:400,پانصد:500,پونصد:500,ششصد:600,هفتصد:700,هشتصد:800,نهصد:900,نیم:0.5};
    const ordinal = {اول:1,اولین:1,یکم:1,دوم:2,سوم:3,چهارم:4,پنجم:5,ششم:6,هفتم:7,هشتم:8,نهم:9,دهم:10,یازدهم:11,دوازدهم:12,سیزدهم:13,چهاردهم:14,پانزدهم:15};
    const powers = {هزار:3,میلیون:6,میلیارد:9,تریلیون:12,thousand:3,million:6,billion:9,trillion:12};
    const numericFields = {area:[1,9999999],buildArea:[0,9999999],floor:[-99,99],unit:[0,999],yearBuilt:[1,9999]};
    const labels = {price:'قیمت کل',deposit:'رهن',rent:'اجاره',pricePerMeter:'قیمت هر متر',area:'متراژ',buildArea:'زیربنا',floor:'طبقه',unit:'واحد',yearBuilt:'سال ساخت',rooms:'تعداد خواب',phone:'تلفن',phone2:'تلفن دوم',hasParking:'پارکینگ',hasElevator:'آسانسور',hasStorage:'انباری',canExchange:'معاوضه',canPartner:'مشارکت',isPreSale:'پیش‌فروش',referrer:'نام مالک/معرف',city:'شهر',location:'محله',exactAddress:'آدرس دقیق',description:'توضیحات',internalNote:'یادداشت خصوصی'};
    function digits(value) { return String(value).replace(/[۰-۹٠-٩]/g, ch => String(ch.charCodeAt(0) - (ch <= '٩' ? 1632 : 1776))); }
    function clean(value) { return digits(value).normalize('NFKC').toLowerCase().replace(/ي|ى/g,'ی').replace(/ك/g,'ک').replace(/\u200c|\u200d/g,' ').replace(/[\u064b-\u065f\u0670\u0640\u200e\u200f\u202a-\u202e]/g,'').replace(/\s+/g,' ').trim(); }
    const missing = value => value === null || value === undefined || (typeof value === 'string' && value.trim() === '');
    function atom(token) {
        if (Object.prototype.hasOwnProperty.call(small,token)) return small[token];
        const s = token.replace(/٬/g,',').replace(/٫/g,'.').replace(/−/g,'-');
        const plain = /^[+-]?\d+(?:\.\d+)?(?:e[+-]?\d{1,2})?$/i.test(s);
        const grouped = /^[+-]?\d{1,3}(?:,\d{3})+(?:\.\d+)?$/.test(s);
        if (!plain && !grouped) return null;
        const value = Number(s.replace(/,/g,''));
        return Number.isFinite(value) && Math.abs(value) <= MAX_MONEY ? value : null;
    }
    function group(tokens) {
        if (!tokens.length) return null;
        const decimal = tokens.indexOf('ممیز');
        if (decimal !== -1) {
            if (tokens.lastIndexOf('ممیز') !== decimal) return null;
            const whole=group(tokens.slice(0,decimal)), tail=tokens.slice(decimal+1);
            let fraction;
            if(tail.length && tail.every(t=>atom(t)!==null && Number.isInteger(atom(t)) && atom(t)>=0 && atom(t)<=9)) fraction=tail.map(t=>String(atom(t))).join('');
            else { const n=group(tail); if(n===null || !Number.isInteger(n) || n<0)return null; fraction=String(n); }
            return whole!==null && whole>=0 && Number.isInteger(whole) && fraction.length<=6 ? Number(whole+'.'+fraction) : null;
        }
        let value=0, previous=Infinity, needNumber=true;
        for(const token of tokens) {
            if(token==='و') { if(needNumber)return null;needNumber=true;continue; }
            if(!needNumber)return null;
            const n=atom(token); if(n===null || (previous!==Infinity && (n>=previous || !Number.isInteger(previous))))return null;
            value+=n;previous=n;needNumber=false;
        }
        return needNumber ? null : value;
    }
    // Decimal exponent shifting avoids 5.31 * 1e9 floating-point drift.
    function shift(value,power) { const parts=String(value).split('e'); return Number(parts[0]+'e'+((Number(parts[1])||0)+power)); }
    function numberText(value, allowRemainder = true) {
        if(typeof value==='number')return Number.isFinite(value)?value:null;
        if(typeof value!=='string'||value.length>180)return null;
        let text=clean(value).replace(/٫/g,'.').replace(/،/g,' و ');
        text=text.replace(/(هزار|میلیون|میلیارد|تریلیون|thousand|million|billion|trillion)/g,' $1 ').replace(/\s+/g,' ').trim();
        const tokens=text.split(' ').filter(Boolean); let total=0,buffer=[],lastPower=null,endsScale=false;
        for(const token of tokens) {
            if(Object.prototype.hasOwnProperty.call(powers,token)) {
                const power=powers[token];
                if(!buffer.length && endsScale && power>lastPower) { total=shift(total,power);lastPower+=power;continue; }
                const n=buffer.length?group(buffer):((lastPower===null && power===3)?1:null);
                if(n===null || (lastPower!==null && power>=lastPower))return null;
                total+=shift(n,power);buffer=[];lastPower=power;endsScale=true;
            } else {
                if(token==='و' && endsScale && !buffer.length){endsScale=false;continue;}
                buffer.push(token);endsScale=false;
            }
        }
        if(buffer.length) {
            const tail=group(buffer);if(tail===null)return null;
            if(lastPower!==null) {
                // "یک میلیارد و نیم" is explicit; "یک میلیارد و دویست" is not.
                if(buffer.length===1 && buffer[0]==='نیم')total+=shift(tail,lastPower);
                else if(allowRemainder || lastPower<=3)total+=tail;
                else return null;
            } else total=tail;
        } else if(!endsScale)return null;
        return Number.isFinite(total) ? total : null;
    }
    function money(value) {
        if(missing(value))return null;
        let rial=false, text=value;
        if(typeof value==='string') {
            text=clean(value);
            const units=text.match(/تومان|تومن|ریال|tomans?|rials?|irr/g)||[];
            const types=new Set(units.map(u=>/ریال|rial|irr/.test(u)?'rial':'toman'));
            if(types.size>1)return null;rial=types.has('rial');
            text=text.replace(/تومان|تومن|ریال|tomans?|rials?|irr/g,' ').trim();
        }
        let n=numberText(text,false);if(n===null)return null;if(rial)n=shift(n,-1);
        return Number.isSafeInteger(n)&&n>=0&&n<=MAX_MONEY?n:null;
    }
    function quantity(value,min,max) {
        if(missing(value))return null;
        let text=value;
        if(typeof value==='string') {
            text=clean(value).replace(/متر\s*مربع|مترمربعی|متری|متر|سال\s*ساخت|سال|طبقه|واحد|خواب|sqm|m²|m2/g,' ').trim();
            if(text==='همکف')text='0';
            if(Object.prototype.hasOwnProperty.call(ordinal,text))text=String(ordinal[text]);
            if(text.startsWith('منفی ')) { const n=numberText(text.slice(5));return n!==null&&Number.isInteger(n)&&-n>=min&&-n<=max?-n:null; }
        }
        const n=numberText(text);return Number.isSafeInteger(n)&&n>=min&&n<=max?n:null;
    }
    function boolean(value) {
        if(typeof value==='boolean')return value;
        if(value===1||value===0)return value===1;
        if(typeof value!=='string')return null;
        const s=clean(value);
        if(['true','1','yes','بله','دارد','بلی'].includes(s))return true;
        if(['false','0','no','خیر','ندارد','نه'].includes(s))return false;
        return null;
    }
    function enumValue(value,options) {
        if(typeof value!=='string')return null;
        const key=clean(value).replace(/[\/_-]+/g,' ').replace(/\s+/g,' ').trim();
        for(const [name,aliases] of Object.entries(options)) if([name,...aliases].some(v=>clean(v).replace(/[\/_-]+/g,' ').replace(/\s+/g,' ').trim()===key))return name;
        return null;
    }
    const deals={'فروش':['خرید','خرید و فروش','sale','sell'],'رهن و اجاره':['اجاره','رهن اجاره','رهن/اجاره','رهن واجاره','rent','rental'],'رهن کامل':['رهنکامل','full rent','full-rent']};
    const usages={'مسکونی':['آپارتمان','اپارتمان','خانه','منزل','سوئیت','سوییت','آپارتمانی','apartment','residential'],'ویلایی':['ویلا','ویلائی','خانه باغ','villa'],'تجاری':['مغازه','دکان','commercial','shop'],'اداری':['دفتر','دفتر کار','مطب','office'],'زمین/کلنگی':['زمین','کلنگی','زمین کلنگی','land'],'باغ':['باغچه','garden']};
    function phone(value) {
        if(missing(value)||(!['string','number'].includes(typeof value)))return null;
        let text=digits(value).replace(/[\s()\-]/g,'');
        if(/^(?:\+98|0098)\d{10}$/.test(text))text='0'+text.replace(/^(?:\+98|0098)/,'');
        else if(/^98\d{10}$/.test(text))text='0'+text.slice(2);
        else if(typeof value==='number' && /^\d{10}$/.test(text))text='0'+text;
        return /^0\d{10}$/.test(text)?text:null;
    }
    function normalize(raw) {
        if(!raw || typeof raw!=='object' || Array.isArray(raw))throw new Error('مشخصات ملک در پاسخ مدل معتبر نیست.');
        const values={},warnings=[];
        function take(key,value) { values[key]=value; if(!missing(raw[key]) && value===null)warnings.push((labels[key]||key)+' نامعتبر یا مبهم بود و نیاز به بررسی دارد.'); }
        values.dealType=enumValue(raw.dealType,deals);values.usage=enumValue(raw.usage,usages);
        if(!values.dealType)warnings.push('نوع واگذاری را انتخاب کنید.');
        if(!values.usage)warnings.push('کاربری را انتخاب کنید.');
        for(const [key,[min,max]] of Object.entries(numericFields))take(key,quantity(raw[key],min,max));
        for(const key of ['price','deposit','rent','pricePerMeter'])take(key,money(raw[key]));
        for(const key of ['phone','phone2']) { take(key,phone(raw[key])); if(typeof raw[key]==='number' && values[key] && String(raw[key]).length===10) warnings.push('صفر ابتدایی '+labels[key]+' که مدل عددی فرستاده بود برگردانده شد؛ شماره را بررسی کنید.'); }
        for(const key of ['referrer','city','location','exactAddress','description','internalNote']) {
            const v=raw[key];take(key,typeof v==='string'&&v.trim()&&v.length<=8000?v.trim():null);
        }
        for(const key of ['hasParking','hasElevator','hasStorage','canExchange','canPartner','isPreSale'])take(key,boolean(raw[key]));
        let rooms=null;
        if(typeof raw.rooms==='string'&&/^(بدون\s*خواب(?:\s*\/\s*سوئیت)?|سوئیت|سوییت|استودیو|studio)$/.test(clean(raw.rooms)))rooms='بدون خواب/سوئیت';
        else if(typeof raw.rooms==='string'&&/^(?:5\+|5\s*(?:به\s*بالا|خواب به بالا))$/.test(clean(raw.rooms)))rooms='5+';
        else {const n=quantity(raw.rooms,0,99);if(n!==null)rooms=n===0?'بدون خواب/سوئیت':n>=5?'5+':String(n);}
        take('rooms',rooms);
        if(values.price===null && values.pricePerMeter!==null && values.area>0 && values.dealType==='فروش') {
            const total=values.pricePerMeter*values.area;
            if(Number.isSafeInteger(total) && total<=MAX_MONEY) {values.price=total;warnings.push('قیمت کل از قیمت هر متر × متراژ محاسبه شد؛ آن را تأیید کنید.');}
            else warnings.push('قیمت کل محاسبه‌شده از محدودهٔ عددی مجاز بیشتر است.');
        }
        if(values.pricePerMeter!==null && (values.dealType!=='فروش'||!values.area))warnings.push('برای محاسبهٔ قیمت متری، نوع فروش و متراژ معتبر لازم است.');
        if((values.dealType==='فروش' && (values.deposit>0||values.rent>0)) || (values.dealType==='رهن کامل' && values.rent>0) || (['رهن کامل','رهن و اجاره'].includes(values.dealType) && values.price>0)) {
            values.dealType=null;warnings.push('نوع واگذاری و مبلغ‌ها ناسازگارند؛ نوع واگذاری را دوباره انتخاب کنید.');
        }
        const meaningful=Object.entries(values).some(([k,v])=>!missing(v) && (typeof v!=='boolean'||v===true));
        return {values,warnings:[...new Set(warnings)],meaningful};
    }
    const api={digits,clean,money,quantity,boolean,phone,normalize};
    if(typeof module!=='undefined'&&module.exports)module.exports=api;else root.JarvisFields=api;
})(typeof window!=='undefined'?window:globalThis);
