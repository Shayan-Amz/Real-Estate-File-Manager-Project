/* Predeclared scoring rules; no model inference, answer repair, or fabricated observations. */
(function(root) {
    'use strict';
    const fields = {
        usage: ['کاربری', 'text'], dealType: ['واگذاری', 'text'], city: ['شهر', 'text'], location: ['محله', 'text'],
        area: ['متراژ', 'number'], buildArea: ['زیربنا', 'number'], rooms: ['خواب', 'number'], floor: ['طبقه', 'number'],
        unit: ['واحد', 'number'], yearBuilt: ['سال ساخت', 'number'], price: ['قیمت فروش (تومان)', 'number'],
        deposit: ['رهن (تومان)', 'number'], rent: ['اجاره (تومان)', 'number'],
        hasParking: ['پارکینگ', 'boolean'], hasElevator: ['آسانسور', 'boolean'], hasStorage: ['انباری', 'boolean']
    };
    function digits(value) {
        return String(value).replace(/[۰-۹٠-٩]/g, char => String('۰۱۲۳۴۵۶۷۸۹'.includes(char) ? '۰۱۲۳۴۵۶۷۸۹'.indexOf(char) : '٠١٢٣٤٥٦٧٨٩'.indexOf(char)));
    }
    function normalize(value) {
        return digits(String(value ?? '').normalize('NFKC')).toLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک')
            .replace(/ة/g, 'ه').replace(/[\u200c\u200d]/g, ' ').replace(/[\u064b-\u065f\u0670\u0640]/g, '')
            .replace(/[^\p{L}\p{N}\s]/gu, ' ').replace(/\s+/g, ' ').trim();
    }
    function wordError(reference, hypothesis) {
        const r = normalize(reference).split(' ').filter(Boolean), h = normalize(hypothesis).split(' ').filter(Boolean);
        if (!r.length || h.length > 2000) return null;
        let previous = Array.from({length: h.length + 1}, (_, i) => ({cost: i, s: 0, d: 0, i}));
        for (let row = 1; row <= r.length; row++) {
            const current = [{cost: row, s: 0, d: row, i: 0}];
            for (let col = 1; col <= h.length; col++) {
                if (r[row - 1] === h[col - 1]) { current[col] = {...previous[col - 1]}; continue; }
                const sub = previous[col - 1], del = previous[col], ins = current[col - 1];
                const choices = [
                    {cost: sub.cost + 1, s: sub.s + 1, d: sub.d, i: sub.i},
                    {cost: del.cost + 1, s: del.s, d: del.d + 1, i: del.i},
                    {cost: ins.cost + 1, s: ins.s, d: ins.d, i: ins.i + 1}
                ];
                current[col] = choices.reduce((best, item) => item.cost < best.cost ? item : best);
            }
            previous = current;
        }
        const score = previous[h.length];
        return {...score, reference_words: r.length, hypothesis_words: h.length, wer: score.cost / r.length};
    }
    function missing(value) { return value === null || value === undefined || (typeof value === 'string' && value.trim() === ''); }
    function canonical(value, kind) {
        if (missing(value)) return null;
        if (kind === 'number') {
            if (typeof value !== 'string' && typeof value !== 'number') return NaN;
            const text = digits(value).replace(/[,٬\s]/g, '');
            return /^[+-]?\d+(?:\.\d+)?$/.test(text) ? Number(text) : NaN;
        }
        if (kind === 'boolean') return typeof value === 'boolean' ? value : null;
        return typeof value === 'string' ? normalize(value) : null;
    }
    function fieldScore(testCase, prediction) {
        const params = prediction && typeof prediction === 'object' && !Array.isArray(prediction) ? prediction : {};
        let tp = 0, fp = 0, fn = 0, typeErrors = 0;
        const mismatches = [], extras = [];
        for (const [key, [,kind]] of Object.entries(fields)) {
            const value = params[key], hasGold = Object.prototype.hasOwnProperty.call(testCase.gold, key);
            if (!missing(value)) {
                const expectedType = kind === 'text' ? 'string' : kind;
                if (typeof value !== expectedType || (kind === 'number' && !Number.isFinite(value))) typeErrors++;
            }
            if (hasGold) {
                const expected = canonical(testCase.gold[key], kind), actual = canonical(value, kind);
                const aliases = testCase.aliases?.[key] || [testCase.gold[key]];
                const correct = !missing(value) && (kind === 'text' ? aliases.some(alias => canonical(alias, kind) === actual) : actual === expected);
                if (correct) tp++;
                else { fn++; if (!missing(value)) fp++; mismatches.push({field: key, expected: testCase.gold[key], actual: value ?? null}); }
            } else {
                // Prompt defaults and zero-valued inactive price fields are not assertions.
                const isDefault = (kind === 'boolean' && value === false) || (['price','deposit','rent'].includes(key) && canonical(value, kind) === 0);
                if (!missing(value) && !isDefault) { fp++; extras.push({field: key, actual: value}); }
            }
        }
        const precision = tp + fp ? tp / (tp + fp) : 0, recall = tp + fn ? tp / (tp + fn) : 0;
        return {tp, fp, fn, expected_fields: Object.keys(testCase.gold).length, accuracy: recall, precision, recall,
            f1: precision + recall ? 2 * precision * recall / (precision + recall) : 0,
            exact_match: fn === 0 && fp === 0, type_errors: typeErrors, mismatches, extras};
    }
    function percentile(values, q) {
        const sorted = values.filter(Number.isFinite).slice().sort((a,b) => a-b);
        if (!sorted.length) return null;
        const at = (sorted.length - 1) * q, lower = Math.floor(at), upper = Math.ceil(at);
        return sorted[lower] + (sorted[upper] - sorted[lower]) * (at - lower);
    }
    function stats(values) {
        values = values.filter(Number.isFinite);
        return {n: values.length, mean: values.length ? values.reduce((a,b) => a+b,0) / values.length : null,
            p50: percentile(values,.5), p95: percentile(values,.95), min: values.length ? Math.min(...values) : null, max: values.length ? Math.max(...values) : null};
    }
    function summarize(runs) {
        const groups = {};
        for (const run of runs) {
            const asrModel = run.mode === 'voice' ? (run.asr?.metadata?.asr_model || run.metadata?.asr_model || '') : '';
            const llmModel = run.mode === 'manual' ? '' : (run.llm?.result?.model_requested || run.llm?.metadata?.llm_model || run.metadata?.llm_model || '');
            const key = [run.mode, run.condition, asrModel, llmModel].join('|');
            (groups[key] ||= []).push(run);
        }
        return Object.fromEntries(Object.entries(groups).map(([key, group]) => {
            const scored = group.filter(r => r.raw_field_score), words = group.filter(r => r.wer);
            const tp = scored.reduce((a,r)=>a+r.raw_field_score.tp,0), fp = scored.reduce((a,r)=>a+r.raw_field_score.fp,0), fn = scored.reduce((a,r)=>a+r.raw_field_score.fn,0);
            const edits = words.reduce((a,r)=>a+r.wer.cost,0), count = words.reduce((a,r)=>a+r.wer.reference_words,0);
            return [key, {started: group.length, completed: group.filter(r=>r.status==='completed').length,
                failed: group.filter(r=>r.status==='failed').length, cancelled: group.filter(r=>r.status==='cancelled').length,
                asr_scored: words.length, wer_micro: count ? edits/count : null, extract_scored: scored.length,
                fields_micro: {tp,fp,fn,precision:tp+fp?tp/(tp+fp):null,recall:tp+fn?tp/(tp+fn):null,f1:2*tp+fp+fn?2*tp/(2*tp+fp+fn):null},
                raw_exact_success_all_runs: group[0]?.mode !== 'manual' && group.length ? group.filter(r=>r.raw_field_score?.exact_match).length/group.length : null,
                final_correct_all_runs: group.length ? group.filter(r=>r.final_field_score?.exact_match).length/group.length : null,
                asr_service_ms_all: stats(group.map(r=>r.asr?.server_asr_ms)), asr_service_ms_success: stats(group.filter(r=>r.asr?.ok).map(r=>r.asr.server_asr_ms)),
                llm_service_ms_all: stats(group.map(r=>r.llm?.result?.server_ms)), llm_service_ms_success: stats(group.filter(r=>r.llm?.ok).map(r=>r.llm.result.server_ms)),
                client_pipeline_ms_all: stats(group.map(r=>r.client_pipeline_ms)), client_pipeline_ms_success: stats(group.filter(r=>r.llm?.ok).map(r=>r.client_pipeline_ms)),
                workflow_ms_all: stats(group.map(r=>r.workflow_ms)), workflow_ms_completed: stats(group.filter(r=>r.status==='completed').map(r=>r.workflow_ms)),
                review_ms: stats(group.map(r=>r.review_ms)), rtf_decoded: stats(group.filter(r=>r.rtf_is_estimate===false).map(r=>r.rtf)), rtf_estimated: stats(group.filter(r=>r.rtf_is_estimate===true).map(r=>r.rtf))}];
        }));
    }
    const api = {fields, digits, normalize, wordError, canonical, fieldScore, stats, summarize};
    if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.ThesisMetrics = api;
})(typeof window !== 'undefined' ? window : globalThis);
