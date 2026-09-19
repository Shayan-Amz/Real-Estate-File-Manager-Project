const test=require('node:test'), assert=require('node:assert/strict');
const M=require('../evaluation/metrics.js');
const cases=require('../evaluation/cases.json').cases;
test('identical normalized Persian text has zero WER',()=>{assert.equal(M.wordError('كتابِ من زيبا است.','کتاب من زیبا است').wer,0);});
test('substitution, deletion and insertion are counted with a reference denominator',()=>{
 const s=M.wordError('ملک خوب است','ملک بد است');assert.equal(s.s,1);assert.equal(s.wer,1/3);
 assert.equal(M.wordError('ملک خوب است','ملک است').d,1);assert.equal(M.wordError('ملک خوب','ملک خیلی خوب').i,1);
});
test('WER is not clamped at 100 percent and empty reference is undefined',()=>{assert.equal(M.wordError('ملک','الف ب ج د').wer,4);assert.equal(M.wordError('',''),null);assert.equal(M.wordError('خانه خوب','').wer,1);});
test('spoken number versus digit spelling remains an explicit lexical difference',()=>{assert.ok(M.wordError('صد و بیست متر','۱۲۰ متر').wer>0);assert.equal(M.normalize('۱۲۰ متر'),M.normalize('١٢٠ متر'));});
test('all predeclared gold cases achieve exact semantic match',()=>{for(const c of cases){const s=M.fieldScore(c,c.gold);assert.equal(s.exact_match,true,c.id);assert.equal(s.f1,1);assert.equal(s.type_errors,0);}});
test('a wrong field value is one false positive and one false negative',()=>{const c={gold:{area:120},aliases:{}};const s=M.fieldScore(c,{area:130});assert.equal(s.tp,0);assert.equal(s.fp,1);assert.equal(s.fn,1);});
test('hallucinated non-default fields lower precision',()=>{const c={gold:{area:120},aliases:{}};const s=M.fieldScore(c,{area:120,city:'شهر ناگفته'});assert.equal(s.precision,.5);assert.equal(s.recall,1);assert.equal(s.exact_match,false);});
test('false booleans are facts, not missing values; unknown defaults are separately defined',()=>{
 assert.equal(M.fieldScore({gold:{hasParking:false}}, {hasParking:false}).tp,1);
 const s=M.fieldScore({gold:{area:120}}, {area:120,hasElevator:false,deposit:0});assert.equal(s.fp,0);
});
test('numeric strings can match after normalization but fail strict type compliance',()=>{const s=M.fieldScore({gold:{price:5000000000}}, {price:'۵٬۰۰۰٬۰۰۰٬۰۰۰'});assert.equal(s.tp,1);assert.equal(s.type_errors,1);});
test('predeclared aliases are accepted without guessing new aliases from results',()=>{const c=cases.find(c=>c.id==='C05');assert.equal(M.fieldScore(c,{...c.gold,location:'سعادت‌آباد'}).fn,0);assert.ok(M.fieldScore(c,{...c.gold,location:'شهرک دیگر'}).fn>0);});
test('empty metric series are null, not invented zero latency',()=>{assert.deepEqual(M.stats([]),{n:0,mean:null,p50:null,p95:null,min:null,max:null});assert.equal(M.stats([100,200,300]).p95,290);});
test('all failed trials remain in task success denominator while metric coverage is explicit',()=>{
 const score=M.fieldScore({gold:{area:120}},{area:120});
 const runs=[{mode:'voice',condition:'quiet',status:'completed',raw_field_score:score,final_field_score:score},{mode:'voice',condition:'quiet',status:'failed'}];
 const group=Object.values(M.summarize(runs))[0];assert.equal(group.started,2);assert.equal(group.failed,1);assert.equal(group.extract_scored,1);assert.equal(group.raw_exact_success_all_runs,.5);assert.equal(group.fields_micro.recall,1);
});
test('RTF estimates are separated from decoded-duration RTF and manual extraction is not mislabeled',()=>{
 const groups=Object.values(M.summarize([{mode:'voice',condition:'quiet',status:'completed',rtf:2,rtf_is_estimate:true},{mode:'voice',condition:'quiet',status:'completed',rtf:1,rtf_is_estimate:false}]));
 assert.equal(groups[0].rtf_estimated.mean,2);assert.equal(groups[0].rtf_decoded.mean,1);
 assert.equal(Object.values(M.summarize([{mode:'manual',status:'completed'}]))[0].raw_exact_success_all_runs,null);
});
