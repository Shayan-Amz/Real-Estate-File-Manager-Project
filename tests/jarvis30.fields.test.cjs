const test=require('node:test'),assert=require('node:assert/strict');
const F=require('../jarvis/fields30.js');
const amounts=[
 ['۵۰۰۰۰۰۰۰۰۰',5000000000],['٥٠٠٬٠٠٠٬٠٠٠',500000000],['5,000,000,000',5000000000],
 ['۵ میلیارد تومان',5000000000],['۵٫۲ میلیارد',5200000000],['5.31 billion',5310000000],
 ['پنج میلیارد و دویست میلیون تومان',5200000000],['یک و نیم میلیارد',1500000000],
 ['یک میلیارد و نیم',1500000000],['سه ممیز پنج میلیارد',3500000000],['نیم میلیون',500000],
 ['۵۰ میلیارد ریال',5000000000],['5e9',5000000000],['1.2e7',12000000],['۱۲ میلیون تومان',12000000],
 ['هزار و پانصد تومان',1500],[0,0],['۰',0]
];
for(const [input,expected] of amounts)test('exact money: '+input,()=>assert.equal(F.money(input),expected));
for(const input of ['توافقی','پنج تا شش میلیارد','پنج میلیارد و دویست','5,5 میلیارد','5/2 میلیارد','5 میلیون ریال تومان','100 USD','-5 میلیارد','1.5',Infinity,{},true,'1e99','میلیارد','میلیون تومان'])test('ambiguous/unsafe money rejected: '+String(input),()=>assert.equal(F.money(input),null));
test('non-ASCII quantities, negative floor and spoken year are preserved',()=>{assert.equal(F.quantity('۱۲۰ متر مربع',1,9999999),120);assert.equal(F.quantity('-۲',-99,99),-2);assert.equal(F.quantity('همکف',-99,99),0);assert.equal(F.quantity('هزار و چهارصد و دو',1,9999),1402);assert.equal(F.quantity('طبقه سوم',-99,99),3);});
test('numbers are bounded instead of silently truncated',()=>{assert.equal(F.quantity('12345678',1,9999999),null);assert.equal(F.quantity('0',1,9999999),null);assert.equal(F.quantity('3.5',0,99),null);});
test('false strings do not become true checkboxes',()=>{for(const x of ['false','False','ندارد','۰',0,false])assert.equal(F.boolean(x),false);for(const x of ['true','دارد','۱',1,true])assert.equal(F.boolean(x),true);assert.equal(F.boolean('maybe'),null);});
test('usage/deal aliases resolve to real select/button values',()=>{const {values}=F.normalize({usage:'آپارتمان',dealType:' رهن/اجاره ',deposit:'۳۰۰ میلیون',rent:'۱۲ میلیون'});assert.equal(values.usage,'مسکونی');assert.equal(values.dealType,'رهن و اجاره');assert.equal(values.deposit,300000000);assert.equal(values.rent,12000000);});
test('incomplete enum data keeps known prices but requires human selection',()=>{const r=F.normalize({price:'۵ میلیارد',area:120});assert.equal(r.values.price,5000000000);assert.equal(r.values.dealType,null);assert.equal(r.values.usage,null);assert.ok(r.warnings.length>=2);});
test('zero rent, ground floor, and studio select are valid values',()=>{const r=F.normalize({dealType:'اجاره',usage:'مسکونی',rent:0,deposit:100,floor:'۰',rooms:'۰'});assert.equal(r.values.rent,0);assert.equal(r.values.floor,0);assert.equal(r.values.rooms,'بدون خواب/سوئیت');});
test('room counts match the real select including Persian five-plus',()=>{assert.equal(F.normalize({rooms:'۵+'}).values.rooms,'5+');assert.equal(F.normalize({rooms:'۶'}).values.rooms,'5+');assert.equal(F.normalize({rooms:'دو خواب'}).values.rooms,'2');assert.equal(F.normalize({rooms:null}).values.rooms,null);});
test('phone formats retain or restore the Iranian leading zero without inventing partial numbers',()=>{assert.equal(F.phone('۰۹۱۲۳۴۵۶۷۸۹'),'09123456789');assert.equal(F.phone('+98 912 345 6789'),'09123456789');assert.equal(F.phone(9123456789),'09123456789');assert.equal(F.phone('12345'),null);});
test('per-meter total is calculated only from an explicit sale and valid area',()=>{const r=F.normalize({dealType:'فروش',usage:'آپارتمان',area:100,pricePerMeter:'۶۰ میلیون'});assert.equal(r.values.price,6000000000);assert.ok(r.warnings.some(x=>x.includes('محاسبه')));assert.equal(F.normalize({area:100,pricePerMeter:'60 میلیون'}).values.price,null);});
test('explicit total wins over derived amounts; excessive multiplication is rejected',()=>{assert.equal(F.normalize({dealType:'فروش',area:100,price:5000000000,pricePerMeter:60000000}).values.price,5000000000);assert.equal(F.normalize({dealType:'فروش',area:9999999,pricePerMeter:Number.MAX_SAFE_INTEGER}).values.price,null);});
test('conflicting monetary roles do not silently select the wrong deal',()=>{assert.equal(F.normalize({dealType:'فروش',price:1000,deposit:100}).values.dealType,null);assert.equal(F.normalize({dealType:'رهن کامل',deposit:100,rent:1}).values.dealType,null);});
test('private identity, existing-record IDs, images and publication flags are never accepted',()=>{const r=F.normalize({area:120,id:'old-property',agencyId:'other',role:'مدیر',status:'واگذار شده',showToGuest:true,isVIP:true,images:['bad']});for(const key of ['id','agencyId','role','status','showToGuest','isVIP','images'])assert.ok(!(key in r.values));});
test('empty/default-only model output cannot reset a form',()=>{assert.equal(F.normalize({hasParking:false,hasElevator:false,hasStorage:false}).meaningful,false);assert.throws(()=>F.normalize([]));assert.throws(()=>F.normalize(null));});
